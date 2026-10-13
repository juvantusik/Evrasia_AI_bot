#!/usr/bin/env bash
# Static method-flow metadata only: no PHP/Bitrix bootstrap, no SQL, no values.
set -Eeuo pipefail
umask 077

ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
PASS_COUNT=0
FAIL_COUNT=0
pass() { PASS_COUNT=$((PASS_COUNT+1)); printf 'PASS: %s\n' "$1"; }
fail() { FAIL_COUNT=$((FAIL_COUNT+1)); printf 'FAIL: %s\n' "$1"; }
finish() {
  local rc=$?
  trap - EXIT
  printf '\n=== 5. RESULT ===\n'
  printf 'MODE=READ_ONLY\nPRODUCTION_APPLICATION_WRITE=NO\n'
  printf 'DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n'
  printf 'BITRIX_BOOTSTRAP=NO\nPHP_SITE_CODE_EXECUTED=NO\n'
  printf 'SMS_SENT=NO\nUSER_DATA_READ=NO\nCONFIG_FILES_READ=NO\n'
  printf 'SOURCE_LINES_PRINTED=NO\nSECRETS_PRINTED=NO\n'
  printf 'PHONE_EVENT_VERSION_WRITER=NOT_PROVEN\n'
  printf 'PHONE_SAME_NUMBER_RECONFIRM_ATOMICITY=NOT_PROVEN\n'
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
      ! -d "$ROOT/local/php_interface/Controllers/V4" ]]; then
  fail 'host/root guard'
  exit 1
fi
for cmd in awk sha256sum realpath wc; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    fail "read-only utility missing: $cmd"
    exit 1
  fi
done
pass 'host and static source guard'

printf '\n=== 2. SOURCE SHA INTEGRITY ===\n'
while IFS='|' read -r relative expected; do
  [[ -n "$relative" ]] || continue
  path="$ROOT/$relative"
  if [[ ! -f "$path" || ! -r "$path" || -L "$path" ||
        "$(realpath -- "$path")" != "$path" ]]; then
    fail "source file unavailable: $relative"
    exit 1
  fi
  size="$(wc -c < "$path")"
  if (( size < 20 || size > 1200000 )); then
    fail "source size invalid: $relative"
    exit 1
  fi
  actual="$(sha256sum -- "$path" | awk '{print $1}')"
  if [[ "$actual" != "$expected" ]]; then
    fail "source SHA changed: $relative"
    exit 1
  fi
  pass "exact pinned source: $relative"
done <<'PINS'
local/php_interface/Controllers/V4/Auth.php|72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362
local/php_interface/lib/PhoneAuth.php|a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27
PINS

# No raw PHP source lines, strings, variables, phone numbers or code bodies
# are printed. Only whitelisted method identifiers and fixed marker names.
printf '\n=== 3. AUTH V4: SIGNUP / CONFIRM REQUEST / CONFIRM FLOW ===\n'
awk '
  function emit(tag, detail) {
    if (detail == "") printf "LINE=%d OWNER=%s TAG=%s\n", NR, owner, tag
    else printf "LINE=%d OWNER=%s TAG=%s NAME=%s\n", NR, owner, tag, detail
    count++
  }
  function method_calls(original, work, call) {
    work=tolower(original)
    while (match(work, /->[[:space:]]*[a-z_][a-z0-9_]*[[:space:]]*\(/)) {
      call=substr(work, RSTART, RLENGTH)
      sub(/^->[[:space:]]*/, "", call)
      sub(/[[:space:]]*\($/, "", call)
      if (length(call) <= 40) emit("INSTANCE_METHOD_CALL", call)
      work=substr(work, RSTART + RLENGTH)
    }
    work=tolower(original)
    while (match(work, /(userphoneauthtable|cuser|usertable|phoneauth)[[:space:]]*::[[:space:]]*[a-z_][a-z0-9_]*[[:space:]]*\(/)) {
      call=substr(work, RSTART, RLENGTH)
      sub(/^.*::[[:space:]]*/, "", call)
      sub(/[[:space:]]*\($/, "", call)
      if (length(call) <= 40) emit("STATIC_METHOD_CALL", call)
      work=substr(work, RSTART + RLENGTH)
    }
  }
  {
    raw=$0
    lo=tolower(raw)
    if (match(raw, /function[[:space:]]+[A-Za-z_][A-Za-z0-9_]*/)) {
      owner=substr(raw,RSTART,RLENGTH)
      sub(/^function[[:space:]]+/, "", owner)
      if (owner=="signup" || owner=="confirm_request" || owner=="confirm" ||
          owner=="confirmation") emit("TARGET_METHOD", "")
    }
    if (owner!="signup" && owner!="confirm_request" && owner!="confirm" &&
        owner!="confirmation") next
    if ((owner=="signup" && (NR<263 || NR>426)) ||
        (owner=="confirmation" && (NR<60 || NR>146)) ||
        (owner=="confirm_request" && (NR<779 || NR>953)) ||
        (owner=="confirm" && (NR<954 || NR>1140))) next

    if (index(lo, "confirmed") > 0) {
      emit("CONFIRMED_REFERENCE", "")
      refs++
      if (raw ~ /["\047]CONFIRMED["\047][[:space:]]*=>[[:space:]]*["\047]Y["\047]/ ||
          raw ~ /\[[[:space:]]*["\047]CONFIRMED["\047][[:space:]]*\][[:space:]]*=[[:space:]]*["\047]Y["\047]/)
        emit("CONFIRMED_Y_ASSIGNMENT_CANDIDATE", "")
      if (raw ~ /["\047]CONFIRMED["\047][[:space:]]*=>[[:space:]]*["\047]N["\047]/ ||
          raw ~ /\[[[:space:]]*["\047]CONFIRMED["\047][[:space:]]*\][[:space:]]*=[[:space:]]*["\047]N["\047]/)
        emit("CONFIRMED_N_ASSIGNMENT_CANDIDATE", "")
    }
    if (index(lo, "userphoneauthtable") > 0) emit("PHONE_AUTH_CLASS_REFERENCE", "")
    if (index(lo, "phoneconfirm") > 0 || index(lo, "phoneauth") > 0)
      emit("PHONE_VERIFY_HELPER_REFERENCE", "")
    if (index(lo, "starttransaction") > 0 || index(lo, "committransaction") > 0 ||
        index(lo, "rollbacktransaction") > 0 || index(lo, "begintransaction") > 0 ||
        lo ~ /for[[:space:]]+update/)
      emit("TRANSACTION_OR_LOCK_REFERENCE", "")
    if (raw ~ /^[[:space:]]*(if|else[[:space:]]+if)[[:space:]]*\(/)
      emit("CONDITIONAL_BRANCH", "")
    if (raw ~ /^[[:space:]]*(return|throw)[[:space:];(]/)
      emit("EARLY_EXIT_OR_RETURN", "")
    method_calls(raw)
  }
  END {
    printf "AUTH_CONFIRMED_REFERENCES=%d\nAUTH_FLOW_TAGS=%d\n", refs, count
    if (refs<5 || count<12) exit 2
  }
' "$ROOT/local/php_interface/Controllers/V4/Auth.php" || {
  fail 'V4 auth flow static markers could not be established'
  exit 1
}
pass 'V4 signup/confirmation flows classified, no PHP source emitted'

printf '\n=== 4. PHONE AUTH HELPER: CODE CHECK CONTROL FLOW ===\n'
awk '
  function emit(tag, detail) {
    if (detail=="") printf "LINE=%d OWNER=%s TAG=%s\n", NR, owner, tag
    else printf "LINE=%d OWNER=%s TAG=%s NAME=%s\n", NR, owner, tag, detail
    count++
  }
  {
    raw=$0
    lo=tolower(raw)
    if (match(raw, /function[[:space:]]+[A-Za-z_][A-Za-z0-9_]*/)) {
      owner=substr(raw,RSTART,RLENGTH)
      sub(/^function[[:space:]]+/, "", owner)
      if (owner=="check" || owner=="sendCode") emit("TARGET_METHOD", "")
    }
    if ((owner=="check" && NR>=135 && NR<=181) ||
        (owner=="sendCode" && NR>=65 && NR<=128)) {
      if (raw ~ /^[[:space:]]*(if|else[[:space:]]+if)[[:space:]]*\(/)
        emit("CONDITIONAL_BRANCH", "")
      if (raw ~ /^[[:space:]]*(return|throw)[[:space:];(]/)
        emit("EARLY_EXIT_OR_RETURN", "")
      if (index(lo, "confirmed") > 0) emit("CONFIRMED_REFERENCE", "")
      if (index(lo, "userphoneauthtable") > 0) emit("PHONE_TABLE_REFERENCE", "")
      if (index(lo, "sendsms") > 0 || index(lo, "sendcode") > 0)
        emit("SEND_CALL_REFERENCE", "")
      while (match(lo, /->[[:space:]]*[a-z_][a-z0-9_]*[[:space:]]*\(/)) {
        call=substr(lo,RSTART,RLENGTH)
        sub(/^->[[:space:]]*/, "", call)
        sub(/[[:space:]]*\($/, "", call)
        if (length(call)<=40) emit("INSTANCE_METHOD_CALL", call)
        lo=substr(lo,RSTART+RLENGTH)
      }
    }
  }
  END {
    printf "PHONEAUTH_FLOW_TAGS=%d\n", count
    if (count<3) exit 2
  }
' "$ROOT/local/php_interface/lib/PhoneAuth.php" || {
  fail 'phone auth helper flow not classified'
  exit 1
}
pass 'helper static flow classified, no original text emitted'
printf '\nEVIDENCE_TYPE=SOURCE_METADATA_ONLY\n'
