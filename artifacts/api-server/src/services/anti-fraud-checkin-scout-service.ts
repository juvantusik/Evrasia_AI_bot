import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import {
  BitrixAntiFraudCheckinGateway,
  type BitrixAntiFraudCheckinRecord,
} from "./bitrix-antifraud-checkin-gateway";
import { enrichRestisHistoryForHighRiskUserOnce } from "./anti-fraud-restis-history-enricher";

const SOURCE = "bitrix_checkin_scout";
const LOCK_NAME = "anti_fraud_checkin_scout_sync";
const DEFAULT_DAYS = 3;
const SCOUT_RETENTION_DAYS = 7;
const DEFAULT_MAX_DEEP_CHECKS = 10;
const MAX_DEEP_CHECKS = 50;
const CONFIRM_3PLUS_DAYS_60D = 3;
const CONFIRM_2PLUS_DAYS_7D = 3;

type WatchRow = {
  bitrix_user_id: number;
  status: "watching" | "deep_check" | "confirmed" | "expired";
  watch_started_day: string;
  watch_until_day: string;
  last_trigger_day: string;
  trigger_kind: "double_checkin" | "triple_checkin" | "repeated_double";
  last_deep_check_day: string | null;
  last_deep_check_count: number | null;
};

type DailyRow = {
  bitrix_user_id: number;
  visit_day: string;
  checkins: number;
};

type ConfirmationRow = {
  days_2plus_7d: number;
  days_3plus_60d: number;
};

export type CheckinScoutSyncResult = {
  runId: string;
  fetchedRecords: number;
  writtenRecords: number;
  observedAccounts: number;
  unresolvedCardCount: number;
  removedExpiredRows: number;
};

export type CheckinScoutEvaluationResult = {
  watchedAccounts: number;
  deepCheckCandidates: number;
  deepChecksAttempted: number;
  deepChecksSucceeded: number;
  deepChecksFailed: number;
  confirmedAccounts: number;
  expiredAccounts: number;
};

export type CheckinScoutSyncOptions = {
  gateway?: BitrixAntiFraudCheckinGateway;
  days?: number;
};

export type CheckinScoutEvaluationOptions = {
  maxDeepChecks?: number;
};

const safeErrorMessage = (error: unknown): string =>
  (error instanceof Error ? error.message : "Неизвестная ошибка Check-in Scout").slice(0, 2000);

const boundedInteger = (
  value: number,
  fallback: number,
  minimum: number,
  maximum: number,
): number => {
  if (!Number.isInteger(value)) return fallback;
  return Math.max(minimum, Math.min(maximum, value));
};

const addDays = (day: string, amount: number): string => {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) {
    throw new Error("Check-in Scout получил некорректную календарную дату");
  }
  const date = new Date(`${day}T00:00:00.000Z`);
  if (Number.isNaN(date.getTime())) {
    throw new Error("Check-in Scout получил некорректную календарную дату");
  }
  date.setUTCDate(date.getUTCDate() + amount);
  return date.toISOString().slice(0, 10);
};

const persistScoutRecord = async (
  record: BitrixAntiFraudCheckinRecord,
  client: Awaited<ReturnType<typeof pool.connect>>,
): Promise<void> => {
  const restisId = `scout:${record.sourceRestisId}`;
  const inserted = await client.query(
    `INSERT INTO anti_fraud_visits (
       restis_id, source_restis_id, card_id, bitrix_user_id, visited_at, restaurant,
       amount, bonus_added, bonus_spent, loyalty_verified, synced_at, resolved_at
     )
     VALUES ($1,$2,NULL,$3,$4,$5,NULL,NULL,NULL,false,now(),now())
     ON CONFLICT (restis_id) DO UPDATE SET
       synced_at = now()
     WHERE anti_fraud_visits.source_restis_id = EXCLUDED.source_restis_id
       AND anti_fraud_visits.bitrix_user_id = EXCLUDED.bitrix_user_id
       AND anti_fraud_visits.visited_at = EXCLUDED.visited_at
       AND anti_fraud_visits.restaurant = EXCLUDED.restaurant
     RETURNING restis_id`,
    [
      restisId,
      record.sourceRestisId,
      record.bitrixUserId,
      record.occurredAt,
      record.restaurant,
    ],
  );

  if (!inserted.rowCount) {
    throw new Error(
      `Check-in Scout source_restis_id=${record.sourceRestisId} повторно пришёл с другими core-данными`,
    );
  }
};

// Добавлено 19.09.2026 ИТ Директор Евразии
// Site-side endpoint отдаёт накопленные чекины за 3 московских календарных дня.
// Бот хранит лёгкие unverified scout rows; protected VIP_HISTORY позже заменяет совпавшие
// строки на verified history штатным enricher-ом.
export const syncCheckinScoutSnapshotOnce = async (
  options: CheckinScoutSyncOptions = {},
): Promise<CheckinScoutSyncResult> => {
  const gateway = options.gateway ?? new BitrixAntiFraudCheckinGateway();
  const days = boundedInteger(Number(options.days ?? DEFAULT_DAYS), DEFAULT_DAYS, 1, 3);
  const snapshot = await gateway.fetchRecentCheckins(days);
  const runId = randomUUID();
  const client = await pool.connect();
  let lockAcquired = false;
  let transactionOpen = false;
  let runCreated = false;

  try {
    const lock = await client.query<{ locked: boolean }>(
      "SELECT pg_try_advisory_lock(hashtext($1)) AS locked",
      [LOCK_NAME],
    );
    lockAcquired = lock.rows[0]?.locked === true;
    if (!lockAcquired) {
      throw new Error("Check-in Scout sync уже выполняется другим процессом");
    }

    await client.query(
      `INSERT INTO anti_fraud_sync_runs (run_id, source, status, started_at)
       VALUES ($1,$2,'running',now())`,
      [runId, SOURCE],
    );
    runCreated = true;

    await client.query(
      `INSERT INTO anti_fraud_sync_state (source, last_started_at, last_error, updated_at)
       VALUES ($1,now(),NULL,now())
       ON CONFLICT (source) DO UPDATE SET
         last_started_at=EXCLUDED.last_started_at,
         last_error=NULL,
         updated_at=now()`,
      [SOURCE],
    );

    await client.query("BEGIN");
    transactionOpen = true;

    for (const record of snapshot.records) {
      await persistScoutRecord(record, client);
    }

    const removed = await client.query(
      `DELETE FROM anti_fraud_visits
       WHERE loyalty_verified IS FALSE
         AND restis_id LIKE 'scout:%'
         AND visited_at < now() - ($1::text || ' days')::interval`,
      [SCOUT_RETENTION_DAYS],
    );

    await client.query("COMMIT");
    transactionOpen = false;

    const observedAccounts = new Set(snapshot.records.map((record) => record.bitrixUserId)).size;
    const removedExpiredRows = removed.rowCount ?? 0;

    await client.query(
      `UPDATE anti_fraud_sync_runs
       SET status='success', finished_at=now(), records_fetched=$2,
           records_written=$3, records_resolved=$4, error=NULL
       WHERE run_id=$1`,
      [runId, snapshot.records.length, snapshot.records.length, observedAccounts],
    );

    await client.query(
      `UPDATE anti_fraud_sync_state
       SET last_succeeded_at=now(), last_error=NULL,
           records_fetched=$2, records_written=$3, records_resolved=$4, updated_at=now()
       WHERE source=$1`,
      [SOURCE, snapshot.records.length, snapshot.records.length, observedAccounts],
    );

    return {
      runId,
      fetchedRecords: snapshot.records.length,
      writtenRecords: snapshot.records.length,
      observedAccounts,
      unresolvedCardCount: snapshot.unresolvedCardCount,
      removedExpiredRows,
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
           SET status='failed', finished_at=now(), error=$2
           WHERE run_id=$1`,
          [runId, message],
        );
        await client.query(
          `UPDATE anti_fraud_sync_state
           SET last_error=$2, updated_at=now()
           WHERE source=$1`,
          [SOURCE, message],
        );
      } catch {
        // Не скрываем исходную ошибку.
      }
    }
    throw error;
  } finally {
    if (lockAcquired) {
      try {
        await client.query("SELECT pg_advisory_unlock(hashtext($1))", [LOCK_NAME]);
      } catch {
        // Соединение освобождается ниже.
      }
    }
    client.release();
  }
};

const currentMoscowDay = async (): Promise<string> => {
  const result = await pool.query<{ today: string }>(
    `SELECT to_char((now() AT TIME ZONE 'Europe/Moscow')::date, 'YYYY-MM-DD') AS today`,
  );
  const today = String(result.rows[0]?.today ?? "");
  if (!/^\d{4}-\d{2}-\d{2}$/.test(today)) {
    throw new Error("Не удалось определить московскую календарную дату");
  }
  return today;
};

const loadRecentDailyCounts = async (): Promise<Map<number, Map<string, number>>> => {
  const result = await pool.query<DailyRow>(
    `WITH scout_checkins AS (
       SELECT DISTINCT bitrix_user_id, source_restis_id, visited_at, restaurant
       FROM anti_fraud_visits
       WHERE bitrix_user_id IS NOT NULL
         AND restis_id LIKE 'scout:%'
         AND visited_at >= (
           ((now() AT TIME ZONE 'Europe/Moscow')::date - 2)::timestamp
           AT TIME ZONE 'Europe/Moscow'
         )
     )
     SELECT
       bitrix_user_id,
       to_char((visited_at AT TIME ZONE 'Europe/Moscow')::date, 'YYYY-MM-DD') AS visit_day,
       count(*)::int AS checkins
     FROM scout_checkins
     GROUP BY bitrix_user_id, (visited_at AT TIME ZONE 'Europe/Moscow')::date
     HAVING count(*) >= 2
     ORDER BY bitrix_user_id, visit_day`,
  );

  const byUser = new Map<number, Map<string, number>>();
  for (const row of result.rows) {
    const userId = Number(row.bitrix_user_id);
    const day = String(row.visit_day);
    const count = Number(row.checkins);
    if (!byUser.has(userId)) byUser.set(userId, new Map());
    byUser.get(userId)!.set(day, count);
  }
  return byUser;
};

const loadWatchStates = async (userIds: number[]): Promise<Map<number, WatchRow>> => {
  if (!userIds.length) return new Map();
  const result = await pool.query<WatchRow>(
    `SELECT
       bitrix_user_id,
       status,
       watch_started_day::text,
       watch_until_day::text,
       last_trigger_day::text,
       trigger_kind,
       last_deep_check_day::text,
       last_deep_check_count
     FROM anti_fraud_checkin_watch_state
     WHERE bitrix_user_id = ANY($1::int[])`,
    [userIds],
  );
  return new Map(result.rows.map((row) => [Number(row.bitrix_user_id), row]));
};

const persistWatchState = async (
  userId: number,
  startDay: string,
  untilDay: string,
  triggerDay: string,
  triggerKind: WatchRow["trigger_kind"],
): Promise<void> => {
  await pool.query(
    `INSERT INTO anti_fraud_checkin_watch_state (
       bitrix_user_id, status, watch_started_day, watch_until_day, last_trigger_day,
       trigger_kind, last_seen_at, updated_at
     )
     VALUES ($1,'watching',$2::date,$3::date,$4::date,$5,now(),now())
     ON CONFLICT (bitrix_user_id) DO UPDATE SET
       status = CASE
         WHEN anti_fraud_checkin_watch_state.status='confirmed' THEN 'confirmed'
         ELSE 'watching'
       END,
       watch_started_day = CASE
         WHEN anti_fraud_checkin_watch_state.status IN ('expired')
           OR anti_fraud_checkin_watch_state.watch_until_day < $2::date
         THEN $2::date
         ELSE anti_fraud_checkin_watch_state.watch_started_day
       END,
       watch_until_day = CASE
         WHEN anti_fraud_checkin_watch_state.status IN ('expired')
           OR anti_fraud_checkin_watch_state.watch_until_day < $2::date
         THEN $3::date
         ELSE GREATEST(anti_fraud_checkin_watch_state.watch_until_day, $3::date)
       END,
       last_trigger_day=$4::date,
       trigger_kind=$5,
       last_seen_at=now(),
       updated_at=now()`,
    [userId, startDay, untilDay, triggerDay, triggerKind],
  );
};

const loadConfirmation = async (userId: number): Promise<ConfirmationRow> => {
  const result = await pool.query<ConfirmationRow>(
    `WITH checkins AS (
       SELECT DISTINCT source_restis_id, visited_at, restaurant
       FROM anti_fraud_visits
       WHERE bitrix_user_id=$1
         AND loyalty_verified IS TRUE
         AND visited_at >= now() - interval '60 days'
     ),
     daily AS (
       SELECT
         (visited_at AT TIME ZONE 'Europe/Moscow')::date AS visit_day,
         count(*)::int AS checkins
       FROM checkins
       GROUP BY (visited_at AT TIME ZONE 'Europe/Moscow')::date
     )
     SELECT
       count(*) FILTER (
         WHERE visit_day >= (now() AT TIME ZONE 'Europe/Moscow')::date - 6
           AND checkins >= 2
       )::int AS days_2plus_7d,
       count(*) FILTER (WHERE checkins >= 3)::int AS days_3plus_60d
     FROM daily`,
    [userId],
  );
  return {
    days_2plus_7d: Number(result.rows[0]?.days_2plus_7d ?? 0),
    days_3plus_60d: Number(result.rows[0]?.days_3plus_60d ?? 0),
  };
};

const deepCheckNeeded = (
  state: WatchRow | undefined,
  startDay: string,
  daily: Map<string, number>,
): { day: string; count: number; kind: WatchRow["trigger_kind"] } | null => {
  const days = [...daily.entries()].sort(([a], [b]) => a.localeCompare(b));
  let candidate: { day: string; count: number; kind: WatchRow["trigger_kind"] } | null = null;

  for (const [day, count] of days) {
    if (day < startDay) continue;
    let kind: WatchRow["trigger_kind"] | null = null;
    if (count >= 3) kind = "triple_checkin";
    else if (day > startDay && count >= 2) kind = "repeated_double";
    if (!kind) continue;

    if (
      state?.last_deep_check_day === day &&
      Number(state.last_deep_check_count ?? 0) >= count
    ) {
      continue;
    }
    candidate = { day, count, kind };
  }

  return candidate;
};

// Бизнес-правило:
// 1 чекин/день — норма;
// 2 чекина — WATCH на текущий + 2 следующих московских дня;
// повторный день 2+ — deep check;
// 3-й чекин в любой день — deep check немедленно;
// 3 дня с 2+ за 7 дней ИЛИ 3 дня с 3+ за 60 дней — подтверждённая регулярность.
export const evaluateCheckinScoutOnce = async (
  options: CheckinScoutEvaluationOptions = {},
): Promise<CheckinScoutEvaluationResult> => {
  const today = await currentMoscowDay();
  const dailyByUser = await loadRecentDailyCounts();
  const userIds = [...dailyByUser.keys()];
  const states = await loadWatchStates(userIds);
  const maxDeepChecks = boundedInteger(
    Number(options.maxDeepChecks ?? process.env.ANTI_FRAUD_SCOUT_MAX_HISTORY_USERS_PER_RUN ?? DEFAULT_MAX_DEEP_CHECKS),
    DEFAULT_MAX_DEEP_CHECKS,
    1,
    MAX_DEEP_CHECKS,
  );

  let watchedAccounts = 0;
  const candidates: Array<{ userId: number; day: string; count: number; kind: WatchRow["trigger_kind"] }> = [];

  for (const [userId, daily] of dailyByUser) {
    const days = [...daily.entries()].sort(([a], [b]) => a.localeCompare(b));
    if (!days.length) continue;

    const existing = states.get(userId);
    if (existing?.status === "confirmed") continue;

    const activeExisting =
      existing &&
      existing.status !== "expired" &&
      existing.watch_until_day >= today;

    const startDay = activeExisting ? existing.watch_started_day : days[0]![0];
    const firstCount = daily.get(startDay) ?? days[0]![1];
    const untilDay = activeExisting ? existing.watch_until_day : addDays(startDay, 2);
    const initialKind: WatchRow["trigger_kind"] =
      firstCount >= 3 ? "triple_checkin" : "double_checkin";

    await persistWatchState(
      userId,
      startDay,
      untilDay,
      days[days.length - 1]![0],
      initialKind,
    );
    watchedAccounts += 1;

    const refreshedState = (await loadWatchStates([userId])).get(userId);
    const deep = deepCheckNeeded(refreshedState, startDay, daily);
    if (deep) candidates.push({ userId, ...deep });
  }

  candidates.sort((a, b) => b.count - a.count || a.userId - b.userId);

  let deepChecksAttempted = 0;
  let deepChecksSucceeded = 0;
  let deepChecksFailed = 0;
  let confirmedAccounts = 0;

  for (const candidate of candidates.slice(0, maxDeepChecks)) {
    deepChecksAttempted += 1;

    await pool.query(
      `UPDATE anti_fraud_checkin_watch_state
       SET status='deep_check',
           trigger_kind=$2,
           last_trigger_day=$3::date,
           last_deep_check_day=$3::date,
           last_deep_check_count=$4,
           deep_check_requested_at=now(),
           updated_at=now()
       WHERE bitrix_user_id=$1
         AND status <> 'confirmed'`,
      [candidate.userId, candidate.kind, candidate.day, candidate.count],
    );

    try {
      await enrichRestisHistoryForHighRiskUserOnce({
        bitrixUserId: candidate.userId,
        riskGateConfirmed: true,
        lookbackDays: 60,
        refreshHours: 0,
      });

      const confirmation = await loadConfirmation(candidate.userId);
      const confirmed =
        confirmation.days_3plus_60d >= CONFIRM_3PLUS_DAYS_60D ||
        confirmation.days_2plus_7d >= CONFIRM_2PLUS_DAYS_7D;

      await pool.query(
        `UPDATE anti_fraud_checkin_watch_state
         SET status=$2,
             deep_check_completed_at=now(),
             confirmed_at=CASE WHEN $2='confirmed' THEN COALESCE(confirmed_at, now()) ELSE confirmed_at END,
             confirmed_days_2plus_7d=$3,
             confirmed_days_3plus_60d=$4,
             updated_at=now()
         WHERE bitrix_user_id=$1`,
        [
          candidate.userId,
          confirmed ? "confirmed" : "watching",
          confirmation.days_2plus_7d,
          confirmation.days_3plus_60d,
        ],
      );

      deepChecksSucceeded += 1;
      if (confirmed) confirmedAccounts += 1;
    } catch {
      deepChecksFailed += 1;
      await pool.query(
        `UPDATE anti_fraud_checkin_watch_state
         SET status='watching', updated_at=now()
         WHERE bitrix_user_id=$1
           AND status <> 'confirmed'`,
        [candidate.userId],
      );
    }
  }

  const expired = await pool.query(
    `UPDATE anti_fraud_checkin_watch_state
     SET status='expired', updated_at=now()
     WHERE status IN ('watching','deep_check')
       AND watch_until_day < $1::date
     RETURNING bitrix_user_id`,
    [today],
  );

  return {
    watchedAccounts,
    deepCheckCandidates: candidates.length,
    deepChecksAttempted,
    deepChecksSucceeded,
    deepChecksFailed,
    confirmedAccounts,
    expiredAccounts: expired.rowCount ?? 0,
  };
};
