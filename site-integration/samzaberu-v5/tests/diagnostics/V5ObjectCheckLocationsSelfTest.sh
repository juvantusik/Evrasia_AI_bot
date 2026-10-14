#!/usr/bin/env bash
# Synthetic sources only. Never reads or executes production PHP.
set -Eeuo pipefail
umask 077
[[ "${1:-}" == '--synthetic-only' ]] || { echo 'FAIL: synthetic-only required'; exit 1; }
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
SCANNER="$HERE/V5PhoneCheckActiveCallerDiscovery.php"
TMP="$(mktemp -d /tmp/v5-object-check-tests.XXXXXXXX)"
GOOD="$TMP/local/php_interface/Controllers/V4/PhoneFlow.php"
HELPER="$TMP/local/php_interface/lib/SmsPhone.php"
SENSITIVE="$TMP/local/php_interface/lib/TokenSettings.php"
cleanup() {
  rm -f -- "$GOOD" "$HELPER" "$SENSITIVE" "$TMP/out" "$TMP/err" "$TMP/lint"
  rmdir -- "$TMP/local/php_interface/Controllers/V4" "$TMP/local/php_interface/Controllers" \
    "$TMP/local/php_interface/lib" "$TMP/local/php_interface" "$TMP/local" "$TMP"
}
trap cleanup EXIT
mkdir -p "$TMP/local/php_interface/Controllers/V4" "$TMP/local/php_interface/lib"
cat > "$GOOD" <<'PHP'
<?php
// $PRIVATE_CANARY_COMMENT->check('PRIVATE_CANARY_COMMENT');
$PRIVATE_CANARY_RECEIVER->check('PRIVATE_CANARY_ARGUMENT');
$PRIVATE_CANARY_RECEIVER
    ?->
    check('PRIVATE_CANARY_NULLSAFE');
$PRIVATE_CANARY_RECEIVER->PRIVATE_CANARY_PROPERTY->check('PRIVATE_CANARY_PROPERTY');
$PRIVATE_CANARY_RECEIVER?->PRIVATE_CANARY_PROPERTY?->check('PRIVATE_CANARY_PROPERTY');
PRIVATE_CANARY_FACTORY()->check('PRIVATE_CANARY_CALL');
($PRIVATE_CANARY_RECEIVER)->check('PRIVATE_CANARY_GROUP');
$PRIVATE_CANARY_ARRAY[0]->check('PRIVATE_CANARY_INDEX');
$PRIVATE_CANARY_RECEIVER->{'PRIVATE_CANARY_DYNAMIC'}->check('PRIVATE_CANARY_DYNAMIC');
$literal = '$PRIVATE_CANARY_LITERAL->check("PRIVATE_CANARY_STRING"); PhoneAuth::check()';
$heredoc = <<<CANARY_TEXT
PhoneAuth::check('PRIVATE_CANARY_HEREDOC'); new PhoneAuth();
\$PRIVATE_CANARY_LITERAL->check('PRIVATE_CANARY_HEREDOC');
CANARY_TEXT;
$nowdoc = <<<'CANARY_TEXT'
$PRIVATE_CANARY_LITERAL->check('PRIVATE_CANARY_NOWDOC'); use PhoneAuth as Alias;
CANARY_TEXT;
/* $PRIVATE_CANARY_COMMENT->check('PRIVATE_CANARY_COMMENT'); */
PHP
cat > "$HELPER" <<'PHP'
<?php
/* $PRIVATE_CANARY_COMMENT?->check('PRIVATE_CANARY_COMMENT'); */
$PRIVATE_CANARY_OTHER->check('PRIVATE_CANARY_HELPER');
PHP
php -n -l "$SCANNER" > "$TMP/lint"
php -n "$SCANNER" --source-only --approved-scope --locate-object-checks \
  "$GOOD" "$HELPER" > "$TMP/out" 2> "$TMP/err"
grep -Fxq 'OBJECT_CHECK_SOURCE=Controllers/V4/PhoneFlow.php' "$TMP/out"
grep -Fxq 'OBJECT_CHECK_SOURCE=lib/SmsPhone.php' "$TMP/out"
grep -Fxq "OBJECT_CHECK_SOURCE_SHA256=$(sha256sum -- "$GOOD" | cut -d ' ' -f 1)" "$TMP/out"
grep -Fxq "OBJECT_CHECK_SOURCE_SHA256=$(sha256sum -- "$HELPER" | cut -d ' ' -f 1)" "$TMP/out"
grep -Fxq 'OBJECT_CHECK_LOCATION LINE=3 METHOD_TOKEN_LINE=3 OPERATOR=OBJECT RECEIVER_SHAPE=VARIABLE_MASKED' "$TMP/out"
grep -Fxq 'OBJECT_CHECK_LOCATION LINE=5 METHOD_TOKEN_LINE=6 OPERATOR=NULLSAFE RECEIVER_SHAPE=VARIABLE_MASKED' "$TMP/out"
grep -Fxq 'OBJECT_CHECK_LOCATION LINE=7 METHOD_TOKEN_LINE=7 OPERATOR=OBJECT RECEIVER_SHAPE=PROPERTY_CHAIN_MASKED' "$TMP/out"
grep -Fxq 'OBJECT_CHECK_LOCATION LINE=8 METHOD_TOKEN_LINE=8 OPERATOR=NULLSAFE RECEIVER_SHAPE=PROPERTY_CHAIN_MASKED' "$TMP/out"
grep -Fxq 'OBJECT_CHECK_LOCATION LINE=9 METHOD_TOKEN_LINE=9 OPERATOR=OBJECT RECEIVER_SHAPE=PARENTHESIZED_OR_CALL' "$TMP/out"
grep -Fxq 'OBJECT_CHECK_LOCATION LINE=10 METHOD_TOKEN_LINE=10 OPERATOR=OBJECT RECEIVER_SHAPE=PARENTHESIZED_OR_CALL' "$TMP/out"
grep -Fxq 'OBJECT_CHECK_LOCATION LINE=11 METHOD_TOKEN_LINE=11 OPERATOR=OBJECT RECEIVER_SHAPE=INDEXED_EXPRESSION' "$TMP/out"
grep -Fxq 'OBJECT_CHECK_LOCATION LINE=12 METHOD_TOKEN_LINE=12 OPERATOR=OBJECT RECEIVER_SHAPE=UNKNOWN' "$TMP/out"
grep -Fxq 'OBJECT_CHECK_LOCATIONS_EMITTED=9' "$TMP/out"
grep -Fxq 'OBJECT_CHECK_SOURCE_FILES=2' "$TMP/out"
grep -Fxq 'OBJECT_CHECK_CALLS_UNRESOLVED=9' "$TMP/out"
grep -Fxq 'ACTIVE_PHONEAUTH_STATIC_CALLS=0' "$TMP/out"
grep -Fxq 'ACTIVE_PHONEAUTH_INSTANTIATIONS=0' "$TMP/out"
grep -Fxq 'PHONEAUTH_IMPORT_HINTS=0' "$TMP/out"
grep -Fxq 'CANDIDATE_SOURCE_FILES=0' "$TMP/out"
grep -Fxq 'RECEIVER_CLASS_PROVEN=NO' "$TMP/out"
grep -Fxq 'FINAL_STATUS=PASS' "$TMP/out"
if grep -Eq 'PRIVATE_CANARY|CANARY_TEXT|CANDIDATE_SOURCE=' "$TMP/out" || [[ -s "$TMP/err" ]]; then
  echo 'FAIL: private source value or unexpected parser output'; exit 1
fi
echo 'PASS: safe locations, distinct operator/method lines and shape enums across two sources'
echo 'PASS: comments, literal strings, heredoc and nowdoc decoys ignored; private values masked'

# Default mode remains the prior inventory, without location records.
php -n "$SCANNER" --source-only --approved-scope "$GOOD" "$HELPER" > "$TMP/out" 2> "$TMP/err"
grep -Fxq 'OBJECT_CHECK_CALLS_UNRESOLVED=9' "$TMP/out"
if grep -Eq '^OBJECT_CHECK_(SOURCE|LOCATION)|^RECEIVER_CLASS_PROVEN=' "$TMP/out" || [[ -s "$TMP/err" ]]; then
  echo 'FAIL: optional mode changed default inventory output'; exit 1
fi
echo 'PASS: default inventory output remains unchanged'

# Suppressed static-call records must not mark complete object output as capped.
printf '<?php\n' > "$GOOD"
for ((i = 0; i < 105; i++)); do
  printf '%s\n' 'PhoneAuth::check("PRIVATE_CANARY_ARGUMENT");' >> "$GOOD"
done
printf '%s\n' '$PRIVATE_CANARY_RECEIVER->check("PRIVATE_CANARY_ARGUMENT");' >> "$GOOD"
php -n "$SCANNER" --source-only --approved-scope --locate-object-checks \
  "$GOOD" > "$TMP/out" 2> "$TMP/err"
grep -Fxq 'ACTIVE_PHONEAUTH_STATIC_CALLS=105' "$TMP/out"
grep -Fxq 'OBJECT_CHECK_LOCATIONS_EMITTED=1' "$TMP/out"
grep -Fxq 'OUTPUT_CAPPED=NO' "$TMP/out"
[[ ! -s "$TMP/err" ]]
if grep -Eq 'PRIVATE_CANARY|^ACTIVE_PHONEAUTH_STATIC_CALL LINE=' "$TMP/out"; then
  echo 'FAIL: hidden static-call output or private values emitted'; exit 1
fi
echo 'PASS: complete object locations do not inherit the legacy static-output cap'

# The exact cap is accepted; exceeding it never emits a partial result.
printf '<?php\n' > "$GOOD"
for ((i = 0; i < 80; i++)); do
  printf '%s\n' '$PRIVATE_CANARY_RECEIVER->check("PRIVATE_CANARY_ARGUMENT");' >> "$GOOD"
done
php -n "$SCANNER" --source-only --approved-scope --locate-object-checks \
  "$GOOD" > "$TMP/out" 2> "$TMP/err"
grep -Fxq 'OBJECT_CHECK_LOCATIONS_EMITTED=80' "$TMP/out"
[[ ! -s "$TMP/err" ]]
printf '%s\n' '$PRIVATE_CANARY_RECEIVER->check("PRIVATE_CANARY_ARGUMENT");' >> "$GOOD"
if php -n "$SCANNER" --source-only --approved-scope --locate-object-checks \
    "$HELPER" "$GOOD" > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: object-check record cap accepted'; exit 1
fi
grep -Fxq 'FAIL: object check location cap exceeded' "$TMP/err"
[[ ! -s "$TMP/out" ]]
echo 'PASS: 80-location boundary accepted; overflow fails before publishing any location'

cat > "$SENSITIVE" <<'PHP'
<?php
$PRIVATE_CANARY_SECRET->check('PRIVATE_CANARY_SECRET');
PHP
if php -n "$SCANNER" --source-only --approved-scope --locate-object-checks \
    "$HELPER" "$SENSITIVE" > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: sensitive source accepted'; exit 1
fi
grep -Fxq 'FAIL: source scope or sensitive basename guard' "$TMP/err"
[[ ! -s "$TMP/out" ]]
echo 'PASS: sensitive path denied without publishing earlier buffered locations'

# Keep the original file-count guard in optional mode.
paths=()
for ((i = 0; i < 81; i++)); do paths+=("$HELPER"); done
if php -n "$SCANNER" --source-only --approved-scope --locate-object-checks \
    "${paths[@]}" > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: source file-count cap accepted'; exit 1
fi
grep -Fxq 'FAIL: scan file count guard' "$TMP/err"
[[ ! -s "$TMP/out" ]]
echo 'PASS: 81 source paths denied by existing bounded-file guard'
echo 'MODE=SYNTHETIC_ONLY'
echo 'PRODUCTION_SOURCE_READ=NO'
echo 'FINAL_STATUS=PASS'
echo 'FINAL_RC=0'
