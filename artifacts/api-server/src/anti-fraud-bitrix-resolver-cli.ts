import { pool } from "@workspace/db";
import { resolveBitrixCardsOnce } from "./services/anti-fraud-bitrix-card-resolver";

const parseBatchSize = (): number | undefined => {
  const raw = process.env.BITRIX_ANTI_FRAUD_BATCH_SIZE?.trim();
  if (!raw) return undefined;

  const value = Number(raw);
  if (!Number.isInteger(value) || value <= 0 || value > 500) {
    throw new Error("BITRIX_ANTI_FRAUD_BATCH_SIZE должен быть целым числом от 1 до 500");
  }
  return value;
};

// Добавлено 03.09.2026 ИТ Директор Евразии
const main = async (): Promise<void> => {
  try {
    const result = await resolveBitrixCardsOnce({ batchSize: parseBatchSize() });
    process.stdout.write(
      `${JSON.stringify({
        status: "ok",
        source: "bitrix_card_map",
        runId: result.runId,
        requestedCards: result.requestedCards,
        resolvedCards: result.resolvedCards,
        unresolvedCards: result.unresolvedCards,
        ambiguousCards: result.ambiguousCards,
        updatedCards: result.updatedCards,
        updatedVisits: result.updatedVisits,
      })}\n`,
    );
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "Неизвестная ошибка Anti-Fraud Bitrix resolver CLI";
    process.stderr.write(
      `${JSON.stringify({
        status: "error",
        source: "bitrix_card_map",
        error: message.slice(0, 2000),
      })}\n`,
    );
    process.exitCode = 1;
  } finally {
    await pool.end();
  }
};

void main();
