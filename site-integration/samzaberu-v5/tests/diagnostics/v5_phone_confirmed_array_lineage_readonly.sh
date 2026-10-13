#!/usr/bin/env bash
# Production Bitrix source-only: trace the exact CONFIRMED array receiver.
# Never prints original PHP statements, values, SQL, phones, SMS, or secrets.
set -Eeuo pipefail
umask 077

ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
REL='local/php_interface/Controllers/V4/Auth.php'
FILE="$ROOT/$REL"
PIN='72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362'
PASS_COUNT=0
FAIL_COUNT=0

pass() { PASS_COUNT=$((PASS_COUNT+1)); printf 'PASS: %s\n' "$1"; }
fail() { FAIL_COUNT=$((FAIL_COUNT+1)); printf 'FAIL: %s\n' "$1"; }

finish() {
  local rc=$?
  trap - EXIT
  printf '\n=== 5. RESULT ===\n'
  printf 'MODE=READ_ONLY\n'
  printf 'PRODUCTION_APPLICATION_WRITE=NO\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n'
  printf 'BITRIX_BOOTSTRAP=NO\nSITE_PHP_EXECUTED=NO\nSMS_SENT=NO\n'
  printf 'USER_DATA_READ=NO\nCONFIG_FILES_READ=NO\nSECRETS_PRINTED=NO\n'
  printf 'PHP_SOURCE_LINES_PRINTED=NO\n'
  printf 'PHONE_EVENT_VERSION_WRITER=NOT_PROVEN\n'
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
if [[ "$(hostname -s)" != 'evrasia' || "$(id -u)" -ne 0 ||
      ! -d "$ROOT/local/php_interface/Controllers/V4" ]]; then
  fail 'production host/root/source guard'
  exit 1
fi
for tool in awk sha256sum realpath wc; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    fail "missing read-only tool: $tool"
    exit 1
  fi
done
pass 'host/root and source-only guards'

printf '\n=== 2. PINNED V4 CONTROLLER SOURCE ===\n'
if [[ ! -f "$FILE" || ! -r "$FILE" || -L "$FILE" ||
      "$(realpath -- "$FILE")" != "$FILE" ]]; then
  fail 'pinned source path unavailable'
  exit 1
fi
bytes="$(wc -c < "$FILE")"
if ((bytes<100 || bytes>1200000)); then
  fail 'unexpected V4 controller source size'
  exit 1
fi
actual="$(sha256sum -- "$FILE" | awk '{print $1}')"
if [[ "$actual" != "$PIN" ]]; then
  fail 'V4 controller source fingerprint changed; no code inspected'
  exit 1
fi
printf 'SOURCE=%s\nSOURCE_SHA256=%s\n' "$REL" "$actual"
pass 'exact V4 source fingerprint unchanged'

printf '\n=== 3. CONFIRMED FIELD RECEIVER AND FLOW METADATA ===\n'
AWK_SOURCE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/v5_phone_confirmed_array_lineage.awk"
if [[ ! -f "$AWK_SOURCE" || ! -r "$AWK_SOURCE" || -L "$AWK_SOURCE" ]]; then
  fail 'source-only AWK analyzer unavailable'
  exit 1
fi
awk -f "$AWK_SOURCE" "$FILE" || {
  fail 'source changed or expected CONFIRMED lineage anchors unavailable'
  exit 1
}
pass 'field-array receiver and subsequent source call sites classified'

printf '\n=== 4. SCOPE / LIMITATIONS ===\n'
printf 'ORIGINAL_CODE_AND_VALUES_DISCLOSED=NO\n'
printf 'STATIC_FIELD_LINEAGE_IS_NOT_SQL_COMMIT_PROOF=YES\n'
printf 'NATIVE_CONFIRMED_EVENT_REQUIRES_SEPARATE_PROOF=YES\n'
pass 'no production runtime action performed'
