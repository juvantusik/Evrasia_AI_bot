#!/usr/bin/env bash
# Synthetic-only: real PHP token_get_all(), never production source.
set -Eeuo pipefail
umask 077
[[ "${1:-}" == '--synthetic-only' ]] || { echo 'FAIL: --synthetic-only required'; exit 1; }
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PARSER="$HERE/V5PhoneCheckMaskedControlFlow.php"
TMP="$(mktemp -d /tmp/v5-masked-check.XXXXXXXX)"
trap 'rm -f -- "$TMP/helper" "$TMP/auth" "$TMP/bad" "$TMP/out" "$TMP/err"; rmdir -- "$TMP"' EXIT
for ((line=1;line<=190;line++)); do
  case "$line" in
    1) printf '%s\n' '<?php' ;;
    135) printf '%s\n' 'class PhoneAuth { public function check() {' ;;
    146) printf '%s\n' ' if (!$blocked) {' ;;
    147) printf '%s\n' '  return CheckFailure::from("PRIVATE_CANARY_SENTINEL");' ;;
    148) printf '%s\n' ' }' ;;
    149) printf '%s\n' ' return false;' ;;
    152) printf '%s\n' ' if ($tries > $max) {' ;;
    155) printf '%s\n' '  return SomeResult::deny($PRIVATE_CANARY);' ;;
    156) printf '%s\n' ' }' ;;
    179) printf '%s\n' ' return CheckResult::ok(true);' ;;
    185) printf '%s\n' ' return \Company\Response::ok($privateId);' ;;
    186) printf '%s\n' '}' ;;
    187) printf '%s\n' '}' ;;
    *) printf '\n' ;;
  esac
done > "$TMP/helper"
for ((line=1;line<=150;line++)); do
  case "$line" in
    1) printf '%s\n' '<?php' ;;
    60) printf '%s\n' 'class Auth { public function confirmation() {' ;;
    80) printf '%s\n' '  $response = \Evrasia\PhoneAuth::check($PRIVATE_CANARY);' ;;
    100) printf '%s\n' '  $other->check($PRIVATE_CANARY);' ;;
    110) printf '%s\n' '  // \Evrasia\PhoneAuth::check("OTHER_PRIVATE_CANARY");' ;;
    140) printf '%s\n' ' } }' ;;
    *) printf '\n' ;;
  esac
done > "$TMP/auth"

php -n -l "$PARSER" > "$TMP/err"
php -n "$PARSER" --source-only "$TMP/helper" "$TMP/auth" > "$TMP/out" 2> "$TMP/err"
grep -Fxq 'PHONE_HELPER_SOURCE_ANCHORS_VALID=YES' "$TMP/out"
grep -Fxq 'CHECK_CONDITION_LINE=146 TOKEN_SHAPE=NOT|VARIABLE_MASKED TOKEN_COUNT=2 TRUNCATED=NO' "$TMP/out"
grep -Fxq 'CHECK_CONDITION_LINE=152 TOKEN_SHAPE=VARIABLE_MASKED|GREATER|VARIABLE_MASKED TOKEN_COUNT=3 TRUNCATED=NO' "$TMP/out"
grep -Fxq 'CHECK_RETURN_LINE=149 TOKEN_SHAPE=LITERAL_FALSE TOKEN_COUNT=1 TRUNCATED=NO' "$TMP/out"
grep -Fxq 'CHECK_RETURN_LINE=179 TOKEN_SHAPE=IDENT_MASKED|STATIC_ACCESS|IDENT_MASKED|PAREN_OPEN|LITERAL_TRUE|PAREN_CLOSE TOKEN_COUNT=6 TRUNCATED=NO' "$TMP/out"
grep -Fxq 'CHECK_RETURN_LINE=185 TOKEN_SHAPE=NAME_FULLY_QUALIFIED_MASKED|STATIC_ACCESS|IDENT_MASKED|PAREN_OPEN|VARIABLE_MASKED|PAREN_CLOSE TOKEN_COUNT=6 TRUNCATED=NO' "$TMP/out"
grep -Fxq 'ACTIVE_CONTROLLER_PHONEAUTH_STATIC_CALL_LINE=80 METHOD=check' "$TMP/out"
grep -Fxq 'ACTIVE_CONTROLLER_OBJECT_CHECK_CALL_LINE=100 RECEIVER=MASKED' "$TMP/out"
grep -Fxq 'CONTROLLER_DIRECT_PHONEAUTH_CHECK_CALLS=1' "$TMP/out"
grep -Fxq 'CONTROLLER_OBJECT_CHECK_CALLS_RECEIVER_UNRESOLVED=1' "$TMP/out"
grep -Fxq 'FINAL_STATUS=PASS' "$TMP/out"
if grep -Ei 'PRIVATE_CANARY|SENTINEL|privateId|someResult|CheckFailure|Company|Response::' "$TMP/out" || [[ -s "$TMP/err" ]]; then
  echo 'FAIL: source value/unknown identifier leaked or parser emitted stderr'; exit 1
fi
echo 'PASS: two conditions and five full return token shapes classified without source values'
echo 'PASS: active controller call counted while comment decoy excluded'

sed '155s#^#// #' "$TMP/helper" > "$TMP/bad"
if php -n "$PARSER" --source-only "$TMP/bad" "$TMP/auth" > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: commented return accepted'; exit 1
fi
grep -Fxq 'FAIL: check return anchor changed' "$TMP/err"
echo 'PASS: commented return fails closed'

sed '152s#^#// #' "$TMP/helper" > "$TMP/bad"
if php -n "$PARSER" --source-only "$TMP/bad" "$TMP/auth" > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: commented condition accepted'; exit 1
fi
grep -Fxq 'FAIL: check conditional anchor changed' "$TMP/err"
echo 'PASS: commented condition fails closed'
echo 'MODE=SYNTHETIC_ONLY'
echo 'PRODUCTION_SOURCE_READ=NO'
echo 'FINAL_STATUS=PASS'
echo 'FINAL_RC=0'
