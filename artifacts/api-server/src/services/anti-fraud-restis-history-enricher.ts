import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import {
  RestisAntiFraudGateway,
  type RestisVisitEvent,
} from "./restis-antifraud-gateway";

const SOURCE = "restis_vip_history";
const LOCK_PREFIX = "anti_fraud_restis_vip_history";
const DAY_MS = 24 * 60 * 60 * 1000;
const HOUR_MS = 60 * 60 * 1000;
const DEFAULT_LOOKBACK_DAYS = 60;
const MAX_LOOKBACK_DAYS = 60;
const DEFAULT_REFRESH_HOURS = 24;
const MAX_REFRESH_HOURS = 168;
const DEFAULT_PAGE_SIZE = 10_000;

// Добавлено 03.09.2026 ИТ Директор Евразии
// Историческое обогащение разрешено только после явного подтверждения risk gate.
export type RestisHistoryEnrichmentOptions = {
  bitrixUserId: number;
  riskGateConfirmed: true;
  lookbackDays?: number;
  refreshHours?: number;
  pageSize?: number;
  gateway?: RestisAntiFraudGateway;
  now?: Date;
};

// Добавлено 03.09.2026 ИТ Директор Евразии
export type RestisHistoryEnrichmentResult = {
  runId: string;
  bitrixUserId: number;
  lookbackDays: number;
  windowFrom: string;
  windowUntil: string;
  activeCards: number;
  fetchedCards: number;
  skippedFreshCards: number;
  rawRows: number;
  relevantEvents: number;
  writtenEvents: number;
  resolvedEvents: number;
};

type ActiveCardRow = {
  id: number;
  card_number: string;
  history_loaded_from: Date | null;
  history_loaded_until: Date | null;
  history_loaded_at: Date | null;
};

type ExistingVisitRow = {
  restis_id: string;
  card_id: number;
  bitrix_user_id: number | null;
  visited_at: Date;
  restaurant: string;
};

type FetchedCardHistory = {
  cardId: number;
  cardNumber: string;
  rawRows: number;
  visits: RestisVisitEvent[];
};

const boundedInteger = (
  value: number,
  fallback: number,
  minimum: number,
  maximum: number,
): number => {
  if (!Number.isInteger(value)) return fallback;
  return Math.min(maximum, Math.max(minimum, value));
};

const safeErrorMessage = (error: unknown): string => {
  const message =
    error instanceof Error ? error.message : "Неизвестная ошибка RestIS VIP_HISTORY";
  return message.slice(0, 2000);
};

const coverageIsFresh = (
  card: ActiveCardRow,
  windowFrom: Date,
  now: Date,
  refreshHours: number,
): boolean => {
  if (!card.history_loaded_from || !card.history_loaded_until || !card.history_loaded_at) {
    return false;
  }

  const freshBoundary = new Date(now.getTime() - refreshHours * HOUR_MS);
  return (
    card.history_loaded_from.getTime() <= windowFrom.getTime() &&
    card.history_loaded_until.getTime() >= freshBoundary.getTime() &&
    card.history_loaded_at.getTime() >= freshBoundary.getTime()
  );
};

// Добавлено 03.09.2026 ИТ Директор Евразии
// Один RestIS ID обязан оставаться привязанным к той же активной карте, дате и ресторану.
const assertExistingVisitsAreStable = (
  incoming: Map<string, { visit: RestisVisitEvent; cardId: number }>,
  existingRows: ExistingVisitRow[],
  bitrixUserId: number,
): Set<string> => {
  const existingIds = new Set<string>();

  for (const row of existingRows) {
    const item = incoming.get(row.restis_id);
    if (!item) continue;

    existingIds.add(row.restis_id);
    const same =
      row.card_id === item.cardId &&
      row.visited_at.getTime() === item.visit.visitedAt.getTime() &&
      row.restaurant === item.visit.restaurant &&
      (row.bitrix_user_id === null || row.bitrix_user_id === bitrixUserId);

    if (!same) {
      throw new Error(
        `RestIS изменил ранее сохранённое историческое событие ID=${row.restis_id}; обогащение остановлено`,
      );
    }
  }

  return existingIds;
};

// Добавлено 03.09.2026 ИТ Директор Евразии
// Функция принимает ровно один подозрительный Bitrix USER_ID и никогда не перебирает всю базу карт.
// В VIP_HISTORY участвуют только карты, которые Bitrix resolver пометил is_active=true.
export const enrichRestisHistoryForHighRiskUserOnce = async (
  options: RestisHistoryEnrichmentOptions,
): Promise<RestisHistoryEnrichmentResult> => {
  if (options.riskGateConfirmed !== true) {
    throw new Error("VIP_HISTORY запрещён без подтверждённого Anti-Fraud risk gate");
  }

  const bitrixUserId = Number(options.bitrixUserId);
  if (!Number.isInteger(bitrixUserId) || bitrixUserId <= 0) {
    throw new Error("bitrixUserId для VIP_HISTORY должен быть положительным integer");
  }

  const lookbackDays = boundedInteger(
    Number(options.lookbackDays ?? process.env.ANTI_FRAUD_HISTORY_LOOKBACK_DAYS ?? DEFAULT_LOOKBACK_DAYS),
    DEFAULT_LOOKBACK_DAYS,
    1,
    MAX_LOOKBACK_DAYS,
  );
  const refreshHours = boundedInteger(
    Number(options.refreshHours ?? process.env.ANTI_FRAUD_HISTORY_REFRESH_HOURS ?? DEFAULT_REFRESH_HOURS),
    DEFAULT_REFRESH_HOURS,
    1,
    MAX_REFRESH_HOURS,
  );
  const pageSize = boundedInteger(
    Number(options.pageSize ?? process.env.RESTIS_VIP_HISTORY_PAGE_SIZE ?? DEFAULT_PAGE_SIZE),
    DEFAULT_PAGE_SIZE,
    1,
    DEFAULT_PAGE_SIZE,
  );
  const now = options.now ?? new Date();
  if (Number.isNaN(now.getTime())) {
    throw new Error("Некорректное время запуска VIP_HISTORY");
  }

  const windowUntil = now;
  const windowFrom = new Date(now.getTime() - lookbackDays * DAY_MS);
  const gateway = options.gateway ?? new RestisAntiFraudGateway();
  const runId = randomUUID();
  const client = await pool.connect();
  const lockName = `${LOCK_PREFIX}:${bitrixUserId}`;
  let lockAcquired = false;
  let transactionOpen = false;
  let runCreated = false;

  try {
    const lockResult = await client.query<{ locked: boolean }>(
      "SELECT pg_try_advisory_lock(hashtext($1)) AS locked",
      [lockName],
    );
    lockAcquired = lockResult.rows[0]?.locked === true;
    if (!lockAcquired) {
      throw new Error("VIP_HISTORY для этого аккаунта уже выполняется другим процессом");
    }

    await client.query(
      `INSERT INTO anti_fraud_sync_runs (run_id, source, status, started_at)
       VALUES ($1, $2, 'running', now())`,
      [runId, SOURCE],
    );
    runCreated = true;

    const cardsResult = await client.query<ActiveCardRow>(
      `SELECT id, card_number, history_loaded_from, history_loaded_until, history_loaded_at
       FROM anti_fraud_cards
       WHERE bitrix_user_id = $1
         AND is_active IS TRUE
       ORDER BY id`,
      [bitrixUserId],
    );

    const activeCards = cardsResult.rows;
    const cardsToFetch = activeCards.filter(
      (card) => !coverageIsFresh(card, windowFrom, now, refreshHours),
    );
    const skippedFreshCards = activeCards.length - cardsToFetch.length;
    const fetched: FetchedCardHistory[] = [];
    let rawRows = 0;

    for (const card of cardsToFetch) {
      const batch = await gateway.fetchVipHistory(card.card_number, pageSize);
      rawRows += batch.rawRows;

      if (batch.rawRows >= pageSize) {
        throw new Error(
          "RestIS VIP_HISTORY достиг предельного pagesize для активной карты; требуется пагинация",
        );
      }

      fetched.push({
        cardId: card.id,
        cardNumber: card.card_number,
        rawRows: batch.rawRows,
        visits: batch.visits.filter(
          (visit) =>
            visit.visitedAt.getTime() >= windowFrom.getTime() &&
            visit.visitedAt.getTime() <= windowUntil.getTime(),
        ),
      });
    }

    await client.query("BEGIN");
    transactionOpen = true;

    const fetchedCardIds = fetched.map((item) => item.cardId);
    const stillActiveIds = new Set<number>();

    if (fetchedCardIds.length) {
      const activeNow = await client.query<{ id: number }>(
        `SELECT id
         FROM anti_fraud_cards
         WHERE id = ANY($1::int[])
           AND bitrix_user_id = $2
           AND is_active IS TRUE`,
        [fetchedCardIds, bitrixUserId],
      );
      for (const row of activeNow.rows) stillActiveIds.add(row.id);
    }

    const incoming = new Map<string, { visit: RestisVisitEvent; cardId: number }>();
    const acceptedHistories = fetched.filter((item) => stillActiveIds.has(item.cardId));

    for (const history of acceptedHistories) {
      for (const visit of history.visits) {
        const previous = incoming.get(visit.restisId);
        if (previous) {
          const same =
            previous.cardId === history.cardId &&
            previous.visit.visitedAt.getTime() === visit.visitedAt.getTime() &&
            previous.visit.restaurant === visit.restaurant;
          if (!same) {
            throw new Error(
              `RestIS VIP_HISTORY вернул конфликт между активными картами для ID=${visit.restisId}`,
            );
          }
          continue;
        }
        incoming.set(visit.restisId, { visit, cardId: history.cardId });
      }
    }

    let existingIds = new Set<string>();
    const incomingIds = [...incoming.keys()];
    if (incomingIds.length) {
      const existing = await client.query<ExistingVisitRow>(
        `SELECT restis_id, card_id, bitrix_user_id, visited_at, restaurant
         FROM anti_fraud_visits
         WHERE restis_id = ANY($1::text[])`,
        [incomingIds],
      );
      existingIds = assertExistingVisitsAreStable(incoming, existing.rows, bitrixUserId);
    }

    let writtenEvents = 0;
    for (const [restisId, item] of incoming) {
      if (existingIds.has(restisId)) continue;

      const inserted = await client.query(
        `INSERT INTO anti_fraud_visits
           (restis_id, card_id, bitrix_user_id, visited_at, restaurant, synced_at, resolved_at)
         VALUES ($1, $2, $3, $4, $5, now(), now())
         ON CONFLICT (restis_id) DO NOTHING
         RETURNING restis_id`,
        [
          restisId,
          item.cardId,
          bitrixUserId,
          item.visit.visitedAt,
          item.visit.restaurant,
        ],
      );
      if (inserted.rowCount) writtenEvents += 1;
    }

    for (const history of acceptedHistories) {
      await client.query(
        `UPDATE anti_fraud_cards
         SET history_loaded_from = CASE
               WHEN history_loaded_from IS NULL OR history_loaded_from > $2 THEN $2
               ELSE history_loaded_from
             END,
             history_loaded_until = CASE
               WHEN history_loaded_until IS NULL OR history_loaded_until < $3 THEN $3
               ELSE history_loaded_until
             END,
             history_loaded_at = now()
         WHERE id = $1
           AND bitrix_user_id = $4
           AND is_active IS TRUE`,
        [history.cardId, windowFrom, windowUntil, bitrixUserId],
      );
    }

    let resolvedEvents = 0;
    if (incomingIds.length) {
      const resolvedResult = await client.query<{ count: string }>(
        `SELECT count(*)::text AS count
         FROM anti_fraud_visits
         WHERE restis_id = ANY($1::text[])
           AND bitrix_user_id = $2`,
        [incomingIds, bitrixUserId],
      );
      resolvedEvents = Number(resolvedResult.rows[0]?.count ?? 0);
    }

    await client.query(
      `UPDATE anti_fraud_sync_runs
       SET status = 'success', finished_at = now(), records_fetched = $2,
           records_written = $3, records_resolved = $4, error = NULL
       WHERE run_id = $1`,
      [runId, rawRows, writtenEvents, resolvedEvents],
    );

    await client.query("COMMIT");
    transactionOpen = false;

    return {
      runId,
      bitrixUserId,
      lookbackDays,
      windowFrom: windowFrom.toISOString(),
      windowUntil: windowUntil.toISOString(),
      activeCards: activeCards.length,
      fetchedCards: cardsToFetch.length,
      skippedFreshCards,
      rawRows,
      relevantEvents: incoming.size,
      writtenEvents,
      resolvedEvents,
    };
  } catch (error) {
    if (transactionOpen) {
      await client.query("ROLLBACK");
      transactionOpen = false;
    }

    if (runCreated) {
      const message = safeErrorMessage(error);
      try {
        await client.query(
          `UPDATE anti_fraud_sync_runs
           SET status = 'failed', finished_at = now(), error = $2
           WHERE run_id = $1`,
          [runId, message],
        );
      } catch {
        // Ошибка фиксации статуса не должна скрывать исходную ошибку VIP_HISTORY.
      }
    }
    throw error;
  } finally {
    if (lockAcquired) {
      try {
        await client.query("SELECT pg_advisory_unlock(hashtext($1))", [lockName]);
      } catch {
        // Соединение всё равно будет освобождено ниже.
      }
    }
    client.release();
  }
};
