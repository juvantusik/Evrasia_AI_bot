ALTER TABLE "corporate_legal_entities" ADD COLUMN IF NOT EXISTS "full_name" text;
--> statement-breakpoint
ALTER TABLE "corporate_legal_entities" ADD COLUMN IF NOT EXISTS "kpp" text;
--> statement-breakpoint
ALTER TABLE "corporate_legal_entities" ADD COLUMN IF NOT EXISTS "ogrn" text;
--> statement-breakpoint
ALTER TABLE "corporate_legal_entities" ADD COLUMN IF NOT EXISTS "legal_address" text;
--> statement-breakpoint
ALTER TABLE "corporate_legal_entities" ADD COLUMN IF NOT EXISTS "actual_address" text;
--> statement-breakpoint
ALTER TABLE "corporate_legal_entities" ADD COLUMN IF NOT EXISTS "postal_address" text;
--> statement-breakpoint
ALTER TABLE "corporate_legal_entities" ADD COLUMN IF NOT EXISTS "general_director" text;
--> statement-breakpoint
ALTER TABLE "corporate_legal_entities" ADD COLUMN IF NOT EXISTS "source" text;
--> statement-breakpoint
ALTER TABLE "corporate_legal_entities" ADD COLUMN IF NOT EXISTS "verification_status" text DEFAULT 'UNVERIFIED' NOT NULL;
--> statement-breakpoint
ALTER TABLE "corporate_legal_entities" ADD COLUMN IF NOT EXISTS "notes" text;
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "corporate_legal_entities_inn_idx" ON "corporate_legal_entities" ("inn");
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "corporate_legal_entities_ogrn_idx" ON "corporate_legal_entities" ("ogrn");
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "corporate_legal_entity_bank_accounts" (
  "id" text PRIMARY KEY NOT NULL,
  "legal_entity_id" text NOT NULL,
  "bank_name" text,
  "bik" text,
  "account_number" text NOT NULL,
  "correspondent_account" text,
  "is_primary" boolean DEFAULT false NOT NULL,
  "active" boolean DEFAULT true NOT NULL,
  "source" text,
  "verification_status" text DEFAULT 'UNVERIFIED' NOT NULL,
  "created_at" timestamp with time zone DEFAULT now() NOT NULL,
  "updated_at" timestamp with time zone DEFAULT now() NOT NULL,
  CONSTRAINT "corporate_legal_entity_bank_accounts_legal_entity_fk"
    FOREIGN KEY ("legal_entity_id") REFERENCES "corporate_legal_entities"("id") ON DELETE RESTRICT
);
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "corporate_legal_entity_bank_accounts_entity_idx"
  ON "corporate_legal_entity_bank_accounts" ("legal_entity_id");
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "corporate_legal_entity_operator_accounts" (
  "id" text PRIMARY KEY NOT NULL,
  "legal_entity_id" text NOT NULL,
  "operator" text NOT NULL,
  "account_number" text NOT NULL,
  "contract_number" text,
  "is_primary" boolean DEFAULT false NOT NULL,
  "active" boolean DEFAULT true NOT NULL,
  "source" text,
  "verification_status" text DEFAULT 'UNVERIFIED' NOT NULL,
  "created_at" timestamp with time zone DEFAULT now() NOT NULL,
  "updated_at" timestamp with time zone DEFAULT now() NOT NULL,
  CONSTRAINT "corporate_legal_entity_operator_accounts_legal_entity_fk"
    FOREIGN KEY ("legal_entity_id") REFERENCES "corporate_legal_entities"("id") ON DELETE RESTRICT
);
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "corporate_legal_entity_operator_accounts_entity_idx"
  ON "corporate_legal_entity_operator_accounts" ("legal_entity_id", "operator");
--> statement-breakpoint
ALTER TABLE "corporate_phone_directory" ADD COLUMN IF NOT EXISTS "legal_entity_id" text;
--> statement-breakpoint
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'corporate_phone_directory_legal_entity_fk'
  ) THEN
    ALTER TABLE "corporate_phone_directory"
      ADD CONSTRAINT "corporate_phone_directory_legal_entity_fk"
      FOREIGN KEY ("legal_entity_id") REFERENCES "corporate_legal_entities"("id") ON DELETE RESTRICT;
  END IF;
END $$;
--> statement-breakpoint
UPDATE "corporate_phone_directory" p
SET "legal_entity_id" = le."id"
FROM "corporate_legal_entities" le
WHERE p."legal_entity_id" IS NULL
  AND lower(trim(p."legal_entity")) = lower(trim(le."name"))
  AND p."inn" IS NOT DISTINCT FROM le."inn";
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "corporate_directory_restaurants" (
  "id" text PRIMARY KEY NOT NULL,
  "number" integer NOT NULL,
  "ou" text NOT NULL,
  "legal_entity" text NOT NULL,
  "legal_entity_id" text,
  "address" text NOT NULL,
  "actual_director" text NOT NULL DEFAULT '',
  "actual_phone_mode" text NOT NULL DEFAULT 'AUTO',
  "actual_personal_phone" text NOT NULL DEFAULT '',
  "general_director" text NOT NULL DEFAULT '',
  "general_phone_mode" text NOT NULL DEFAULT 'AUTO',
  "general_personal_phone" text NOT NULL DEFAULT '',
  "email" text NOT NULL DEFAULT '',
  "active" boolean NOT NULL DEFAULT true,
  "created_at" timestamp with time zone DEFAULT now() NOT NULL,
  "updated_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
ALTER TABLE "corporate_directory_restaurants" ADD COLUMN IF NOT EXISTS "legal_entity_id" text;
--> statement-breakpoint
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'corporate_directory_restaurants_legal_entity_fk'
  ) THEN
    ALTER TABLE "corporate_directory_restaurants"
      ADD CONSTRAINT "corporate_directory_restaurants_legal_entity_fk"
      FOREIGN KEY ("legal_entity_id") REFERENCES "corporate_legal_entities"("id") ON DELETE RESTRICT;
  END IF;
END $$;
--> statement-breakpoint
WITH unambiguous AS (
  SELECT lower(trim("name")) AS normalized_name, min("id") AS id
  FROM "corporate_legal_entities"
  WHERE "active" = true
  GROUP BY lower(trim("name"))
  HAVING count(*) = 1
)
UPDATE "corporate_directory_restaurants" r
SET "legal_entity_id" = u.id
FROM unambiguous u
WHERE r."legal_entity_id" IS NULL
  AND lower(trim(r."legal_entity")) = u.normalized_name;
