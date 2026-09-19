-- Добавлено 19.09.2026 ИТ Директор Евразии
-- Check-in Scout: короткое устойчивое состояние наблюдения за аккаунтами,
-- которые делают 2+ чекина в сутки. Фактические события продолжают храниться
-- в anti_fraud_visits, а эта таблица хранит только состояние наблюдения/эскалации.

CREATE TABLE IF NOT EXISTS "anti_fraud_checkin_watch_state" (
  "bitrix_user_id" integer PRIMARY KEY NOT NULL,
  "status" text NOT NULL DEFAULT 'watching',
  "watch_started_day" date NOT NULL,
  "watch_until_day" date NOT NULL,
  "last_trigger_day" date NOT NULL,
  "trigger_kind" text NOT NULL,
  "last_deep_check_day" date,
  "last_deep_check_count" integer,
  "deep_check_requested_at" timestamptz,
  "deep_check_completed_at" timestamptz,
  "confirmed_at" timestamptz,
  "confirmed_days_2plus_7d" integer NOT NULL DEFAULT 0,
  "confirmed_days_3plus_60d" integer NOT NULL DEFAULT 0,
  "last_seen_at" timestamptz NOT NULL DEFAULT now(),
  "updated_at" timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT "anti_fraud_checkin_watch_status_check"
    CHECK ("status" IN ('watching', 'deep_check', 'confirmed', 'expired')),
  CONSTRAINT "anti_fraud_checkin_watch_trigger_check"
    CHECK ("trigger_kind" IN ('double_checkin', 'triple_checkin', 'repeated_double')),
  CONSTRAINT "anti_fraud_checkin_watch_counts_check"
    CHECK (
      ("last_deep_check_count" IS NULL OR "last_deep_check_count" >= 0)
      AND "confirmed_days_2plus_7d" >= 0
      AND "confirmed_days_3plus_60d" >= 0
    )
);

CREATE INDEX IF NOT EXISTS "anti_fraud_checkin_watch_status_idx"
  ON "anti_fraud_checkin_watch_state" ("status", "watch_until_day");

CREATE INDEX IF NOT EXISTS "anti_fraud_checkin_watch_updated_idx"
  ON "anti_fraud_checkin_watch_state" ("updated_at" DESC);
