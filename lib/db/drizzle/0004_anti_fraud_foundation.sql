-- Добавлено 03.09.2026 ИТ Директор Евразии
CREATE TABLE "anti_fraud_accounts" (
  "bitrix_user_id" integer PRIMARY KEY NOT NULL,
  "phone_normalized" text,
  "email_normalized" text,
  "display_name" text,
  "registered_at" timestamp with time zone,
  "bitrix_active" boolean DEFAULT true NOT NULL,
  "current_card_number" text,
  "last_synced_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE INDEX "anti_fraud_accounts_phone_idx" ON "anti_fraud_accounts" ("phone_normalized");
--> statement-breakpoint
CREATE INDEX "anti_fraud_accounts_email_idx" ON "anti_fraud_accounts" ("email_normalized");
--> statement-breakpoint
CREATE TABLE "anti_fraud_cards" (
  "card_number" text PRIMARY KEY NOT NULL,
  "bitrix_user_id" integer,
  "card_type" integer,
  "restis_state" integer,
  "is_active" boolean DEFAULT false NOT NULL,
  "first_seen_at" timestamp with time zone DEFAULT now() NOT NULL,
  "last_seen_at" timestamp with time zone DEFAULT now() NOT NULL,
  "resolved_at" timestamp with time zone
);
--> statement-breakpoint
CREATE INDEX "anti_fraud_cards_user_idx" ON "anti_fraud_cards" ("bitrix_user_id");
--> statement-breakpoint
CREATE INDEX "anti_fraud_cards_active_idx" ON "anti_fraud_cards" ("is_active");
--> statement-breakpoint
CREATE TABLE "anti_fraud_visits" (
  "restis_id" text PRIMARY KEY NOT NULL,
  "card_number" text NOT NULL,
  "bitrix_user_id" integer,
  "visited_at" timestamp with time zone NOT NULL,
  "restaurant" text NOT NULL,
  "synced_at" timestamp with time zone DEFAULT now() NOT NULL,
  "resolved_at" timestamp with time zone
);
--> statement-breakpoint
CREATE INDEX "anti_fraud_visits_card_visited_idx" ON "anti_fraud_visits" ("card_number","visited_at");
--> statement-breakpoint
CREATE INDEX "anti_fraud_visits_user_visited_idx" ON "anti_fraud_visits" ("bitrix_user_id","visited_at");
--> statement-breakpoint
CREATE INDEX "anti_fraud_visits_restaurant_visited_idx" ON "anti_fraud_visits" ("restaurant","visited_at");
--> statement-breakpoint
CREATE TABLE "anti_fraud_device_events" (
  "source_event_id" text PRIMARY KEY NOT NULL,
  "bitrix_user_id" integer NOT NULL,
  "device_hash" text NOT NULL,
  "event_type" integer NOT NULL,
  "auth_method" integer,
  "occurred_at" timestamp with time zone NOT NULL,
  "synced_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE INDEX "anti_fraud_device_events_user_occurred_idx" ON "anti_fraud_device_events" ("bitrix_user_id","occurred_at");
--> statement-breakpoint
CREATE INDEX "anti_fraud_device_events_device_occurred_idx" ON "anti_fraud_device_events" ("device_hash","occurred_at");
--> statement-breakpoint
CREATE TABLE "anti_fraud_sync_state" (
  "source" text PRIMARY KEY NOT NULL,
  "cursor" text,
  "last_started_at" timestamp with time zone,
  "last_succeeded_at" timestamp with time zone,
  "last_error" text,
  "records_fetched" integer DEFAULT 0 NOT NULL,
  "records_written" integer DEFAULT 0 NOT NULL,
  "records_resolved" integer DEFAULT 0 NOT NULL,
  "updated_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE "anti_fraud_sync_runs" (
  "run_id" text PRIMARY KEY NOT NULL,
  "source" text NOT NULL,
  "status" text NOT NULL,
  "started_at" timestamp with time zone DEFAULT now() NOT NULL,
  "finished_at" timestamp with time zone,
  "records_fetched" integer DEFAULT 0 NOT NULL,
  "records_written" integer DEFAULT 0 NOT NULL,
  "records_resolved" integer DEFAULT 0 NOT NULL,
  "error" text
);
--> statement-breakpoint
CREATE INDEX "anti_fraud_sync_runs_source_started_idx" ON "anti_fraud_sync_runs" ("source","started_at");
