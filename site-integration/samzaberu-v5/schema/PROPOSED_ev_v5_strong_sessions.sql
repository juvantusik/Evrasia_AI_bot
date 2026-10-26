-- PROPOSAL ONLY. NO AUTOMATIC MIGRATION. NEVER EXECUTE ON PRODUCTION.
-- Requires separately attested Bitrix/MySQL staging and reviewed transaction
-- compatibility, backups, grants, InnoDB engine and rollback plan.
-- Keeps the SINGLE existing proposed ev_samzaberu_auth_state AUTH_EPOCH authority.
-- No raw refresh bearer, password, phone, SMS code, device ID or trust token.

CREATE TABLE ev_v5_strong_sessions (
    sid CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    auth_epoch BIGINT UNSIGNED NOT NULL,
    assurance ENUM('totp','sms','trusted','email') NOT NULL,
    device_binding CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    platform ENUM('ios','android') NOT NULL,
    assured_at BIGINT UNSIGNED NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    status ENUM('active','revoked') NOT NULL,
    refresh_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    refresh_generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
    grant_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at BIGINT UNSIGNED NOT NULL,
    updated_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (sid),
    UNIQUE KEY uq_v5_session_refresh_digest (refresh_digest),
    UNIQUE KEY uq_v5_session_consumed_grant (grant_digest),
    KEY ix_v5_session_user_status (user_id, status),
    KEY ix_v5_session_expiry (expires_at),
    CONSTRAINT chk_v5_strong_session_epoch CHECK (auth_epoch >= 1),
    CONSTRAINT chk_v5_strong_session_generation CHECK (refresh_generation >= 1),
    CONSTRAINT chk_v5_strong_session_lifetime CHECK (expires_at > assured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- grant_digest is HMAC-only and is NULL for a new, freshly verified strong-trust
-- login. A consumed TOTP/SMS grant is mapped to a stable one-time HMAC digest;
-- the UNIQUE constraint prevents reusing the same grant to mint more sessions.
-- Refresh rotation updates a SINGLE locked row by SID, old digest, generation,
-- epoch and active status (affected rows MUST equal one). Session expiry never
-- extends automatically on refresh. One-time refresh cannot be assumed without
-- real MySQL concurrent staging tests.
--
-- Staging wiring prerequisites (none exists in production):
-- * b_user row lock first, mandatory independently proven native hook coverage,
--   site proof recheck, shared ev_samzaberu_auth_state epoch validation.
-- * Consumed challenge must be a durable, server-only, one-time record and
--   locked proof provider must verify it; trust must be revalidated under lock.
-- * Begin/commit/rollback must own the same native Bitrix/MySQL transaction.
-- * V5 JWT keys and refresh digest key must be independent of V4 and each other.
-- * HTTP router continues to deny all requests with 503 until full integration.
