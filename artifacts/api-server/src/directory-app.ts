import express, { type Express } from "express";
import pinoHttp from "pino-http";
import path from "node:path";
import directoryRouter from "./routes/directory-web";
import { logger } from "./lib/logger";

const app: Express = express();

app.disable("x-powered-by");
app.use(
  pinoHttp({
    logger,
    serializers: {
      req(req) {
        return { id: req.id, method: req.method, url: req.url?.split("?")[0] };
      },
      res(res) {
        return { statusCode: res.statusCode };
      },
    },
  }),
);
app.use(express.json({ limit: "1mb" }));
app.use(express.urlencoded({ extended: true }));
app.use("/api", directoryRouter);

const staticRoot = process.env.STATIC_ROOT?.trim();
if (staticRoot) {
  const indexFile = path.join(staticRoot, "index.html");
  app.use(express.static(staticRoot, { index: false }));
  app.use((req, res, next) => {
    if (req.method !== "GET" || req.path.startsWith("/api/")) {
      next();
      return;
    }
    res.sendFile(indexFile);
  });
}

export default app;
