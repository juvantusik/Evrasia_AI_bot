#!/usr/bin/env bash
printf '\033[3J\033[2J\033[H'
set -Eeuo pipefail
umask 077

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
CART="$SITE/local/php_interface/Controllers/V4/Cart.php"
SERVICE="$SITE/local/php_interface/lib/Services/CartApiService.php"
OFF='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
COMMIT='91b6ef08aee8bd190b45c0b5a4d3d74295b96a34'
PARSER_BLOB='bf13523e5fa77bcccca1c468a980582e355d6b00'
SCRIPT=''
PASSED=NO

finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$SCRIPT" && "$SCRIPT" == /tmp/ev-v5-checkout-shape.????????.php &&
         -f "$SCRIPT" && ! -L "$SCRIPT" ]]; then rm -f -- "$SCRIPT"; fi
    echo
    echo '=== 5. RESULT ==='
    echo "V5_CHECKOUT_BUSINESS_SHAPE=$PASSED"
    echo 'MODE=SOURCE_ONLY_READ_ONLY'
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
    if ((rc == 0)); then echo 'FINAL_STATUS=PASS'
    else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
}
trap finish EXIT
fail() { echo "FAIL: $1"; exit 2; }

echo '=== 1. HOST / ENVIRONMENT ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] ||
    fail 'host/root'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP unavailable'
for tool in curl git sha256sum timeout; do
    command -v "$tool" >/dev/null || fail "missing tool: $tool"
done
[[ -f "$CART" && ! -L "$CART" && -f "$SERVICE" && ! -L "$SERVICE" ]] ||
    fail 'pinned source unavailable'
printf '%s  %s\n' \
  '73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039' "$CART" \
  '61671c764f23a82ce66a990d5c9122ebf25fdac800ab51828c0805c8c9db18e0' "$SERVICE" \
  '5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d' "$SITE/api/v4/router.php" |
    sha256sum -c --status || fail 'production source changed'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 AUTH not OFF'
echo 'PASS: pinned checkout files and V5 AUTH OFF'

echo
echo '=== 2. LOAD PINNED MASKED PARSER ==='
SCRIPT="$(mktemp /tmp/ev-v5-checkout-shape.XXXXXXXX.php)"
URL="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$COMMIT/site-integration/samzaberu-v5/tests/diagnostics/V5CheckoutBusinessMethodShape.php"
curl -fLsS --connect-timeout 8 --max-time 30 "$URL" -o "$SCRIPT" ||
    fail 'parser download'
[[ "$(git hash-object "$SCRIPT")" == "$PARSER_BLOB" ]] ||
    fail 'parser integrity'
/usr/bin/php8.3 -l "$SCRIPT" >/dev/null || fail 'PHP syntax'
echo 'PASS: parser pinned and linted'

printf '\033[3J\033[2J\033[H'
echo '=== 3. V4 CHECKOUT BUSINESS SHAPES (MASKED) ==='
timeout 15 /usr/bin/php8.3 \
    -d auto_prepend_file= -d auto_append_file= \
    -d display_errors=0 -d log_errors=0 \
    "$SCRIPT" "$CART" "$SERVICE" ||
    fail 'business shape read'

echo
echo '=== 4. VERIFY ==='
printf '%s  %s\n' \
  '73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039' "$CART" \
  '61671c764f23a82ce66a990d5c9122ebf25fdac800ab51828c0805c8c9db18e0' "$SERVICE" |
    sha256sum -c --status || fail 'source changed during read'
echo 'PASS: exact business source unchanged'
PASSED=PASS
