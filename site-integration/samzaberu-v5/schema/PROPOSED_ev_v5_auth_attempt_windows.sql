-- CANDIDATE ONLY - NOT APPROVED OR DEPLOYED TO production/staging.
-- This is a NEW table, not an assertion that such a production table exists.
-- Must undergo backup, staging concurrency tests, grants and rollback review.
-- All bucket keys are server-side HMAC-SHA256 hex; no plain login, IP, phone,
-- device ID, password or token may be recorded in this table.
CREATE TABLE ev_v5_auth_attempt_windows (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    window_expires_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket_key),
    KEY ix_v5_auth_attempt_expiry (window_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A maintenance job (NOT provided/enabled here) will need an approved,
-- bounded removal strategy for expired rows. Do not run any DELETE on
-- production until target, retention, rollback, and back-up are reviewed.
