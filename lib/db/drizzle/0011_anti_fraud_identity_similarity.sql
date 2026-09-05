-- Добавлено 05.09.2026 ИТ Директор Евразии
-- Кандидатные identity-связки: похожий email / телефон с отличием в 1 цифру.
-- Сами по себе слабые признаки не объединяют аккаунты: связь считается подтверждённой
-- только при наличии дополнительного независимого identity/device-признака.

CREATE TABLE IF NOT EXISTS anti_fraud_identity_links (
  left_user_id integer NOT NULL,
  right_user_id integer NOT NULL,
  similar_email boolean NOT NULL DEFAULT false,
  similar_phone boolean NOT NULL DEFAULT false,
  same_name boolean NOT NULL DEFAULT false,
  shared_device boolean NOT NULL DEFAULT false,
  corroborated boolean NOT NULL DEFAULT false,
  email_details text,
  phone_details text,
  risk_score integer NOT NULL DEFAULT 0,
  computed_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (left_user_id, right_user_id),
  CONSTRAINT anti_fraud_identity_links_order_check CHECK (left_user_id < right_user_id),
  CONSTRAINT anti_fraud_identity_links_candidate_check CHECK (similar_email OR similar_phone),
  CONSTRAINT anti_fraud_identity_links_score_check CHECK (risk_score >= 0 AND risk_score <= 100)
);

CREATE INDEX IF NOT EXISTS anti_fraud_identity_links_left_idx
  ON anti_fraud_identity_links (left_user_id, corroborated, risk_score);

CREATE INDEX IF NOT EXISTS anti_fraud_identity_links_right_idx
  ON anti_fraud_identity_links (right_user_id, corroborated, risk_score);

CREATE INDEX IF NOT EXISTS anti_fraud_identity_links_corroborated_idx
  ON anti_fraud_identity_links (corroborated, risk_score, computed_at);
