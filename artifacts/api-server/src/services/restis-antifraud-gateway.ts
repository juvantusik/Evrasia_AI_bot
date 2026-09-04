import { readFile } from "node:fs/promises";

// Добавлено 03.09.2026 ИТ Директор Евразии
export type RestisVisitEvent = {
  restisId: string;
  cardNumber: string;
  visitedAt: Date;
  restaurant: string;
};

// Добавлено 03.09.2026 ИТ Директор Евразии
export type RestisVisitBatch = {
  rawRows: number;
  visits: RestisVisitEvent[];
};

type RestisGatewayOptions = {
  apiUrl?: string;
  username?: string;
  password?: string;
  passwordFile?: string;
  timeoutMs?: number;
  timezoneOffset?: string;
  fetchImpl?: typeof fetch;
};

const DEFAULT_API_URL = "https://api.evrasia.spb.ru/";
const DEFAULT_TIMEOUT_MS = 15_000;
const DEFAULT_TIMEZONE_OFFSET = "+03:00";

const positiveInteger = (value: number, fallback: number): number =>
  Number.isInteger(value) && value > 0 ? value : fallback;

const decodeXml = (value: string): string =>
  value
    .replaceAll("&quot;", '"')
    .replaceAll("&apos;", "'")
    .replaceAll("&lt;", "<")
    .replaceAll("&gt;", ">")
    .replaceAll("&amp;", "&");

const parseAttributes = (value: string): Record<string, string> => {
  const result: Record<string, string> = {};
  const attributePattern = /([A-Za-z0-9_]+)="([^"]*)"/g;
  for (const match of value.matchAll(attributePattern)) {
    result[match[1]] = decodeXml(match[2]);
  }
  return result;
};

const normalizeTimezoneOffset = (value: string): string => {
  const trimmed = value.trim();
  if (!/^[+-](?:0\d|1\d|2[0-3]):[0-5]\d$/.test(trimmed)) {
    throw new Error("RESTIS_TIMEZONE_OFFSET должен иметь формат +03:00");
  }
  return trimmed;
};

const parseRestisDate = (value: string, timezoneOffset: string): Date => {
  const normalized = value.trim();
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(normalized)) {
    throw new Error(`RestIS вернул некорректную дату OPEN_DATE: ${normalized}`);
  }
  const date = new Date(`${normalized}${timezoneOffset}`);
  if (Number.isNaN(date.getTime())) {
    throw new Error(`RestIS вернул некорректную дату OPEN_DATE: ${normalized}`);
  }
  return date;
};

// Добавлено 03.09.2026 ИТ Директор Евразии
export const parseVipTodayXml = (
  xml: string,
  timezoneOffset = DEFAULT_TIMEZONE_OFFSET,
): RestisVisitBatch => {
  const offset = normalizeTimezoneOffset(timezoneOffset);
  const rows = [...xml.matchAll(/<TODAY\b([^>]*)\/?\s*>/g)];
  const unique = new Map<string, RestisVisitEvent>();

  for (const row of rows) {
    const attributes = parseAttributes(row[1] ?? "");
    const restisId = (attributes.ID ?? "").trim();
    const cardNumber = (attributes.CARD_NO ?? "").trim();
    const openDate = (attributes.OPEN_DATE ?? "").trim();
    const restaurant = (attributes.NAME_OBJECT ?? "").trim();

    if (!restisId || !cardNumber || !openDate || !restaurant) {
      throw new Error(
        "RestIS VIP_TODAY вернул строку без обязательных ID/CARD_NO/OPEN_DATE/NAME_OBJECT",
      );
    }

    const event: RestisVisitEvent = {
      restisId,
      cardNumber,
      visitedAt: parseRestisDate(openDate, offset),
      restaurant,
    };

    const previous = unique.get(restisId);
    if (previous) {
      const same =
        previous.cardNumber === event.cardNumber &&
        previous.visitedAt.getTime() === event.visitedAt.getTime() &&
        previous.restaurant === event.restaurant;
      if (!same) {
        throw new Error(`RestIS VIP_TODAY вернул конфликтующие строки для ID=${restisId}`);
      }
      continue;
    }

    unique.set(restisId, event);
  }

  return { rawRows: rows.length, visits: [...unique.values()] };
};

// Добавлено 03.09.2026 ИТ Директор Евразии
export class RestisAntiFraudGateway {
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly options: RestisGatewayOptions = {}) {
    this.fetchImpl = options.fetchImpl ?? fetch;
  }

  async fetchVipToday(pageSize = 1000): Promise<RestisVisitBatch> {
    const apiUrl = (this.options.apiUrl ?? process.env.RESTIS_API_URL ?? DEFAULT_API_URL).trim();
    let parsedUrl: URL;
    try {
      parsedUrl = new URL(apiUrl);
    } catch {
      throw new Error("RESTIS_API_URL содержит некорректный адрес");
    }
    if (parsedUrl.protocol !== "https:") {
      throw new Error("RESTIS_API_URL должен использовать HTTPS");
    }

    const username = (this.options.username ?? process.env.RESTIS_API_USERNAME ?? "").trim();
    if (!username) {
      throw new Error("RESTIS_API_USERNAME не настроен");
    }
    const password = await this.resolvePassword();
    const timeoutMs = positiveInteger(
      Number(this.options.timeoutMs ?? process.env.RESTIS_API_TIMEOUT_MS ?? DEFAULT_TIMEOUT_MS),
      DEFAULT_TIMEOUT_MS,
    );
    const safePageSize = Math.min(10_000, positiveInteger(Number(pageSize), 1000));
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);

    try {
      const response = await this.fetchImpl(parsedUrl, {
        method: "POST",
        headers: {
          Authorization: `Basic ${Buffer.from(`${username}:${password}`, "utf8").toString("base64")}`,
          "Content-Type": "application/xml",
        },
        body: `<VIP_TODAY pagesize="${safePageSize}" />`,
        signal: controller.signal,
      });

      const body = await response.text();
      if (!response.ok) {
        throw new Error(`RestIS VIP_TODAY отклонил запрос: HTTP ${response.status}`);
      }

      const timezoneOffset =
        this.options.timezoneOffset ?? process.env.RESTIS_TIMEZONE_OFFSET ?? DEFAULT_TIMEZONE_OFFSET;
      return parseVipTodayXml(body, timezoneOffset);
    } catch (error) {
      if (error instanceof Error && error.name === "AbortError") {
        throw new Error("Превышено время ожидания ответа RestIS");
      }
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  }

  private async resolvePassword(): Promise<string> {
    const direct = (this.options.password ?? process.env.RESTIS_API_PASSWORD ?? "").trim();
    if (direct) return direct;

    const passwordFile = (
      this.options.passwordFile ?? process.env.RESTIS_API_PASSWORD_FILE ?? ""
    ).trim();
    if (!passwordFile) {
      throw new Error("RESTIS_API_PASSWORD или RESTIS_API_PASSWORD_FILE не настроен");
    }

    let password: string;
    try {
      password = (await readFile(passwordFile, "utf8")).trim();
    } catch {
      throw new Error("Не удалось прочитать файл RESTIS_API_PASSWORD_FILE");
    }
    if (!password) {
      throw new Error("Файл RESTIS_API_PASSWORD_FILE пуст");
    }
    return password;
  }
}
