import { Router, type IRouter } from "express";
import { pool } from "@workspace/db";
import { listAntiFraudCases } from "../services/anti-fraud-case-service";
import { listAntiFraudCaseDynamics } from "../services/anti-fraud-case-dynamics-service";
import { blockAntiFraudAccounts } from "../services/anti-fraud-block-service";
import { unblockAntiFraudAccounts } from "../services/anti-fraud-unblock-service";
import {
  getAntiFraudSettings,
  setAntiFraudBonusBalanceThreshold,
} from "../services/anti-fraud-settings-service";
import {
  getAntiFraudWebSummary,
  listAntiFraudWebAccounts,
  listAntiFraudWebDevices,
  listAntiFraudWebSimilarAccounts,
} from "../services/anti-fraud-web-service";
import {
  getAntiFraudHourlySchedulerStatus,
  runAntiFraudHourlyCycleOnce,
} from "../services/anti-fraud-hourly-service";
import {
  createAntiFraudOperatorInvestigationForUser,
  listAntiFraudOperatorInvestigations,
  processAntiFraudOperatorInvestigationById,
} from "../services/anti-fraud-operator-investigation-service";
import {
  BitrixAntiFraudPhoneResolverError,
  BitrixAntiFraudPhoneResolverGateway,
} from "../services/bitrix-antifraud-phone-resolver-gateway";

const router: IRouter = Router();

const errorMessage = (error: unknown): string =>
  error instanceof Error ? error.message : "Не удалось загрузить данные Anti-Fraud.";

type ConsentSource = "signup" | "account_gate" | "other";

type ContactTarget = {
  bitrixUserId: number;
  phoneMasked: string | null;
  emailMasked: string | null;
  bitrixActive?: boolean;
  bitrixBlocked?: boolean;
  bitrixBlockReason?: string | null;
  blockedAt?: string | null;
  bonusBalance?: number | null;
  loyaltyActiveCardCount?: number | null;
  loyaltyIssue?: string | null;
  loyaltySyncedAt?: string | null;
  offerAccepted?: boolean | null;
  offerAcceptedAt?: string | null;
  offerSource?: ConsentSource | null;
  pdAccepted?: boolean | null;
  pdAcceptedAt?: string | null;
  pdSource?: ConsentSource | null;
};

type ContactValue = {
  phone: string | null;
  email: string | null;
  bitrixActive: boolean;
  bitrixBlocked: boolean;
  bitrixBlockReason: string | null;
  blockedAt: string | null;
  bonusBalance: number | null;
  loyaltyActiveCardCount: number | null;
  loyaltyIssue: string | null;
  loyaltySyncedAt: string | null;
  offerAccepted: boolean | null;
  offerAcceptedAt: string | null;
  offerSource: ConsentSource | null;
  pdAccepted: boolean | null;
  pdAcceptedAt: string | null;
  pdSource: ConsentSource | null;
};

const displayPhone = (value: unknown): string | null => {
  const digits = String(value ?? "").replace(/\D/g, "");
  return digits.length >= 7 ? `+${digits}` : null;
};

const displayEmail = (value: unknown): string | null => {
  const text = String(value ?? "").trim();
  return text && text.includes("@") ? text : null;
};

const iso = (value: unknown): string | null => {
  if (!value) return null;
  const date = value instanceof Date ? value : new Date(String(value));
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
};

const consentSource = (value: unknown): ConsentSource | null =>
  value === "signup" || value === "account_gate" || value === "other" ? value : null;

const loadContactMap = async (userIds: number[]): Promise<Map<number, ContactValue>> => {
  const ids = [...new Set(userIds.filter((id) => Number.isInteger(id) && id > 0))];
  if (!ids.length) return new Map();

  const result = await pool.query<{
    bitrix_user_id: number;
    phone_normalized: string | null;
    email_normalized: string | null;
    bitrix_active: boolean;
    bitrix_blocked: boolean;
    bitrix_block_reason: string | null;
    blocked_at: Date | null;
    blocked_audit_result: string | null;
    bonus_balance: string | number | null;
    loyalty_active_card_count: number | null;
    loyalty_issue: string | null;
    loyalty_synced_at: Date | null;
    offer_accepted: boolean | null;
    offer_accepted_at: Date | null;
    offer_source: string | null;
    pd_accepted: boolean | null;
    pd_accepted_at: Date | null;
    pd_source: string | null;
  }>(`
    SELECT
      a.bitrix_user_id,
      a.phone_normalized,
      a.email_normalized,
      a.bitrix_active,
      a.bitrix_blocked,
      a.bitrix_block_reason,
      audit.blocked_at,
      audit.blocked_audit_result,
      a.bonus_balance,
      a.loyalty_active_card_count,
      a.loyalty_issue,
      a.loyalty_synced_at,
      a.offer_accepted,
      a.offer_accepted_at,
      a.offer_source,
      a.pd_accepted,
      a.pd_accepted_at,
      a.pd_source
    FROM anti_fraud_accounts a
    LEFT JOIN LATERAL (
      SELECT
        b.created_at AS blocked_at,
        b.result AS blocked_audit_result
      FROM anti_fraud_block_audit b
      WHERE b.bitrix_user_id = a.bitrix_user_id
        AND b.success IS TRUE
        AND b.result IN ('blocked', 'unblocked')
      ORDER BY b.created_at DESC, b.id DESC
      LIMIT 1
    ) audit ON true
    WHERE a.bitrix_user_id = ANY($1::int[])
  `, [ids]);

  return new Map(
    result.rows.map((row) => [
      Number(row.bitrix_user_id),
      {
        phone: displayPhone(row.phone_normalized),
        email: displayEmail(row.email_normalized),
        bitrixActive: row.bitrix_active === true,
        bitrixBlocked: row.bitrix_blocked === true,
        bitrixBlockReason: row.bitrix_block_reason ?? null,
        blockedAt:
          row.bitrix_blocked === true && row.blocked_audit_result === "blocked"
            ? iso(row.blocked_at)
            : null,
        bonusBalance: row.bonus_balance === null ? null : Number(row.bonus_balance),
        loyaltyActiveCardCount:
          row.loyalty_active_card_count === null ? null : Number(row.loyalty_active_card_count),
        loyaltyIssue: row.loyalty_issue ?? null,
        loyaltySyncedAt: iso(row.loyalty_synced_at),
        offerAccepted: row.offer_accepted === null ? null : row.offer_accepted === true,
        offerAcceptedAt: iso(row.offer_accepted_at),
        offerSource: consentSource(row.offer_source),
        pdAccepted: row.pd_accepted === null ? null : row.pd_accepted === true,
        pdAcceptedAt: iso(row.pd_accepted_at),
        pdSource: consentSource(row.pd_source),
      },
    ]),
  );
};

// В Anti-Fraud оператор должен видеть полный телефон/email: они нужны для ручной
// проверки аккаунта в Bitrix и последующей блокировки. Названия contact-полей оставлены
// прежними для обратной совместимости текущего web-клиента.
// Обновлено 12.09.2026: здесь же отдаётся read-only snapshot актуальных Оферты/ПД.
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
      bitrixActive: contact.bitrixActive,
      bitrixBlocked: contact.bitrixBlocked,
      bitrixBlockReason: contact.bitrixBlockReason,
      blockedAt: contact.blockedAt,
      bonusBalance: contact.bonusBalance,
      loyaltyActiveCardCount: contact.loyaltyActiveCardCount,
      loyaltyIssue: contact.loyaltyIssue,
      loyaltySyncedAt: contact.loyaltySyncedAt,
      offerAccepted: contact.offerAccepted,
      offerAcceptedAt: contact.offerAcceptedAt,
      offerSource: contact.offerSource,
      pdAccepted: contact.pdAccepted,
      pdAcceptedAt: contact.pdAcceptedAt,
      pdSource: contact.pdSource,
    };
  });

const groupBonusSummary = (accounts: ContactTarget[]) => {
  const known = accounts
    .map((account) => account.bonusBalance)
    .filter((value): value is number => typeof value === "number" && Number.isFinite(value));

  return {
    groupBonusBalance: known.length ? known.reduce((sum, value) => sum + value, 0) : null,
    groupBonusKnownAccounts: known.length,
    groupBonusTotalAccounts: accounts.length,
  };
};

const parseActionUserIds = (value: unknown): number[] | null => {
  if (!Array.isArray(value) || value.length < 1 || value.length > 50) return null;
  const unique = new Set<number>();
  for (const raw of value) {
    const id = Number(raw);
    if (!Number.isInteger(id) || id <= 0) return null;
    unique.add(id);
  }
  return [...unique];
};

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

// Настройки Anti-Fraud сохраняются в bot_settings и применяются при следующем scoring/refresh.
router.get("/anti-fraud/settings", async (req, res): Promise<void> => {
  try {
    res.json(await getAntiFraudSettings());
  } catch (error) {
    req.log.error({ error }, "Failed to load Anti-Fraud settings");
    res.status(503).json({ error: errorMessage(error) });
  }
});

router.post("/anti-fraud/settings", async (req, res): Promise<void> => {
  try {
    const value = req.body?.bonusBalanceThreshold;
    if (value === undefined || value === null || value === "") {
      res.status(400).json({ error: "Укажите порог бонусного баланса." });
      return;
    }
    res.json(await setAntiFraudBonusBalanceThreshold(value));
  } catch (error) {
    req.log.error({ error }, "Failed to save Anti-Fraud settings");
    const message = errorMessage(error);
    res.status(message.includes("Порог бонусов") ? 400 : 503).json({ error: message });
  }
});

// Обновлено 07.09.2026 ИТ Директор Евразии
router.post("/anti-fraud/refresh", async (req, res): Promise<void> => {
  try {
    const before = getAntiFraudHourlySchedulerStatus();
    if (before.running) {
      res.status(409).json({ error: "Обновление Anti-Fraud уже выполняется.", scheduler: before });
      return;
    }
    const cycle = runAntiFraudHourlyCycleOnce();
    const started = getAntiFraudHourlySchedulerStatus();
    res.status(202).json({ ok: true, accepted: true, scheduler: started });
    void cycle.catch((error) => req.log.error({ error }, "Async Anti-Fraud refresh failed"));
  } catch (error) {
    req.log.error({ error }, "Failed to start Anti-Fraud refresh");
    res.status(503).json({ error: errorMessage(error) });
  }
});

// Step 2: persistent manual investigation. USER_ID is an admin/fallback path;
// normal operator UI will resolve phone through the protected Bitrix resolver first.
router.get("/anti-fraud/investigations", async (req, res): Promise<void> => {
  try {
    res.json({
      records: await listAntiFraudOperatorInvestigations(Number(req.query.limit ?? 100)),
    });
  } catch (error) {
    req.log.error({ error }, "Failed to load Anti-Fraud operator investigations");
    res.status(503).json({ error: errorMessage(error) });
  }
});

router.post("/anti-fraud/investigations", async (req, res): Promise<void> => {
  try {
    const resolved = await new BitrixAntiFraudPhoneResolverGateway().resolvePhone(req.body?.phone);

    const investigation = await createAntiFraudOperatorInvestigationForUser({
      bitrixUserId: resolved.bitrixUserId,
      source: req.body?.source,
      reason: req.body?.reason,
    });

    res.status(202).json({
      ok: true,
      resolved: {
        bitrixUserId: resolved.bitrixUserId,
      },
      investigation,
    });

    void processAntiFraudOperatorInvestigationById(investigation.investigationId).catch((error) =>
      req.log.error(
        { error, investigationId: investigation.investigationId },
        "Async Anti-Fraud phone investigation failed",
      ),
    );
  } catch (error) {
    if (error instanceof BitrixAntiFraudPhoneResolverError) {
      req.log.warn(
        { status: error.httpStatus, matchCount: error.matchCount },
        "Anti-Fraud phone resolver rejected operator request",
      );
      res.status(error.httpStatus).json({
        error: error.message,
        ...(error.httpStatus === 409 && error.matchCount !== null
          ? { matchCount: error.matchCount }
          : {}),
      });
      return;
    }

    const message = errorMessage(error);
    req.log.error({ error }, "Failed to create phone-first Anti-Fraud investigation");
    const badInput =
      message.includes("Источник проверки") ||
      message.includes("Комментарий");
    res.status(badInput ? 400 : 503).json({ error: message });
  }
});

router.post("/anti-fraud/investigations/by-user-id", async (req, res): Promise<void> => {
  try {
    const bitrixUserId = Number(req.body?.userId);
    if (!Number.isInteger(bitrixUserId) || bitrixUserId <= 0) {
      res.status(400).json({ error: "Укажите корректный USER_ID." });
      return;
    }

    const investigation = await createAntiFraudOperatorInvestigationForUser({
      bitrixUserId,
      source: req.body?.source,
      reason: req.body?.reason,
    });

    res.status(202).json({ ok: true, investigation });
    void processAntiFraudOperatorInvestigationById(investigation.investigationId).catch((error) =>
      req.log.error(
        { error, investigationId: investigation.investigationId },
        "Async Anti-Fraud operator investigation failed",
      ),
    );
  } catch (error) {
    const message = errorMessage(error);
    req.log.error({ error }, "Failed to create Anti-Fraud operator investigation");
    const badInput =
      message.includes("USER_ID") ||
      message.includes("Источник проверки") ||
      message.includes("Комментарий");
    res.status(badInput ? 400 : 503).json({ error: message });
  }
});

router.get("/anti-fraud/case-dynamics", async (req, res): Promise<void> => {
  try {
    res.json({ records: await listAntiFraudCaseDynamics() });
  } catch (error) {
    req.log.error({ error }, "Failed to load Anti-Fraud case dynamics");
    res.status(503).json({ error: errorMessage(error) });
  }
});

router.get("/anti-fraud/cases", async (req, res): Promise<void> => {
  try {
    const records = await listAntiFraudCases();
    const userIds = records.flatMap((item) => item.accounts.map((account) => account.bitrixUserId));
    const contacts = await loadContactMap(userIds);
    res.json({
      records: records.map((item) => {
        const accounts = exposeFullContacts(item.accounts, contacts);
        return { ...item, accounts, ...groupBonusSummary(accounts) };
      }),
    });
  } catch (error) {
    req.log.error({ error }, "Failed to load Anti-Fraud cases");
    res.status(503).json({ error: errorMessage(error) });
  }
});

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

// caseId используется только во внутреннем audit и не передаётся в Bitrix/public reason.
router.post("/anti-fraud/block", async (req, res): Promise<void> => {
  try {
    const userIds = parseActionUserIds(req.body?.userIds);
    if (!userIds) {
      res.status(400).json({ error: "Нужно передать от 1 до 50 корректных USER_ID." });
      return;
    }
    res.json(await blockAntiFraudAccounts(userIds, req.body?.caseId));
  } catch (error) {
    req.log.error({ error }, "Failed to block Anti-Fraud accounts");
    res.status(503).json({ error: errorMessage(error) });
  }
});

// Разблокировка индивидуальная/служебная: ACTIVE=Y, BLOCKED=N; основание не стирается.
router.post("/anti-fraud/unblock", async (req, res): Promise<void> => {
  try {
    const userIds = parseActionUserIds(req.body?.userIds);
    if (!userIds) {
      res.status(400).json({ error: "Нужно передать от 1 до 50 корректных USER_ID." });
      return;
    }
    res.json(await unblockAntiFraudAccounts(userIds, req.body?.caseId));
  } catch (error) {
    req.log.error({ error }, "Failed to unblock Anti-Fraud accounts");
    res.status(503).json({ error: errorMessage(error) });
  }
});

router.get("/anti-fraud/devices", async (req, res): Promise<void> => {
  try {
    res.json({ records: await listAntiFraudWebDevices(Number(req.query.limit ?? 200)) });
  } catch (error) {
    req.log.error({ error }, "Failed to load Anti-Fraud devices");
    res.status(503).json({ error: errorMessage(error) });
  }
});

router.get("/anti-fraud/similar-accounts", async (req, res): Promise<void> => {
  try {
    res.json({ records: await listAntiFraudWebSimilarAccounts(Number(req.query.limit ?? 100)) });
  } catch (error) {
    req.log.error({ error }, "Failed to load Anti-Fraud similar accounts");
    res.status(503).json({ error: errorMessage(error) });
  }
});

export default router;
