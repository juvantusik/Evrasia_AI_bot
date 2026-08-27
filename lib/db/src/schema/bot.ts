import { boolean, pgTable, primaryKey, text, timestamp } from "drizzle-orm/pg-core";

export const botUsersTable = pgTable("bot_users", {
  telegramUserId: text("telegram_user_id").primaryKey(),
  username: text("username"),
  firstName: text("first_name"),
  lastName: text("last_name"),
  isBlocked: boolean("is_blocked").notNull().default(false),
  createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
  updatedAt: timestamp("updated_at", { withTimezone: true })
    .notNull()
    .defaultNow()
    .$onUpdate(() => new Date()),
  lastSeenAt: timestamp("last_seen_at", { withTimezone: true }).notNull().defaultNow(),
});

export const botModuleAccessTable = pgTable(
  "bot_module_access",
  {
    telegramUserId: text("telegram_user_id").notNull(),
    moduleKey: text("module_key").notNull(),
    enabled: boolean("enabled").notNull().default(true),
    grantedBy: text("granted_by"),
    grantedAt: timestamp("granted_at", { withTimezone: true }).notNull().defaultNow(),
    updatedAt: timestamp("updated_at", { withTimezone: true })
      .notNull()
      .defaultNow()
      .$onUpdate(() => new Date()),
  },
  (table) => ({
    pk: primaryKey({ columns: [table.telegramUserId, table.moduleKey] }),
  }),
);

export const botAccessAuditTable = pgTable("bot_access_audit", {
  id: text("id").primaryKey(),
  action: text("action").notNull(),
  adminTelegramUserId: text("admin_telegram_user_id").notNull(),
  targetTelegramUserId: text("target_telegram_user_id").notNull(),
  moduleKey: text("module_key").notNull(),
  createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
});

export const corporatePhoneDirectoryTable = pgTable("corporate_phone_directory", {
  phone: text("phone").primaryKey(),
  operator: text("operator").notNull(),
  legalEntity: text("legal_entity").notNull(),
  inn: text("inn").notNull(),
  accountNumber: text("account_number").notNull(),
  restaurantName: text("restaurant_name"),
  active: boolean("active").notNull().default(true),
  updatedAt: timestamp("updated_at", { withTimezone: true })
    .notNull()
    .defaultNow()
    .$onUpdate(() => new Date()),
});

export type BotUser = typeof botUsersTable.$inferSelect;
export type BotModuleAccess = typeof botModuleAccessTable.$inferSelect;
export type CorporatePhoneDirectoryRecord = typeof corporatePhoneDirectoryTable.$inferSelect;
