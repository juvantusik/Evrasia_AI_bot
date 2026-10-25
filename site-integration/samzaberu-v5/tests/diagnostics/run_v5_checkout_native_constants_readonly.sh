#!/usr/bin/env bash
printf '\033[3J\033[2J\033[H'
set -Eeuo pipefail
umask 077

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
CART="$SITE/local/php_interface/Controllers/V4/Cart.php"
ROUTER="$SITE/api/v4/router.php"
OFF='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
CART_SHA='73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039'
ROUTER_SHA='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
PARSE_COMMIT='7c3ea14f0996c2fce7cb765d3bf234d3f6a5f1f2'
PARSER_BLOB='7986f4bcc87eb5862a6e5871c9a6b65e9275c39d'
PARSER=''
TEST='NOT_RUN'

finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$PARSER" && "$PARSER" == /tmp/ev-v5-native-constants.????????.php &&
          -f "$PARSER" && ! -L "$PARSER" ]]; then rm -f -- "$PARSER"; fi
    echo
    echo '=== 5. RESULT ==='
    echo "V5_CHECKOUT_NATIVE_CONSTANTS_TRACE=$TEST"
    echo 'MODE=SOURCE_ONLY_READ_ONLY'
    echo 'BITRIX_BOOTSTRAP=NO'
    echo 'SITE_SOURCE_EXECUTED=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'API_V4_CHANGED=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'ORDER_CREATED=NO'
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

echo '=== 1. HOST / PINNED PRODUCTION SOURCE ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] || fail 'host/root'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP missing'
for exe in curl git sha256sum timeout cmp; do command -v "$exe" >/dev/null || fail "tool missing: $exe"; done
[[ -f "$CART" && ! -L "$CART" && -r "$CART" ]] || fail 'Cart source unavailable'
[[ -f "$ROUTER" && ! -L "$ROUTER" && -r "$ROUTER" ]] || fail 'V4 router unavailable'
printf '%s  %s\n' "$CART_SHA" "$CART" "$ROUTER_SHA" "$ROUTER" |
    sha256sum -c --status || fail 'V4 source changed'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 AUTH not OFF'
echo 'PASS: exact V4 Cart/router unchanged, V5 AUTH OFF'

echo
echo '=== 2. PINNED ALLOWLIST SOURCE PARSER ==='
PARSER="$(mktemp /tmp/ev-v5-native-constants.XXXXXXXX.php)"
URL="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$PARSE_COMMIT/site-integration/samzaberu-v5/tests/diagnostics/V5CheckoutNativeConstantsTrace.php"
curl -fLsS --connect-timeout 8 --max-time 30 "$URL" -o "$PARSER" ||
    fail 'parser download'
[[ "$(git hash-object "$PARSER")" == "$PARSER_BLOB" ]] || fail 'parser integrity'
/usr/bin/php8.3 -l "$PARSER" >/dev/null || fail 'PHP syntax'
timeout 10 /usr/bin/php8.3 -d display_errors=0 -d log_errors=0 \
    -d auto_prepend_file= -d auto_append_file= \
    "$PARSER" --self-test || fail 'parser redaction test'
echo 'PASS: exact parser syntax and redaction self-test'

printf '\033[3J\033[2J\033[H'
echo '=== 1. CHECKOUT NATIVE FIELD/POLICY VALUES ==='
echo 'PASS: V4 pinned, V5 OFF'
echo
echo '=== 2. ALLOWLISTED NATIVE CHECKOUT CONSTANTS ==='
TEST='FAIL'
timeout 15 /usr/bin/php8.3 -d display_errors=0 -d log_errors=0 \
    -d auto_prepend_file= -d auto_append_file= \
    "$PARSER" --source-only "$CART" || fail 'static Cart::order parse'
TEST='PASS'

echo
echo '=== 3. VERIFY ==='
printf '%s  %s\n' "$CART_SHA" "$CART" "$ROUTER_SHA" "$ROUTER" |
    sha256sum -c --status || fail 'V4 source changed after parsing'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 AUTH changed'
echo 'PASS: source-only, production unchanged'
