import { BitrixAntiFraudLoyaltyGateway } from "./services/bitrix-antifraud-loyalty-gateway";

const parseUserId = (): number => {
  const value = Number(process.env.ANTI_FRAUD_LOYALTY_USER_ID ?? "");
  if (!Number.isInteger(value) || value <= 0) {
    throw new Error("ANTI_FRAUD_LOYALTY_USER_ID должен быть положительным integer");
  }
  return value;
};

const parseHistoryDays = (): number => {
  const raw = process.env.ANTI_FRAUD_LOYALTY_HISTORY_DAYS?.trim();
  if (!raw) return 60;
  const value = Number(raw);
  if (!Number.isInteger(value) || value < 1 || value > 60) {
    throw new Error("ANTI_FRAUD_LOYALTY_HISTORY_DAYS должен быть от 1 до 60");
  }
  return value;
};

const includeHistory = (): boolean =>
  String(process.env.ANTI_FRAUD_LOYALTY_INCLUDE_HISTORY ?? "false").toLowerCase() === "true";

// Добавлено 05.09.2026 ИТ Директор Евразии
// Диагностический CLI никогда не печатает service token или номер карты.
const main = async (): Promise<void> => {
  try {
    const userId = parseUserId();
    const history = includeHistory();
    const historyDays = parseHistoryDays();
    const gateway = new BitrixAntiFraudLoyaltyGateway();
    const result = await gateway.resolveLoyalty([userId], {
      includeHistory: history,
      historyDays,
    });
    const record = result.records[0] ?? null;

    process.stdout.write(
      `${JSON.stringify({
        status: "ok",
        source: "bitrix_antifraud_loyalty",
        requested: result.requested,
        resolved: result.resolved,
        unresolved: result.unresolved,
        record: record
          ? {
              bitrixUserId: record.bitrixUserId,
              activeCardFound: record.activeCardFound,
              activeCardCount: record.activeCardCount,
              cardStatusId: record.cardStatusId,
              cardType: record.cardType,
              discountPercent: record.discountPercent,
              bonusBalance: record.bonusBalance,
              totalSpend: record.totalSpend,
              todaySum: record.todaySum,
              issue: record.issue,
              historySummary: record.historySummary
                ? {
                    historyDays: record.historySummary.historyDays,
                    visits: record.historySummary.visits,
                    amount: record.historySummary.amount,
                    bonusAdded: record.historySummary.bonusAdded,
                    bonusSpent: record.historySummary.bonusSpent,
                  }
                : null,
              maxBonusSpent:
                record.history && record.history.length
                  ? record.history.reduce(
                      (best, event) =>
                        Number(event.bonusSpent) > Number(best.bonusSpent) ? event : best,
                      record.history[0],
                    ).bonusSpent
                  : null,
            }
          : null,
      })}\n`,
    );
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "Неизвестная ошибка Anti-Fraud loyalty CLI";
    process.stderr.write(
      `${JSON.stringify({
        status: "error",
        source: "bitrix_antifraud_loyalty",
        error: message.slice(0, 2000),
      })}\n`,
    );
    process.exitCode = 1;
  }
};

void main();
