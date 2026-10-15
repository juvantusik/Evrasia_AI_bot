-- Evrasia V5: metadata only, in an ALREADY authenticated SQL console.
-- Confirm the connection is the existing evrasia / eurasia_new server.
-- This file does NOT connect, read credentials or change transaction state.
-- No USE, SET, BEGIN/COMMIT/ROLLBACK, DDL or application-table SELECT.
-- A missing result means NOT VISIBLE to this account, not proven ABSENT.
-- Separate result sets are not claimed to be one atomic schema snapshot.

SELECT DATABASE() AS selected_database, VERSION() AS database_version;

SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'eurasia_new'
  AND TABLE_NAME IN (
    'b_user', 'b_sec_user', 'b_user_phone_auth', 'ev_trusted_devices',
    'ev_samzaberu_auth_state', 'ev_v5_auth_attempt_windows',
    'ev_v5_auth_challenges', 'ev_v5_sms_budget_windows',
    'ev_v5_sms_outbox', 'ev_v5_strong_sessions'
  )
ORDER BY TABLE_NAME
LIMIT 11;

SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, DATA_TYPE, IS_NULLABLE,
       CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
       CHARACTER_SET_NAME, COLLATION_NAME,
       (LOCATE('unsigned', COLUMN_TYPE) > 0) AS IS_UNSIGNED,
       (LOCATE('auto_increment', EXTRA) > 0) AS IS_AUTO_INCREMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'eurasia_new'
  AND TABLE_NAME IN (
    'b_user', 'b_sec_user', 'b_user_phone_auth', 'ev_trusted_devices',
    'ev_samzaberu_auth_state', 'ev_v5_auth_attempt_windows',
    'ev_v5_auth_challenges', 'ev_v5_sms_budget_windows',
    'ev_v5_sms_outbox', 'ev_v5_strong_sessions'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION
LIMIT 513;

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX,
       COLUMN_NAME, SUB_PART, INDEX_TYPE, COLLATION
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'eurasia_new'
  AND TABLE_NAME IN (
    'b_user', 'b_sec_user', 'b_user_phone_auth', 'ev_trusted_devices',
    'ev_samzaberu_auth_state', 'ev_v5_auth_attempt_windows',
    'ev_v5_auth_challenges', 'ev_v5_sms_budget_windows',
    'ev_v5_sms_outbox', 'ev_v5_strong_sessions'
  )
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
LIMIT 513;

SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE
FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = 'eurasia_new'
  AND TABLE_NAME IN (
    'b_user', 'b_sec_user', 'b_user_phone_auth', 'ev_trusted_devices',
    'ev_samzaberu_auth_state', 'ev_v5_auth_attempt_windows',
    'ev_v5_auth_challenges', 'ev_v5_sms_budget_windows',
    'ev_v5_sms_outbox', 'ev_v5_strong_sessions'
  )
ORDER BY TABLE_NAME, CONSTRAINT_NAME
LIMIT 129;
