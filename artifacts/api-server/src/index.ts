import app from "./app";
import { logger } from "./lib/logger";
import { migrateDatabase } from "@workspace/db/migrate";
import path from "node:path";
import { fileURLToPath } from "node:url";
import {
  startEvrasiaTelegramBot,
  stopEvrasiaTelegramBot,
} from "./services/evrasia-telegram-bot";

const rawPort = process.env["PORT"];

if (!rawPort) {
  throw new Error(
    "PORT environment variable is required but was not provided.",
  );
}

const port = Number(rawPort);

if (Number.isNaN(port) || port <= 0) {
  throw new Error(`Invalid PORT value: "${rawPort}"`);
}

await migrateDatabase(
  path.resolve(path.dirname(fileURLToPath(import.meta.url)), "./drizzle"),
);

const server = app.listen(port, () => {
  logger.info({ port }, "Server listening");
  startEvrasiaTelegramBot();
});

server.on("error", (err) => {
  logger.error({ err }, "Error listening on port");
  process.exit(1);
});

let shuttingDown = false;

const shutdown = (signal: NodeJS.Signals): void => {
  if (shuttingDown) return;
  shuttingDown = true;
  logger.info({ signal }, "Shutting down API server");
  const forceExit = setTimeout(() => {
    logger.warn({ signal }, "Forced API shutdown after grace period");
    process.exit(1);
  }, 30_000);
  forceExit.unref();

  void (async () => {
    try {
      await Promise.all([
        stopEvrasiaTelegramBot(),
        new Promise<void>((resolve, reject) => {
          server.close((err) => {
            if (err) reject(err);
            else resolve();
          });
        }),
      ]);
      clearTimeout(forceExit);
      process.exit(0);
    } catch (err) {
      logger.error({ err }, "API server did not close cleanly");
      process.exit(1);
    }
  })();
};

process.once("SIGTERM", () => shutdown("SIGTERM"));
process.once("SIGINT", () => shutdown("SIGINT"));
