#!/usr/bin/env bash
# Real PHP lexer tests using only synthetically generated sources. No Bitrix.
set -Eeuo pipefail
umask 077
[[ "${1:-}" == '--synthetic-only' ]] || { echo 'FAIL: --synthetic-only required'; exit 1; }
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
SCANNER="$HERE/V5ActiveV4PhoneLexicalMap.php"
TMP="$(mktemp -d /tmp/v5-active-phone.XXXXXXXX)"
trap 'rm -f -- "$TMP/auth" "$TMP/helper" "$TMP/comment-only" "$TMP/out" "$TMP/error"; rmdir -- "$TMP"' EXIT

cat > "$TMP/auth" <<'PHP'
<?php
/*
 public function confirm_request() {
     $unused = UserPhoneAuthTable::add(["CONFIRMED" => "Y"]);
     $canary = "SECRET_CANARY_MUST_NOT_PRINT";
 }
*/
class Auth {
    public function confirm() {
        $rows = \Bitrix\Main\UserPhoneAuthTable::getList([]);
        $phoneuser["CONFIRMED"] = "N";
        $obuser->Update($id, []);
    }
}
PHP
cat > "$TMP/helper" <<'PHP'
<?php
class PhoneAuth {
    public function sendCode() {
        /* UserPhoneAuthTable::delete(["CONFIRMED" => "Y"]); */
        return true;
    }
    public function check() { return true; }
}
PHP
php -n "$SCANNER" --source-only "$TMP/auth" "$TMP/helper" > "$TMP/out" 2> "$TMP/error"
grep -Fxq '=== SOURCE=AUTH_CONTROLLER ===' "$TMP/out"
grep -Eq '^ACTIVE_METHOD_LINE=[0-9]+ NAME=confirm$' "$TMP/out"
grep -Eq '^COMMENTED_METHOD_TEXT_LINE=[0-9]+ NAME=confirm_request$' "$TMP/out"
grep -Eq '^ACTIVE_NATIVE_PHONE_STATIC_CALL_LINE=[0-9]+ NAME=getlist$' "$TMP/out"
grep -Eq '^ACTIVE_CONFIRMED_FIELD_REFERENCE_LINE=[0-9]+$' "$TMP/out"
grep -Eq '^ACTIVE_INSTANCE_MUTATOR_LINE=[0-9]+ NAME=update$' "$TMP/out"
grep -Fxq 'ACTIVE_NATIVE_PHONE_STATIC_CALLS=1' "$TMP/out"
grep -Fxq 'ACTIVE_METHOD_DECLARATIONS=2' "$TMP/out"
grep -Fxq 'COMMENTED_METHOD_TEXT_MATCHES=1' "$TMP/out"
grep -Fxq 'ACTIVE_CONFIRMED_FIELD_REFERENCES=1' "$TMP/out"
grep -Fxq 'FINAL_STATUS=PASS' "$TMP/out"
if grep -Eq 'SECRET_CANARY|MUST_NOT_PRINT|unused' "$TMP/out" || [[ -s "$TMP/error" ]]; then
    echo 'FAIL: secret source content leaked or stderr produced'; exit 1
fi
echo 'PASS: real PHP lexer distinguishes commented legacy method from active namespaced native call'

cat > "$TMP/comment-only" <<'PHP'
<?php
/*
function signup() {
    UserPhoneAuthTable::add(["CONFIRMED" => "Y"]);
}
function confirm() {
    UserPhoneAuthTable::update(1, ["CONFIRMED" => "Y"]);
}
*/
PHP
php -n "$SCANNER" --source-only "$TMP/comment-only" "$TMP/helper" > "$TMP/out" 2> "$TMP/error"
grep -Fxq 'ACTIVE_METHOD_DECLARATIONS=0' "$TMP/out"
grep -Fxq 'ACTIVE_NATIVE_PHONE_STATIC_CALLS=0' "$TMP/out"
grep -Fxq 'ACTIVE_CONFIRMED_FIELD_REFERENCES=0' "$TMP/out"
grep -Fxq 'COMMENTED_METHOD_TEXT_MATCHES=2' "$TMP/out"
echo 'PASS: comment-only phone writers contribute zero active call sites'

if php -n "$SCANNER" --source-only "$TMP/auth" > "$TMP/out" 2> "$TMP/error"; then
    echo 'FAIL: missing second source accepted'; exit 1
fi
echo 'PASS: two explicit source paths required'
echo 'MODE=SYNTHETIC_ONLY'
echo 'PRODUCTION_SOURCE_READ=NO'
echo 'FINAL_STATUS=PASS'
echo 'FINAL_RC=0'
