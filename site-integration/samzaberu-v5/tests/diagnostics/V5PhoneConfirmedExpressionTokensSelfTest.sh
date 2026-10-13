#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ "${1:-}" == "--synthetic-only" ]] || { echo "FAIL: --synthetic-only is required"; exit 1; }
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="$ROOT/V5PhoneConfirmedExpressionTokens.php"
TMP="$(mktemp -d /tmp/v5-phone-expression-test.XXXXXXXX)"
trap 'rm -f -- "$TMP/fixture" "$TMP/out" "$TMP/bad"; rmdir -- "$TMP"' EXIT
for ((n=1;n<=1015;n++)); do
  case "$n" in
    891) printf '%s\n' ' $phoneuser["CONFIRMED"] = $hiddenSensitiveVariable === "SECRET_CANARY_123" ? "N" : "Y";' ;;
    1012) printf '%s\n' ' $hiddenSensitiveVariable == "SECRET_CANARY_456" ? ["=CONFIRMED" => "Y"] : [];' ;;
    *) printf '\n' ;;
  esac
done > "$TMP/fixture"
php -n "$SCRIPT" --source-only "$TMP/fixture" > "$TMP/out"
grep -Fxq 'SOURCE_ANCHORS_VALID=YES' "$TMP/out"
grep -Eq '^SOURCE_LINE_891_TOKEN_SHAPE=.*VAR_PHONEUSER.*FIELD_CONFIRMED.*LITERAL_N.*LITERAL_Y' "$TMP/out"
grep -Eq '^SOURCE_LINE_1012_TOKEN_SHAPE=.*FIELD_FILTER_CONFIRMED.*LITERAL_Y' "$TMP/out"
grep -Fxq 'SOURCE_VALUES_PRINTED=NO' "$TMP/out"
if grep -Eq '(SECRET_CANARY|hiddenSensitiveVariable|PASSWORD_PLACEHOLDER)' "$TMP/out"; then
  echo "FAIL: tokenizer leaked private identifier or literal"
  exit 1
fi
echo "PASS: real token_get_all masked source values and preserved fixed CONFIRMED shapes"

sed '891s/CONFIRMED/OTHER/' "$TMP/fixture" > "$TMP/bad"
if php -n "$SCRIPT" --source-only "$TMP/bad" > "$TMP/out" 2>&1; then
  echo "FAIL: mutated assignment was accepted"
  exit 1
fi
grep -Fxq 'SOURCE_ANCHORS_VALID=NO' "$TMP/out"
echo "PASS: changed line-891 anchor refused"

sed '1012s/CONFIRMED/OTHER/' "$TMP/fixture" > "$TMP/bad"
if php -n "$SCRIPT" --source-only "$TMP/bad" > "$TMP/out" 2>&1; then
  echo "FAIL: mutated confirmation check was accepted"
  exit 1
fi
grep -Fxq 'SOURCE_ANCHORS_VALID=NO' "$TMP/out"
echo "PASS: changed line-1012 anchor refused"

echo 'MODE=SYNTHETIC_ONLY'
echo 'PRODUCTION_SOURCE_READ=NO'
echo 'FINAL_STATUS=PASS'
echo 'FINAL_RC=0'
