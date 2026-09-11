import { index, integer, pgTable, text, timestamp } from "drizzle-orm/pg-core";

// Добавлено 05.09.2026 ИТ Директор Евразии
// Последнее изменившееся состояние Anti-Fraud кейса и его предыдущее состояние.
// currentEvidenceScore/previousEvidenceScore — внутренний показатель насыщенности
// доказательствами; это не пользовательский Risk 0..100.
export const antiFraudCaseStateTable = pgTable(
  "anti_fraud_case_state",
  {
    caseId: text("case_id").primaryKey(),
    currentFingerprint: text("current_fingerprint").notNull(),
    currentEvidenceScore: integer("current_evidence_score").notNull().default(0),
    currentOverallRisk: integer("current_overall_risk").notNull().default(0),
    currentAccountCount: integer("current_account_count").notNull().default(0),
    currentDeviceCount: integer("current_device_count").notNull().default(0),
    currentReasonCount: integer("current_reason_count").notNull().default(0),
    currentAccountIds: text("current_account_ids").notNull().default(""),
    currentReasonCodes: text("current_reason_codes").notNull().default(""),
    currentChangedAt: timestamp("current_changed_at", { withTimezone: true }).notNull().defaultNow(),
    previousEvidenceScore: integer("previous_evidence_score"),
    previousOverallRisk: integer("previous_overall_risk"),
    previousAccountCount: integer("previous_account_count"),
    previousDeviceCount: integer("previous_device_count"),
    previousReasonCount: integer("previous_reason_count"),
    previousAccountIds: text("previous_account_ids"),
    previousReasonCodes: text("previous_reason_codes"),
    previousChangedAt: timestamp("previous_changed_at", { withTimezone: true }),
    firstSeenAt: timestamp("first_seen_at", { withTimezone: true }).notNull().defaultNow(),
    lastSeenAt: timestamp("last_seen_at", { withTimezone: true }).notNull().defaultNow(),
    observedRuns: integer("observed_runs").notNull().default(1),
  },
  (table) => ({
    changedIdx: index("anti_fraud_case_state_changed_idx").on(table.currentChangedAt),
    seenIdx: index("anti_fraud_case_state_seen_idx").on(table.lastSeenAt),
  }),
);

// Добавлено 11.09.2026 ИТ Директор Евразии
// Операторская web-метка первого появления USER_ID в текущем интерфейсе Anti-Fraud.
// Она не является risk/grouping evidence и используется только для 24-часового бейджа «Новый».
export const antiFraudWebAccountStateTable = pgTable(
  "anti_fraud_web_account_state",
  {
    bitrixUserId: integer("bitrix_user_id").primaryKey(),
    firstSeenAt: timestamp("first_seen_at", { withTimezone: true }).notNull().defaultNow(),
  },
  (table) => ({
    firstSeenIdx: index("anti_fraud_web_account_state_first_seen_idx").on(table.firstSeenAt),
  }),
);

export type AntiFraudCaseState = typeof antiFraudCaseStateTable.$inferSelect;
export type AntiFraudWebAccountState = typeof antiFraudWebAccountStateTable.$inferSelect;
