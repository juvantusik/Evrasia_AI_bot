import { readFile } from "node:fs/promises";

const DEFAULT_API_URL = "https://evrasia.rest/api/internal/anti-fraud/unblock";
const DEFAULT_TIMEOUT_MS = 15_000;
const MAX_USERS = 50;

export type BitrixAntiFraudUnblockState = {
  active: boolean;
  blocked: boolean;
  blockReason: string | null;
};

export type BitrixAntiFraudUnblockRecord = {
  bitrixUserId: number;
  before: BitrixAntiFraudUnblockState;
  after: BitrixAntiFraudUnblockState | null;
  alreadyUnblocked: boolean;
  changed: boolean;
  success: boolean;
  result: "unblocked" | "already_unblocked" | "update_failed" | "verification_failed";
};

export type BitrixAntiFraudUnblockResult = {
  requested: number;
  resolved: number;
  records: BitrixAntiFraudUnblockRecord[];
  unresolved: number[];
};

type Options = {
  apiUrl?: string;
  token?: string;
  tokenFile?: string;
  timeoutMs?: number;
  fetchImpl?: typeof fetch;
};

const parsePositiveInteger = (value: unknown, field: string): number => {
  if (!Number.isInteger(value) || Number(value) <= 0) {
    throw new Error(`Bitrix Anti-Fraud unblock вернул некорректное поле ${field}`);
  }
  return Number(value);
};

const parseNonNegativeInteger = (value: unknown, field: string): number => {
  if (!Number.isInteger(value) || Number(value) < 0) {
    throw new Error(`Bitrix Anti-Fraud unblock вернул некорректное поле ${field}`);
  }
  return Number(value);
};

const parseNullableText = (value: unknown, field: string, maxLength: number): string | null => {
  if (value === null) return null;
  if (typeof value !== "string") {
    throw new Error(`Bitrix Anti-Fraud unblock вернул некорректное поле ${field}`);
  }
  const normalized = value.trim();
  if (!normalized) return null;
  if (normalized.length > maxLength || /[\u0000-\u001F\u007F]/.test(normalized)) {
    throw new Error(`Bitrix Anti-Fraud unblock вернул некорректное поле ${field}`);
  }
  return normalized;
};

const normalizeUserIds = (userIds: number[]): number[] => {
  const unique = new Set<number>();
  for (const raw of userIds) {
    const value = Number(raw);
    if (!Number.isInteger(value) || value <= 0) {
      throw new Error("Bitrix Anti-Fraud unblock принимает только положительные USER_ID");
    }
    unique.add(value);
  }
  const result = [...unique];
  if (!result.length || result.length > MAX_USERS) {
    throw new Error("Bitrix Anti-Fraud unblock принимает от 1 до 50 USER_ID за запрос");
  }
  return result;
};

const parseState = (value: unknown, field: string): BitrixAntiFraudUnblockState => {
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    throw new Error(`Bitrix Anti-Fraud unblock вернул некорректное поле ${field}`);
  }
  const row = value as Record<string, unknown>;
  if (typeof row.active !== "boolean" || typeof row.blocked !== "boolean") {
    throw new Error(`Bitrix Anti-Fraud unblock вернул некорректное поле ${field}`);
  }
  return {
    active: row.active,
    blocked: row.blocked,
    blockReason: parseNullableText(row.block_reason, `${field}.block_reason`, 1000),
  };
};

const parseResponse = (body: string, requestedUserIds: number[]): BitrixAntiFraudUnblockResult => {
  let payload: unknown;
  try {
    payload = JSON.parse(body);
  } catch {
    throw new Error("Bitrix Anti-Fraud unblock вернул некорректный JSON");
  }
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
    throw new Error("Bitrix Anti-Fraud unblock вернул некорректный ответ");
  }

  const data = payload as Record<string, unknown>;
  if (typeof data.ok !== "boolean" || data.dry_run !== false || !Array.isArray(data.records) || !Array.isArray(data.unresolved)) {
    throw new Error("Bitrix Anti-Fraud unblock вернул неполный ответ");
  }

  const requested = parsePositiveInteger(data.requested, "requested");
  const resolved = parseNonNegativeInteger(data.resolved, "resolved");
  if (requested !== requestedUserIds.length) {
    throw new Error("Bitrix Anti-Fraud unblock вернул несовпадающее количество requested");
  }

  const allowed = new Set(requestedUserIds);
  const seen = new Set<number>();
  const allowedResults = new Set(["unblocked", "already_unblocked", "update_failed", "verification_failed"]);

  const records: BitrixAntiFraudUnblockRecord[] = data.records.map((raw) => {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
      throw new Error("Bitrix Anti-Fraud unblock вернул некорректную запись records");
    }
    const row = raw as Record<string, unknown>;
    const bitrixUserId = parsePositiveInteger(row.bitrix_user_id, "bitrix_user_id");
    if (!allowed.has(bitrixUserId) || seen.has(bitrixUserId)) {
      throw new Error("Bitrix Anti-Fraud unblock вернул неожиданный или повторный USER_ID");
    }
    seen.add(bitrixUserId);

    if (
      typeof row.already_unblocked !== "boolean" ||
      typeof row.changed !== "boolean" ||
      typeof row.success !== "boolean" ||
      typeof row.result !== "string" ||
      !allowedResults.has(row.result)
    ) {
      throw new Error("Bitrix Anti-Fraud unblock вернул некорректный результат записи");
    }

    return {
      bitrixUserId,
      before: parseState(row.before, "before"),
      after: row.after === null ? null : parseState(row.after, "after"),
      alreadyUnblocked: row.already_unblocked,
      changed: row.changed,
      success: row.success,
      result: row.result as BitrixAntiFraudUnblockRecord["result"],
    };
  });

  const unresolved = data.unresolved.map((raw) => {
    const bitrixUserId = parsePositiveInteger(raw, "unresolved");
    if (!allowed.has(bitrixUserId) || seen.has(bitrixUserId)) {
      throw new Error("Bitrix Anti-Fraud unblock вернул неожиданный или повторный USER_ID в unresolved");
    }
    seen.add(bitrixUserId);
    return bitrixUserId;
  });

  if (resolved !== records.length || seen.size !== requestedUserIds.length) {
    throw new Error("Bitrix Anti-Fraud unblock не классифицировал все запрошенные USER_ID");
  }

  return { requested, resolved, records, unresolved };
};

export class BitrixAntiFraudUnblockGateway {
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly options: Options = {}) {
    this.fetchImpl = options.fetchImpl ?? fetch;
  }

  async unblockAccounts(userIds: number[]): Promise<BitrixAntiFraudUnblockResult> {
    const normalizedUserIds = normalizeUserIds(userIds);
    const apiUrl = (
      this.options.apiUrl ??
      process.env.BITRIX_ANTI_FRAUD_UNBLOCK_API_URL ??
      DEFAULT_API_URL
    ).trim();

    let parsedUrl: URL;
    try {
      parsedUrl = new URL(apiUrl);
    } catch {
      throw new Error("BITRIX_ANTI_FRAUD_UNBLOCK_API_URL содержит некорректный адрес");
    }
    if (parsedUrl.protocol !== "https:") {
      throw new Error("BITRIX_ANTI_FRAUD_UNBLOCK_API_URL должен использовать HTTPS");
    }

    const token = await this.resolveToken();
    const timeoutMs = Number.isInteger(Number(this.options.timeoutMs)) && Number(this.options.timeoutMs) > 0
      ? Number(this.options.timeoutMs)
      : Number(process.env.BITRIX_ANTI_FRAUD_TIMEOUT_MS ?? DEFAULT_TIMEOUT_MS);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs > 0 ? timeoutMs : DEFAULT_TIMEOUT_MS);

    try {
      const response = await this.fetchImpl(parsedUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Anti-Fraud-Token": token,
        },
        body: JSON.stringify({ user_ids: normalizedUserIds, dry_run: false }),
        signal: controller.signal,
      });
      const body = await response.text();
      if (!response.ok) {
        throw new Error(`Bitrix Anti-Fraud unblock отклонил запрос: HTTP ${response.status}`);
      }
      return parseResponse(body, normalizedUserIds);
    } catch (error) {
      if (error instanceof Error && error.name === "AbortError") {
        throw new Error("Превышено время ожидания ответа Bitrix Anti-Fraud unblock");
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
