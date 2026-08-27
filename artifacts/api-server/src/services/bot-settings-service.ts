import { botSettingsTable, db } from "@workspace/db";
import { eq } from "drizzle-orm";

export const BOT_SETTING_KEYS = {
  megafonGroupChatId: "megafon_group_chat_id",
} as const;

export const getBotSetting = async (key: string): Promise<string | null> => {
  const row = await db.query.botSettingsTable.findFirst({
    where: eq(botSettingsTable.key, key),
  });
  return row?.value ?? null;
};

export const setBotSetting = async (key: string, value: string): Promise<void> => {
  const now = new Date();
  await db
    .insert(botSettingsTable)
    .values({ key, value, updatedAt: now })
    .onConflictDoUpdate({
      target: botSettingsTable.key,
      set: { value, updatedAt: now },
    });
};
