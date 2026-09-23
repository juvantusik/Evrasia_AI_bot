import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import {
  BitrixAntiFraudAccountGateway,
  type BitrixAntiFraudAccountRecord,
} from "./bitrix-antifraud-account-gateway";
import { analyzeAntiFraudWithSimilarityOnce } from "./anti-fraud-identity-similarity-service";
import { BitrixAntiFraudCheckinGateway } from "./bitrix-antifraud-checkin-gateway";
import { enrichRestisHistoryForOperatorInvestigationOnce } from "./anti-fraud-restis-history-enricher";
import { refreshAntiFraudOperatorPhysicalHistoryOnce } from "./anti-fraud-operator-physical-history-service";

const DEFAULT_BATCH_LIMIT = 5;
const MAX_BATCH_LIMIT = 20;

export type AntiFraudOperatorInvestigationStatus =
  | "pending"
  | "processing"
  | "history_ready"
  | "ready"
  | "failed";

export type AntiFraudOperatorInvestigation = {
  investigationId: string;
  bitrixUserId: number;
  source: string;
  reason: string | null;
  status: AntiFraudOperatorInvestigationStatus;
  requestedAt: string;
  startedAt: string | null;
  historyCompletedAt: string | null;
  physicalHistoryFrom: string | null;
  physicalHistoryUntil: string | null;
  scoringCompletedAt: string | null;
  completedAt: string | null;
  lastError: string | null;
  updatedAt: string;
};

type InvestigationRow = {
  investigation_id: string;
  bitrix_user_id: number;
  source: string;
  reason: string | null;
  status: AntiFraudOperatorInvestigationStatus;
  requested_at: Date;
  started_at: Date | null;
  history_completed_at: Date | null;
  physical_history_from: Date | null;
  physical_history_until: Date | null;
  scoring_completed_at: Date | null;
  completed_at: Date | null;
  last_error: string | null;
  updated_at: Date;
};

const iso = (value: Date | null): string | null => value ? value.toISOString() : null;

const mapInvestigation = (row: InvestigationRow): AntiFraudOperatorInvestigation => ({
  investigationId: row.investigation_id,
  bitrixUserId: Number(row.bitrix_user_id),
  source: row.source,
  reason: row.reason,
  status: row.status,
  requestedAt: row.requested_at.toISOString(),
  startedAt: iso(row.started_at),
  historyCompletedAt: iso(row.history_completed_at),
  physicalHistoryFrom: iso(row.physical_history_from),
  physicalHistoryUntil: iso(row.physical_history_until),
  scoringCompletedAt: iso(row.scoring_completed_at),
  completedAt: iso(row.completed_at),
  lastError: row.last_error,
  updatedAt: row.updated_at.toISOString(),
});

const normalizeText = (
  value: unknown,
  field: string,
  maximum: number,
  required: boolean,
): string | null => {
  if (value === null || value === undefined) {
    if (required) throw new Error(`${field} обязателен`);
    return null;
  }
  if (typeof value !== "string") throw new Error(`${field} должен быть строкой`);
  const text = value.trim();
  if (!text) {
    if (required) throw new Error(`${field} обязателен`);
    return null;
  }
  if (text.length > maximum || /[\u0000-\u001F\u007F]/.test(text)) {
    throw new Error(`${field} содержит недопустимое значение`);
  }
  return text;
};

const safeError = (error: unknown): string =>
  (error instanceof Error ? error.message : "Неизвестная ошибка ручной Anti-Fraud проверки")
    .slice(0, 1000);

const upsertAccount = async (record: BitrixAntiFraudAccountRecord): Promise<void> => {
  await pool.query(
    `INSERT INTO anti_fraud_accounts (
       bitrix_user_id, phone_normalized, email_normalized, display_name, registered_at,
       bitrix_active, bitrix_blocked, bitrix_block_reason,
       offer_accepted, offer_accepted_at, offer_source,
       pd_accepted, pd_accepted_at, pd_source, last_synced_at
     )
     VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,now())
     ON CONFLICT (bitrix_user_id) DO UPDATE SET
       phone_normalized = EXCLUDED.phone_normalized,
       email_normalized = EXCLUDED.email_normalized,
       display_name = EXCLUDED.display_name,
       registered_at = EXCLUDED.registered_at,
       bitrix_active = EXCLUDED.bitrix_active,
       bitrix_blocked = EXCLUDED.bitrix_blocked,
       bitrix_block_reason = EXCLUDED.bitrix_block_reason,
       offer_accepted = EXCLUDED.offer_accepted,
       offer_accepted_at = EXCLUDED.offer_accepted_at,
       offer_source = EXCLUDED.offer_source,
       pd_accepted = EXCLUDED.pd_accepted,
       pd_accepted_at = EXCLUDED.pd_accepted_at,
       pd_source = EXCLUDED.pd_source,
       last_synced_at = now()`,
    [
      record.bitrixUserId,
      record.phoneNormalized,
      record.emailNormalized,
      record.displayName,
      record.registeredAt,
      record.bitrixActive,
      record.bitrixBlocked,
      record.bitrixBlockReason,
      record.offerAccepted,
      record.offerAcceptedAt,
      record.offerSource,
      record.pdAccepted,
      record.pdAcceptedAt,
      record.pdSource,
    ],
  );
};

export const createAntiFraudOperatorInvestigationForUser = async (input: {
  bitrixUserId: number;
  source: string;
  reason?: string | null;
}): Promise<AntiFraudOperatorInvestigation> => {
  const bitrixUserId = Number(input.bitrixUserId);
  if (!Number.isInteger(bitrixUserId) || bitrixUserId <= 0) {
    throw new Error("USER_ID должен быть положительным integer");
  }
  const source = normalizeText(input.source, "Источник проверки", 80, true) as string;
  const reason = normalizeText(input.reason, "Комментарий", 500, false);
  const investigationId = randomUUID();
  const client = await pool.connect();

  try {
    await client.query("BEGIN");
    const result = await client.query<InvestigationRow>(
      `INSERT INTO anti_fraud_operator_investigations (
         investigation_id, bitrix_user_id, source, reason, status, requested_at, updated_at
       )
       VALUES ($1,$2,$3,$4,'pending',now(),now())
       RETURNING *`,
      [investigationId, bitrixUserId, source, reason],
    );

    // Ручная проверка немедленно делает USER_ID web-visible. Текущая семантика
    // «Новый» = 24 часа от первого появления в web Anti-Fraud, поэтому first_seen
    // фиксируется атомарно при создании investigation. Повторная проверка не
    // продлевает окно благодаря ON CONFLICT DO NOTHING.
    await client.query(
      `INSERT INTO anti_fraud_web_account_state (bitrix_user_id, first_seen_at)
       VALUES ($1, now())
       ON CONFLICT (bitrix_user_id) DO NOTHING`,
      [bitrixUserId],
    );

    await client.query("COMMIT");
    return mapInvestigation(result.rows[0]);
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
};

export const listAntiFraudOperatorInvestigations = async (
  limitValue = 100,
): Promise<AntiFraudOperatorInvestigation[]> => {
  const limit = Number.isInteger(limitValue)
    ? Math.max(1, Math.min(500, limitValue))
    : 100;
  const result = await pool.query<InvestigationRow>(
    `SELECT *
     FROM anti_fraud_operator_investigations
     ORDER BY requested_at DESC
     LIMIT $1`,
    [limit],
  );
  return result.rows.map(mapInvestigation);
};

const recoverStaleProcessing = async (): Promise<void> => {
  await pool.query(
    `UPDATE anti_fraud_operator_investigations
     SET status = CASE WHEN history_completed_at IS NULL THEN 'pending' ELSE 'history_ready' END,
         last_error = 'Восстановлено после прерванной обработки',
         updated_at = now()
     WHERE status = 'processing'
       AND updated_at < now() - interval '60 minutes'`,
  );
};

const claimPending = async (investigationId?: string): Promise<InvestigationRow | null> => {
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    const selected = await client.query<InvestigationRow>(
      `SELECT *
       FROM anti_fraud_operator_investigations
       WHERE status IN ('pending','history_ready')
         AND ($1::text IS NULL OR investigation_id = $1)
       ORDER BY requested_at
       FOR UPDATE SKIP LOCKED
       LIMIT 1`,
      [investigationId ?? null],
    );
    const row = selected.rows[0];
    if (!row) {
      await client.query("COMMIT");
      return null;
    }
    const updated = await client.query<InvestigationRow>(
      `UPDATE anti_fraud_operator_investigations
       SET status='processing', started_at=COALESCE(started_at, now()),
           last_error=NULL, updated_at=now()
       WHERE investigation_id=$1
       RETURNING *`,
      [row.investigation_id],
    );
    await client.query("COMMIT");
    return updated.rows[0] ?? null;
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
};

const processInvestigation = async (
  investigation: InvestigationRow,
  gateway = new BitrixAntiFraudAccountGateway(),
  checkinGateway = new BitrixAntiFraudCheckinGateway(),
): Promise<void> => {
  try {
    const accountMap = await gateway.resolveAccounts([Number(investigation.bitrix_user_id)]);
    const account = accountMap.records[0];
    if (!account || accountMap.unresolved.length) {
      throw new Error("Bitrix account-map не разрешил USER_ID ручной проверки");
    }
    await upsertAccount(account);

    if (!investigation.history_completed_at) {
      await enrichRestisHistoryForOperatorInvestigationOnce({
        bitrixUserId: Number(investigation.bitrix_user_id),
        investigationId: investigation.investigation_id,
        lookbackDays: 60,
      });

      // Физическая история операторской проверки берётся из targeted Check-in,
      // а не из VIP_HISTORY/anti_fraud_visits. Snapshot живёт отдельно и поэтому
      // не меняет входные данные automatic risk scoring.
      await refreshAntiFraudOperatorPhysicalHistoryOnce({
        bitrixUserId: Number(investigation.bitrix_user_id),
        investigationId: investigation.investigation_id,
        lookbackDays: 60,
        gateway: checkinGateway,
      });

      await pool.query(
        `UPDATE anti_fraud_operator_investigations
         SET status='history_ready', history_completed_at=now(), updated_at=now()
         WHERE investigation_id=$1`,
        [investigation.investigation_id],
      );
    }

    // Операторская причина не превращается в automatic risk reason.
    // После ручного 60-day enrichment применяется обычный explainable scoring.
    await analyzeAntiFraudWithSimilarityOnce({
      refreshAccounts: false,
      autoHistory: false,
    });

    await pool.query(
      `UPDATE anti_fraud_operator_investigations
       SET status='ready', scoring_completed_at=now(), completed_at=now(),
           last_error=NULL, updated_at=now()
       WHERE investigation_id=$1`,
      [investigation.investigation_id],
    );
  } catch (error) {
    await pool.query(
      `UPDATE anti_fraud_operator_investigations
       SET status='failed', last_error=$2, completed_at=now(), updated_at=now()
       WHERE investigation_id=$1`,
      [investigation.investigation_id, safeError(error)],
    );
  }
};

export const processAntiFraudOperatorInvestigationById = async (
  investigationIdValue: string,
  options?: {
    gateway?: BitrixAntiFraudAccountGateway;
    checkinGateway?: BitrixAntiFraudCheckinGateway;
  },
): Promise<{ processed: boolean }> => {
  const investigationId = String(investigationIdValue ?? "").trim();
  if (!investigationId || investigationId.length > 100) {
    throw new Error("Некорректный investigation_id");
  }

  await recoverStaleProcessing();
  const investigation = await claimPending(investigationId);
  if (!investigation) return { processed: false };

  await processInvestigation(investigation, options?.gateway, options?.checkinGateway);
  return { processed: true };
};

export const processPendingAntiFraudOperatorInvestigationsOnce = async (options?: {
  limit?: number;
  gateway?: BitrixAntiFraudAccountGateway;
  checkinGateway?: BitrixAntiFraudCheckinGateway;
}): Promise<{ processed: number }> => {
  const requested = Number(options?.limit ?? DEFAULT_BATCH_LIMIT);
  const limit = Number.isInteger(requested)
    ? Math.max(1, Math.min(MAX_BATCH_LIMIT, requested))
    : DEFAULT_BATCH_LIMIT;
  let processed = 0;
  await recoverStaleProcessing();

  for (let index = 0; index < limit; index += 1) {
    const investigation = await claimPending();
    if (!investigation) break;
    await processInvestigation(investigation, options?.gateway, options?.checkinGateway);
    processed += 1;
  }

  return { processed };
};
