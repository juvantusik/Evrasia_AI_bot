-- CI DISPOSABLE MARIADB DB ONLY; no real Bitrix users/accounts.
-- This file must be executed AFTER candidate trigger SQL, in the same CI DB.
DELIMITER $$
CREATE PROCEDURE v5_assert_epoch(
  IN p_user INT, IN p_expected BIGINT, IN p_label VARCHAR(96)
)
BEGIN
  DECLARE current_epoch BIGINT DEFAULT NULL;
  SELECT AUTH_EPOCH INTO current_epoch
  FROM ev_samzaberu_auth_state WHERE USER_ID=p_user;
  IF current_epoch IS NULL OR current_epoch <> p_expected THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CI epoch assertion failed';
  END IF;
  SELECT CONCAT('PASS: ', p_label) AS check_result;
END$$
DELIMITER ;

-- ROLLBACK must undo both native phone insertion and AUTH_EPOCH bump.
START TRANSACTION;
INSERT INTO b_user_phone_auth
  (USER_ID, PHONE_NUMBER, CONFIRMED)
VALUES (101, '+79120000001', 'N');
CALL v5_assert_epoch(101,2,'phone INSERT increments inside transaction');
ROLLBACK;
CALL v5_assert_epoch(101,1,'rollback restores epoch');
SELECT IF(COUNT(*)=0,'PASS: rollback removes phone insert',
  'FAIL: rollback failed') FROM b_user_phone_auth WHERE USER_ID=101;

-- Stable failed OTP attempt and send timestamp are NOT revocations.
INSERT INTO b_user_phone_auth
  (USER_ID, PHONE_NUMBER, CONFIRMED)
VALUES (101, '+79120000001', 'N');
CALL v5_assert_epoch(101,2,'phone INSERT after rollback');
UPDATE b_user_phone_auth SET ATTEMPTS=1 WHERE USER_ID=101;
CALL v5_assert_epoch(101,2,'OTP attempts do not revoke session');
UPDATE b_user_phone_auth SET CONFIRMED='Y' WHERE USER_ID=101;
CALL v5_assert_epoch(101,3,'phone confirmation changes epoch');
UPDATE b_user_phone_auth
  SET PHONE_NUMBER='+79120000002' WHERE USER_ID=101;
CALL v5_assert_epoch(101,4,'phone replacement changes epoch');
DELETE FROM b_user_phone_auth WHERE USER_ID=101;
CALL v5_assert_epoch(101,5,'phone DELETE changes epoch');

-- Recovery codes must revoke only on actual code lifecycle events.
INSERT INTO b_sec_recovery_codes
  (ID,USER_ID,CODE,USED) VALUES (1001,101,'ci-hash-1','N');
CALL v5_assert_epoch(101,6,'recovery INSERT changes epoch');
UPDATE b_sec_recovery_codes SET USING_IP='198.51.100.1' WHERE ID=1001;
CALL v5_assert_epoch(101,6,'recovery metadata only does not revoke');
UPDATE b_sec_recovery_codes SET USED='Y' WHERE ID=1001;
CALL v5_assert_epoch(101,7,'recovery redemption changes epoch');
UPDATE b_sec_recovery_codes SET CODE='ci-hash-2' WHERE ID=1001;
CALL v5_assert_epoch(101,8,'recovery code rotation changes epoch');
DELETE FROM b_sec_recovery_codes WHERE ID=1001;
CALL v5_assert_epoch(101,9,'recovery DELETE changes epoch');

-- Cross-user ownership migration must invalidate both users.
INSERT INTO b_user_phone_auth
  (USER_ID,PHONE_NUMBER,CONFIRMED)
VALUES (101,'+79120000003','N');
CALL v5_assert_epoch(101,10,'phone ownership source initial epoch');
UPDATE b_user_phone_auth SET USER_ID=102 WHERE USER_ID=101;
CALL v5_assert_epoch(101,11,'phone ownership source revoked');
CALL v5_assert_epoch(102,2,'phone ownership target revoked');

INSERT INTO b_sec_recovery_codes
  (ID,USER_ID,CODE,USED) VALUES (1002,101,'ci-hash-3','N');
CALL v5_assert_epoch(101,12,'recovery ownership source initial epoch');
UPDATE b_sec_recovery_codes SET USER_ID=102 WHERE ID=1002;
CALL v5_assert_epoch(101,13,'recovery ownership source revoked');
CALL v5_assert_epoch(102,3,'recovery ownership target revoked');

-- An account with no AUTH_EPOCH state is NOT initialized implicitly.
INSERT INTO b_user_phone_auth
  (USER_ID,PHONE_NUMBER,CONFIRMED)
VALUES (103,'+79120000004','Y');
SELECT IF(COUNT(*)=0,'PASS: missing epoch row remains absent',
  'FAIL: missing epoch row implicitly initialized')
FROM ev_samzaberu_auth_state WHERE USER_ID=103;

-- Rollback recovery redemption also rolls back corresponding epoch change.
START TRANSACTION;
UPDATE b_sec_recovery_codes SET USED='Y' WHERE ID=1002;
CALL v5_assert_epoch(102,4,'recovery redemption bump inside transaction');
ROLLBACK;
CALL v5_assert_epoch(102,3,'recovery rollback restores epoch');
SELECT IF(USED='N','PASS: recovery rollback restores USED',
  'FAIL: recovery rollback did not restore USED')
FROM b_sec_recovery_codes WHERE ID=1002;
