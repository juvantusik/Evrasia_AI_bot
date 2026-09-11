CREATE TABLE IF NOT EXISTS "anti_fraud_web_account_state" (
  "bitrix_user_id" integer PRIMARY KEY NOT NULL,
  "first_seen_at" timestamp with time zone DEFAULT now() NOT NULL
);

CREATE INDEX IF NOT EXISTS "anti_fraud_web_account_state_first_seen_idx"
  ON "anti_fraud_web_account_state" USING btree ("first_seen_at");

-- Bootstrap: все аккаунты, уже присутствующие в текущем web Anti-Fraud на момент миграции,
-- считаются существующими, а не новыми. Новые USER_ID после миграции будут добавляться
-- captureAntiFraudCaseDynamics() с реальным first_seen_at.
WITH visible_accounts AS (
  SELECT DISTINCT s.bitrix_user_id
  FROM anti_fraud_risk_scores s
  WHERE s.overall_risk > 0

  UNION

  SELECT DISTINCT l2.bitrix_user_id
  FROM anti_fraud_device_links l1
  JOIN anti_fraud_device_links l2 ON l2.device_hash = l1.device_hash
  JOIN anti_fraud_risk_scores s ON s.bitrix_user_id = l1.bitrix_user_id
  WHERE s.overall_risk > 0

  UNION

  SELECT DISTINCT a2.bitrix_user_id
  FROM anti_fraud_accounts a1
  JOIN anti_fraud_accounts a2
    ON a2.bitrix_user_id <> a1.bitrix_user_id
   AND (
     (a1.phone_normalized IS NOT NULL AND a1.phone_normalized <> '' AND a2.phone_normalized = a1.phone_normalized)
     OR
     (a1.email_normalized IS NOT NULL AND a1.email_normalized <> '' AND a2.email_normalized = a1.email_normalized)
   )
  JOIN anti_fraud_risk_scores s ON s.bitrix_user_id = a1.bitrix_user_id
  WHERE s.overall_risk > 0

  UNION

  SELECT DISTINCT CASE
    WHEN l.left_user_id = s.bitrix_user_id THEN l.right_user_id
    ELSE l.left_user_id
  END AS bitrix_user_id
  FROM anti_fraud_identity_links l
  JOIN anti_fraud_risk_scores s
    ON s.bitrix_user_id = l.left_user_id
    OR s.bitrix_user_id = l.right_user_id
  WHERE l.corroborated IS TRUE
    AND s.overall_risk > 0
)
INSERT INTO anti_fraud_web_account_state (bitrix_user_id, first_seen_at)
SELECT bitrix_user_id, now() - interval '25 hours'
FROM visible_accounts
WHERE bitrix_user_id IS NOT NULL
ON CONFLICT (bitrix_user_id) DO NOTHING;
