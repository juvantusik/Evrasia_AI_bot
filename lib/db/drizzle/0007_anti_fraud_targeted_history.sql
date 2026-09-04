-- Добавлено 03.09.2026 ИТ Директор Евразии
ALTER TABLE "anti_fraud_cards"
  ADD COLUMN "history_loaded_from" timestamp with time zone,
  ADD COLUMN "history_loaded_until" timestamp with time zone,
  ADD COLUMN "history_loaded_at" timestamp with time zone;

COMMENT ON COLUMN "anti_fraud_cards"."history_loaded_from" IS
  'Oldest visit timestamp included in the latest targeted VIP_HISTORY enrichment window.';

COMMENT ON COLUMN "anti_fraud_cards"."history_loaded_until" IS
  'Newest visit timestamp boundary covered by the latest targeted VIP_HISTORY enrichment window.';

COMMENT ON COLUMN "anti_fraud_cards"."history_loaded_at" IS
  'When targeted VIP_HISTORY was last successfully loaded for this active card.';
