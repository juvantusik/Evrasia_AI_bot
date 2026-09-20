import { readFile } from "node:fs/promises";

const DEFAULT_API_URL = "https://evrasia.rest/api/internal/anti-fraud/phone-resolve";
const DEFAULT_TIMEOUT_MS = 30_000;

export type BitrixAntiFraudPhoneResolverResult = {
  bitrixUserId: number;
};

export type BitrixAntiFraudPhoneResolverGatewayOptions = {
  apiUrl?: string;
  token?: string;
  tokenFile?: string;
  timeoutMs?: number;
  fetchImpl?: typeof fetch;
};

export class BitrixAntiFraudPhoneResolverError extends Error {
  readonly httpStatus: 400 | 404 | 409 | 503;
  readonly matchCount: number | null;

  constructor(
    message: string,
    httpStatus: 400 | 404 | 409 | 503,
    matchCount: number | null = null,
  ) {
    super(message);
    this.name = "BitrixAntiFraudPhoneResolverError";
    this.httpStatus = httpStatus;
    this.matchCount = matchCount;
  }
}

const normalizePhoneInput = (value: unknown): string => {
  if (typeof value !== "string") {
    throw new BitrixAntiFraudPhoneResolverError("Укажите корректный номер телефона.", 400);
  }
  const phone = value.trim();
  if (!phone || phone.length > 64 || /[\u0000-\u001F\u007F]/.test(phone)) {
    throw new BitrixAntiFraudPhoneResolverError("Укажите корректный номер телефона.", 400);
  }
  const digits = phone.replace(/\D/g, "");
  if (digits.length < 7 || digits.length > 15) {
    throw new BitrixAntiFraudPhoneResolverError("Укажите корректный номер телефона.", 400);
  }
  return phone;
};

const parseJsonObject = (body: string): Record<string, unknown> => {
  let payload: unknown;
  try {
    payload = JSON.parse(body);
  } catch {
    throw new BitrixAntiFraudPhoneResolverError(
      "Сервис определения аккаунта временно недоступен.",
      503,
    );
  }
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
    throw new BitrixAntiFraudPhoneResolverError(
      "Сервис определения аккаунта временно недоступен.",
      503,
    );
  }
  return payload as Record<string, unknown>;
};

const parseSuccess = (body: string): BitrixAntiFraudPhoneResolverResult => {
  const data = parseJsonObject(body);
  if (data.ok !== true || data.status !== "unique") {
    throw new BitrixAntiFraudPhoneResolverError(
      "Сервис определения аккаунта вернул некорректный ответ.",
      503,
    );
  }
  if (!Number.isInteger(data.bitrix_user_id) || Number(data.bitrix_user_id) <= 0) {
    throw new BitrixAntiFraudPhoneResolverError(
      "Сервис определения аккаунта вернул некорректный ответ.",
      503,
    );
  }
  return { bitrixUserId: Number(data.bitrix_user_id) };
};

const parseAmbiguousMatchCount = (body: string): number => {
  const data = parseJsonObject(body);
  const matchCount = Number(data.match_count);
  if (data.status !== "ambiguous" || !Number.isInteger(matchCount) || matchCount < 2) {
    throw new BitrixAntiFraudPhoneResolverError(
      "Сервис определения аккаунта вернул некорректный неоднозначный ответ.",
      503,
    );
  }
  return matchCount;
};

// Добавлено 20.09.2026 ИТ Директор Евразии
// Точный production contract подтверждён read-only по
// AntiFraudPhoneResolverService.php:
// request JSON field = phone;
// unique = 200 + status=unique + bitrix_user_id (ACTIVE is re-read via account-map);
// invalid=400, not_found=404, ambiguous=409+match_count, resolver error=503.
// Protected HTTP body и token никогда не пробрасываются в web error.
export class BitrixAntiFraudPhoneResolverGateway {
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly options: BitrixAntiFraudPhoneResolverGatewayOptions = {}) {
    this.fetchImpl = options.fetchImpl ?? fetch;
  }

  async resolvePhone(phoneValue: unknown): Promise<BitrixAntiFraudPhoneResolverResult> {
    const phone = normalizePhoneInput(phoneValue);
    const apiUrl = (
      this.options.apiUrl
      ?? process.env.BITRIX_ANTI_FRAUD_PHONE_RESOLVER_API_URL
      ?? DEFAULT_API_URL
    ).trim();

    let parsedUrl: URL;
    try {
      parsedUrl = new URL(apiUrl);
    } catch {
      throw new BitrixAntiFraudPhoneResolverError(
        "BITRIX_ANTI_FRAUD_PHONE_RESOLVER_API_URL содержит некорректный адрес.",
        503,
      );
    }
    if (parsedUrl.protocol !== "https:") {
      throw new BitrixAntiFraudPhoneResolverError(
        "BITRIX_ANTI_FRAUD_PHONE_RESOLVER_API_URL должен использовать HTTPS.",
        503,
      );
    }

    const token = await this.resolveToken();
    const timeoutCandidate = Number(
      this.options.timeoutMs
      ?? process.env.BITRIX_ANTI_FRAUD_TIMEOUT_MS
      ?? DEFAULT_TIMEOUT_MS,
    );
    const timeoutMs =
      Number.isInteger(timeoutCandidate) && timeoutCandidate > 0
        ? timeoutCandidate
        : DEFAULT_TIMEOUT_MS;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);

    try {
      const response = await this.fetchImpl(parsedUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Anti-Fraud-Token": token,
        },
        body: JSON.stringify({ phone }),
        signal: controller.signal,
      });
      const body = await response.text();

      if (response.status === 200) return parseSuccess(body);

      if (response.status === 400) {
        throw new BitrixAntiFraudPhoneResolverError("Укажите корректный номер телефона.", 400);
      }
      if (response.status === 404) {
        throw new BitrixAntiFraudPhoneResolverError(
          "По этому номеру аккаунт Bitrix не найден.",
          404,
        );
      }
      if (response.status === 409) {
        const matchCount = parseAmbiguousMatchCount(body);
        throw new BitrixAntiFraudPhoneResolverError(
          "По этому номеру найдено несколько аккаунтов. Проверка не создана.",
          409,
          matchCount,
        );
      }

      // 401 protected auth и любые неожиданные HTTP статусы — внутренняя проблема интеграции.
      throw new BitrixAntiFraudPhoneResolverError(
        "Сервис определения аккаунта временно недоступен.",
        503,
      );
    } catch (error) {
      if (error instanceof BitrixAntiFraudPhoneResolverError) throw error;
      if (error instanceof Error && error.name === "AbortError") {
        throw new BitrixAntiFraudPhoneResolverError(
          "Превышено время ожидания сервиса определения аккаунта.",
          503,
        );
      }
      throw new BitrixAntiFraudPhoneResolverError(
        "Сервис определения аккаунта временно недоступен.",
        503,
      );
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
      throw new BitrixAntiFraudPhoneResolverError(
        "Protected Anti-Fraud token не настроен.",
        503,
      );
    }

    let token: string;
    try {
      token = (await readFile(tokenFile, "utf8")).trim();
    } catch {
      throw new BitrixAntiFraudPhoneResolverError(
        "Не удалось прочитать protected Anti-Fraud token.",
        503,
      );
    }
    if (token.length < 32) {
      throw new BitrixAntiFraudPhoneResolverError(
        "Protected Anti-Fraud token имеет некорректный формат.",
        503,
      );
    }
    return token;
  }
}
