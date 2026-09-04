import { pool } from "@workspace/db";

// Добавлено 03.09.2026 ИТ Директор Евразии
export type AntiFraudCaseReason = {
  code: string;
  score: number;
  details: string;
};

export type AntiFraudCaseAccount = {
  bitrixUserId: number;
  displayName: string | null;
  phoneMasked: string | null;
  emailMasked: string | null;
  bitrixActive: boolean;
  overallRisk: number;
  riskLevel: "low" | "medium" | "high" | "critical";
  deviceRisk: number;
  linkedAccountRisk: number;
  identitySimilarityRisk: number;
  visitBehaviorRisk: number;
  historicalBehaviorRisk: number;
  historyEnriched: boolean;
  computedAt: string;
  reasons: AntiFraudCaseReason[];
};

export type AntiFraudCaseDevice = {
  devicePrefix: string;
  userIds: number[];
  lastSeenAt: string | null;
};

export type AntiFraudCase = {
  caseId: string;
  overallRisk: number;
  riskLevel: "low" | "medium" | "high" | "critical";
  accountCount: number;
  accounts: AntiFraudCaseAccount[];
  signals: Array<"multiaccount" | "phone" | "email" | "visits" | "fast_switch" | "linked_visits">;
  devices: AntiFraudCaseDevice[];
  identityMatches: Array<{ type: "phone" | "email"; userIds: number[] }>;
  updatedAt: string;
};

type ParentMap = Map<number, number>;

const riskOrder: Record<AntiFraudCase["riskLevel"], number> = {
  low: 0,
  medium: 1,
  high: 2,
  critical: 3,
};

const ensureReady = async (): Promise<void> => {
  const result = await pool.query<{ ready: boolean }>(`
    SELECT
      to_regclass('public.anti_fraud_risk_scores') IS NOT NULL
      AND to_regclass('public.anti_fraud_risk_reasons') IS NOT NULL
      AND to_regclass('public.anti_fraud_device_links') IS NOT NULL
      AND to_regclass('public.anti_fraud_accounts') IS NOT NULL AS ready
  `);
  if (!result.rows[0]?.ready) throw new Error("Anti-Fraud ещё не инициализирован в этой базе данных.");
};

const iso = (value: unknown): string => {
  const date = value instanceof Date ? value : new Date(String(value ?? 0));
  return Number.isNaN(date.getTime()) ? new Date(0).toISOString() : date.toISOString();
};

const nullableIso = (value: unknown): string | null => (value ? iso(value) : null);

const maskPhone = (value: unknown): string | null => {
  const digits = String(value ?? "").replace(/\D/g, "");
  if (digits.length < 7) return null;
  return `+${digits.slice(0, 1)} *** ***-${digits.slice(-4)}`;
};

const maskEmail = (value: unknown): string | null => {
  const text = String(value ?? "").trim();
  const at = text.indexOf("@");
  if (at <= 0) return null;
  const local = text.slice(0, at);
  const domain = text.slice(at + 1);
  return `${local.slice(0, Math.min(2, local.length))}${local.length > 2 ? "***" : ""}@${domain}`;
};

const find = (parents: ParentMap, id: number): number => {
  const parent = parents.get(id);
  if (parent === undefined) {
    parents.set(id, id);
    return id;
  }
  if (parent === id) return id;
  const root = find(parents, parent);
  parents.set(id, root);
  return root;
};

const union = (parents: ParentMap, ids: number[]): void => {
  if (ids.length < 2) return;
  const first = find(parents, ids[0]);
  for (const id of ids.slice(1)) {
    const root = find(parents, id);
    if (root !== first) parents.set(root, first);
  }
};

const validLevel = (value: unknown): AntiFraudCase["riskLevel"] => {
  const text = String(value ?? "low");
  if (text === "critical" || text === "high" || text === "medium") return text;
  return "low";
};

// Добавлено 03.09.2026 ИТ Директор Евразии
// Одна строка интерфейса = один кейс. Связующие признаки (устройство, телефон, email)
// объединяют аккаунты транзитивно. Поведенческие признаки сами по себе аккаунты не объединяют.
export const listAntiFraudCases = async (): Promise<AntiFraudCase[]> => {
  await ensureReady();

  const [accountResult, deviceResult, identityResult] = await Promise.all([
    pool.query<any>(`
      WITH risky AS (
        SELECT bitrix_user_id
        FROM anti_fraud_risk_scores
        WHERE overall_risk > 0
      ), relevant AS (
        SELECT bitrix_user_id FROM risky
        UNION
        SELECT l2.bitrix_user_id
        FROM anti_fraud_device_links l1
        JOIN anti_fraud_device_links l2 ON l2.device_hash = l1.device_hash
        JOIN risky r ON r.bitrix_user_id = l1.bitrix_user_id
        UNION
        SELECT a2.bitrix_user_id
        FROM anti_fraud_accounts a1
        JOIN anti_fraud_accounts a2
          ON a2.bitrix_user_id <> a1.bitrix_user_id
         AND (
           (a1.phone_normalized IS NOT NULL AND a1.phone_normalized <> '' AND a2.phone_normalized = a1.phone_normalized)
           OR
           (a1.email_normalized IS NOT NULL AND a1.email_normalized <> '' AND a2.email_normalized = a1.email_normalized)
         )
        JOIN risky r ON r.bitrix_user_id = a1.bitrix_user_id
      )
      SELECT
        u.bitrix_user_id,
        a.display_name,
        a.phone_normalized,
        a.email_normalized,
        COALESCE(a.bitrix_active, true) AS bitrix_active,
        COALESCE(s.overall_risk, 0) AS overall_risk,
        COALESCE(s.risk_level, 'low') AS risk_level,
        COALESCE(s.device_risk, 0) AS device_risk,
        COALESCE(s.linked_account_risk, 0) AS linked_account_risk,
        COALESCE(s.identity_similarity_risk, 0) AS identity_similarity_risk,
        COALESCE(s.visit_behavior_risk, 0) AS visit_behavior_risk,
        COALESCE(s.historical_behavior_risk, 0) AS historical_behavior_risk,
        COALESCE(s.history_enriched, false) AS history_enriched,
        COALESCE(s.computed_at, now()) AS computed_at,
        COALESCE(
          json_agg(
            json_build_object('code', rr.reason_code, 'score', rr.score, 'details', rr.details)
            ORDER BY rr.score DESC, rr.reason_code
          ) FILTER (WHERE rr.id IS NOT NULL),
          '[]'::json
        ) AS reasons
      FROM relevant u
      LEFT JOIN anti_fraud_accounts a ON a.bitrix_user_id = u.bitrix_user_id
      LEFT JOIN anti_fraud_risk_scores s ON s.bitrix_user_id = u.bitrix_user_id
      LEFT JOIN anti_fraud_risk_reasons rr ON rr.bitrix_user_id = u.bitrix_user_id
      GROUP BY u.bitrix_user_id, a.bitrix_user_id, s.bitrix_user_id
      ORDER BY COALESCE(s.overall_risk, 0) DESC, u.bitrix_user_id
    `),
    pool.query<any>(`
      WITH risky AS (
        SELECT bitrix_user_id FROM anti_fraud_risk_scores WHERE overall_risk > 0
      ), relevant_devices AS (
        SELECT DISTINCT l.device_hash
        FROM anti_fraud_device_links l
        JOIN risky r ON r.bitrix_user_id = l.bitrix_user_id
      )
      SELECT
        left(l.device_hash, 16) AS device_prefix,
        array_agg(DISTINCT l.bitrix_user_id ORDER BY l.bitrix_user_id) AS user_ids,
        max(l.last_seen_at) AS last_seen_at
      FROM anti_fraud_device_links l
      JOIN relevant_devices d ON d.device_hash = l.device_hash
      GROUP BY l.device_hash
      HAVING count(DISTINCT l.bitrix_user_id) > 1
      ORDER BY count(DISTINCT l.bitrix_user_id) DESC, max(l.last_seen_at) DESC NULLS LAST
    `),
    pool.query<any>(`
      WITH risky AS (
        SELECT bitrix_user_id FROM anti_fraud_risk_scores WHERE overall_risk > 0
      ), matches AS (
        SELECT 'phone'::text AS match_type, phone_normalized AS match_key, bitrix_user_id
        FROM anti_fraud_accounts
        WHERE phone_normalized IS NOT NULL AND phone_normalized <> ''
        UNION ALL
        SELECT 'email'::text AS match_type, email_normalized AS match_key, bitrix_user_id
        FROM anti_fraud_accounts
        WHERE email_normalized IS NOT NULL AND email_normalized <> ''
      ), relevant_keys AS (
        SELECT DISTINCT m.match_type, m.match_key
        FROM matches m
        JOIN risky r ON r.bitrix_user_id = m.bitrix_user_id
      )
      SELECT
        m.match_type,
        array_agg(DISTINCT m.bitrix_user_id ORDER BY m.bitrix_user_id) AS user_ids
      FROM matches m
      JOIN relevant_keys k USING (match_type, match_key)
      GROUP BY m.match_type, m.match_key
      HAVING count(DISTINCT m.bitrix_user_id) > 1
    `),
  ]);

  const accounts = new Map<number, AntiFraudCaseAccount>();
  const parents: ParentMap = new Map();

  for (const row of accountResult.rows) {
    const bitrixUserId = Number(row.bitrix_user_id);
    find(parents, bitrixUserId);
    accounts.set(bitrixUserId, {
      bitrixUserId,
      displayName: row.display_name ?? null,
      phoneMasked: maskPhone(row.phone_normalized),
      emailMasked: maskEmail(row.email_normalized),
      bitrixActive: Boolean(row.bitrix_active),
      overallRisk: Number(row.overall_risk ?? 0),
      riskLevel: validLevel(row.risk_level),
      deviceRisk: Number(row.device_risk ?? 0),
      linkedAccountRisk: Number(row.linked_account_risk ?? 0),
      identitySimilarityRisk: Number(row.identity_similarity_risk ?? 0),
      visitBehaviorRisk: Number(row.visit_behavior_risk ?? 0),
      historicalBehaviorRisk: Number(row.historical_behavior_risk ?? 0),
      historyEnriched: Boolean(row.history_enriched),
      computedAt: iso(row.computed_at),
      reasons: Array.isArray(row.reasons)
        ? row.reasons.map((reason: any) => ({
            code: String(reason.code ?? ""),
            score: Number(reason.score ?? 0),
            details: String(reason.details ?? ""),
          }))
        : [],
    });
  }

  const devices: AntiFraudCaseDevice[] = deviceResult.rows.map((row: any) => {
    const userIds: number[] = Array.isArray(row.user_ids) ? row.user_ids.map(Number) : [];
    union(parents, userIds);
    return {
      devicePrefix: String(row.device_prefix ?? ""),
      userIds,
      lastSeenAt: nullableIso(row.last_seen_at),
    };
  });

  const identityMatches: AntiFraudCase["identityMatches"] = identityResult.rows.map((row: any) => {
    const userIds: number[] = Array.isArray(row.user_ids) ? row.user_ids.map(Number) : [];
    union(parents, userIds);
    return {
      type: row.match_type === "email" ? "email" : "phone",
      userIds,
    };
  });

  const grouped = new Map<number, number[]>();
  for (const id of accounts.keys()) {
    const root = find(parents, id);
    const ids = grouped.get(root) ?? [];
    ids.push(id);
    grouped.set(root, ids);
  }

  const cases: AntiFraudCase[] = [];
  for (const ids of grouped.values()) {
    const caseAccounts = ids
      .map((id) => accounts.get(id))
      .filter((value): value is AntiFraudCaseAccount => Boolean(value))
      .sort((a, b) => b.overallRisk - a.overallRisk || a.bitrixUserId - b.bitrixUserId);

    if (!caseAccounts.some((account) => account.overallRisk > 0)) continue;

    const idSet = new Set(ids);
    const caseDevices = devices.filter((device) => device.userIds.some((id) => idSet.has(id)));
    const caseIdentity = identityMatches.filter((match) => match.userIds.some((id) => idSet.has(id)));
    const reasonCodes = new Set(caseAccounts.flatMap((account) => account.reasons.map((reason) => reason.code)));
    const signals: AntiFraudCase["signals"] = [];

    if (caseDevices.length > 0 || reasonCodes.has("shared_device_accounts")) signals.push("multiaccount");
    if (caseIdentity.some((match) => match.type === "phone") || reasonCodes.has("duplicate_phone_identity")) signals.push("phone");
    if (caseIdentity.some((match) => match.type === "email") || reasonCodes.has("duplicate_email_identity")) signals.push("email");
    if (reasonCodes.has("high_daily_visit_frequency") || reasonCodes.has("repeated_high_visit_days")) signals.push("visits");
    if (reasonCodes.has("fast_account_switch") || reasonCodes.has("repeated_fast_switches")) signals.push("fast_switch");
    if (reasonCodes.has("linked_visit_proximity")) signals.push("linked_visits");

    const overallRisk = Math.max(...caseAccounts.map((account) => account.overallRisk));
    const riskLevel = caseAccounts.reduce<AntiFraudCase["riskLevel"]>(
      (best, account) => (riskOrder[account.riskLevel] > riskOrder[best] ? account.riskLevel : best),
      "low",
    );
    const updatedAt = caseAccounts.reduce(
      (latest, account) => (account.computedAt > latest ? account.computedAt : latest),
      new Date(0).toISOString(),
    );

    cases.push({
      caseId: `AF-${Math.min(...ids)}`,
      overallRisk,
      riskLevel,
      accountCount: caseAccounts.length,
      accounts: caseAccounts,
      signals,
      devices: caseDevices,
      identityMatches: caseIdentity,
      updatedAt,
    });
  }

  return cases.sort((a, b) => b.overallRisk - a.overallRisk || b.accountCount - a.accountCount || a.caseId.localeCompare(b.caseId));
};
