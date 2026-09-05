import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import { logger } from "../lib/logger";
import { syncTrustedDeviceOnce } from "./anti-fraud-trusted-device-collector";
import { syncBitrixAccountsOnce } from "./anti-fraud-bitrix-account-collector";
import {
  refreshLoyaltyBalancesForUsersOnce,
  refreshStalestLoyaltyBalancesOnce,
} from "./anti-fraud-loyalty-balance-service";
import { analyzeAntiFraudWithSimilarityOnce } from "./anti-fraud-identity-similarity-service";
import { captureAntiFraudCaseDynamics } from "./anti-fraud-case-dynamics-service";

const SOURCE = "anti_fraud_protected_cycle";
const DEFAULT_INTERVAL_MINUTES = 15;
const MIN_INTERVAL_MINUTES = 15;
const MAX_INTERVAL_MINUTES = 24 * 60;
const DEFAULT_LOYALTY_STALE_BATCH = 200;
const MAX_PRIORITY_LOYALTY_USERS = 200;

type SchedulerStatus = {
  enabled: boolean;
  running: boolean;
  intervalMinutes: number;
  lastStartedAt: string | null;
  lastFinishedAt: string | null;
  lastSucceededAt: string | null;
  lastStatus: "idle" | "running" | "success" | "partial" | "failed";
  lastError: string | null;
  nextRunAt: string | null;
};

type StageResult = {
  stage: string;
  ok: boolean;
  error?: string;
};

const schedulerStatus: SchedulerStatus = {
  enabled: false,
  running: false,
  intervalMinutes: DEFAULT_INTERVAL_MINUTES,
  lastStartedAt: null,
  lastFinishedAt: null,
  lastSucceededAt: null,
  lastStatus: "idle",
  lastError: null,
  nextRunAt: null,
};

let timer: NodeJS.Timeout | null = null;
let started = false;
let stopped = false;

const envBoolean = (name: string, fallback: boolean): boolean => {
  const raw = String(process.env[name] ?? "").trim().toLowerCase();
  if (!raw) return fallback;
  if (["1", "true", "yes", "on"].includes(raw)) return true;
  if (["0", "false", "no", "off"].includes(raw)) return false;
  throw new Error(`${name} должен быть boolean`);
};

const boundedInterval = (value: number): number => {
  if (!Number.isInteger(value)) return DEFAULT_INTERVAL_MINUTES;
  return Math.max(MIN_INTERVAL_MINUTES, Math.min(MAX_INTERVAL_MINUTES, value));
};

const boundedLoyaltyBatch = (value: number): number => {
  if (!Number.isInteger(value) || value <= 0) return DEFAULT_LOYALTY_STALE_BATCH;
  return Math.min(200, value);
};

const safeError = (error: unknown): string =>
  (error instanceof Error ? error.message : "Неизвестная ошибка").slice(0, 1000);

const runStage = async (stage: string, action: () => Promise<unknown>): Promise<StageResult> => {
  try {
    await action();
    return { stage, ok: true };
  } catch (error) {
    const message = safeError(error);
    logger.warn({ stage, error: message }, "Anti-Fraud protected source stage failed");
    return { stage, ok: false, error: message };
  }
};

const persistCycleStart = async (runId: string): Promise<void> => {
  await pool.query(
    `INSERT INTO anti_fraud_sync_runs (run_id, source, status, started_at)
     VALUES ($1, $2, 'running', now())`,
    [runId, SOURCE],
  );

  await pool.query(
    `INSERT INTO anti_fraud_sync_state (source, last_started_at, last_error, updated_at)
     VALUES ($1, now(), NULL, now())
     ON CONFLICT (source) DO UPDATE SET
       last_started_at = EXCLUDED.last_started_at,
       last_error = NULL,
       updated_at = now()`,
    [SOURCE],
  );
};

const persistCycleFinish = async (
  runId: string,
  status: "success" | "partial" | "failed",
  stages: StageResult[],
  error: string | null,
): Promise<void> => {
  const sourceFailures = stages.filter((stage) => !stage.ok);
  const combinedError = error ?? (
    sourceFailures.length
      ? sourceFailures.map((stage) => `${stage.stage}: ${stage.error ?? "failed"}`).join(" | ").slice(0, 2000)
      : null
  );

  await pool.query(
    `UPDATE anti_fraud_sync_runs
     SET status = $2,
         finished_at = now(),
         records_fetched = $3,
         records_written = $4,
         records_resolved = $5,
         error = $6
     WHERE run_id = $1`,
    [
      runId,
      status,
      stages.length,
      stages.filter((stage) => stage.ok).length,
      stages.filter((stage) => !stage.ok).length,
      combinedError,
    ],
  );

  await pool.query(
    `UPDATE anti_fraud_sync_state
     SET last_succeeded_at = CASE WHEN $2 <> 'failed' THEN now() ELSE last_succeeded_at END,
         last_error = $3,
         records_fetched = $4,
         records_written = $5,
         records_resolved = $6,
         updated_at = now()
     WHERE source = $1`,
    [
      SOURCE,
      status,
      combinedError,
      stages.length,
      stages.filter((stage) => stage.ok).length,
      stages.filter((stage) => !stage.ok).length,
    ],
  );
};

const refreshPriorityLoyaltyOnce = async (): Promise<void> => {
  const result = await pool.query<{ bitrix_user_id: number }>(
    `SELECT s.bitrix_user_id
     FROM anti_fraud_risk_scores s
     JOIN anti_fraud_accounts a ON a.bitrix_user_id = s.bitrix_user_id
     WHERE a.bitrix_active IS TRUE
       AND s.overall_risk > 0
     ORDER BY s.overall_risk DESC, s.bitrix_user_id
     LIMIT $1`,
    [MAX_PRIORITY_LOYALTY_USERS],
  );

  const userIds = result.rows.map((row) => Number(row.bitrix_user_id));
  if (!userIds.length) return;
  await refreshLoyaltyBalancesForUsersOnce({ userIds });
};

// Обновлено 05.09.2026 ИТ Директор Евразии
// Защищённый цикл больше НЕ использует прямые RestIS VIP_TODAY/card-map и не требует
// RestIS credentials в контейнере бота. Каждые 15 минут при включённом scheduler:
// 1) полный snapshot Trusted Device;
// 2) account-map по известным USER_ID;
// 3) свежий loyalty TotalSum/count/issue для уже рискованных аккаунтов;
// 4) rolling refresh до 200 самых давно не проверявшихся активных аккаунтов;
// 5) explainable risk + адресная history только для history gate;
// 6) фиксация case dynamics.
// Rolling loyalty выбран намеренно: полный флот = ~1800 RestIS Balance вызовов за один проход,
// поэтому без отдельного load-test не запускаем такой burst каждые 15 минут.
export const runAntiFraudHourlyCycleOnce = async (): Promise<void> => {
  if (schedulerStatus.running) {
    logger.warn("Anti-Fraud protected cycle skipped because previous cycle is still running");
    return;
  }

  const runId = randomUUID();
  schedulerStatus.running = true;
  schedulerStatus.lastStatus = "running";
  schedulerStatus.lastStartedAt = new Date().toISOString();
  schedulerStatus.lastError = null;

  const stages: StageResult[] = [];

  try {
    await persistCycleStart(runId);

    stages.push(await runStage("trusted_device_export", () => syncTrustedDeviceOnce()));
    stages.push(await runStage("bitrix_account_map", () => syncBitrixAccountsOnce()));
    stages.push(await runStage("loyalty_priority", () => refreshPriorityLoyaltyOnce()));
    stages.push(
      await runStage("loyalty_stale_scan", async () => {
        const limit = boundedLoyaltyBatch(
          Number(process.env.ANTI_FRAUD_LOYALTY_SCAN_BATCH_SIZE ?? DEFAULT_LOYALTY_STALE_BATCH),
        );
        await refreshStalestLoyaltyBalancesOnce({ limit });
      }),
    );

    // account-map уже выполнен отдельным stage. Auto-history остаётся только адресным:
    // protected loyalty endpoint вызывается для history-gated USER_ID, а не для всего флота.
    await analyzeAntiFraudWithSimilarityOnce({
      refreshAccounts: false,
      autoHistory: true,
    });
    stages.push({ stage: "risk_scoring", ok: true });

    stages.push(await runStage("case_dynamics", () => captureAntiFraudCaseDynamics()));

    const partial = stages.some((stage) => !stage.ok);
    const finalStatus = partial ? "partial" : "success";
    await persistCycleFinish(runId, finalStatus, stages, null);

    schedulerStatus.lastStatus = finalStatus;
    schedulerStatus.lastFinishedAt = new Date().toISOString();
    schedulerStatus.lastSucceededAt = schedulerStatus.lastFinishedAt;
    schedulerStatus.lastError = partial
      ? stages.filter((stage) => !stage.ok).map((stage) => `${stage.stage}: ${stage.error}`).join(" | ")
      : null;

    logger.info(
      {
        runId,
        status: finalStatus,
        failedStages: stages.filter((stage) => !stage.ok).map((stage) => stage.stage),
      },
      "Anti-Fraud protected cycle finished",
    );
  } catch (error) {
    const message = safeError(error);
    stages.push({ stage: "risk_scoring", ok: false, error: message });

    try {
      await persistCycleFinish(runId, "failed", stages, message);
    } catch (persistError) {
      logger.warn({ error: safeError(persistError) }, "Could not persist Anti-Fraud protected cycle failure");
    }

    schedulerStatus.lastStatus = "failed";
    schedulerStatus.lastFinishedAt = new Date().toISOString();
    schedulerStatus.lastError = message;
    logger.error({ runId, error: message }, "Anti-Fraud protected cycle failed");
  } finally {
    schedulerStatus.running = false;
  }
};

const scheduleNext = (): void => {
  if (stopped || !schedulerStatus.enabled) return;
  const delayMs = schedulerStatus.intervalMinutes * 60_000;
  schedulerStatus.nextRunAt = new Date(Date.now() + delayMs).toISOString();
  timer = setTimeout(() => {
    timer = null;
    void runAntiFraudHourlyCycleOnce().finally(() => scheduleNext());
  }, delayMs);
  timer.unref();
};

export const startAntiFraudHourlyScheduler = (): void => {
  if (started) return;
  started = true;
  stopped = false;

  schedulerStatus.enabled = envBoolean("ANTI_FRAUD_SCHEDULER_ENABLED", false);
  schedulerStatus.intervalMinutes = boundedInterval(
    Number(process.env.ANTI_FRAUD_SCHEDULER_INTERVAL_MINUTES ?? DEFAULT_INTERVAL_MINUTES),
  );

  if (!schedulerStatus.enabled) {
    logger.info("Anti-Fraud protected scheduler is disabled");
    return;
  }

  const runOnStart = envBoolean("ANTI_FRAUD_SCHEDULER_RUN_ON_START", false);
  logger.info(
    {
      intervalMinutes: schedulerStatus.intervalMinutes,
      runOnStart,
    },
    "Anti-Fraud protected scheduler started",
  );

  if (runOnStart) {
    schedulerStatus.nextRunAt = new Date(Date.now() + 5_000).toISOString();
    timer = setTimeout(() => {
      timer = null;
      void runAntiFraudHourlyCycleOnce().finally(() => scheduleNext());
    }, 5_000);
    timer.unref();
  } else {
    scheduleNext();
  }
};

export const stopAntiFraudHourlyScheduler = (): void => {
  stopped = true;
  if (timer) clearTimeout(timer);
  timer = null;
  schedulerStatus.nextRunAt = null;
};

export const getAntiFraudHourlySchedulerStatus = (): SchedulerStatus => ({
  ...schedulerStatus,
});
