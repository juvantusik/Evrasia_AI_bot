import { pool } from "@workspace/db";
import { refreshLoyaltyBalancesForUsersOnce } from "./services/anti-fraud-loyalty-balance-service";

const MAX_USER_IDS = 200;

const parseUserIds = (): number[] => {
  const raw = (process.env.ANTI_FRAUD_LOYALTY_USER_IDS ?? "").trim();
  if (!raw) {
    throw new Error("ANTI_FRAUD_LOYALTY_USER_IDS обязателен");
  }

  const result = new Set<number>();
  for (const token of raw.split(/[\s,;]+/)) {
    if (!token) continue;
    const value = Number(token);
    if (!Number.isInteger(value) || value <= 0) {
      throw new Error("ANTI_FRAUD_LOYALTY_USER_IDS должен содержать только положительные USER_ID");
    }
    result.add(value);
  }

  const userIds = [...result];
  if (!userIds.length || userIds.length > MAX_USER_IDS) {
    throw new Error(`ANTI_FRAUD_LOYALTY_USER_IDS должен содержать от 1 до ${MAX_USER_IDS} USER_ID`);
  }
  return userIds;
};

// Добавлено 05.09.2026 ИТ Директор Евразии
// CLI предназначен для безопасного адресного/backfill обновления текущих бонусных балансов.
// Он использует только protected loyalty endpoint, не запрашивает историю и не печатает USER_ID,
// service token, номер карты или RestIS credentials.
const main = async (): Promise<void> => {
  try {
    const userIds = parseUserIds();
    const result = await refreshLoyaltyBalancesForUsersOnce({ userIds });

    process.stdout.write(
      `${JSON.stringify({
        status: "ok",
        source: "bitrix_loyalty_balance",
        runId: result.runId,
        requestedAccounts: result.requestedAccounts,
        resolvedAccounts: result.resolvedAccounts,
        unresolvedAccounts: result.unresolvedAccounts,
        updatedAccounts: result.updatedAccounts,
        activeCardAccounts: result.activeCardAccounts,
      })}\n`,
    );
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "Неизвестная ошибка Anti-Fraud loyalty balance CLI";
    process.stderr.write(
      `${JSON.stringify({
        status: "error",
        source: "bitrix_loyalty_balance",
        error: message.slice(0, 2000),
      })}\n`,
    );
    process.exitCode = 1;
  } finally {
    await pool.end();
  }
};

void main();
