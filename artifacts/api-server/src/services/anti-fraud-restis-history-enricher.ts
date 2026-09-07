import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import {
  BitrixAntiFraudLoyaltyGateway,
  type BitrixAntiFraudLoyaltyHistoryEvent,
} from "./bitrix-antifraud-loyalty-gateway";

const SOURCE = "bitrix_loyalty_history";
const LOCK_PREFIX = "anti_fraud_bitrix_loyalty_history";
const DAY_MS = 24 * 60 * 60 * 1000;
const HOUR_MS = 60 * 60 * 1000;
const DEFAULT_LOOKBACK_DAYS = 60;
const MAX_LOOKBACK_DAYS = 60;
const DEFAULT_REFRESH_HOURS = 24;
const MAX_REFRESH_HOURS = 168;

export type RestisHistoryEnrichmentOptions = {
  bitrixUserId: number;
  riskGateConfirmed: true;
  lookbackDays?: number;
  refreshHours?: number;
  gateway?: BitrixAntiFraudLoyaltyGateway;
  now?: Date;
};

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

type AccountCoverageRow = {
  loyalty_history_loaded_from: Date | null;
  loyalty_history_loaded_until: Date | null;
  loyalty_history_loaded_at: Date | null;
};

type ExistingVisitRow = {
  restis_id: string;
  source_restis_id: string;
  bitrix_user_id: number | null;
  visited_at: Date;
  restaurant: string;
  amount: string | null;
  bonus_added: string | null;
  bonus_spent: string | null;
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
    error instanceof Error ? error.message : "Неизвестная ошибка protected loyalty history";
  return message.slice(0, 2000);
};

const coverageIsFresh = (
  account: AccountCoverageRow,
  windowFrom: Date,
  now: Date,
  refreshHours: number,
): boolean => {
  if (
    !account.loyalty_history_loaded_from ||
    !account.loyalty_history_loaded_until ||
    !account.loyalty_history_loaded_at
  ) {
    return false;
  }

  const freshBoundary = new Date(now.getTime() - refreshHours * HOUR_MS);
  return (
    account.loyalty_history_loaded_from.getTime() <= windowFrom.getTime() &&
    account.loyalty_history_loaded_until.getTime() >= freshBoundary.getTime() &&
    account.loyalty_history_loaded_at.getTime() >= freshBoundary.getTime()
  );
};

const assertExistingVisitsAreStable = (
  incoming: Map<string, BitrixAntiFraudLoyaltyHistoryEvent>,
  existingRows: ExistingVisitRow[],
  bitrixUserId: number,
): Set<string> => {
  const existingIds = new Set<string>();

  for (const row of existingRows) {
    const event = incoming.get(row.restis_id);
    if (!event) continue;

    existingIds.add(row.restis_id);
    const same =
      row.source_restis_id === event.restisId &&
      row.visited_at.getTime() === event.occurredAt.getTime() &&
      row.restaurant === event.restaurant &&
      row.amount === event.amount &&
      row.bonus_added === event.bonusAdded &&
      row.bonus_spent === event.bonusSpent &&
      (row.bitrix_user_id === null || row.bitrix_user_id === bitrixUserId);

    if (!same) {
      throw new Error(
        `Protected loyalty history изменил ранее сохранённое событие ID=${row.restis_id}; обогащение остановлено`,
      );
    }
  }

  return existingIds;
};

// Обновлено 05.09.2026 ИТ Директор Евразии
// История идёт USER_ID -> active RESTIS_STATE=113 -> protected loyalty endpoint.
// Raw source restis_id может повторяться; уникальность определяется полным денежным событием.
export const enrichRestisHistoryForHighRiskUserOnce = async (
  options: RestisHistoryEnrichmentOptions,
): Promise<RestisHistoryEnrichmentResult> => {
  if (options.riskGateConfirmed !== true) {
    throw new Error("Loyalty history запрещён без подтверждённого Anti-Fraud risk gate");
  }

  const bitrixUserId = Number(options.bitrixUserId);
  if (!Number.isInteger(bitrixUserId) || bitrixUserId <= 0) {
    throw new Error("bitrixUserId для loyalty history должен быть положительным integer");
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
  const now = options.now ?? new Date();
  if (Number.isNaN(now.getTime())) {
    throw new Error("Некорректное время запуска loyalty history");
  }

  const windowUntil = now;
  const windowFrom = new Date(now.getTime() - lookbackDays * DAY_MS);
  const gateway = options.gateway ?? new BitrixAntiFraudLoyaltyGateway();
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
      throw new Error("Loyalty history для этого аккаунта уже выполняется другим процессом");
    }

    await client.query(
      `INSERT INTO anti_fraud_sync_runs (run_id, source, status, started_at)
       VALUES ($1, $2, 'running', now())`,
      [runId, SOURCE],
    );
    runCreated = true;

    const accountResult = await client.query<AccountCoverageRow>(
      `SELECT loyalty_history_loaded_from, loyalty_history_loaded_until, loyalty_history_loaded_at
       FROM anti_fraud_accounts
       WHERE bitrix_user_id = $1`,
      [bitrixUserId],
    );
    const account = accountResult.rows[0];
    if (!account) {
      throw new Error("Loyalty history требует предварительно синхронизированный Anti-Fraud account");
    }

    if (coverageIsFresh(account, windowFrom, now, refreshHours)) {
      await client.query(
        `UPDATE anti_fraud_sync_runs
         SET status='success', finished_at=now(), records_fetched=0,
             records_written=0, records_resolved=0, error=NULL
         WHERE run_id=$1`,
        [runId],
      );
      return {
        runId,
        bitrixUserId,
        lookbackDays,
        windowFrom: windowFrom.toISOString(),
        windowUntil: windowUntil.toISOString(),
        activeCards: 0,
        fetchedCards: 0,
        skippedFreshCards: 1,
        rawRows: 0,
        relevantEvents: 0,
        writtenEvents: 0,
        resolvedEvents: 0,
      };
    }

    const response = await gateway.resolveLoyalty([bitrixUserId], {
      includeHistory: true,
      historyDays: lookbackDays,
    });
    const record = response.records.find((item) => item.bitrixUserId === bitrixUserId);
    if (!record) {
      throw new Error("Protected loyalty endpoint не разрешил запрошенный USER_ID");
    }

    await client.query(
      `UPDATE anti_fraud_accounts
       SET bonus_balance = $2::numeric(14,2), loyalty_synced_at = now()
       WHERE bitrix_user_id = $1`,
      [bitrixUserId, record.bonusBalance],
    );

    if (!record.activeCardFound) {
      await client.query(
        `UPDATE anti_fraud_sync_runs
         SET status='success', finished_at=now(), records_fetched=0,
             records_written=0, records_resolved=0, error=NULL
         WHERE run_id=$1`,
        [runId],
      );
      return {
        runId,
        bitrixUserId,
        lookbackDays,
        windowFrom: windowFrom.toISOString(),
        windowUntil: windowUntil.toISOString(),
        activeCards: record.activeCardCount,
        fetchedCards: 0,
        skippedFreshCards: 0,
        rawRows: 0,
        relevantEvents: 0,
        writtenEvents: 0,
        resolvedEvents: 0,
      };
    }

    const history = record.history ?? [];
    const relevant = history.filter(
      (event) =>
        event.occurredAt.getTime() >= windowFrom.getTime() &&
        event.occurredAt.getTime() <= windowUntil.getTime(),
    );
    const incoming = new Map<string, BitrixAntiFraudLoyaltyHistoryEvent>();
    for (const event of relevant) incoming.set(event.eventId, event);

    await client.query("BEGIN");
    transactionOpen = true;

    const incomingIds = [...incoming.keys()];
    let existingIds = new Set<string>();
    if (incomingIds.length) {
      const existing = await client.query<ExistingVisitRow>(
        `SELECT restis_id, source_restis_id, bitrix_user_id, visited_at, restaurant,
                amount::text, bonus_added::text, bonus_spent::text
         FROM anti_fraud_visits
         WHERE restis_id = ANY($1::text[])`,
        [incomingIds],
      );
      existingIds = assertExistingVisitsAreStable(incoming, existing.rows, bitrixUserId);
    }

    let writtenEvents = 0;
    for (const event of incoming.values()) {
      // Protected history — более полный источник, чем legacy VIP_TODAY. Удаляем только
      // совпавшую непроверенную legacy-строку этого же USER_ID, чтобы не считать визит дважды.
      await client.query(
        `DELETE FROM anti_fraud_visits
         WHERE loyalty_verified IS FALSE
           AND bitrix_user_id = $1
           AND source_restis_id = $2
           AND visited_at = $3
           AND restaurant = $4`,
        [bitrixUserId, event.restisId, event.occurredAt, event.restaurant],
      );

      const upserted = await client.query(
        `INSERT INTO anti_fraud_visits (
           restis_id, source_restis_id, card_id, bitrix_user_id, visited_at, restaurant,
           amount, bonus_added, bonus_spent, loyalty_verified, synced_at, resolved_at
         )
         VALUES ($1, $2, NULL, $3, $4, $5, $6::numeric(14,2), $7::numeric(14,2),
                 $8::numeric(14,2), true, now(), now())
         ON CONFLICT (restis_id) DO UPDATE SET
           bitrix_user_id = CASE
             WHEN anti_fraud_visits.bitrix_user_id IS NULL THEN EXCLUDED.bitrix_user_id
             ELSE anti_fraud_visits.bitrix_user_id
           END,
           source_restis_id = EXCLUDED.source_restis_id,
           amount = EXCLUDED.amount,
           bonus_added = EXCLUDED.bonus_added,
           bonus_spent = EXCLUDED.bonus_spent,
           loyalty_verified = true,
           synced_at = now(),
           resolved_at = COALESCE(anti_fraud_visits.resolved_at, now())
         RETURNING restis_id`,
        [
          event.eventId,
          event.restisId,
          bitrixUserId,
          event.occurredAt,
          event.restaurant,
          event.amount,
          event.bonusAdded,
          event.bonusSpent,
        ],
      );
      if (upserted.rowCount && !existingIds.has(event.eventId)) writtenEvents += 1;
    }

    await client.query(
      `UPDATE anti_fraud_accounts
       SET loyalty_history_loaded_from = CASE
             WHEN loyalty_history_loaded_from IS NULL OR loyalty_history_loaded_from > $2 THEN $2
             ELSE loyalty_history_loaded_from
           END,
           loyalty_history_loaded_until = CASE
             WHEN loyalty_history_loaded_until IS NULL OR loyalty_history_loaded_until < $3 THEN $3
             ELSE loyalty_history_loaded_until
           END,
           loyalty_history_loaded_at = now(),
           loyalty_synced_at = now(),
           bonus_balance = $4::numeric(14,2)
       WHERE bitrix_user_id = $1`,
      [bitrixUserId, windowFrom, windowUntil, record.bonusBalance],
    );

    let resolvedEvents = 0;
    if (incomingIds.length) {
      const resolvedResult = await client.query<{ count: string }>(
        `SELECT count(*)::text AS count
         FROM anti_fraud_visits
         WHERE restis_id = ANY($1::text[])
           AND bitrix_user_id = $2
           AND loyalty_verified IS TRUE`,
        [incomingIds, bitrixUserId],
      );
      resolvedEvents = Number(resolvedResult.rows[0]?.count ?? 0);
    }

    await client.query(
      `UPDATE anti_fraud_sync_runs
       SET status='success', finished_at=now(), records_fetched=$2,
           records_written=$3, records_resolved=$4, error=NULL
       WHERE run_id=$1`,
      [runId, history.length, writtenEvents, resolvedEvents],
    );

    await client.query("COMMIT");
    transactionOpen = false;

    return {
      runId,
      bitrixUserId,
      lookbackDays,
      windowFrom: windowFrom.toISOString(),
      windowUntil: windowUntil.toISOString(),
      activeCards: record.activeCardCount,
      fetchedCards: 1,
      skippedFreshCards: 0,
      rawRows: history.length,
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
      try {
        await client.query(
          `UPDATE anti_fraud_sync_runs
           SET status='failed', finished_at=now(), error=$2
           WHERE run_id=$1`,
          [runId, safeErrorMessage(error)],
        );
      } catch {
        // Ошибка фиксации статуса не должна скрывать исходную ошибку history enrichment.
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
