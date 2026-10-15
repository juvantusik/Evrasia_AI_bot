-- PRODUCTION CANDIDATE ONLY. DO NOT EXECUTE WITHOUT EXPLICIT APPROVAL.
-- Derived after read-only metadata inspection of eurasia_new on 2026-10-15.
-- V5 remains OFF after this DDL. No backfill, hooks, credentials, GRANT or activation.
-- Exactly six NEW tables. No ALTER of Bitrix/V4 tables and no native_tx_probe table.
-- Deliberately no IF NOT EXISTS: unexpected/partial state must stop the migration.

CREATE TABLE ev_samzaberu_auth_state (
    USER_ID BIGINT UNSIGNED NOT NULL,
    AUTH_EPOCH BIGINT UNSIGNED NOT NULL,
    PHONE_EVENT_VERSION BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PHONE_BINDING_HMAC CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (USER_ID),
    CONSTRAINT chk_v5_auth_epoch_positive CHECK (AUTH_EPOCH >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ev_v5_auth_attempt_windows (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    window_expires_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket_key),
    KEY ix_v5_auth_attempt_expiry (window_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE ev_v5_strong_sessions (
    sid CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    auth_epoch BIGINT UNSIGNED NOT NULL,
    assurance ENUM('totp','sms','trusted') NOT NULL,
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

-- STOP HERE. Do not initialize AUTH_EPOCH rows in this migration.
-- V5SecurityEventVersions fails closed when a user row is absent.
-- Per-user initialization must be designed together with fully installed
-- native password/MFA/phone/logout/block/recovery hooks and their lock order.
