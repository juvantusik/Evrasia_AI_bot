-- PROPOSAL ONLY. DO NOT EXECUTE ON EVRASIA/PRODUCTION.
-- Requires approved staging deployment, InnoDB checks, backup, rollback,
-- independent DB credentials, and verified native-Bitrix eligibility adapter.
-- These are entirely NEW V5 tables: no migration of b_user or b_sec_user.
-- No plain phone, SMS code, login, password, IP or device ID stored here.

CREATE TABLE ev_v5_auth_challenges (
    challenge_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    device_binding CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    platform ENUM('ios','android') NOT NULL,
    kind ENUM('sms','totp') NOT NULL,
    status ENUM('pending','consumed','cancelled','expired') NOT NULL,
    password_proven ENUM('Y','N') NOT NULL,
    issued_at BIGINT UNSIGNED NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sms_sent_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sms_sent_at BIGINT UNSIGNED NULL,
    verification_stamp CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    auth_epoch CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sms_code_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    current_delivery_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (challenge_id),
    KEY ix_v5_sms_challenge_expiry (expires_at),
    KEY ix_v5_sms_challenge_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ev_v5_sms_budget_windows (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    used_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket_key),
    KEY ix_v5_sms_budget_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ev_v5_sms_outbox (
    delivery_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    challenge_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    send_ordinal TINYINT UNSIGNED NOT NULL,
    encrypted_envelope MEDIUMBLOB NOT NULL,
    provider_idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    verification_stamp CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    max_price_minor INT UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('queued','claimed','sent','unknown','failed','cancelled') NOT NULL DEFAULT 'queued',
    queued_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (delivery_id),
    UNIQUE KEY uq_v5_sms_provider_key (provider_idempotency_key),
    UNIQUE KEY uq_v5_sms_challenge_ordinal (challenge_id, send_ordinal),
    KEY ix_v5_sms_outbox_status (status, queued_at),
    CONSTRAINT fk_v5_sms_outbox_challenge FOREIGN KEY (challenge_id)
        REFERENCES ev_v5_auth_challenges (challenge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NOT INCLUDED: deployment, SMS worker, confirmation code verification,
-- code expiry maintenance, provider retries, or destructive cleanup.
-- The dispatcher MUST recheck current verified-phone stamp before sending;
-- ambiguous provider outcome without proven idempotency => 'unknown',
-- NEVER an automatic second charge. Production rollback needs an approved plan.
