#!/usr/bin/env bash
# Production read-only: two active checks and five returns in PhoneAuth::check only.
set -Eeuo pipefail
umask 077
ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
REL='local/php_interface/lib/PhoneAuth.php'
PIN='a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27'
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
  printf 'PRODUCTION_APPLICATION_WRITE=NO\nSMS_SENT=NO\nPHP_INI_LOADED=NO\n'
  printf 'SOURCE_VALUES_PRINTED=NO\nSOURCE_STATEMENTS_PRINTED=NO\n'
  printf 'USER_DATA_READ=NO\nCONFIG_FILES_READ=NO\n'
  printf 'PARSER_EXECUTED=%s\n' "$PARSER_EXECUTED"
  printf 'TOKENIZER_LOAD_MODE=%s\n' "$TOKENIZER_LOAD_MODE"
  printf 'NATIVE_PHONE_VERIFICATION_PROVEN=NO\n'
  printf 'PHONE_EVENT_VERSION_WRITER=NOT_PROVEN\n'
  printf 'PASS_COUNT=%s\nFAIL_COUNT=%s\n' "$PASS_COUNT" "$FAIL_COUNT"
  if ((rc==0 && FAIL_COUNT==0)); then
    printf 'FINAL_STATUS=PASS\nFINAL_RC=0\n'
  else
    printf 'FINAL_STATUS=FAIL\nFINAL_RC=1\n'
  fi
  printf 'TERMINAL_WILL_STAY_OPEN=YES\n'
}
trap finish EXIT

printf '=== 1. HOST / ENVIRONMENT ===\n'
if [[ "$(hostname -s)" != evrasia || "$(id -u)" -ne 0 ||
      ! -d "$ROOT/local/php_interface/lib" ]]; then
  fail 'expected root/production/source path guard';exit 1
fi
for cmd in php sha256sum realpath wc stat; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    fail "missing read-only utility: $cmd";exit 1
  fi
done
pass 'production/root/source-only guards'

printf '\n=== 2. EXACT SOURCE SHA ===\n'
FILE="$ROOT/$REL"
if [[ ! -f "$FILE" || ! -r "$FILE" || -L "$FILE" ||
      "$(realpath -- "$FILE")" != "$FILE" ]]; then
  fail 'source file unavailable or symlinked';exit 1
fi
size="$(wc -c < "$FILE")"
if ((size<50 || size>1200000)); then
  fail 'source size outside guard';exit 1
fi
digest="$(sha256sum -- "$FILE")"
digest="${digest%% *}"
if [[ "$digest" != "$PIN" ]]; then
  fail 'pinned PhoneAuth.php source changed';exit 1
fi
printf 'SOURCE=%s\nSOURCE_SHA256=%s\n' "$REL" "$digest"
pass 'exact production PhoneAuth.php fingerprint'

printf '\n=== 3. TRUSTED PHP TOKENIZER (NO PHP.INI) ===\n'
PHP_ARGS=(-n)
if php -n -r 'exit(function_exists("token_get_all")?0:2);' 2>/dev/null; then
  TOKENIZER_LOAD_MODE=BUILT_IN
else
  dir="$(php -n -r 'echo ini_get("extension_dir");' 2>/dev/null)" || {
    fail 'default PHP extension directory unavailable';exit 1
  }
  case "$dir" in /usr/lib/php/*) ;; *) fail 'untrusted extension dir';exit 1;; esac
  module="$dir/tokenizer.so"
  if [[ ! -f "$module" || ! -r "$module" || -L "$module" ||
        "$(realpath -- "$module")" != "$module" ||
        "$(stat -c '%u' -- "$module")" != '0' ]]; then
    fail 'trusted root-owned tokenizer.so unavailable';exit 1
  fi
  PHP_ARGS=(-n -d "extension=$module")
  if ! php "${PHP_ARGS[@]}" -r 'exit(function_exists("token_get_all")?0:2);' 2>/dev/null; then
    fail 'system tokenizer extension failed to load';exit 1
  fi
  TOKENIZER_LOAD_MODE=EXPLICIT_ROOT_OWNED_TOKENIZER_SO
fi
printf 'TOKENIZER_LOAD_MODE=%s\n' "$TOKENIZER_LOAD_MODE"
PARSER="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/V5PhoneAuthCheckReturnKinds.php"
if [[ ! -f "$PARSER" || ! -r "$PARSER" || -L "$PARSER" ]]; then
  fail 'trusted tokenizer parser missing';exit 1
fi
pass 'source-only lexer available'

printf '\n=== 4. ACTIVE CHECK BRANCH AND RETURN EXPRESSION KINDS ===\n'
PARSER_EXECUTED=YES
if ! php "${PHP_ARGS[@]}" "$PARSER" --source-only "$FILE"; then
  fail 'active check branch/return classification incomplete';exit 1
fi
pass 'active check control shapes classified'
