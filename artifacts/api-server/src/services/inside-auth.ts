import type { SamzaberuAccessContext } from "./samzaberu-service";

type InsideUser = {
  id: number;
  name: string;
  login: string;
  jobId: number;
  factJobId: number;
  jobName: string;
};

type InsideRestaurant = {
  id: number;
  name: string;
};

export type InsideSamzaberuSession = {
  user: InsideUser;
  accessMode: "full" | "assigned";
  accessContext: SamzaberuAccessContext;
};

type FetchImplementation = typeof fetch;

export class InsideAccessError extends Error {
  constructor(
    message: string,
    readonly statusCode: number,
    readonly code: string,
  ) {
    super(message);
  }
}

const parseIdSet = (name: string): ReadonlySet<number> => {
  const values = (process.env[name] ?? "")
    .split(",")
    .map((value) => value.trim())
    .filter(Boolean);
  const result = new Set<number>();

  for (const value of values) {
    if (!/^\d+$/.test(value)) {
      throw new InsideAccessError(
        `Переменная ${name} содержит некорректный ID`,
        503,
        "inside_access_misconfigured",
      );
    }
    result.add(Number(value));
  }

  return result;
};

const requiredBaseUrl = (): URL => {
  const configured = process.env.INSIDE_API_BASE_URL?.trim();
  if (!configured) {
    throw new InsideAccessError(
      "Адрес API Inside не настроен",
      503,
      "inside_access_misconfigured",
    );
  }

  let url: URL;
  try {
    url = new URL(configured.endsWith("/") ? configured : `${configured}/`);
  } catch {
    throw new InsideAccessError(
      "Адрес API Inside настроен некорректно",
      503,
      "inside_access_misconfigured",
    );
  }

  if (!["http:", "https:"].includes(url.protocol) || url.username || url.password) {
    throw new InsideAccessError(
      "Адрес API Inside должен использовать HTTP(S) и не содержать учётные данные",
      503,
      "inside_access_misconfigured",
    );
  }

  return url;
};

const bearerToken = (authorizationHeader: string | undefined): string => {
  const match = /^Bearer\s+([^\s]+)$/i.exec(authorizationHeader?.trim() ?? "");
  if (!match?.[1] || match[1].length > 8192) {
    throw new InsideAccessError(
      "Требуется авторизация Inside",
      401,
      "inside_token_missing",
    );
  }
  return match[1];
};

const requestJson = async (
  baseUrl: URL,
  path: string,
  token: string,
  fetchImplementation: FetchImplementation,
): Promise<unknown> => {
  const configuredTimeout = Number(process.env.INSIDE_API_TIMEOUT_MS ?? "10000");
  const timeoutMs =
    Number.isFinite(configuredTimeout) && configuredTimeout >= 1000
      ? Math.min(configuredTimeout, 30000)
      : 10000;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetchImplementation(new URL(path, baseUrl), {
      method: "GET",
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${token}`,
      },
      redirect: "error",
      signal: controller.signal,
    });

    if (response.status === 401) {
      throw new InsideAccessError(
        "Сессия Inside недействительна",
        401,
        "inside_token_invalid",
      );
    }
    if (response.status === 403) {
      throw new InsideAccessError(
        "Inside не разрешил эту операцию",
        403,
        "inside_access_forbidden",
      );
    }
    if (!response.ok) {
      throw new InsideAccessError(
        `API Inside ответил с кодом ${response.status}`,
        502,
        "inside_api_unavailable",
      );
    }

    return await response.json();
  } catch (error) {
    if (error instanceof InsideAccessError) throw error;
    throw new InsideAccessError(
      error instanceof Error && error.name === "AbortError"
        ? "API Inside не ответил вовремя"
        : "Не удалось проверить сессию через API Inside",
      502,
      "inside_api_unavailable",
    );
  } finally {
    clearTimeout(timeout);
  }
};

const parseUser = (value: unknown): InsideUser => {
  if (!value || typeof value !== "object") {
    throw new InsideAccessError(
      "API Inside вернул некорректные данные пользователя",
      502,
      "inside_api_invalid_response",
    );
  }
  const raw = value as Record<string, unknown>;
  const id = Number(raw.id);
  const jobId = Number(raw.job_id);
  const factJobId = Number(raw.fact_job_id);

  if (
    !Number.isInteger(id) ||
    id <= 0 ||
    typeof raw.name !== "string" ||
    !raw.name.trim() ||
    typeof raw.login !== "string" ||
    !Number.isInteger(jobId) ||
    !Number.isInteger(factJobId)
  ) {
    throw new InsideAccessError(
      "API Inside вернул неполный профиль пользователя",
      502,
      "inside_api_invalid_response",
    );
  }

  return {
    id,
    name: raw.name.trim(),
    login: raw.login,
    jobId,
    factJobId,
    jobName: typeof raw.job_name === "string" ? raw.job_name : "",
  };
};

const parseRestaurants = (value: unknown): InsideRestaurant[] => {
  if (!Array.isArray(value)) {
    throw new InsideAccessError(
      "API Inside вернул некорректный список ресторанов",
      502,
      "inside_api_invalid_response",
    );
  }

  return value.map((item) => {
    if (!item || typeof item !== "object") {
      throw new InsideAccessError(
        "API Inside вернул некорректный ресторан",
        502,
        "inside_api_invalid_response",
      );
    }
    const raw = item as Record<string, unknown>;
    const id = Number(raw.id);
    if (!Number.isInteger(id) || id <= 0 || typeof raw.name !== "string") {
      throw new InsideAccessError(
        "API Inside вернул неполные данные ресторана",
        502,
        "inside_api_invalid_response",
      );
    }
    return { id, name: raw.name };
  });
};

export const resolveInsideSamzaberuSession = async (
  authorizationHeader: string | undefined,
  fetchImplementation: FetchImplementation = fetch,
): Promise<InsideSamzaberuSession> => {
  const token = bearerToken(authorizationHeader);
  const baseUrl = requiredBaseUrl();
  const user = parseUser(
    await requestJson(baseUrl, "auth", token, fetchImplementation),
  );

  const fullAccessJobIds = parseIdSet("INSIDE_SAMZABERU_FULL_ACCESS_JOB_IDS");
  const assignedAccessJobIds = parseIdSet("INSIDE_SAMZABERU_ASSIGNED_JOB_IDS");
  const fullAccessUserIds = parseIdSet("INSIDE_SAMZABERU_FULL_ACCESS_USER_IDS");
  const assignedAccessUserIds = parseIdSet("INSIDE_SAMZABERU_ASSIGNED_USER_IDS");

  if (
    fullAccessJobIds.size === 0 &&
    assignedAccessJobIds.size === 0 &&
    fullAccessUserIds.size === 0 &&
    assignedAccessUserIds.size === 0
  ) {
    throw new InsideAccessError(
      "Права SamZaberu для Inside не настроены",
      503,
      "inside_access_misconfigured",
    );
  }

  const hasFullAccess =
    fullAccessJobIds.has(user.factJobId) || fullAccessUserIds.has(user.id);
  const hasAssignedAccess =
    assignedAccessJobIds.has(user.factJobId) || assignedAccessUserIds.has(user.id);

  if (!hasFullAccess && !hasAssignedAccess) {
    throw new InsideAccessError(
      "У вашей должности нет доступа к SamZaberu",
      403,
      "inside_access_forbidden",
    );
  }

  const restaurants = hasFullAccess
    ? null
    : parseRestaurants(
        await requestJson(baseUrl, "rests/personal", token, fetchImplementation),
      );
  const allowedRestaurantIds =
    restaurants === null
      ? null
      : new Set(restaurants.map((restaurant) => String(restaurant.id)));

  return {
    user,
    accessMode: hasFullAccess ? "full" : "assigned",
    accessContext: {
      source: "inside",
      operatorId: `inside:${user.id}`,
      operatorName: user.name,
      allowedRestaurantIds,
    },
  };
};
