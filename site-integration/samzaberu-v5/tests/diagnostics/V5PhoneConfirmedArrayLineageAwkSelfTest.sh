#!/usr/bin/env bash
# Pure synthetic fixture: positively parse and exercise exact production AWK.
set -Eeuo pipefail
umask 077
[[ "${1:-}" == '--synthetic-only' ]] || { echo 'FAIL: must select --synthetic-only'; exit 1; }

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
analyzer="$here/v5_phone_confirmed_array_lineage.awk"
[[ -f "$analyzer" && -r "$analyzer" && ! -L "$analyzer" ]] || exit 1

tmp="$(mktemp -d /tmp/v5-lineage-synthetic.XXXXXXXX)"
trap 'rm -f -- "$tmp/good" "$tmp/bad891" "$tmp/bad869" "$tmp/out" "$tmp/err"; rmdir -- "$tmp"' EXIT

# Deterministic 1040-line FAKE PHP source, never customer info or Bitrix.
for ((n=1;n<=1040;n++)); do
  line=''
  case "$n" in
    869) line=' $rows = UserPhoneAuthTable::getList([]);' ;;
    876) line=' $added = UserPhoneAuthTable::add([]);' ;;
    880) line=' $phoneRow = UserPhoneAuthTable::getRowById(1);' ;;
    891) line=' $phoneRow["CONFIRMED"] = "Y";' ;;
    896) line=' $confirmed = $phoneRow["CONFIRMED"];' ;;
    1012) line=' $status = $other["CONFIRMED"];' ;;
  esac
  printf '%s\n' "$line"
done > "$tmp/good"

# Critical regression: POSITIVE AWK execution (unlike bash -n and wrong-host).
awk -f "$analyzer" "$tmp/good" > "$tmp/out" 2> "$tmp/err"
grep -Fxq 'SOURCE_ANCHORS_VALID=YES' "$tmp/out"
grep -Fxq 'CONFIRMED_ARRAY_VARIABLE=$phonerow' "$tmp/out"
grep -Fxq 'CONFIRMED_RECEIVER_REUSES_AFTER_891=1' "$tmp/out"
grep -Fxq 'DIRECT_CLASS_METHOD_CALL_MARKERS=3' "$tmp/out"
[[ ! -s "$tmp/err" ]] || { echo 'FAIL: AWK produced stderr'; exit 1; }
echo 'PASS: POSIX AWK parser ran positive fixture with real 869/876/880/891/1012 anchors'

sed '891s/CONFIRMED/OTHER/' "$tmp/good" > "$tmp/bad891"
if awk -f "$analyzer" "$tmp/bad891" > "$tmp/out" 2> "$tmp/err"; then
  echo 'FAIL: changed 891 CONFIRMED write should be rejected'; exit 1
fi
grep -Fxq 'SOURCE_ANCHORS_VALID=NO' "$tmp/out"
echo 'PASS: changed CONFIRMED assignment fails closed'

sed '869s/UserPhoneAuthTable/UserTable/' "$tmp/good" > "$tmp/bad869"
if awk -f "$analyzer" "$tmp/bad869" > "$tmp/out" 2> "$tmp/err"; then
  echo 'FAIL: changed 869 table reference should be rejected'; exit 1
fi
grep -Fxq 'SOURCE_ANCHORS_VALID=NO' "$tmp/out"
echo 'PASS: changed table call fails closed'

echo 'SYNTHETIC_ONLY=YES'
echo 'PRODUCTION_READ=NO'
echo 'FINAL_STATUS=PASS'
echo 'FINAL_RC=0'
