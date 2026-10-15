#!/usr/bin/env bash
# Synthetic token fixtures only. No Bitrix bootstrap and no real production sources.
set -Eeuo pipefail
umask 077
[[ $# -eq 1 && "$1" == '--synthetic-only' ]] || { echo 'FAIL: synthetic-only required'; exit 2; }
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PARSER="$HERE/V5CoreUserPhoneReplaceFlow.php"
LAUNCHER="$HERE/v5_core_user_phone_replace_flow_readonly.sh"
TMP="$(mktemp -d /tmp/v5-phone-flow-test.XXXXXXXX)"
SRC="$TMP/bitrix/modules/main/classes/general/user.php"
cleanup(){
  rm -f -- "$SRC" "$TMP/out" "$TMP/err"
  rmdir -- "$TMP/bitrix/modules/main/classes/general" \
    "$TMP/bitrix/modules/main/classes" "$TMP/bitrix/modules/main" \
    "$TMP/bitrix/modules" "$TMP/bitrix" "$TMP"
}
trap cleanup EXIT
mkdir -p -- "$(dirname -- "$SRC")"
php -n -l "$PARSER" >/dev/null
bash -n "$LAUNCHER"
bash -n "${BASH_SOURCE[0]}"
PASS_COUNT=0

fixture(){
  local mode="${1:-plain}" 
  for n in $(seq 1 4106); do
    case "$n" in
      1) echo '<?php';;
      3919) echo 'class CUser {';;
      3920) echo 'public function Update($CANARY_PRIVATE) {';;
      4096) if [[ "$mode" == nested_var ]]; then echo '$CANARY_PRIVATE = $CANARY_OTHER;'; else echo '// CANARY'; fi;;
      4097) echo 'if (true) {';;
      4098)
        if [[ "$mode" == comment ]]; then
          echo '// \Bitrix\Main\UserPhoneAuthTable::delete(["USER_ID" => $CANARY_PRIVATE]);'
        elif [[ "$mode" == nested_var ]]; then
          echo '\Bitrix\Main\UserPhoneAuthTable::delete($CANARY_PRIVATE);'
        else
          echo '\Bitrix\Main\UserPhoneAuthTable::delete(["USER_ID" => $CANARY_PRIVATE]);'
        fi;;
      4099) if [[ "$mode" == divergent ]]; then echo '}'; else echo '// CANARY'; fi;;
      4101)
        if [[ "$mode" == divergent ]]; then echo 'if (false) {'
        elif [[ "$mode" == nested ]]; then echo 'if (true) {'
        elif [[ "$mode" == nested_var ]]; then echo 'if ($CANARY_PRIVATE) {'
        else echo '// CANARY'
        fi;;
      4102)
        case "$mode" in
          confirmed) echo '\Bitrix\Main\UserPhoneAuthTable::add(["USER_ID" => $CANARY_PRIVATE, "PHONE_NUMBER" => "CANARY_SECRET", "CONFIRMED" => "Y"]);';;
          dynamic) echo '\Bitrix\Main\UserPhoneAuthTable::add($CANARY_VARIABLE);';;
          string) echo '$CANARY_PRIVATE = "UserPhoneAuthTable::add([CONFIRMED => Y])";';;
          *) echo '\Bitrix\Main\UserPhoneAuthTable::add(["USER_ID" => $CANARY_PRIVATE, "PHONE_NUMBER" => "CANARY_SECRET"]);';;
        esac;;
      4103) echo '}';;
      4104) echo '}';;
      4105) echo '}';;
      4106) if [[ "$mode" == nested || "$mode" == nested_var ]]; then echo '}'; else echo '// CANARY'; fi;;
      *) echo '// CANARY_REDACT';;
    esac
  done > "$SRC"
}
scan(){
  local sha
  sha="$(sha256sum -- "$SRC")"; sha="${sha%% *}"
  php -n "$PARSER" --source-only --core-user "$SRC" --expected-sha256 "$sha" > "$TMP/out" 2> "$TMP/err"
}
assert_mask(){
  if grep -Eq 'CANARY|PRIVATE_VALUE|SECRET_VALUE|PHONE_NUMBER" =>|CONFIRMED" =>' "$TMP/out" "$TMP/err"; then
    echo 'FAIL: source identifiers or values leaked'
    exit 1
  fi
}
expect_pass(){
  scan
  [[ ! -s "$TMP/err" ]]
  grep -Fxq 'FINAL_STATUS=PASS' "$TMP/out"
  grep -Fxq 'CORE_ANALYZED_BYTES_SHA_MATCHED=YES' "$TMP/out"
  grep -Fxq 'NATIVE_PHONE_VERIFICATION_WRITE_PROVEN=NO' "$TMP/out"
  grep -Fxq 'PRODUCTION_BASELINE_REPLACED=NO' "$TMP/out"
  assert_mask
  PASS_COUNT=$((PASS_COUNT+1))
}
expect_fail(){
  local reason="$1" rc
  if scan; then echo 'FAIL: expected anchor denial'; exit 1; else rc=$?; fi
  [[ "$rc" -eq 2 && ! -s "$TMP/out" ]]
  grep -Fxq "FAIL: $reason" "$TMP/err"
  assert_mask
  PASS_COUNT=$((PASS_COUNT+1))
}

fixture plain
expect_pass
grep -Fxq 'DIRECT_MUTATOR_ANCHOR_COUNT=2' "$TMP/out"
grep -Fxq 'CALL_BRACED_BLOCK_RELATION=SAME_BRACED_BLOCK' "$TMP/out"
grep -Fxq 'DELETE_FIRST_ARG_SHAPE=SHORT_ARRAY_LITERAL' "$TMP/out"
grep -Fxq 'ADD_FIRST_ARG_SHAPE=SHORT_ARRAY_LITERAL' "$TMP/out"
grep -Fxq 'ADD_INLINE_USER_ID_KEY_COUNT=1' "$TMP/out"
grep -Fxq 'ADD_INLINE_PHONE_NUMBER_KEY_COUNT=1' "$TMP/out"
grep -Fxq 'ADD_INLINE_CONFIRMED_KEY_COUNT=0' "$TMP/out"

fixture confirmed
expect_pass
grep -Fxq 'ADD_INLINE_CONFIRMED_KEY_COUNT=1' "$TMP/out"
grep -Fxq 'ADD_INLINE_CONFIRMED_VALUE_KIND=LITERAL_Y' "$TMP/out"

fixture dynamic
expect_pass
grep -Fxq 'ADD_FIRST_ARG_SHAPE=VARIABLE_OR_EXPRESSION' "$TMP/out"
grep -Fxq 'ADD_INLINE_KEYS_COVERAGE=NOT_APPLICABLE' "$TMP/out"

fixture divergent
expect_pass
grep -Fxq 'CALL_BRACED_BLOCK_RELATION=DIVERGENT_BLOCKS' "$TMP/out"

fixture nested
expect_pass
grep -Fxq 'CALL_BRACED_BLOCK_RELATION=DELETE_BLOCK_ANCESTOR' "$TMP/out"
grep -Fxq 'ADD_EXCLUSIVE_BLOCK_KIND=IF' "$TMP/out"
grep -Fxq 'ADD_EXCLUSIVE_BLOCK_OPEN_LINE=4101' "$TMP/out"
grep -Fxq 'DELETE_FIRST_ARG_EXACT_SHAPE=ARRAY_CONSTRUCTOR' "$TMP/out"

fixture nested_var
expect_pass
grep -Fxq 'CALL_BRACED_BLOCK_RELATION=DELETE_BLOCK_ANCESTOR' "$TMP/out"
grep -Fxq 'ADD_EXCLUSIVE_BLOCK_KIND=IF' "$TMP/out"
grep -Fxq 'DELETE_FIRST_ARG_EXACT_SHAPE=BARE_VARIABLE' "$TMP/out"
grep -Fxq 'DELETE_BASE_VARIABLE_PRIOR_ASSIGNMENT_COUNT=1' "$TMP/out"
grep -Fxq 'DELETE_BASE_VARIABLE_LAST_ASSIGNMENT_LINE=4096' "$TMP/out"
grep -Fxq 'DELETE_BASE_VARIABLE_LAST_ASSIGNMENT_RHS_KIND=VARIABLE_OR_DERIVED' "$TMP/out"
grep -Fxq 'ADD_GUARD_REFERENCES_DELETE_ARG_BASE_VARIABLE=YES' "$TMP/out"

fixture comment
expect_fail 'exact pinned native mutator anchor DELETE'

fixture string
expect_fail 'exact pinned native mutator anchor ADD'

fixture plain
if php -n "$PARSER" --source-only --core-user "$SRC" \
  --expected-sha256 "$(printf '%064d' 0)" > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: accepted stale checksum'; exit 1
fi
[[ ! -s "$TMP/out" ]]
grep -Fxq 'FAIL: exact analyzed source SHA guard' "$TMP/err"
assert_mask
PASS_COUNT=$((PASS_COUNT+1))

# Line mismatch must be rejected even with the correct buffer SHA.
sed -i '4098s/UserPhoneAuthTable/OtherClass/' "$SRC"
expect_fail 'exact pinned native mutator anchor DELETE'

if [[ "$(hostname -s)" != evrasia ]]; then
  if bash "$LAUNCHER" > "$TMP/out" 2> "$TMP/err"; then
    echo 'FAIL: production launcher accepted nonproduction host'; exit 1
  fi
  grep -Fxq 'PHP_SCANNER_EXECUTED=NO' "$TMP/out"
  grep -Fxq 'FINAL_STATUS=FAIL' "$TMP/out"
  PASS_COUNT=$((PASS_COUNT+1))
fi
printf 'MODE=SYNTHETIC_ONLY\nPRODUCTION_SOURCE_READ=NO\nSITE_PHP_EXECUTED=NO\n'
printf 'PASS_COUNT=%s\nFAIL_COUNT=0\nFINAL_STATUS=PASS\nFINAL_RC=0\n' "$PASS_COUNT"
