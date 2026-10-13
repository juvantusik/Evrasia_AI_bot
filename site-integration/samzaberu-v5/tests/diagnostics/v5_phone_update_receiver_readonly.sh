#!/usr/bin/env bash
# Strictly static metadata for the THREE already source-confirmed V4 ->update sites.
# All output is fixed tags, source-code line numbers and PHP identifier names.
# Never echoes PHP expressions, argument values, phone numbers, SMS or secrets.
set -Eeuo pipefail
umask 077

ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
REL='local/php_interface/Controllers/V4/Auth.php'
SOURCE="$ROOT/$REL"
EXPECTED_SHA='72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362'
PASS_COUNT=0
FAIL_COUNT=0
pass() { PASS_COUNT=$((PASS_COUNT + 1)); printf 'PASS: %s\n' "$1"; }
fail() { FAIL_COUNT=$((FAIL_COUNT + 1)); printf 'FAIL: %s\n' "$1"; }

finish() {
  local rc=$?
  trap - EXIT
  printf '\n=== 5. RESULT ===\n'
  printf 'MODE=READ_ONLY\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n'
  printf 'BITRIX_BOOTSTRAP=NO\nPRODUCTION_APPLICATION_WRITE=NO\n'
  printf 'SITE_PHP_CODE_EXECUTED=NO\nUSER_DATA_READ=NO\n'
  printf 'CONFIG_FILES_READ=NO\nSMS_SENT=NO\nSECRETS_PRINTED=NO\n'
  printf 'SOURCE_LINES_PRINTED=NO\n'
  printf 'CONFIRMED_WRITE_TARGET_PROVEN=NO\n'
  printf 'SAME_PHONE_RECONFIRM_ATOMICITY_PROVEN=NO\n'
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
  fail 'expected production host/root/source guard'
  exit 1
fi
for tool in awk sha256sum realpath wc; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    fail "missing read-only binary: $tool"
    exit 1
  fi
done
pass 'correct host and source-only mode'

printf '\n=== 2. EXACT SOURCE SHA GUARD ===\n'
if [[ ! -f "$SOURCE" || ! -r "$SOURCE" || -L "$SOURCE" ||
      "$(realpath -- "$SOURCE")" != "$SOURCE" ]]; then
  fail 'source missing or path changed'
  exit 1
fi
bytes="$(wc -c < "$SOURCE")"
if ((bytes < 100 || bytes > 1200000)); then
  fail 'source size not in expected bounds'
  exit 1
fi
actual_sha="$(sha256sum -- "$SOURCE" | awk '{print $1}')"
if [[ "$actual_sha" != "$EXPECTED_SHA" ]]; then
  fail 'pinned V4 controller SHA changed; refuse analysis'
  exit 1
fi
printf 'SOURCE=%s\nSOURCE_SHA256=%s\n' "$REL" "$actual_sha"
pass 'production Auth.php matches known source'

printf '\n=== 3. EXACT UPDATE SITES AND SAFE FIELD METADATA ===\n'
awk '
  function emit(tag, detail) {
    if (detail == "")
      printf "LINE=%d OWNER=%s TAG=%s\n", NR, owner, tag
    else
      printf "LINE=%d OWNER=%s TAG=%s IDENT=%s\n", NR, owner, tag, detail
    tags++
  }
  function receiver_from_update(src, lowered, call, name) {
    lowered=tolower(src)
    while (match(lowered, /[$][a-z_][a-z0-9_]*[[:space:]]*->[[:space:]]*update[[:space:]]*\(/)) {
      call=substr(lowered, RSTART, RLENGTH)
      sub(/[[:space:]]*->[[:space:]]*update[[:space:]]*\($/, "", call)
      name=call
      # Only PHP identifiers, never source arguments or string literals.
      if (name ~ /^[$][a-z_][a-z0-9_]*$/ && length(name) <= 50)
        emit("INSTANCE_UPDATE_RECEIVER", name)
      else
        emit("INSTANCE_UPDATE_RECEIVER_UNRESOLVED", "")
      if (NR==137 || NR==851 || NR==1026) anchored[NR]++
      lowered=substr(lowered, RSTART + RLENGTH)
    }
    if (index(tolower(src), "->update(") > 0 &&
        (NR==137 || NR==851 || NR==1026) && !anchored[NR])
      emit("UPDATE_RECEIVER_NON_SIMPLE", "")
  }
  {
    raw=$0
    lo=tolower(raw)

    if (match(raw, /function[[:space:]]+[A-Za-z_][A-Za-z0-9_]*/)) {
      owner=substr(raw, RSTART, RLENGTH)
      sub(/^function[[:space:]]+/, "", owner)
    }

    in_scope = (owner=="confirmation" && NR>=60 && NR<=146) ||
               (owner=="confirm_request" && NR>=779 && NR<=953) ||
               (owner=="confirm" && NR>=954 && NR<=1140)
    if (!in_scope) next

    if (NR==137 || NR==851 || NR==1026) {
      if (lo !~ /->[[:space:]]*update[[:space:]]*\(/)
        bad_anchor++
      else
        emit("KNOWN_UPDATE_SITE", "")
    }

    # Variable provenance is classified, but PHP source and literal values
    # are never printed. Identifier output is restricted to update receivers.
    if (lo ~ /new[[:space:]]+[\\]?cuser([^a-z_0-9]|$)/)
      emit("CONSTRUCT_CUSER", "")
    if (lo ~ /new[[:space:]]+[\\]?user([^a-z_0-9]|$)/)
      emit("CONSTRUCT_USER", "")
    if (lo ~ /new[[:space:]]+[\\]?userphoneauth([^a-z_0-9]|$)/)
      emit("CONSTRUCT_PHONE_AUTH_HELPER", "")
    if (lo ~ /userphoneauthtable[[:space:]]*::/)
      emit("STATIC_PHONE_AUTH_TABLE_REFERENCE", "")
    if (lo ~ /cuser[[:space:]]*::/)
      emit("STATIC_CUSER_REFERENCE", "")
    if (lo ~ /usertable[[:space:]]*::/)
      emit("STATIC_USER_TABLE_REFERENCE", "")

    # Field names are identifiers from a fixed allowlist, not field values.
    if (lo ~ /["\047]confirmed["\047]/) {
      emit("FIELD_CONFIRMED_REFERENCE", "")
      if (lo ~ /["\047]confirmed["\047][[:space:]]*=>/)
        emit("FIELD_CONFIRMED_ARRAY_KEY", "")
      if (lo ~ /\[[[:space:]]*["\047]confirmed["\047][[:space:]]*\][[:space:]]*=/)
        emit("FIELD_CONFIRMED_SUBSCRIPT_WRITE", "")
      if (lo ~ /["\047]confirmed["\047][[:space:]]*=>[[:space:]]*["\047]y["\047]/ ||
          lo ~ /\[[[:space:]]*["\047]confirmed["\047][[:space:]]*\][[:space:]]*=[[:space:]]*["\047]y["\047]/)
        emit("FIELD_CONFIRMED_LITERAL_Y_SAME_LINE", "")
    }
    if (lo ~ /["\047]phone_number["\047]/)
      emit("FIELD_PHONE_NUMBER_REFERENCE", "")
    if (lo ~ /["\047]active["\047]/)
      emit("FIELD_ACTIVE_REFERENCE", "")
    if (lo ~ /["\047]user_id["\047]/)
      emit("FIELD_USER_ID_REFERENCE", "")

    # For context print only line numbers for control-flow statements in
    # narrow neighborhoods of the three already confirmed writer candidates.
    if ((NR>=129 && NR<=143) || (NR>=842 && NR<=858) ||
        (NR>=1005 && NR<=1034)) {
      if (raw ~ /^[[:space:]]*(if|else[[:space:]]+if)[[:space:]]*\(/)
        emit("NEAR_WRITER_BRANCH", "")
      if (raw ~ /^[[:space:]]*(return|throw)[[:space:];(]/)
        emit("NEAR_WRITER_RETURN_OR_THROW", "")
    }
    receiver_from_update(raw)
  }
  END {
    printf "KNOWN_UPDATE_137=%d\nKNOWN_UPDATE_851=%d\nKNOWN_UPDATE_1026=%d\n",
      anchored[137]+0, anchored[851]+0, anchored[1026]+0
    printf "SAFE_SOURCE_TAGS=%d\n", tags
    if (bad_anchor || anchored[137]!=1 || anchored[851]!=1 ||
        anchored[1026]!=1) exit 2
  }
' "$SOURCE" || {
  fail 'one or more pinned update-site receiver diagnostics unresolved'
  exit 1
}
pass 'three known update sites and safe static field markers inspected'

printf '\n=== 4. EVIDENCE BOUNDARY ===\n'
printf 'THREE_UPDATE_SITES=SOURCE_VERIFIED\n'
printf 'UPDATE_RECEIVER_IDENTIFIER_IS_NOT_DATABASE_PROOF=YES\n'
printf 'MULTILINE_DYNAMIC_FIELD_ASSIGNMENTS_MAY_NOT_MATCH=YES\n'
pass 'no native Bitrix runtime or SMS operation was attempted'
