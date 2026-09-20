-- Добавлено 20.09.2026 ИТ Директор Евразии
-- Step 2 ручной Anti-Fraud проверки: постоянный audit/state операторского расследования.
-- Телефон здесь намеренно не дублируется: после защищённого resolver хранится USER_ID,
-- а источник/основание оператора остаются отдельно от автоматических risk-сигналов.

CREATE TABLE IF NOT EXISTS "anti_fraud_operator_investigations" (
  "investigation_id" text PRIMARY KEY NOT NULL,
  "bitrix_user_id" integer NOT NULL,
  "source" text NOT NULL,
  "reason" text,
  "status" text NOT NULL DEFAULT 'pending',
  "requested_at" timestamptz NOT NULL DEFAULT now(),
  "started_at" timestamptz,
  "history_completed_at" timestamptz,
  "scoring_completed_at" timestamptz,
  "completed_at" timestamptz,
  "last_error" text,
  "updated_at" timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT "anti_fraud_operator_investigation_status_check"
    CHECK ("status" IN ('pending', 'processing', 'history_ready', 'ready', 'failed')),
  CONSTRAINT "anti_fraud_operator_investigation_source_check"
    CHECK (length(btrim("source")) BETWEEN 1 AND 80),
  CONSTRAINT "anti_fraud_operator_investigation_reason_check"
    CHECK ("reason" IS NULL OR length("reason") <= 500)
);

CREATE INDEX IF NOT EXISTS "anti_fraud_operator_investigation_user_idx"
  ON "anti_fraud_operator_investigations" ("bitrix_user_id", "requested_at" DESC);

CREATE INDEX IF NOT EXISTS "anti_fraud_operator_investigation_status_idx"
  ON "anti_fraud_operator_investigations" ("status", "updated_at");

