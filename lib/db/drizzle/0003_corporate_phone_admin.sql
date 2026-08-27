ALTER TABLE "corporate_phone_directory" ADD COLUMN IF NOT EXISTS "id" text;
--> statement-breakpoint
UPDATE "corporate_phone_directory" SET "id" = "phone" WHERE "id" IS NULL;
--> statement-breakpoint
ALTER TABLE "corporate_phone_directory" ALTER COLUMN "id" SET NOT NULL;
--> statement-breakpoint
ALTER TABLE "corporate_phone_directory" DROP CONSTRAINT IF EXISTS "corporate_phone_directory_pkey";
--> statement-breakpoint
ALTER TABLE "corporate_phone_directory" ADD CONSTRAINT "corporate_phone_directory_pkey" PRIMARY KEY("id");
--> statement-breakpoint
ALTER TABLE "corporate_phone_directory" ALTER COLUMN "inn" DROP NOT NULL;
--> statement-breakpoint
ALTER TABLE "corporate_phone_directory" ALTER COLUMN "account_number" DROP NOT NULL;
--> statement-breakpoint
ALTER TABLE "corporate_phone_directory" ADD COLUMN IF NOT EXISTS "line_type" text;
--> statement-breakpoint
ALTER TABLE "corporate_phone_directory" ADD COLUMN IF NOT EXISTS "subscriber_name" text;
--> statement-breakpoint
ALTER TABLE "corporate_phone_directory" ADD COLUMN IF NOT EXISTS "created_at" timestamp with time zone DEFAULT now() NOT NULL;
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "corporate_legal_entities" (
  "id" text PRIMARY KEY NOT NULL,
  "name" text NOT NULL,
  "inn" text,
  "active" boolean DEFAULT true NOT NULL,
  "created_at" timestamp with time zone DEFAULT now() NOT NULL,
  "updated_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "corporate_phone_audit" (
  "id" text PRIMARY KEY NOT NULL,
  "action" text NOT NULL,
  "admin_telegram_user_id" text NOT NULL,
  "phone_record_id" text,
  "phone" text NOT NULL,
  "before_state" text,
  "after_state" text,
  "created_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "corporate_phone_directory_phone_idx" ON "corporate_phone_directory" ("phone");
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "corporate_phone_directory_subscriber_idx" ON "corporate_phone_directory" ("subscriber_name");
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "corporate_legal_entities_name_idx" ON "corporate_legal_entities" ("name");
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "corporate_phone_audit_phone_idx" ON "corporate_phone_audit" ("phone");
