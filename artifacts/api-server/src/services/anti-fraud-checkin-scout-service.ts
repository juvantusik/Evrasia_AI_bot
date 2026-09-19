import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import { BitrixAntiFraudCheckinGateway } from "./bitrix-antifraud-checkin-gateway";
import {
  evaluateCheckinScoutPhysicalHistory,
  summarizeCheckinScoutSnapshot,
} from "./anti-fraud-checkin-scout-rules";

const SOURCE = "bitrix_checkin_scout";
const LOCK_NAME = "anti_fraud_checkin_scout_sync";
const DEFAULT_DAYS = 3;
const DEFAULT_MAX_DEEP_CHECKS = 10;
const MAX_DEEP_CHECKS = 50;

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

type PendingDeepCheckRow = {
  bitrix_user_id: number;
  last_deep_check_day: string;
  last_deep_check_count: number;
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
  gateway?: BitrixAntiFraudCheckinGateway;
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

// Snapshot читает весь 3-дневный поток, но persistent Anti-Fraud получает только
// пользователей, у которых выполнено бизнес-условие 2+ чекина за московские сутки.
// Нормальные 1 чекин/сутки не пишутся ни в anti_fraud_visits, ни в account-map.
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

// Deep-check читает адресную 60-дневную физическую историю COfflineOrderHl.
// VIP_HISTORY здесь намеренно не используется: её строки отражают денежные операции
// и не являются надёжным источником количества физических посещений.
export const evaluateCheckinScoutOnce = async (
  options: CheckinScoutEvaluationOptions = {},
): Promise<CheckinScoutEvaluationResult> => {
  const gateway = options.gateway ?? new BitrixAntiFraudCheckinGateway();
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
      const history = await gateway.fetchUserHistory(userId, 60);
      const confirmation = evaluateCheckinScoutPhysicalHistory(history.records);

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
          confirmation.confirmed ? "confirmed" : "watching",
          confirmation.days2Plus7d,
          confirmation.days3Plus60d,
        ],
      );

      deepChecksSucceeded += 1;
      if (confirmation.confirmed) confirmedAccounts += 1;
    } catch {
      deepChecksFailed += 1;

      // Только техническая ошибка разрешает retry того же trigger на следующем цикле.
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
