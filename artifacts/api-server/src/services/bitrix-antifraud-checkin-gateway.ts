import { readFile } from "node:fs/promises";

const DEFAULT_API_URL = "https://evrasia.rest/api/internal/anti-fraud/checkins";
const DEFAULT_TIMEOUT_MS = 30_000;
const DEFAULT_SNAPSHOT_DAYS = 3;
const MAX_SNAPSHOT_DAYS = 3;
const DEFAULT_HISTORY_DAYS = 60;
const MAX_HISTORY_DAYS = 60;
const MAX_RECORDS = 20_000;

export type BitrixAntiFraudCheckinRecord = {
  sourceRestisId: string;
  bitrixUserId: number;
  occurredAt: Date;
  restaurant: string;
};

export type BitrixAntiFraudCheckinResult = {
  days: number;
  from: Date;
  to: Date;
  records: BitrixAntiFraudCheckinRecord[];
  unresolvedCardCount: number;
};

export type BitrixAntiFraudCheckinGatewayOptions = {
  apiUrl?: string;
  token?: string;
  tokenFile?: string;
  timeoutMs?: number;
  fetchImpl?: typeof fetch;
};

const boundedDays = (value: number, fallback: number, maximum: number): number => {
  if (!Number.isInteger(value) || value < 1 || value > maximum) return fallback;
  return value;
};

const parsePositiveInteger = (value: unknown, field: string): number => {
  if (!Number.isInteger(value) || Number(value) <= 0) {
    throw new Error(`Bitrix Anti-Fraud checkins вернул некорректное поле ${field}`);
  }
  return Number(value);
};

const parseNonNegativeInteger = (value: unknown, field: string): number => {
  if (!Number.isInteger(value) || Number(value) < 0) {
    throw new Error(`Bitrix Anti-Fraud checkins вернул некорректное поле ${field}`);
  }
  return Number(value);
};

const parseText = (value: unknown, field: string, maxLength: number): string => {
  if (typeof value !== "string") {
    throw new Error(`Bitrix Anti-Fraud checkins вернул некорректное поле ${field}`);
  }
  const normalized = value.trim();
  if (!normalized || normalized.length > maxLength || /[\u0000-\u001F\u007F]/.test(normalized)) {
    throw new Error(`Bitrix Anti-Fraud checkins вернул некорректное поле ${field}`);
  }
  return normalized;
};

const parseDate = (value: unknown, field: string): Date => {
  if (typeof value !== "string" || value.length > 64) {
    throw new Error(`Bitrix Anti-Fraud checkins вернул некорректное поле ${field}`);
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    throw new Error(`Bitrix Anti-Fraud checkins вернул некорректное поле ${field}`);
  }
  return date;
};

const parseResponse = (
  body: string,
  requestedDays: number,
  allowedUserIds?: Set<number>,
): BitrixAntiFraudCheckinResult => {
  let payload: unknown;
  try {
    payload = JSON.parse(body);
  } catch {
    throw new Error("Bitrix Anti-Fraud checkins вернул некорректный JSON");
  }

  if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
    throw new Error("Bitrix Anti-Fraud checkins вернул некорректный ответ");
  }

  const data = payload as Record<string, unknown>;
  if (data.ok !== true || !Array.isArray(data.records)) {
    throw new Error("Bitrix Anti-Fraud checkins вернул неполный ответ");
  }

  const days = parsePositiveInteger(data.days, "days");
  if (days !== requestedDays) {
    throw new Error("Bitrix Anti-Fraud checkins вернул несовпадающее окно days");
  }

  if (data.records.length > MAX_RECORDS) {
    throw new Error("Bitrix Anti-Fraud checkins превысил безопасный лимит записей");
  }

  const from = parseDate(data.from, "from");
  const to = parseDate(data.to, "to");
  if (from.getTime() > to.getTime()) {
    throw new Error("Bitrix Anti-Fraud checkins вернул инвертированное окно");
  }

  const seen = new Set<string>();
  const records = data.records.map((raw) => {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
      throw new Error("Bitrix Anti-Fraud checkins вернул некорректную запись");
    }
    const row = raw as Record<string, unknown>;
    const sourceRestisId = parseText(row.source_restis_id, "records.source_restis_id", 128);
    if (seen.has(sourceRestisId)) {
      throw new Error("Bitrix Anti-Fraud checkins вернул повторный source_restis_id");
    }
    seen.add(sourceRestisId);

    const bitrixUserId = parsePositiveInteger(row.bitrix_user_id, "records.bitrix_user_id");
    if (allowedUserIds && !allowedUserIds.has(bitrixUserId)) {
      throw new Error("Bitrix Anti-Fraud checkins вернул USER_ID вне адресного запроса");
    }

    return {
      sourceRestisId,
      bitrixUserId,
      occurredAt: parseDate(row.occurred_at, "records.occurred_at"),
      restaurant: parseText(row.restaurant, "records.restaurant", 255),
    };
  });

  return {
    days,
    from,
    to,
    records,
    unresolvedCardCount: parseNonNegativeInteger(
      data.unresolved_card_count ?? 0,
      "unresolved_card_count",
    ),
  };
};

// Добавлено 19.09.2026 ИТ Директор Евразии
// Check-in Scout использует один protected endpoint в двух режимах:
// - без user_ids: полный snapshot максимум за 3 московских дня;
// - с user_ids: адресная физическая история максимум за 60 дней.
// Номер карты, raw RestIS ID и RestIS credentials остаются на evrasia.spb.ru.
export class BitrixAntiFraudCheckinGateway {
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly options: BitrixAntiFraudCheckinGatewayOptions = {}) {
    this.fetchImpl = options.fetchImpl ?? fetch;
  }

  async fetchRecentCheckins(
    days = DEFAULT_SNAPSHOT_DAYS,
  ): Promise<BitrixAntiFraudCheckinResult> {
    const normalizedDays = boundedDays(
      Number(days),
      DEFAULT_SNAPSHOT_DAYS,
      MAX_SNAPSHOT_DAYS,
    );
    return this.request({ days: normalizedDays });
  }

  async fetchUserHistory(
    bitrixUserId: number,
    days = DEFAULT_HISTORY_DAYS,
  ): Promise<BitrixAntiFraudCheckinResult> {
    const userId = Number(bitrixUserId);
    if (!Number.isInteger(userId) || userId <= 0) {
      throw new Error("Bitrix Anti-Fraud checkins history требует положительный USER_ID");
    }
    const normalizedDays = boundedDays(
      Number(days),
      DEFAULT_HISTORY_DAYS,
      MAX_HISTORY_DAYS,
    );
    return this.request({ days: normalizedDays, userIds: [userId] });
  }

  private async request(input: {
    days: number;
    userIds?: number[];
  }): Promise<BitrixAntiFraudCheckinResult> {
    const apiUrl = (
      this.options.apiUrl
      ?? process.env.BITRIX_ANTI_FRAUD_CHECKIN_API_URL
      ?? DEFAULT_API_URL
    ).trim();

    let parsedUrl: URL;
    try {
      parsedUrl = new URL(apiUrl);
    } catch {
      throw new Error("BITRIX_ANTI_FRAUD_CHECKIN_API_URL содержит некорректный адрес");
    }
    if (parsedUrl.protocol !== "https:") {
      throw new Error("BITRIX_ANTI_FRAUD_CHECKIN_API_URL должен использовать HTTPS");
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
      const bodyPayload: Record<string, unknown> = { days: input.days };
      if (input.userIds) bodyPayload.user_ids = input.userIds;

      const response = await this.fetchImpl(parsedUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Anti-Fraud-Token": token,
        },
        body: JSON.stringify(bodyPayload),
        signal: controller.signal,
      });
      const body = await response.text();
      if (!response.ok) {
        throw new Error(`Bitrix Anti-Fraud checkins отклонил запрос: HTTP ${response.status}`);
      }
      return parseResponse(
        body,
        input.days,
        input.userIds ? new Set(input.userIds) : undefined,
      );
    } catch (error) {
      if (error instanceof Error && error.name === "AbortError") {
        throw new Error("Превышено время ожидания ответа Bitrix Anti-Fraud checkins");
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
