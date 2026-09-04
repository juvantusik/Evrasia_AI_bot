import { pool } from "@workspace/db";
import { syncBitrixAccountsOnce } from "./services/anti-fraud-bitrix-account-collector";

const parseBatchSize = (): number | undefined => {
  const raw = process.env.BITRIX_ANTI_FRAUD_ACCOUNT_BATCH_SIZE?.trim();
  if (!raw) return undefined;

  const value = Number(raw);
  if (!Number.isInteger(value) || value <= 0 || value > 500) {
    throw new Error(
      "BITRIX_ANTI_FRAUD_ACCOUNT_BATCH_SIZE должен быть целым числом от 1 до 500",
    );
  }
  return value;
};

// Добавлено 03.09.2026 ИТ Директор Евразии
const main = async (): Promise<void> => {
  try {
    const result = await syncBitrixAccountsOnce({ batchSize: parseBatchSize() });
    process.stdout.write(
      `${JSON.stringify({
        status: "ok",
        source: "bitrix_account_map",
        runId: result.runId,
        requestedAccounts: result.requestedAccounts,
        resolvedAccounts: result.resolvedAccounts,
        unresolvedAccounts: result.unresolvedAccounts,
        updatedAccounts: result.updatedAccounts,
      })}\n`,
    );
  } catch (error) {
    const message =
      error instanceof Error
        ? error.message
        : "Неизвестная ошибка Anti-Fraud Bitrix account CLI";
    process.stderr.write(
      `${JSON.stringify({
        status: "error",
        source: "bitrix_account_map",
        error: message.slice(0, 2000),
      })}\n`,
    );
    process.exitCode = 1;
  } finally {
    await pool.end();
  }
};

void main();
