import { BOT_SETTING_KEYS, getBotSetting } from "./bot-settings-service";

export type AntiFraudOperatorWatchEntry = {
  bitrixUserId: number;
  label: string;
  riskOverride: number | null;
  reason: string | null;
};

const boundedRisk = (value: unknown): number | null => {
  if (value === null || value === undefined || value === "") return null;
  const number = Number(value);
  if (!Number.isFinite(number)) return null;
  return Math.max(0, Math.min(100, Math.round(number)));
};

const cleanLabel = (value: unknown): string => {
  const text = String(value ?? "").trim().slice(0, 64);
  return text || "Наблюдение";
};

const cleanReason = (value: unknown): string | null => {
  const text = String(value ?? "").trim().slice(0, 128);
  return text || null;
};

export const parseAntiFraudOperatorWatchlist = (
  value: string | null,
): AntiFraudOperatorWatchEntry[] => {
  if (!value) return [];

  let rawValues: unknown[] = [];
  try {
    const parsed = JSON.parse(value);
    rawValues = Array.isArray(parsed) ? parsed : [];
  } catch {
    rawValues = value.split(",");
  }

  const byUser = new Map<number, AntiFraudOperatorWatchEntry>();

  for (const raw of rawValues) {
    if (typeof raw === "object" && raw !== null && !Array.isArray(raw)) {
      const object = raw as Record<string, unknown>;
      const bitrixUserId = Number(object.bitrixUserId ?? object.userId ?? object.id);
      if (!Number.isInteger(bitrixUserId) || bitrixUserId <= 0) continue;

      byUser.set(bitrixUserId, {
        bitrixUserId,
        label: cleanLabel(object.label),
        riskOverride: boundedRisk(object.riskOverride ?? object.risk),
        reason: cleanReason(object.reason),
      });
      continue;
    }

    const bitrixUserId = Number(raw);
    if (!Number.isInteger(bitrixUserId) || bitrixUserId <= 0) continue;

    byUser.set(bitrixUserId, {
      bitrixUserId,
      label: "Наблюдение",
      riskOverride: null,
      reason: null,
    });
  }

  return [...byUser.values()].sort((a, b) => a.bitrixUserId - b.bitrixUserId);
};

export const getAntiFraudOperatorWatchlist = async (): Promise<
  AntiFraudOperatorWatchEntry[]
> =>
  parseAntiFraudOperatorWatchlist(
    await getBotSetting(BOT_SETTING_KEYS.antiFraudOperatorWatchlist),
  );
