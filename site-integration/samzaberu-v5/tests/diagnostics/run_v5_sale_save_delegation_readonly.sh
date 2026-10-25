#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
ORDER="$SITE/bitrix/modules/sale/lib/order.php"
ORDERBASE="$SITE/bitrix/modules/sale/lib/orderbase.php"
CART="$SITE/local/php_interface/Controllers/V4/Cart.php"
ROUTER="$SITE/api/v4/router.php"
CONFIG='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
CART_SHA='73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039'
ROUTER_SHA='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
PARSER_COMMIT='60a34f22bd313a667c90e1a34a8d03ee1bfbe9ce'
PARSER_BLOB='0172f1aef686e945453dd93238d9d7c4c3a7cbc2'
PARSER=''
RESULT='NOT_RUN'
finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$PARSER" && "$PARSER" == /tmp/ev-v5-save-delegation.????????.php &&
          -f "$PARSER" && ! -L "$PARSER" ]]; then
        rm -f -- "$PARSER"
    fi
    echo
    echo '=== 4. RESULT ==='
    echo "V5_SALE_SAVE_DELEGATION_SOURCE=$RESULT"
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
    echo 'SECRETS_PRINTED=NO'
    echo 'NATIVE_TRANSACTION_E2E=NOT_TESTED'
    if ((rc == 0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
}
trap finish EXIT
fail(){ echo "FAIL: $1"; exit 2; }

echo '=== 1. HOST / BITRIX SOURCE GUARDS ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] || fail 'host/root'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP 8.3 missing'
for cmd in curl git sha256sum cmp timeout; do
    command -v "$cmd" >/dev/null || fail "missing $cmd"
done
for file in "$CART" "$ROUTER" "$ORDER" "$ORDERBASE"; do
    [[ -r "$file" && -f "$file" && ! -L "$file" ]] || fail 'installed source unavailable'
done
printf '%s  %s\n' "$CART_SHA" "$CART" "$ROUTER_SHA" "$ROUTER" |
    sha256sum -c --status || fail 'V4 modified'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$CONFIG" || fail 'V5 AUTH not OFF'
echo 'PASS: original V4 and V5 AUTH OFF, installed Sale source only'

echo
echo '=== 2. VERIFIED READ-ONLY METHOD SHAPE PARSER ==='
PARSER="$(mktemp /tmp/ev-v5-save-delegation.XXXXXXXX.php)"
URL="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$PARSER_COMMIT/site-integration/samzaberu-v5/tests/diagnostics/V5SaleSaveDelegationTrace.php"
curl -fLsS --connect-timeout 8 --max-time 30 "$URL" -o "$PARSER" || fail 'parser download'
[[ "$(git hash-object "$PARSER")" == "$PARSER_BLOB" ]] || fail 'parser integrity'
/usr/bin/php8.3 -n -l "$PARSER" >/dev/null || fail 'PHP parser syntax'
timeout 10 /usr/bin/php8.3 -n "$PARSER" --self-test || fail 'parser self-test'
echo 'PASS: only vetted receiver/method names, no arbitrary strings'

echo
echo '=== 3. DELEGATED SALE SAVE SHAPES ==='
RESULT='FAIL'
timeout 20 /usr/bin/php8.3 -n "$PARSER" --read-only \
    "$ORDER" "$ORDERBASE" || fail 'delegation read-only trace'
printf '%s  %s\n' "$CART_SHA" "$CART" "$ROUTER_SHA" "$ROUTER" |
    sha256sum -c --status || fail 'V4 changed during scan'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$CONFIG" || fail 'V5 OFF changed during scan'
echo 'PASS: source-only, no orders or database operations'
RESULT='PASS'
