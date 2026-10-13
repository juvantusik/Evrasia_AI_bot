#!/usr/bin/env bash
# Exact final SOURCE-ONLY check: recovery & MFA delete events, merge strategy.
# Never loads/executes Bitrix or connects to DB; safe to rerun on pinned core.
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
  printf 'DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\nBITRIX_BOOTSTRAP=NO\n'
  printf 'NATIVE_CODE_EXECUTED=NO\nUSER_DATA_READ=NO\nSECRETS_PRINTED=NO\n'
  printf 'NATIVE_MFA_ATOMICITY_PROVEN=NO\n'
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
if [[ "$(hostname -s)" != 'evrasia' ||
      "$(id -u)" -ne 0 ||
      ! -d "$ROOT/bitrix/modules/security/lib/Mfa" ||
      ! -d "$ROOT/bitrix/modules/main/lib/ORM/Data/AddStrategy" ]]; then
  fail 'expected root / production Bitrix source guard'
  exit 1
fi
for tool in awk sha256sum nl sed grep realpath wc; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    fail "required tool missing: $tool"
    exit 1
  fi
done
pass 'root host and Bitrix source-only guard'

printf '\n=== 2. SOURCE SHA-256 GUARDS ===\n'
while IFS='|' read -r rel expected; do
  [[ -n "$rel" ]] || continue
  file="$ROOT/$rel"
  if [[ ! -f "$file" || ! -r "$file" || -L "$file" ||
        "$(realpath -- "$file")" != "$file" ]]; then
    fail "source unavailable, unreadable or symlink: $rel"
    exit 1
  fi
  actual="$(sha256sum -- "$file" | awk '{print $1}')"
  if [[ "$actual" != "$expected" ]]; then
    fail "pinned source changed: $rel"
    exit 1
  fi
  pass "pinned SHA: $rel"
done <<'PINS'
bitrix/modules/security/lib/Mfa/UserTable.php|cf3979c6bed5684bacc758656c55431a60cefe2e356b1b2a4e63fb323a283a64
bitrix/modules/security/lib/Mfa/RecoveryCodesTable.php|a5ff49a123bd7f3864acfc1fee9010965a8d9d3775d774442de66d5667d5e357
bitrix/modules/main/lib/ORM/Data/AddStrategy/Trait/MergeByDefaultTrait.php|30153b42a1a652b44ca70f2bb14335d08f970d0ebef8ca85e7f349f8ad29c198
PINS

BASE="$ROOT/bitrix/modules/main/lib/ORM/Data/AddStrategy/Internal/BaseMergeTrait.php"
if [[ ! -f "$BASE" || ! -r "$BASE" || -L "$BASE" ||
      "$(realpath -- "$BASE")" != "$BASE" ]]; then
  fail 'BaseMergeTrait source path unavailable'
  exit 1
fi
if ! grep -Eq '^[[:space:]]*trait[[:space:]]+BaseMergeTrait([[:space:]]|$)' "$BASE"; then
  fail 'BaseMergeTrait declaration not found'
  exit 1
fi
bytes="$(wc -c < "$BASE")"
if (( bytes < 20 || bytes > 1000000 )); then
  fail 'BaseMergeTrait source size out of bounds'
  exit 1
fi
printf 'BASE_MERGE_TRAIT_REL=bitrix/modules/main/lib/ORM/Data/AddStrategy/Internal/BaseMergeTrait.php\n'
printf 'BASE_MERGE_TRAIT_SHA256=%s\n' "$(sha256sum -- "$BASE" | awk '{print $1}')"
pass 'BaseMergeTrait source identity and bounded size'

printf '\n=== 3. MFA USER DELETE EVENTS ===\n'
printf 'SOURCE=bitrix/modules/security/lib/Mfa/UserTable.php\nLINES=94-175\n'
nl -ba -- "$ROOT/bitrix/modules/security/lib/Mfa/UserTable.php" | sed -n '94,175p'
pass 'bounded MFA UserTable event declarations'

printf '\n=== 4. RECOVERY / BASE MERGE REMAINING BOUNDARIES ===\n'
printf 'SOURCE=bitrix/modules/security/lib/Mfa/RecoveryCodesTable.php\nLINES=1-82\n'
nl -ba -- "$ROOT/bitrix/modules/security/lib/Mfa/RecoveryCodesTable.php" | sed -n '1,82p'
printf 'SOURCE=bitrix/modules/security/lib/Mfa/RecoveryCodesTable.php\nLINES=125-175\n'
nl -ba -- "$ROOT/bitrix/modules/security/lib/Mfa/RecoveryCodesTable.php" | sed -n '125,175p'

line="$(grep -n -m 1 -E 'function[[:space:]]+getCachedMergeStrategy[[:space:]]*\(' "$BASE" | awk -F: '{print $1}')"
if [[ ! "$line" =~ ^[1-9][0-9]*$ ]]; then
  fail 'cached native merge strategy method unavailable'
  exit 1
fi
first=$((line > 10 ? line - 10 : 1))
last=$((line + 75))
printf 'SOURCE=bitrix/modules/main/lib/ORM/Data/AddStrategy/Internal/BaseMergeTrait.php\n'
printf 'LINES=%s-%s\n' "$first" "$last"
nl -ba -- "$BASE" | sed -n "$first,$last"'p'
pass 'bounded native recovery / merge strategy evidence'
