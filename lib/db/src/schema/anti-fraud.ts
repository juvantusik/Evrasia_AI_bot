import {
  boolean,
  index,
  integer,
  numeric,
  pgTable,
  serial,
  text,
  timestamp,
  uniqueIndex,
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
    // Обновлено 05.09.2026 ИТ Директор Евразии
    // RestIS TotalSum содержит копейки, поэтому используем точный NUMERIC(14,2), а не integer/float.
    bonusBalance: numeric("bonus_balance", { precision: 14, scale: 2 }),
    // Protected loyalty resolution: NULL = ещё не загружено; 0 = нет активной карты;
    // 1 = одна активная карта; >1 = несколько активных RESTIS_STATE=113 карт.
    loyaltyActiveCardCount: integer("loyalty_active_card_count"),
    loyaltyIssue: text("loyalty_issue"),
    loyaltySyncedAt: timestamp("loyalty_synced_at", { withTimezone: true }),
    loyaltyHistoryLoadedFrom: timestamp("loyalty_history_loaded_from", { withTimezone: true }),
    loyaltyHistoryLoadedUntil: timestamp("loyalty_history_loaded_until", { withTimezone: true }),
    loyaltyHistoryLoadedAt: timestamp("loyalty_history_loaded_at", { withTimezone: true }),
    lastSyncedAt: timestamp("last_synced_at", { withTimezone: true }).notNull().defaultNow(),
  },
  (table) => ({
    phoneIdx: index("anti_fraud_accounts_phone_idx").on(table.phoneNormalized),
    emailIdx: index("anti_fraud_accounts_email_idx").on(table.emailNormalized),
    loyaltyIssueIdx: index("anti_fraud_accounts_loyalty_issue_idx").on(table.loyaltyIssue),
  }),
);

// Добавлено 03.09.2026 ИТ Директор Евразии
export const antiFraudCardsTable = pgTable(
  "anti_fraud_cards",
  {
    id: serial("id").primaryKey(),
    cardNumber: text("card_number").notNull(),
    bitrixUserId: integer("bitrix_user_id"),
    cardType: integer("card_type"),
    bitrixCardStatusId: integer("bitrix_card_status_id"),
    isActive: boolean("is_active").notNull().default(false),
    firstSeenAt: timestamp("first_seen_at", { withTimezone: true }).notNull().defaultNow(),
    lastSeenAt: timestamp("last_seen_at", { withTimezone: true }).notNull().defaultNow(),
    resolvedAt: timestamp("resolved_at", { withTimezone: true }),
    // Legacy coverage для старого прямого VIP_HISTORY. Новый loyalty flow хранит coverage на аккаунте.
    historyLoadedFrom: timestamp("history_loaded_from", { withTimezone: true }),
    historyLoadedUntil: timestamp("history_loaded_until", { withTimezone: true }),
    historyLoadedAt: timestamp("history_loaded_at", { withTimezone: true }),
  },
  (table) => ({
    cardNumberUidx: uniqueIndex("anti_fraud_cards_number_uidx").on(table.cardNumber),
    userIdx: index("anti_fraud_cards_user_idx").on(table.bitrixUserId),
  }),
);

// Добавлено 03.09.2026 ИТ Директор Евразии
export const antiFraudVisitsTable = pgTable(
  "anti_fraud_visits",
  {
    // В legacy VIP_TODAY здесь raw RestIS ID. Для protected history — детерминированный
    // внутренний event key, потому что один source RestIS ID может относиться к разным операциям.
    restisId: text("restis_id").primaryKey(),
    sourceRestisId: text("source_restis_id").notNull(),
    // Обновлено 05.09.2026: новый защищённый loyalty API не раскрывает номер активной карты,
    // поэтому card_id может быть NULL для проверенной истории, привязанной напрямую к USER_ID.
    cardId: integer("card_id").references(() => antiFraudCardsTable.id, { onDelete: "restrict" }),
    bitrixUserId: integer("bitrix_user_id"),
    visitedAt: timestamp("visited_at", { withTimezone: true }).notNull(),
    restaurant: text("restaurant").notNull(),
    amount: numeric("amount", { precision: 14, scale: 2 }),
    bonusAdded: numeric("bonus_added", { precision: 14, scale: 2 }),
    bonusSpent: numeric("bonus_spent", { precision: 14, scale: 2 }),
    loyaltyVerified: boolean("loyalty_verified").notNull().default(false),
    syncedAt: timestamp("synced_at", { withTimezone: true }).notNull().defaultNow(),
    resolvedAt: timestamp("resolved_at", { withTimezone: true }),
  },
  (table) => ({
    sourceRestisIdx: index("anti_fraud_visits_source_restis_idx").on(table.sourceRestisId),
    sourceUserTimeIdx: index("anti_fraud_visits_source_user_time_idx").on(
      table.sourceRestisId,
      table.bitrixUserId,
      table.visitedAt,
    ),
    cardVisitedIdx: index("anti_fraud_visits_card_visited_idx").on(
      table.cardId,
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
    eventType: text("event_type").notNull(),
    authMethod: text("auth_method"),
    clientType: text("client_type"),
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
export const antiFraudDeviceLinksTable = pgTable(
  "anti_fraud_device_links",
  {
    sourceLinkId: text("source_link_id").primaryKey(),
    bitrixUserId: integer("bitrix_user_id").notNull(),
    deviceHash: text("device_hash").notNull(),
    status: text("status").notNull(),
    clientType: text("client_type"),
    createdAt: timestamp("created_at", { withTimezone: true }).notNull(),
    lastSeenAt: timestamp("last_seen_at", { withTimezone: true }),
    updatedAt: timestamp("updated_at", { withTimezone: true }),
    seenRunId: text("seen_run_id").notNull(),
    syncedAt: timestamp("synced_at", { withTimezone: true }).notNull().defaultNow(),
  },
  (table) => ({
    userIdx: index("anti_fraud_device_links_user_idx").on(table.bitrixUserId),
    deviceIdx: index("anti_fraud_device_links_device_idx").on(table.deviceHash),
    deviceUserIdx: index("anti_fraud_device_links_device_user_idx").on(
      table.deviceHash,
      table.bitrixUserId,
    ),
  }),
);

// Добавлено 03.09.2026 ИТ Директор Евразии
export const antiFraudRiskScoresTable = pgTable(
  "anti_fraud_risk_scores",
  {
    bitrixUserId: integer("bitrix_user_id").primaryKey(),
    calculationVersion: text("calculation_version").notNull().default("v1"),
    deviceRisk: integer("device_risk").notNull().default(0),
    linkedAccountRisk: integer("linked_account_risk").notNull().default(0),
    identitySimilarityRisk: integer("identity_similarity_risk").notNull().default(0),
    visitBehaviorRisk: integer("visit_behavior_risk").notNull().default(0),
    historicalBehaviorRisk: integer("historical_behavior_risk").notNull().default(0),
    overallRisk: integer("overall_risk").notNull().default(0),
    riskLevel: text("risk_level").notNull(),
    historyGate: boolean("history_gate").notNull().default(false),
    historyEnriched: boolean("history_enriched").notNull().default(false),
    computedAt: timestamp("computed_at", { withTimezone: true }).notNull().defaultNow(),
  },
  (table) => ({
    overallIdx: index("anti_fraud_risk_scores_overall_idx").on(
      table.overallRisk,
      table.computedAt,
    ),
    historyGateIdx: index("anti_fraud_risk_scores_history_gate_idx").on(
      table.historyGate,
      table.overallRisk,
    ),
  }),
);

// Добавлено 03.09.2026 ИТ Директор Евразии
export const antiFraudRiskReasonsTable = pgTable(
  "anti_fraud_risk_reasons",
  {
    id: serial("id").primaryKey(),
    bitrixUserId: integer("bitrix_user_id").notNull(),
    reasonCode: text("reason_code").notNull(),
    score: integer("score").notNull(),
    details: text("details").notNull(),
    computedAt: timestamp("computed_at", { withTimezone: true }).notNull().defaultNow(),
  },
  (table) => ({
    userCodeUidx: uniqueIndex("anti_fraud_risk_reasons_user_code_uidx").on(
      table.bitrixUserId,
      table.reasonCode,
    ),
    codeIdx: index("anti_fraud_risk_reasons_code_idx").on(
      table.reasonCode,
      table.score,
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

// Добавлено 03.09.2026 ИТ Директор Евразии
export const antiFraudSyncRunsTable = pgTable(
  "anti_fraud_sync_runs",
  {
    runId: text("run_id").primaryKey(),
    source: text("source").notNull(),
    status: text("status").notNull(),
    startedAt: timestamp("started_at", { withTimezone: true }).notNull().defaultNow(),
    finishedAt: timestamp("finished_at", { withTimezone: true }),
    recordsFetched: integer("records_fetched").notNull().default(0),
    recordsWritten: integer("records_written").notNull().default(0),
    recordsResolved: integer("records_resolved").notNull().default(0),
    error: text("error"),
  },
  (table) => ({
    sourceStartedIdx: index("anti_fraud_sync_runs_source_started_idx").on(
      table.source,
      table.startedAt,
    ),
  }),
);

export type AntiFraudAccount = typeof antiFraudAccountsTable.$inferSelect;
export type AntiFraudCard = typeof antiFraudCardsTable.$inferSelect;
export type AntiFraudVisit = typeof antiFraudVisitsTable.$inferSelect;
export type AntiFraudDeviceEvent = typeof antiFraudDeviceEventsTable.$inferSelect;
export type AntiFraudDeviceLink = typeof antiFraudDeviceLinksTable.$inferSelect;
export type AntiFraudRiskScore = typeof antiFraudRiskScoresTable.$inferSelect;
export type AntiFraudRiskReason = typeof antiFraudRiskReasonsTable.$inferSelect;
export type AntiFraudSyncState = typeof antiFraudSyncStateTable.$inferSelect;
export type AntiFraudSyncRun = typeof antiFraudSyncRunsTable.$inferSelect;
