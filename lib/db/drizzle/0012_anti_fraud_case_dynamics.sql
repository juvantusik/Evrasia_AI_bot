-- Добавлено 05.09.2026 ИТ Директор Евразии
-- Состояние Anti-Fraud кейсов для сравнения «было → стало».
-- Отображаемый risk остаётся 0..100; evidence_score используется только внутренне
-- для определения усиления уже критических кейсов и пользователю как risk не показывается.

CREATE TABLE IF NOT EXISTS anti_fraud_case_state (
  case_id text PRIMARY KEY,
  current_fingerprint text NOT NULL,
  current_evidence_score integer NOT NULL DEFAULT 0,
  current_overall_risk integer NOT NULL DEFAULT 0,
  current_account_count integer NOT NULL DEFAULT 0,
  current_device_count integer NOT NULL DEFAULT 0,
  current_reason_count integer NOT NULL DEFAULT 0,
  current_account_ids text NOT NULL DEFAULT '',
  current_reason_codes text NOT NULL DEFAULT '',
  current_changed_at timestamptz NOT NULL DEFAULT now(),
  previous_evidence_score integer,
  previous_overall_risk integer,
  previous_account_count integer,
  previous_device_count integer,
  previous_reason_count integer,
  previous_account_ids text,
  previous_reason_codes text,
  previous_changed_at timestamptz,
  first_seen_at timestamptz NOT NULL DEFAULT now(),
  last_seen_at timestamptz NOT NULL DEFAULT now(),
  observed_runs integer NOT NULL DEFAULT 1,
  CONSTRAINT anti_fraud_case_state_current_nonnegative_check CHECK (
    current_evidence_score >= 0
    AND current_overall_risk >= 0
    AND current_overall_risk <= 100
    AND current_account_count >= 0
    AND current_device_count >= 0
    AND current_reason_count >= 0
    AND observed_runs >= 1
  )
);

CREATE INDEX IF NOT EXISTS anti_fraud_case_state_changed_idx
  ON anti_fraud_case_state (current_changed_at DESC);

CREATE INDEX IF NOT EXISTS anti_fraud_case_state_seen_idx
  ON anti_fraud_case_state (last_seen_at DESC);
