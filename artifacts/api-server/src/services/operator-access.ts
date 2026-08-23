import { restaurantDirectory } from "./restaurant-directory";

export const VALERIA_OPERATOR_ID = "valeria-anatolievna";
export const VALERIA_OPERATOR_NAME = "Валерия Анатольевна";
export const NATALIA_OPERATOR_ID = "natalia-andreevna";
export const NATALIA_OPERATOR_NAME = "Наталья Андреевна";
export const EKATERINA_OPERATOR_ID = "ekaterina-vasilievna";
export const EKATERINA_OPERATOR_NAME = "Екатерина Васильевна";

const configuredFullAccessTelegramIds = (): string[] =>
  (process.env.ALLOWED_TELEGRAM_USER_IDS ?? "")
    .split(",")
    .map((id) => id.trim())
    .filter(Boolean);

const configuredValeriaTelegramId = (): string | null => {
  const value = process.env.VALERIA_TELEGRAM_ID?.trim();
  return value || null;
};

const configuredNataliaTelegramId = (): string | null => {
  const value = process.env.NATALIA_TELEGRAM_ID?.trim();
  return value || null;
};

const configuredEkaterinaTelegramId = (): string | null => {
  const value = process.env.EKATERINA_TELEGRAM_ID?.trim();
  return value || null;
};

export const resolveTelegramOperatorId = (telegramUserId: string): string | null => {
  const normalizedTelegramUserId = telegramUserId.trim();
  if (!normalizedTelegramUserId) return null;

  const valeriaTelegramId = configuredValeriaTelegramId();
  if (valeriaTelegramId === normalizedTelegramUserId) {
    return VALERIA_OPERATOR_ID;
  }

  const nataliaTelegramId = configuredNataliaTelegramId();
  if (nataliaTelegramId === normalizedTelegramUserId) {
    return NATALIA_OPERATOR_ID;
  }

  const ekaterinaTelegramId = configuredEkaterinaTelegramId();
  if (ekaterinaTelegramId === normalizedTelegramUserId) {
    return EKATERINA_OPERATOR_ID;
  }

  return configuredFullAccessTelegramIds().includes(normalizedTelegramUserId)
    ? normalizedTelegramUserId
    : null;
};

export const isAllowedOperatorId = (operatorId: string): boolean =>
  configuredFullAccessTelegramIds().includes(operatorId) ||
  (operatorId === VALERIA_OPERATOR_ID && configuredValeriaTelegramId() !== null) ||
  (operatorId === NATALIA_OPERATOR_ID && configuredNataliaTelegramId() !== null) ||
  (operatorId === EKATERINA_OPERATOR_ID && configuredEkaterinaTelegramId() !== null);

export const hasConfiguredTelegramAccess = (): boolean =>
  configuredFullAccessTelegramIds().length > 0 ||
  configuredValeriaTelegramId() !== null ||
  configuredNataliaTelegramId() !== null ||
  configuredEkaterinaTelegramId() !== null;

export type OperatorAccessView = {
  operatorId: string;
  operatorName: string;
  restaurantCount: number;
  configured: boolean;
  accessMode: "full" | "assigned";
};

export const getOperatorAccessOverview = (): OperatorAccessView[] => [
  {
    operatorId: "full-access",
    operatorName: "Приёмочный доступ",
    restaurantCount: restaurantDirectory.length,
    configured: configuredFullAccessTelegramIds().length > 0,
    accessMode: "full",
  },
  {
    operatorId: VALERIA_OPERATOR_ID,
    operatorName: VALERIA_OPERATOR_NAME,
    restaurantCount: restaurantDirectory.filter(
      (restaurant) => restaurant.operatorId === VALERIA_OPERATOR_ID,
    ).length,
    configured: configuredValeriaTelegramId() !== null,
    accessMode: "assigned",
  },
  {
    operatorId: NATALIA_OPERATOR_ID,
    operatorName: NATALIA_OPERATOR_NAME,
    restaurantCount: restaurantDirectory.filter(
      (restaurant) => restaurant.operatorId === NATALIA_OPERATOR_ID,
    ).length,
    configured: configuredNataliaTelegramId() !== null,
    accessMode: "assigned",
  },
  {
    operatorId: EKATERINA_OPERATOR_ID,
    operatorName: EKATERINA_OPERATOR_NAME,
    restaurantCount: restaurantDirectory.filter(
      (restaurant) => restaurant.operatorId === EKATERINA_OPERATOR_ID,
    ).length,
    configured: configuredEkaterinaTelegramId() !== null,
    accessMode: "assigned",
  },
];
