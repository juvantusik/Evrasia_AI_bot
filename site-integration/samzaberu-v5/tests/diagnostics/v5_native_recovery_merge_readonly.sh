#!/usr/bin/env bash
# Source-only: Bitrix MFA recovery cleanup and ORM merge trait, no native execution.
set -Eeuo pipefail
umask 077
ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
MFA="$ROOT/bitrix/modules/security/lib/Mfa"
ORM="$ROOT/bitrix/modules/main/lib/ORM"
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
  printf 'BITRIX_BOOTSTRAP=NO\nNATIVE_CODE_EXECUTED=NO\n'
  printf 'USER_DATA_READ=NO\nSECRETS_PRINTED=NO\n'
  printf 'SOURCE_STATIC_ONLY=YES\nATOMICITY_RUNTIME_PROVEN=NO\n'
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
      ! -d "$MFA" || ! -d "$ORM" ]]; then
  fail 'production host/root/source guard'
  exit 1
fi
for tool in awk grep find sha256sum nl sed realpath wc; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    fail "missing read-only tool: $tool"
    exit 1
  fi
done
pass 'native source-only guard'

printf '\n=== 2. PINNED SOURCE INTEGRITY ===\n'
while IFS='|' read -r rel sha; do
  [[ -n "$rel" ]] || continue
  path="$ROOT/$rel"
  if [[ ! -f "$path" || ! -r "$path" || -L "$path" ]]; then
    fail "pinned source unavailable: $rel"
    exit 1
  fi
  actual="$(sha256sum -- "$path" | awk '{print $1}')"
  if [[ "$actual" != "$sha" ]]; then
    fail "pinned source SHA mismatch: $rel"
    exit 1
  fi
  pass "source integrity: $rel"
done <<'PINS'
bitrix/modules/security/lib/Mfa/Otp.php|285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c
bitrix/modules/security/lib/Mfa/UserTable.php|cf3979c6bed5684bacc758656c55431a60cefe2e356b1b2a4e63fb323a283a64
PINS

printf '\n=== 3. OTP TABLE / TRAIT WIRING ===\n'
printf 'SOURCE=bitrix/modules/security/lib/Mfa/UserTable.php\nLINES=1-100\n'
nl -ba -- "$MFA/UserTable.php" | sed -n '1,100p'
pass 'native MFA UserTable declaration inspected'

# Search only framework ORM files with a merge/trait basename.
MERGE_TRAIT=''
while IFS= read -r -d '' file; do
  [[ -f "$file" && -r "$file" && ! -L "$file" ]] || continue
  if grep -Eq '(^|[[:space:]])trait[[:space:]]+MergeByDefaultTrait([[:space:]]|$)' "$file"; then
    if [[ -n "$MERGE_TRAIT" ]]; then
      fail 'ambiguous MergeByDefaultTrait class definition'
      exit 1
    fi
    MERGE_TRAIT="$file"
  fi
done < <(find "$ORM" -type f -iname '*merge*trait*.php' -print0)
if [[ -z "$MERGE_TRAIT" ||
      "$(realpath -- "$MERGE_TRAIT")" != "$MERGE_TRAIT" ||
      "$MERGE_TRAIT" != "$ORM/"* ]]; then
  fail 'MergeByDefaultTrait source unresolved'
  exit 1
fi
trait_bytes="$(wc -c < "$MERGE_TRAIT")"
if ((trait_bytes < 20 || trait_bytes > 1000000)); then
  fail 'unexpected trait source size'
  exit 1
fi
trait_sha="$(sha256sum -- "$MERGE_TRAIT" | awk '{print $1}')"
trait_rel="$(realpath --relative-to="$ROOT" -- "$MERGE_TRAIT")"
printf 'MERGE_TRAIT_REL=%s\nMERGE_TRAIT_SHA256=%s\n' "$trait_rel" "$trait_sha"
printf 'SOURCE=%s\nLINES=1-115\n' "$trait_rel"
nl -ba -- "$MERGE_TRAIT" | sed -n '1,115p'
pass 'read-only merge trait method resolution'

printf '\n=== 4. RECOVERY CODE CLEANUP IMPLEMENTATION ===\n'
RECOVERY_SOURCE=''
while IFS= read -r -d '' file; do
  [[ -f "$file" && -r "$file" && ! -L "$file" ]] || continue
  if grep -Eq '(^|[[:space:]])class[[:space:]]+RecoveryCodesTable([[:space:]]|$)' "$file" &&
     grep -Eq 'function[[:space:]]+clearByUser[[:space:]]*\(' "$file"; then
    if [[ -n "$RECOVERY_SOURCE" ]]; then
      fail 'ambiguous recovery writer class'
      exit 1
    fi
    RECOVERY_SOURCE="$file"
  fi
done < <(find "$MFA" -maxdepth 3 -type f -iname '*recover*.php' -print0)
if [[ -z "$RECOVERY_SOURCE" ||
      "$(realpath -- "$RECOVERY_SOURCE")" != "$RECOVERY_SOURCE" ||
      "$RECOVERY_SOURCE" != "$MFA/"* ]]; then
  fail 'RecoveryCodesTable::clearByUser source unresolved'
  exit 1
fi
recovery_bytes="$(wc -c < "$RECOVERY_SOURCE")"
if ((recovery_bytes < 20 || recovery_bytes > 1000000)); then
  fail 'unexpected recovery source size'
  exit 1
fi
method_line="$(grep -n -m 1 -E 'function[[:space:]]+clearByUser[[:space:]]*\(' "$RECOVERY_SOURCE" | awk -F: '{print $1}')"
if [[ ! "$method_line" =~ ^[1-9][0-9]*$ ]]; then
  fail 'recovery cleanup method locator'
  exit 1
fi
first="$method_line"
last=$((method_line + 45))
recovery_sha="$(sha256sum -- "$RECOVERY_SOURCE" | awk '{print $1}')"
recovery_rel="$(realpath --relative-to="$ROOT" -- "$RECOVERY_SOURCE")"
printf 'RECOVERY_SOURCE_REL=%s\nRECOVERY_SOURCE_SHA256=%s\n' "$recovery_rel" "$recovery_sha"
printf 'SOURCE=%s\nLINES=%s-%s\n' "$recovery_rel" "$first" "$last"
nl -ba -- "$RECOVERY_SOURCE" | sed -n "$first,$last"'p'
pass 'bounded recovery cleanup method inspected'
