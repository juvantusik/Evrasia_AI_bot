#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
ORDERBASE="$SITE/bitrix/modules/sale/lib/orderbase.php"
V4_CART="$SITE/local/php_interface/Controllers/V4/Cart.php"
V4_ROUTER="$SITE/api/v4/router.php"
V5_CONFIG='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
V4_CART_SHA='73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039'
V4_ROUTER_SHA='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
PARSER_COMMIT='754124399b4bd872d7e917bf5295ec8a338bec49'
PARSER_BLOB='60335287366d40ce4f275da330c1ed80c2e27c0d'
PARSER=''
FINAL='FAIL'
finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$PARSER" &&
          "$PARSER" == /tmp/ev-v5-save-receiver.????????.php &&
          -f "$PARSER" && ! -L "$PARSER" ]]; then
        rm -f -- "$PARSER"
    fi
    echo
    echo '=== 4. RESULT ==='
    echo "V5_SALE_OTHER_SAVE_RECEIVER=$FINAL"
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
fail(){ echo "FAIL: $1"; exit 2; }
guards() {
    printf '%s  %s\n' \
        "$V4_CART_SHA" "$V4_CART" "$V4_ROUTER_SHA" "$V4_ROUTER" |
        sha256sum -c --status || fail 'V4 source changed'
    printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
        cmp -s - "$V5_CONFIG" || fail 'V5 AUTH not OFF'
}
echo '=== 1. HOST / VERIFIED SITE SOURCE ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] || fail 'wrong host or user'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP 8.3 missing'
for cmd in curl git sha256sum cmp timeout; do
    command -v "$cmd" >/dev/null || fail "missing $cmd"
done
for file in "$ORDERBASE" "$V4_CART" "$V4_ROUTER" "$V5_CONFIG"; do
    [[ -f "$file" && -r "$file" && ! -L "$file" ]] || fail 'expected source missing'
done
guards
echo 'PASS: installed OrderBase source only, V4 unchanged, V5 AUTH OFF'

echo
echo '=== 2. PINNED RECEIVER CLASSIFIER ==='
PARSER="$(mktemp /tmp/ev-v5-save-receiver.XXXXXXXX.php)"
URL="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$PARSER_COMMIT/site-integration/samzaberu-v5/tests/diagnostics/V5SaleSaveReceiverSourceTrace.php"
curl -fLsS --connect-timeout 8 --max-time 30 "$URL" -o "$PARSER" || fail 'parser download'
[[ "$(git hash-object "$PARSER")" == "$PARSER_BLOB" ]] || fail 'parser integrity mismatch'
/usr/bin/php8.3 -l "$PARSER" >/dev/null || fail 'parser PHP syntax'
timeout 10 /usr/bin/php8.3 -d auto_prepend_file= -d auto_append_file= \
    -d display_errors=0 -d log_errors=0 "$PARSER" --self-test ||
    fail 'parser failed redaction self-test'
echo 'PASS: receiver origin tokenization and literals redacted'

echo
echo '=== 3. ONE ORDERBASE SAVE RECEIVER ==='
timeout 15 /usr/bin/php8.3 -d auto_prepend_file= -d auto_append_file= \
    -d display_errors=0 -d log_errors=0 \
    "$PARSER" --read-only "$ORDERBASE" || fail 'source-only receiver analysis'
guards
echo 'PASS: source only, no native execution or SQL'
FINAL='PASS'
