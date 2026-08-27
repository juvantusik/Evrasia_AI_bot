CREATE TABLE "bot_users" (
  "telegram_user_id" text PRIMARY KEY NOT NULL,
  "username" text,
  "first_name" text,
  "last_name" text,
  "is_blocked" boolean DEFAULT false NOT NULL,
  "created_at" timestamp with time zone DEFAULT now() NOT NULL,
  "updated_at" timestamp with time zone DEFAULT now() NOT NULL,
  "last_seen_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE "bot_module_access" (
  "telegram_user_id" text NOT NULL,
  "module_key" text NOT NULL,
  "enabled" boolean DEFAULT true NOT NULL,
  "granted_by" text,
  "granted_at" timestamp with time zone DEFAULT now() NOT NULL,
  "updated_at" timestamp with time zone DEFAULT now() NOT NULL,
  CONSTRAINT "bot_module_access_telegram_user_id_module_key_pk" PRIMARY KEY("telegram_user_id","module_key")
);
--> statement-breakpoint
CREATE TABLE "bot_access_audit" (
  "id" text PRIMARY KEY NOT NULL,
  "action" text NOT NULL,
  "admin_telegram_user_id" text NOT NULL,
  "target_telegram_user_id" text NOT NULL,
  "module_key" text NOT NULL,
  "created_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE "corporate_phone_directory" (
  "phone" text PRIMARY KEY NOT NULL,
  "operator" text NOT NULL,
  "legal_entity" text NOT NULL,
  "inn" text NOT NULL,
  "account_number" text NOT NULL,
  "restaurant_name" text,
  "active" boolean DEFAULT true NOT NULL,
  "updated_at" timestamp with time zone DEFAULT now() NOT NULL
);
