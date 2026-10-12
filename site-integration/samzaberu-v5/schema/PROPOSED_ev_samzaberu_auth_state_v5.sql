-- CANDIDATE SCHEMA ONLY. NEVER EXECUTE ON PRODUCTION.
-- V3C already proposed ev_samzaberu_auth_state with USER_ID/AUTH_EPOCH.
-- This is a compatible INITIAL CREATE proposal, NOT a migration of an
-- existing table and NOT a claim the table exists on any live server.
-- If V3C is first deployed with a different DDL, design a reviewed ALTER
-- after inspecting that deployed schema. Do not create a second epoch table.
--
-- SECURITY PREREQUISITE BEFORE ISSUING ANY V5 ASSURED TOKEN:
-- All native Bitrix account/password/MFA/phone/logout/block/recovery
-- writers must serialize on the b_user per-user lock, update this shared
-- state from the SAME MySQL PDO transaction, and fail atomically if either
-- side fails. Also verify hook coverage and epoch initialization per account.
-- The V5NativeSecurityHookCoveragePort has deliberately NO live provider.

CREATE TABLE ev_samzaberu_auth_state (
    USER_ID BIGINT UNSIGNED NOT NULL,
    AUTH_EPOCH BIGINT UNSIGNED NOT NULL,
    PHONE_EVENT_VERSION BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PHONE_BINDING_HMAC CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (USER_ID),
    CONSTRAINT chk_v5_auth_epoch_positive CHECK (AUTH_EPOCH >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The PHONE_BINDING_HMAC is keyed HMAC of user ID + verified E.164,
-- never the phone itself. PHONE_EVENT_VERSION increments even when an
-- unchanged number is reconfirmed, preventing an old SMS challenge from
-- resuming after a reset. It starts at zero until a trusted confirmation
-- hook records a real event; do NOT backfill 'confirmed' from current text.
-- AUTH_EPOCH must start at a trusted initialized positive value, and be
-- incremented on every security event that can invalidate an existing
-- password proof or strong session. V3C consumes this same integer value.
--
-- Deliberately NOT provided here: INSERT/backfill, native Bitrix hooks,
-- DB credentials, event replay, activation, cleanup, ALTER, DROP, or GRANT.
