import { readFile } from "node:fs/promises";

const DEFAULT_API_URL = "https://evrasia.rest/api/internal/anti-fraud/account-map";
const DEFAULT_TIMEOUT_MS = 15_000;
const MAX_USERS = 500;

// Добавлено 03.09.2026 ИТ Директор Евразии
export type BitrixAntiFraudAccountRecord = {
  bitrixUserId: number;
  phoneNormalized: string | null;
  emailNormalized: string | null;
  displayName: string | null;
  registeredAt: Date | null;
  bitrixActive: boolean;
  // Добавлено 05.09.2026 ИТ Директор Евразии
  // Поле опционально для обратной совместимости со старой версией account-map.
  bonusBalance: number | null;
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

const parseOptionalNonNegativeInteger = (value: unknown, field: string): number | null => {
  if (value === undefined || value === null || value === "") return null;
  const normalized = typeof value === "string" && /^\d+$/.test(value.trim())
    ? Number(value.trim())
    : value;
  if (!Number.isInteger(normalized) || Number(normalized) < 0) {
    throw new Error(`Bitrix Anti-Fraud account-map вернул некорректное поле ${field}`);
  }
  return Number(normalized);
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

const parseDate = (value: unknown): Date | null => {
  if (value === null) return null;
  if (typeof value !== "string" || value.length > 64) {
    throw new Error("Bitrix Anti-Fraud account-map вернул некорректное поле registered_at");
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    throw new Error("Bitrix Anti-Fraud account-map вернул некорректное поле registered_at");
  }
  return date;
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

    if (typeof row.bitrix_active !== "boolean") {
      throw new Error("Bitrix Anti-Fraud account-map вернул некорректное поле bitrix_active");
    }

    return {
      bitrixUserId,
      phoneNormalized: parsePhone(row.phone_normalized),
      emailNormalized: parseEmail(row.email_normalized),
      displayName: parseNullableText(row.display_name, "display_name", 255),
      registeredAt: parseDate(row.registered_at),
      bitrixActive: row.bitrix_active,
      bonusBalance: parseOptionalNonNegativeInteger(row.bonus_balance, "bonus_balance"),
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
