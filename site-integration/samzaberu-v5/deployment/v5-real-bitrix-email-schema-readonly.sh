#!/usr/bin/env bash
# Real Evrasia only: read-only information_schema contract for V5 email OTP.
# NO isolated database, synthetic users, runtime bootstrap or write.
set -Eeuo pipefail
umask 077

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
BITRIX_USER="$SITE/bitrix/modules/main/classes/general/user.php"
USER_SHA='bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99'
DB='eurasia_new'

finish() {
    local rc=$?
    trap - EXIT
    echo
    echo '=== V5 REAL BITRIX READONLY RESULT ==='
    if ((rc==0)); then
        echo 'FINAL_STATUS=PASS'
    else
        echo 'FINAL_STATUS=FAIL'
    fi
    echo "FINAL_RC=$rc"
    echo 'MODE=REAL_PRODUCTION_SCHEMA_READ_ONLY'
    echo 'ISOLATED_DATABASE=NO'
    echo 'SYNTHETIC_ACCOUNTS=NO'
    echo 'USER_RECORDS_FETCHED=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'DDL_EXECUTED=NO'
    echo 'BITRIX_BOOTSTRAP=NO'
    echo 'MAIL_SENT=NO'
    echo 'SMS_SENT=NO'
    echo 'WEBSITE_CODE_WRITE=NO'
    echo 'API_V4_CHANGED=NO'
    echo 'API_V5_PRODUCTION_CHANGED=NO'
    exit "$rc"
}
trap finish EXIT

echo '=== 1. EXACT REAL HOST AND BITRIX SOURCE ==='
[[ "$(hostname -s)" == evrasia ]]
[[ "$(id -u)" -eq 0 ]]
[[ -f "$BITRIX_USER" && ! -L "$BITRIX_USER" && -r "$BITRIX_USER" ]]
printf '%s  %s\n' "$USER_SHA" "$BITRIX_USER" | sha256sum --check --status
echo 'HOST_AND_BITRIX_SOURCE=PASS'

if command -v mariadb >/dev/null 2>&1; then
    CLIENT="$(command -v mariadb)"
else
    CLIENT="$(command -v mysql)"
fi

sql() {
    "$CLIENT" --protocol=socket --user=root --database="$DB" \
      --batch --skip-column-names --execute="$1"
}

DATABASE="$(sql 'SELECT DATABASE();')"
[[ "$DATABASE" == "$DB" ]]
echo 'REAL_DATABASE_IDENTITY=PASS'

echo '=== 2. EXISTING REAL INNODB TABLES ONLY ==='
sql "
SELECT CONCAT('TABLE=',TABLE_NAME,' ENGINE=',COALESCE(ENGINE,'NULL'))
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (
 'b_user','b_sec_user','ev_samzaberu_auth_state',
 'ev_web_email_ownership_proof','ev_v5_email_auth_challenges',
 'ev_v5_auth_challenges','ev_v5_sms_outbox','ev_v5_strong_sessions'
)
ORDER BY TABLE_NAME;
"

echo '=== 3. REQUIRED COLUMN PRESENCE (NAMES ONLY) ==='
sql "
SELECT CONCAT(
  'TABLE=',TABLE_NAME,
  ' MATCHED_COLUMNS=',COUNT(*),
  ' COLUMNS=',GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME SEPARATOR ',')
)
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND (
    (TABLE_NAME='b_user'
     AND COLUMN_NAME IN ('ID','EMAIL','LOGIN','PASSWORD','ACTIVE',
                         'BLOCKED','PASSWORD_EXPIRED','TIMESTAMP_X'))
    OR
    (TABLE_NAME='b_sec_user'
     AND COLUMN_NAME IN ('USER_ID','ACTIVE','TYPE','SECRET','DEACTIVATE_UNTIL'))
    OR
    (TABLE_NAME='ev_samzaberu_auth_state'
     AND COLUMN_NAME IN ('USER_ID','AUTH_EPOCH'))
    OR
    (TABLE_NAME='ev_web_email_ownership_proof'
     AND COLUMN_NAME IN ('user_id','current_email_mac','profile_updated_at',
                         'verified_at','source','revoked'))
    OR
    (TABLE_NAME='ev_v5_email_auth_challenges'
     AND COLUMN_NAME IN ('challenge_id','user_id','device_binding','platform',
                         'status','email_stamp','auth_epoch','code_hmac',
                         'issued_at','expires_at','failed_attempts'))
  )
GROUP BY TABLE_NAME
ORDER BY TABLE_NAME;
"

echo '=== 4. REAL SCHEMA CHECKPOINT ==='
echo 'NEXT_STEP=COMPARE_EXISTING_SCHEMA_WITH_V5_EMAIL_NATIVE_PORTS'
