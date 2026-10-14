#!/usr/bin/env bash
# Positive + negative PHP tokenizer tests on synthetic fixtures ONLY.
set -Eeuo pipefail
umask 077
[[ "${1:-}" == '--synthetic-only' ]] || { echo 'FAIL: --synthetic-only required'; exit 1; }
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PARSER="$HERE/V5ActivePhoneSignupBoundary.php"
TMP="$(mktemp -d /tmp/ev-v5-signup-fixture.XXXXXXXX)"
trap 'rm -f -- "$TMP/auth" "$TMP/helper" "$TMP/commented" "$TMP/out" "$TMP/err"; rmdir -- "$TMP"' EXIT

for ((line=1;line<=450;line++)); do
  case "$line" in
    1) printf '%s\n' '<?php' ;;
    60) printf '%s\n' 'function confirmation() {' ;;
    137) printf '%s\n' '  $obuser->Update(1, []);' ;;
    146) printf '%s\n' '}' ;;
    263) printf '%s\n' 'function signup() {' ;;
    372) printf '%s\n' '  UserPhoneAuthTable::add(["USER_ID"=>1,"PHONE_NUMBER"=>$PRIVATE_CANARY_PHONE]);' ;;
    373) printf '%s\n' '}' ;;
    *) printf '\n' ;;
  esac
done > "$TMP/auth"

for ((line=1;line<=230;line++)); do
  case "$line" in
    1) printf '%s\n' '<?php' ;;
    65) printf '%s\n' 'function sendCode() {' ;;
    100) printf '%s\n' '  $helper->sendSms();' ;;
    134) printf '%s\n' '}' ;;
    135) printf '%s\n' 'function check() {' ;;
    160) printf '%s\n' '  $helper->createTokens();' ;;
    187) printf '%s\n' '}' ;;
    188) printf '%s\n' 'function createUser() {' ;;
    210) printf '%s\n' '  $user->Add([]);' ;;
    219) printf '%s\n' '}' ;;
    *) printf '\n' ;;
  esac
done > "$TMP/helper"

php -n -l "$PARSER" > "$TMP/err"
php -n "$PARSER" --source-only "$TMP/auth" "$TMP/helper" > "$TMP/out" 2> "$TMP/err"
grep -Fxq 'ACTIVE_ANCHOR_METHOD=signup LINE=263' "$TMP/out"
grep -Fxq 'ACTIVE_ANCHOR_METHOD=confirmation LINE=60' "$TMP/out"
grep -Fxq 'ACTIVE_ANCHOR_METHOD=sendcode LINE=65' "$TMP/out"
grep -Fxq 'ACTIVE_ANCHOR_METHOD=check LINE=135' "$TMP/out"
grep -Fxq 'ACTIVE_ANCHOR_METHOD=createuser LINE=188' "$TMP/out"
grep -Fxq 'ACTIVE_NATIVE_PHONE_ADD_LINE=372' "$TMP/out"
grep -Fxq 'PHONE_ADD_FIRST_ARGUMENT_KIND=SHORT_ARRAY_LITERAL' "$TMP/out"
grep -Fxq 'PHONE_ADD_INLINE_FIELD_KEY=USER_ID' "$TMP/out"
grep -Fxq 'PHONE_ADD_INLINE_FIELD_KEY=PHONE_NUMBER' "$TMP/out"
grep -Fxq 'PHONE_ADD_ANCHOR_COUNT=1' "$TMP/out"
grep -Fxq 'FINAL_STATUS=PASS' "$TMP/out"
if grep -Eq 'PRIVATE_CANARY_PHONE|123456789|API_KEY_SECRET' "$TMP/out" || [[ -s "$TMP/err" ]]; then
  echo 'FAIL: private identifier disclosure or unexpected PHP stderr'; exit 1
fi
echo 'PASS: active signup phone-add fields and helper calls found without source disclosure'

sed '372s/^/\/\/ /' "$TMP/auth" > "$TMP/commented"
if php -n "$PARSER" --source-only "$TMP/commented" "$TMP/helper" > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: commented phone-add accepted as active'; exit 1
fi
echo 'PASS: commented phone-add rejected'

sed '372s/UserPhoneAuthTable/UserTable/' "$TMP/auth" > "$TMP/commented"
if php -n "$PARSER" --source-only "$TMP/commented" "$TMP/helper" > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: mutated native table accepted'; exit 1
fi
echo 'PASS: changed native table fails closed'

echo 'MODE=SYNTHETIC_ONLY'
echo 'PRODUCTION_SOURCE_READ=NO'
echo 'FINAL_STATUS=PASS'
echo 'FINAL_RC=0'
