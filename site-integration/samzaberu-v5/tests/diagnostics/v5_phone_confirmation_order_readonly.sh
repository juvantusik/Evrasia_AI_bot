#!/usr/bin/env bash
# SOURCE-ONLY, pin-verified V4 phone confirmation and native Bitrix writer ordering.
# Emits line numbers, class/method names and enum-like tags only. NEVER source lines.
set -Eeuo pipefail
umask 077

ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
PASS_COUNT=0
FAIL_COUNT=0

pass() { PASS_COUNT=$((PASS_COUNT + 1)); printf 'PASS: %s\n' "$1"; }
fail() { FAIL_COUNT=$((FAIL_COUNT + 1)); printf 'FAIL: %s\n' "$1"; }
finish() {
  local rc=$?
  trap - EXIT
  printf '\n=== 5. RESULT ===\n'
  printf 'MODE=READ_ONLY\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n'
  printf 'BITRIX_BOOTSTRAP=NO\nNATIVE_CODE_EXECUTED=NO\n'
  printf 'PRODUCTION_APPLICATION_WRITE=NO\nSMS_SENT=NO\n'
  printf 'USER_DATA_READ=NO\nCONFIG_FILES_READ=NO\n'
  printf 'SOURCE_LINES_PRINTED=NO\nSECRETS_PRINTED=NO\n'
  printf 'PHONE_EVENT_VERSION_WRITER_INSTALLED=NOT_PROVEN\n'
  printf 'SAME_NUMBER_RECONFIRM_ATOMICITY_PROVEN=NO\n'
  printf 'PASS_COUNT=%s\nFAIL_COUNT=%s\n' "$PASS_COUNT" "$FAIL_COUNT"
  if ((rc == 0 && FAIL_COUNT == 0)); then
    printf 'FINAL_STATUS=PASS\nFINAL_RC=0\n'
  else
    printf 'FINAL_STATUS=FAIL\nFINAL_RC=1\n'
  fi
  printf 'TERMINAL_WILL_STAY_OPEN=YES\n'
}
trap finish EXIT

printf '=== 1. HOST / ENVIRONMENT ===\n'
if [[ "$(hostname -s)" != 'evrasia' || "$(id -u)" -ne 0 ||
      ! -d "$ROOT/bitrix/modules/main/lib" ||
      ! -d "$ROOT/local/php_interface/Controllers/V4" ]]; then
  fail 'production host/root guard'
  exit 1
fi
for cmd in awk sha256sum realpath wc; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    fail "missing read-only tool: $cmd"
    exit 1
  fi
done
pass 'read-only host and source guard'

printf '\n=== 2. EXACT THREE SOURCE SHA-256 PINS ===\n'
while IFS='|' read -r rel expected; do
  [[ -n "$rel" ]] || continue
  file="$ROOT/$rel"
  if [[ ! -f "$file" || ! -r "$file" || -L "$file" ||
        "$(realpath -- "$file")" != "$file" ]]; then
    fail "unexpected native source path: $rel"
    exit 1
  fi
  bytes="$(wc -c < "$file")"
  if ((bytes < 20 || bytes > 1200000)); then
    fail "unexpected native source size: $rel"
    exit 1
  fi
  current="$(sha256sum -- "$file" | awk '{print $1}')"
  if [[ "$current" != "$expected" ]]; then
    fail "source SHA changed: $rel"
    exit 1
  fi
  pass "pinned source: $rel"
done <<'PINS'
local/php_interface/lib/PhoneAuth.php|a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27
local/php_interface/Controllers/V4/Auth.php|72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362
bitrix/modules/main/lib/UserPhoneAuthTable.php|3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991
PINS

# The AWK program NEVER prints an original line, variable value, literal
# phone, SMS code, connection details, credential, or full call arguments.
# It emits only safe fixed tags and whitelisted PHP method identifiers.
printf '\n=== 3. V4 PHONE CONFIRMATION / RECONFIRMATION ORDER MARKERS ===\n'
awk '
  function emit(tag, detail) {
    if (detail == "") printf "LINE=%d METHOD=%s TAG=%s\n", NR, owner, tag
    else printf "LINE=%d METHOD=%s TAG=%s DETAIL=%s\n", NR, owner, tag, detail
    seen++
  }
  {
    line=$0
    lower=tolower(line)
    if (match(line, /function[[:space:]]+[A-Za-z_][A-Za-z0-9_]*/)) {
      owner=substr(line, RSTART, RLENGTH)
      sub(/^function[[:space:]]+/, "", owner)
    }
    if (owner != "signup" && owner != "confirm_request" &&
        owner != "confirm") next

    if (match(line, /UserPhoneAuthTable[[:space:]]*::[[:space:]]*[A-Za-z_][A-Za-z0-9_]*/)) {
      call=substr(line, RSTART, RLENGTH)
      sub(/^.*::[[:space:]]*/, "", call)
      emit("BITRIX_PHONE_TABLE_CALL", call)
      nativeCalls++
    } else if (index(line, "UserPhoneAuthTable") > 0) {
      emit("BITRIX_PHONE_TABLE_REFERENCE", "")
    }

    if (index(lower, "confirmed") > 0) {
      emit("CONFIRMED_FIELD_REFERENCE", "")
      confirmedRefs++
      if (line ~ /["\047]CONFIRMED["\047][[:space:]]*=>[[:space:]]*["\047]Y["\047]/)
        emit("CONFIRMED_Y_ASSIGNMENT_CANDIDATE", "")
      if (line ~ /["\047]CONFIRMED["\047][[:space:]]*=>[[:space:]]*["\047]N["\047]/)
        emit("CONFIRMED_N_ASSIGNMENT_CANDIDATE", "")
    }
    if (match(line, /(OnBeforeUserUpdate|OnAfterUserUpdate|OnAfterUserAdd|AddEventHandler|registerEventHandler|OnBeforePhone|OnAfterPhone)/))
      emit("POTENTIAL_EVENT_REFERENCE", "")
    if (match(lower, /(starttransaction|committransaction|rollbacktransaction|begintransaction|for[[:space:]]+update)/))
      emit("POTENTIAL_TRANSACTION_REFERENCE", "")
    if (index(lower, "phoneconfirm") > 0)
      emit("PHONE_CONFIRM_HELPER_REFERENCE", "")
  }
  END {
    printf "V4_PHONE_TABLE_STATIC_CALLS=%d\n", nativeCalls
    printf "V4_CONFIRMED_REFERENCES=%d\n", confirmedRefs
    printf "V4_METADATA_MARKERS=%d\n", seen
    if (nativeCalls == 0 || confirmedRefs == 0) exit 2
  }
' "$ROOT/local/php_interface/Controllers/V4/Auth.php" || {
  fail 'V4 phone confirmation static writer order unavailable'
  exit 1
}
pass 'source-verified V4 phone writer call positions (no source printed)'

printf '\n=== 4. NATIVE PHONE TABLE CALLBACK AND WRITE MARKERS ===\n'
awk '
  function emit(tag, detail) {
    if (detail == "") printf "LINE=%d METHOD=%s TAG=%s\n", NR, owner, tag
    else printf "LINE=%d METHOD=%s TAG=%s DETAIL=%s\n", NR, owner, tag, detail
    seen++
  }
  {
    line=$0
    lower=tolower(line)
    if (match(line, /function[[:space:]]+[A-Za-z_][A-Za-z0-9_]*/)) {
      owner=substr(line, RSTART, RLENGTH)
      sub(/^function[[:space:]]+/, "", owner)
      if (owner == "onBeforeAdd" || owner == "onBeforeUpdate" ||
          owner == "modifyFields" || owner == "normalizePhoneNumber")
        emit("NATIVE_METHOD_DECLARATION", "")
    }
    if (owner != "onBeforeAdd" && owner != "onBeforeUpdate" &&
        owner != "modifyFields" && owner != "normalizePhoneNumber") next
    if (index(lower, "confirmed") > 0) emit("CONFIRMED_FIELD_REFERENCE", "")
    if (index(lower, "phone_number") > 0) emit("PHONE_NUMBER_FIELD_REFERENCE", "")
    if (index(lower, "modifyfields") > 0) emit("FIELD_NORMALIZATION_CALL", "")
    if (index(lower, "normalizephonenumber") > 0) emit("PHONE_NORMALIZATION_CALL", "")
    if (match(lower, /(starttransaction|committransaction|rollbacktransaction|begintransaction|queryexecute|for[[:space:]]+update)/))
      emit("NATIVE_SQL_OR_TRANSACTION_REFERENCE", "")
  }
  END {
    printf "NATIVE_CALLBACK_METADATA_MARKERS=%d\n", seen
    if (seen < 4) exit 2
  }
' "$ROOT/bitrix/modules/main/lib/UserPhoneAuthTable.php" || {
  fail 'native phone table callback metadata unavailable'
  exit 1
}
pass 'native phone schema callback positions (no source printed)'
printf '\nSTATIC_METADATA_ONLY=YES\n'
