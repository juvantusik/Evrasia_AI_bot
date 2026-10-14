#!/usr/bin/env bash
# Positive and negative static PHP lexical checks on synthetic PhoneAuth only.
set -Eeuo pipefail
umask 077
[[ "${1:-}" == '--synthetic-only' ]] || { echo 'FAIL: must specify synthetic-only'; exit 1; }
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PARSER="$HERE/V5PhoneAuthCheckReturnKinds.php"
TMP="$(mktemp -d /tmp/v5-check-return-shape.XXXXXXXX)"
trap 'rm -f -- "$TMP/active" "$TMP/commented" "$TMP/out" "$TMP/err"; rmdir -- "$TMP"' EXIT

for ((line=1;line<=190;line++)); do
  case "$line" in
    1) printf '%s\n' '<?php' ;;
    134) printf '%s\n' 'class PhoneAuth {' ;;
    135) printf '%s\n' 'public function check() {' ;;
    146) printf '%s\n' ' if ($blocked === true) {' ;;
    147) printf '%s\n' ' return false;' ;;
    148) printf '%s\n' ' }' ;;
    149) printf '%s\n' ' return null;' ;;
    152) printf '%s\n' ' if ($valid && $one !== $SECRET_CANARY) {' ;;
    153) printf '%s\n' ' \Evrasia\Orm\PhoneAuthCodeTable::delete($id);' ;;
    155) printf '%s\n' ' return true;' ;;
    156) printf '%s\n' ' }' ;;
    160) printf '%s\n' ' \Evrasia\Orm\PhoneAuthCodeTable::delete($id);' ;;
    179) printf '%s\n' ' return $maskedHiddenValue;' ;;
    185) printf '%s\n' ' return ["SECRET_CANARY_VALUE" => 12345];' ;;
    186) printf '%s\n' '}' ;;
    187) printf '%s\n' '}' ;;
    *) printf '\n' ;;
  esac
done > "$TMP/active"

php -n -l "$PARSER" > "$TMP/err"
php -n "$PARSER" --source-only "$TMP/active" > "$TMP/out" 2> "$TMP/err"
grep -Fxq 'SOURCE_ANCHORS_VALID=YES' "$TMP/out"
grep -Fxq 'CHECK_CONDITION_LINE=146 OPERATOR_KINDS=STRICT_EQUAL MASKED_VARIABLE_REFERENCES=1 BOOLEAN_OR_NULL_LITERAL_COUNT=1' "$TMP/out"
grep -Fxq 'CHECK_CONDITION_LINE=152 OPERATOR_KINDS=LOGICAL_AND,STRICT_NOT_EQUAL MASKED_VARIABLE_REFERENCES=3 BOOLEAN_OR_NULL_LITERAL_COUNT=0' "$TMP/out"
grep -Fxq 'CHECK_RETURN_LINE=147 KIND=FALSE_LITERAL_OR_EXPRESSION' "$TMP/out"
grep -Fxq 'CHECK_RETURN_LINE=149 KIND=NULL_LITERAL_OR_EXPRESSION' "$TMP/out"
grep -Fxq 'CHECK_RETURN_LINE=155 KIND=TRUE_LITERAL_OR_EXPRESSION' "$TMP/out"
grep -Fxq 'CHECK_RETURN_LINE=179 KIND=VARIABLE_OR_EXPRESSION' "$TMP/out"
grep -Fxq 'CHECK_RETURN_LINE=185 KIND=ARRAY_LITERAL' "$TMP/out"
grep -Fxq 'CHECK_RETURN_VALUES_AND_IDENTIFIERS_PRINTED=NO' "$TMP/out"
grep -Fxq 'FINAL_STATUS=PASS' "$TMP/out"
if grep -E 'SECRET_CANARY|maskedHiddenValue|12345' "$TMP/out" || [[ -s "$TMP/err" ]]; then
  echo 'FAIL: private literal or variable leaked'; exit 1
fi
echo 'PASS: actual PHP tokenizer classified two active branches and five active returns'

sed '155s/^/\/\/ /' "$TMP/active" > "$TMP/commented"
if php -n "$PARSER" --source-only "$TMP/commented" > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: comment-only return accepted'; exit 1
fi
grep -Fxq 'FAIL: expected live check() method and control anchors not found' "$TMP/err"
echo 'PASS: commented return does not count as executable PHP'

sed '152s/^/\/\/ /' "$TMP/active" > "$TMP/commented"
if php -n "$PARSER" --source-only "$TMP/commented" > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: comment-only condition accepted'; exit 1
fi
grep -Fxq 'FAIL: expected live check() method and control anchors not found' "$TMP/err"
echo 'PASS: commented condition does not count as executable PHP'
echo 'SYNTHETIC_ONLY=YES'
echo 'PRODUCTION_SOURCE_READ=NO'
echo 'FINAL_STATUS=PASS'
echo 'FINAL_RC=0'
