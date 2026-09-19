import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import {
  BitrixAntiFraudAccountGateway,
  type BitrixAntiFraudAccountRecord,
} from "./bitrix-antifraud-account-gateway";

const SOURCE = "bitrix_account_map";
const LOCK_NAME = "anti_fraud_bitrix_account_map";
const DEFAULT_BATCH_SIZE = 500;
const MAX_BATCH_SIZE = 500;

type CollectorOptions = {
  batchSize?: number;
  gateway?: BitrixAntiFraudAccountGateway;
};

export type AntiFraudBitrixAccountCollectorResult = {
  runId: string;
  requestedAccounts: number;
  resolvedAccounts: number;
  unresolvedAccounts: number;
  updatedAccounts: number;
};

const safeErrorMessage = (error: unknown): string => {
  const message = error instanceof Error ? error.message : "Неизвестная ошибка Bitrix account collector";
  return message.slice(0, 2000);
};

const normalizeBatchSize = (value: number): number => {
  if (!Number.isInteger(value) || value <= 0) return DEFAULT_BATCH_SIZE;
  return Math.min(MAX_BATCH_SIZE, value);
};

const chunk = <T>(values: T[], size: number): T[][] => {
  const result: T[][] = [];
  for (let index = 0; index < values.length; index += size) {
    result.push(values.slice(index, index + size));
  }
  return result;
};

// Добавлено 03.09.2026 ИТ Директор Евразии
// В Bitrix запрашиваются только USER_ID, уже попавшие в Anti-Fraud через карты, устройства или ранее известные аккаунты.
// Массовая выгрузка всей базы пользователей Bitrix этим collector не поддерживается.
// Обновлено 19.09.2026: USER_ID из Check-in Scout попадает сюда через anti_fraud_visits,
// поэтому новый частотный кандидат получает account-map в том же protected cycle.
// Обновлено 05.09.2026: bonus_balance здесь больше не читается и не перезаписывается —
// текущий TotalSum приходит только через защищённый loyalty endpoint.
// Обновлено 07.09.2026: ACTIVE/BLOCKED/основание каждый refresh перечитываются из Bitrix;
// Bitrix является master/source of truth для фактического статуса пользователя.
// Обновлено 12.09.2026: актуальные Оферта/ПД синхронизируются как операторский read-only snapshot.
export const syncBitrixAccountsOnce = async (
  options: CollectorOptions = {},
): Promise<AntiFraudBitrixAccountCollectorResult> => {
  const gateway = options.gateway ?? new BitrixAntiFraudAccountGateway();
  const batchSize = normalizeBatchSize(
    Number(options.batchSize ?? process.env.BITRIX_ANTI_FRAUD_ACCOUNT_BATCH_SIZE ?? DEFAULT_BATCH_SIZE),
  );
  const runId = randomUUID();
  const client = await pool.connect();
  let transactionOpen = false;
  let lockAcquired = false;
  let runCreated = false;

  try {
    const lockResult = await client.query<{ locked: boolean }>(
      "SELECT pg_try_advisory_lock(hashtext($1)) AS locked",
      [LOCK_NAME],
    );
    lockAcquired = lockResult.rows[0]?.locked === true;
    if (!lockAcquired) {
      throw new Error("Синхронизация Bitrix account-map уже выполняется другим процессом");
    }

    await client.query(
      `INSERT INTO anti_fraud_sync_runs (run_id, source, status, started_at)
       VALUES ($1, $2, 'running', now())`,
      [runId, SOURCE],
    );
    runCreated = true;

    await client.query(
      `INSERT INTO anti_fraud_sync_state (source, last_started_at, last_error, updated_at)
       VALUES ($1, now(), NULL, now())
       ON CONFLICT (source) DO UPDATE SET
         last_started_at = EXCLUDED.last_started_at,
         last_error = NULL,
         updated_at = now()`,
      [SOURCE],
    );

    const candidateResult = await client.query<{ bitrix_user_id: number }>(
      `SELECT bitrix_user_id
       FROM (
         SELECT bitrix_user_id
         FROM anti_fraud_cards
         WHERE bitrix_user_id IS NOT NULL

         UNION

         SELECT bitrix_user_id
         FROM anti_fraud_device_links

         UNION

         SELECT bitrix_user_id
         FROM anti_fraud_device_events

         UNION

         SELECT bitrix_user_id
         FROM anti_fraud_visits
         WHERE bitrix_user_id IS NOT NULL

         UNION

         SELECT bitrix_user_id
         FROM anti_fraud_accounts
       ) AS candidates
       WHERE bitrix_user_id > 0
       ORDER BY bitrix_user_id`,
    );

    const userIds = candidateResult.rows.map((row) => Number(row.bitrix_user_id));
    const resolvedById = new Map<number, BitrixAntiFraudAccountRecord>();
    let unresolvedAccounts = 0;

    for (const batch of chunk(userIds, batchSize)) {
      if (!batch.length) continue;
      const response = await gateway.resolveAccounts(batch);
      for (const record of response.records) {
        resolvedById.set(record.bitrixUserId, record);
      }
      unresolvedAccounts += response.unresolved.length;
    }

    await client.query("BEGIN");
    transactionOpen = true;

    let updatedAccounts = 0;

    for (const record of resolvedById.values()) {
      const updated = await client.query(
        `INSERT INTO anti_fraud_accounts (
           bitrix_user_id,
           phone_normalized,
           email_normalized,
           display_name,
           registered_at,
           bitrix_active,
           bitrix_blocked,
           bitrix_block_reason,
           offer_accepted,
           offer_accepted_at,
           offer_source,
           pd_accepted,
           pd_accepted_at,
           pd_source,
           last_synced_at
         )
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, now())
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
           last_synced_at = now()
         WHERE
           anti_fraud_accounts.phone_normalized IS DISTINCT FROM EXCLUDED.phone_normalized OR
           anti_fraud_accounts.email_normalized IS DISTINCT FROM EXCLUDED.email_normalized OR
           anti_fraud_accounts.display_name IS DISTINCT FROM EXCLUDED.display_name OR
           anti_fraud_accounts.registered_at IS DISTINCT FROM EXCLUDED.registered_at OR
           anti_fraud_accounts.bitrix_active IS DISTINCT FROM EXCLUDED.bitrix_active OR
           anti_fraud_accounts.bitrix_blocked IS DISTINCT FROM EXCLUDED.bitrix_blocked OR
           anti_fraud_accounts.bitrix_block_reason IS DISTINCT FROM EXCLUDED.bitrix_block_reason OR
           anti_fraud_accounts.offer_accepted IS DISTINCT FROM EXCLUDED.offer_accepted OR
           anti_fraud_accounts.offer_accepted_at IS DISTINCT FROM EXCLUDED.offer_accepted_at OR
           anti_fraud_accounts.offer_source IS DISTINCT FROM EXCLUDED.offer_source OR
           anti_fraud_accounts.pd_accepted IS DISTINCT FROM EXCLUDED.pd_accepted OR
           anti_fraud_accounts.pd_accepted_at IS DISTINCT FROM EXCLUDED.pd_accepted_at OR
           anti_fraud_accounts.pd_source IS DISTINCT FROM EXCLUDED.pd_source
         RETURNING bitrix_user_id`,
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

      if (updated.rowCount) updatedAccounts += 1;

      // Если данные не изменились, всё равно обновляем момент успешной проверки источника.
      if (!updated.rowCount) {
        await client.query(
          `UPDATE anti_fraud_accounts
           SET last_synced_at = now()
           WHERE bitrix_user_id = $1`,
          [record.bitrixUserId],
        );
      }
    }

    const resolvedAccounts = resolvedById.size;

    await client.query(
      `UPDATE anti_fraud_sync_runs
       SET status = 'success', finished_at = now(), records_fetched = $2,
           records_written = $3, records_resolved = $4, error = NULL
       WHERE run_id = $1`,
      [runId, userIds.length, updatedAccounts, resolvedAccounts],
    );

    await client.query(
      `UPDATE anti_fraud_sync_state
       SET cursor = NULL, last_succeeded_at = now(), last_error = NULL,
           records_fetched = $2, records_written = $3, records_resolved = $4,
           updated_at = now()
       WHERE source = $1`,
      [SOURCE, userIds.length, updatedAccounts, resolvedAccounts],
    );

    await client.query("COMMIT");
    transactionOpen = false;

    return {
      runId,
      requestedAccounts: userIds.length,
      resolvedAccounts,
      unresolvedAccounts,
      updatedAccounts,
    };
  } catch (error) {
    if (transactionOpen) {
      await client.query("ROLLBACK");
      transactionOpen = false;
    }

    const message = safeErrorMessage(error);
    if (runCreated) {
      try {
        await client.query(
          `UPDATE anti_fraud_sync_runs
           SET status = 'failed', finished_at = now(), error = $2
           WHERE run_id = $1`,
          [runId, message],
        );
        await client.query(
          `UPDATE anti_fraud_sync_state
           SET last_error = $2, updated_at = now()
           WHERE source = $1`,
          [SOURCE, message],
        );
      } catch {
        // Ошибка фиксации статуса не должна скрывать исходную ошибку account collector.
      }
    }
    throw error;
  } finally {
    if (lockAcquired) {
      try {
        await client.query("SELECT pg_advisory_unlock(hashtext($1))", [LOCK_NAME]);
      } catch {
        // Соединение всё равно будет освобождено ниже.
      }
    }
    client.release();
  }
};
