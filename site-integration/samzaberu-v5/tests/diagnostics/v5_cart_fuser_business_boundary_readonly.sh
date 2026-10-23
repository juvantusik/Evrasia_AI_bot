#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
CART="$SITE/local/php_interface/Controllers/V4/Cart.php"
SERVICE="$SITE/local/php_interface/lib/Services/CartApiService.php"
ROUTER="$SITE/api/v4/router.php"
CART_SHA='73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039'
SERVICE_SHA='61671c764f23a82ce66a990d5c9122ebf25fdac800ab51828c0805c8c9db18e0'
ROUTER_SHA='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
PROBE_SHA='9807b335e19f6ccc26fef95d786eb57c8092a7d9'
PROBE="${1:-}"
PHP_ARGS=(-n)

finish() {
    local rc=$?
    trap - EXIT
    echo
    echo '=== 5. RESULT ==='
    if ((rc==0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'MODE=READ_ONLY'
    echo 'SOURCE_EXECUTED=NO'
    echo 'BITRIX_BOOTSTRAP=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'USER_DATA_READ=NO'
    echo 'SMS_SENT=NO'
    echo 'SECRETS_PRINTED=NO'
    echo 'V4_MODIFIED=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'V5_CART_RUNTIME_RELEASE_APPROVED=NO'
    exit "$rc"
}
trap finish EXIT
fail() { echo "FAIL: $1" >&2; exit 2; }
pin() {
    [[ -f "$1" && -r "$1" && ! -L "$1" &&
       "$(realpath -e "$1")" == "$1" ]] || fail 'source path invalid'
    printf '%s  %s\n' "$2" "$1" | sha256sum -c --status || fail 'installed source SHA drift'
}

echo '=== 1. HOST / ENVIRONMENT ==='
[[ $# -eq 1 && "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] \
    || fail 'exact evrasia host / root / one probe required'
[[ -d "$SITE" && ! -L "$SITE" &&
   "$(realpath -e "$SITE")" == "$SITE" ]] || fail 'unexpected site root'
for cmd in php git sha256sum realpath stat; do
    command -v "$cmd" >/dev/null || fail "missing utility: $cmd"
done
[[ -f "$PROBE" && -r "$PROBE" && ! -L "$PROBE"
   && "$(git hash-object "$PROBE")" == "$PROBE_SHA" ]] \
    || fail 'untrusted/changed parser'
echo 'PASS: exact host and immutable source-only lexer'

echo
echo '=== 2. PINNED INSTALLED V4 SOURCE ==='
pin "$ROUTER" "$ROUTER_SHA"
pin "$CART" "$CART_SHA"
pin "$SERVICE" "$SERVICE_SHA"
echo 'PASS: current V4 router, Cart.php, CartApiService.php unchanged'

if php -n -r 'exit(function_exists("token_get_all")?0:2);' 2>/dev/null; then
    echo 'TOKENIZER_MODE=BUILT_IN'
else
    ext="$(php -n -r 'echo ini_get("extension_dir");')" || fail 'PHP extension directory'
    case "$ext" in /usr/lib/php/*) ;; *) fail 'untrusted extension path';; esac
    lib="$ext/tokenizer.so"
    [[ -f "$lib" && -r "$lib" && ! -L "$lib" &&
       "$(realpath -e "$lib")" == "$lib" &&
       "$(stat -c %u "$lib")" == 0 ]] || fail 'tokenizer.so not trusted'
    PHP_ARGS=(-n -d "extension=$lib")
fi
php "${PHP_ARGS[@]}" -l "$PROBE" >/dev/null || fail 'parser PHP syntax invalid'
php "${PHP_ARGS[@]}" "$PROBE" --self-test || fail 'masking selftest failure'

echo
php "${PHP_ARGS[@]}" "$PROBE" --read-pinned "$CART" "$SERVICE" ||
    fail 'business method source inspection'

pin "$ROUTER" "$ROUTER_SHA"
pin "$CART" "$CART_SHA"
pin "$SERVICE" "$SERVICE_SHA"
echo 'PASS: all three production files identical after source read'
echo 'MAIN_PUBLIC_API=https://evrasia.rest/api/v5'
echo 'NEXT_STEP=IMPLEMENT_AUTHORITATIVE_USER_TO_FUSER_BINDING'
