import { readFile } from "node:fs/promises";

const DEFAULT_API_URL = "https://evrasia.rest/api/internal/anti-fraud/trusted-device-export";
const DEFAULT_TIMEOUT_MS = 15_000;
const DEFAULT_BATCH_SIZE = 1000;
const MAX_BATCH_SIZE = 5000;

// Добавлено 03.09.2026 ИТ Директор Евразии
export type TrustedDeviceLinkRecord = {
  sourceLinkId: string;
  bitrixUserId: number;
  deviceHash: string;
  status: string;
  clientType: string | null;
  createdAt: Date;
  lastSeenAt: Date | null;
  updatedAt: Date | null;
};

// Добавлено 03.09.2026 ИТ Директор Евразии
export type TrustedDeviceEventRecord = {
  sourceEventId: string;
  bitrixUserId: number;
  deviceHash: string;
  eventType: string;
  authMethod: string | null;
  clientType: string | null;
  occurredAt: Date;
};

type Stream = "links" | "events";

type GatewayOptions = {
  apiUrl?: string;
  token?: string;
  tokenFile?: string;
  timeoutMs?: number;
  batchSize?: number;
  fetchImpl?: typeof fetch;
};

type Page<T> = {
  records: T[];
  nextCursor: string | null;
  hasMore: boolean;
};

const positiveInteger = (value: number, fallback: number): number =>
  Number.isInteger(value) && value > 0 ? value : fallback;

const parsePositiveInteger = (value: unknown, field: string): number => {
  if (!Number.isInteger(value) || Number(value) <= 0) {
    throw new Error(`Trusted Device export вернул некорректное поле ${field}`);
  }
  return Number(value);
};

const parseSourceId = (value: unknown, field: string): string => {
  if (typeof value !== "string" || !/^[1-9][0-9]*$/.test(value)) {
    throw new Error(`Trusted Device export вернул некорректное поле ${field}`);
  }
  return value;
};

const parseDeviceHash = (value: unknown): string => {
  if (typeof value !== "string") {
    throw new Error("Trusted Device export вернул некорректный device_hash");
  }
  const normalized = value.trim().toLowerCase();
  if (!/^[a-f0-9]{64}$/.test(normalized)) {
    throw new Error("Trusted Device export вернул некорректный device_hash");
  }
  return normalized;
};

const parseText = (value: unknown, field: string, maxLength: number): string => {
  if (typeof value !== "string") {
    throw new Error(`Trusted Device export вернул некорректное поле ${field}`);
  }
  const normalized = value.trim();
  if (
    !normalized ||
    normalized.length > maxLength ||
    /[\u0000-\u001F\u007F]/.test(normalized)
  ) {
    throw new Error(`Trusted Device export вернул некорректное поле ${field}`);
  }
  return normalized;
};

const parseNullableText = (value: unknown, field: string, maxLength: number): string | null => {
  if (value === null) return null;
  return parseText(value, field, maxLength);
};

const parseDate = (value: unknown, field: string): Date => {
  if (typeof value !== "string" || value.length > 64) {
    throw new Error(`Trusted Device export вернул некорректное поле ${field}`);
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    throw new Error(`Trusted Device export вернул некорректное поле ${field}`);
  }
  return date;
};

const parseNullableDate = (value: unknown, field: string): Date | null => {
  if (value === null) return null;
  return parseDate(value, field);
};

const normalizeCursor = (value: string | null | undefined): string | null => {
  if (value == null || value === "") return null;
  if (!/^[1-9][0-9]*$/.test(value)) {
    throw new Error("Trusted Device cursor должен быть положительным source ID");
  }
  return value;
};

const parsePageEnvelope = (
  body: string,
  expectedStream: Stream,
): { rows: unknown[]; nextCursor: string | null; hasMore: boolean } => {
  let payload: unknown;
  try {
    payload = JSON.parse(body);
  } catch {
    throw new Error("Trusted Device export вернул некорректный JSON");
  }

  if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
    throw new Error("Trusted Device export вернул некорректный ответ");
  }

  const data = payload as Record<string, unknown>;
  if (
    data.ok !== true ||
    data.stream !== expectedStream ||
    !Array.isArray(data.records) ||
    typeof data.has_more !== "boolean"
  ) {
    throw new Error("Trusted Device export вернул неполный ответ");
  }

  let nextCursor: string | null = null;
  if (data.next_cursor !== null) {
    nextCursor = parseSourceId(data.next_cursor, "next_cursor");
  }

  if (data.has_more && nextCursor === null) {
    throw new Error("Trusted Device export не вернул cursor для следующей страницы");
  }

  return {
    rows: data.records,
    nextCursor,
    hasMore: data.has_more,
  };
};

// Добавлено 03.09.2026 ИТ Директор Евразии
// Gateway экспортирует только технические Trusted Device идентификаторы и auth telemetry; PII здесь отсутствует.
export class BitrixAntiFraudDeviceGateway {
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly options: GatewayOptions = {}) {
    this.fetchImpl = options.fetchImpl ?? fetch;
  }

  async fetchLinksPage(afterId?: string | null): Promise<Page<TrustedDeviceLinkRecord>> {
    const envelope = await this.fetchPage("links", afterId);
    return {
      records: envelope.rows.map((raw) => this.parseLink(raw)),
      nextCursor: envelope.nextCursor,
      hasMore: envelope.hasMore,
    };
  }

  async fetchEventsPage(afterId?: string | null): Promise<Page<TrustedDeviceEventRecord>> {
    const envelope = await this.fetchPage("events", afterId);
    return {
      records: envelope.rows.map((raw) => this.parseEvent(raw)),
      nextCursor: envelope.nextCursor,
      hasMore: envelope.hasMore,
    };
  }

  private async fetchPage(stream: Stream, afterId?: string | null) {
    const apiUrl = (
      this.options.apiUrl ??
      process.env.BITRIX_ANTI_FRAUD_DEVICE_API_URL ??
      DEFAULT_API_URL
    ).trim();

    let parsedUrl: URL;
    try {
      parsedUrl = new URL(apiUrl);
    } catch {
      throw new Error("BITRIX_ANTI_FRAUD_DEVICE_API_URL содержит некорректный адрес");
    }
    if (parsedUrl.protocol !== "https:") {
      throw new Error("BITRIX_ANTI_FRAUD_DEVICE_API_URL должен использовать HTTPS");
    }

    const cursor = normalizeCursor(afterId);
    const token = await this.resolveToken();
    const batchSize = Math.min(
      MAX_BATCH_SIZE,
      positiveInteger(
        Number(
          this.options.batchSize ??
            process.env.BITRIX_ANTI_FRAUD_DEVICE_BATCH_SIZE ??
            DEFAULT_BATCH_SIZE,
        ),
        DEFAULT_BATCH_SIZE,
      ),
    );
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
        body: JSON.stringify({
          stream,
          after_id: cursor,
          limit: batchSize,
        }),
        signal: controller.signal,
      });
      const body = await response.text();
      if (!response.ok) {
        throw new Error(`Trusted Device export отклонил запрос: HTTP ${response.status}`);
      }
      return parsePageEnvelope(body, stream);
    } catch (error) {
      if (error instanceof Error && error.name === "AbortError") {
        throw new Error("Превышено время ожидания Trusted Device export");
      }
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  }

  private parseLink(raw: unknown): TrustedDeviceLinkRecord {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
      throw new Error("Trusted Device export вернул некорректную link-запись");
    }
    const row = raw as Record<string, unknown>;
    return {
      sourceLinkId: parseSourceId(row.source_link_id, "source_link_id"),
      bitrixUserId: parsePositiveInteger(row.bitrix_user_id, "bitrix_user_id"),
      deviceHash: parseDeviceHash(row.device_hash),
      status: parseText(row.status, "status", 64),
      clientType: parseNullableText(row.client_type, "client_type", 64),
      createdAt: parseDate(row.created_at, "created_at"),
      lastSeenAt: parseNullableDate(row.last_seen_at, "last_seen_at"),
      updatedAt: parseNullableDate(row.updated_at, "updated_at"),
    };
  }

  private parseEvent(raw: unknown): TrustedDeviceEventRecord {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
      throw new Error("Trusted Device export вернул некорректную event-запись");
    }
    const row = raw as Record<string, unknown>;
    return {
      sourceEventId: parseSourceId(row.source_event_id, "source_event_id"),
      bitrixUserId: parsePositiveInteger(row.bitrix_user_id, "bitrix_user_id"),
      deviceHash: parseDeviceHash(row.device_hash),
      eventType: parseText(row.event_type, "event_type", 128),
      authMethod: parseNullableText(row.auth_method, "auth_method", 128),
      clientType: parseNullableText(row.client_type, "client_type", 64),
      occurredAt: parseDate(row.occurred_at, "occurred_at"),
    };
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
