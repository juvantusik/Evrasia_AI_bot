-- Добавлено 05.09.2026 ИТ Директор Евразии
ALTER TABLE "anti_fraud_accounts"
  ADD COLUMN "bonus_balance" integer;
--> statement-breakpoint
ALTER TABLE "anti_fraud_accounts"
  ADD CONSTRAINT "anti_fraud_accounts_bonus_balance_chk"
  CHECK ("bonus_balance" IS NULL OR "bonus_balance" >= 0);
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_accounts"."bonus_balance" IS
  'Current loyalty bonus balance returned by the internal Bitrix Anti-Fraud account-map. Values above 40000 are an explainable risk signal and trigger 60-day history review.';
