#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
ORDER="$SITE/bitrix/modules/sale/lib/order.php"
ORDERBASE="$SITE/bitrix/modules/sale/lib/orderbase.php"
CART="$SITE/local/php_interface/Controllers/V4/Cart.php"
ROUTER="$SITE/api/v4/router.php"
CONFIG='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
CART_SHA='73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039'
ROUTER_SHA='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
COMMIT='211c1458a11d45c26f8d2ac2d17deb3a7636002f'
BLOB='ac6113c5cf1d8f51715fcb18ccf5dd3d468e6140'
PARSER=''
RESULT='NOT_RUN'
finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$PARSER" && "$PARSER" == /tmp/ev-v5-sale-save.????????.php &&
          -f "$PARSER" && ! -L "$PARSER" ]]; then
        rm -f -- "$PARSER"
    fi
    echo
    echo '=== 4. RESULT ==='
    echo "V5_SALE_SAVE_TRANSACTION_SOURCE=$RESULT"
    echo 'MODE=SOURCE_ONLY_READ_ONLY'
    echo 'BITRIX_BOOTSTRAP=NO'
    echo 'SITE_SOURCE_EXECUTED=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'API_V4_CHANGED=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'ORDER_CREATED=NO'
    echo 'CALL_CENTER_CONTACTED=NO'
    echo 'PAYMENT_STARTED=NO'
    echo 'SECRETS_PRINTED=NO'
    echo 'NATIVE_TRANSACTION_E2E=NOT_TESTED'
    if ((rc==0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
}
trap finish EXIT
fail() { echo "FAIL: $1"; exit 2; }

echo '=== 1. HOST / INSTALLED BITRIX SOURCE GUARDS ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] || fail 'host/root'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP8.3 unavailable'
for c in curl git sha256sum cmp timeout; do command -v "$c" >/dev/null || fail "missing $c"; done
for p in "$ORDER" "$CART" "$ROUTER"; do
    [[ -f "$p" && -r "$p" && ! -L "$p" ]] || fail 'required source unavailable'
done
if [[ -e "$ORDERBASE" ]]; then
    [[ -f "$ORDERBASE" && -r "$ORDERBASE" && ! -L "$ORDERBASE" ]] ||
        fail 'OrderBase path changed'
fi
printf '%s  %s\n' "$CART_SHA" "$CART" "$ROUTER_SHA" "$ROUTER" |
    sha256sum -c --status || fail 'V4 source changed'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$CONFIG" || fail 'V5 AUTH not OFF'
echo 'PASS: V4 unchanged; V5 AUTH OFF; Sale sources source-only'

echo
echo '=== 2. PINNED READ-ONLY TRANSACTION SOURCE PARSER ==='
PARSER="$(mktemp /tmp/ev-v5-sale-save.XXXXXXXX.php)"
URL="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$COMMIT/site-integration/samzaberu-v5/tests/diagnostics/V5SaleSaveTransactionSourceTrace.php"
curl -fLsS --connect-timeout 8 --max-time 30 "$URL" -o "$PARSER" || fail 'parser download'
[[ "$(git hash-object "$PARSER")" == "$BLOB" ]] || fail 'parser integrity'
/usr/bin/php8.3 -l "$PARSER" >/dev/null || fail 'PHP syntax'
timeout 10 /usr/bin/php8.3 -d auto_prepend_file= -d auto_append_file= \
    -d display_errors=0 -d log_errors=0 "$PARSER" --self-test ||
    fail 'parser self-test'
echo 'PASS: bounded method parser and redaction self-test'

echo
echo '=== 3. BITRIX SALE ORDER SAVE DIRECT TRANSACTION SHAPE ==='
RESULT='FAIL'
timeout 20 /usr/bin/php8.3 -d auto_prepend_file= -d auto_append_file= \
    -d display_errors=0 -d log_errors=0 "$PARSER" --read-only \
    "$ORDER" "$ORDERBASE" || fail 'Sale save source scan'
printf '%s  %s\n' "$CART_SHA" "$CART" "$ROUTER_SHA" "$ROUTER" |
    sha256sum -c --status || fail 'V4 source changed after read'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$CONFIG" || fail 'V5 AUTH changed during read'
RESULT='PASS'
echo 'PASS: installed Bitrix source not executed, no DB or orders'
