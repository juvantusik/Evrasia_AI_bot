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

export const botSettingsTable = pgTable("bot_settings", {
  key: text("key").primaryKey(),
  value: text("value").notNull(),
  updatedAt: timestamp("updated_at", { withTimezone: true })
    .notNull()
    .defaultNow()
    .$onUpdate(() => new Date()),
});

export const corporatePhoneDirectoryTable = pgTable("corporate_phone_directory", {
  id: text("id").primaryKey(),
  phone: text("phone").notNull(),
  operator: text("operator").notNull(),
  legalEntity: text("legal_entity").notNull(),
  inn: text("inn"),
  accountNumber: text("account_number"),
  restaurantName: text("restaurant_name"),
  lineType: text("line_type"),
  subscriberName: text("subscriber_name"),
  active: boolean("active").notNull().default(true),
  createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
  updatedAt: timestamp("updated_at", { withTimezone: true })
    .notNull()
    .defaultNow()
    .$onUpdate(() => new Date()),
});

export const corporateLegalEntitiesTable = pgTable("corporate_legal_entities", {
  id: text("id").primaryKey(),
  name: text("name").notNull(),
  inn: text("inn"),
  active: boolean("active").notNull().default(true),
  createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
  updatedAt: timestamp("updated_at", { withTimezone: true })
    .notNull()
    .defaultNow()
    .$onUpdate(() => new Date()),
});

export const corporatePhoneAuditTable = pgTable("corporate_phone_audit", {
  id: text("id").primaryKey(),
  action: text("action").notNull(),
  adminTelegramUserId: text("admin_telegram_user_id").notNull(),
  phoneRecordId: text("phone_record_id"),
  phone: text("phone").notNull(),
  beforeState: text("before_state"),
  afterState: text("after_state"),
  createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
});

export type BotUser = typeof botUsersTable.$inferSelect;
export type BotModuleAccess = typeof botModuleAccessTable.$inferSelect;
export type BotSetting = typeof botSettingsTable.$inferSelect;
export type CorporatePhoneDirectoryRecord = typeof corporatePhoneDirectoryTable.$inferSelect;
export type CorporateLegalEntity = typeof corporateLegalEntitiesTable.$inferSelect;
export type CorporatePhoneAudit = typeof corporatePhoneAuditTable.$inferSelect;
