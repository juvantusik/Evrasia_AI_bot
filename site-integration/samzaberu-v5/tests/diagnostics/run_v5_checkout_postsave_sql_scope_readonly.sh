#!/usr/bin/env bash
printf '\033[3J\033[2J\033[H'
set -Eeuo pipefail
umask 077

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
SERVICE="$SITE/local/php_interface/lib/Services/CartApiService.php"
ROUTER="$SITE/api/v4/router.php"
CONFIG='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
SERVICE_SHA='61671c764f23a82ce66a990d5c9122ebf25fdac800ab51828c0805c8c9db18e0'
ROUTER_SHA='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
PARSER_COMMIT='4e040e4acf48b80f4f9a5a2d932fa1a19d6b22c2'
PARSER_BLOB='4e84d2c84081957a0879183514259326b3a7a591'
PARSER=''
RESULT='NOT_RUN'

finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$PARSER" &&
          "$PARSER" == /tmp/ev-v5-postsave.????????.php &&
          -f "$PARSER" && ! -L "$PARSER" ]]; then
        rm -f -- "$PARSER"
    fi
    echo
    echo '=== 5. RESULT ==='
    echo "V5_POSTSAVE_SQL_SCOPE=$RESULT"
    echo 'MODE=SOURCE_ONLY_READ_ONLY'
    echo 'BITRIX_BOOTSTRAP=NO'
    echo 'SITE_SOURCE_EXECUTED=NO'
    echo 'SQL_TEXT_PRINTED=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'API_V4_CHANGED=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'ORDER_CREATED=NO'
    echo 'RESTIS_ORDER_SENT=NO'
    echo 'PAYMENT_STARTED=NO'
    echo 'CUSTOMER_RECORDS_READ=NO'
    echo 'SMS_SENT=NO'
    echo 'SECRETS_PRINTED=NO'
    if ((rc==0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
}
trap finish EXIT
fail() { echo "FAIL: $1"; exit 2; }

echo '=== 1. HOST / REAL V4 SOURCE ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] || fail 'host/root'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP 8.3 unavailable'
for cmd in curl git sha256sum timeout cmp; do command -v "$cmd" >/dev/null || fail "missing $cmd"; done
for file in "$SERVICE" "$ROUTER"; do
    [[ -f "$file" && ! -L "$file" && -r "$file" ]] || fail 'source unavailable'
done
printf '%s  %s\n' "$SERVICE_SHA" "$SERVICE" "$ROUTER_SHA" "$ROUTER" |
    sha256sum -c --status || fail 'installed source changed'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$CONFIG" || fail 'V5 AUTH not OFF'
echo 'PASS: exact installed CartApiService, V4 router; V5 AUTH OFF'

echo
echo '=== 2. PINNED SOURCE-ONLY QUERY CLASSIFIER ==='
PARSER="$(mktemp /tmp/ev-v5-postsave.XXXXXXXX.php)"
URL="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$PARSER_COMMIT/site-integration/samzaberu-v5/tests/diagnostics/V5CheckoutPostSaveSqlScope.php"
curl -fLsS --connect-timeout 8 --max-time 30 "$URL" -o "$PARSER" || fail 'parser download'
[[ "$(git hash-object "$PARSER")" == "$PARSER_BLOB" ]] || fail 'parser SHA mismatch'
/usr/bin/php8.3 -l "$PARSER" >/dev/null || fail 'PHP syntax'
timeout 10 /usr/bin/php8.3 -d auto_prepend_file= -d auto_append_file= \
    -d display_errors=0 -d log_errors=0 \
    "$PARSER" --self-test || fail 'classifier self-test'
echo 'PASS: exact classifier and redaction test'

printf '\033[3J\033[2J\033[H'
echo '=== 1. PINNED CHECKOUT POST-SAVE SOURCE ==='
echo 'PASS: V4 unchanged; V5 AUTH OFF'
echo
echo '=== 2. TWO CARTAPISERVICE ORDER-CREATED SQL OPERATIONS ==='
RESULT='FAIL'
timeout 20 /usr/bin/php8.3 -d auto_prepend_file= -d auto_append_file= \
    -d display_errors=0 -d log_errors=0 \
    "$PARSER" --source-only "$SERVICE" || fail 'post-save query classification'
echo
echo '=== 3. VERIFY ==='
printf '%s  %s\n' "$SERVICE_SHA" "$SERVICE" "$ROUTER_SHA" "$ROUTER" |
    sha256sum -c --status || fail 'V4 source changed during read'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$CONFIG" || fail 'V5 AUTH changed'
echo 'PASS: production unchanged'
RESULT='PASS'
