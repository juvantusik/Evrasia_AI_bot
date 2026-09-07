import { createHash } from "node:crypto";
import { readFile } from "node:fs/promises";

const DEFAULT_API_URL = "https://evrasia.rest/api/internal/anti-fraud/loyalty";
const DEFAULT_TIMEOUT_MS = 30_000;
const MAX_USERS = 50;
const MAX_HISTORY_USERS = 10;
const MAX_HISTORY_DAYS = 60;

// Добавлено 05.09.2026 ИТ Директор Евразии
export type BitrixAntiFraudLoyaltyHistoryEvent = {
  // Внутренний стабильный ключ события. RestIS source ID сам по себе не уникален.
  eventId: string;
  // Исходный RestIS ID сохраняется отдельно и может повторяться.
  restisId: string;
  occurredAt: Date;
  restaurant: string;
  amount: string;
  bonusAdded: string;
  bonusSpent: string;
};

export type BitrixAntiFraudLoyaltyHistorySummary = {
  historyDays: number;
  from: Date;
  to: Date;
  visits: number;
  amount: string;
  bonusAdded: string;
  bonusSpent: string;
};

export type BitrixAntiFraudLoyaltyRecord = {
  bitrixUserId: number;
  activeCardFound: boolean;
  activeCardCount: number;
  cardStatusId: number | null;
  cardStatus: string | null;
  cardType: number | null;
  discountPercent: number | null;
  bonusBalance: string | null;
  totalSpend: string | null;
  todaySum: string | null;
  historySummary: BitrixAntiFraudLoyaltyHistorySummary | null;
  history: BitrixAntiFraudLoyaltyHistoryEvent[] | null;
  issue: string | null;
};

export type BitrixAntiFraudLoyaltyResult = {
  requested: number;
  resolved: number;
  records: BitrixAntiFraudLoyaltyRecord[];
  unresolved: number[];
  includeHistory: boolean;
  historyDays: number | null;
};

export type BitrixAntiFraudLoyaltyGatewayOptions = {
  apiUrl?: string;
  token?: string;
  tokenFile?: string;
  timeoutMs?: number;
  fetchImpl?: typeof fetch;
};

export type ResolveLoyaltyOptions = {
  includeHistory?: boolean;
  historyDays?: number;
};

const parsePositiveInteger = (value: unknown, field: string): number => {
  if (!Number.isInteger(value) || Number(value) <= 0) {
    throw new Error(`Bitrix Anti-Fraud loyalty вернул некорректное поле ${field}`);
  }
  return Number(value);
};

const parseNonNegativeInteger = (value: unknown, field: string): number => {
  if (!Number.isInteger(value) || Number(value) < 0) {
    throw new Error(`Bitrix Anti-Fraud loyalty вернул некорректное поле ${field}`);
  }
  return Number(value);
};

const parseNullableInteger = (value: unknown, field: string): number | null => {
  if (value === null || value === undefined || value === "") return null;
  if (!Number.isInteger(value)) {
    throw new Error(`Bitrix Anti-Fraud loyalty вернул некорректное поле ${field}`);
  }
  return Number(value);
};

const parseNullableNumber = (value: unknown, field: string): number | null => {
  if (value === null || value === undefined || value === "") return null;
  if (typeof value !== "number" || !Number.isFinite(value) || value < 0) {
    throw new Error(`Bitrix Anti-Fraud loyalty вернул некорректное поле ${field}`);
  }
  return value;
};

const parseText = (value: unknown, field: string, maxLength: number): string => {
  if (typeof value !== "string") {
    throw new Error(`Bitrix Anti-Fraud loyalty вернул некорректное поле ${field}`);
  }
  const normalized = value.trim();
  if (!normalized || normalized.length > maxLength || /[\u0000-\u001F\u007F]/.test(normalized)) {
    throw new Error(`Bitrix Anti-Fraud loyalty вернул некорректное поле ${field}`);
  }
  return normalized;
};

const parseNullableText = (value: unknown, field: string, maxLength: number): string | null => {
  if (value === null || value === undefined || value === "") return null;
  return parseText(value, field, maxLength);
};

const parseDate = (value: unknown, field: string): Date => {
  if (typeof value !== "string" || value.length > 64) {
    throw new Error(`Bitrix Anti-Fraud loyalty вернул некорректное поле ${field}`);
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    throw new Error(`Bitrix Anti-Fraud loyalty вернул некорректное поле ${field}`);
  }
  return date;
};

// RestIS денежные значения храним и передаём как decimal string, без binary-float округления.
const parseMoney = (value: unknown, field: string): string => {
  if (typeof value !== "string") {
    throw new Error(`Bitrix Anti-Fraud loyalty вернул некорректное поле ${field}`);
  }
  const normalized = value.trim();
  const match = /^(\d{1,12})(?:\.(\d{1,2}))?$/.exec(normalized);
  if (!match) {
    throw new Error(`Bitrix Anti-Fraud loyalty вернул некорректное поле ${field}`);
  }
  return `${match[1]}.${(match[2] ?? "").padEnd(2, "0")}`;
};

const parseNullableMoney = (value: unknown, field: string): string | null => {
  if (value === null || value === undefined || value === "") return null;
  return parseMoney(value, field);
};

// Реальный protected loyalty endpoint может вернуть отрицательный текущий бонусный остаток.
// Это допустимое состояние баланса, а не ошибка формата. Остальные денежные поля и история
// сохраняют более строгий неотрицательный контракт.
const parseSignedMoney = (value: unknown, field: string): string => {
  if (typeof value !== "string") {
    throw new Error(`Bitrix Anti-Fraud loyalty вернул некорректное поле ${field}`);
  }
  const normalized = value.trim();
  const match = /^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/.exec(normalized);
  if (!match) {
    throw new Error(`Bitrix Anti-Fraud loyalty вернул некорректное поле ${field}`);
  }
  return `${match[1]}${match[2]}.${(match[3] ?? "").padEnd(2, "0")}`;
};

const parseNullableSignedMoney = (value: unknown, field: string): string | null => {
  if (value === null || value === undefined || value === "") return null;
  return parseSignedMoney(value, field);
};

const normalizeUserIds = (userIds: number[], includeHistory: boolean): number[] => {
  const unique = new Set<number>();
  for (const raw of userIds) {
    const value = Number(raw);
    if (!Number.isInteger(value) || value <= 0) {
      throw new Error("Bitrix Anti-Fraud loyalty принимает только положительные USER_ID");
    }
    unique.add(value);
  }

  const result = [...unique];
  const limit = includeHistory ? MAX_HISTORY_USERS : MAX_USERS;
  if (!result.length || result.length > limit) {
    throw new Error(
      includeHistory
        ? "Bitrix Anti-Fraud loyalty принимает от 1 до 10 USER_ID с историей за запрос"
        : "Bitrix Anti-Fraud loyalty принимает от 1 до 50 USER_ID за запрос",
    );
  }
  return result;
};

const normalizeHistoryDays = (value: number): number => {
  if (!Number.isInteger(value) || value < 1 || value > MAX_HISTORY_DAYS) {
    throw new Error("Bitrix Anti-Fraud loyalty history_days должен быть от 1 до 60");
  }
  return value;
};

const parseHistorySummary = (value: unknown): BitrixAntiFraudLoyaltyHistorySummary | null => {
  if (value === null || value === undefined) return null;
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    throw new Error("Bitrix Anti-Fraud loyalty вернул некорректный history_summary");
  }
  const row = value as Record<string, unknown>;
  return {
    historyDays: parsePositiveInteger(row.history_days, "history_summary.history_days"),
    from: parseDate(row.from, "history_summary.from"),
    to: parseDate(row.to, "history_summary.to"),
    visits: parseNonNegativeInteger(row.visits, "history_summary.visits"),
    amount: parseMoney(row.amount, "history_summary.amount"),
    bonusAdded: parseMoney(row.bonus_added, "history_summary.bonus_added"),
    bonusSpent: parseMoney(row.bonus_spent, "history_summary.bonus_spent"),
  };
};

const buildEventId = (
  restisId: string,
  occurredAt: Date,
  restaurant: string,
  amount: string,
  bonusAdded: string,
  bonusSpent: string,
): string => {
  const digest = createHash("sha256")
    .update(
      JSON.stringify([
        restisId,
        occurredAt.toISOString(),
        restaurant,
        amount,
        bonusAdded,
        bonusSpent,
      ]),
      "utf8",
    )
    .digest("hex");
  return `loyalty:${digest}`;
};

const parseHistory = (value: unknown): BitrixAntiFraudLoyaltyHistoryEvent[] | null => {
  if (value === null || value === undefined) return null;
  if (!Array.isArray(value)) {
    throw new Error("Bitrix Anti-Fraud loyalty вернул некорректный history");
  }

  const seenEventIds = new Set<string>();
  return value.map((raw) => {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
      throw new Error("Bitrix Anti-Fraud loyalty вернул некорректную запись history");
    }

    const row = raw as Record<string, unknown>;
    const restisId = parseText(row.restis_id, "history.restis_id", 128);
    const occurredAt = parseDate(row.occurred_at, "history.occurred_at");
    const restaurant = parseText(row.restaurant, "history.restaurant", 255);
    const amount = parseMoney(row.amount, "history.amount");
    const bonusAdded = parseMoney(row.bonus_added, "history.bonus_added");
    const bonusSpent = parseMoney(row.bonus_spent, "history.bonus_spent");
    const eventId = buildEventId(
      restisId,
      occurredAt,
      restaurant,
      amount,
      bonusAdded,
      bonusSpent,
    );

    // Один source restis_id может относиться к нескольким денежным операциям.
    // Запрещаем только полностью идентичную повторную запись, которую невозможно
    // отличить от дубля транспорта/выгрузки.
    if (seenEventIds.has(eventId)) {
      throw new Error("Bitrix Anti-Fraud loyalty вернул повторное идентичное событие history");
    }
    seenEventIds.add(eventId);

    return {
      eventId,
      restisId,
      occurredAt,
      restaurant,
      amount,
      bonusAdded,
      bonusSpent,
    };
  });
};

const parseResponse = (
  body: string,
  requestedUserIds: number[],
  includeHistory: boolean,
  requestedHistoryDays: number,
): BitrixAntiFraudLoyaltyResult => {
  let payload: unknown;
  try {
    payload = JSON.parse(body);
  } catch {
    throw new Error("Bitrix Anti-Fraud loyalty вернул некорректный JSON");
  }

  if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
    throw new Error("Bitrix Anti-Fraud loyalty вернул некорректный ответ");
  }

  const data = payload as Record<string, unknown>;
  if (data.ok !== true || !Array.isArray(data.records) || !Array.isArray(data.unresolved)) {
    throw new Error("Bitrix Anti-Fraud loyalty вернул неполный ответ");
  }
  if (data.include_history !== includeHistory) {
    throw new Error("Bitrix Anti-Fraud loyalty вернул несовпадающий include_history");
  }

  const responseHistoryDays =
    data.history_days === null || data.history_days === undefined
      ? null
      : parsePositiveInteger(data.history_days, "history_days");
  if (includeHistory && responseHistoryDays !== requestedHistoryDays) {
    throw new Error("Bitrix Anti-Fraud loyalty вернул несовпадающий history_days");
  }
  if (!includeHistory && responseHistoryDays !== null) {
    throw new Error("Bitrix Anti-Fraud loyalty неожиданно вернул history_days без истории");
  }

  const requested = parsePositiveInteger(data.requested, "requested");
  if (requested !== requestedUserIds.length) {
    throw new Error("Bitrix Anti-Fraud loyalty вернул несовпадающее количество requested");
  }

  const allowed = new Set(requestedUserIds);
  const seenUsers = new Set<number>();

  const records: BitrixAntiFraudLoyaltyRecord[] = data.records.map((raw) => {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
      throw new Error("Bitrix Anti-Fraud loyalty вернул некорректную запись records");
    }
    const row = raw as Record<string, unknown>;
    if (Object.prototype.hasOwnProperty.call(row, "card_number")) {
      throw new Error("Bitrix Anti-Fraud loyalty не должен раскрывать номер карты");
    }

    const bitrixUserId = parsePositiveInteger(row.bitrix_user_id, "bitrix_user_id");
    if (!allowed.has(bitrixUserId) || seenUsers.has(bitrixUserId)) {
      throw new Error("Bitrix Anti-Fraud loyalty вернул неожиданный или повторный USER_ID");
    }
    seenUsers.add(bitrixUserId);

    if (typeof row.active_card_found !== "boolean") {
      throw new Error("Bitrix Anti-Fraud loyalty вернул некорректное поле active_card_found");
    }
    const activeCardCount = parseNonNegativeInteger(row.active_card_count, "active_card_count");
    if (row.active_card_found !== (activeCardCount === 1)) {
      throw new Error("Bitrix Anti-Fraud loyalty вернул противоречивый статус активной карты");
    }

    const cardStatusId = parseNullableInteger(row.card_status_id, "card_status_id");
    if (row.active_card_found && cardStatusId !== 113) {
      throw new Error("Bitrix Anti-Fraud loyalty вернул активную карту не в RESTIS_STATE=113");
    }

    const historySummary = parseHistorySummary(row.history_summary);
    const history = parseHistory(row.history);
    if (includeHistory && row.active_card_found && (historySummary === null || history === null)) {
      throw new Error("Bitrix Anti-Fraud loyalty не вернул запрошенную историю активной карты");
    }
    if (!includeHistory && (historySummary !== null || history !== null)) {
      throw new Error("Bitrix Anti-Fraud loyalty неожиданно вернул историю");
    }
    if (includeHistory && historySummary && history && historySummary.visits !== history.length) {
      throw new Error("Bitrix Anti-Fraud loyalty вернул несовпадающее число visits и history rows");
    }

    return {
      bitrixUserId,
      activeCardFound: row.active_card_found,
      activeCardCount,
      cardStatusId,
      cardStatus: parseNullableText(row.card_status, "card_status", 64),
      cardType: parseNullableInteger(row.card_type, "card_type"),
      discountPercent: parseNullableNumber(row.discount_percent, "discount_percent"),
      bonusBalance: parseNullableSignedMoney(row.bonus_balance, "bonus_balance"),
      totalSpend: parseNullableMoney(row.total_spend, "total_spend"),
      todaySum: parseNullableMoney(row.today_sum, "today_sum"),
      historySummary,
      history,
      issue: parseNullableText(row.issue, "issue", 128),
    };
  });

  const unresolved = data.unresolved.map((raw) => {
    const bitrixUserId = parsePositiveInteger(raw, "unresolved");
    if (!allowed.has(bitrixUserId) || seenUsers.has(bitrixUserId)) {
      throw new Error("Bitrix Anti-Fraud loyalty вернул неожиданный или повторный USER_ID в unresolved");
    }
    seenUsers.add(bitrixUserId);
    return bitrixUserId;
  });

  if (seenUsers.size !== requestedUserIds.length) {
    throw new Error("Bitrix Anti-Fraud loyalty не классифицировал все запрошенные USER_ID");
  }

  const resolved = parseNonNegativeInteger(data.resolved, "resolved");
  if (resolved !== records.length) {
    throw new Error("Bitrix Anti-Fraud loyalty вернул несовпадающее количество resolved");
  }

  return {
    requested,
    resolved,
    records,
    unresolved,
    includeHistory,
    historyDays: responseHistoryDays,
  };
};

// Добавлено 05.09.2026 ИТ Директор Евразии
// RestIS credentials остаются на evrasia.spb.ru; бот передаёт только внутренний Anti-Fraud service token.
export class BitrixAntiFraudLoyaltyGateway {
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly options: BitrixAntiFraudLoyaltyGatewayOptions = {}) {
    this.fetchImpl = options.fetchImpl ?? fetch;
  }

  async resolveLoyalty(
    userIds: number[],
    options: ResolveLoyaltyOptions = {},
  ): Promise<BitrixAntiFraudLoyaltyResult> {
    const includeHistory = options.includeHistory === true;
    const historyDays = normalizeHistoryDays(Number(options.historyDays ?? MAX_HISTORY_DAYS));
    const normalizedUserIds = normalizeUserIds(userIds, includeHistory);
    const apiUrl = (
      this.options.apiUrl ??
      process.env.BITRIX_ANTI_FRAUD_LOYALTY_API_URL ??
      DEFAULT_API_URL
    ).trim();

    let parsedUrl: URL;
    try {
      parsedUrl = new URL(apiUrl);
    } catch {
      throw new Error("BITRIX_ANTI_FRAUD_LOYALTY_API_URL содержит некорректный адрес");
    }
    if (parsedUrl.protocol !== "https:") {
      throw new Error("BITRIX_ANTI_FRAUD_LOYALTY_API_URL должен использовать HTTPS");
    }

    const token = await this.resolveToken();
    const timeoutCandidate = Number(
      this.options.timeoutMs ?? process.env.BITRIX_ANTI_FRAUD_TIMEOUT_MS ?? DEFAULT_TIMEOUT_MS,
    );
    const timeoutMs =
      Number.isInteger(timeoutCandidate) && timeoutCandidate > 0 ? timeoutCandidate : DEFAULT_TIMEOUT_MS;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);

    try {
      const response = await this.fetchImpl(parsedUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Anti-Fraud-Token": token,
        },
        body: JSON.stringify({
          user_ids: normalizedUserIds,
          include_history: includeHistory,
          history_days: historyDays,
        }),
        signal: controller.signal,
      });
      const body = await response.text();
      if (!response.ok) {
        throw new Error(`Bitrix Anti-Fraud loyalty отклонил запрос: HTTP ${response.status}`);
      }
      return parseResponse(body, normalizedUserIds, includeHistory, historyDays);
    } catch (error) {
      if (error instanceof Error && error.name === "AbortError") {
        throw new Error("Превышено время ожидания ответа Bitrix Anti-Fraud loyalty");
      }
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  }

  private async resolveToken(): Promise<string> {
    const direct = (this.options.token ?? process.env.BITRIX_ANTI_FRAUD_TOKEN ?? "").trim();
    if (direct) return direct;

    const tokenFile = (
      this.options.tokenFile ?? process.env.BITRIX_ANTI_FRAUD_TOKEN_FILE ?? ""
    ).trim();
    if (!tokenFile) {
      throw new Error("BITRIX_ANTI_FRAUD_TOKEN или BITRIX_ANTI_FRAUD_TOKEN_FILE не настроен");
    }

    let token: string;
    try {
      token = (await readFile(tokenFile, "utf8")).trim();
    } catch {
      throw new Error("Не удалось прочитать файл BITRIX_ANTI_FRAUD_TOKEN_FILE");
    }
    if (token.length < 32) {
      throw new Error("Файл BITRIX_ANTI_FRAUD_TOKEN_FILE пуст или содержит слишком короткий токен");
    }
    return token;
  }
}
