-- Добавлено 03.09.2026 ИТ Директор Евразии
CREATE TABLE "anti_fraud_risk_scores" (
  "bitrix_user_id" integer PRIMARY KEY NOT NULL,
  "calculation_version" text DEFAULT 'v1' NOT NULL,
  "device_risk" integer DEFAULT 0 NOT NULL,
  "linked_account_risk" integer DEFAULT 0 NOT NULL,
  "identity_similarity_risk" integer DEFAULT 0 NOT NULL,
  "visit_behavior_risk" integer DEFAULT 0 NOT NULL,
  "historical_behavior_risk" integer DEFAULT 0 NOT NULL,
  "overall_risk" integer DEFAULT 0 NOT NULL,
  "risk_level" text NOT NULL,
  "history_gate" boolean DEFAULT false NOT NULL,
  "history_enriched" boolean DEFAULT false NOT NULL,
  "computed_at" timestamp with time zone DEFAULT now() NOT NULL,
  CONSTRAINT "anti_fraud_risk_scores_device_chk" CHECK ("device_risk" BETWEEN 0 AND 100),
  CONSTRAINT "anti_fraud_risk_scores_linked_chk" CHECK ("linked_account_risk" BETWEEN 0 AND 100),
  CONSTRAINT "anti_fraud_risk_scores_identity_chk" CHECK ("identity_similarity_risk" BETWEEN 0 AND 100),
  CONSTRAINT "anti_fraud_risk_scores_visit_chk" CHECK ("visit_behavior_risk" BETWEEN 0 AND 100),
  CONSTRAINT "anti_fraud_risk_scores_historical_chk" CHECK ("historical_behavior_risk" BETWEEN 0 AND 100),
  CONSTRAINT "anti_fraud_risk_scores_overall_chk" CHECK ("overall_risk" BETWEEN 0 AND 100),
  CONSTRAINT "anti_fraud_risk_scores_level_chk" CHECK ("risk_level" IN ('low','medium','high','critical'))
);
--> statement-breakpoint
CREATE INDEX "anti_fraud_risk_scores_overall_idx"
  ON "anti_fraud_risk_scores" ("overall_risk" DESC, "computed_at" DESC);
--> statement-breakpoint
CREATE INDEX "anti_fraud_risk_scores_history_gate_idx"
  ON "anti_fraud_risk_scores" ("history_gate", "overall_risk" DESC);
--> statement-breakpoint
CREATE TABLE "anti_fraud_risk_reasons" (
  "id" serial PRIMARY KEY NOT NULL,
  "bitrix_user_id" integer NOT NULL,
  "reason_code" text NOT NULL,
  "score" integer NOT NULL,
  "details" text NOT NULL,
  "computed_at" timestamp with time zone DEFAULT now() NOT NULL,
  CONSTRAINT "anti_fraud_risk_reasons_score_chk" CHECK ("score" BETWEEN 0 AND 100)
);
--> statement-breakpoint
CREATE UNIQUE INDEX "anti_fraud_risk_reasons_user_code_uidx"
  ON "anti_fraud_risk_reasons" ("bitrix_user_id", "reason_code");
--> statement-breakpoint
CREATE INDEX "anti_fraud_risk_reasons_code_idx"
  ON "anti_fraud_risk_reasons" ("reason_code", "score" DESC);
--> statement-breakpoint
COMMENT ON TABLE "anti_fraud_risk_scores" IS
  'Latest explainable Anti-Fraud risk snapshot. v1.7 is advisory only and never blocks a Bitrix account automatically.';
--> statement-breakpoint
COMMENT ON TABLE "anti_fraud_risk_reasons" IS
  'Aggregated reason codes behind the latest Anti-Fraud score; details must not contain phone, email, card number or full device hash.';
