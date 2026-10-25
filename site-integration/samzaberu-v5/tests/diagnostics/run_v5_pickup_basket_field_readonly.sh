#!/usr/bin/env bash
printf '\033[3J\033[2J\033[H'
set -Eeuo pipefail
umask 077

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
CART="$SITE/local/php_interface/Controllers/V4/Cart.php"
V4_ROUTER="$SITE/api/v4/router.php"
OFF='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
CART_HASH='73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039'
V4_HASH='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
PARSER_COMMIT='7bfc27e9da4c5e32ed9ec7765fa930383812cf86'
PARSER_BLOB='8463851978c33c89c4aee6775d0f20edd9fc09cc'
PARSER=''
TRACE='NOT_RUN'
finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$PARSER" && "$PARSER" == /tmp/ev-v5-pickup-shape.????????.php &&
          -f "$PARSER" && ! -L "$PARSER" ]]; then rm -f -- "$PARSER"; fi
    echo
    echo '=== 5. RESULT ==='
    echo "V5_PICKUP_NATIVE_BUSINESS_SHAPE=$TRACE"
    echo 'MODE=SOURCE_ONLY_READ_ONLY'
    echo 'BITRIX_BOOTSTRAP=NO'
    echo 'SITE_SOURCE_EXECUTED=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'API_V4_CHANGED=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'REAL_ORDER_CREATED=NO'
    echo 'PAYMENT_GATEWAY_CALLED=NO'
    echo 'USER_DATA_READ=NO'
    echo 'SMS_SENT=NO'
    echo 'SECRETS_PRINTED=NO'
    if ((rc == 0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
}
trap finish EXIT
fail() { echo "FAIL: $1"; exit 2; }

echo '=== 1. HOST / CURRENT SOURCE ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] || fail 'host/root'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP unavailable'
for command in curl git sha256sum timeout cmp; do command -v "$command" >/dev/null || fail "missing $command"; done
[[ -f "$CART" && ! -L "$CART" && -r "$CART" ]] || fail 'Cart missing'
[[ -f "$V4_ROUTER" && ! -L "$V4_ROUTER" && -r "$V4_ROUTER" ]] || fail 'V4 router missing'
printf '%s  %s\n' "$CART_HASH" "$CART" "$V4_HASH" "$V4_ROUTER" |
    sha256sum -c --status || fail 'V4 Cart/router changed'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 AUTH no longer OFF'
echo 'PASS: live V4 pinned, V5 AUTH OFF'

echo
echo '=== 2. PINNED MASKED CHECKOUT PARSER ==='
PARSER="$(mktemp /tmp/ev-v5-pickup-shape.XXXXXXXX.php)"
URL="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$PARSER_COMMIT/site-integration/samzaberu-v5/tests/diagnostics/V5PickupBasketPropertyShape.php"
curl -fLsS --connect-timeout 8 --max-time 30 "$URL" -o "$PARSER" || fail 'download'
[[ "$(git hash-object "$PARSER")" == "$PARSER_BLOB" ]] || fail 'parser integrity'
/usr/bin/php8.3 -l "$PARSER" >/dev/null || fail 'PHP syntax'
timeout 10 /usr/bin/php8.3 -d display_errors=0 -d log_errors=0 \
    "$PARSER" --self-test || fail 'source parser self-test'
echo 'PASS: exact parser and source rules'

printf '\033[3J\033[2J\033[H'
echo '=== 1. VERIFIED V4 CHECKOUT BASKET/PROPERTY MAP ==='
echo 'PASS: source pinned, V5 OFF'
echo
echo '=== 2. NATIVE PICKUP BUSINESS FIELD SHAPES ==='
TRACE='FAIL'
timeout 20 /usr/bin/php8.3 -d display_errors=0 -d log_errors=0 \
    -d auto_prepend_file= -d auto_append_file= \
    "$PARSER" --source-only "$CART" || fail 'checkout source-only trace'

echo
echo '=== 3. VERIFY UNCHANGED ==='
printf '%s  %s\n' "$CART_HASH" "$CART" "$V4_HASH" "$V4_ROUTER" |
    sha256sum -c --status || fail 'V4 source changed during trace'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 AUTH changed'
echo 'PASS: source-only verified; no installation'
TRACE='PASS'
