import { createInsertSchema } from "drizzle-zod";
import { boolean, integer, pgTable, text, timestamp } from "drizzle-orm/pg-core";
import { z } from "zod/v4";

export const samzaberuRulesTable = pgTable("samzaberu_rules", {
  restaurantId: text("restaurant_id").primaryKey(),
  ruleId: text("rule_id").notNull(),
  active: boolean("active").notNull().default(true),
  startsAt: timestamp("starts_at", { withTimezone: true }).notNull(),
  endsAt: timestamp("ends_at", { withTimezone: true }).notNull(),
  notificationType: text("notification_type").notNull(),
  updatedAt: timestamp("updated_at", { withTimezone: true }).notNull().defaultNow().$onUpdate(() => new Date()),
});

export const samzaberuRequestsTable = pgTable("samzaberu_requests", {
  id: text("id").primaryKey(),
  status: text("status").notNull(),
  action: text("action").notNull(),
  restaurantId: text("restaurant_id").notNull(),
  restaurantName: text("restaurant_name").notNull(),
  operatorId: text("operator_id").notNull(),
  operatorName: text("operator_name").notNull(),
  requestedAt: timestamp("requested_at", { withTimezone: true }).notNull().defaultNow(),
  targetUntil: timestamp("target_until", { withTimezone: true }),
  reason: text("reason"),
  message: text("message").notNull(),
  retryCount: integer("retry_count").notNull().default(0),
  escalatedTo: text("escalated_to").array().notNull().default([]),
  canCompleteManually: boolean("can_complete_manually").notNull().default(false),
});

export const insertSamzaberuRuleSchema = createInsertSchema(samzaberuRulesTable);
export const insertSamzaberuRequestSchema = createInsertSchema(samzaberuRequestsTable);

export type InsertSamzaberuRule = z.infer<typeof insertSamzaberuRuleSchema>;
export type InsertSamzaberuRequest = z.infer<typeof insertSamzaberuRequestSchema>;
export type SamzaberuRule = typeof samzaberuRulesTable.$inferSelect;
export type SamzaberuRequestRecord = typeof samzaberuRequestsTable.$inferSelect;