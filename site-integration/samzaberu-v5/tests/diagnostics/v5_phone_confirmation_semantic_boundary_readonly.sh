#!/usr/bin/env bash
# Source-only semantic boundary: native phone confirmation, no Bitrix bootstrap.
set -Eeuo pipefail
umask 077

ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
FILE="$ROOT/local/php_interface/Controllers/V4/Auth.php"
PIN='72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362'
PASS_COUNT=0
FAIL_COUNT=0
pass(){ PASS_COUNT=$((PASS_COUNT+1)); printf 'PASS: %s\n' "$1"; }
fail(){ FAIL_COUNT=$((FAIL_COUNT+1)); printf 'FAIL: %s\n' "$1"; }
finish(){
  local rc=$?
  trap - EXIT
  printf '\n=== 5. RESULT ===\n'
  printf 'MODE=READ_ONLY\nPRODUCTION_APPLICATION_WRITE=NO\n'
  printf 'DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n'
  printf 'BITRIX_BOOTSTRAP=NO\nPHP_SITE_CODE_EXECUTED=NO\n'
  printf 'USER_DATA_READ=NO\nCONFIG_FILES_READ=NO\n'
  printf 'SOURCE_LINES_PRINTED=NO\nSECRETS_PRINTED=NO\nSMS_SENT=NO\n'
  printf 'PHONE_EVENT_VERSION_ATOMICITY=NOT_PROVEN\n'
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
  fail 'expected production host/root guard'
  exit 1
fi
for bin in awk sha256sum wc realpath; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    fail "missing tool: $bin"
    exit 1
  fi
done
pass 'production source-only host guard'

printf '\n=== 2. PINNED PHP SOURCE ===\n'
if [[ ! -f "$FILE" || ! -r "$FILE" || -L "$FILE" ||
      "$(realpath -- "$FILE")" != "$FILE" ]]; then
  fail 'source unavailable or path changed'
  exit 1
fi
bytes="$(wc -c < "$FILE")"
if ((bytes<100 || bytes>1200000)); then
  fail 'source size out of range'
  exit 1
fi
sha="$(sha256sum -- "$FILE" | awk '{print $1}')"
if [[ "$sha" != "$PIN" ]]; then
  fail 'source content changed since verified SHA'
  exit 1
fi
printf 'SOURCE=local/php_interface/Controllers/V4/Auth.php\nSOURCE_SHA256=%s\n' "$sha"
pass 'exact source SHA guard'

printf '\n=== 3. CONFIRMED ASSIGNMENT AND CONFIRM METHOD CLASSIFICATION ===\n'
AWK_SOURCE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/v5_phone_confirmation_semantic_boundary.awk"
if [[ ! -f "$AWK_SOURCE" || ! -r "$AWK_SOURCE" || -L "$AWK_SOURCE" ]]; then
  fail 'pinned semantic AWK source unavailable'
  exit 1
fi
if ! awk -f "$AWK_SOURCE" "$FILE"; then
  fail 'semantic source anchors not confirmed'
  exit 1
fi
pass 'bounded source classification completed'

printf '\n=== 4. INTERPRETATION LIMIT ===\n'
printf 'PHONE_CODE_VALIDATION_OR_SMS_DELIVERY_EXECUTED=NO\n'
printf 'SAME_NUMBER_RECONFIRM_BUMP_PROVEN=NO\n'
printf 'REAL_BITRIX_MYSQL_TRANSACTION_PROVEN=NO\n'
pass 'read-only source semantics; no runtime claim'
