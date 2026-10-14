#!/usr/bin/env bash
# Read-only inventory of active PhoneAuth callers in bounded V4 PHP source.
set -Eeuo pipefail
umask 077
ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
PASS_COUNT=0
FAIL_COUNT=0
PHP_SCANNER_EXECUTED=NO
TOKENIZER_LOAD_MODE=UNAVAILABLE
CONFIG_FILES_READ=NO
DISCOVERED_SOURCE_COUNT=0
MANIFEST=''
SORTED=''
pass(){ PASS_COUNT=$((PASS_COUNT+1)); printf 'PASS: %s\n' "$1"; }
fail(){ FAIL_COUNT=$((FAIL_COUNT+1)); printf 'FAIL: %s\n' "$1"; }
finish(){
  local rc=$?
  trap - EXIT
  [[ -z "$MANIFEST" ]] || rm -f -- "$MANIFEST"
  [[ -z "$SORTED" ]] || rm -f -- "$SORTED"
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
  printf 'APPROVED_SOURCE_FILES=%s\n' "$DISCOVERED_SOURCE_COUNT"
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
for bin in php sha256sum realpath wc stat find sort mktemp; do
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
PINS

printf '\n=== 3. BOUNDED PHP SOURCE FILE SELECTION ===\n'
MANIFEST="$(mktemp /tmp/v5-phone-caller-manifest.XXXXXXXX)"
SORTED="$(mktemp /tmp/v5-phone-caller-sorted.XXXXXXXX)"
V4="$ROOT/local/php_interface/Controllers/V4"
LIB="$ROOT/local/php_interface/lib"
if ! find "$V4" -maxdepth 2 -type f -name '*.php' -print0 > "$MANIFEST"; then
  fail 'cannot enumerate V4 controller source names'
  exit 1
fi
if ! find "$LIB" -maxdepth 1 -type f \( -iname '*phone*.php' -o -iname '*auth*.php' -o -iname '*sms*.php' \) -print0 >> "$MANIFEST"; then
  fail 'cannot enumerate known phone/auth/sms library source names'
  exit 1
fi
if ! sort -zu "$MANIFEST" > "$SORTED"; then
  fail 'source list sorting failed'
  exit 1
fi
SOURCES=()
SKIPPED_SENSITIVE_NAMES=0
while IFS= read -r -d '' file; do
  if [[ "$file" != "$ROOT/local/php_interface/"* ]]; then
    fail 'source path outside approved root'
    exit 1
  fi
  relative="${file#"$ROOT/local/php_interface/"}"
  case "${relative,,}" in
    *config*|*secret*|*credential*|*password*|*token*|*settings*|*env*)
      SKIPPED_SENSITIVE_NAMES=$((SKIPPED_SENSITIVE_NAMES+1))
      continue
      ;;
  esac
  if [[ ! "$relative" =~ ^Controllers/V4/([A-Za-z0-9_.-]+/)?[A-Za-z0-9_.-]+[.]php$ &&
        ! "$relative" =~ ^lib/[A-Za-z0-9_.-]+[.]php$ ]]; then
    fail 'source filename failed whitelist'
    exit 1
  fi
  if [[ ! -f "$file" || ! -r "$file" || -L "$file" ||
        "$(realpath -- "$file")" != "$file" ]]; then
    fail 'source file path changed or symlink'
    exit 1
  fi
  bytes="$(wc -c < "$file")"
  if ((bytes<20 || bytes>1000000)); then
    fail 'source size outside bounded lexical scope'
    exit 1
  fi
  SOURCES+=("$file")
  if (("${#SOURCES[@]}" > 80)); then
    fail 'too many source files for approved bounded scan'
    exit 1
  fi
done < "$SORTED"
DISCOVERED_SOURCE_COUNT="${#SOURCES[@]}"
if ((DISCOVERED_SOURCE_COUNT==0)); then
  fail 'no approved PHP source files in V4 scope'
  exit 1
fi
printf 'SCOPE=V4_CONTROLLERS_AND_NAMED_PHONE_AUTH_SMS_LIBS\n'
printf 'APPROVED_SOURCE_FILES=%s\n' "$DISCOVERED_SOURCE_COUNT"
printf 'SKIPPED_SENSITIVE_FILENAMES=%s\n' "$SKIPPED_SENSITIVE_NAMES"
pass 'bounded source files, no config/secret filenames read'

printf '\n=== 4. PHP SOURCE-ONLY CALLER DISCOVERY ===\n'
PHP_ARGS=(-n)
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
  PHP_ARGS=(-n -d "extension=$module")
  if ! php "${PHP_ARGS[@]}" -r 'exit(function_exists("token_get_all")?0:2);' 2>/dev/null; then
    fail 'trusted system tokenizer could not be loaded'
    exit 1
  fi
  TOKENIZER_LOAD_MODE=EXPLICIT_ROOT_OWNED_TOKENIZER_SO
fi
printf 'TOKENIZER_LOAD_MODE=%s\n' "$TOKENIZER_LOAD_MODE"
PARSER="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/V5PhoneCheckActiveCallerDiscovery.php"
if [[ ! -f "$PARSER" || ! -r "$PARSER" || -L "$PARSER" ]]; then
  fail 'static PHP lexical scanner unavailable'
  exit 1
fi
PHP_SCANNER_EXECUTED=YES
if ! php "${PHP_ARGS[@]}" "$PARSER" --source-only --approved-scope "${SOURCES[@]}"; then
  fail 'active PhoneAuth call-site discovery did not complete'
  exit 1
fi
pass 'source-only active PHP caller inventory completed'
