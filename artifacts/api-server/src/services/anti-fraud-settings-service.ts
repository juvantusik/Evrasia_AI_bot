import {
  BOT_SETTING_KEYS,
  getBotSetting,
  setBotSetting,
} from "./bot-settings-service";

export const DEFAULT_ANTI_FRAUD_BONUS_BALANCE_THRESHOLD = 40_000;

export type AntiFraudSettings = {
  bonusBalanceThreshold: number;
  defaultBonusBalanceThreshold: number;
};

const normalizeThreshold = (value: unknown): number => {
  const number = Number(value);
  if (!Number.isFinite(number) || number < 0) {
    throw new Error("Порог бонусов должен быть неотрицательным числом.");
  }
  return Math.round(number * 100) / 100;
};

export const getAntiFraudSettings = async (): Promise<AntiFraudSettings> => {
  const stored = await getBotSetting(BOT_SETTING_KEYS.antiFraudBonusBalanceThreshold);
  let bonusBalanceThreshold = DEFAULT_ANTI_FRAUD_BONUS_BALANCE_THRESHOLD;

  if (stored !== null) {
    const parsed = Number(stored);
    if (Number.isFinite(parsed) && parsed >= 0) {
      bonusBalanceThreshold = Math.round(parsed * 100) / 100;
    }
  }

  return {
    bonusBalanceThreshold,
    defaultBonusBalanceThreshold: DEFAULT_ANTI_FRAUD_BONUS_BALANCE_THRESHOLD,
  };
};

export const setAntiFraudBonusBalanceThreshold = async (
  value: unknown,
): Promise<AntiFraudSettings> => {
  const threshold = normalizeThreshold(value);
  await setBotSetting(
    BOT_SETTING_KEYS.antiFraudBonusBalanceThreshold,
    threshold.toFixed(2),
  );
  return {
    bonusBalanceThreshold: threshold,
    defaultBonusBalanceThreshold: DEFAULT_ANTI_FRAUD_BONUS_BALANCE_THRESHOLD,
  };
};
