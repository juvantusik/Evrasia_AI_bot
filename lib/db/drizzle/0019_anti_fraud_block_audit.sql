-- Добавлено 08.09.2026 ИТ Директор Евразии
-- Внутренний аудит блокировок. Технические признаки и case_id не передаются пользователю/Bitrix.
CREATE TABLE IF NOT EXISTS "anti_fraud_block_audit" (
  "id" bigserial PRIMARY KEY,
  "operation_id" text NOT NULL,
  "case_id" text,
  "bitrix_user_id" integer NOT NULL,
  "source" text NOT NULL,
  "before_active" boolean,
  "before_blocked" boolean,
  "after_active" boolean,
  "after_blocked" boolean,
  "result" text NOT NULL,
  "success" boolean NOT NULL DEFAULT false,
  "created_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS "anti_fraud_block_audit_user_created_idx"
  ON "anti_fraud_block_audit" ("bitrix_user_id", "created_at");

CREATE INDEX IF NOT EXISTS "anti_fraud_block_audit_operation_idx"
  ON "anti_fraud_block_audit" ("operation_id");
