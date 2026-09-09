import { botSettingsTable, db } from "@workspace/db";
import { eq } from "drizzle-orm";

export const BOT_SETTING_KEYS = {
  megafonGroupChatId: "megafon_group_chat_id",
  antiFraudBonusBalanceThreshold: "anti_fraud_bonus_balance_threshold",
} as const;

export const getBotSetting = async (key: string): Promise<string | null> => {
  const rows = await db
    .select({ value: botSettingsTable.value })
    .from(botSettingsTable)
    .where(eq(botSettingsTable.key, key))
    .limit(1);
  return rows[0]?.value ?? null;
};

export const setBotSetting = async (key: string, value: string): Promise<void> => {
  await db
    .insert(botSettingsTable)
    .values({ key, value })
    .onConflictDoUpdate({
      target: botSettingsTable.key,
      set: { value, updatedAt: new Date() },
    });
};
