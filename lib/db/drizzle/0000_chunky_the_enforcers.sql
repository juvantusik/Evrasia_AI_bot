CREATE TABLE IF NOT EXISTS "samzaberu_requests" (
	"id" text PRIMARY KEY NOT NULL,
	"status" text NOT NULL,
	"action" text NOT NULL,
	"restaurant_id" text NOT NULL,
	"restaurant_name" text NOT NULL,
	"operator_id" text NOT NULL,
	"operator_name" text NOT NULL,
	"requested_at" timestamp with time zone DEFAULT now() NOT NULL,
	"target_until" timestamp with time zone,
	"reason" text,
	"message" text NOT NULL,
	"retry_count" integer DEFAULT 0 NOT NULL,
	"escalated_to" text[] DEFAULT '{}' NOT NULL,
	"can_complete_manually" boolean DEFAULT false NOT NULL
);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "samzaberu_rules" (
	"restaurant_id" text PRIMARY KEY NOT NULL,
	"rule_id" text NOT NULL,
	"active" boolean DEFAULT true NOT NULL,
	"starts_at" timestamp with time zone NOT NULL,
	"ends_at" timestamp with time zone NOT NULL,
	"notification_type" text NOT NULL,
	"updated_at" timestamp with time zone DEFAULT now() NOT NULL
);
