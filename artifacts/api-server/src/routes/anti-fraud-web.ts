import { Router, type IRouter } from "express";
import { listAntiFraudCases } from "../services/anti-fraud-case-service";
import {
  getAntiFraudWebSummary,
  listAntiFraudWebAccounts,
  listAntiFraudWebDevices,
  listAntiFraudWebSimilarAccounts,
} from "../services/anti-fraud-web-service";

const router: IRouter = Router();

const errorMessage = (error: unknown): string =>
  error instanceof Error ? error.message : "Не удалось загрузить данные Anti-Fraud.";

// Добавлено 03.09.2026 ИТ Директор Евразии
router.get("/anti-fraud/summary", async (req, res): Promise<void> => {
  try {
    res.json(await getAntiFraudWebSummary());
  } catch (error) {
    req.log.error({ error }, "Failed to load Anti-Fraud summary");
    res.status(503).json({ error: errorMessage(error) });
  }
});

// Добавлено 03.09.2026 ИТ Директор Евразии
router.get("/anti-fraud/cases", async (req, res): Promise<void> => {
  try {
    res.json({ records: await listAntiFraudCases() });
  } catch (error) {
    req.log.error({ error }, "Failed to load Anti-Fraud cases");
    res.status(503).json({ error: errorMessage(error) });
  }
});

// Добавлено 03.09.2026 ИТ Директор Евразии
router.get("/anti-fraud/accounts", async (req, res): Promise<void> => {
  try {
    const records = await listAntiFraudWebAccounts({
      query: String(req.query.q ?? ""),
      level: String(req.query.level ?? ""),
      limit: Number(req.query.limit ?? 200),
    });
    res.json({ records });
  } catch (error) {
    req.log.error({ error }, "Failed to load Anti-Fraud accounts");
    res.status(503).json({ error: errorMessage(error) });
  }
});

// Добавлено 03.09.2026 ИТ Директор Евразии
router.get("/anti-fraud/devices", async (req, res): Promise<void> => {
  try {
    res.json({ records: await listAntiFraudWebDevices(Number(req.query.limit ?? 200)) });
  } catch (error) {
    req.log.error({ error }, "Failed to load Anti-Fraud devices");
    res.status(503).json({ error: errorMessage(error) });
  }
});

// Добавлено 03.09.2026 ИТ Директор Евразии
router.get("/anti-fraud/similar-accounts", async (req, res): Promise<void> => {
  try {
    res.json({ records: await listAntiFraudWebSimilarAccounts(Number(req.query.limit ?? 100)) });
  } catch (error) {
    req.log.error({ error }, "Failed to load Anti-Fraud similar accounts");
    res.status(503).json({ error: errorMessage(error) });
  }
});

export default router;
