-- PROPOSED ONLY: not applied, not automatically approved or executable.
-- For the EXISTING production eurasia_new database ONLY, subject to a separate
-- express owner approval, verified backup, guarded execution and rollback plan.
-- Do not run from GitHub CI, test databases or public HTTP endpoints.
-- Separate mobile V5 email OTP ledger; never reuse website PHP sessions.
-- Existing b_user, ev_samzaberu_auth_state, ev_web_email_ownership_proof
-- MUST already be InnoDB. No existing table or API V4 may be altered.
-- No plaintext address, OTP, password or device id is persisted.
CREATE TABLE ev_v5_email_auth_challenges (
    challenge_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    device_binding CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    platform VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    email_stamp CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    auth_epoch BIGINT UNSIGNED NOT NULL,
    code_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    issued_at BIGINT UNSIGNED NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (challenge_id),
    KEY idx_account_status_expiry (user_id, status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
