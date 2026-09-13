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
DROP TABLE IF EXISTS "_v18_legal_entity_alias_map";
--> statement-breakpoint
CREATE TABLE "_v18_legal_entity_alias_map" AS
WITH duplicate_inns AS (
  SELECT "inn"
  FROM "corporate_legal_entities"
  WHERE "inn" IS NOT NULL
    AND trim("inn") <> ''
  GROUP BY "inn"
  HAVING count(*) > 1
),
ranked AS (
  SELECT
    le.*,
    row_number() OVER (
      PARTITION BY le."inn"
      ORDER BY
        CASE WHEN le."name" ~ '^ООО "' THEN 0 ELSE 1 END,
        CASE WHEN le."name" ~ '^ООО " ' THEN 1 ELSE 0 END,
        CASE WHEN le."name" LIKE '%  %' THEN 1 ELSE 0 END,
        CASE WHEN le."name" LIKE '% - %' THEN 1 ELSE 0 END,
        CASE WHEN le."name" LIKE '%«%' OR le."name" LIKE '%»%' THEN 1 ELSE 0 END,
        length(le."name"),
        le."id"
    ) AS rn
  FROM "corporate_legal_entities" le
  JOIN duplicate_inns d ON d."inn" = le."inn"
),
survivors AS (
  SELECT
    "inn",
    "id" AS survivor_id,
    "name" AS survivor_name
  FROM ranked
  WHERE rn = 1
)
SELECT
  r."inn",
  r."id" AS alias_id,
  r."name" AS alias_name,
  s.survivor_id,
  s.survivor_name,
  (r."id" = s.survivor_id) AS is_survivor
FROM ranked r
JOIN survivors s ON s."inn" = r."inn";
--> statement-breakpoint
DELETE FROM "corporate_legal_entities" le
USING "_v18_legal_entity_alias_map" m
WHERE le."id" = m.alias_id
  AND m.is_survivor = false;
--> statement-breakpoint
CREATE UNIQUE INDEX IF NOT EXISTS "corporate_legal_entities_inn_unique_idx"
  ON "corporate_legal_entities" ("inn")
  WHERE "inn" IS NOT NULL AND trim("inn") <> '';
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "corporate_legal_entities_ogrn_idx"
  ON "corporate_legal_entities" ("ogrn");
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
UPDATE "corporate_phone_directory" p
SET "legal_entity_id" = le."id"
FROM "corporate_legal_entities" le
WHERE p."legal_entity_id" IS NULL
  AND p."inn" IS NOT NULL
  AND trim(p."inn") <> ''
  AND p."inn" = le."inn"
  AND le."active" = true;
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
UPDATE "corporate_directory_restaurants" r
SET "legal_entity_id" = m.survivor_id
FROM "_v18_legal_entity_alias_map" m
WHERE r."legal_entity_id" IS NULL
  AND lower(trim(r."legal_entity")) = lower(trim(m.alias_name));
--> statement-breakpoint
WITH normalized_entities AS (
  SELECT
    regexp_replace(
      lower("name"),
      '[[:space:]"«»(),.\-]+',
      '',
      'g'
    ) AS normalized_name,
    min("id") AS id
  FROM "corporate_legal_entities"
  WHERE "active" = true
  GROUP BY regexp_replace(
    lower("name"),
    '[[:space:]"«»(),.\-]+',
    '',
    'g'
  )
  HAVING count(*) = 1
)
UPDATE "corporate_directory_restaurants" r
SET "legal_entity_id" = n.id
FROM normalized_entities n
WHERE r."legal_entity_id" IS NULL
  AND regexp_replace(
    lower(r."legal_entity"),
    '[[:space:]"«»(),.\-]+',
    '',
    'g'
  ) = n.normalized_name;
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
DROP TABLE IF EXISTS "_v18_legal_entity_alias_map";
