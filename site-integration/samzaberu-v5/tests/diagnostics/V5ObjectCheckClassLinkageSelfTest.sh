#!/usr/bin/env bash
# Synthetic sources only. Never read site PHP or execute a production diagnostic.
set -Eeuo pipefail
umask 077
[[ $# == 1 && "$1" == '--synthetic-only' ]] || { echo 'FAIL: synthetic-only required'; exit 2; }
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
SCANNER="$HERE/V5ObjectCheckClassLinkage.php"
LAUNCHER="$HERE/v5_object_check_class_linkage_readonly.sh"
TMP="$(mktemp -d /tmp/v5-class-linkage-synthetic.XXXXXXXX)"
PIN="$TMP/local/php_interface/Controllers/V4/PinCode.php"
PHONE="$TMP/local/php_interface/lib/PhoneAuth.php"
cleanup(){
  rm -f -- "$PIN" "$PHONE" "$TMP/out" "$TMP/err"
  rmdir -- "$TMP/local/php_interface/Controllers/V4" \
    "$TMP/local/php_interface/Controllers" "$TMP/local/php_interface/lib" \
    "$TMP/local/php_interface" "$TMP/local" "$TMP"
}
trap cleanup EXIT
mkdir -p -- "$TMP/local/php_interface/Controllers/V4" "$TMP/local/php_interface/lib"
php -n -l "$SCANNER" >/dev/null
bash -n "$LAUNCHER"
bash -n "${BASH_SOURCE[0]}"
PASS_COUNT=0

make_pin(){
  local fqcn="$1"
  for n in $(seq 1 84); do
    case "$n" in
      1) echo '<?php';;
      2) echo 'namespace CANARY\SomeOtherNamespace;';;
      3) echo 'class CANARY_PinCode {';;
      14) echo 'function canaryPin() {';;
      43) echo 'if (true) {';;
      44) printf '  $CANARY_RECEIVER = new \\%s("CANARY_SECRET");\n' "$fqcn";;
      46) echo '  $CANARY_RECEIVER->check("CANARY_TOKEN");';;
      47) echo '}';;
      83) echo '}';;
      84) echo '}';;
      *) echo '// CANARY_FIXTURE';;
    esac
  done > "$PIN"
}
make_phone(){
  local namespace="$1" mode="${2:-normal}"
  for n in $(seq 1 150); do
    case "$n" in
      1) echo '<?php';;
      2) printf 'namespace %s;\n' "$namespace";;
      3) echo 'class PhoneAuth {';;
      27) echo 'public function initiate() {';;
      46) if [[ "$mode" == string ]]; then
            echo '  "CANARY interpolated {$this->check()}";'
          else
            echo '  $this->check("CANARY_CODE");'
          fi;;
      63) echo '}';;
      90) if [[ "$mode" == split ]]; then echo '}'; else echo '// CANARY'; fi;;
      110) if [[ "$mode" == split ]]; then echo 'class CANARY_AnotherClass {'; else echo '// CANARY'; fi;;
      135) if [[ "$mode" == comment ]]; then
             echo '// public function check() { return true; }'
           else
             echo 'public function check() { return true; }'
           fi;;
      150) echo '}';;
      *) echo '// CANARY_FIXTURE';;
    esac
  done > "$PHONE"
}
scan(){
  local h1 h2
  h1="$(sha256sum -- "$PIN")"; h1="${h1%% *}"
  h2="$(sha256sum -- "$PHONE")"; h2="${h2%% *}"
  php -n "$SCANNER" --source-only \
    --pincode "$PIN" --pincode-sha "$h1" \
    --phoneauth "$PHONE" --phoneauth-sha "$h2" > "$TMP/out" 2> "$TMP/err"
}
assert_mask(){
  if grep -Eq 'CANARY|Vendor|Other|Secret|SomeOtherNamespace' "$TMP/out" "$TMP/err"; then
    echo 'FAIL: synthetic value/name leakage'
    exit 1
  fi
}
expect_ok(){
  scan
  [[ ! -s "$TMP/err" ]]
  grep -Fxq 'FINAL_STATUS=PASS' "$TMP/out"
  grep -Fxq 'SOURCES_EXACT_SHA_MATCHED=YES' "$TMP/out"
  grep -Fxq 'ARBITRARY_IDENTIFIERS_PRINTED=NO' "$TMP/out"
  grep -Fxq 'RUNTIME_METHOD_DISPATCH_PROVEN=NO' "$TMP/out"
  assert_mask
  PASS_COUNT=$((PASS_COUNT+1))
}
refuse_scan(){
  local reason="$1" rc
  if scan; then echo 'FAIL: invalid source accepted'; exit 1; else rc=$?; fi
  [[ "$rc" == 2 && ! -s "$TMP/out" ]]
  grep -Fxq "FAIL: $reason" "$TMP/err"
  assert_mask
  PASS_COUNT=$((PASS_COUNT+1))
}

make_pin Vendor\PhoneAuth
make_phone Vendor
expect_ok
grep -Fxq 'PIN_CONSTRUCTOR_FQCN_MATCHES_PHONE_OWNER=YES' "$TMP/out"
grep -Fxq 'PHONE_THIS_OWNER_AND_CHECK_DECLARATION_SAME_CLASS=YES' "$TMP/out"
grep -Fxq 'PHONE_OWNER_BASENAME_HINT=PHONEAUTH_BASENAME' "$TMP/out"

make_pin Vendor\DifferentClass
expect_ok
grep -Fxq 'PIN_CONSTRUCTOR_FQCN_MATCHES_PHONE_OWNER=NO' "$TMP/out"

make_pin Vendor\PhoneAuth
make_phone OtherNamespace
expect_ok
grep -Fxq 'PIN_CONSTRUCTOR_FQCN_MATCHES_PHONE_OWNER=NO' "$TMP/out"

make_phone Vendor split
expect_ok
grep -Fxq 'PIN_CONSTRUCTOR_FQCN_MATCHES_PHONE_OWNER=YES' "$TMP/out"
grep -Fxq 'PHONE_THIS_OWNER_AND_CHECK_DECLARATION_SAME_CLASS=NO' "$TMP/out"

make_phone Vendor comment
refuse_scan 'PhoneAuth two pinned method declarations'

make_phone Vendor string
refuse_scan 'PhoneAuth active call anchor'

make_phone Vendor
h1="$(sha256sum -- "$PIN")"; h1="${h1%% *}"
if php -n "$SCANNER" --source-only \
  --pincode "$PIN" --pincode-sha "$h1" \
  --phoneauth "$PHONE" --phoneauth-sha "$(printf '%064d' 0)" \
  > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: altered SHA accepted'
  exit 1
fi
[[ ! -s "$TMP/out" ]]
grep -Fxq 'FAIL: exact analyzed source SHA guard' "$TMP/err"
assert_mask
PASS_COUNT=$((PASS_COUNT+1))

if [[ "$(hostname -s)" != evrasia ]]; then
  if bash "$LAUNCHER" > "$TMP/out" 2> "$TMP/err"; then
    echo 'FAIL: wrong host accepted'
    exit 1
  fi
  grep -Fxq 'PHP_SCANNER_EXECUTED=NO' "$TMP/out"
  grep -Fxq 'FINAL_STATUS=FAIL' "$TMP/out"
  assert_mask
  PASS_COUNT=$((PASS_COUNT+1))
fi

printf 'MODE=SYNTHETIC_ONLY\nPRODUCTION_SOURCE_READ=NO\nSITE_PHP_EXECUTED=NO\n'
printf 'PASS_COUNT=%s\nFAIL_COUNT=0\nFINAL_STATUS=PASS\nFINAL_RC=0\n' "$PASS_COUNT"
