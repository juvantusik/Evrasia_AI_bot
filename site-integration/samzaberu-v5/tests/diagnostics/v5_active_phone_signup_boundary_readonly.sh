#!/usr/bin/env bash
# Guarded read-only V4 active signup/PhoneAuth boundary.
set -Eeuo pipefail
umask 077
ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
PASS_COUNT=0
FAIL_COUNT=0
PARSER_EXECUTED=NO
TOKENIZER_LOAD_MODE=UNAVAILABLE
pass(){ PASS_COUNT=$((PASS_COUNT+1)); printf 'PASS: %s\n' "$1"; }
fail(){ FAIL_COUNT=$((FAIL_COUNT+1)); printf 'FAIL: %s\n' "$1"; }
finish(){
  local rc=$?
  trap - EXIT
  printf '\n=== 5. RESULT ===\n'
  printf 'MODE=READ_ONLY\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n'
  printf 'BITRIX_BOOTSTRAP=NO\nSITE_PHP_EXECUTED=NO\n'
  printf 'PRODUCTION_APPLICATION_WRITE=NO\n'
  printf 'SMS_SENT=NO\nPHP_INI_LOADED=NO\n'
  printf 'SOURCE_VALUES_PRINTED=NO\nSOURCE_STATEMENTS_PRINTED=NO\n'
  printf 'CONFIG_FILES_READ=NO\nUSER_DATA_READ=NO\n'
  printf 'PARSER_EXECUTED=%s\n' "$PARSER_EXECUTED"
  printf 'TOKENIZER_LOAD_MODE=%s\n' "$TOKENIZER_LOAD_MODE"
  printf 'NATIVE_PHONE_WRITE_ATOMICITY=NOT_PROVEN\n'
  printf 'SAME_PHONE_RECONFIRM_ATOMICITY=NOT_PROVEN\n'
  printf 'PASS_COUNT=%s\nFAIL_COUNT=%s\n' "$PASS_COUNT" "$FAIL_COUNT"
  if (( rc==0 && FAIL_COUNT==0 )); then
    printf 'FINAL_STATUS=PASS\nFINAL_RC=0\n'
  else
    printf 'FINAL_STATUS=FAIL\nFINAL_RC=1\n'
  fi
  printf 'TERMINAL_WILL_STAY_OPEN=YES\n'
}
trap finish EXIT
printf '=== 1. HOST / ENVIRONMENT ===\n'
if [[ "$(hostname -s)" != evrasia || "$(id -u)" -ne 0 ||
      ! -d "$ROOT/local/php_interface/Controllers/V4" ||
      ! -d "$ROOT/local/php_interface/lib" ]]; then
  fail 'expected host/root/source directories'
  exit 1
fi
for bin in php sha256sum realpath wc stat; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    fail "missing tool: $bin"
    exit 1
  fi
done
pass 'host/root and source directory guard'
printf '\n=== 2. EXACT PHP SOURCE SHA-256 ===\n'
AUTH="$ROOT/local/php_interface/Controllers/V4/Auth.php"
HELPER="$ROOT/local/php_interface/lib/PhoneAuth.php"
while IFS='|' read -r relative expected; do
  [[ -n "$relative" ]] || continue
  path="$ROOT/$relative"
  if [[ ! -f "$path" || ! -r "$path" || -L "$path" ||
        "$(realpath -- "$path")" != "$path" ]]; then
    fail "unexpected source path: $relative"
    exit 1
  fi
  bytes="$(wc -c < "$path")"
  if ((bytes<50 || bytes>1200000)); then
    fail "unexpected source size: $relative"
    exit 1
  fi
  digest="$(sha256sum -- "$path")"
  digest="${digest%% *}"
  if [[ "$digest" != "$expected" ]]; then
    fail "source SHA changed: $relative"
    exit 1
  fi
  printf 'SOURCE=%s\nSOURCE_SHA256=%s\n' "$relative" "$digest"
  pass "source pin intact: $relative"
done <<'PINS'
local/php_interface/Controllers/V4/Auth.php|72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362
local/php_interface/lib/PhoneAuth.php|a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27
PINS
printf '\n=== 3. PHP TOKENIZER WITHOUT PHP.INI ===\n'
PHP_ARGS=(-n)
if php -n -r 'exit(function_exists("token_get_all")?0:2);' 2>/dev/null; then
  TOKENIZER_LOAD_MODE=BUILT_IN
else
  directory="$(php -n -r 'echo ini_get("extension_dir");' 2>/dev/null)" || {
    fail 'PHP default extension dir unavailable'
    exit 1
  }
  case "$directory" in
    /usr/lib/php/*) ;;
    *) fail 'untrusted PHP extension dir'; exit 1 ;;
  esac
  module="$directory/tokenizer.so"
  if [[ ! -f "$module" || ! -r "$module" || -L "$module" ||
        "$(realpath -- "$module")" != "$module" ||
        "$(stat -c '%u' -- "$module")" != '0' ]]; then
    fail 'trusted tokenizer.so unavailable'
    exit 1
  fi
  PHP_ARGS=(-n -d "extension=$module")
  if ! php "${PHP_ARGS[@]}" -r 'exit(function_exists("token_get_all")?0:2);' 2>/dev/null; then
    fail 'tokenizer extension did not load'
    exit 1
  fi
  TOKENIZER_LOAD_MODE=EXPLICIT_ROOT_OWNED_TOKENIZER_SO
fi
printf 'TOKENIZER_LOAD_MODE=%s\n' "$TOKENIZER_LOAD_MODE"
PARSER="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/V5ActivePhoneSignupBoundary.php"
if [[ ! -f "$PARSER" || ! -r "$PARSER" || -L "$PARSER" ]]; then
  fail 'static source-only parser missing'
  exit 1
fi
pass 'tokenizer and guarded parser ready'
printf '\n=== 4. ACTIVE SIGNUP PHONE ADD / PHONEAUTH METHOD CALLS ===\n'
PARSER_EXECUTED=YES
if ! php "${PHP_ARGS[@]}" "$PARSER" --source-only "$AUTH" "$HELPER"; then
  fail 'source-only signup boundary analysis not complete'
  exit 1
fi
pass 'only pinned PHP files statically tokenized'
