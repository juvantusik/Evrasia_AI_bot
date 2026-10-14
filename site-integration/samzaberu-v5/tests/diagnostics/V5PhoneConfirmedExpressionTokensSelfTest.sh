#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ "${1:-}" == "--synthetic-only" ]] || { echo "FAIL: --synthetic-only is required"; exit 1; }
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="$ROOT/V5PhoneConfirmedExpressionTokens.php"
TMP="$(mktemp -d /tmp/v5-phone-expression-test.XXXXXXXX)"
trap 'rm -f -- "$TMP/fixture" "$TMP/out" "$TMP/bad" "$TMP/comments" "$TMP/block" "$TMP/mixed"; rmdir -- "$TMP"' EXIT
for ((n=1;n<=1015;n++)); do
  case "$n" in
    1) printf '%s\n' '<?php' ;;
    891) printf '%s\n' ' $phoneuser["CONFIRMED"] = $hiddenSensitiveVariable === "SECRET_CANARY_123" ? "N" : "Y";' ;;
    1012) printf '%s\n' ' $hiddenSensitiveVariable == "SECRET_CANARY_456" ? ["=CONFIRMED" => "Y"] : [];' ;;
    *) printf '\n' ;;
  esac
done > "$TMP/fixture"
php -n "$SCRIPT" --source-only "$TMP/fixture" > "$TMP/out"
grep -Fxq 'SOURCE_TEXT_ANCHORS_VALID=YES' "$TMP/out"
grep -Fxq 'PARSER=PHP_TOKEN_GET_ALL_COMPLETE_ORIGINAL_SOURCE' "$TMP/out"
grep -Fxq 'BOTH_SOURCE_ANCHORS_ACTIVE_PHP=YES' "$TMP/out"
grep -Fxq 'SOURCE_ANCHOR_891_LEXICAL_KIND=PHP_CODE' "$TMP/out"
grep -Fxq 'SOURCE_ANCHOR_1012_LEXICAL_KIND=PHP_CODE' "$TMP/out"
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
grep -Fxq 'SOURCE_TEXT_ANCHORS_VALID=NO' "$TMP/out"
echo "PASS: changed line-891 anchor refused"

sed '1012s/CONFIRMED/OTHER/' "$TMP/fixture" > "$TMP/bad"
if php -n "$SCRIPT" --source-only "$TMP/bad" > "$TMP/out" 2>&1; then
  echo "FAIL: mutated confirmation check was accepted"
  exit 1
fi
grep -Fxq 'SOURCE_TEXT_ANCHORS_VALID=NO' "$TMP/out"
echo "PASS: changed line-1012 anchor refused"

# A source-level text regexp must never promote commented PHP to active code.
sed -e '891s/^/\/\/ /' -e '1012s/^/\/\/ /' "$TMP/fixture" > "$TMP/comments"
php -n "$SCRIPT" --source-only "$TMP/comments" > "$TMP/out"
grep -Fxq 'SOURCE_TEXT_ANCHORS_VALID=YES' "$TMP/out"
grep -Fxq 'SOURCE_ANCHOR_891_LEXICAL_KIND=COMMENT' "$TMP/out"
grep -Fxq 'SOURCE_ANCHOR_1012_LEXICAL_KIND=COMMENT' "$TMP/out"
grep -Fxq 'BOTH_SOURCE_ANCHORS_ACTIVE_PHP=NO' "$TMP/out"
grep -Fxq 'SOURCE_LINE_891_LEXICAL_CLASS=COMMENT_ONLY' "$TMP/out"
grep -Fxq 'SOURCE_LINE_891_TOKEN_SHAPE=NONE' "$TMP/out"
echo 'PASS: previously false-positive commented lines are explicitly non-executable'

sed -e '890s/^$/\/\*/' -e '892s/^$/\*\//' \
    -e '1011s/^$/\/\*/' -e '1013s/^$/\*\//' \
    "$TMP/fixture" > "$TMP/block"
php -n "$SCRIPT" --source-only "$TMP/block" > "$TMP/out"
grep -Fxq 'SOURCE_ANCHOR_891_LEXICAL_KIND=COMMENT' "$TMP/out"
grep -Fxq 'SOURCE_ANCHOR_1012_LEXICAL_KIND=COMMENT' "$TMP/out"
grep -Fxq 'BOTH_SOURCE_ANCHORS_ACTIVE_PHP=NO' "$TMP/out"
echo 'PASS: source-spanning multiline comment state is preserved'

sed -e '891s/^/ $code = true; \/\/ /' \
    "$TMP/fixture" > "$TMP/mixed"
php -n "$SCRIPT" --source-only "$TMP/mixed" > "$TMP/out"
grep -Fxq 'SOURCE_LINE_891_LEXICAL_CLASS=ACTIVE_PHP_AND_COMMENT' "$TMP/out"
grep -Fxq 'SOURCE_ANCHOR_891_LEXICAL_KIND=COMMENT' "$TMP/out"
grep -Fxq 'BOTH_SOURCE_ANCHORS_ACTIVE_PHP=NO' "$TMP/out"
echo 'PASS: inline comment anchor is not mistaken for earlier code on same line'

echo 'MODE=SYNTHETIC_ONLY'
echo 'PRODUCTION_SOURCE_READ=NO'
echo 'FINAL_STATUS=PASS'
echo 'FINAL_RC=0'
