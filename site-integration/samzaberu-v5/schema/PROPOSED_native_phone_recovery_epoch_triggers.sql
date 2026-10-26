-- V5 CANDIDATE ONLY, NEVER RUN AGAINST PRODUCTION OR SHARED V4.
-- Operator-confirmed production 2026-10-26:
--   b_user_phone_auth: USER_ID, PHONE_NUMBER, OTP_SECRET, ATTEMPTS,
--                      CONFIRMED, DATE_SENT; native triggers=0.
--   b_sec_recovery_codes: ID, USER_ID, CODE, USED, USING_DATE, USING_IP;
--                         native triggers=0.
--
-- These are additive V5-only AUTH_EPOCH bookkeeping proposals for a
-- DISPOSABLE InnoDB schema. They do not create/modify Bitrix accounts.
-- They bump only an ALREADY INITIALIZED ev_samzaberu_auth_state row.
-- No missing row initialization, no phone HMAC/version repair.
--
-- Crucial: this candidate DOES NOT prove same-number reconfirmation without
-- a changed PHONE_NUMBER/CONFIRMED field. That native event needs its own
-- observed, same-transaction owner hook, separately from these triggers.
-- Logout is per V5 session, NOT a global browser->mobile epoch bump.
--
-- On real deployment these triggers could affect existing V4 phone/recovery
-- writes and locking; require real Bitrix staging transaction/race evidence,
-- verified source hashes and a reviewed rollback before any installation.

DELIMITER $$

CREATE TRIGGER ev_v5_phone_epoch_ai
AFTER INSERT ON b_user_phone_auth
FOR EACH ROW
BEGIN
    UPDATE ev_samzaberu_auth_state
       SET AUTH_EPOCH = AUTH_EPOCH + 1
     WHERE USER_ID = NEW.USER_ID
       AND AUTH_EPOCH >= 1 AND AUTH_EPOCH < 9223372036854775806;
END$$

CREATE TRIGGER ev_v5_phone_epoch_au
AFTER UPDATE ON b_user_phone_auth
FOR EACH ROW
BEGIN
    IF NOT (OLD.PHONE_NUMBER <=> NEW.PHONE_NUMBER)
       OR NOT (OLD.CONFIRMED <=> NEW.CONFIRMED)
       OR NOT (OLD.USER_ID <=> NEW.USER_ID)
    THEN
        UPDATE ev_samzaberu_auth_state
           SET AUTH_EPOCH = AUTH_EPOCH + 1
         WHERE USER_ID = OLD.USER_ID
           AND AUTH_EPOCH >= 1 AND AUTH_EPOCH < 9223372036854775806;
        IF OLD.USER_ID <> NEW.USER_ID THEN
            UPDATE ev_samzaberu_auth_state
               SET AUTH_EPOCH = AUTH_EPOCH + 1
             WHERE USER_ID = NEW.USER_ID
               AND AUTH_EPOCH >= 1 AND AUTH_EPOCH < 9223372036854775806;
        END IF;
    END IF;
END$$

CREATE TRIGGER ev_v5_phone_epoch_ad
AFTER DELETE ON b_user_phone_auth
FOR EACH ROW
BEGIN
    UPDATE ev_samzaberu_auth_state
       SET AUTH_EPOCH = AUTH_EPOCH + 1
     WHERE USER_ID = OLD.USER_ID
       AND AUTH_EPOCH >= 1 AND AUTH_EPOCH < 9223372036854775806;
END$$

CREATE TRIGGER ev_v5_recovery_epoch_ai
AFTER INSERT ON b_sec_recovery_codes
FOR EACH ROW
BEGIN
    UPDATE ev_samzaberu_auth_state
       SET AUTH_EPOCH = AUTH_EPOCH + 1
     WHERE USER_ID = NEW.USER_ID
       AND AUTH_EPOCH >= 1 AND AUTH_EPOCH < 9223372036854775806;
END$$

CREATE TRIGGER ev_v5_recovery_epoch_au
AFTER UPDATE ON b_sec_recovery_codes
FOR EACH ROW
BEGIN
    IF NOT (OLD.USED <=> NEW.USED)
       OR NOT (OLD.CODE <=> NEW.CODE)
       OR NOT (OLD.USER_ID <=> NEW.USER_ID)
    THEN
        UPDATE ev_samzaberu_auth_state
           SET AUTH_EPOCH = AUTH_EPOCH + 1
         WHERE USER_ID = OLD.USER_ID
           AND AUTH_EPOCH >= 1 AND AUTH_EPOCH < 9223372036854775806;
        IF OLD.USER_ID <> NEW.USER_ID THEN
            UPDATE ev_samzaberu_auth_state
               SET AUTH_EPOCH = AUTH_EPOCH + 1
             WHERE USER_ID = NEW.USER_ID
               AND AUTH_EPOCH >= 1 AND AUTH_EPOCH < 9223372036854775806;
        END IF;
    END IF;
END$$

CREATE TRIGGER ev_v5_recovery_epoch_ad
AFTER DELETE ON b_sec_recovery_codes
FOR EACH ROW
BEGIN
    UPDATE ev_samzaberu_auth_state
       SET AUTH_EPOCH = AUTH_EPOCH + 1
     WHERE USER_ID = OLD.USER_ID
       AND AUTH_EPOCH >= 1 AND AUTH_EPOCH < 9223372036854775806;
END$$

DELIMITER ;
