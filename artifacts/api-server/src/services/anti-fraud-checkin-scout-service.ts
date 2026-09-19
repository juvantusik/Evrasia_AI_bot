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

type ConfirmationRow = {
  days_2plus_7d: number;
  days_3plus_60d: number;
};

type PendingDeepCheckRow = {
  bitrix_user_id: number;
  last_deep_check_day: string;
  last_deep_check_count: number;
};

export type CheckinScoutDailySummary = {
  bitrixUserId: number;
  days: Array<{ day: string; checkins: number }>;
};

export type CheckinScoutSyncResult = {
  runId: string;
  fetchedRecords: number;
  observedAccounts: number;
  watchedAccounts: number;
  deepCheckCandidates: number;
  unresolvedCardCount: number;
  expiredAccounts: number;
};

export type CheckinScoutEvaluationResult = {
  deepCheckCandidates: number;
  deepChecksAttempted: number;
  deepChecksSucceeded: number;
  deepChecksFailed: number;
  confirmedAccounts: number;
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

const moscowDay = (date: Date): string => {
  const parts = new Intl.DateTimeFormat("en", {
    timeZone: "Europe/Moscow",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(date);
  const values = new Map(parts.map((part) => [part.type, part.value]));
  const day = `${values.get("year")}-${values.get("month")}-${values.get("day")}`;
  if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) {
    throw new Error("Check-in Scout не смог определить московскую календарную дату");
  }
  return day;
};

// Добавлено 19.09.2026 ИТ Директор Евразии
// Ключевой guard: 1 чекин в сутки является нормой и вообще не попадает в persistent
// Anti-Fraud storage. Snapshot группируется в памяти; в БД пишется только WATCH для 2+.
export const summarizeCheckinScoutSnapshot = (
  records: BitrixAntiFraudCheckinRecord[],
): CheckinScoutDailySummary[] => {
  const byUser = new Map<number, Map<string, Set<string>>>();

  for (const record of records) {
    const userId = Number(record.bitrixUserId);
    if (!Number.isInteger(userId) || userId <= 0) continue;
    const day = moscowDay(record.occurredAt);

    if (!byUser.has(userId)) byUser.set(userId, new Map());
    const days = byUser.get(userId)!;
    if (!days.has(day)) days.set(day, new Set());
    days.get(day)!.add(record.sourceRestisId);
  }

  const summaries: CheckinScoutDailySummary[] = [];
  for (const [bitrixUserId, days] of byUser) {
    const suspiciousDays = [...days.entries()]
      .map(([day, ids]) => ({ day, checkins: ids.size }))
      .filter((item) => item.checkins >= 2)
      .sort((a, b) => a.day.localeCompare(b.day));

    if (suspiciousDays.length) {
      summaries.push({ bitrixUserId, days: suspiciousDays });
    }
  }

  return summaries.sort((a, b) => a.bitrixUserId - b.bitrixUserId);
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
         WHEN anti_fraud_checkin_watch_state.status='deep_check' THEN 'deep_check'
         ELSE 'watching'
       END,
       watch_started_day = CASE
         WHEN anti_fraud_checkin_watch_state.status='expired'
           OR anti_fraud_checkin_watch_state.watch_until_day < $2::date
         THEN $2::date
         ELSE anti_fraud_checkin_watch_state.watch_started_day
       END,
       watch_until_day = CASE
         WHEN anti_fraud_checkin_watch_state.status='expired'
           OR anti_fraud_checkin_watch_state.watch_until_day < $2::date
         THEN $3::date
         ELSE anti_fraud_checkin_watch_state.watch_until_day
       END,
       last_trigger_day=$4::date,
       trigger_kind=$5,
       last_seen_at=now(),
       updated_at=now()`,
    [userId, startDay, untilDay, triggerDay, triggerKind],
  );
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
      state?.last_deep_check_day === day
      && Number(state.last_deep_check_count ?? 0) >= count
    ) {
      continue;
    }

    candidate = { day, count, kind };
  }

  return candidate;
};

// Snapshot читает ~весь поток посещений, но persistent Anti-Fraud получает только
// пользователей, у которых уже выполнено бизнес-условие 2+ чекина за московские сутки.
// Это принципиально не превращает нормальных посетителей с 1 чекином/день в Anti-Fraud accounts.
export const syncCheckinScoutSnapshotOnce = async (
  options: CheckinScoutSyncOptions = {},
): Promise<CheckinScoutSyncResult> => {
  const gateway = options.gateway ?? new BitrixAntiFraudCheckinGateway();
  const days = boundedInteger(Number(options.days ?? DEFAULT_DAYS), DEFAULT_DAYS, 1, 3);
  const snapshot = await gateway.fetchRecentCheckins(days);
  const summaries = summarizeCheckinScoutSnapshot(snapshot.records);
  const runId = randomUUID();
  const today = await currentMoscowDay();
  const client = await pool.connect();
  let lockAcquired = false;
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

    const userIds = summaries.map((item) => item.bitrixUserId);
    const states = await loadWatchStates(userIds);
    let watchedAccounts = 0;
    let deepCheckCandidates = 0;

    for (const summary of summaries) {
      const daily = new Map(summary.days.map((item) => [item.day, item.checkins]));
      const existing = states.get(summary.bitrixUserId);
      if (existing?.status === "confirmed") continue;

      const activeExisting =
        existing
        && existing.status !== "expired"
        && existing.watch_until_day >= today;

      const startDay = activeExisting ? existing.watch_started_day : summary.days[0]!.day;
      const firstCount = daily.get(startDay) ?? summary.days[0]!.checkins;
      const untilDay = activeExisting ? existing.watch_until_day : addDays(startDay, 2);
      const lastDay = summary.days[summary.days.length - 1]!.day;
      const initialKind: WatchRow["trigger_kind"] =
        firstCount >= 3 ? "triple_checkin" : "double_checkin";

      await persistWatchState(
        summary.bitrixUserId,
        startDay,
        untilDay,
        lastDay,
        initialKind,
      );
      watchedAccounts += 1;

      const deep = deepCheckNeeded(existing, startDay, daily);
      if (!deep) continue;

      const marked = await pool.query(
        `UPDATE anti_fraud_checkin_watch_state
         SET status='deep_check',
             trigger_kind=$2,
             last_trigger_day=$3::date,
             last_deep_check_day=$3::date,
             last_deep_check_count=$4,
             deep_check_requested_at=now(),
             deep_check_completed_at=NULL,
             updated_at=now()
         WHERE bitrix_user_id=$1
           AND status <> 'confirmed'
         RETURNING bitrix_user_id`,
        [summary.bitrixUserId, deep.kind, deep.day, deep.count],
      );
      if (marked.rowCount) deepCheckCandidates += 1;
    }

    const expired = await pool.query(
      `UPDATE anti_fraud_checkin_watch_state
       SET status='expired', updated_at=now()
       WHERE status='watching'
         AND watch_until_day < $1::date
       RETURNING bitrix_user_id`,
      [today],
    );

    await client.query(
      `UPDATE anti_fraud_sync_runs
       SET status='success', finished_at=now(), records_fetched=$2,
           records_written=$3, records_resolved=$4, error=NULL
       WHERE run_id=$1`,
      [runId, snapshot.records.length, watchedAccounts, deepCheckCandidates],
    );

    await client.query(
      `UPDATE anti_fraud_sync_state
       SET last_succeeded_at=now(), last_error=NULL,
           records_fetched=$2, records_written=$3, records_resolved=$4, updated_at=now()
       WHERE source=$1`,
      [SOURCE, snapshot.records.length, watchedAccounts, deepCheckCandidates],
    );

    return {
      runId,
      fetchedRecords: snapshot.records.length,
      observedAccounts: new Set(snapshot.records.map((record) => record.bitrixUserId)).size,
      watchedAccounts,
      deepCheckCandidates,
      unresolvedCardCount: snapshot.unresolvedCardCount,
      expiredAccounts: expired.rowCount ?? 0,
    };
  } catch (error) {
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

// Deep history запускается уже после account-map stage: enricher требует существующий
// anti_fraud_accounts row. Pending deep_check хранится в БД, поэтому restart между стадиями безопасен.
export const evaluateCheckinScoutOnce = async (
  options: CheckinScoutEvaluationOptions = {},
): Promise<CheckinScoutEvaluationResult> => {
  const maxDeepChecks = boundedInteger(
    Number(
      options.maxDeepChecks
      ?? process.env.ANTI_FRAUD_SCOUT_MAX_HISTORY_USERS_PER_RUN
      ?? DEFAULT_MAX_DEEP_CHECKS,
    ),
    DEFAULT_MAX_DEEP_CHECKS,
    1,
    MAX_DEEP_CHECKS,
  );

  const pending = await pool.query<PendingDeepCheckRow>(
    `SELECT
       bitrix_user_id,
       last_deep_check_day::text,
       last_deep_check_count
     FROM anti_fraud_checkin_watch_state
     WHERE status='deep_check'
       AND last_deep_check_day IS NOT NULL
       AND last_deep_check_count IS NOT NULL
     ORDER BY deep_check_requested_at NULLS FIRST, bitrix_user_id
     LIMIT $1`,
    [maxDeepChecks],
  );

  let deepChecksAttempted = 0;
  let deepChecksSucceeded = 0;
  let deepChecksFailed = 0;
  let confirmedAccounts = 0;

  for (const candidate of pending.rows) {
    const userId = Number(candidate.bitrix_user_id);
    deepChecksAttempted += 1;

    try {
      await enrichRestisHistoryForHighRiskUserOnce({
        bitrixUserId: userId,
        checkinScoutConfirmed: true,
        lookbackDays: 60,
        refreshHours: 0,
      });

      const confirmation = await loadConfirmation(userId);
      const confirmed =
        confirmation.days_3plus_60d >= CONFIRM_3PLUS_DAYS_60D
        || confirmation.days_2plus_7d >= CONFIRM_2PLUS_DAYS_7D;

      await pool.query(
        `UPDATE anti_fraud_checkin_watch_state
         SET status=$2,
             deep_check_completed_at=now(),
             confirmed_at=CASE
               WHEN $2='confirmed' THEN COALESCE(confirmed_at, now())
               ELSE confirmed_at
             END,
             confirmed_days_2plus_7d=$3,
             confirmed_days_3plus_60d=$4,
             updated_at=now()
         WHERE bitrix_user_id=$1`,
        [
          userId,
          confirmed ? "confirmed" : "watching",
          confirmation.days_2plus_7d,
          confirmation.days_3plus_60d,
        ],
      );

      deepChecksSucceeded += 1;
      if (confirmed) confirmedAccounts += 1;
    } catch {
      deepChecksFailed += 1;

      // Сбрасываем marker проверенного day/count только при технической ошибке,
      // чтобы следующий protected cycle повторил именно эту deep check.
      await pool.query(
        `UPDATE anti_fraud_checkin_watch_state
         SET status='watching',
             last_deep_check_day=NULL,
             last_deep_check_count=NULL,
             updated_at=now()
         WHERE bitrix_user_id=$1
           AND status <> 'confirmed'`,
        [userId],
      );
    }
  }

  return {
    deepCheckCandidates: pending.rows.length,
    deepChecksAttempted,
    deepChecksSucceeded,
    deepChecksFailed,
    confirmedAccounts,
  };
};
