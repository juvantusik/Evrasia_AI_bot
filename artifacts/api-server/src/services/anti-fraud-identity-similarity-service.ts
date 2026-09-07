import { pool } from "@workspace/db";
import { enrichRestisHistoryForHighRiskUserOnce } from "./anti-fraud-restis-history-enricher";
import {
  analyzeAntiFraudOnce,
  type AntiFraudRiskAnalysisOptions,
  type AntiFraudRiskAnalysisResult,
} from "./anti-fraud-risk-engine";

const SIMILAR_EMAIL_RISK = 15;
const SIMILAR_PHONE_RISK = 20;
const SIMILAR_EMAIL_PHONE_COMBO_RISK = 15;
const DEFAULT_HISTORY_THRESHOLD = 50;
const DEFAULT_MAX_HISTORY_USERS = 10;
const MAX_HISTORY_USERS = 50;

type AccountIdentity = {
  bitrixUserId: number;
  displayName: string | null;
  phone: string | null;
  email: string | null;
};

export type IdentitySimilarityLink = {
  leftUserId: number;
  rightUserId: number;
  similarEmail: boolean;
  similarPhone: boolean;
  sameName: boolean;
  sharedDevice: boolean;
  corroborated: boolean;
  emailDetails: string | null;
  phoneDetails: string | null;
  riskScore: number;
};

export type IdentitySimilaritySyncResult = {
  candidatePairs: number;
  corroboratedPairs: number;
  similarEmailPairs: number;
  similarPhonePairs: number;
};

export type AntiFraudRiskAnalysisWithSimilarityResult = AntiFraudRiskAnalysisResult & {
  identityCandidatePairs: number;
  identityCorroboratedPairs: number;
  identitySimilarEmailPairs: number;
  identitySimilarPhonePairs: number;
};

const boundedInteger = (
  value: number,
  fallback: number,
  minimum: number,
  maximum: number,
): number => {
  if (!Number.isInteger(value)) return fallback;
  return Math.max(minimum, Math.min(maximum, value));
};

const normalizeName = (value: string | null): string =>
  String(value ?? "")
    .trim()
    .toLocaleLowerCase("ru-RU")
    .replaceAll("ё", "е")
    .replace(/[^\p{L}\p{N}]+/gu, "");

const parseEmail = (value: string | null): {
  full: string;
  local: string;
  provider: string;
  tld: string;
} | null => {
  const full = String(value ?? "").trim().toLowerCase();
  const at = full.lastIndexOf("@");
  if (at <= 0 || at === full.length - 1) return null;

  const local = full.slice(0, at);
  const domain = full.slice(at + 1);
  const labels = domain.split(".").filter(Boolean);
  if (local.length < 1 || labels.length < 2) return null;

  // Для yandex.ru / yandex.com / yandex.kz provider будет одинаковым: yandex.
  // Берём label непосредственно перед TLD. Это намеренно консервативное правило,
  // а не попытка угадать все публичные suffix-зоны мира.
  const provider = labels.at(-2) ?? "";
  const tld = labels.at(-1) ?? "";
  if (!provider || !tld) return null;

  return { full, local, provider, tld };
};

const normalizeCrossProviderLocal = (value: string): string =>
  value.toLowerCase().replace(/[._-]/g, "");

// Проверяет расстояние Левенштейна <= 1 без построения полной матрицы.
export const differsByAtMostOneEdit = (left: string, right: string): boolean => {
  if (left === right) return true;
  if (Math.abs(left.length - right.length) > 1) return false;

  let i = 0;
  let j = 0;
  let edits = 0;

  while (i < left.length && j < right.length) {
    if (left[i] === right[j]) {
      i += 1;
      j += 1;
      continue;
    }

    edits += 1;
    if (edits > 1) return false;

    if (left.length > right.length) i += 1;
    else if (right.length > left.length) j += 1;
    else {
      i += 1;
      j += 1;
    }
  }

  if (i < left.length || j < right.length) edits += 1;
  return edits <= 1;
};

export const phonesDifferByOneDigit = (left: string | null, right: string | null): boolean => {
  const a = String(left ?? "").replace(/\D/g, "");
  const b = String(right ?? "").replace(/\D/g, "");
  if (a.length < 7 || a.length !== b.length || a === b) return false;

  let differences = 0;
  for (let index = 0; index < a.length; index += 1) {
    if (a[index] !== b[index]) differences += 1;
    if (differences > 1) return false;
  }
  return differences === 1;
};

const emailsAreSimilar = (
  left: string | null,
  right: string | null,
): { similar: boolean; crossProvider: boolean; details: string | null } => {
  const a = parseEmail(left);
  const b = parseEmail(right);
  if (!a || !b || a.full === b.full) {
    return { similar: false, crossProvider: false, details: null };
  }

  if (a.provider === b.provider) {
    if (a.local.length < 6 || b.local.length < 6) {
      return { similar: false, crossProvider: false, details: null };
    }
    if (!differsByAtMostOneEdit(a.local, b.local)) {
      return { similar: false, crossProvider: false, details: null };
    }

    const localRelation = a.local === b.local ? "same_local" : "local_edit_distance_1";
    return {
      similar: true,
      crossProvider: false,
      details:
        `${localRelation}; provider=${a.provider}; ` +
        `left_tld=${a.tld}; right_tld=${b.tld}`,
    };
  }

  // Добавлено 07.09.2026 ИТ Директор Евразии
  // Для разных почтовых провайдеров similarity intentionally stricter:
  // убираем только типовые разделители . _ - и требуем полное совпадение local-part.
  // Такая cross-provider связь остаётся слабой и не подтверждается одним только именем.
  const normalizedLeft = normalizeCrossProviderLocal(a.local);
  const normalizedRight = normalizeCrossProviderLocal(b.local);
  if (normalizedLeft.length < 6 || normalizedRight.length < 6) {
    return { similar: false, crossProvider: true, details: null };
  }
  if (normalizedLeft !== normalizedRight) {
    return { similar: false, crossProvider: true, details: null };
  }

  return {
    similar: true,
    crossProvider: true,
    details:
      `cross_provider_same_normalized_local; ` +
      `left_provider=${a.provider}; right_provider=${b.provider}; ` +
      `left_tld=${a.tld}; right_tld=${b.tld}`,
  };
};

const pairKey = (left: number, right: number): string =>
  left < right ? `${left}:${right}` : `${right}:${left}`;

export const evaluateIdentityPair = (
  left: AccountIdentity,
  right: AccountIdentity,
  sharedDevice: boolean,
): IdentitySimilarityLink | null => {
  const leftEmail = String(left.email ?? "").trim().toLowerCase();
  const rightEmail = String(right.email ?? "").trim().toLowerCase();
  const leftPhone = String(left.phone ?? "").replace(/\D/g, "");
  const rightPhone = String(right.phone ?? "").replace(/\D/g, "");

  const emailSimilarity = emailsAreSimilar(leftEmail || null, rightEmail || null);
  const similarPhone = phonesDifferByOneDigit(leftPhone || null, rightPhone || null);
  if (!emailSimilarity.similar && !similarPhone) return null;

  const leftName = normalizeName(left.displayName);
  const rightName = normalizeName(right.displayName);
  const sameName = leftName.length >= 3 && leftName === rightName;
  const exactEmail = Boolean(leftEmail && leftEmail === rightEmail);
  const exactPhone = Boolean(leftPhone && leftPhone === rightPhone);

  // Слабый признак только открывает дополнительную проверку.
  // Same-provider email может подтверждаться именем, exact/similar phone или устройством.
  // Cross-provider email (совпавший local-part после удаления . _ -) намеренно строже:
  // одного sameName недостаточно; нужен телефон или общее устройство.
  const emailCorroborated = emailSimilarity.similar && (
    emailSimilarity.crossProvider
      ? (exactPhone || similarPhone || sharedDevice)
      : (sameName || exactPhone || similarPhone || sharedDevice)
  );
  const phoneCorroborated =
    similarPhone && (sameName || exactEmail || emailSimilarity.similar || sharedDevice);
  const corroborated = emailCorroborated || phoneCorroborated;

  let riskScore = 0;
  if (corroborated) {
    if (emailCorroborated) riskScore += SIMILAR_EMAIL_RISK;
    if (phoneCorroborated) riskScore += SIMILAR_PHONE_RISK;
    if (emailCorroborated && phoneCorroborated) {
      riskScore += SIMILAR_EMAIL_PHONE_COMBO_RISK;
    }
  }

  return {
    leftUserId: Math.min(left.bitrixUserId, right.bitrixUserId),
    rightUserId: Math.max(left.bitrixUserId, right.bitrixUserId),
    similarEmail: emailSimilarity.similar,
    similarPhone,
    sameName,
    sharedDevice,
    corroborated,
    emailDetails: emailSimilarity.details,
    phoneDetails: similarPhone ? "same_length; differing_digits=1" : null,
    riskScore,
  };
};

const loadSharedDevicePairs = async (): Promise<Set<string>> => {
  const result = await pool.query<{ left_user_id: number; right_user_id: number }>(`
    SELECT DISTINCT
      LEAST(a.bitrix_user_id, b.bitrix_user_id)::int AS left_user_id,
      GREATEST(a.bitrix_user_id, b.bitrix_user_id)::int AS right_user_id
    FROM anti_fraud_device_links a
    JOIN anti_fraud_device_links b
      ON b.device_hash = a.device_hash
     AND b.bitrix_user_id > a.bitrix_user_id
  `);

  return new Set(
    result.rows.map((row) => pairKey(Number(row.left_user_id), Number(row.right_user_id))),
  );
};

export const syncIdentitySimilarityLinksOnce = async (): Promise<IdentitySimilaritySyncResult> => {
  const [accountsResult, sharedDevicePairs] = await Promise.all([
    pool.query<{
      bitrix_user_id: number;
      display_name: string | null;
      phone_normalized: string | null;
      email_normalized: string | null;
    }>(`
      SELECT bitrix_user_id, display_name, phone_normalized, email_normalized
      FROM anti_fraud_accounts
      WHERE bitrix_active IS TRUE
        AND bitrix_user_id > 0
      ORDER BY bitrix_user_id
    `),
    loadSharedDevicePairs(),
  ]);

  const accounts: AccountIdentity[] = accountsResult.rows.map((row) => ({
    bitrixUserId: Number(row.bitrix_user_id),
    displayName: row.display_name ?? null,
    phone: row.phone_normalized ?? null,
    email: row.email_normalized ?? null,
  }));

  const links: IdentitySimilarityLink[] = [];
  for (let leftIndex = 0; leftIndex < accounts.length; leftIndex += 1) {
    const left = accounts[leftIndex]!;
    for (let rightIndex = leftIndex + 1; rightIndex < accounts.length; rightIndex += 1) {
      const right = accounts[rightIndex]!;
      const evaluated = evaluateIdentityPair(
        left,
        right,
        sharedDevicePairs.has(pairKey(left.bitrixUserId, right.bitrixUserId)),
      );
      if (evaluated) links.push(evaluated);
    }
  }

  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    await client.query("DELETE FROM anti_fraud_identity_links");

    for (const link of links) {
      await client.query(
        `INSERT INTO anti_fraud_identity_links (
           left_user_id, right_user_id, similar_email, similar_phone,
           same_name, shared_device, corroborated, email_details,
           phone_details, risk_score, computed_at
         )
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,now())`,
        [
          link.leftUserId,
          link.rightUserId,
          link.similarEmail,
          link.similarPhone,
          link.sameName,
          link.sharedDevice,
          link.corroborated,
          link.emailDetails,
          link.phoneDetails,
          link.riskScore,
        ],
      );
    }

    await client.query("COMMIT");
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }

  return {
    candidatePairs: links.length,
    corroboratedPairs: links.filter((link) => link.corroborated).length,
    similarEmailPairs: links.filter((link) => link.similarEmail).length,
    similarPhonePairs: links.filter((link) => link.similarPhone).length,
  };
};

type UserSimilarity = {
  hasEmail: boolean;
  hasPhone: boolean;
  emailLinks: number;
  phoneLinks: number;
};

const applySimilarityRiskOverlay = async (historyThreshold: number): Promise<void> => {
  const result = await pool.query<{
    left_user_id: number;
    right_user_id: number;
    similar_email: boolean;
    similar_phone: boolean;
  }>(`
    SELECT left_user_id, right_user_id, similar_email, similar_phone
    FROM anti_fraud_identity_links
    WHERE corroborated IS TRUE
  `);

  const byUser = new Map<number, UserSimilarity>();
  const touch = (userId: number, similarEmail: boolean, similarPhone: boolean): void => {
    const current = byUser.get(userId) ?? {
      hasEmail: false,
      hasPhone: false,
      emailLinks: 0,
      phoneLinks: 0,
    };
    if (similarEmail) {
      current.hasEmail = true;
      current.emailLinks += 1;
    }
    if (similarPhone) {
      current.hasPhone = true;
      current.phoneLinks += 1;
    }
    byUser.set(userId, current);
  };

  for (const row of result.rows) {
    touch(Number(row.left_user_id), Boolean(row.similar_email), Boolean(row.similar_phone));
    touch(Number(row.right_user_id), Boolean(row.similar_email), Boolean(row.similar_phone));
  }

  const client = await pool.connect();
  try {
    await client.query("BEGIN");

    for (const [userId, similarity] of byUser) {
      const emailRisk = similarity.hasEmail ? SIMILAR_EMAIL_RISK : 0;
      const phoneRisk = similarity.hasPhone ? SIMILAR_PHONE_RISK : 0;
      const comboRisk = similarity.hasEmail && similarity.hasPhone
        ? SIMILAR_EMAIL_PHONE_COMBO_RISK
        : 0;
      const addedRisk = emailRisk + phoneRisk + comboRisk;

      const updated = await client.query<{ overall_risk: number }>(
        `UPDATE anti_fraud_risk_scores
         SET identity_similarity_risk = LEAST(100, identity_similarity_risk + $2),
             overall_risk = LEAST(100, overall_risk + $2),
             risk_level = CASE
               WHEN LEAST(100, overall_risk + $2) >= 75 THEN 'critical'
               WHEN LEAST(100, overall_risk + $2) >= 50 THEN 'high'
               WHEN LEAST(100, overall_risk + $2) >= 25 THEN 'medium'
               ELSE 'low'
             END,
             history_gate = LEAST(100, overall_risk + $2) >= $3,
             computed_at = now()
         WHERE bitrix_user_id = $1
         RETURNING overall_risk`,
        [userId, addedRisk, historyThreshold],
      );

      if (!updated.rowCount) continue;

      if (emailRisk > 0) {
        await client.query(
          `INSERT INTO anti_fraud_risk_reasons
             (bitrix_user_id, reason_code, score, details, computed_at)
           VALUES ($1, 'similar_email_identity', $2, $3, now())
           ON CONFLICT (bitrix_user_id, reason_code) DO UPDATE SET
             score = EXCLUDED.score,
             details = EXCLUDED.details,
             computed_at = now()`,
          [userId, emailRisk, `corroborated_similar_email_links=${similarity.emailLinks}`],
        );
      }

      if (phoneRisk > 0) {
        await client.query(
          `INSERT INTO anti_fraud_risk_reasons
             (bitrix_user_id, reason_code, score, details, computed_at)
           VALUES ($1, 'similar_phone_identity', $2, $3, now())
           ON CONFLICT (bitrix_user_id, reason_code) DO UPDATE SET
             score = EXCLUDED.score,
             details = EXCLUDED.details,
             computed_at = now()`,
          [userId, phoneRisk, `corroborated_similar_phone_links=${similarity.phoneLinks}`],
        );
      }

      if (comboRisk > 0) {
        await client.query(
          `INSERT INTO anti_fraud_risk_reasons
             (bitrix_user_id, reason_code, score, details, computed_at)
           VALUES ($1, 'similar_identity_combo', $2, $3, now())
           ON CONFLICT (bitrix_user_id, reason_code) DO UPDATE SET
             score = EXCLUDED.score,
             details = EXCLUDED.details,
             computed_at = now()`,
          [
            userId,
            comboRisk,
            `similar_email_links=${similarity.emailLinks}; similar_phone_links=${similarity.phoneLinks}`,
          ],
        );
      }
    }

    await client.query("COMMIT");
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
};

// Обновлено 05.09.2026 ИТ Директор Евразии
// History eligibility определяется только итоговым risk gate и активностью аккаунта.
// Наличие карты в anti_fraud_cards не требуется: защищённый loyalty endpoint сам находит
// текущую карту RESTIS_STATE=113 по USER_ID и безопасно сообщает, если активной карты нет.
const loadHistoryEligibleUsers = async (): Promise<number[]> => {
  const result = await pool.query<{ bitrix_user_id: number }>(`
    SELECT s.bitrix_user_id
    FROM anti_fraud_risk_scores s
    JOIN anti_fraud_accounts a ON a.bitrix_user_id = s.bitrix_user_id
    WHERE s.history_gate IS TRUE
      AND a.bitrix_active IS TRUE
      AND s.history_enriched IS NOT TRUE
    ORDER BY s.overall_risk DESC, s.bitrix_user_id
  `);
  return result.rows.map((row) => Number(row.bitrix_user_id));
};

const loadFinalResult = async (
  base: AntiFraudRiskAnalysisResult,
  historyEligibleAccounts: number,
  historyAttemptedAccounts: number,
  historySuccessfulAccounts: number,
  historyFailedAccounts: number,
  similarity: IdentitySimilaritySyncResult,
): Promise<AntiFraudRiskAnalysisWithSimilarityResult> => {
  const [summary, topRisk] = await Promise.all([
    pool.query<{
      scored: string;
      medium: string;
      high: string;
      critical: string;
      history_gate: string;
    }>(`
      SELECT
        count(*)::text AS scored,
        count(*) FILTER (WHERE risk_level = 'medium')::text AS medium,
        count(*) FILTER (WHERE risk_level = 'high')::text AS high,
        count(*) FILTER (WHERE risk_level = 'critical')::text AS critical,
        count(*) FILTER (WHERE history_gate IS TRUE)::text AS history_gate
      FROM anti_fraud_risk_scores
    `),
    pool.query<{
      bitrix_user_id: number;
      overall_risk: number;
      risk_level: string;
    }>(`
      SELECT bitrix_user_id, overall_risk, risk_level
      FROM anti_fraud_risk_scores
      ORDER BY overall_risk DESC, bitrix_user_id
      LIMIT 20
    `),
  ]);

  const row = summary.rows[0];
  return {
    ...base,
    scoredAccounts: Number(row?.scored ?? 0),
    mediumAccounts: Number(row?.medium ?? 0),
    highAccounts: Number(row?.high ?? 0),
    criticalAccounts: Number(row?.critical ?? 0),
    historyGateAccounts: Number(row?.history_gate ?? 0),
    historyEligibleAccounts,
    historyAttemptedAccounts,
    historySuccessfulAccounts,
    historyFailedAccounts,
    topRisk: topRisk.rows.map((item) => ({
      bitrixUserId: Number(item.bitrix_user_id),
      overallRisk: Number(item.overall_risk),
      riskLevel: String(item.risk_level),
    })),
    identityCandidatePairs: similarity.candidatePairs,
    identityCorroboratedPairs: similarity.corroboratedPairs,
    identitySimilarEmailPairs: similarity.similarEmailPairs,
    identitySimilarPhonePairs: similarity.similarPhonePairs,
  };
};

// Полный explainable-анализ: сначала базовый scoring, затем candidate/corroboration
// похожих email/телефонов. Только после итогового score запускается адресная 60-дневная
// история через защищённый loyalty endpoint по USER_ID.
export const analyzeAntiFraudWithSimilarityOnce = async (
  options: AntiFraudRiskAnalysisOptions = {},
): Promise<AntiFraudRiskAnalysisWithSimilarityResult> => {
  const historyThreshold = boundedInteger(
    Number(
      options.historyThreshold ??
        process.env.ANTI_FRAUD_HISTORY_RISK_THRESHOLD ??
        DEFAULT_HISTORY_THRESHOLD,
    ),
    DEFAULT_HISTORY_THRESHOLD,
    1,
    100,
  );
  const maxHistoryUsers = boundedInteger(
    Number(
      options.maxHistoryUsers ??
        process.env.ANTI_FRAUD_MAX_HISTORY_USERS_PER_RUN ??
        DEFAULT_MAX_HISTORY_USERS,
    ),
    DEFAULT_MAX_HISTORY_USERS,
    1,
    MAX_HISTORY_USERS,
  );

  let base = await analyzeAntiFraudOnce({
    ...options,
    autoHistory: false,
  });

  let similarity = await syncIdentitySimilarityLinksOnce();
  await applySimilarityRiskOverlay(historyThreshold);

  const eligible = await loadHistoryEligibleUsers();
  let historyAttemptedAccounts = 0;
  let historySuccessfulAccounts = 0;
  let historyFailedAccounts = 0;

  if (options.autoHistory === true) {
    for (const bitrixUserId of eligible.slice(0, maxHistoryUsers)) {
      historyAttemptedAccounts += 1;
      try {
        await enrichRestisHistoryForHighRiskUserOnce({
          bitrixUserId,
          riskGateConfirmed: true,
          lookbackDays: 60,
        });
        historySuccessfulAccounts += 1;
      } catch {
        historyFailedAccounts += 1;
      }
    }

    if (historyAttemptedAccounts > 0) {
      base = await analyzeAntiFraudOnce({
        ...options,
        refreshAccounts: false,
        autoHistory: false,
      });
      similarity = await syncIdentitySimilarityLinksOnce();
      await applySimilarityRiskOverlay(historyThreshold);
    }
  }

  return loadFinalResult(
    base,
    eligible.length,
    historyAttemptedAccounts,
    historySuccessfulAccounts,
    historyFailedAccounts,
    similarity,
  );
};
