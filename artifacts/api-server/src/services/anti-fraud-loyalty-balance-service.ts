import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import {
  BitrixAntiFraudLoyaltyGateway,
  type BitrixAntiFraudLoyaltyRecord,
} from "./bitrix-antifraud-loyalty-gateway";

const SOURCE = "bitrix_loyalty_balance";
const LOCK_NAME = "anti_fraud_bitrix_loyalty_balance";
const API_BATCH_SIZE = 50;
const DEFAULT_SCAN_BATCH_SIZE = 50;
const MAX_SCAN_BATCH_SIZE = 200;

export type AntiFraudLoyaltyBalanceRefreshOptions = {
  userIds: number[];
  gateway?: BitrixAntiFraudLoyaltyGateway;
};

export type AntiFraudLoyaltyBalanceScanOptions = {
  limit?: number;
  gateway?: BitrixAntiFraudLoyaltyGateway;
};

export type AntiFraudLoyaltyBalanceRefreshResult = {
  runId: string;
  requestedAccounts: number;
  resolvedAccounts: number;
  unresolvedAccounts: number;
  updatedAccounts: number;
  activeCardAccounts: number;
};

const safeErrorMessage = (error: unknown): string => {
  const message = error instanceof Error ? error.message : "Неизвестная ошибка loyalty balance refresh";
  return message.slice(0, 2000);
};

const normalizeUserIds = (values: number[]): number[] => {
  const result = new Set<number>();
  for (const raw of values) {
    const value = Number(raw);
    if (!Number.isInteger(value) || value <= 0) {
      throw new Error("Loyalty balance refresh принимает только положительные USER_ID");
    }
    result.add(value);
  }
  if (!result.size || result.size > MAX_SCAN_BATCH_SIZE) {
    throw new Error(`Loyalty balance refresh принимает от 1 до ${MAX_SCAN_BATCH_SIZE} USER_ID`);
  }
  return [...result];
};

const chunk = <T>(values: T[], size: number): T[][] => {
  const result: T[][] = [];
  for (let index = 0; index < values.length; index += size) {
    result.push(values.slice(index, index + size));
  }
  return result;
};

const normalizeScanLimit = (value: number): number => {
  if (!Number.isInteger(value) || value <= 0) return DEFAULT_SCAN_BATCH_SIZE;
  return Math.min(MAX_SCAN_BATCH_SIZE, value);
};

const persistLoyaltyRecords = async (
  records: BitrixAntiFraudLoyaltyRecord[],
): Promise<number> => {
  const client = await pool.connect();
  let transactionOpen = false;
  try {
    await client.query("BEGIN");
    transactionOpen = true;
    let updatedAccounts = 0;

    for (const record of records) {
      const result = await client.query(
        `UPDATE anti_fraud_accounts
         SET bonus_balance = $2::numeric(14,2),
             loyalty_synced_at = now()
         WHERE bitrix_user_id = $1
           AND (
             bonus_balance IS DISTINCT FROM $2::numeric(14,2)
             OR loyalty_synced_at IS NULL
           )
         RETURNING bitrix_user_id`,
        [record.bitrixUserId, record.bonusBalance],
      );
      if (result.rowCount) updatedAccounts += 1;

      if (!result.rowCount) {
        await client.query(
          `UPDATE anti_fraud_accounts
           SET loyalty_synced_at = now()
           WHERE bitrix_user_id = $1`,
          [record.bitrixUserId],
        );
      }
    }

    await client.query("COMMIT");
    transactionOpen = false;
    return updatedAccounts;
  } catch (error) {
    if (transactionOpen) await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
};

// Добавлено 05.09.2026 ИТ Директор Евразии
// Адресный refresh использует только защищённый site-side loyalty endpoint.
// RestIS credentials и номер активной карты в бот не передаются.
export const refreshLoyaltyBalancesForUsersOnce = async (
  options: AntiFraudLoyaltyBalanceRefreshOptions,
): Promise<AntiFraudLoyaltyBalanceRefreshResult> => {
  const userIds = normalizeUserIds(options.userIds);
  const gateway = options.gateway ?? new BitrixAntiFraudLoyaltyGateway();
  const runId = randomUUID();
  const lockClient = await pool.connect();
  let lockAcquired = false;
  let runCreated = false;

  try {
    const lock = await lockClient.query<{ locked: boolean }>(
      "SELECT pg_try_advisory_lock(hashtext($1)) AS locked",
      [LOCK_NAME],
    );
    lockAcquired = lock.rows[0]?.locked === true;
    if (!lockAcquired) {
      throw new Error("Loyalty balance refresh уже выполняется другим процессом");
    }

    await lockClient.query(
      `INSERT INTO anti_fraud_sync_runs (run_id, source, status, started_at)
       VALUES ($1,$2,'running',now())`,
      [runId, SOURCE],
    );
    runCreated = true;

    const records: BitrixAntiFraudLoyaltyRecord[] = [];
    let unresolvedAccounts = 0;

    for (const batch of chunk(userIds, API_BATCH_SIZE)) {
      const response = await gateway.resolveLoyalty(batch, { includeHistory: false });
      records.push(...response.records);
      unresolvedAccounts += response.unresolved.length;
    }

    const updatedAccounts = await persistLoyaltyRecords(records);
    const activeCardAccounts = records.filter((record) => record.activeCardFound).length;

    await lockClient.query(
      `UPDATE anti_fraud_sync_runs
       SET status='success', finished_at=now(), records_fetched=$2,
           records_written=$3, records_resolved=$4, error=NULL
       WHERE run_id=$1`,
      [runId, userIds.length, updatedAccounts, records.length],
    );

    return {
      runId,
      requestedAccounts: userIds.length,
      resolvedAccounts: records.length,
      unresolvedAccounts,
      updatedAccounts,
      activeCardAccounts,
    };
  } catch (error) {
    if (runCreated) {
      try {
        await lockClient.query(
          `UPDATE anti_fraud_sync_runs
           SET status='failed', finished_at=now(), error=$2
           WHERE run_id=$1`,
          [runId, safeErrorMessage(error)],
        );
      } catch {
        // Не скрываем исходную ошибку.
      }
    }
    throw error;
  } finally {
    if (lockAcquired) {
      try {
        await lockClient.query("SELECT pg_advisory_unlock(hashtext($1))", [LOCK_NAME]);
      } catch {
        // Соединение освобождается ниже.
      }
    }
    lockClient.release();
  }
};

// Добавлено 05.09.2026 ИТ Директор Евразии
// Фоновый scan обновляет только небольшую порцию самых давно не проверявшихся аккаунтов.
// При 50 аккаунтах в час ~1800 известных аккаунтов проходят полный цикл примерно за 36 часов,
// без одномоментных тысяч RestIS Balance-запросов.
export const refreshStalestLoyaltyBalancesOnce = async (
  options: AntiFraudLoyaltyBalanceScanOptions = {},
): Promise<AntiFraudLoyaltyBalanceRefreshResult | null> => {
  const limit = normalizeScanLimit(
    Number(options.limit ?? process.env.ANTI_FRAUD_LOYALTY_SCAN_BATCH_SIZE ?? DEFAULT_SCAN_BATCH_SIZE),
  );
  const candidates = await pool.query<{ bitrix_user_id: number }>(
    `SELECT bitrix_user_id
     FROM anti_fraud_accounts
     WHERE bitrix_active IS TRUE
     ORDER BY loyalty_synced_at ASC NULLS FIRST, bitrix_user_id
     LIMIT $1`,
    [limit],
  );
  const userIds = candidates.rows.map((row) => Number(row.bitrix_user_id));
  if (!userIds.length) return null;
  return refreshLoyaltyBalancesForUsersOnce({ userIds, gateway: options.gateway });
};
