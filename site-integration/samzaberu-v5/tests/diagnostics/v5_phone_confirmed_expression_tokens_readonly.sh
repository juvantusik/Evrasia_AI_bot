#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
REL='local/php_interface/Controllers/V4/Auth.php'
FILE="$ROOT/$REL"
PIN='72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362'
PASS_COUNT=0
FAIL_COUNT=0
TOKENIZER_EXECUTED=NO
pass() { PASS_COUNT=$((PASS_COUNT+1)); printf 'PASS: %s\n' "$1"; }
fail() { FAIL_COUNT=$((FAIL_COUNT+1)); printf 'FAIL: %s\n' "$1"; }

finish() {
  local rc=$?
  trap - EXIT
  printf '\n=== 5. RESULT ===\n'
  printf 'MODE=READ_ONLY\nPRODUCTION_APPLICATION_WRITE=NO\n'
  printf 'DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n'
  printf 'BITRIX_BOOTSTRAP=NO\nSITE_PHP_EXECUTED=NO\n'
  printf 'PHP_TOKENIZER_EXECUTED=%s\nPHP_INI_LOADED=NO\n' "$TOKENIZER_EXECUTED"
  printf 'USER_DATA_READ=NO\nCONFIG_FILES_READ=NO\n'
  printf 'SOURCE_LINES_PRINTED=NO\nSECRETS_PRINTED=NO\nSMS_SENT=NO\n'
  printf 'PHONE_EVENT_VERSION_WRITER=NOT_PROVEN\n'
  printf 'SAME_PHONE_RECONFIRM_ATOMICITY=NOT_PROVEN\n'
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
      ! -d "$ROOT/local/php_interface/Controllers/V4" ]]; then
  fail 'pinned root/host/source guard'
  exit 1
fi
for bin in php sha256sum realpath wc stat; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    fail "required tool unavailable: $bin"
    exit 1
  fi
done
pass 'root/host/read-only tool guards'

printf '\n=== 2. EXACT V4 SOURCE SHA GUARD ===\n'
if [[ ! -f "$FILE" || ! -r "$FILE" || -L "$FILE" ||
      "$(realpath -- "$FILE")" != "$FILE" ]]; then
  fail 'source path unavailable or changed'
  exit 1
fi
bytes="$(wc -c < "$FILE")"
if ((bytes < 100 || bytes > 1200000)); then
  fail 'source size guard failed'
  exit 1
fi
sha="$(sha256sum -- "$FILE")"
sha="${sha%% *}"
if [[ "$sha" != "$PIN" ]]; then
  fail 'source SHA diverged'
  exit 1
fi
printf 'SOURCE=%s\nSOURCE_SHA256=%s\n' "$REL" "$sha"
pass 'production source checksum pinned'

printf '\n=== 3. SAFE PHP CLI TOKENIZER AVAILABILITY ===\n'
# PHP -n disables php.ini and any automatically enabled shared modules.
# On the production Debian CLI tokenizer may be packaged as tokenizer.so.
# Enable ONLY the root-owned system tokenizer, not php.ini or third-party modules.
PHP_ARGS=(-n)
TOKENIZER_LOAD_MODE='UNAVAILABLE'
if php -n -r 'exit(function_exists("token_get_all") ? 0 : 2);' 2>/dev/null; then
  TOKENIZER_LOAD_MODE='BUILT_IN'
else
  EXTENSION_DIR="$(php -n -r 'echo ini_get("extension_dir");' 2>/dev/null)" || {
    fail 'cannot resolve safe default PHP extension directory'
    exit 1
  }
  case "$EXTENSION_DIR" in
    /usr/lib/php/*) ;;
    *)
      fail 'PHP extension_dir is not under trusted /usr/lib/php; no module loaded'
      exit 1
      ;;
  esac
  TOKENIZER_MODULE="$EXTENSION_DIR/tokenizer.so"
  if [[ ! -f "$TOKENIZER_MODULE" || ! -r "$TOKENIZER_MODULE" ||
        -L "$TOKENIZER_MODULE" ||
        "$(realpath -- "$TOKENIZER_MODULE")" != "$TOKENIZER_MODULE" ||
        "$(stat -c '%u' -- "$TOKENIZER_MODULE")" != '0' ]]; then
    fail 'trusted root-owned tokenizer.so not found at expected PHP extension path'
    exit 1
  fi
  PHP_ARGS=(-n -d "extension=$TOKENIZER_MODULE")
  if ! php "${PHP_ARGS[@]}" -r 'exit(function_exists("token_get_all") ? 0 : 2);' 2>/dev/null; then
    fail 'token_get_all unavailable with explicit tokenizer.so; no further modules loaded'
    exit 1
  fi
  TOKENIZER_LOAD_MODE='EXPLICIT_ROOT_OWNED_TOKENIZER_SO'
fi
printf 'TOKENIZER_LOAD_MODE=%s\n' "$TOKENIZER_LOAD_MODE"
PARSER="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/V5PhoneConfirmedExpressionTokens.php"
if [[ ! -f "$PARSER" || ! -r "$PARSER" || -L "$PARSER" ]]; then
  fail 'expected static tokenizer not present'
  exit 1
fi
pass 'PHP tokenizer available with php.ini disabled'

printf '\n=== 4. EXACT TWO CONFIRMED EXPRESSIONS / SANITIZED TOKEN SHAPES ===\n'
TOKENIZER_EXECUTED=YES
if ! php "${PHP_ARGS[@]}" "$PARSER" --source-only "$FILE"; then
  fail 'static target token classification failed'
  exit 1
fi
pass 'targeted source expressions classified without source value disclosure'
