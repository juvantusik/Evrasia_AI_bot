#!/usr/bin/env bash
# Narrow source-only V4 CART read contract discovery; does NOT invoke any V4
# HTTP controller, Bitrix bootstrap, DB, RestIS, PHP session or SMS.
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
ROUTER="$SITE/api/v4/router.php"
ROUTER_SHA='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
CONTROLLERS="$SITE/local/php_interface/Controllers/V4"
CART="$CONTROLLERS/Cart.php"
RESTAURANTS="$CONTROLLERS/Restaurants.php"
CART_SHA='73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039'
RESTAURANTS_SHA='c9560839aac8b9640ab65b778736deeed1366096a4a1a0393f4bf72462d80deb'
PROBE_BLOB='a9acf25dceb7b3e1bae1cf836e3da6c6097a6fe6'
PROBE="${1:-}"
PHP_ARGS=(-n)
SERVICE='ABSENT'
SERVICE_BEFORE=''
finish() {
    local rc=$?
    trap - EXIT
    echo
    echo '=== 5. RESULT ==='
    if (( rc == 0 )); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    printf 'FINAL_RC=%s\n' "$rc"
    echo 'MODE=READ_ONLY'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'SITE_SOURCE_EXECUTED=NO'
    echo 'BITRIX_BOOTSTRAP=NO'
    echo 'USER_DATA_READ=NO'
    echo 'SMS_SENT=NO'
    echo 'V4_MODIFIED=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'RUNTIME_CART_ADAPTER_APPROVED=NO'
    echo 'SECRETS_PRINTED=NO'
    exit "$rc"
}
trap finish EXIT
fail() { echo "FAIL: $1" >&2; exit 2; }
pin() {
    [[ -f "$1" && -r "$1" && ! -L "$1" && "$(realpath -e "$1")" == "$1" ]] ||
        fail 'untrusted source path'
    printf '%s  %s\n' "$2" "$1" | sha256sum -c --status ||
        fail 'installed source SHA drift'
}

echo '=== 1. HOST / ENVIRONMENT ==='
[[ $# -eq 1 ]] || fail 'one pinned parser argument required'
[[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] ||
    fail 'wrong host or root'
[[ -d "$SITE" && ! -L "$SITE" && "$(realpath -e "$SITE")" == "$SITE" ]] ||
    fail 'unexpected site root'
[[ -d "$CONTROLLERS" && ! -L "$CONTROLLERS" &&
   "$(realpath -e "$CONTROLLERS")" == "$CONTROLLERS" ]] ||
    fail 'unexpected V4 controller directory'
for cmd in php git sha256sum realpath find stat wc; do
    command -v "$cmd" >/dev/null || fail "missing utility $cmd"
done
[[ -f "$PROBE" && ! -L "$PROBE" && -r "$PROBE" &&
   "$(git hash-object "$PROBE")" == "$PROBE_BLOB" ]] ||
    fail 'unverified static PHP probe'
echo 'PASS: exact host/root and immutable parser'

echo
echo '=== 2. PINNED SOURCE / PHP LEXER ==='
pin "$ROUTER" "$ROUTER_SHA"
pin "$CART" "$CART_SHA"
pin "$RESTAURANTS" "$RESTAURANTS_SHA"
echo 'PASS: current V4 router, Cart, Restaurants SHA matches observed installed source'
if php -n -r 'exit(function_exists("token_get_all") ? 0 : 2);' 2>/dev/null; then
    echo 'TOKENIZER_MODE=BUILT_IN'
else
    ext="$(php -n -r 'echo ini_get("extension_dir");')" ||
        fail 'PHP system extension directory unknown'
    case "$ext" in /usr/lib/php/*) ;; *) fail 'unsafe PHP extension directory';; esac
    module="$ext/tokenizer.so"
    [[ -f "$module" && -r "$module" && ! -L "$module" &&
       "$(realpath -e "$module")" == "$module" &&
       "$(stat -c %u "$module")" == 0 ]] ||
        fail 'root-owned trusted tokenizer unavailable'
    PHP_ARGS=(-n -d "extension=$module")
    php "${PHP_ARGS[@]}" -r 'exit(function_exists("token_get_all")?0:2);' ||
        fail 'PHP lexer unavailable'
    echo 'TOKENIZER_MODE=SYSTEM_ROOT_OWNED_MODULE'
fi
php "${PHP_ARGS[@]}" -l "$PROBE" >/dev/null || fail 'probe PHP syntax'
php "${PHP_ARGS[@]}" "$PROBE" --self-test || fail 'probe string/comment redaction selftest'
echo 'PASS: source-only method analyzer selftested'

echo
echo '=== 3. EXACT EXISTING CART SERVICE FILE LOOKUP ==='
SERVICE_ROOT="$SITE/local/php_interface"
[[ -d "$SERVICE_ROOT" && ! -L "$SERVICE_ROOT" &&
   "$(realpath -e "$SERVICE_ROOT")" == "$SERVICE_ROOT" ]] ||
    fail 'site library root invalid'
SERVICE_COUNT="$(find "$SERVICE_ROOT" -maxdepth 6 -type f -name CartApiService.php -print | wc -l)"
[[ "$SERVICE_COUNT" =~ ^[0-9]+$ ]] && (( SERVICE_COUNT <= 1 )) ||
    fail 'multiple CartApiService definitions'
if (( SERVICE_COUNT == 1 )); then
    SERVICE="$(find "$SERVICE_ROOT" -maxdepth 6 -type f -name CartApiService.php -print -quit)"
    [[ -f "$SERVICE" && -r "$SERVICE" && ! -L "$SERVICE" &&
       "$(realpath -e "$SERVICE")" == "$SERVICE" ]] ||
        fail 'unsafe V4 CartApiService source'
    SERVICE_BEFORE="$(sha256sum "$SERVICE")"
    echo "CART_API_SERVICE_FILE=$SERVICE"
else
    echo 'CART_API_SERVICE_FILE=NOT_FOUND_BY_EXACT_FILENAME'
fi

echo
echo '=== 4. PINNED CART / RESTAURANTS CONTRACT ONLY ==='
php "${PHP_ARGS[@]}" "$PROBE" --source-only "$CART" "$RESTAURANTS" "$SERVICE" ||
    fail 'targeted service contract incomplete'

pin "$ROUTER" "$ROUTER_SHA"
pin "$CART" "$CART_SHA"
pin "$RESTAURANTS" "$RESTAURANTS_SHA"
if [[ "$SERVICE" != ABSENT ]]; then
    [[ "$(sha256sum "$SERVICE")" == "$SERVICE_BEFORE" ]] ||
        fail 'CartApiService source mutated while scanned'
fi
echo 'PASS: V4 router, Cart, Restaurants and CartApiService unchanged'
echo 'MAIN_PUBLIC_API=https://evrasia.rest/api/v5'
echo 'NEXT_STEP=CONNECT_REAL_CART_READ_TO_V5'
