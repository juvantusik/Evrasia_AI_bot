-- Добавлено 05.09.2026 ИТ Директор Евразии
-- RestIS возвращает денежные значения с копейками. Integer для bonus_balance был неверным типом.
ALTER TABLE "anti_fraud_accounts"
  DROP CONSTRAINT IF EXISTS "anti_fraud_accounts_bonus_balance_chk";
--> statement-breakpoint
ALTER TABLE "anti_fraud_accounts"
  ALTER COLUMN "bonus_balance" TYPE numeric(14,2)
  USING "bonus_balance"::numeric(14,2);
--> statement-breakpoint
ALTER TABLE "anti_fraud_accounts"
  ADD CONSTRAINT "anti_fraud_accounts_bonus_balance_chk"
  CHECK ("bonus_balance" IS NULL OR "bonus_balance" >= 0);
--> statement-breakpoint
ALTER TABLE "anti_fraud_accounts"
  ADD COLUMN "loyalty_synced_at" timestamp with time zone,
  ADD COLUMN "loyalty_history_loaded_from" timestamp with time zone,
  ADD COLUMN "loyalty_history_loaded_until" timestamp with time zone,
  ADD COLUMN "loyalty_history_loaded_at" timestamp with time zone;
--> statement-breakpoint

-- История теперь приходит через защищённый site-side loyalty API по USER_ID.
-- Поэтому новый исторический event не обязан раскрывать или хранить номер активной карты в боте.
ALTER TABLE "anti_fraud_visits"
  ALTER COLUMN "card_id" DROP NOT NULL,
  ADD COLUMN "amount" numeric(14,2),
  ADD COLUMN "bonus_added" numeric(14,2),
  ADD COLUMN "bonus_spent" numeric(14,2),
  ADD COLUMN "loyalty_verified" boolean NOT NULL DEFAULT false;
--> statement-breakpoint
ALTER TABLE "anti_fraud_visits"
  ADD CONSTRAINT "anti_fraud_visits_amount_chk"
    CHECK ("amount" IS NULL OR "amount" >= 0),
  ADD CONSTRAINT "anti_fraud_visits_bonus_added_chk"
    CHECK ("bonus_added" IS NULL OR "bonus_added" >= 0),
  ADD CONSTRAINT "anti_fraud_visits_bonus_spent_chk"
    CHECK ("bonus_spent" IS NULL OR "bonus_spent" >= 0);
--> statement-breakpoint

COMMENT ON COLUMN "anti_fraud_accounts"."bonus_balance" IS
  'Current RestIS TotalSum from /api/internal/anti-fraud/loyalty. Stored as NUMERIC(14,2); values above 40000 are an explainable +50 risk signal.';
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_accounts"."loyalty_synced_at" IS
  'Last successful current loyalty-state refresh through the protected site-side loyalty API.';
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_accounts"."loyalty_history_loaded_at" IS
  'Last successful protected 60-day loyalty-history refresh for this Bitrix USER_ID.';
--> statement-breakpoint
COMMENT ON COLUMN "anti_fraud_visits"."loyalty_verified" IS
  'True only for events returned through the protected loyalty endpoint from the currently active RESTIS_STATE=113 card.';
