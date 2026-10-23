#!/usr/bin/env bash
# Single-purpose PRODUCTION READ_ONLY inventory: V4 route registrations and
# direct V4 controller names/method DECLARATIONS. No code/include/DB/HTTP calls.
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
ROUTER="$SITE/api/v4/router.php"
CONTROLLERS="$SITE/local/php_interface/Controllers/V4"
PIN='3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50'
PARSER_PIN='159e83349129f2927402b625425b7be64c838095'
PARSER="${1:-}"
SHAS=''
PHP_ARGS=(-n)
PASS_COUNT=0

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 2
}
pass() {
    PASS_COUNT=$((PASS_COUNT+1))
    printf 'PASS: %s\n' "$1"
}
finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$SHAS" && "$SHAS" == /tmp/ev-v4-route-hashes.* &&
          -f "$SHAS" && ! -L "$SHAS" ]]; then
        rm -f -- "$SHAS" || true
    fi
    echo
    echo '=== 5. RESULT ==='
    if ((rc==0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    printf 'FINAL_RC=%s\nPASS_COUNT=%s\n' "$rc" "$PASS_COUNT"
    echo 'MODE=READ_ONLY'
    echo 'PRODUCTION_FILE_WRITE=NO'
    echo 'DIAGNOSTIC_TEMP_FILES_ONLY=YES'
    echo 'BITRIX_BOOTSTRAP=NO'
    echo 'SITE_PHP_SOURCE_EXECUTED=NO'
    echo 'PHP_INI_LOADED=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'USER_DATA_READ=NO'
    echo 'SMS_SENT=NO'
    echo 'SECRETS_PRINTED=NO'
    echo 'V4_MODIFIED=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
    exit "$rc"
}
trap finish EXIT

echo '=== 1. HOST / ENVIRONMENT ==='
[[ $# -eq 1 ]] || fail 'Exactly one pinned parser file required'
[[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] || fail 'Wrong host/root; no source read'
[[ -d "$SITE" && ! -L "$SITE" &&
   "$(realpath -e "$SITE")" == "$SITE" ]] || fail 'Site root drift'
[[ -d "$CONTROLLERS" && ! -L "$CONTROLLERS" &&
   "$(realpath -e "$CONTROLLERS")" == "$CONTROLLERS" ]] || fail 'V4 controllers missing'
for cmd in php sha256sum realpath git find sort xargs mktemp stat grep; do
    command -v "$cmd" >/dev/null || fail "Missing diagnostic utility $cmd"
done
[[ -f "$ROUTER" && -r "$ROUTER" && ! -L "$ROUTER" &&
   "$(realpath -e "$ROUTER")" == "$ROUTER" ]] || fail 'Unsafe V4 router'
[[ -f "$PARSER" && -r "$PARSER" && ! -L "$PARSER" ]] || fail 'Unsafe parser'
[[ "$(git hash-object "$PARSER")" == "$PARSER_PIN" ]] || fail 'Parser blob drift'
pass 'evrasia, root, site path and pinned diagnostic PHP'

echo
echo '=== 2. PINNED V4 ROUTER / PHP SAFETY ==='
printf '%s  %s\n' "$PIN" "$ROUTER" | sha256sum -c --status \
    || fail 'V4 router changed: STOP before business mapping'
pass 'current installed V4 router SHA256 exactly matches archived production pin'

if php -n -r 'exit(function_exists("token_get_all")?0:2);' 2>/dev/null; then
    echo 'TOKENIZER_MODE=BUILT_IN'
else
    ext_dir="$(php -n -r 'echo ini_get("extension_dir");')" \
        || fail 'Cannot resolve tokenizer system extension dir'
    case "$ext_dir" in /usr/lib/php/*) ;; *) fail 'Unexpected extension dir';; esac
    module="$ext_dir/tokenizer.so"
    [[ -f "$module" && -r "$module" && ! -L "$module" &&
       "$(realpath -e "$module")" == "$module" &&
       "$(stat -c %u "$module")" == 0 ]] \
        || fail 'Trusted root-owned tokenizer.so unavailable'
    PHP_ARGS=(-n -d "extension=$module")
    php "${PHP_ARGS[@]}" -r 'exit(function_exists("token_get_all")?0:2);' \
        || fail 'Explicit system tokenizer could not load'
    echo 'TOKENIZER_MODE=EXPLICIT_SYSTEM_MODULE'
fi
php "${PHP_ARGS[@]}" -l "$PARSER" >/dev/null || fail 'Parser syntax'
php "${PHP_ARGS[@]}" "$PARSER" --self-test \
    || fail 'Lexer refused clean route declarations / comment masking'
pass 'PHP tokenizer + isolated parser selftest; no Bitrix bootstrap'

SHAS="$(mktemp /tmp/ev-v4-route-hashes.XXXXXXXX)"
find "$CONTROLLERS" -maxdepth 1 -type f -name '*.php' -print0 \
  | sort -z | xargs -0 -r sha256sum > "$SHAS"
[[ -s "$SHAS" ]] || fail 'No direct V4 controllers found'
if find "$CONTROLLERS" -maxdepth 1 -type l -name '*.php' -print -quit \
    | grep -q .; then
    fail 'Symlink V4 PHP controller present; refused source scan'
fi

php "${PHP_ARGS[@]}" "$PARSER" --source-only "$ROUTER" "$CONTROLLERS" \
    || fail 'Static route/controller declaration readout failed'
pass 'V4 route templates and controller signatures collected without executing code'

echo
echo '=== VERIFY READ-ONLY ==='
printf '%s  %s\n' "$PIN" "$ROUTER" | sha256sum -c --status \
    || fail 'Router changed during inspection'
sha256sum -c --status "$SHAS" || fail 'Controller bytes changed during source scan'
pass 'installed V4 router and all scanned controllers remained unchanged'

echo 'MAIN_MOBILE_API_DOMAIN=https://evrasia.rest'
echo 'V4_BUSINESS_FACADE_READY_FOR_SOURCE_MAPPING=YES'
echo 'NATIVE_V4_CODE_EXECUTED=NO'
echo 'V5_AUTH_RELEASE_GATE=DENIED'
