-- Добавлено 03.09.2026 ИТ Директор Евразии
ALTER TABLE "anti_fraud_accounts" DROP COLUMN "current_card_number";
--> statement-breakpoint
ALTER TABLE "anti_fraud_cards" ADD COLUMN "id" serial NOT NULL;
--> statement-breakpoint
ALTER TABLE "anti_fraud_cards" DROP CONSTRAINT "anti_fraud_cards_pkey";
--> statement-breakpoint
ALTER TABLE "anti_fraud_cards" ADD CONSTRAINT "anti_fraud_cards_pkey" PRIMARY KEY ("id");
--> statement-breakpoint
CREATE UNIQUE INDEX "anti_fraud_cards_number_uidx" ON "anti_fraud_cards" ("card_number");
--> statement-breakpoint
ALTER TABLE "anti_fraud_visits" ADD COLUMN "card_id" integer;
--> statement-breakpoint
UPDATE "anti_fraud_visits" AS v
SET "card_id" = c."id"
FROM "anti_fraud_cards" AS c
WHERE c."card_number" = v."card_number";
--> statement-breakpoint
ALTER TABLE "anti_fraud_visits" ALTER COLUMN "card_id" SET NOT NULL;
--> statement-breakpoint
ALTER TABLE "anti_fraud_visits"
  ADD CONSTRAINT "anti_fraud_visits_card_id_fk"
  FOREIGN KEY ("card_id") REFERENCES "anti_fraud_cards"("id") ON DELETE RESTRICT;
--> statement-breakpoint
DROP INDEX "anti_fraud_visits_card_visited_idx";
--> statement-breakpoint
CREATE INDEX "anti_fraud_visits_card_visited_idx"
  ON "anti_fraud_visits" ("card_id", "visited_at");
--> statement-breakpoint
ALTER TABLE "anti_fraud_visits" DROP COLUMN "card_number";
--> statement-breakpoint
ALTER TABLE "anti_fraud_device_events"
  ALTER COLUMN "event_type" TYPE text USING "event_type"::text;
--> statement-breakpoint
ALTER TABLE "anti_fraud_device_events"
  ALTER COLUMN "auth_method" TYPE text USING "auth_method"::text;
--> statement-breakpoint
DROP INDEX "anti_fraud_cards_active_idx";
--> statement-breakpoint
ALTER TABLE "anti_fraud_cards"
  ADD CONSTRAINT "anti_fraud_cards_number_format_chk"
  CHECK ("card_number" ~ '^[0-9]{4,32}$');
--> statement-breakpoint
ALTER TABLE "anti_fraud_sync_runs"
  ADD CONSTRAINT "anti_fraud_sync_runs_status_chk"
  CHECK ("status" IN ('running', 'success', 'failed'));
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_cards"."card_number" IS
  'Raw RestIS loyalty-card identifier. Stored only in the card master table for Bitrix resolution; never copy to visit history or logs.';
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_visits"."card_id" IS
  'Internal surrogate reference to anti_fraud_cards; visit history does not duplicate raw loyalty-card identifiers.';
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_device_events"."event_type" IS
  'Raw Trusted Device event type preserved as text until source semantics are formally mapped.';
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_device_events"."auth_method" IS
  'Raw Trusted Device auth method preserved as text until source semantics are formally mapped.';
