-- Добавлено 03.09.2026 ИТ Директор Евразии
ALTER TABLE "anti_fraud_cards"
  RENAME COLUMN "restis_state" TO "bitrix_card_status_id";
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_cards"."bitrix_card_status_id" IS
  'Raw Bitrix loyalty-card status enum ID returned by the internal card-map endpoint; not a RestIS state.';
