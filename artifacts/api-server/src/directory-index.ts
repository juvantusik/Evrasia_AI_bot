import app from "./directory-app";
import { logger } from "./lib/logger";
import { ensureDirectoryWebSchema } from "./services/directory-web-service";

const rawPort = process.env.PORT ?? "8080";
const port = Number(rawPort);
if (!Number.isInteger(port) || port <= 0) {
  throw new Error(`Invalid PORT value: "${rawPort}"`);
}

await ensureDirectoryWebSchema();

const server = app.listen(port, () => {
  logger.info({ port, version: "1.6.9" }, "Evrasia corporate directory web server listening");
});

const shutdown = (signal: string): void => {
  logger.info({ signal }, "Stopping corporate directory web server");
  server.close((error) => {
    if (error) {
      logger.error({ error }, "Failed to stop corporate directory web server cleanly");
      process.exit(1);
    }
    process.exit(0);
  });
};

process.on("SIGTERM", () => shutdown("SIGTERM"));
process.on("SIGINT", () => shutdown("SIGINT"));
