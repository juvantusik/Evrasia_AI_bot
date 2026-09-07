-- Добавлено 05.09.2026 ИТ Директор Евразии
-- Сохраняем текущее состояние loyalty resolution отдельно от bonus_balance.
-- Это позволяет отличать: нет активной карты, ровно одна активная карта,
-- несколько активных карт и техническую невозможность получить данные.
ALTER TABLE "anti_fraud_accounts"
  ADD COLUMN "loyalty_active_card_count" integer,
  ADD COLUMN "loyalty_issue" text;

ALTER TABLE "anti_fraud_accounts"
  ADD CONSTRAINT "anti_fraud_accounts_loyalty_active_card_count_chk"
  CHECK (
    "loyalty_active_card_count" IS NULL
    OR "loyalty_active_card_count" >= 0
  );

CREATE INDEX "anti_fraud_accounts_loyalty_issue_idx"
  ON "anti_fraud_accounts" ("loyalty_issue");
