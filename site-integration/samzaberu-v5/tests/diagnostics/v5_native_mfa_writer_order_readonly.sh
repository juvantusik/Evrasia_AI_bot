#!/usr/bin/env bash
# Only static, checksum-pinned Bitrix code fragments. No bootstrap or DB.
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
  printf 'MODE=READ_ONLY\nPRODUCTION_APPLICATION_WRITE=NO\n'
  printf 'DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n'
  printf 'BITRIX_BOOTSTRAP=NO\nBITRIX_NATIVE_CODE_EXECUTED=NO\n'
  printf 'USER_DATA_READ=NO\nSECRETS_PRINTED=NO\n'
  printf 'NATIVE_EVENT_ATOMICITY_PROVEN=NO\n'
  printf 'PASS_COUNT=%s\nFAIL_COUNT=%s\n' "$PASS_COUNT" "$FAIL_COUNT"
  if (( rc == 0 && FAIL_COUNT == 0 )); then
    printf 'FINAL_STATUS=PASS\nFINAL_RC=0\n'
  else
    printf 'FINAL_STATUS=FAIL\nFINAL_RC=1\n'
  fi
  printf 'TERMINAL_WILL_STAY_OPEN=YES\n'
}
trap finish EXIT

printf '=== 1. HOST / ENVIRONMENT ===\n'
if [[ "$(hostname -s)" != 'evrasia' || "$(id -u)" -ne 0 ||
      ! -d "$ROOT/bitrix/modules/security/lib/Mfa" ||
      ! -d "$ROOT/bitrix/modules/main/lib/ORM/Data" ]]; then
  fail 'production host/root/source guard'
  exit 1
fi
for tool in sha256sum awk sed nl; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    fail "required command missing: $tool"
    exit 1
  fi
done
pass 'production source-only guard'

printf '\n=== 2. PINNED SOURCE INTEGRITY ===\n'
while IFS='|' read -r rel pin; do
  [[ -n "$rel" ]] || continue
  path="$ROOT/$rel"
  if [[ ! -f "$path" || ! -r "$path" || -L "$path" ]]; then
    fail "pinned source missing/unreadable/symlink: $rel"
    exit 1
  fi
  actual="$(sha256sum -- "$path" | awk '{print $1}')"
  if [[ "$actual" != "$pin" ]]; then
    fail "pinned source SHA mismatch: $rel"
    exit 1
  fi
  pass "pinned SHA: $rel"
done <<'PINNED_FILES'
bitrix/modules/security/lib/Mfa/Otp.php|285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c
bitrix/modules/security/lib/Mfa/OtpEvents.php|9d176f4f8ac3c24755d46f595f962405538a7e391dcc6f7c74666130749a7c91
bitrix/modules/security/classes/general/user.php|4f177416df72ffb7f92573c57028d2607238a2df1e1654c3e594624ad7795fa3
bitrix/modules/security/lib/Mfa/UserTable.php|cf3979c6bed5684bacc758656c55431a60cefe2e356b1b2a4e63fb323a283a64
bitrix/modules/main/lib/ORM/Data/DataManager.php|131ba9acb332a5805945b531976e8b57fb92df943da16894efd922b777bf6028
bitrix/modules/main/lib/ORM/Data/AddStrategy/Merge.php|0c0c5af78647e6e3622f6dffcfbe4970241fad673bf1a482372a5d3add03c157
PINNED_FILES

print_range() {
  local rel="$1" first="$2" last="$3"
  printf '\nSOURCE=%s\nLINES=%s-%s\n' "$rel" "$first" "$last"
  nl -ba -- "$ROOT/$rel" | sed -n "$first,$last"'p'
}

printf '\n=== 3. NATIVE MFA / AGENT WRITER ORDER ===\n'
print_range 'bitrix/modules/security/lib/Mfa/Otp.php' 334 402
print_range 'bitrix/modules/security/lib/Mfa/OtpEvents.php' 1 50
print_range 'bitrix/modules/security/classes/general/user.php' 50 172
print_range 'bitrix/modules/main/lib/ORM/Data/AddStrategy/Merge.php' 25 108
pass 'bounded native writer excerpts'

printf '\n=== 4. ORM EVENT VS DATABASE WRITE MARKERS ===\n'
awk '
  (NR>=885 && NR<=1027) || (NR>=1256 && NR<=1373) || (NR>=1644 && NR<=1721) {
    area=(NR<=1027 ? "ADD" : (NR<=1373 ? "UPDATE" : "DELETE"))
    lower=tolower($0)
    if (index(lower, "event") || index(lower, "->add(") ||
        index(lower, "->update(") || index(lower, "->delete(") ||
        index(lower, "->insert(") || index(lower, "->merge(") ||
        index(lower, "->query(") || index(lower, "queryexecute") ||
        index(lower, "getconnection") || index(lower, "starttransaction") ||
        index(lower, "committransaction") || index(lower, "rollbacktransaction")) {
      printf "ORM_SECTION=%s LINE=%d CODE=%s\n", area, NR, $0
      matched++
    }
  }
  END { if (!matched) exit 1 }
' "$ROOT/bitrix/modules/main/lib/ORM/Data/DataManager.php" || {
  fail 'ORM event marker inspection'
  exit 1
}
pass 'ORM source ordering markers only, no runtime proof'
