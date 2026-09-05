import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import { logger } from "../lib/logger";
import { collectRestisVisitsOnce } from "./anti-fraud-restis-service";
import { resolveBitrixCardsOnce } from "./anti-fraud-bitrix-card-resolver";
import { syncTrustedDeviceOnce } from "./anti-fraud-trusted-device-collector";
import { analyzeAntiFraudWithSimilarityOnce } from "./anti-fraud-identity-similarity-service";
import { captureAntiFraudCaseDynamics } from "./anti-fraud-case-dynamics-service";

const SOURCE = "anti_fraud_hourly_cycle";
const DEFAULT_INTERVAL_MINUTES = 60;
const MIN_INTERVAL_MINUTES = 15;
const MAX_INTERVAL_MINUTES = 24 * 60;

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

const safeError = (error: unknown): string =>
  (error instanceof Error ? error.message : "Неизвестная ошибка").slice(0, 1000);

const runStage = async (stage: string, action: () => Promise<unknown>): Promise<StageResult> => {
  try {
    await action();
    return { stage, ok: true };
  } catch (error) {
    const message = safeError(error);
    logger.warn({ stage, error: message }, "Anti-Fraud hourly source stage failed");
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

// Один hourly-проход. Источники читаются последовательно, чтобы card/account-map
// увидели события, только что полученные из RestIS/Trusted Device. Ошибка одного
// источника не отменяет расчёт по последнему валидному snapshot остальных источников.
export const runAntiFraudHourlyCycleOnce = async (): Promise<void> => {
  if (schedulerStatus.running) {
    logger.warn("Anti-Fraud hourly cycle skipped because previous cycle is still running");
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

    stages.push(await runStage("restis_vip_today", () => collectRestisVisitsOnce()));
    stages.push(await runStage("bitrix_card_map", () => resolveBitrixCardsOnce()));
    stages.push(await runStage("trusted_device_export", () => syncTrustedDeviceOnce()));

    // analyzeAntiFraudWithSimilarityOnce внутри сначала обновит уже известные аккаунты Bitrix,
    // затем построит candidate/corroborated identity-связи, пересчитает score и при gate>=50
    // адресно загрузит VIP_HISTORY за 60 дней.
    await analyzeAntiFraudWithSimilarityOnce({
      refreshAccounts: true,
      autoHistory: true,
    });
    stages.push({ stage: "risk_scoring", ok: true });

    // После полностью завершённого score фиксируем состояние кейсов. Так Risk 100
    // остаётся 100, но рост группы/устройств/reason codes виден как «было → стало».
    await captureAntiFraudCaseDynamics();
    stages.push({ stage: "case_dynamics", ok: true });

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
      "Anti-Fraud hourly cycle finished",
    );
  } catch (error) {
    const message = safeError(error);
    stages.push({ stage: "risk_scoring", ok: false, error: message });

    try {
      await persistCycleFinish(runId, "failed", stages, message);
    } catch (persistError) {
      logger.warn({ error: safeError(persistError) }, "Could not persist Anti-Fraud hourly failure");
    }

    schedulerStatus.lastStatus = "failed";
    schedulerStatus.lastFinishedAt = new Date().toISOString();
    schedulerStatus.lastError = message;
    logger.error({ runId, error: message }, "Anti-Fraud hourly cycle failed");
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
    logger.info("Anti-Fraud hourly scheduler is disabled");
    return;
  }

  const runOnStart = envBoolean("ANTI_FRAUD_SCHEDULER_RUN_ON_START", true);
  logger.info(
    {
      intervalMinutes: schedulerStatus.intervalMinutes,
      runOnStart,
    },
    "Anti-Fraud hourly scheduler started",
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
