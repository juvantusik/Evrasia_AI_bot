import { readFile } from "node:fs/promises";

// Добавлено 03.09.2026 ИТ Директор Евразии
export type BitrixAntiFraudCardRecord = {
  cardNumber: string;
  bitrixUserId: number;
  cardType: number | null;
  cardStatus: string | null;
  cardStatusId: number | null;
  isActive: boolean;
};

// Добавлено 03.09.2026 ИТ Директор Евразии
export type BitrixAntiFraudAmbiguousCard = {
  cardNumber: string;
  bitrixUserIds: number[];
};

// Добавлено 03.09.2026 ИТ Директор Евразии
export type BitrixAntiFraudCardMapResult = {
  requested: number;
  resolved: number;
  records: BitrixAntiFraudCardRecord[];
  unresolved: string[];
  ambiguous: BitrixAntiFraudAmbiguousCard[];
};

type BitrixAntiFraudCardGatewayOptions = {
  apiUrl?: string;
  token?: string;
  tokenFile?: string;
  timeoutMs?: number;
  fetchImpl?: typeof fetch;
};

const DEFAULT_API_URL = "https://evrasia.rest/api/internal/anti-fraud/card-map";
const DEFAULT_TIMEOUT_MS = 15_000;
const MAX_CARDS = 500;

const positiveInteger = (value: number, fallback: number): number =>
  Number.isInteger(value) && value > 0 ? value : fallback;

const normalizeCards = (cards: string[]): string[] => {
  const unique = new Set<string>();

  for (const value of cards) {
    const card = String(value).trim();
    if (!/^\d{4,32}$/.test(card)) {
      throw new Error(`Некорректный номер карты Bitrix Anti-Fraud: ${card}`);
    }
    unique.add(card);
  }

  const result = [...unique];
  if (!result.length || result.length > MAX_CARDS) {
    throw new Error("Bitrix Anti-Fraud card-map принимает от 1 до 500 карт за запрос");
  }
  return result;
};

const parseIntegerOrNull = (value: unknown, field: string): number | null => {
  if (value === null) return null;
  if (!Number.isInteger(value)) {
    throw new Error(`Bitrix Anti-Fraud вернул некорректное поле ${field}`);
  }
  return Number(value);
};

const parsePositiveInteger = (value: unknown, field: string): number => {
  if (!Number.isInteger(value) || Number(value) <= 0) {
    throw new Error(`Bitrix Anti-Fraud вернул некорректное поле ${field}`);
  }
  return Number(value);
};

const parseNonNegativeInteger = (value: unknown, field: string): number => {
  if (!Number.isInteger(value) || Number(value) < 0) {
    throw new Error(`Bitrix Anti-Fraud вернул некорректное поле ${field}`);
  }
  return Number(value);
};

const parseCardNumber = (value: unknown, field: string): string => {
  if (typeof value !== "string" || !/^\d{4,32}$/.test(value)) {
    throw new Error(`Bitrix Anti-Fraud вернул некорректное поле ${field}`);
  }
  return value;
};

const parseResponse = (
  body: string,
  requestedCards: string[],
): BitrixAntiFraudCardMapResult => {
  let payload: unknown;
  try {
    payload = JSON.parse(body);
  } catch {
    throw new Error("Bitrix Anti-Fraud вернул некорректный JSON");
  }

  if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
    throw new Error("Bitrix Anti-Fraud вернул некорректный ответ");
  }

  const data = payload as Record<string, unknown>;
  if (data.ok !== true) {
    throw new Error("Bitrix Anti-Fraud вернул ответ без ok=true");
  }
  if (
    !Array.isArray(data.records) ||
    !Array.isArray(data.unresolved) ||
    !Array.isArray(data.ambiguous)
  ) {
    throw new Error("Bitrix Anti-Fraud вернул неполный card-map ответ");
  }

  const requested = parsePositiveInteger(data.requested, "requested");
  if (requested !== requestedCards.length) {
    throw new Error("Bitrix Anti-Fraud вернул несовпадающее количество requested");
  }

  const allowed = new Set(requestedCards);
  const seen = new Set<string>();

  const records: BitrixAntiFraudCardRecord[] = data.records.map((raw) => {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
      throw new Error("Bitrix Anti-Fraud вернул некорректную запись records");
    }
    const row = raw as Record<string, unknown>;
    const cardNumber = parseCardNumber(row.card_number, "card_number");
    if (!allowed.has(cardNumber) || seen.has(cardNumber)) {
      throw new Error(`Bitrix Anti-Fraud вернул неожиданную или повторную карту ${cardNumber}`);
    }
    seen.add(cardNumber);

    if (row.card_status !== null && typeof row.card_status !== "string") {
      throw new Error("Bitrix Anti-Fraud вернул некорректное поле card_status");
    }
    if (typeof row.is_active !== "boolean") {
      throw new Error("Bitrix Anti-Fraud вернул некорректное поле is_active");
    }

    return {
      cardNumber,
      bitrixUserId: parsePositiveInteger(row.bitrix_user_id, "bitrix_user_id"),
      cardType: parseIntegerOrNull(row.card_type, "card_type"),
      cardStatus: row.card_status as string | null,
      cardStatusId: parseIntegerOrNull(row.card_status_id, "card_status_id"),
      isActive: row.is_active,
    };
  });

  const unresolved = data.unresolved.map((value) => {
    const cardNumber = parseCardNumber(value, "unresolved");
    if (!allowed.has(cardNumber) || seen.has(cardNumber)) {
      throw new Error(`Bitrix Anti-Fraud вернул неожиданную или повторную карту ${cardNumber}`);
    }
    seen.add(cardNumber);
    return cardNumber;
  });

  const ambiguous: BitrixAntiFraudAmbiguousCard[] = data.ambiguous.map((raw) => {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
      throw new Error("Bitrix Anti-Fraud вернул некорректную запись ambiguous");
    }
    const row = raw as Record<string, unknown>;
    const cardNumber = parseCardNumber(row.card_number, "ambiguous.card_number");
    if (!allowed.has(cardNumber) || seen.has(cardNumber)) {
      throw new Error(`Bitrix Anti-Fraud вернул неожиданную или повторную карту ${cardNumber}`);
    }
    if (!Array.isArray(row.bitrix_user_ids) || row.bitrix_user_ids.length < 2) {
      throw new Error("Bitrix Anti-Fraud вернул некорректный ambiguous.bitrix_user_ids");
    }
    const bitrixUserIds = [
      ...new Set(
        row.bitrix_user_ids.map((value) =>
          parsePositiveInteger(value, "ambiguous.bitrix_user_ids"),
        ),
      ),
    ];
    if (bitrixUserIds.length < 2) {
      throw new Error("Bitrix Anti-Fraud вернул неоднозначную карту без нескольких владельцев");
    }
    seen.add(cardNumber);
    return { cardNumber, bitrixUserIds };
  });

  if (seen.size !== requestedCards.length) {
    throw new Error("Bitrix Anti-Fraud card-map не классифицировал все запрошенные карты");
  }

  const resolved = parseNonNegativeInteger(data.resolved, "resolved");
  if (resolved !== records.length) {
    throw new Error("Bitrix Anti-Fraud вернул несовпадающее количество resolved");
  }

  return {
    requested,
    resolved,
    records,
    unresolved,
    ambiguous,
  };
};

// Добавлено 03.09.2026 ИТ Директор Евразии
export class BitrixAntiFraudCardGateway {
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly options: BitrixAntiFraudCardGatewayOptions = {}) {
    this.fetchImpl = options.fetchImpl ?? fetch;
  }

  async resolveCards(cards: string[]): Promise<BitrixAntiFraudCardMapResult> {
    const normalizedCards = normalizeCards(cards);
    const apiUrl = (
      this.options.apiUrl ??
      process.env.BITRIX_ANTI_FRAUD_API_URL ??
      DEFAULT_API_URL
    ).trim();
    let parsedUrl: URL;
    try {
      parsedUrl = new URL(apiUrl);
    } catch {
      throw new Error("BITRIX_ANTI_FRAUD_API_URL содержит некорректный адрес");
    }
    if (parsedUrl.protocol !== "https:") {
      throw new Error("BITRIX_ANTI_FRAUD_API_URL должен использовать HTTPS");
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
        body: JSON.stringify({ cards: normalizedCards }),
        signal: controller.signal,
      });
      const body = await response.text();
      if (!response.ok) {
        throw new Error(`Bitrix Anti-Fraud card-map отклонил запрос: HTTP ${response.status}`);
      }
      return parseResponse(body, normalizedCards);
    } catch (error) {
      if (error instanceof Error && error.name === "AbortError") {
        throw new Error("Превышено время ожидания ответа Bitrix Anti-Fraud card-map");
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
