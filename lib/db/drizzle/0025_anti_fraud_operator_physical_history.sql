-- Добавлено 23.09.2026 ИТ Директор Евразии
-- Ручная Anti-Fraud проверка хранит физическую 60-дневную историю отдельно
-- от anti_fraud_visits, чтобы операторский запрос не менял automatic risk scoring.

ALTER TABLE "anti_fraud_operator_investigations"
  ADD COLUMN IF NOT EXISTS "physical_history_from" timestamptz,
  ADD COLUMN IF NOT EXISTS "physical_history_until" timestamptz;

CREATE TABLE IF NOT EXISTS "anti_fraud_operator_investigation_visits" (
  "investigation_id" text NOT NULL
    REFERENCES "anti_fraud_operator_investigations" ("investigation_id")
    ON DELETE CASCADE,
  "physical_event_id" text NOT NULL,
  "bitrix_user_id" integer NOT NULL,
  "occurred_at" timestamptz NOT NULL,
  "restaurant" text NOT NULL,
  "created_at" timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT "anti_fraud_operator_investigation_visits_pk"
    PRIMARY KEY ("investigation_id", "physical_event_id"),
  CONSTRAINT "anti_fraud_operator_investigation_visits_user_check"
    CHECK ("bitrix_user_id" > 0)
);

CREATE INDEX IF NOT EXISTS "anti_fraud_operator_investigation_visits_user_occurred_idx"
  ON "anti_fraud_operator_investigation_visits" ("bitrix_user_id", "occurred_at" DESC);

CREATE INDEX IF NOT EXISTS "anti_fraud_operator_investigation_visits_investigation_occurred_idx"
  ON "anti_fraud_operator_investigation_visits" ("investigation_id", "occurred_at");
