#!/usr/bin/env bash
printf '\033[3J\033[2J\033[H'
set -Eeuo pipefail
umask 077

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
CART="$SITE/local/php_interface/Controllers/V4/Cart.php"
ORDER="$SITE/local/php_interface/lib/COrder.php"
SERVICE="$SITE/local/php_interface/lib/Services/CartApiService.php"
ROUTER="$SITE/api/v4/router.php"
OFF='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
CART_SHA='73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039'
ORDER_SHA='ae70d5be3f9344cf5caaecc7d8a1139fe6b74df4992d924fb40dd4642b54b223'
SERVICE_SHA='61671c764f23a82ce66a990d5c9122ebf25fdac800ab51828c0805c8c9db18e0'
ROUTER_SHA='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
PARSER_COMMIT='ce2533c52f338afc588ea4d12fcb7ad80d57690c'
PARSER_BLOB='0d860b6a048e5068c5419aa5146ea2e77c9f77b5'
PARSER=''
RESULT='NOT_RUN'

finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$PARSER" && "$PARSER" == /tmp/ev-v5-restis-event.????????.php &&
          -f "$PARSER" && ! -L "$PARSER" ]]; then rm -f -- "$PARSER"; fi
    echo
    echo '=== 5. RESULT ==='
    echo "V5_RESTIS_ORDER_EVENT_BOUNDARY=$RESULT"
    echo 'MODE=SOURCE_ONLY_READ_ONLY'
    echo 'BITRIX_BOOTSTRAP=NO'
    echo 'SITE_SOURCE_EXECUTED=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'API_V4_CHANGED=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'ORDER_CREATED=NO'
    echo 'RESTIS_ORDER_SENT=NO'
    echo 'PAYMENT_STARTED=NO'
    echo 'SYNTHETIC_GUEST_CREATED=NO'
    echo 'SMS_SENT=NO'
    echo 'SECRETS_PRINTED=NO'
    if ((rc == 0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
}
trap finish EXIT
fail() { echo "FAIL: $1"; exit 2; }

echo '=== 1. HOST / VERIFIED V4 AND V5 STATE ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] || fail 'host/root'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP 8.3'
for cmd in git curl sha256sum timeout cmp; do
    command -v "$cmd" >/dev/null || fail "missing $cmd"
done
for file in "$CART" "$ORDER" "$SERVICE" "$ROUTER"; do
    [[ -r "$file" && -f "$file" && ! -L "$file" ]] || fail 'source unavailable'
done
printf '%s  %s\n' \
    "$CART_SHA" "$CART" "$ORDER_SHA" "$ORDER" \
    "$SERVICE_SHA" "$SERVICE" "$ROUTER_SHA" "$ROUTER" |
    sha256sum -c --status || fail 'installed source changed'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 AUTH not OFF'
echo 'PASS: V4 Cart, COrder, CartApiService exact; V5 AUTH OFF'

echo
echo '=== 2. VERIFIED SOURCE-ONLY PARSER ==='
PARSER="$(mktemp /tmp/ev-v5-restis-event.XXXXXXXX.php)"
URL="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$PARSER_COMMIT/site-integration/samzaberu-v5/tests/diagnostics/V5RestisOrderCreateTrace.php"
curl -fLsS --connect-timeout 8 --max-time 30 "$URL" -o "$PARSER" || fail 'parser download'
[[ "$(git hash-object "$PARSER")" == "$PARSER_BLOB" ]] || fail 'parser integrity'
/usr/bin/php8.3 -l "$PARSER" >/dev/null || fail 'PHP syntax'
timeout 10 /usr/bin/php8.3 -d display_errors=0 -d log_errors=0 \
    -d auto_prepend_file= -d auto_append_file= \
    "$PARSER" --self-test || fail 'source-only parser self-test'
echo 'PASS: parser pinned, syntax checked and redaction tested'

printf '\033[3J\033[2J\033[H'
echo '=== 1. RESTIS ORDER CREATED EVENT TRACE ==='
echo 'PASS: V4 code unchanged, V5 AUTH OFF'
echo
echo '=== 2. ORDER CREATED EVENT AND RESTIS HOOK SHAPES ==='
RESULT='FAIL'
timeout 20 /usr/bin/php8.3 -d display_errors=0 -d log_errors=0 \
    -d auto_prepend_file= -d auto_append_file= \
    "$PARSER" --source-only "$CART" "$ORDER" "$SERVICE" ||
    fail 'CartApiService orderCreatedEvent trace'

echo
echo '=== 3. VERIFY SOURCE UNCHANGED ==='
printf '%s  %s\n' \
    "$CART_SHA" "$CART" "$ORDER_SHA" "$ORDER" \
    "$SERVICE_SHA" "$SERVICE" "$ROUTER_SHA" "$ROUTER" |
    sha256sum -c --status || fail 'source changed during read'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 AUTH changed'
echo 'PASS: source-only, production unchanged'
RESULT='PASS'
