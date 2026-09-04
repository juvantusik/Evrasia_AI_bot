import {
  boolean,
  index,
  integer,
  pgTable,
  text,
  timestamp,
} from "drizzle-orm/pg-core";

// Добавлено 03.09.2026 ИТ Директор Евразии
export const antiFraudAccountsTable = pgTable(
  "anti_fraud_accounts",
  {
    bitrixUserId: integer("bitrix_user_id").primaryKey(),
    phoneNormalized: text("phone_normalized"),
    emailNormalized: text("email_normalized"),
    displayName: text("display_name"),
    registeredAt: timestamp("registered_at", { withTimezone: true }),
    bitrixActive: boolean("bitrix_active").notNull().default(true),
    currentCardNumber: text("current_card_number"),
    lastSyncedAt: timestamp("last_synced_at", { withTimezone: true }).notNull().defaultNow(),
  },
  (table) => ({
    phoneIdx: index("anti_fraud_accounts_phone_idx").on(table.phoneNormalized),
    emailIdx: index("anti_fraud_accounts_email_idx").on(table.emailNormalized),
  }),
);

// Добавлено 03.09.2026 ИТ Директор Евразии
export const antiFraudCardsTable = pgTable(
  "anti_fraud_cards",
  {
    cardNumber: text("card_number").primaryKey(),
    bitrixUserId: integer("bitrix_user_id"),
    cardType: integer("card_type"),
    cardStatus: integer("card_status"),
    isActive: boolean("is_active").notNull().default(false),
    firstSeenAt: timestamp("first_seen_at", { withTimezone: true }).notNull().defaultNow(),
    lastSeenAt: timestamp("last_seen_at", { withTimezone: true }).notNull().defaultNow(),
    resolvedAt: timestamp("resolved_at", { withTimezone: true }),
  },
  (table) => ({
    userIdx: index("anti_fraud_cards_user_idx").on(table.bitrixUserId),
    activeIdx: index("anti_fraud_cards_active_idx").on(table.isActive),
  }),
);

// Добавлено 03.09.2026 ИТ Директор Евразии
export const antiFraudVisitsTable = pgTable(
  "anti_fraud_visits",
  {
    restisId: text("restis_id").primaryKey(),
    cardNumber: text("card_number").notNull(),
    bitrixUserId: integer("bitrix_user_id"),
    visitedAt: timestamp("visited_at", { withTimezone: true }).notNull(),
    restaurant: text("restaurant").notNull(),
    syncedAt: timestamp("synced_at", { withTimezone: true }).notNull().defaultNow(),
    resolvedAt: timestamp("resolved_at", { withTimezone: true }),
  },
  (table) => ({
    cardVisitedIdx: index("anti_fraud_visits_card_visited_idx").on(
      table.cardNumber,
      table.visitedAt,
    ),
    userVisitedIdx: index("anti_fraud_visits_user_visited_idx").on(
      table.bitrixUserId,
      table.visitedAt,
    ),
    restaurantVisitedIdx: index("anti_fraud_visits_restaurant_visited_idx").on(
      table.restaurant,
      table.visitedAt,
    ),
  }),
);

// Добавлено 03.09.2026 ИТ Директор Евразии
export const antiFraudDeviceEventsTable = pgTable(
  "anti_fraud_device_events",
  {
    sourceEventId: text("source_event_id").primaryKey(),
    bitrixUserId: integer("bitrix_user_id").notNull(),
    deviceHash: text("device_hash").notNull(),
    eventType: integer("event_type").notNull(),
    authMethod: integer("auth_method"),
    occurredAt: timestamp("occurred_at", { withTimezone: true }).notNull(),
    syncedAt: timestamp("synced_at", { withTimezone: true }).notNull().defaultNow(),
  },
  (table) => ({
    userOccurredIdx: index("anti_fraud_device_events_user_occurred_idx").on(
      table.bitrixUserId,
      table.occurredAt,
    ),
    deviceOccurredIdx: index("anti_fraud_device_events_device_occurred_idx").on(
      table.deviceHash,
      table.occurredAt,
    ),
  }),
);

// Добавлено 03.09.2026 ИТ Директор Евразии
export const antiFraudSyncStateTable = pgTable("anti_fraud_sync_state", {
  source: text("source").primaryKey(),
  cursor: text("cursor"),
  lastStartedAt: timestamp("last_started_at", { withTimezone: true }),
  lastSucceededAt: timestamp("last_succeeded_at", { withTimezone: true }),
  lastError: text("last_error"),
  recordsFetched: integer("records_fetched").notNull().default(0),
  recordsWritten: integer("records_written").notNull().default(0),
  recordsResolved: integer("records_resolved").notNull().default(0),
  updatedAt: timestamp("updated_at", { withTimezone: true }).notNull().defaultNow(),
});

export type AntiFraudAccount = typeof antiFraudAccountsTable.$inferSelect;
export type AntiFraudCard = typeof antiFraudCardsTable.$inferSelect;
export type AntiFraudVisit = typeof antiFraudVisitsTable.$inferSelect;
export type AntiFraudDeviceEvent = typeof antiFraudDeviceEventsTable.$inferSelect;
export type AntiFraudSyncState = typeof antiFraudSyncStateTable.$inferSelect;
