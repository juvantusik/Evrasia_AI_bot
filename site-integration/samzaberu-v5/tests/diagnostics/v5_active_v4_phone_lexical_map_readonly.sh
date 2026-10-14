#!/usr/bin/env bash
# Pin-verified production V4 source-only PHP active/comment code path map.
set -Eeuo pipefail
umask 077

ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
PASS_COUNT=0
FAIL_COUNT=0
TOKENIZER_LOAD_MODE=UNAVAILABLE
PHP_ANALYSIS_EXECUTED=NO

pass() { PASS_COUNT=$((PASS_COUNT+1)); printf 'PASS: %s\n' "$1"; }
fail() { FAIL_COUNT=$((FAIL_COUNT+1)); printf 'FAIL: %s\n' "$1"; }
finish() {
  local rc=$?
  trap - EXIT
  printf '\n=== 5. RESULT ===\n'
  printf 'MODE=READ_ONLY\n'
  printf 'DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n'
  printf 'PRODUCTION_APPLICATION_WRITE=NO\nBITRIX_BOOTSTRAP=NO\n'
  printf 'PHP_SITE_CODE_EXECUTED=NO\nPHP_INI_LOADED=NO\n'
  printf 'SOURCE_VALUES_PRINTED=NO\nSMS_SENT=NO\n'
  printf 'USER_DATA_READ=NO\nCONFIG_FILES_READ=NO\n'
  printf 'TOKENIZER_LOAD_MODE=%s\n' "$TOKENIZER_LOAD_MODE"
  printf 'PHP_ANALYSIS_EXECUTED=%s\n' "$PHP_ANALYSIS_EXECUTED"
  printf 'NATIVE_PHONE_ATOMICITY_PROVEN=NO\n'
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
if [[ "$(hostname -s)" != 'evrasia' || "$(id -u)" -ne 0 ||
      ! -d "$ROOT/local/php_interface/Controllers/V4" ||
      ! -d "$ROOT/local/php_interface/lib" ]]; then
  fail 'expected production host/root/directories'
  exit 1
fi
for cmd in php realpath sha256sum wc stat; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    fail "read-only utility unavailable: $cmd"
    exit 1
  fi
done
pass 'host/root and static-source guards'

printf '\n=== 2. EXACT TWO SOURCE SHA-256 PINS ===\n'
AUTH="$ROOT/local/php_interface/Controllers/V4/Auth.php"
HELPER="$ROOT/local/php_interface/lib/PhoneAuth.php"
while IFS='|' read -r rel expected; do
  [[ -n "$rel" ]] || continue
  source="$ROOT/$rel"
  if [[ ! -f "$source" || ! -r "$source" || -L "$source" ||
        "$(realpath -- "$source")" != "$source" ]]; then
    fail "pinned source missing or symlink: $rel"
    exit 1
  fi
  bytes="$(wc -c < "$source")"
  if ((bytes<50 || bytes>1200000)); then
    fail "source size out of trusted bounds: $rel"
    exit 1
  fi
  actual="$(sha256sum -- "$source")"
  actual="${actual%% *}"
  if [[ "$actual" != "$expected" ]]; then
    fail "SHA changed since source baseline: $rel"
    exit 1
  fi
  printf 'SOURCE=%s\nSOURCE_SHA256=%s\n' "$rel" "$actual"
  pass "pinned source intact: $rel"
done <<'PINS'
local/php_interface/Controllers/V4/Auth.php|72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362
local/php_interface/lib/PhoneAuth.php|a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27
PINS

printf '\n=== 3. TRUSTED TOKENIZER MODULE (NO PHP.INI) ===\n'
PHP_ARGS=(-n)
if php -n -r 'exit(function_exists("token_get_all") ? 0 : 2);' 2>/dev/null; then
  TOKENIZER_LOAD_MODE=BUILT_IN
else
  ext="$(php -n -r 'echo ini_get("extension_dir");' 2>/dev/null)" || {
    fail 'cannot discover PHP extension directory'
    exit 1
  }
  case "$ext" in
    /usr/lib/php/*) ;;
    *)
      fail 'non-system PHP extension directory'
      exit 1
      ;;
  esac
  module="$ext/tokenizer.so"
  if [[ ! -f "$module" || ! -r "$module" || -L "$module" ||
        "$(realpath -- "$module")" != "$module" ||
        "$(stat -c '%u' -- "$module")" != '0' ]]; then
    fail 'no root-owned system tokenizer.so at pinned extension directory'
    exit 1
  fi
  PHP_ARGS=(-n -d "extension=$module")
  if ! php "${PHP_ARGS[@]}" -r 'exit(function_exists("token_get_all") ? 0 : 2);' 2>/dev/null; then
    fail 'trusted tokenizer cannot be loaded'
    exit 1
  fi
  TOKENIZER_LOAD_MODE=EXPLICIT_ROOT_OWNED_TOKENIZER_SO
fi
printf 'TOKENIZER_LOAD_MODE=%s\n' "$TOKENIZER_LOAD_MODE"
PARSER="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/V5ActiveV4PhoneLexicalMap.php"
if [[ ! -f "$PARSER" || ! -r "$PARSER" || -L "$PARSER" ]]; then
  fail 'static parser missing or symlink'
  exit 1
fi
pass 'trusted PHP lexer ready without php.ini'

printf '\n=== 4. ACTIVE V4 CODE VS COMMENTED TEXT MAP ===\n'
PHP_ANALYSIS_EXECUTED=YES
if ! php "${PHP_ARGS[@]}" "$PARSER" --source-only "$AUTH" "$HELPER"; then
  fail 'source-only active PHP map could not be completed'
  exit 1
fi
pass 'only tokenized the two known V4 source files'
