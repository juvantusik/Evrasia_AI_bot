import { pool } from "@workspace/db";
import { syncTrustedDeviceOnce } from "./services/anti-fraud-trusted-device-collector";

// Добавлено 03.09.2026 ИТ Директор Евразии
const main = async (): Promise<void> => {
  try {
    const result = await syncTrustedDeviceOnce();
    process.stdout.write(
      `${JSON.stringify({
        status: "ok",
        source: "trusted_device_export",
        runId: result.runId,
        fetchedLinks: result.fetchedLinks,
        syncedLinks: result.syncedLinks,
        removedLinks: result.removedLinks,
        fetchedEvents: result.fetchedEvents,
        writtenEvents: result.writtenEvents,
        observedAccounts: result.observedAccounts,
        eventCursor: result.eventCursor,
      })}\n`,
    );
  } catch (error) {
    const message =
      error instanceof Error
        ? error.message
        : "Неизвестная ошибка Anti-Fraud Trusted Device CLI";
    process.stderr.write(
      `${JSON.stringify({
        status: "error",
        source: "trusted_device_export",
        error: message.slice(0, 2000),
      })}\n`,
    );
    process.exitCode = 1;
  } finally {
    await pool.end();
  }
};

void main();
