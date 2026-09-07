-- Добавлено 05.09.2026 ИТ Директор Евразии
-- Реальный protected loyalty endpoint может вернуть отрицательный текущий бонусный остаток.
-- Это валидное состояние текущего баланса. Ограничение >= 0 из 0010/0013 блокировало такие данные.
ALTER TABLE "anti_fraud_accounts"
  DROP CONSTRAINT IF EXISTS "anti_fraud_accounts_bonus_balance_chk";
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_accounts"."bonus_balance" IS
  'Current signed RestIS TotalSum from /api/internal/anti-fraud/loyalty. Stored as NUMERIC(14,2); negative values are valid, while values above 40000 are an explainable +50 risk signal.';
