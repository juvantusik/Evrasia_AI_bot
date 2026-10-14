#!/usr/bin/env bash
# Synthetic sources only. Does not read real V4 PHP or run application code.
set -Eeuo pipefail
umask 077
[[ "${1:-}" == "--synthetic-only" ]] || { echo "FAIL: synthetic-only required"; exit 1; }
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
SCANNER="$HERE/V5PhoneCheckActiveCallerDiscovery.php"
TMP="$(mktemp -d /tmp/v5-phone-caller-tests.XXXXXXXX)"
trap 'rm -f -- "$GOOD" "$HELPER" "$TMP/out" "$TMP/err" "$TMP/lint" "$TMP/local/php_interface/lib/TokenSettings.php"; rmdir -- "$TMP/local/php_interface/Controllers/V4" "$TMP/local/php_interface/Controllers" "$TMP/local/php_interface/lib" "$TMP/local/php_interface" "$TMP/local" "$TMP"' EXIT
mkdir -p "$TMP/local/php_interface/Controllers/V4" "$TMP/local/php_interface/lib"
GOOD="$TMP/local/php_interface/Controllers/V4/PhoneFlow.php"
HELPER="$TMP/local/php_interface/lib/SmsPhone.php"
cat > "$GOOD" <<'PHP'
<?php
use Evrasia\PhoneAuth as SMS;
class PhoneFlow {
  public function confirmation() {
    $a = SMS::check("PRIVATE_CANARY_NUM");
    \Evrasia\PhoneAuth::sendCode("PRIVATE_CANARY_CODE");
    $p = new \Evrasia\PhoneAuth();
    $other->check("PRIVATE_CANARY_OTHER");
    // \Evrasia\PhoneAuth::check("PRIVATE_CANARY_COMMENT");
    /*
      PhoneAuth::check("PRIVATE_CANARY_MULTILINE");
    */
  }
}
PHP
cat > "$HELPER" <<'PHP'
<?php
/* PhoneAuth::check("PRIVATE_CANARY_INACTIVE"); */
class Other {
    public function foo() { return true; }
}
PHP

php -n -l "$SCANNER" > "$TMP/lint"
php -n "$SCANNER" --source-only --approved-scope "$GOOD" "$HELPER" > "$TMP/out" 2> "$TMP/err"
grep -Fxq 'CANDIDATE_SOURCE=Controllers/V4/PhoneFlow.php' "$TMP/out"
grep -Eq '^ACTIVE_PHONEAUTH_STATIC_CALL LINE=[0-9]+ METHOD=check CLASS=PHONEAUTH_OR_ALIAS$' "$TMP/out"
grep -Eq '^ACTIVE_PHONEAUTH_STATIC_CALL LINE=[0-9]+ METHOD=sendcode CLASS=PHONEAUTH_OR_ALIAS$' "$TMP/out"
grep -Eq '^ACTIVE_PHONEAUTH_NEW LINE=[0-9]+$' "$TMP/out"
grep -Fxq 'ACTIVE_PHONEAUTH_STATIC_CALLS=2' "$TMP/out"
grep -Fxq 'ACTIVE_PHONEAUTH_INSTANTIATIONS=1' "$TMP/out"
grep -Fxq 'PHONEAUTH_IMPORT_HINTS=1' "$TMP/out"
grep -Fxq 'OBJECT_CHECK_CALLS_UNRESOLVED=1' "$TMP/out"
grep -Fxq 'CANDIDATE_SOURCE_FILES=1' "$TMP/out"
grep -Fxq 'SCANNED_APPROVED_PHP_FILES=2' "$TMP/out"
grep -Fxq 'FINAL_STATUS=PASS' "$TMP/out"
if grep -Eq 'PRIVATE_CANARY|PRIVATE_CANARY_NUM|PRIVATE_CANARY_CODE' "$TMP/out" || [[ -s "$TMP/err" ]]; then
  echo "FAIL: unexpected private source value or parser stderr"; exit 1
fi
echo "PASS: real tokenizer finds static and aliased PhoneAuth calls but ignores both comment kinds"

cat > "$GOOD" <<'PHP'
<?php
/*
use Evrasia\PhoneAuth as SMS;
$foo = SMS::check("PRIVATE_CANARY_COMMENT");
$bar = \Evrasia\PhoneAuth::sendCode("PRIVATE_CANARY_COMMENT");
*/
PHP
php -n "$SCANNER" --source-only --approved-scope "$GOOD" "$HELPER" > "$TMP/out" 2> "$TMP/err"
grep -Fxq 'ACTIVE_PHONEAUTH_STATIC_CALLS=0' "$TMP/out"
grep -Fxq 'PHONEAUTH_IMPORT_HINTS=0' "$TMP/out"
grep -Fxq 'CANDIDATE_SOURCE_FILES=0' "$TMP/out"
echo "PASS: comment-only source does not count as active PhoneAuth route"

cat > "$TMP/local/php_interface/lib/TokenSettings.php" <<'PHP'
<?php
PhoneAuth::check("PRIVATE_CANARY");
PHP
if php -n "$SCANNER" --source-only --approved-scope \
    "$TMP/local/php_interface/lib/TokenSettings.php" > "$TMP/out" 2> "$TMP/err"; then
  echo "FAIL: settings/secret-style source accepted"; exit 1
fi
grep -Fxq 'FAIL: source scope or sensitive basename guard' "$TMP/err"
echo "PASS: potential configuration or secret-bearing PHP filenames are denied"

echo "MODE=SYNTHETIC_ONLY"
echo "PRODUCTION_SOURCE_READ=NO"
echo "FINAL_STATUS=PASS"
echo "FINAL_RC=0"
