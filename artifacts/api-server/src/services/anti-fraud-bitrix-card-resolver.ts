import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import {
  BitrixAntiFraudCardGateway,
  type BitrixAntiFraudCardRecord,
} from "./bitrix-antifraud-card-gateway";

const SOURCE = "bitrix_card_map";
const LOCK_NAME = "anti_fraud_bitrix_card_map";
const DEFAULT_BATCH_SIZE = 500;
const MAX_BATCH_SIZE = 500;

type ResolverOptions = {
  batchSize?: number;
  gateway?: BitrixAntiFraudCardGateway;
};

type ExistingCardRow = {
  card_number: string;
  bitrix_user_id: number | null;
};

export type AntiFraudBitrixCardResolverResult = {
  runId: string;
  requestedCards: number;
  resolvedCards: number;
  unresolvedCards: number;
  ambiguousCards: number;
  updatedCards: number;
  updatedVisits: number;
};

const safeErrorMessage = (error: unknown): string => {
  const message = error instanceof Error ? error.message : "Неизвестная ошибка Bitrix card resolver";
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

const assertOwnersAreStable = (
  existingRows: ExistingCardRow[],
  resolvedByCard: Map<string, BitrixAntiFraudCardRecord>,
): void => {
  for (const row of existingRows) {
    const resolved = resolvedByCard.get(row.card_number);
    if (!resolved || row.bitrix_user_id === null) continue;
    if (row.bitrix_user_id !== resolved.bitrixUserId) {
      throw new Error(
        `Bitrix изменил владельца карты ${row.card_number}: ${row.bitrix_user_id} -> ${resolved.bitrixUserId}; синхронизация остановлена`,
      );
    }
  }
};

// Добавлено 03.09.2026 ИТ Директор Евразии
export const resolveBitrixCardsOnce = async (
  options: ResolverOptions = {},
): Promise<AntiFraudBitrixCardResolverResult> => {
  const gateway = options.gateway ?? new BitrixAntiFraudCardGateway();
  const batchSize = normalizeBatchSize(
    Number(options.batchSize ?? process.env.BITRIX_ANTI_FRAUD_BATCH_SIZE ?? DEFAULT_BATCH_SIZE),
  );
  const runId = randomUUID();
  const client = await pool.connect();
  let transactionOpen = false;
  let lockAcquired = false;

  try {
    const lockResult = await client.query<{ locked: boolean }>(
      "SELECT pg_try_advisory_lock(hashtext($1)) AS locked",
      [LOCK_NAME],
    );
    lockAcquired = lockResult.rows[0]?.locked === true;
    if (!lockAcquired) {
      throw new Error("Синхронизация Bitrix card-map уже выполняется другим процессом");
    }

    await client.query(
      `INSERT INTO anti_fraud_sync_runs (run_id, source, status, started_at)
       VALUES ($1, $2, 'running', now())`,
      [runId, SOURCE],
    );
    await client.query(
      `INSERT INTO anti_fraud_sync_state (source, last_started_at, last_error, updated_at)
       VALUES ($1, now(), NULL, now())
       ON CONFLICT (source) DO UPDATE SET
         last_started_at = EXCLUDED.last_started_at,
         last_error = NULL,
         updated_at = now()`,
      [SOURCE],
    );

    const cardsResult = await client.query<ExistingCardRow>(
      `SELECT card_number, bitrix_user_id
       FROM anti_fraud_cards
       ORDER BY card_number`,
    );
    const cardNumbers = cardsResult.rows.map((row) => row.card_number);

    const resolvedByCard = new Map<string, BitrixAntiFraudCardRecord>();
    let unresolvedCards = 0;
    let ambiguousCards = 0;

    for (const batch of chunk(cardNumbers, batchSize)) {
      if (!batch.length) continue;
      const response = await gateway.resolveCards(batch);
      for (const record of response.records) {
        resolvedByCard.set(record.cardNumber, record);
      }
      unresolvedCards += response.unresolved.length;
      ambiguousCards += response.ambiguous.length;
    }

    assertOwnersAreStable(cardsResult.rows, resolvedByCard);

    await client.query("BEGIN");
    transactionOpen = true;

    let updatedCards = 0;
    for (const record of resolvedByCard.values()) {
      const updated = await client.query(
        `UPDATE anti_fraud_cards
         SET bitrix_user_id = $2,
             card_type = $3,
             restis_state = $4,
             is_active = $5,
             resolved_at = COALESCE(resolved_at, now())
         WHERE card_number = $1
           AND (
             bitrix_user_id IS DISTINCT FROM $2 OR
             card_type IS DISTINCT FROM $3 OR
             restis_state IS DISTINCT FROM $4 OR
             is_active IS DISTINCT FROM $5 OR
             resolved_at IS NULL
           )
         RETURNING card_number`,
        [
          record.cardNumber,
          record.bitrixUserId,
          record.cardType,
          record.cardStatusId,
          record.isActive,
        ],
      );
      if (updated.rowCount) updatedCards += 1;
    }

    const visitConflict = await client.query<{
      restis_id: string;
      visit_user_id: number;
      card_user_id: number;
    }>(
      `SELECT v.restis_id,
              v.bitrix_user_id AS visit_user_id,
              c.bitrix_user_id AS card_user_id
       FROM anti_fraud_visits v
       JOIN anti_fraud_cards c ON c.card_number = v.card_number
       WHERE v.bitrix_user_id IS NOT NULL
         AND c.bitrix_user_id IS NOT NULL
         AND v.bitrix_user_id <> c.bitrix_user_id
       LIMIT 1`,
    );
    const conflict = visitConflict.rows[0];
    if (conflict) {
      throw new Error(
        `Конфликт владельца посещения RestIS ${conflict.restis_id}: ${conflict.visit_user_id} != ${conflict.card_user_id}`,
      );
    }

    const visitsUpdated = await client.query(
      `UPDATE anti_fraud_visits v
       SET bitrix_user_id = c.bitrix_user_id,
           resolved_at = COALESCE(v.resolved_at, now())
       FROM anti_fraud_cards c
       WHERE v.card_number = c.card_number
         AND c.bitrix_user_id IS NOT NULL
         AND (
           v.bitrix_user_id IS NULL OR
           v.resolved_at IS NULL
         )`,
    );
    const updatedVisits = visitsUpdated.rowCount ?? 0;
    const resolvedCards = resolvedByCard.size;

    await client.query(
      `UPDATE anti_fraud_sync_runs
       SET status = 'success', finished_at = now(), records_fetched = $2,
           records_written = $3, records_resolved = $4, error = NULL
       WHERE run_id = $1`,
      [runId, cardNumbers.length, updatedCards, resolvedCards],
    );
    await client.query(
      `UPDATE anti_fraud_sync_state
       SET cursor = NULL, last_succeeded_at = now(), last_error = NULL,
           records_fetched = $2, records_written = $3, records_resolved = $4,
           updated_at = now()
       WHERE source = $1`,
      [SOURCE, cardNumbers.length, updatedCards, resolvedCards],
    );

    await client.query("COMMIT");
    transactionOpen = false;

    return {
      runId,
      requestedCards: cardNumbers.length,
      resolvedCards,
      unresolvedCards,
      ambiguousCards,
      updatedCards,
      updatedVisits,
    };
  } catch (error) {
    if (transactionOpen) {
      await client.query("ROLLBACK");
      transactionOpen = false;
    }

    const message = safeErrorMessage(error);
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
      // Ошибка фиксации статуса не должна скрывать исходную ошибку синхронизации.
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
