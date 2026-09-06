-- Добавлено 06.09.2026 ИТ Директор Евразии
-- Scheduler имеет штатный статус partial, когда отдельные protected-source стадии
-- завершились ошибкой, но остальные стадии цикла были выполнены. Ограничение из 0005
-- должно соответствовать этому контракту приложения.
ALTER TABLE "anti_fraud_sync_runs"
  DROP CONSTRAINT IF EXISTS "anti_fraud_sync_runs_status_chk";
--> statement-breakpoint
ALTER TABLE "anti_fraud_sync_runs"
  ADD CONSTRAINT "anti_fraud_sync_runs_status_chk"
  CHECK ("status" IN ('running', 'success', 'partial', 'failed'));
