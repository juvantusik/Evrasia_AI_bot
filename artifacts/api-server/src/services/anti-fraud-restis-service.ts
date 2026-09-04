import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import {
  RestisAntiFraudGateway,
  type RestisVisitEvent,
} from "./restis-antifraud-gateway";

const SOURCE = "restis_vip_today";
const LOCK_NAME = "anti_fraud_restis_vip_today";

export type AntiFraudRestisSyncResult = {
  runId: string;
  rawRows: number;
  uniqueEvents: number;
  writtenEvents: number;
  seenCards: number;
};

type RestisCollectorOptions = {
  pageSize?: number;
  gateway?: RestisAntiFraudGateway;
};

type ExistingVisitRow = {
  restis_id: string;
  card_number: string;
  visited_at: Date;
  restaurant: string;
};

const safeErrorMessage = (error: unknown): string => {
  const message = error instanceof Error ? error.message : "Неизвестная ошибка RestIS collector";
  return message.slice(0, 2000);
};

const newestCursor = (visits: RestisVisitEvent[]): string | null => {
  if (!visits.length) return null;
  const newest = [...visits].sort((a, b) => {
    const timeDiff = b.visitedAt.getTime() - a.visitedAt.getTime();
    return timeDiff || b.restisId.localeCompare(a.restisId);
  })[0];
  return `${newest.visitedAt.toISOString()}|${newest.restisId}`;
};

const assertExistingVisitsAreStable = (
  visits: RestisVisitEvent[],
  existingRows: ExistingVisitRow[],
): Set<string> => {
  const incoming = new Map(visits.map((visit) => [visit.restisId, visit]));
  const existingIds = new Set<string>();

  for (const row of existingRows) {
    const visit = incoming.get(row.restis_id);
    if (!visit) continue;
    existingIds.add(row.restis_id);

    const same =
      row.card_number === visit.cardNumber &&
      row.visited_at.getTime() === visit.visitedAt.getTime() &&
      row.restaurant === visit.restaurant;

    if (!same) {
      throw new Error(
        `RestIS изменил ранее сохранённое событие ID=${row.restis_id}; синхронизация остановлена`,
      );
    }
  }

  return existingIds;
};

// Добавлено 03.09.2026 ИТ Директор Евразии
export const collectRestisVisitsOnce = async (
  options: RestisCollectorOptions = {},
): Promise<AntiFraudRestisSyncResult> => {
  const gateway = options.gateway ?? new RestisAntiFraudGateway();
  const pageSize = Number(options.pageSize ?? process.env.RESTIS_VIP_TODAY_PAGE_SIZE ?? 1000);
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
      throw new Error("Синхронизация RestIS уже выполняется другим процессом");
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

    const batch = await gateway.fetchVipToday(pageSize);
    const cards = [...new Set(batch.visits.map((visit) => visit.cardNumber))];
    const cursor = newestCursor(batch.visits);
    const incomingIds = batch.visits.map((visit) => visit.restisId);

    await client.query("BEGIN");
    transactionOpen = true;

    for (const cardNumber of cards) {
      await client.query(
        `INSERT INTO anti_fraud_cards (card_number, first_seen_at, last_seen_at)
         VALUES ($1, now(), now())
         ON CONFLICT (card_number) DO UPDATE SET last_seen_at = now()`,
        [cardNumber],
      );
    }

    let existingIds = new Set<string>();
    if (incomingIds.length) {
      const existing = await client.query<ExistingVisitRow>(
        `SELECT restis_id, card_number, visited_at, restaurant
         FROM anti_fraud_visits
         WHERE restis_id = ANY($1::text[])`,
        [incomingIds],
      );
      existingIds = assertExistingVisitsAreStable(batch.visits, existing.rows);
    }

    let writtenEvents = 0;
    for (const visit of batch.visits) {
      if (existingIds.has(visit.restisId)) continue;

      const inserted = await client.query(
        `INSERT INTO anti_fraud_visits
           (restis_id, card_number, bitrix_user_id, visited_at, restaurant, synced_at, resolved_at)
         VALUES (
           $1,
           $2,
           (SELECT bitrix_user_id FROM anti_fraud_cards WHERE card_number = $2),
           $3,
           $4,
           now(),
           CASE
             WHEN (SELECT bitrix_user_id FROM anti_fraud_cards WHERE card_number = $2) IS NULL
               THEN NULL
             ELSE now()
           END
         )
         ON CONFLICT (restis_id) DO NOTHING
         RETURNING restis_id`,
        [visit.restisId, visit.cardNumber, visit.visitedAt, visit.restaurant],
      );
      if (inserted.rowCount) writtenEvents += 1;
    }

    let recordsResolved = 0;
    if (incomingIds.length) {
      const resolvedResult = await client.query<{ count: string }>(
        `SELECT count(*)::text AS count
         FROM anti_fraud_visits
         WHERE restis_id = ANY($1::text[])
           AND bitrix_user_id IS NOT NULL`,
        [incomingIds],
      );
      recordsResolved = Number(resolvedResult.rows[0]?.count ?? 0);
    }

    await client.query(
      `UPDATE anti_fraud_sync_runs
       SET status = 'success', finished_at = now(), records_fetched = $2,
           records_written = $3, records_resolved = $4, error = NULL
       WHERE run_id = $1`,
      [runId, batch.rawRows, writtenEvents, recordsResolved],
    );
    await client.query(
      `UPDATE anti_fraud_sync_state
       SET cursor = $2, last_succeeded_at = now(), last_error = NULL,
           records_fetched = $3, records_written = $4, records_resolved = $5,
           updated_at = now()
       WHERE source = $1`,
      [SOURCE, cursor, batch.rawRows, writtenEvents, recordsResolved],
    );

    await client.query("COMMIT");
    transactionOpen = false;

    return {
      runId,
      rawRows: batch.rawRows,
      uniqueEvents: batch.visits.length,
      writtenEvents,
      seenCards: cards.length,
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
