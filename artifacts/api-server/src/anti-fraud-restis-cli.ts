import { pool } from "@workspace/db";
import { collectRestisVisitsOnce } from "./services/anti-fraud-restis-service";

const parsePageSize = (): number | undefined => {
  const raw = process.env.RESTIS_VIP_TODAY_PAGE_SIZE?.trim();
  if (!raw) return undefined;

  const value = Number(raw);
  if (!Number.isInteger(value) || value <= 0 || value > 10_000) {
    throw new Error("RESTIS_VIP_TODAY_PAGE_SIZE должен быть целым числом от 1 до 10000");
  }
  return value;
};

// Добавлено 03.09.2026 ИТ Директор Евразии
const main = async (): Promise<void> => {
  try {
    const result = await collectRestisVisitsOnce({ pageSize: parsePageSize() });
    process.stdout.write(
      `${JSON.stringify({
        status: "ok",
        source: "restis_vip_today",
        runId: result.runId,
        rawRows: result.rawRows,
        uniqueEvents: result.uniqueEvents,
        writtenEvents: result.writtenEvents,
        seenCards: result.seenCards,
      })}\n`,
    );
  } catch (error) {
    const message = error instanceof Error ? error.message : "Неизвестная ошибка Anti-Fraud RestIS CLI";
    process.stderr.write(
      `${JSON.stringify({
        status: "error",
        source: "restis_vip_today",
        error: message.slice(0, 2000),
      })}\n`,
    );
    process.exitCode = 1;
  } finally {
    await pool.end();
  }
};

void main();
