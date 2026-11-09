import { readFile } from "node:fs/promises";

const DEFAULT_API_URL = "https://evrasia.rest/api/internal/anti-fraud/operation-proximity";
const DEFAULT_TIMEOUT_MS = 15_000;
const SOURCE_EVIDENCE = "positive_bonus_operations_not_receipt_closure";
const MAX_CASES = 200;
const MAX_BODY_LENGTH = 131_072;

export type AntiFraudOperationProximityCase = {
  id: string;
  restaurant1: number;
  bill1: number;
  operation1: string;
  restaurant2: number;
  bill2: number;
  operation2: string;
  gapSeconds: number;
  ownerStatus: "restis_card" | "unresolved" | "ambiguous" | "not_loaded";
  ownerName: string | null;
  ownerPhone: string | null;
  cardLast4: string | null;
};

export type AntiFraudOperationProximitySnapshot = {
  evidence: typeof SOURCE_EVIDENCE;
  generatedAt: string;
  windowFrom: string;
  windowUntil: string;
  sourceOperations: number;
  candidateCards: number;
  candidatePairs: number;
  receiptCloseConfirmed: false;
  riskScoreChanged: false;
  cases: AntiFraudOperationProximityCase[];
};

export type AntiFraudOperationProximityGatewayOptions = {
  apiUrl?: string;
  token?: string;
  tokenFile?: string;
  timeoutMs?: number;
  fetchImpl?: typeof fetch;
};

const invalid = (field: string): never => {
  throw new Error(`Bitrix Anti-Fraud operation-proximity: invalid ${field}`);
};

const object = (value: unknown, name: string): Record<string, unknown> =>
  value && typeof value === "object" && !Array.isArray(value)
    ? value as Record<string, unknown>
    : invalid(name);

const natural = (value: unknown, field: string, minimum = 0): number =>
  Number.isSafeInteger(value) && Number(value) >= minimum
    ? Number(value)
    : invalid(field);

const timestamp = (value: unknown, field: string): string => {
  if (typeof value !== "string" || value.length > 64 ||
      !/^20\d\d-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/.test(value) ||
      Number.isNaN(new Date(value).getTime())) {
    return invalid(field);
  }
  return value;
};

// Strict allowlist: raw CardNumber and unknown source fields never enter bot API/UI.
export const parseAntiFraudOperationProximitySnapshot = (
  payload: unknown,
): AntiFraudOperationProximitySnapshot => {
  const data = object(payload, "response");
  if (data.ok !== true || data.version === 0 || data.evidence !== SOURCE_EVIDENCE ||
      data.receipt_close_confirmed !== false || data.risk_score_changed !== false) {
    return invalid("evidence");
  }
  const generatedAt = timestamp(data.generated_at, "generated_at");
  const windowFrom = timestamp(data.window_from, "window_from");
  const windowUntil = timestamp(data.window_until, "window_until");
  if (new Date(windowUntil).getTime() <= new Date(windowFrom).getTime()) {
    return invalid("window");
  }

  const sourceOperations = natural(data.source_operations, "source_operations");
  const candidateCards = natural(data.candidate_cards, "candidate_cards");
  const candidatePairs = natural(data.candidate_pairs, "candidate_pairs");
  if (!Array.isArray(data.cases) || data.cases.length > MAX_CASES ||
      candidatePairs !== data.cases.length || candidateCards > candidatePairs) {
    return invalid("cases");
  }

  const used = new Set<string>();
  const cases = data.cases.map((raw, index): AntiFraudOperationProximityCase => {
    const item = object(raw, `cases[${index}]`);
    if (typeof item.id !== "string" || !/^OP-\d{3}$/.test(item.id) || used.has(item.id)) {
      return invalid("case_id");
    }
    used.add(item.id);
    const restaurant1 = natural(item.restaurant_1, "restaurant_1", 1);
    const restaurant2 = natural(item.restaurant_2, "restaurant_2", 1);
    const bill1 = natural(item.bill_1, "bill_1", 1);
    const bill2 = natural(item.bill_2, "bill_2", 1);
    const gapSeconds = natural(item.gap_seconds, "gap_seconds");
    const operation1 = timestamp(item.operation_1, "operation_1");
    const operation2 = timestamp(item.operation_2, "operation_2");

    if (restaurant1 === restaurant2 || gapSeconds > 1800 ||
        Math.abs(new Date(operation2).getTime() - new Date(operation1).getTime()) > 1_801_000) {
      return invalid("proximity");
    }

    // The authoritative record is a RESTISBONUS dbo.Cards contact record, not a proven Bitrix identity.
    // Only allowlisted owner display fields enter the bot/UI. Raw CardNumber is never returned.
    const allowedStatus = ["restis_card", "unresolved", "ambiguous"] as const;
    const ownerStatus = item.owner_status === undefined
      ? "not_loaded"
      : allowedStatus.find((status) => status === item.owner_status);
    if (!ownerStatus) return invalid("owner_status");
    const ownerName = item.owner_name === undefined || item.owner_name === null
      ? null : (typeof item.owner_name === "string" && item.owner_name.trim().length > 0 &&
        item.owner_name.trim().length <= 255 && !/[\u0000-\u001f\u007f]/.test(item.owner_name)
        ? item.owner_name.trim() : invalid("owner_name"));
    const ownerPhone = item.owner_phone === undefined || item.owner_phone === null
      ? null : (typeof item.owner_phone === "string" && item.owner_phone.trim().length > 0 &&
        item.owner_phone.trim().length <= 64 && !/[\u0000-\u001f\u007f]/.test(item.owner_phone)
        ? item.owner_phone.trim() : invalid("owner_phone"));
    const cardLast4 = item.card_last4 === undefined || item.card_last4 === null
      ? null : (typeof item.card_last4 === "string" && /^\d{4}$/.test(item.card_last4)
        ? item.card_last4 : invalid("card_last4"));
    if (ownerStatus === "restis_card" && (
      item.owner_source !== "restis_cards" || !ownerName || !ownerPhone || !cardLast4
    )) return invalid("restis_owner_fields");
    if (ownerStatus !== "restis_card" && (ownerName !== null || ownerPhone !== null)) {
      return invalid("unverified_owner");
    }
    return {
      id: item.id, restaurant1, bill1, operation1, restaurant2, bill2, operation2, gapSeconds,
      ownerStatus, ownerName, ownerPhone, cardLast4,
    };
  });

  return {
    evidence: SOURCE_EVIDENCE,
    generatedAt,
    windowFrom,
    windowUntil,
    sourceOperations,
    candidateCards,
    candidatePairs,
    receiptCloseConfirmed: false,
    riskScoreChanged: false,
    cases,
  };
};

// Server-side only. Reuses the existing Bitrix Anti-Fraud service token config;
// the browser receives neither token nor SQL Server credentials.
export class BitrixAntiFraudOperationProximityGateway {
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly options: AntiFraudOperationProximityGatewayOptions = {}) {
    this.fetchImpl = options.fetchImpl ?? fetch;
  }

  async getSnapshot(): Promise<AntiFraudOperationProximitySnapshot> {
    const apiUrl = (
      this.options.apiUrl ??
      process.env.BITRIX_ANTI_FRAUD_OPERATION_PROXIMITY_API_URL ??
      DEFAULT_API_URL
    ).trim();

    let parsedUrl: URL;
    try {
      parsedUrl = new URL(apiUrl);
    } catch {
      throw new Error("Неверный адрес protected Anti-Fraud operation-proximity");
    }
    if (parsedUrl.protocol !== "https:") {
      throw new Error("Protected Anti-Fraud operation-proximity требует HTTPS");
    }

    const token = await this.resolveToken();
    const timeoutCandidate = Number(
      this.options.timeoutMs ?? process.env.BITRIX_ANTI_FRAUD_TIMEOUT_MS ?? DEFAULT_TIMEOUT_MS
    );
    const timeoutMs = Number.isInteger(timeoutCandidate) && timeoutCandidate > 0
      ? timeoutCandidate
      : DEFAULT_TIMEOUT_MS;

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await this.fetchImpl(parsedUrl, {
        method: "GET",
        headers: { "X-Anti-Fraud-Token": token, "Accept": "application/json" },
        signal: controller.signal,
      });
      if (!response.ok) {
        throw new Error(`Protected Anti-Fraud operation-proximity: HTTP ${response.status}`);
      }
      const body = await response.text();
      if (body.length > MAX_BODY_LENGTH) return invalid("response_size");
      let payload: unknown;
      try {
        payload = JSON.parse(body);
      } catch {
        return invalid("response_json");
      }
      return parseAntiFraudOperationProximitySnapshot(payload);
    } catch (error) {
      if (error instanceof Error && error.name === "AbortError") {
        throw new Error("Тайм-аут protected Anti-Fraud operation-proximity");
      }
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  }

  private async resolveToken(): Promise<string> {
    const direct = (this.options.token ?? process.env.BITRIX_ANTI_FRAUD_TOKEN ?? "").trim();
    if (direct) {
      if (direct.length < 32) throw new Error("Неверная конфигурация Anti-Fraud token");
      return direct;
    }
    const file = (
      this.options.tokenFile ?? process.env.BITRIX_ANTI_FRAUD_TOKEN_FILE ?? ""
    ).trim();
    if (!file) {
      throw new Error("Не настроен токен для protected Anti-Fraud");
    }
    let token: string;
    try {
      token = (await readFile(file, "utf8")).trim();
    } catch {
      throw new Error("Недоступен secret file для protected Anti-Fraud");
    }
    if (token.length < 32) throw new Error("Неверный токен для protected Anti-Fraud");
    return token;
  }
}
