-- Добавлено 03.09.2026 ИТ Директор Евразии
ALTER TABLE "anti_fraud_device_events"
  ADD COLUMN "client_type" text;
--> statement-breakpoint
ALTER TABLE "anti_fraud_device_events"
  ADD CONSTRAINT "anti_fraud_device_events_hash_format_chk"
  CHECK ("device_hash" ~ '^[a-f0-9]{64}$');
--> statement-breakpoint
CREATE TABLE "anti_fraud_device_links" (
  "source_link_id" text PRIMARY KEY NOT NULL,
  "bitrix_user_id" integer NOT NULL,
  "device_hash" text NOT NULL,
  "status" text NOT NULL,
  "client_type" text,
  "created_at" timestamp with time zone NOT NULL,
  "last_seen_at" timestamp with time zone,
  "updated_at" timestamp with time zone,
  "seen_run_id" text NOT NULL,
  "synced_at" timestamp with time zone DEFAULT now() NOT NULL,
  CONSTRAINT "anti_fraud_device_links_hash_format_chk"
    CHECK ("device_hash" ~ '^[a-f0-9]{64}$')
);
--> statement-breakpoint
CREATE INDEX "anti_fraud_device_links_user_idx"
  ON "anti_fraud_device_links" ("bitrix_user_id");
--> statement-breakpoint
CREATE INDEX "anti_fraud_device_links_device_idx"
  ON "anti_fraud_device_links" ("device_hash");
--> statement-breakpoint
CREATE INDEX "anti_fraud_device_links_device_user_idx"
  ON "anti_fraud_device_links" ("device_hash", "bitrix_user_id");
--> statement-breakpoint
COMMENT ON TABLE "anti_fraud_device_links" IS
  'Current Trusted Device user-device relationships mirrored from Bitrix. Full snapshot reconciliation is used because auth events can be incomplete.';
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_device_links"."status" IS
  'Raw Trusted Device status preserved as text; source value is not interpreted by the ingestion layer.';
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_device_links"."client_type" IS
  'Raw Trusted Device client type preserved as text when present in source.';
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_device_events"."client_type" IS
  'Raw Trusted Device client type preserved as text when present in source.';
