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
awk '
  function emit(line, tag, detail) {
    if (detail == "") printf "LINE=%d TAG=%s\n", line, tag
    else printf "LINE=%d TAG=%s IDENT=%s\n", line, tag, detail
    tags++
  }
  function lhs_var(s, x) {
    x=tolower(s)
    if (match(x, /^[[:space:]]*[$][a-z_][a-z0-9_]*[[:space:]]*=/)) {
      x=substr(x,RSTART,RLENGTH)
      sub(/=.*/, "", x)
      gsub(/[[:space:]]/, "", x)
      if (length(x)<=48) return x
    }
    return ""
  }
  function extract_field_var(s, f) {
    f=tolower(s)
    if (match(f, /[$][a-z_][a-z0-9_]*[[:space:]]*\[[[:space:]]*["\047]confirmed["\047][[:space:]]*\]/)) {
      f=substr(f,RSTART,RLENGTH)
      sub(/\[.*/, "", f)
      gsub(/[[:space:]]/, "", f)
      if (length(f)<=48) return f
    }
    return ""
  }
  function direct_methods(line, src, rest, call) {
    rest=tolower(src)
    while (match(rest, /(userphoneauthtable|cuser|usertable)[[:space:]]*::[[:space:]]*[a-z_][a-z0-9_]*[[:space:]]*\(/)) {
      call=substr(rest,RSTART,RLENGTH)
      gsub(/[[:space:]]/, "", call)
      sub(/\($/, "", call)
      if (length(call)<=72) emit(line, "CLASS_METHOD_REFERENCE", call)
      rest=substr(rest,RSTART+RLENGTH)
      direct_calls++
    }
  }
  {
    if (NR<840 || NR>1040) next
    src=$0
    low=tolower(src)
    snippet[NR]=low

    # Assert these four already observed anchor categories, not PHP values.
    if (NR==869 || NR==876 || NR==880) {
      if (low !~ /userphoneauthtable[[:space:]]*::/) wrong++
      else phoneanchors++
    }
    if (NR==891) {
      if (low !~ /\[[[:space:]]*["\047]confirmed["\047][[:space:]]*\][[:space:]]*=/) wrong++
      else {
        confirmed_var=extract_field_var(low)
        confirmed_anchor++
        emit(NR, "CONFIRMED_ARRAY_WRITE_RECEIVER", confirmed_var=="" ? "UNRESOLVED" : confirmed_var)
      }
    }
    if (NR==1012) {
      if (index(low,"confirmed")==0) wrong++
      else {
        confirm_ref++
        var_1012=extract_field_var(low)
        emit(NR,"CONFIRM_METHOD_CONFIRMED_RECEIVER",var_1012==""?"UNRESOLVED":var_1012)
      }
    }
  }
  END {
    if (wrong>0 || phoneanchors!=3 || confirmed_anchor!=1 || confirm_ref!=1) {
      printf "SOURCE_ANCHORS_VALID=NO\n"
      exit 2
    }
    printf "SOURCE_ANCHORS_VALID=YES\n"
    printf "CONFIRMED_ARRAY_VARIABLE=%s\n", confirmed_var==""?"UNRESOLVED":confirmed_var

    # Print fixed classifications for precisely known V4 source ranges;
    # no original source line, function argument or field value is emitted.
    for (n=869;n<=938;n++) {
      s=snippet[n]
      if (s=="") continue

      v=lhs_var(s)
      if (v!="") emit(n,"PLAIN_ASSIGNMENT_RECEIVER",v)
      if (index(s,"confirmed")>0) emit(n,"CONFIRMED_NAME_REFERENCE","")
      if (index(s,"phone_number")>0) emit(n,"PHONE_NUMBER_NAME_REFERENCE","")

      direct_methods(n,s)
      if (s ~ /->[[:space:]]*(update|save|add|set|delete)[[:space:]]*\(/)
        emit(n,"INSTANCE_MUTATOR_METHOD_REFERENCE","")
      if (s ~ /[^a-z_0-9]generatephonecode[[:space:]]*\(/)
        emit(n,"PHONE_CODE_GENERATION_REFERENCE","")

      # Once the receiver is resolved, track all lexical uses after its
      # assignment. Lexical proximity != an actual DB write.
      if (confirmed_var!="" && n>891 && index(s,confirmed_var)>0) {
        count_references++
        if (count_references<=40) emit(n,"CONFIRMED_RECEIVER_LEXICAL_REUSE","")
      }
    }
    for (n=992;n<=1034;n++) {
      s=snippet[n]
      if (s=="") continue
      if (index(s,"confirmed")>0) emit(n,"CONFIRM_METHOD_CONFIRMED_NAME_REFERENCE","")
      if (s ~ /->[[:space:]]*(update|save|add)[[:space:]]*\(/)
        emit(n,"CONFIRM_METHOD_INSTANCE_MUTATOR","")
      direct_methods(n,s)
    }
    printf "DIRECT_CLASS_METHOD_CALL_MARKERS=%d\n", direct_calls
    printf "CONFIRMED_RECEIVER_REUSES_AFTER_891=%d\n", count_references
    printf "STATIC_METADATA_TAGS=%d\n", tags
  }
' "$FILE" || {
  fail 'source changed or expected CONFIRMED lineage anchors unavailable'
  exit 1
}
pass 'field-array receiver and subsequent source call sites classified'

printf '\n=== 4. SCOPE / LIMITATIONS ===\n'
printf 'ORIGINAL_CODE_AND_VALUES_DISCLOSED=NO\n'
printf 'STATIC_FIELD_LINEAGE_IS_NOT_SQL_COMMIT_PROOF=YES\n'
printf 'NATIVE_CONFIRMED_EVENT_REQUIRES_SEPARATE_PROOF=YES\n'
pass 'no production runtime action performed'
