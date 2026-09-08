-- Добавлено 07.09.2026 ИТ Директор Евразии
-- Bitrix остаётся source of truth для статуса пользователя Anti-Fraud.
ALTER TABLE "anti_fraud_accounts"
  ADD COLUMN IF NOT EXISTS "bitrix_blocked" boolean NOT NULL DEFAULT false;

ALTER TABLE "anti_fraud_accounts"
  ADD COLUMN IF NOT EXISTS "bitrix_block_reason" text;
