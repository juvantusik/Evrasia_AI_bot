#!/usr/bin/env bash
# Positive and negative parser runs on synthetic source only, never production.
set -Eeuo pipefail
umask 077
[[ "${1:-}" == "--synthetic-only" ]] || { echo "FAIL: --synthetic-only required"; exit 1; }
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
parser="$here/v5_phone_confirmation_semantic_boundary.awk"
[[ -f "$parser" && -r "$parser" && ! -L "$parser" ]] || exit 1
fixture="$(mktemp /tmp/v5-phone-boundary.XXXXXXXX.php)"
output="$(mktemp /tmp/v5-phone-boundary-out.XXXXXXXX)"
error="$(mktemp /tmp/v5-phone-boundary-err.XXXXXXXX)"
bad="$(mktemp /tmp/v5-phone-boundary-bad.XXXXXXXX)"
trap 'rm -f -- "$fixture" "$output" "$error" "$bad"' EXIT

for ((i=1;i<=1140;i++)); do
  line=''
  case "$i" in
    869) line=' $rows = UserPhoneAuthTable::getList([]);' ;;
    876) line=' $added = UserPhoneAuthTable::add([]);' ;;
    880) line=' if (($phoneuser = UserPhoneAuthTable::getRowById(1))) {}' ;;
    891) line=" \$phoneuser['CONFIRMED'] = 'N';" ;;
    1012) line=" if (\$u['PHONE_AUTH.CONFIRMED'] === 'Y') {}" ;;
    1026) line=' $obuser->Update(1, []);' ;;
  esac
  printf '%s\n' "$line"
done > "$fixture"

awk -f "$parser" "$fixture" > "$output" 2> "$error"
grep -Fxq "SOURCE_ANCHORS_VALID=YES" "$output"
grep -Fxq "ROW_READ_ASSIGNED_TO_PHONEUSER_SAME_LINE=YES" "$output"
grep -Fxq "PHONEUSER_CONFIRMED_ASSIGNMENT_VALUE=LITERAL_N" "$output"
grep -Fxq "CONFIRM_1012_FIELD_KIND=PHONE_AUTH_RELATION" "$output"
grep -Fxq "CONFIRM_1012_HAS_Y_LITERAL=YES" "$output"
grep -Fxq "PHONEUSER_DIRECT_REFS_AFTER_891=0" "$output"
grep -Fxq "REQUEST_AFTER_891_NATIVE_PHONE_TABLE_MUTATOR_CALLS=0" "$output"
grep -Fxq "CONFIRM_NATIVE_PHONE_TABLE_MUTATOR_CALLS=0" "$output"
[[ ! -s "$error" ]] || { echo "FAIL: valid fixture produced stderr"; exit 1; }
echo "PASS: positive semantic parser classified known phone-row anchors"

sed "891s/'N'/'Y'/" "$fixture" > "$bad"
awk -f "$parser" "$bad" > "$output" 2> "$error"
grep -Fxq "PHONEUSER_CONFIRMED_ASSIGNMENT_VALUE=LITERAL_Y" "$output"
echo "PASS: positive semantic parser distinguishes Y and N assignment literals"

sed '891s/CONFIRMED/OTHER/' "$fixture" > "$bad"
if awk -f "$parser" "$bad" > "$output" 2> "$error"; then
  echo "FAIL: changed CONFIRMED assignment accepted"
  exit 1
fi
grep -Fxq "SOURCE_ANCHORS_VALID=NO" "$output"
echo "PASS: tampered field fails closed"

sed '1012s/CONFIRMED/OTHER/' "$fixture" > "$bad"
if awk -f "$parser" "$bad" > "$output" 2> "$error"; then
  echo "FAIL: changed confirm method condition accepted"
  exit 1
fi
grep -Fxq "SOURCE_ANCHORS_VALID=NO" "$output"
echo "PASS: tampered confirm condition fails closed"

echo "MODE=SYNTHETIC_ONLY"
echo "PRODUCTION_PHP_READ=NO"
echo "FINAL_STATUS=PASS"
echo "FINAL_RC=0"
