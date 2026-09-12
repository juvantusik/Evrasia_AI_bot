ALTER TABLE "anti_fraud_accounts"
  ADD COLUMN IF NOT EXISTS "offer_accepted" boolean,
  ADD COLUMN IF NOT EXISTS "offer_accepted_at" timestamptz,
  ADD COLUMN IF NOT EXISTS "offer_source" text,
  ADD COLUMN IF NOT EXISTS "pd_accepted" boolean,
  ADD COLUMN IF NOT EXISTS "pd_accepted_at" timestamptz,
  ADD COLUMN IF NOT EXISTS "pd_source" text;

ALTER TABLE "anti_fraud_accounts"
  ADD CONSTRAINT "anti_fraud_accounts_offer_source_check"
  CHECK ("offer_source" IS NULL OR "offer_source" IN ('signup', 'account_gate', 'other'));

ALTER TABLE "anti_fraud_accounts"
  ADD CONSTRAINT "anti_fraud_accounts_pd_source_check"
  CHECK ("pd_source" IS NULL OR "pd_source" IN ('signup', 'account_gate', 'other'));
