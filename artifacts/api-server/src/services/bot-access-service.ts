import { randomUUID } from "node:crypto";
import {
  botAccessAuditTable,
  botModuleAccessTable,
  botUsersTable,
  db,
  type BotUser,
} from "@workspace/db";
import { desc, eq } from "drizzle-orm";

export const CORPORATE_COMMUNICATIONS_MODULE = "corporate_communications";

export type TelegramUserProfile = {
  id: string;
  username?: string | null;
  firstName?: string | null;
  lastName?: string | null;
};

export type BotUserAccessView = BotUser & {
  corporateCommunications: boolean;
};

const configuredSuperAdminIds = (): string[] =>
  (process.env.SUPER_ADMIN_TELEGRAM_IDS ?? "")
    .split(",")
    .map((value) => value.trim())
    .filter(Boolean);

const bootstrapSuperAdminId = (): string | null =>
  (process.env.ALLOWED_TELEGRAM_USER_IDS ?? "")
    .split(",")
    .map((value) => value.trim())
    .filter(Boolean)[0] ?? null;

export const isSuperAdmin = (telegramUserId: string): boolean => {
  const normalized = telegramUserId.trim();
  const explicitAdmins = configuredSuperAdminIds();
  if (explicitAdmins.length > 0) return explicitAdmins.includes(normalized);
  return bootstrapSuperAdminId() === normalized;
};

export const upsertBotUser = async (profile: TelegramUserProfile): Promise<void> => {
  const now = new Date();
  await db
    .insert(botUsersTable)
    .values({
      telegramUserId: profile.id,
      username: profile.username ?? null,
      firstName: profile.firstName ?? null,
      lastName: profile.lastName ?? null,
      lastSeenAt: now,
      updatedAt: now,
    })
    .onConflictDoUpdate({
      target: botUsersTable.telegramUserId,
      set: {
        username: profile.username ?? null,
        firstName: profile.firstName ?? null,
        lastName: profile.lastName ?? null,
        lastSeenAt: now,
        updatedAt: now,
      },
    });
};

const ensureBotUser = async (telegramUserId: string): Promise<void> => {
  await db
    .insert(botUsersTable)
    .values({ telegramUserId })
    .onConflictDoNothing({ target: botUsersTable.telegramUserId });
};

export const hasModuleAccess = async (
  telegramUserId: string,
  moduleKey: string,
): Promise<boolean> => {
  if (isSuperAdmin(telegramUserId)) return true;

  const user = await db.query.botUsersTable.findFirst({
    where: eq(botUsersTable.telegramUserId, telegramUserId),
  });
  if (user?.isBlocked) return false;

  const access = await db.query.botModuleAccessTable.findFirst({
    where: (table, { and, eq: equals }) =>
      and(equals(table.telegramUserId, telegramUserId), equals(table.moduleKey, moduleKey)),
  });
  return access?.enabled === true;
};

export const setModuleAccess = async (input: {
  adminTelegramUserId: string;
  targetTelegramUserId: string;
  moduleKey: string;
  enabled: boolean;
}): Promise<void> => {
  if (!isSuperAdmin(input.adminTelegramUserId)) {
    throw new Error("Недостаточно прав администратора.");
  }

  await ensureBotUser(input.targetTelegramUserId);
  const now = new Date();
  await db
    .insert(botModuleAccessTable)
    .values({
      telegramUserId: input.targetTelegramUserId,
      moduleKey: input.moduleKey,
      enabled: input.enabled,
      grantedBy: input.adminTelegramUserId,
      grantedAt: now,
      updatedAt: now,
    })
    .onConflictDoUpdate({
      target: [botModuleAccessTable.telegramUserId, botModuleAccessTable.moduleKey],
      set: {
        enabled: input.enabled,
        grantedBy: input.adminTelegramUserId,
        updatedAt: now,
      },
    });

  await db.insert(botAccessAuditTable).values({
    id: randomUUID(),
    action: input.enabled ? "GRANT" : "REVOKE",
    adminTelegramUserId: input.adminTelegramUserId,
    targetTelegramUserId: input.targetTelegramUserId,
    moduleKey: input.moduleKey,
  });
};

export const listBotUsers = async (limit = 30): Promise<BotUserAccessView[]> => {
  const users = await db
    .select()
    .from(botUsersTable)
    .orderBy(desc(botUsersTable.lastSeenAt))
    .limit(limit);

  const accesses = await db
    .select()
    .from(botModuleAccessTable)
    .where(eq(botModuleAccessTable.moduleKey, CORPORATE_COMMUNICATIONS_MODULE));
  const corporateAccess = new Map(
    accesses.map((access) => [access.telegramUserId, access.enabled]),
  );

  return users.map((user) => ({
    ...user,
    corporateCommunications:
      isSuperAdmin(user.telegramUserId) || corporateAccess.get(user.telegramUserId) === true,
  }));
};
