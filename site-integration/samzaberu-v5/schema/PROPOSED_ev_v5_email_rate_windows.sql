-- PROPOSAL ONLY: do not execute without separately approved same-database
-- eurasia_new migration, existing-schema check, backup and rollback plan.
-- NEVER create any isolated/test DB or synthetic user. V5 AUTH remains OFF.
-- HMAC keys hide email/phone/ip/device/user identifiers.
CREATE TABLE ev_v5_email_rate_windows (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    used_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket_key),
    KEY idx_v5_email_rate_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
