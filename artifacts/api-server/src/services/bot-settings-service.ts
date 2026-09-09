import { botSettings, db, eq } from "@workspace/db";

export const BOT_SETTING_KEYS = {
  megafonGroupChatId: "megafon_group_chat_id",
  antiFraudBonusBalanceThreshold: "anti_fraud_bonus_balance_threshold",
} as const;

export const getBotSetting = async (key: string): Promise<string | null> => {
  const rows = await db
    .select({ value: botSettings.settingValue })
    .from(botSettings)
    .where(eq(botSettings.settingKey, key))
    .limit(1);
  return rows[0]?.value ?? null;
};

export const setBotSetting = async (key: string, value: string): Promise<void> => {
  await db
    .insert(botSettings)
    .values({ settingKey: key, settingValue: value })
    .onConflictDoUpdate({
      target: botSettings.settingKey,
      set: { settingValue: value, updatedAt: new Date() },
    });
};
