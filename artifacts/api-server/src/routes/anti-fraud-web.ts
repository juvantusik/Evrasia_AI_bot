import { Router, type IRouter } from "express";
import { pool } from "@workspace/db";
import { listAntiFraudCases } from "../services/anti-fraud-case-service";
import { listAntiFraudCaseDynamics } from "../services/anti-fraud-case-dynamics-service";
import {
  getAntiFraudWebSummary,
  listAntiFraudWebAccounts,
  listAntiFraudWebDevices,
  listAntiFraudWebSimilarAccounts,
} from "../services/anti-fraud-web-service";
import { getAntiFraudHourlySchedulerStatus } from "../services/anti-fraud-hourly-service";

const router: IRouter = Router();

const errorMessage = (error: unknown): string =>
  error instanceof Error ? error.message : "Не удалось загрузить данные Anti-Fraud.";

type ContactTarget = {
  bitrixUserId: number;
  phoneMasked: string | null;
  emailMasked: string | null;
  bonusBalance?: number | null;
};

type ContactValue = {
  phone: string | null;
  email: string | null;
  bonusBalance: number | null;
};

const displayPhone = (value: unknown): string | null => {
  const digits = String(value ?? "").replace(/\D/g, "");
  return digits.length >= 7 ? `+${digits}` : null;
};

const displayEmail = (value: unknown): string | null => {
  const text = String(value ?? "").trim();
  return text && text.includes("@") ? text : null;
};

const loadContactMap = async (userIds: number[]): Promise<Map<number, ContactValue>> => {
  const ids = [...new Set(userIds.filter((id) => Number.isInteger(id) && id > 0))];
  if (!ids.length) return new Map();

  const result = await pool.query<{
    bitrix_user_id: number;
    phone_normalized: string | null;
    email_normalized: string | null;
    bonus_balance: number | null;
  }>(`
    SELECT bitrix_user_id, phone_normalized, email_normalized, bonus_balance
    FROM anti_fraud_accounts
    WHERE bitrix_user_id = ANY($1::int[])
  `, [ids]);

  return new Map(
    result.rows.map((row) => [
      Number(row.bitrix_user_id),
      {
        phone: displayPhone(row.phone_normalized),
        email: displayEmail(row.email_normalized),
        bonusBalance: row.bonus_balance === null ? null : Number(row.bonus_balance),
      },
    ]),
  );
};

// В Anti-Fraud оператор должен видеть полный телефон/email: они нужны для ручной
// проверки аккаунта в Bitrix и последующей блокировки. Названия contact-полей оставлены
// прежними для обратной совместимости текущего web-клиента.
const exposeFullContacts = <T extends ContactTarget>(
  records: T[],
  contacts: Map<number, ContactValue>,
): T[] =>
  records.map((record) => {
    const contact = contacts.get(record.bitrixUserId);
    if (!contact) return record;
    return {
      ...record,
      phoneMasked: contact.phone,
      emailMasked: contact.email,
      bonusBalance: contact.bonusBalance,
    };
  });

// Добавлено 03.09.2026 ИТ Директор Евразии
router.get("/anti-fraud/summary", async (req, res): Promise<void> => {
  try {
    res.json(await getAntiFraudWebSummary());
  } catch (error) {
    req.log.error({ error }, "Failed to load Anti-Fraud summary");
    res.status(503).json({ error: errorMessage(error) });
  }
});

// Добавлено 05.09.2026 ИТ Директор Евразии
router.get("/anti-fraud/scheduler", async (_req, res): Promise<void> => {
  res.json(getAntiFraudHourlySchedulerStatus());
});

// Добавлено 05.09.2026 ИТ Директор Евразии
router.get("/anti-fraud/case-dynamics", async (req, res): Promise<void> => {
  try {
    res.json({ records: await listAntiFraudCaseDynamics() });
  } catch (error) {
    req.log.error({ error }, "Failed to load Anti-Fraud case dynamics");
    res.status(503).json({ error: errorMessage(error) });
  }
});

// Добавлено 03.09.2026 ИТ Директор Евразии
router.get("/anti-fraud/cases", async (req, res): Promise<void> => {
  try {
    const records = await listAntiFraudCases();
    const userIds = records.flatMap((item) => item.accounts.map((account) => account.bitrixUserId));
    const contacts = await loadContactMap(userIds);
    res.json({
      records: records.map((item) => ({
        ...item,
        accounts: exposeFullContacts(item.accounts, contacts),
      })),
    });
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
    const contacts = await loadContactMap(records.map((record) => record.bitrixUserId));
    res.json({ records: exposeFullContacts(records, contacts) });
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
