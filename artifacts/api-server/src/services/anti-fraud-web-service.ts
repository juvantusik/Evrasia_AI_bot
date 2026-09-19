import { pool } from "@workspace/db";
import { getAntiFraudOperatorWatchlist } from "./anti-fraud-operator-watchlist-service";

// Добавлено 03.09.2026 ИТ Директор Евразии
export type AntiFraudWebSummary = {
  scoredAccounts: number;
  criticalAccounts: number;
  highAccounts: number;
  mediumAccounts: number;
  lowAccounts: number;
  historyGateAccounts: number;
  historyEnrichedAccounts: number;
  devices: number;
  sharedDevices: number;
  accountsOnSharedDevices: number;
  trustedDeviceHashes: number;
  updatedAt: string | null;
};

export type AntiFraudWebReason = {
  code: string;
  score: number;
  details: string;
};

export type AntiFraudWebAccount = {
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
  historyGate: boolean;
  historyEnriched: boolean;
  computedAt: string;
  reasons: AntiFraudWebReason[];
  operatorWatched: boolean;
  operatorLabel: string | null;
  operatorRiskOverride: number | null;
};

export type AntiFraudWebDevice = {
  devicePrefix: string;
  accountCount: number;
  userIds: number[];
  lastSeenAt: string | null;
  clientTypes: string[];
};

export type AntiFraudWebSimilarGroup = {
  matchType: "phone" | "email";
  accountCount: number;
  accounts: Array<{
    bitrixUserId: number;
    displayName: string | null;
    overallRisk: number;
    riskLevel: string;
  }>;
};

const ensureReady = async (): Promise<void> => {
  const result = await pool.query<{ ready: boolean }>(`
    SELECT
      to_regclass('public.anti_fraud_risk_scores') IS NOT NULL
      AND to_regclass('public.anti_fraud_device_links') IS NOT NULL
      AND to_regclass('public.anti_fraud_accounts') IS NOT NULL AS ready
  `);
  if (!result.rows[0]?.ready) {
    throw new Error("Anti-Fraud ещё не инициализирован в этой базе данных.");
  }
};

const iso = (value: unknown): string | null => {
  if (!value) return null;
  const date = value instanceof Date ? value : new Date(String(value));
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
};

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
  const visible = local.slice(0, Math.min(2, local.length));
  return `${visible}${local.length > 2 ? "***" : ""}@${domain}`;
};


// Обновлено 10.09.2026 ИТ Директор Евразии:
// operational-счётчики по-прежнему исключают заблокированные и неактивные аккаунты,
// а trustedDeviceHashes отражает полный синхронизированный snapshot Trusted Device без этого фильтра.
export const getAntiFraudWebSummary = async (): Promise<AntiFraudWebSummary> => {
  await ensureReady();
  const result = await pool.query<{
    scored_accounts: string;
    critical_accounts: string;
    high_accounts: string;
    medium_accounts: string;
    low_accounts: string;
    history_gate_accounts: string;
    history_enriched_accounts: string;
    devices: string;
    shared_devices: string;
    accounts_on_shared_devices: string;
    trusted_device_hashes: string;
    updated_at: Date | null;
  }>(`
    WITH score_summary AS (
      SELECT
        count(*) AS scored_accounts,
        count(*) FILTER (WHERE s.risk_level = 'critical') AS critical_accounts,
        count(*) FILTER (WHERE s.risk_level = 'high') AS high_accounts,
        count(*) FILTER (WHERE s.risk_level = 'medium') AS medium_accounts,
        count(*) FILTER (WHERE s.risk_level = 'low') AS low_accounts,
        count(*) FILTER (WHERE s.history_gate IS TRUE) AS history_gate_accounts,
        count(*) FILTER (WHERE s.history_enriched IS TRUE) AS history_enriched_accounts,
        max(s.computed_at) AS updated_at
      FROM anti_fraud_risk_scores s
      LEFT JOIN anti_fraud_accounts a ON a.bitrix_user_id = s.bitrix_user_id
      WHERE COALESCE(a.bitrix_active, true) IS TRUE
        AND COALESCE(a.bitrix_blocked, false) IS NOT TRUE
    ),
    device_summary AS (
      SELECT
        count(*) AS devices,
        count(*) FILTER (WHERE account_count > 1) AS shared_devices,
        COALESCE(sum(account_count) FILTER (WHERE account_count > 1), 0) AS accounts_on_shared_devices
      FROM (
        SELECT l.device_hash, count(DISTINCT l.bitrix_user_id) AS account_count
        FROM anti_fraud_device_links l
        LEFT JOIN anti_fraud_accounts a ON a.bitrix_user_id = l.bitrix_user_id
        WHERE COALESCE(a.bitrix_active, true) IS TRUE
          AND COALESCE(a.bitrix_blocked, false) IS NOT TRUE
        GROUP BY l.device_hash
      ) d
    ),
    trusted_device_summary AS (
      SELECT count(DISTINCT device_hash) AS trusted_device_hashes
      FROM anti_fraud_device_links
    )
    SELECT * FROM score_summary CROSS JOIN device_summary CROSS JOIN trusted_device_summary
  `);
  const row = result.rows[0];
  return {
    scoredAccounts: Number(row?.scored_accounts ?? 0),
    criticalAccounts: Number(row?.critical_accounts ?? 0),
    highAccounts: Number(row?.high_accounts ?? 0),
    mediumAccounts: Number(row?.medium_accounts ?? 0),
    lowAccounts: Number(row?.low_accounts ?? 0),
    historyGateAccounts: Number(row?.history_gate_accounts ?? 0),
    historyEnrichedAccounts: Number(row?.history_enriched_accounts ?? 0),
    devices: Number(row?.devices ?? 0),
    sharedDevices: Number(row?.shared_devices ?? 0),
    accountsOnSharedDevices: Number(row?.accounts_on_shared_devices ?? 0),
    trustedDeviceHashes: Number(row?.trusted_device_hashes ?? 0),
    updatedAt: iso(row?.updated_at),
  };
};

// Добавлено 03.09.2026 ИТ Директор Евразии
export const listAntiFraudWebAccounts = async (input?: {
  query?: string;
  level?: string;
  limit?: number;
}): Promise<AntiFraudWebAccount[]> => {
  await ensureReady();
  const limit = Math.max(1, Math.min(500, Math.floor(input?.limit ?? 200)));
  const query = String(input?.query ?? "").trim();
  const level = ["low", "medium", "high", "critical"].includes(String(input?.level))
    ? String(input?.level)
    : "";
  const operatorWatchlist = await getAntiFraudOperatorWatchlist();
  const watchedIds = new Set(operatorWatchlist.map((item) => item.bitrixUserId));
  const watchByUser = new Map(operatorWatchlist.map((item) => [item.bitrixUserId, item] as const));
  const result = await pool.query<any>(`
    SELECT
      s.bitrix_user_id,
      a.display_name,
      a.phone_normalized,
      a.email_normalized,
      COALESCE(a.bitrix_active, true) AS bitrix_active,
      s.overall_risk,
      s.risk_level,
      s.device_risk,
      s.linked_account_risk,
      s.identity_similarity_risk,
      s.visit_behavior_risk,
      s.historical_behavior_risk,
      s.history_gate,
      s.history_enriched,
      s.computed_at,
      COALESCE(
        json_agg(
          json_build_object(
            'code', r.reason_code,
            'score', r.score,
            'details', r.details
          ) ORDER BY r.score DESC, r.reason_code
        ) FILTER (WHERE r.id IS NOT NULL),
        '[]'::json
      ) AS reasons
    FROM anti_fraud_risk_scores s
    LEFT JOIN anti_fraud_accounts a ON a.bitrix_user_id = s.bitrix_user_id
    LEFT JOIN anti_fraud_risk_reasons r ON r.bitrix_user_id = s.bitrix_user_id
    WHERE ($1::text = '' OR s.risk_level = $1)
      AND (
        $2::text = ''
        OR s.bitrix_user_id::text ILIKE '%' || $2 || '%'
        OR COALESCE(a.display_name, '') ILIKE '%' || $2 || '%'
      )
    GROUP BY s.bitrix_user_id, a.bitrix_user_id
    ORDER BY (s.bitrix_user_id = ANY($4::int[])) DESC, s.overall_risk DESC, s.bitrix_user_id
    LIMIT $3
  `, [level, query, limit, operatorWatchlist.map((item) => item.bitrixUserId)]);

  return result.rows.map((row: any) => ({
    bitrixUserId: Number(row.bitrix_user_id),
    displayName: row.display_name ?? null,
    phoneMasked: maskPhone(row.phone_normalized),
    emailMasked: maskEmail(row.email_normalized),
    bitrixActive: Boolean(row.bitrix_active),
    overallRisk: Number(row.overall_risk ?? 0),
    riskLevel: row.risk_level,
    deviceRisk: Number(row.device_risk ?? 0),
    linkedAccountRisk: Number(row.linked_account_risk ?? 0),
    identitySimilarityRisk: Number(row.identity_similarity_risk ?? 0),
    visitBehaviorRisk: Number(row.visit_behavior_risk ?? 0),
    historicalBehaviorRisk: Number(row.historical_behavior_risk ?? 0),
    historyGate: Boolean(row.history_gate),
    historyEnriched: Boolean(row.history_enriched),
    computedAt: iso(row.computed_at) ?? new Date(0).toISOString(),
    reasons: Array.isArray(row.reasons)
      ? row.reasons.map((reason: any) => ({
          code: String(reason.code ?? ""),
          score: Number(reason.score ?? 0),
          details: String(reason.details ?? ""),
        }))
      : [],
    operatorWatched: watchedIds.has(Number(row.bitrix_user_id)),
    operatorLabel: watchByUser.get(Number(row.bitrix_user_id))?.label ?? null,
    operatorRiskOverride: watchByUser.get(Number(row.bitrix_user_id))?.riskOverride ?? null,
  }));
};

// Обновлено 08.09.2026 ИТ Директор Евразии:
// вкладка общих устройств отражает только активные незаблокированные аккаунты.
export const listAntiFraudWebDevices = async (limitValue = 200): Promise<AntiFraudWebDevice[]> => {
  await ensureReady();
  const limit = Math.max(1, Math.min(500, Math.floor(limitValue)));
  const result = await pool.query<any>(`
    SELECT
      left(l.device_hash, 16) AS device_prefix,
      count(DISTINCT l.bitrix_user_id)::int AS account_count,
      array_agg(DISTINCT l.bitrix_user_id ORDER BY l.bitrix_user_id) AS user_ids,
      max(l.last_seen_at) AS last_seen_at,
      array_remove(array_agg(DISTINCT l.client_type ORDER BY l.client_type), NULL) AS client_types
    FROM anti_fraud_device_links l
    LEFT JOIN anti_fraud_accounts a ON a.bitrix_user_id = l.bitrix_user_id
    WHERE COALESCE(a.bitrix_active, true) IS TRUE
      AND COALESCE(a.bitrix_blocked, false) IS NOT TRUE
    GROUP BY l.device_hash
    HAVING count(DISTINCT l.bitrix_user_id) > 1
    ORDER BY account_count DESC, max(l.last_seen_at) DESC NULLS LAST
    LIMIT $1
  `, [limit]);
  return result.rows.map((row: any) => ({
    devicePrefix: String(row.device_prefix ?? ""),
    accountCount: Number(row.account_count ?? 0),
    userIds: Array.isArray(row.user_ids) ? row.user_ids.map(Number) : [],
    lastSeenAt: iso(row.last_seen_at),
    clientTypes: Array.isArray(row.client_types) ? row.client_types.map(String) : [],
  }));
};

// Обновлено 08.09.2026 ИТ Директор Евразии:
// рекомендации по совпадающим контактам являются operational и исключают заблокированные и неактивные аккаунты.
export const listAntiFraudWebSimilarAccounts = async (limitValue = 100): Promise<AntiFraudWebSimilarGroup[]> => {
  await ensureReady();
  const limit = Math.max(1, Math.min(300, Math.floor(limitValue)));
  const result = await pool.query<any>(`
    WITH matches AS (
      SELECT 'phone'::text AS match_type, phone_normalized AS match_key, bitrix_user_id
      FROM anti_fraud_accounts
      WHERE phone_normalized IS NOT NULL
        AND phone_normalized <> ''
        AND COALESCE(bitrix_active, true) IS TRUE
        AND COALESCE(bitrix_blocked, false) IS NOT TRUE
      UNION ALL
      SELECT 'email'::text AS match_type, email_normalized AS match_key, bitrix_user_id
      FROM anti_fraud_accounts
      WHERE email_normalized IS NOT NULL
        AND email_normalized <> ''
        AND COALESCE(bitrix_active, true) IS TRUE
        AND COALESCE(bitrix_blocked, false) IS NOT TRUE
    ), duplicate_keys AS (
      SELECT match_type, match_key
      FROM matches
      GROUP BY match_type, match_key
      HAVING count(DISTINCT bitrix_user_id) > 1
    )
    SELECT
      m.match_type,
      count(DISTINCT m.bitrix_user_id)::int AS account_count,
      json_agg(
        json_build_object(
          'bitrixUserId', m.bitrix_user_id,
          'displayName', a.display_name,
          'overallRisk', COALESCE(s.overall_risk, 0),
          'riskLevel', COALESCE(s.risk_level, 'low')
        ) ORDER BY COALESCE(s.overall_risk, 0) DESC, m.bitrix_user_id
      ) AS accounts
    FROM duplicate_keys k
    JOIN matches m USING (match_type, match_key)
    LEFT JOIN anti_fraud_accounts a ON a.bitrix_user_id = m.bitrix_user_id
    LEFT JOIN anti_fraud_risk_scores s ON s.bitrix_user_id = m.bitrix_user_id
    GROUP BY m.match_type, m.match_key
    ORDER BY max(COALESCE(s.overall_risk, 0)) DESC, count(DISTINCT m.bitrix_user_id) DESC
    LIMIT $1
  `, [limit]);
  return result.rows.map((row: any) => ({
    matchType: row.match_type === "email" ? "email" : "phone",
    accountCount: Number(row.account_count ?? 0),
    accounts: Array.isArray(row.accounts)
      ? row.accounts.map((account: any) => ({
          bitrixUserId: Number(account.bitrixUserId),
          displayName: account.displayName ?? null,
          overallRisk: Number(account.overallRisk ?? 0),
          riskLevel: String(account.riskLevel ?? "low"),
        }))
      : [],
  }));
};