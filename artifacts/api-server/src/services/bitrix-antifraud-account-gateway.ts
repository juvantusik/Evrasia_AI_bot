import { readFile } from "node:fs/promises";

const DEFAULT_API_URL = "https://evrasia.rest/api/internal/anti-fraud/account-map";
const DEFAULT_TIMEOUT_MS = 15_000;
const MAX_USERS = 500;

type ConsentSource = "signup" | "account_gate" | "other";

// Добавлено 03.09.2026 ИТ Директор Евразии
export type BitrixAntiFraudAccountRecord = {
  bitrixUserId: number;
  phoneNormalized: string | null;
  emailNormalized: string | null;
  displayName: string | null;
  registeredAt: Date | null;
  bitrixActive: boolean;
  bitrixBlocked: boolean;
  bitrixBlockReason: string | null;
  offerAccepted: boolean;
  offerAcceptedAt: Date | null;
  offerSource: ConsentSource | null;
  pdAccepted: boolean;
  pdAcceptedAt: Date | null;
  pdSource: ConsentSource | null;
};

// Добавлено 03.09.2026 ИТ Директор Евразии
export type BitrixAntiFraudAccountMapResult = {
  requested: number;
  resolved: number;
  records: BitrixAntiFraudAccountRecord[];
  unresolved: number[];
};

type BitrixAntiFraudAccountGatewayOptions = {
  apiUrl?: string;
  token?: string;
  tokenFile?: string;
  timeoutMs?: number;
  fetchImpl?: typeof fetch;
};

const positiveInteger = (value: number, fallback: number): number =>
  Number.isInteger(value) && value > 0 ? value : fallback;

const parsePositiveInteger = (value: unknown, field: string): number => {
  if (!Number.isInteger(value) || Number(value) <= 0) {
    throw new Error(`Bitrix Anti-Fraud account-map вернул некорректное поле ${field}`);
  }
  return Number(value);
};

const parseNonNegativeInteger = (value: unknown, field: string): number => {
  if (!Number.isInteger(value) || Number(value) < 0) {
    throw new Error(`Bitrix Anti-Fraud account-map вернул некорректное поле ${field}`);
  }
  return Number(value);
};

const normalizeUserIds = (userIds: number[]): number[] => {
  const unique = new Set<number>();
  for (const raw of userIds) {
    const value = Number(raw);
    if (!Number.isInteger(value) || value <= 0) {
      throw new Error("Bitrix Anti-Fraud account-map принимает только положительные USER_ID");
    }
    unique.add(value);
  }

  const result = [...unique];
  if (!result.length || result.length > MAX_USERS) {
    throw new Error("Bitrix Anti-Fraud account-map принимает от 1 до 500 USER_ID за запрос");
  }
  return result;
};

const parseNullableText = (value: unknown, field: string, maxLength: number): string | null => {
  if (value === null) return null;
  if (typeof value !== "string") {
    throw new Error(`Bitrix Anti-Fraud account-map вернул некорректное поле ${field}`);
  }
  const normalized = value.trim();
  if (!normalized) return null;
  if (normalized.length > maxLength || /[\u0000-\u001F\u007F]/.test(normalized)) {
    throw new Error(`Bitrix Anti-Fraud account-map вернул некорректное поле ${field}`);
  }
  return normalized;
};

const parsePhone = (value: unknown): string | null => {
  const phone = parseNullableText(value, "phone_normalized", 20);
  if (phone === null) return null;
  if (!/^\d{7,15}$/.test(phone)) {
    throw new Error("Bitrix Anti-Fraud account-map вернул некорректное поле phone_normalized");
  }
  return phone;
};

const parseEmail = (value: unknown): string | null => {
  const email = parseNullableText(value, "email_normalized", 254);
  if (email === null) return null;
  if (email !== email.toLowerCase() || /\s/.test(email) || !email.includes("@")) {
    throw new Error("Bitrix Anti-Fraud account-map вернул некорректное поле email_normalized");
  }
  return email;
};

const parseNullableDate = (value: unknown, field: string): Date | null => {
  if (value === null) return null;
  if (typeof value !== "string" || value.length > 64) {
    throw new Error(`Bitrix Anti-Fraud account-map вернул некорректное поле ${field}`);
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    throw new Error(`Bitrix Anti-Fraud account-map вернул некорректное поле ${field}`);
  }
  return date;
};

const parseBoolean = (value: unknown, field: string): boolean => {
  if (typeof value !== "boolean") {
    throw new Error(`Bitrix Anti-Fraud account-map вернул некорректное поле ${field}`);
  }
  return value;
};

const parseConsentSource = (value: unknown, field: string): ConsentSource | null => {
  if (value === null) return null;
  if (value === "signup" || value === "account_gate" || value === "other") return value;
  throw new Error(`Bitrix Anti-Fraud account-map вернул некорректное поле ${field}`);
};

const parseResponse = (
  body: string,
  requestedUserIds: number[],
): BitrixAntiFraudAccountMapResult => {
  let payload: unknown;
  try {
    payload = JSON.parse(body);
  } catch {
    throw new Error("Bitrix Anti-Fraud account-map вернул некорректный JSON");
  }

  if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
    throw new Error("Bitrix Anti-Fraud account-map вернул некорректный ответ");
  }

  const data = payload as Record<string, unknown>;
  if (data.ok !== true || !Array.isArray(data.records) || !Array.isArray(data.unresolved)) {
    throw new Error("Bitrix Anti-Fraud account-map вернул неполный ответ");
  }

  const requested = parsePositiveInteger(data.requested, "requested");
  if (requested !== requestedUserIds.length) {
    throw new Error("Bitrix Anti-Fraud account-map вернул несовпадающее количество requested");
  }

  const allowed = new Set(requestedUserIds);
  const seen = new Set<number>();

  const records: BitrixAntiFraudAccountRecord[] = data.records.map((raw) => {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
      throw new Error("Bitrix Anti-Fraud account-map вернул некорректную запись records");
    }

    const row = raw as Record<string, unknown>;
    const bitrixUserId = parsePositiveInteger(row.bitrix_user_id, "bitrix_user_id");
    if (!allowed.has(bitrixUserId) || seen.has(bitrixUserId)) {
      throw new Error("Bitrix Anti-Fraud account-map вернул неожиданный или повторный USER_ID");
    }
    seen.add(bitrixUserId);

    return {
      bitrixUserId,
      phoneNormalized: parsePhone(row.phone_normalized),
      emailNormalized: parseEmail(row.email_normalized),
      displayName: parseNullableText(row.display_name, "display_name", 255),
      registeredAt: parseNullableDate(row.registered_at, "registered_at"),
      bitrixActive: parseBoolean(row.bitrix_active, "bitrix_active"),
      bitrixBlocked: parseBoolean(row.bitrix_blocked, "bitrix_blocked"),
      bitrixBlockReason: parseNullableText(row.block_reason, "block_reason", 1000),
      offerAccepted: parseBoolean(row.offer_accepted, "offer_accepted"),
      offerAcceptedAt: parseNullableDate(row.offer_accepted_at, "offer_accepted_at"),
      offerSource: parseConsentSource(row.offer_source, "offer_source"),
      pdAccepted: parseBoolean(row.pd_accepted, "pd_accepted"),
      pdAcceptedAt: parseNullableDate(row.pd_accepted_at, "pd_accepted_at"),
      pdSource: parseConsentSource(row.pd_source, "pd_source"),
    };
  });

  const unresolved = data.unresolved.map((raw) => {
    const bitrixUserId = parsePositiveInteger(raw, "unresolved");
    if (!allowed.has(bitrixUserId) || seen.has(bitrixUserId)) {
      throw new Error("Bitrix Anti-Fraud account-map вернул неожиданный или повторный USER_ID в unresolved");
    }
    seen.add(bitrixUserId);
    return bitrixUserId;
  });

  if (seen.size !== requestedUserIds.length) {
    throw new Error("Bitrix Anti-Fraud account-map не классифицировал все запрошенные USER_ID");
  }

  const resolved = parseNonNegativeInteger(data.resolved, "resolved");
  if (resolved !== records.length) {
    throw new Error("Bitrix Anti-Fraud account-map вернул несовпадающее количество resolved");
  }

  return { requested, resolved, records, unresolved };
};

// Добавлено 03.09.2026 ИТ Директор Евразии
// Gateway принимает только адресный список USER_ID, не умеет выгружать всю пользовательскую базу Bitrix.
// Loyalty balance намеренно вынесен в отдельный защищённый /anti-fraud/loyalty endpoint.
// Обновлено 12.09.2026: account-map также возвращает read-only snapshot актуальных Оферты/ПД.
export class BitrixAntiFraudAccountGateway {
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly options: BitrixAntiFraudAccountGatewayOptions = {}) {
    this.fetchImpl = options.fetchImpl ?? fetch;
  }

  async resolveAccounts(userIds: number[]): Promise<BitrixAntiFraudAccountMapResult> {
    const normalizedUserIds = normalizeUserIds(userIds);
    const apiUrl = (
      this.options.apiUrl ??
      process.env.BITRIX_ANTI_FRAUD_ACCOUNT_API_URL ??
      DEFAULT_API_URL
    ).trim();

    let parsedUrl: URL;
    try {
      parsedUrl = new URL(apiUrl);
    } catch {
      throw new Error("BITRIX_ANTI_FRAUD_ACCOUNT_API_URL содержит некорректный адрес");
    }
    if (parsedUrl.protocol !== "https:") {
      throw new Error("BITRIX_ANTI_FRAUD_ACCOUNT_API_URL должен использовать HTTPS");
    }

    const token = await this.resolveToken();
    const timeoutMs = positiveInteger(
      Number(
        this.options.timeoutMs ??
          process.env.BITRIX_ANTI_FRAUD_TIMEOUT_MS ??
          DEFAULT_TIMEOUT_MS,
      ),
      DEFAULT_TIMEOUT_MS,
    );
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);

    try {
      const response = await this.fetchImpl(parsedUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Anti-Fraud-Token": token,
        },
        body: JSON.stringify({ user_ids: normalizedUserIds }),
        signal: controller.signal,
      });
      const body = await response.text();
      if (!response.ok) {
        throw new Error(`Bitrix Anti-Fraud account-map отклонил запрос: HTTP ${response.status}`);
      }
      return parseResponse(body, normalizedUserIds);
    } catch (error) {
      if (error instanceof Error && error.name === "AbortError") {
        throw new Error("Превышено время ожидания ответа Bitrix Anti-Fraud account-map");
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
      this.options.tokenFile ??
      process.env.BITRIX_ANTI_FRAUD_TOKEN_FILE ??
      ""
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
