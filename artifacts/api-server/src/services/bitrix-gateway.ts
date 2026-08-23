import { randomUUID } from "node:crypto";
import { readFile } from "node:fs/promises";
import type { RestaurantDirectoryEntry } from "./restaurant-directory";

export type BitrixMutationResult = {
  ruleId: string;
};

export interface BitrixGateway {
  readonly serviceLayer: string;
  applyStop(
    restaurant: RestaurantDirectoryEntry,
    until: Date,
    simulateFailure: boolean,
  ): Promise<BitrixMutationResult>;
  applyEnable(
    restaurant: RestaurantDirectoryEntry,
    simulateFailure: boolean,
  ): Promise<void>;
}

type BitrixRuleView = {
  id: number;
  effective: boolean;
  blocks_orders: boolean;
};

type BitrixState = {
  restaurant: {
    id: number;
    name: string;
    active: boolean;
  };
  blocked: boolean;
  personal_rules: BitrixRuleView[];
  group_rules: BitrixRuleView[];
};

type BitrixApiResponse = {
  ok?: boolean;
  error?: string;
  message?: string;
  state?: BitrixState;
  request_id?: string;
};

type BitrixGatewayOptions = {
  apiUrl?: string;
  apiToken?: string;
  apiTokenFile?: string;
  timeoutMs?: number;
  fetchImpl?: typeof fetch;
};

const positiveTimeout = (value: number): number =>
  Number.isFinite(value) && value > 0 ? value : 10_000;

export class BitrixHttpGateway implements BitrixGateway {
  readonly serviceLayer = "BitrixHttpGateway";
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly options: BitrixGatewayOptions = {}) {
    this.fetchImpl = options.fetchImpl ?? fetch;
  }

  async applyStop(
    restaurant: RestaurantDirectoryEntry,
    until: Date,
    simulateFailure: boolean,
  ): Promise<BitrixMutationResult> {
    if (simulateFailure) {
      throw new Error("Имитация сбоя Bitrix API");
    }

    const response = await this.request({
      action: "stop",
      restaurant_id: Number(restaurant.id),
      until: until.toISOString(),
    });
    const personalRule = response.state?.personal_rules.find(
      (rule) => rule.effective && rule.blocks_orders,
    );

    if (!response.state?.blocked || !personalRule) {
      throw new Error("Bitrix API ответил успешно, но персональный запрет не подтверждён");
    }

    return { ruleId: String(personalRule.id) };
  }

  async applyEnable(
    restaurant: RestaurantDirectoryEntry,
    simulateFailure: boolean,
  ): Promise<void> {
    if (simulateFailure) {
      throw new Error("Имитация сбоя Bitrix API");
    }

    const response = await this.request({
      action: "enable",
      restaurant_id: Number(restaurant.id),
    });

    if (response.state?.blocked) {
      throw new Error("Bitrix API ответил успешно, но ресторан остался заблокирован");
    }
  }

  private async request(body: Record<string, unknown>): Promise<BitrixApiResponse> {
    const apiUrl = (this.options.apiUrl ?? process.env.BITRIX_API_URL ?? "").trim();
    if (!apiUrl) {
      throw new Error("BITRIX_API_URL не настроен");
    }

    let parsedUrl: URL;
    try {
      parsedUrl = new URL(apiUrl);
    } catch {
      throw new Error("BITRIX_API_URL содержит некорректный адрес");
    }
    if (parsedUrl.protocol !== "https:") {
      throw new Error("BITRIX_API_URL должен использовать HTTPS");
    }

    const token = await this.resolveToken();
    const configuredTimeout = Number(
      this.options.timeoutMs ?? process.env.BITRIX_API_TIMEOUT_MS ?? 10_000,
    );
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), positiveTimeout(configuredTimeout));

    try {
      const response = await this.fetchImpl(parsedUrl, {
        method: "POST",
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json",
          "X-Request-ID": `bot-${randomUUID()}`,
        },
        body: JSON.stringify(body),
        signal: controller.signal,
      });

      const payload = await this.readResponse(response);
      if (!response.ok || payload.ok !== true) {
        const reason = payload.message ?? payload.error ?? `HTTP ${response.status}`;
        throw new Error(`Bitrix API отклонил запрос: ${reason}`);
      }
      if (!payload.state) {
        throw new Error("Bitrix API не вернул подтверждённое состояние ресторана");
      }
      return payload;
    } catch (error) {
      if (error instanceof Error && error.name === "AbortError") {
        throw new Error("Превышено время ожидания ответа Bitrix API");
      }
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  }

  private async resolveToken(): Promise<string> {
    const directToken = (this.options.apiToken ?? process.env.BITRIX_API_TOKEN ?? "").trim();
    if (directToken) {
      return directToken;
    }

    const tokenFile = (
      this.options.apiTokenFile ??
      process.env.BITRIX_API_TOKEN_FILE ??
      ""
    ).trim();
    if (!tokenFile) {
      throw new Error("BITRIX_API_TOKEN или BITRIX_API_TOKEN_FILE не настроен");
    }

    let token: string;
    try {
      token = (await readFile(tokenFile, "utf8")).trim();
    } catch {
      throw new Error("Не удалось прочитать файл BITRIX_API_TOKEN_FILE");
    }
    if (!token) {
      throw new Error("Файл BITRIX_API_TOKEN_FILE пуст");
    }
    return token;
  }

  private async readResponse(response: Response): Promise<BitrixApiResponse> {
    try {
      return (await response.json()) as BitrixApiResponse;
    } catch {
      throw new Error(`Bitrix API вернул некорректный JSON (HTTP ${response.status})`);
    }
  }
}
