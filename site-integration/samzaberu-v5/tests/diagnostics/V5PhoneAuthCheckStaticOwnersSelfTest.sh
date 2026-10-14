#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ "${1:-}" == '--synthetic-only' ]] || { echo 'FAIL: synthetic-only arg required'; exit 1; }
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PARSER="$HERE/V5PhoneAuthCheckStaticOwners.php"
TMP="$(mktemp -d /tmp/v5-helper-owner-test.XXXXXXXX)"
trap 'rm -f -- "$TMP/good" "$TMP/bad" "$TMP/out" "$TMP/err"; rmdir -- "$TMP"' EXIT

for ((line=1;line<=220;line++)); do
  case "$line" in
    1) printf '%s\n' '<?php' ;;
    65) printf '%s\n' 'public function sendCode() {' ;;
    106) printf '%s\n' ' $r = \Bitrix\Main\SmsCodeTable::add(["SECRET_CANARY"=>"NEVER_PRINT"]);' ;;
    134) printf '%s\n' '}' ;;
    135) printf '%s\n' 'public function check() {' ;;
    145) printf '%s\n' ' if ($a) {' ;;
    153) printf '%s\n' ' \Bitrix\Main\SmsCodeTable::delete($hidden);' ;;
    160) printf '%s\n' ' \Bitrix\Main\SmsCodeTable::delete(123);' ;;
    174) printf '%s\n' ' return true;' ;;
    187) printf '%s\n' '}' ;;
    188) printf '%s\n' 'public function createUser() {' ;;
    220) printf '%s\n' '}' ;;
    *) printf '\n' ;;
  esac
done > "$TMP/good"

php -n -l "$PARSER" > "$TMP/err"
php -n "$PARSER" --source-only "$TMP/good" > "$TMP/out" 2> "$TMP/err"
grep -Fxq 'SOURCE_ANCHORS_VALID=YES' "$TMP/out"
grep -Fxq 'STATIC_CALL_LINE=106 METHOD=add OWNER=\Bitrix\Main\SmsCodeTable OWNER_KIND=SOURCE_CLASS_IDENTIFIER FIRST_ARG_KIND=SHORT_ARRAY' "$TMP/out"
grep -Fxq 'STATIC_CALL_LINE=153 METHOD=delete OWNER=\Bitrix\Main\SmsCodeTable OWNER_KIND=SOURCE_CLASS_IDENTIFIER FIRST_ARG_KIND=VARIABLE' "$TMP/out"
grep -Fxq 'STATIC_CALL_LINE=160 METHOD=delete OWNER=\Bitrix\Main\SmsCodeTable OWNER_KIND=SOURCE_CLASS_IDENTIFIER FIRST_ARG_KIND=NUMBER_LITERAL_MASKED' "$TMP/out"
grep -Fxq 'CHECK_BRANCH_CONDITIONS=1' "$TMP/out"
grep -Fxq 'CHECK_RETURN_STATEMENTS=1' "$TMP/out"
grep -Fxq 'FINAL_STATUS=PASS' "$TMP/out"
if grep -E '(SECRET_CANARY|NEVER_PRINT|hidden|123)' "$TMP/out" || [[ -s "$TMP/err" ]]; then
  echo 'FAIL: protected argument value or variable leaked'; exit 1
fi
echo 'PASS: positive full-file PHP tokenizer found fixed static owners without values'

sed '153s#^#// #' "$TMP/good" > "$TMP/bad"
if php -n "$PARSER" --source-only "$TMP/bad" > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: comment-only call accepted'; exit 1
fi
grep -Fq 'FAIL: active static call anchor changed' "$TMP/err"
echo 'PASS: commented helper static delete fails closed'

sed '160s/::delete/::update/' "$TMP/good" > "$TMP/bad"
if php -n "$PARSER" --source-only "$TMP/bad" > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: unexpected method accepted'; exit 1
fi
grep -Fq 'FAIL: active static call anchor changed' "$TMP/err"
echo 'PASS: changed helper call name fails closed'
echo 'SYNTHETIC_ONLY=YES'
echo 'PRODUCTION_SOURCE_READ=NO'
echo 'FINAL_STATUS=PASS'
echo 'FINAL_RC=0'
