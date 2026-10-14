#!/usr/bin/env bash
# Narrow source-only receiver-binding hints for two pinned object check anchors.
set -Eeuo pipefail
umask 077
ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
PASS_COUNT=0
FAIL_COUNT=0
PHP_SCANNER_EXECUTED=NO
TOKENIZER_LOAD_MODE=UNAVAILABLE
CONFIG_FILES_READ=NO
DISCOVERED_SOURCE_COUNT=0
REPORT=''
PART=''
pass(){ PASS_COUNT=$((PASS_COUNT+1)); printf 'PASS: %s\n' "$1"; }
fail(){ FAIL_COUNT=$((FAIL_COUNT+1)); printf 'FAIL: %s\n' "$1"; }
finish(){
  local rc=$?
  trap - EXIT
  [[ -z "$REPORT" ]] || rm -f -- "$REPORT"
  [[ -z "$PART" ]] || rm -f -- "$PART"
  printf '\n=== 5. RESULT ===\n'
  printf 'MODE=READ_ONLY\n'
  printf 'PRODUCTION_APPLICATION_WRITE=NO\n'
  printf 'DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n'
  printf 'BITRIX_BOOTSTRAP=NO\nSITE_PHP_EXECUTED=NO\n'
  printf 'SMS_SENT=NO\nPHP_INI_LOADED=NO\n'
  printf 'SOURCE_STATEMENTS_PRINTED=NO\nSOURCE_VALUES_PRINTED=NO\n'
  printf 'USER_DATA_READ=NO\nCONFIG_FILES_READ=%s\n' "$CONFIG_FILES_READ"
  printf 'PHP_SCANNER_EXECUTED=%s\n' "$PHP_SCANNER_EXECUTED"
  printf 'TOKENIZER_LOAD_MODE=%s\n' "$TOKENIZER_LOAD_MODE"
  printf 'TARGET_PHP_FILES=%s\n' "$DISCOVERED_SOURCE_COUNT"
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
if [[ "$(hostname -s)" != 'evrasia' || "$(id -u)" -ne 0 ||
      ! -d "$ROOT/local/php_interface/Controllers/V4" ||
      ! -d "$ROOT/local/php_interface/lib" ]]; then
  fail 'production host, root or source scope guard failed'
  exit 1
fi
for bin in php sha256sum realpath wc stat mktemp cat; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    fail "read-only utility unavailable: $bin"
    exit 1
  fi
done
pass 'production host/root and known source scope'

printf '\n=== 2. PINNED V4 SOURCE BASELINES ===\n'
while IFS='|' read -r relative expected; do
  [[ -n "$relative" ]] || continue
  file="$ROOT/$relative"
  if [[ ! -f "$file" || ! -r "$file" || -L "$file" ||
        "$(realpath -- "$file")" != "$file" ]]; then
    fail "source guard: $relative"
    exit 1
  fi
  bytes="$(wc -c < "$file")"
  if ((bytes<50 || bytes>1200000)); then
    fail "source size guard: $relative"
    exit 1
  fi
  sha="$(sha256sum -- "$file")"
  sha="${sha%% *}"
  if [[ "$sha" != "$expected" ]]; then
    fail "source checksum changed: $relative"
    exit 1
  fi
  printf 'SOURCE=%s\nSOURCE_SHA256=%s\n' "$relative" "$sha"
  pass "pinned source intact: $relative"
done <<'PINS'
local/php_interface/Controllers/V4/Auth.php|72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362
local/php_interface/lib/PhoneAuth.php|a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27
local/php_interface/Controllers/V4/PinCode.php|7734ddbaba0e7ec9ddfc9f0a9008c0da0c0a1523e505e52caebac2bbaf637cd0
PINS

printf '\n=== 3. TWO PINNED OBJECT CHECK TARGETS ===\n'
DISCOVERED_SOURCE_COUNT=2
printf 'TARGET_PHP_FILES=2\nEXPECTED_OBJECT_OPERATOR_LINE=46\n'
printf 'TARGET_SOURCE=Controllers/V4/PinCode.php\nTARGET_SOURCE=lib/PhoneAuth.php\n'
pass 'two previously located calls; no broad source search'

printf '\n=== 4. MASKED RECEIVER BINDING HINTS ===\n'
PHP_ARGS=(-n -d display_errors=stderr -d log_errors=0 -d zend.exception_ignore_args=1)
if php -n -r 'exit(function_exists("token_get_all")?0:2);' 2>/dev/null; then
  TOKENIZER_LOAD_MODE=BUILT_IN
else
  ext="$(php -n -r 'echo ini_get("extension_dir");' 2>/dev/null)" || {
    fail 'PHP system module directory unavailable'
    exit 1
  }
  case "$ext" in /usr/lib/php/*) ;; *)
    fail 'PHP extension directory is not trusted'
    exit 1 ;;
  esac
  module="$ext/tokenizer.so"
  if [[ ! -f "$module" || ! -r "$module" || -L "$module" ||
        "$(realpath -- "$module")" != "$module" ||
        "$(stat -c '%u' -- "$module")" != 0 ]]; then
    fail 'trusted tokenizer.so unavailable'
    exit 1
  fi
  PHP_ARGS=(-n -d display_errors=stderr -d log_errors=0 -d zend.exception_ignore_args=1 -d "extension=$module")
  if ! php "${PHP_ARGS[@]}" -r 'exit(function_exists("token_get_all")?0:2);' 2>/dev/null; then
    fail 'trusted system tokenizer could not be loaded'
    exit 1
  fi
  TOKENIZER_LOAD_MODE=EXPLICIT_ROOT_OWNED_TOKENIZER_SO
fi
printf 'TOKENIZER_LOAD_MODE=%s\n' "$TOKENIZER_LOAD_MODE"
PARSER="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/V5ObjectCheckReceiverBinding.php"
if [[ ! -f "$PARSER" || ! -r "$PARSER" || -L "$PARSER" ]]; then
  fail 'static PHP lexical scanner unavailable'
  exit 1
fi
REPORT="$(mktemp /tmp/v5-receiver-binding-report.XXXXXXXX)"
PART="$(mktemp /tmp/v5-receiver-binding-part.XXXXXXXX)"
PHP_SCANNER_EXECUTED=YES
for label in Controllers/V4/PinCode.php lib/PhoneAuth.php; do
  case "$label" in
    Controllers/V4/PinCode.php) expected='7734ddbaba0e7ec9ddfc9f0a9008c0da0c0a1523e505e52caebac2bbaf637cd0' ;;
    lib/PhoneAuth.php) expected='a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27' ;;
  esac
  if ! php "${PHP_ARGS[@]}" "$PARSER" --source-only --receiver-line 46 \
      --source-label "$label" --expected-sha256 "$expected" \
      "$ROOT/local/php_interface/$label" > "$PART"; then
    fail 'pinned receiver-binding source anchor analysis did not complete'
    exit 1
  fi
  cat -- "$PART" >> "$REPORT"
done
cat -- "$REPORT"
pass 'two source-only receiver-binding hint reports completed'
