#!/usr/bin/env bash
printf '\033[3J\033[2J\033[H'
set -Eeuo pipefail
umask 077

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
ROUTER="$SITE/api/v4/router.php"
OFF='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
ROUTER_SHA='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
PARSER_COMMIT='69220aa8c69e48e6733d39d002cdaaef6282a199'
PARSER_BLOB='fc297f147ab1bdfdceda521e6f2c773665cca363'
PARSER=''
RESULT='NOT_RUN'

finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$PARSER" && "$PARSER" == /tmp/ev-paid-status-parser.????????.php &&
          -f "$PARSER" && ! -L "$PARSER" ]]; then rm -f -- "$PARSER"; fi
    echo
    echo '=== 5. RESULT ==='
    echo "ORDER_PAYMENT_STATUS_SOURCE_TRACE=$RESULT"
    echo 'MODE=SOURCE_ONLY_READ_ONLY'
    echo 'BITRIX_BOOTSTRAP=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'API_V4_CHANGED=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'ORDER_CREATED=NO'
    echo 'PAYMENT_STARTED=NO'
    echo 'USER_DATA_READ=NO'
    echo 'SMS_SENT=NO'
    echo 'SECRETS_PRINTED=NO'
    if ((rc==0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
}
trap finish EXIT
fail() { echo "FAIL: $1"; exit 2; }

echo '=== 1. HOST / ENVIRONMENT ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] ||
    fail 'host/root'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP unavailable'
for cmd in curl git sha256sum timeout cmp; do
    command -v "$cmd" >/dev/null || fail "missing $cmd"
done
[[ -f "$ROUTER" && -r "$ROUTER" && ! -L "$ROUTER" ]] ||
    fail 'V4 router unavailable'
[[ -d "$SITE/local/php_interface" && ! -L "$SITE/local/php_interface" ]] ||
    fail 'Bitrix application sources unavailable'
printf '%s  %s\n' "$ROUTER_SHA" "$ROUTER" |
    sha256sum -c --status || fail 'V4 router source changed'
printf '%s\n' \
    '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 AUTH no longer OFF'
echo 'PASS: production source pinned; V5 AUTH OFF'

echo
echo '=== 2. SOURCE-ONLY PARSER ==='
PARSER="$(mktemp /tmp/ev-paid-status-parser.XXXXXXXX.php)"
URL="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$PARSER_COMMIT/site-integration/samzaberu-v5/tests/diagnostics/V5ReadOnlyOrderPaymentStatusTrace.php"
curl -fLsS --connect-timeout 8 --max-time 30 "$URL" -o "$PARSER" ||
    fail 'parser download'
[[ "$(git hash-object "$PARSER")" == "$PARSER_BLOB" ]] ||
    fail 'parser source integrity'
/usr/bin/php8.3 -l "$PARSER" >/dev/null || fail 'PHP syntax'
timeout 10 /usr/bin/php8.3 \
    -d auto_prepend_file= -d auto_append_file= \
    -d display_errors=0 -d log_errors=0 \
    "$PARSER" --self-test || fail 'parser selftest'
echo 'PASS: source parser pinned and tested'

printf '\033[3J\033[2J\033[H'
echo '=== 1. VERIFIED ROUTER / STATUS SOURCE ==='
echo 'PASS: V4 router pinned; V5 AUTH OFF'
echo
echo '=== 2. STATUS AND PAYMENT IMPLEMENTATION (SOURCE ONLY) ==='
RESULT='FAIL'
timeout 30 /usr/bin/php8.3 \
    -d auto_prepend_file= -d auto_append_file= \
    -d display_errors=0 -d log_errors=0 \
    "$PARSER" --source-only "$SITE" ||
    fail 'route/status source trace'
RESULT='PASS'

echo
echo '=== 3. VERIFY SOURCE UNCHANGED ==='
printf '%s  %s\n' "$ROUTER_SHA" "$ROUTER" |
    sha256sum -c --status || fail 'V4 router changed during read'
printf '%s\n' \
    '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 AUTH changed'
echo 'PASS: read-only source verification'
