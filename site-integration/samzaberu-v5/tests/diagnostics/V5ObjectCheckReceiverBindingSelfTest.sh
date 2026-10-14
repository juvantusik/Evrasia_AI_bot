#!/usr/bin/env bash
# Synthetic sources only; production sources and application runtime are absent.
set -Eeuo pipefail
umask 077
[[ $# == 1 && ${1:-} == '--synthetic-only' ]] || {
  echo 'FAIL: synthetic-only required'; exit 1;
}
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
SCANNER="$HERE/V5ObjectCheckReceiverBinding.php"
TMP="$(mktemp -d /tmp/v5-receiver-binding-tests.XXXXXXXX)"
GOOD="$TMP/local/php_interface/Controllers/V4/PinCode.php"
HELPER="$TMP/local/php_interface/lib/PhoneAuth.php"
SENSITIVE="$TMP/local/php_interface/lib/TokenSettings.php"
cleanup() {
  rm -f -- "$GOOD" "$HELPER" "$SENSITIVE" "$TMP/out" "$TMP/err" "$TMP/lint"
  rmdir -- "$TMP/local/php_interface/Controllers/V4" "$TMP/local/php_interface/Controllers" \
    "$TMP/local/php_interface/lib" "$TMP/local/php_interface" "$TMP/local" "$TMP"
}
trap cleanup EXIT
mkdir -p "$TMP/local/php_interface/Controllers/V4" "$TMP/local/php_interface/lib"
php -n -l "$SCANNER" > "$TMP/lint"
bash -n "${BASH_SOURCE[0]}"
PASS_COUNT=0
scan() {
  local path="${3:-$GOOD}" digest
  digest="${4:-$(sha256sum -- "$path" | cut -d ' ' -f 1)}"
  php -n "$SCANNER" --source-only --receiver-line "$1" --source-label \
    "${2:-Controllers/V4/PinCode.php}" --expected-sha256 "$digest" "$path" > "$TMP/out" 2> "$TMP/err"
}
masked() {
  if grep -Eq 'CANARY_|SecretClass|SuperSecret|Vendor\\' "$TMP/out" "$TMP/err"; then
    echo 'FAIL: source canary leaked'; exit 1;
  fi
}
ok() {
  scan "$@"
  [[ ! -s "$TMP/err" ]]
  grep -Fxq 'FINAL_STATUS=PASS' "$TMP/out"
  grep -Fxq 'RECEIVER_CLASS_PROVEN=NO' "$TMP/out"
  grep -Fxq 'ANALYZED_SOURCE_SHA_MATCHED=YES' "$TMP/out"
  grep -Fxq 'RECEIVER_BINDING_COVERAGE=PARTIAL' "$TMP/out"
  grep -Fxq 'STRING_INTERPOLATION_COVERAGE=EXCLUDED' "$TMP/out"
  grep -Fxq 'CLASS_NAMESPACE_RESOLVED=NO' "$TMP/out"
  grep -Fxq 'CONTROL_FLOW_AND_ALIASING_RESOLVED=NO' "$TMP/out"
  masked
  PASS_COUNT=$((PASS_COUNT + 1))
}
denied() {
  local reason=$1 rc
  shift
  if scan "$@"; then echo 'FAIL: refusal fixture accepted'; exit 1; else rc=$?; fi
  [[ $rc == 2 && ! -s "$TMP/out" ]]
  grep -Fxq "FAIL: $reason" "$TMP/err"
  masked
  PASS_COUNT=$((PASS_COUNT + 1))
}

cat > "$GOOD" <<'PHP'
<?php
function unrelated() { $CANARY_RECEIVER = new SecretClass(); }
function probe() {
    $CANARY_RECEIVER = new Vendor\JwtAuthorization('CANARY_ARGUMENT');
    $CANARY_RECEIVER->check('CANARY_PHONE');
}
PHP
ok 5
grep -Fxq "SOURCE_SHA256=$(sha256sum -- "$GOOD" | cut -d ' ' -f 1)" "$TMP/out"
grep -Fxq 'ENCLOSING_FUNCTION_DECLARATION_LINE=3' "$TMP/out"
grep -Fxq 'ENCLOSING_BODY_START_LINE=3' "$TMP/out"
grep -Fxq 'ENCLOSING_BODY_END_LINE=6' "$TMP/out"
grep -Fxq 'PRIOR_ASSIGNMENT_RECORDS=1' "$TMP/out"
grep -Fxq 'PRIOR_ASSIGNMENT_1_OPERATOR_LINE=4' "$TMP/out"
grep -Fxq 'PRIOR_ASSIGNMENT_1_CLASS_BASENAME_HINT=JWTAUTHORIZATION_BASENAME' "$TMP/out"
echo 'PASS: same-function local new basename hint; unrelated function ignored'

cat > "$GOOD" <<'PHP'
<?php
function probe() {
    $CANARY_RECEIVER = new JwtAuthorization();
    if (false) { $CANARY_RECEIVER = $CANARY_OTHER; }
    $CANARY_RECEIVER->check('CANARY_ARGUMENT');
    $CANARY_RECEIVER = new PhoneAuth();
}
PHP
ok 5
grep -Fxq 'PRIOR_ASSIGNMENT_RECORDS=2' "$TMP/out"
grep -Fxq 'PRIOR_ASSIGNMENT_1_RHS_KIND=NEW_CLASS_EXPRESSION' "$TMP/out"
grep -Fxq 'PRIOR_ASSIGNMENT_2_RHS_KIND=VARIABLE_REFERENCE' "$TMP/out"
grep -Fxq 'PRIOR_ASSIGNMENT_2_NESTED_BLOCK_DEPTH=1' "$TMP/out"
grep -Fxq 'CONDITIONAL_CONTEXT_UNRESOLVED=YES' "$TMP/out"
echo 'PASS: two earlier assignments stay lexical hints; later assignment excluded'

cat > "$GOOD" <<'PHP'
<?php
function probe() {
    $CANARY_RECEIVER = new JwtAuthorization();
    class CANARY_CLASS { public $CANARY_RECEIVER = null; }
    $CANARY_CLOSURE = function($CANARY_RECEIVER = null) { $CANARY_RECEIVER = new PhoneAuth(); };
    $CANARY_ARROW = fn() => ($CANARY_RECEIVER = new SecretClass());
    function nested($CANARY_RECEIVER = null) { $CANARY_RECEIVER = new CUser(); }
    $CANARY_RECEIVER?->check('CANARY_ARGUMENT');
}
PHP
ok 8
grep -Fxq 'PRIOR_ASSIGNMENT_RECORDS=1' "$TMP/out"
grep -Fxq 'RECEIVER_OPERATOR=NULLSAFE' "$TMP/out"
echo 'PASS: nested class properties, closures, arrow closures and function signatures/body excluded'

cat > "$GOOD" <<'PHP'
<?php
function probe() {
    $CANARY_RECEIVER = new JwtAuthorization();
    // $CANARY_RECEIVER = new PhoneAuth(); $CANARY_RECEIVER->check('CANARY_COMMENT');
    $CANARY_LITERAL = '$CANARY_RECEIVER = new PhoneAuth(); $CANARY_RECEIVER->check("CANARY_STRING");';
    $CANARY_INTERPOLATED = "CANARY_TEXT {$CANARY_RECEIVER->check('CANARY_INTERPOLATION')}";
    $CANARY_NESTED = "CANARY {$CANARY_RECEIVER->check("CANARY {$CANARY_RECEIVER->check('CANARY_INNER')}")}";
    $CANARY_HEREDOC = <<<CANARY_TEXT
{$CANARY_RECEIVER->check('CANARY_HEREDOC')}
$CANARY_RECEIVER = new PhoneAuth();
CANARY_TEXT;
    $CANARY_NOWDOC = <<<'CANARY_TEXT'
$CANARY_RECEIVER = new PhoneAuth(); $CANARY_RECEIVER->check('CANARY_NOWDOC');
CANARY_TEXT;
    /* $CANARY_RECEIVER = new PhoneAuth(); */
    $CANARY_RECEIVER->check('CANARY_ARGUMENT');
}
PHP
ok 16
grep -Fxq 'PRIOR_ASSIGNMENT_RECORDS=1' "$TMP/out"
echo 'PASS: comments/string contents excluded; executable interpolation coverage explicitly excluded'

cat > "$GOOD" <<'PHP'
<?php
function probe() {
    $CANARY_RECEIVER = new JwtAuthorization();
    $CANARY_RECEIVER = new PhoneAuth();
    $CANARY_RECEIVER = new CUser();
    $CANARY_RECEIVER = new Vendor\UserPhoneAuthTable();
    $CANARY_RECEIVER = new \PhoneAuthCodeTable();
    $CANARY_RECEIVER = new Vendor\SuperSecretClass('CANARY_SECRET');
    $CANARY_RECEIVER = (new JwtAuthorization())->factory();
    $CANARY_RECEIVER += 0;
    $CANARY_RECEIVER->check('CANARY_ARGUMENT');
}
PHP
ok 11
grep -Fxq 'PRIOR_ASSIGNMENT_RECORDS=8' "$TMP/out"
for record in \
  '1_JWTAUTHORIZATION_BASENAME' '2_PHONEAUTH_BASENAME' '3_CUSER_BASENAME' \
  '4_USERPHONEAUTHTABLE_BASENAME' '5_PHONEAUTHCODETABLE_BASENAME' '6_OTHER_CLASS_MASKED'; do
  grep -Fxq "PRIOR_ASSIGNMENT_${record%%_*}_CLASS_BASENAME_HINT=${record#*_}" "$TMP/out"
done
grep -Fxq 'PRIOR_ASSIGNMENT_7_RHS_KIND=OTHER_EXPRESSION' "$TMP/out"
grep -Fxq 'PRIOR_ASSIGNMENT_8_RHS_KIND=OTHER_EXPRESSION' "$TMP/out"
echo 'PASS: five fixed basename enums; other classes masked; chains and compound assignments conservative'

cat > "$GOOD" <<'PHP'
<?php
$CANARY_RECEIVER = new JwtAuthorization();
function nested($CANARY_RECEIVER = null) { $CANARY_RECEIVER = new PhoneAuth(); }
class CANARY_CLASS { public $CANARY_RECEIVER = null; }
$CANARY_RECEIVER
    ?->
    check('CANARY_ARGUMENT');
PHP
ok 6
grep -Fxq 'ENCLOSING_SCOPE=TOP_LEVEL' "$TMP/out"
grep -Fxq 'RECEIVER_OPERATOR_LINE=6' "$TMP/out"
grep -Fxq 'RECEIVER_METHOD_TOKEN_LINE=7' "$TMP/out"
grep -Fxq 'PRIOR_ASSIGNMENT_RECORDS=1' "$TMP/out"
cp -- "$GOOD" "$HELPER"
ok 6 lib/PhoneAuth.php "$HELPER"
grep -Fxq 'SOURCE=lib/PhoneAuth.php' "$TMP/out"
echo 'PASS: top-level scope and distinct operator/method lines; second allowlisted label'
denied 'exact receiver anchor guard' 7
denied 'analyzed source checksum guard' 6 Controllers/V4/PinCode.php "$GOOD" \
  '0000000000000000000000000000000000000000000000000000000000000000'
denied 'source label allowlist guard' 6 lib/TokenSettings.php "$HELPER"
cp -- "$GOOD" "$SENSITIVE"
denied 'canonical source path guard' 6 lib/PhoneAuth.php "$SENSITIVE"
rm -f -- "$HELPER"
ln -s -- "$GOOD" "$HELPER"
denied 'canonical source path guard' 6 lib/PhoneAuth.php "$HELPER"
rm -f -- "$HELPER"
printf '<?php\n' > "$HELPER"
denied 'source byte cap guard' 6 lib/PhoneAuth.php "$HELPER"
head -c 1000001 /dev/zero > "$HELPER"
denied 'source byte cap guard' 6 lib/PhoneAuth.php "$HELPER"
echo 'PASS: wrong line/checksum, sensitive label/path, symlink and byte caps refused before output'

cat > "$GOOD" <<'PHP'
<?php
$CANARY_RECEIVER->check('CANARY_ONE'); $CANARY_RECEIVER->check('CANARY_TWO');
PHP
denied 'exact receiver anchor guard' 2
cat > "$GOOD" <<'PHP'
<?php
$CANARY_RECEIVER->CANARY_PROPERTY->check('CANARY_ARGUMENT');
PHP
denied 'simple variable receiver guard' 2
cat > "$GOOD" <<'PHP'
<?php
$CANARY_RECEIVER = fn() => $CANARY_OTHER->check('CANARY_ARGUMENT');
PHP
denied 'arrow function receiver scope guard' 2
echo 'PASS: ambiguous same-line anchor, property receiver and arrow-scope receiver refused'

printf '<?php\nfunction probe() {\n' > "$GOOD"
for ((i=0; i<20; i++)); do
  printf '%s\n' '    $CANARY_RECEIVER = new JwtAuthorization();' >> "$GOOD"
done
printf '%s\n' '    $CANARY_RECEIVER->check("CANARY_ARGUMENT");' '}' >> "$GOOD"
ok 23
grep -Fxq 'PRIOR_ASSIGNMENT_RECORDS=20' "$TMP/out"
printf '<?php\nfunction probe() {\n' > "$GOOD"
for ((i=0; i<21; i++)); do
  printf '%s\n' '    $CANARY_RECEIVER = new JwtAuthorization();' >> "$GOOD"
done
printf '%s\n' '    $CANARY_RECEIVER->check("CANARY_ARGUMENT");' '}' >> "$GOOD"
denied 'prior assignment record cap guard' 24
echo 'PASS: exactly 20 records accepted; overflow refuses the entire buffered report'
printf 'MODE=SYNTHETIC_ONLY\nPRODUCTION_SOURCE_READ=NO\nSITE_PHP_EXECUTED=NO\n'
printf 'PASS_COUNT=%s\nFAIL_COUNT=0\nFINAL_STATUS=PASS\nFINAL_RC=0\n' "$PASS_COUNT"
