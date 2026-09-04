import { pool } from "@workspace/db";
import { enrichRestisHistoryForHighRiskUserOnce } from "./services/anti-fraud-restis-history-enricher";

// Добавлено 03.09.2026 ИТ Директор Евразии
const positiveInteger = (name: string, fallback?: number): number => {
  const raw = (process.env[name] ?? "").trim();
  if (!raw && fallback !== undefined) return fallback;
  const value = Number(raw);
  if (!Number.isInteger(value) || value <= 0) {
    throw new Error(`${name} должен быть положительным integer`);
  }
  return value;
};

// Добавлено 03.09.2026 ИТ Директор Евразии
const main = async (): Promise<void> => {
  if ((process.env.ANTI_FRAUD_HISTORY_RISK_GATE_CONFIRMED ?? "").trim() !== "true") {
    throw new Error(
      "ANTI_FRAUD_HISTORY_RISK_GATE_CONFIRMED=true обязателен для адресного VIP_HISTORY",
    );
  }

  const result = await enrichRestisHistoryForHighRiskUserOnce({
    bitrixUserId: positiveInteger("ANTI_FRAUD_HISTORY_BITRIX_USER_ID"),
    riskGateConfirmed: true,
    lookbackDays: positiveInteger("ANTI_FRAUD_HISTORY_LOOKBACK_DAYS", 60),
    refreshHours: positiveInteger("ANTI_FRAUD_HISTORY_REFRESH_HOURS", 24),
    pageSize: positiveInteger("RESTIS_VIP_HISTORY_PAGE_SIZE", 10_000),
  });

  process.stdout.write(`${JSON.stringify({ status: "ok", source: "restis_vip_history", ...result })}\n`);
};

main()
  .catch((error: unknown) => {
    const message = error instanceof Error ? error.message : "Неизвестная ошибка VIP_HISTORY";
    process.stderr.write(
      `${JSON.stringify({ status: "error", source: "restis_vip_history", error: message })}\n`,
    );
    process.exitCode = 1;
  })
  .finally(async () => {
    await pool.end();
  });
