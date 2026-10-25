#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
CAND='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/candidates/6fa8773746e9a40e22008d67636c896eefeec3ed'
OFF='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
CODE_COMMIT='fe5387a901a1bdfc9ecf8f866f3b29080d3504cd'
TRACE_COMMIT='70e6dc12a001c98f3b3b65928a11912f0cee8fea'
BASE="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$CODE_COMMIT/site-integration/samzaberu-v5"
TRACE_URL="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$TRACE_COMMIT/site-integration/samzaberu-v5/tests/diagnostics/V5CheckoutBusinessCallTrace.php"
V4_HASH='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
V5_HASH='f502ae4718a3193d2765d30b75dff638a684cb75078534fecfd3524dce3b94d8'
CART_HASH='73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039'
CART="$SITE/local/php_interface/Controllers/V4/Cart.php"
WORK=''
TEST='NOT_RUN'
finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$WORK" && "$WORK" == /tmp/ev-v5-checkout.???????? &&
        -d "$WORK" && ! -L "$WORK" ]]; then
        rm -r -- "$WORK"
    fi
    echo
    echo '=== 5. RESULT ==='
    echo "V5_CHECKOUT_HTTP_TEST=$TEST"
    echo 'NATIVE_ORDER_PLACEMENT=NOT_IMPLEMENTED'
    echo 'ORDER_CREATED=NO'
    echo 'BITRIX_D7_ADAPTER_EXERCISED=NO'
    echo 'MODE=OFFLINE_FUNCTIONAL_READ_ONLY'
    echo 'V4_RUNTIME_IMPORTED=NO'
    echo 'SITE_SOURCE_EXECUTED=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'API_V4_CHANGED=NO'
    echo 'V5_AUTH_ENABLED=NO'
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
[[ "$(hostname -s)" == 'evrasia' && "$(id -u)" == 0 ]] ||
    fail 'host/root'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP unavailable'
id site_evrasia >/dev/null || fail 'site user missing'
for command in git curl sha256sum runuser timeout; do
    command -v "$command" >/dev/null || fail "missing $command"
done
[[ -d "$CAND/src" && ! -L "$CAND" && ! -L "$CAND/src" ]] ||
    fail 'V5 source candidate missing'
(cd "$CAND" && sha256sum -c --status SOURCE_SHA256) ||
    fail 'V5 candidate changed'
printf '%s  %s\n' "$V4_HASH" "$SITE/api/v4/router.php" |
    sha256sum -c --status || fail 'V4 router changed'
printf '%s  %s\n' "$V5_HASH" "$SITE/api/v5/router.php" |
    sha256sum -c --status || fail 'V5 router changed'
printf '%s  %s\n' "$CART_HASH" "$CART" |
    sha256sum -c --status || fail 'Cart source changed'
printf '%s\n' \
'{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 unexpectedly enabled'
echo 'PASS: V4 pinned; V5 AUTH OFF'

echo
echo '=== 2. FETCH V5 CHECKOUT SOURCE ONLY ==='
WORK="$(mktemp -d /tmp/ev-v5-checkout.XXXXXXXX)"
mkdir -- "$WORK/src" "$WORK/tests"
cp -- "$CAND"/src/*.php "$WORK/src/"
while read -r file expected; do
    curl -fLsS --connect-timeout 8 --max-time 30 \
        "$BASE/$file" -o "$WORK/$file" || fail "fetch: $file"
    [[ "$(git hash-object "$WORK/$file")" == "$expected" ]] ||
        fail "source mismatch: $file"
done <<'FILES'
src/V5CheckoutRequest.php 1feb64788ecef182008b6f604a947b8e2d684bb5
src/V5MySqlOrderPlace.php 8d3e9bda2e71df282b1a59611a6c1ec58271869a
src/V5MySqlStrongSessionStore.php bb72bc9613f44c66fa6fecc2a56e915d8bf1ccf5
src/V5AuthHttpHandler.php d3b2a2740db096b06ecd8486444013740ceb4c05
src/V5RouteMap.php 39450e312eaf57541823ff16de691e11ba13ce5e
src/V5ProductionNativeRequestBoundaryGuard.php d1a0a07c4c663e52b45060ce6a7b86bbfc547235
src/V5MySqlPublicComposition.php 9a14590b85a5cb8dad3af39926864f58ce18c76a
src/V5MySqlPilotComposition.php dd9586032aa334c9732625b3015d9d67b18ed0ad
tests/V5IndependentCheckoutHttpTest.php 751fc06586ff6d5219b32242c8dd07fe12f6f4ce
FILES
curl -fLsS --connect-timeout 8 --max-time 30 \
    "$TRACE_URL" -o "$WORK/tests/trace.php" ||
    fail 'fetch: checkout source trace'
[[ "$(git hash-object "$WORK/tests/trace.php")" == \
    'e928af1f58c6f072f302c6c6ce09e41a06c5f183' ]] ||
    fail 'trace source mismatch'
chown -R root:site_evrasia "$WORK"
chmod 750 "$WORK" "$WORK/src" "$WORK/tests"
chmod 640 "$WORK"/src/*.php "$WORK"/tests/*.php
echo 'PASS: exact V5 checkout sources verified'

echo
echo '=== 3. V5 CHECKOUT HTTP CONTRACT ==='
for file in "$WORK"/src/V5CheckoutRequest.php \
    "$WORK"/src/V5MySqlOrderPlace.php \
    "$WORK"/src/V5MySqlStrongSessionStore.php \
    "$WORK"/src/V5AuthHttpHandler.php \
    "$WORK"/src/V5RouteMap.php \
    "$WORK"/src/V5ProductionNativeRequestBoundaryGuard.php \
    "$WORK"/src/V5MySqlPublicComposition.php \
    "$WORK"/src/V5MySqlPilotComposition.php \
    "$WORK"/tests/*.php; do
    /usr/bin/php8.3 -l "$file" >/dev/null ||
        fail "PHP syntax: $(basename "$file")"
done
echo 'PASS: targeted PHP syntax'
TEST='FAIL'
timeout 20 runuser -u site_evrasia -- /usr/bin/php8.3 \
    -d auto_prepend_file= -d auto_append_file= \
    -d display_errors=0 -d log_errors=0 \
    "$WORK/tests/V5IndependentCheckoutHttpTest.php" ||
    fail 'V5 checkout HTTP contract'
TEST='PASS'

echo
echo '=== 4. NATIVE V4 CHECKOUT BUSINESS CALL SHAPES ==='
timeout 12 /usr/bin/php8.3 -d display_errors=0 \
    "$WORK/tests/trace.php" "$CART" ||
    fail 'source-only checkout call trace'
printf '%s  %s\n' "$CART_HASH" "$CART" |
    sha256sum -c --status || fail 'V4 Cart changed'
printf '%s  %s\n' "$V4_HASH" "$SITE/api/v4/router.php" |
    sha256sum -c --status || fail 'V4 router changed'
printf '%s\n' \
'{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 enabled'
echo 'PASS: native source unchanged; no installation'
