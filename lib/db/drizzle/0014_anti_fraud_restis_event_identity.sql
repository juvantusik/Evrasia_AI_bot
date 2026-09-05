-- Добавлено 05.09.2026 ИТ Директор Евразии
-- Реальный VIP_HISTORY может возвращать несколько разных операций с одинаковым restis_id.
-- Поэтому restis_id остаётся внутренним уникальным ключом строки, а исходный RestIS ID
-- хранится отдельно и может повторяться.
ALTER TABLE "anti_fraud_visits"
  ADD COLUMN "source_restis_id" text;
--> statement-breakpoint
UPDATE "anti_fraud_visits"
SET "source_restis_id" = "restis_id"
WHERE "source_restis_id" IS NULL;
--> statement-breakpoint
ALTER TABLE "anti_fraud_visits"
  ALTER COLUMN "source_restis_id" SET NOT NULL;
--> statement-breakpoint
CREATE INDEX "anti_fraud_visits_source_restis_idx"
  ON "anti_fraud_visits" ("source_restis_id");
--> statement-breakpoint
CREATE INDEX "anti_fraud_visits_source_user_time_idx"
  ON "anti_fraud_visits" ("source_restis_id", "bitrix_user_id", "visited_at");
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_visits"."restis_id" IS
  'Internal unique visit-event key. Legacy VIP_TODAY rows keep the raw RestIS ID here; protected loyalty-history rows use a deterministic event key because RestIS may reuse one source ID for multiple monetary operations.';
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_visits"."source_restis_id" IS
  'Raw RestIS source ID. It is intentionally NOT unique: live VIP_HISTORY can contain different operations with the same source ID and timestamp.';
