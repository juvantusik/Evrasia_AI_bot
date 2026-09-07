import { pool } from "@workspace/db";
import { analyzeAntiFraudWithSimilarityOnce } from "./services/anti-fraud-identity-similarity-service";

const envBoolean = (name: string, fallback: boolean): boolean => {
  const raw = (process.env[name] ?? "").trim().toLowerCase();
  if (!raw) return fallback;
  if (["1", "true", "yes", "on"].includes(raw)) return true;
  if (["0", "false", "no", "off"].includes(raw)) return false;
  throw new Error(`${name} должен быть boolean`);
};

// Добавлено 03.09.2026 ИТ Директор Евразии
const main = async (): Promise<void> => {
  try {
    const result = await analyzeAntiFraudWithSimilarityOnce({
      refreshAccounts: envBoolean("ANTI_FRAUD_REFRESH_ACCOUNTS", true),
      autoHistory: envBoolean("ANTI_FRAUD_AUTO_HISTORY", false),
    });

    process.stdout.write(
      `${JSON.stringify({
        status: "ok",
        source: "anti_fraud_risk_scoring",
        runId: result.runId,
        scoredAccounts: result.scoredAccounts,
        mediumAccounts: result.mediumAccounts,
        highAccounts: result.highAccounts,
        criticalAccounts: result.criticalAccounts,
        historyGateAccounts: result.historyGateAccounts,
        historyEligibleAccounts: result.historyEligibleAccounts,
        historyAttemptedAccounts: result.historyAttemptedAccounts,
        historySuccessfulAccounts: result.historySuccessfulAccounts,
        historyFailedAccounts: result.historyFailedAccounts,
        refreshedAccounts: result.refreshedAccounts,
        identityCandidatePairs: result.identityCandidatePairs,
        identityCorroboratedPairs: result.identityCorroboratedPairs,
        identitySimilarEmailPairs: result.identitySimilarEmailPairs,
        identitySimilarPhonePairs: result.identitySimilarPhonePairs,
        topRisk: result.topRisk,
      })}\n`,
    );
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "Неизвестная ошибка Anti-Fraud risk CLI";
    process.stderr.write(
      `${JSON.stringify({
        status: "error",
        source: "anti_fraud_risk_scoring",
        error: message.slice(0, 2000),
      })}\n`,
    );
    process.exitCode = 1;
  } finally {
    await pool.end();
  }
};

void main();
