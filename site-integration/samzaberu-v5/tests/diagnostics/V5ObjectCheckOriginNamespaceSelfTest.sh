#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ $# == 1 && "$1" == --synthetic-only ]] || exit 2
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
DIR="$(mktemp -d /tmp/v5-origin-test.XXXXXXXX)"
trap 'rm -rf -- "$DIR"' EXIT
mkdir -p "$DIR/local/php_interface/Controllers/V4" "$DIR/local/php_interface/lib"
PIN="$DIR/local/php_interface/Controllers/V4/PinCode.php"
PHONE="$DIR/local/php_interface/lib/PhoneAuth.php"
PHP_ARGS=(-n)
if ! php -n -r 'exit(function_exists("token_get_all")?0:2);' 2>/dev/null; then
  ext="$(php -n -r 'echo ini_get("extension_dir");')"
  PHP_ARGS+=(-d "extension=$ext/tokenizer.so")
fi
php "${PHP_ARGS[@]}" -l "$HERE/V5ObjectCheckOriginNamespace.php" >/dev/null
bash -n "$HERE/v5_object_check_origin_namespace_readonly.sh"
PASS_COUNT=0
for n in $(seq 1 83); do
  case "$n" in
    1) echo '<?php';;
    2) echo 'namespace CANARY\Private;';;
    3) echo 'use CANARY\SecretClass as MaskedAlias;';;
    14) echo 'function CANARY_probe() {';;
    43) echo 'if (true) {';;
    44) echo '$CANARY_RECEIVER = new MaskedAlias("CANARY_SECRET");';;
    45) echo '}';;
    46) echo '$CANARY_RECEIVER->check("CANARY_SECRET");';;
    83) echo '}';;
    *) echo '// fixture';;
  esac
done > "$PIN"
for n in $(seq 1 63); do
  case "$n" in
    1) echo '<?php';;
    27) echo 'function CANARY_probe(&$CANARY_RECEIVER) {';;
    46) echo '$CANARY_RECEIVER->check("CANARY_SECRET");';;
    63) echo '}';;
    *) echo '// fixture';;
  esac
done > "$PHONE"
scan() {
  local label="$1" file="$2" hash
  hash="$(sha256sum "$file")"; hash="${hash%% *}"
  php "${PHP_ARGS[@]}" "$HERE/V5ObjectCheckOriginNamespace.php" --source-only --source-label "$label" --receiver-line 46 --expected-sha256 "$hash" "$file" > "$DIR/out" 2> "$DIR/err"
  grep -Fxq 'FINAL_STATUS=PASS' "$DIR/out"
  grep -Fxq 'ANALYZED_SOURCE_SHA_MATCHED=YES' "$DIR/out"
  ! grep -Eq 'CANARY|SecretClass|MaskedAlias' "$DIR/out" "$DIR/err"
  PASS_COUNT=$((PASS_COUNT+1))
}
scan Controllers/V4/PinCode.php "$PIN"
grep -Fxq 'CONSTRUCTOR_NAME_ORIGIN_HINT=EXPLICIT_CLASS_IMPORT_HINT' "$DIR/out"
grep -Fxq 'CONDITIONAL_DOMINANCE_PROVEN=NO' "$DIR/out"
scan lib/PhoneAuth.php "$PHONE"
grep -Fxq 'PARAMETER_RECEIVER_MATCH=YES' "$DIR/out"
grep -Fxq 'PARAMETER_BY_REFERENCE=YES' "$DIR/out"
# Altered pinned bytes: the parser must refuse without any stdout.
hash="$(sha256sum "$PHONE")"; hash="${hash%% *}"
if php "${PHP_ARGS[@]}" "$HERE/V5ObjectCheckOriginNamespace.php" --source-only --source-label lib/PhoneAuth.php --receiver-line 46 --expected-sha256 "$(printf '%064d' 0)" "$PHONE" > "$DIR/out" 2> "$DIR/err"; then exit 1; fi
[[ ! -s "$DIR/out" ]] && grep -Fxq 'FAIL: analyzed bytes checksum' "$DIR/err"
PASS_COUNT=$((PASS_COUNT+1))
# Commented-out anchor rejected.
sed -i '46c// $CANARY_RECEIVER->check("CANARY_SECRET");' "$PIN"
hash="$(sha256sum "$PIN")"; hash="${hash%% *}"
if php "${PHP_ARGS[@]}" "$HERE/V5ObjectCheckOriginNamespace.php" --source-only --source-label Controllers/V4/PinCode.php --receiver-line 46 --expected-sha256 "$hash" "$PIN" > "$DIR/out" 2> "$DIR/err"; then exit 1; fi
[[ ! -s "$DIR/out" ]] && grep -Fxq 'FAIL: exact object check anchor' "$DIR/err"
PASS_COUNT=$((PASS_COUNT+1))
if [[ "$(hostname -s)" != evrasia ]]; then
  if bash "$HERE/v5_object_check_origin_namespace_readonly.sh" > "$DIR/out" 2> "$DIR/err"; then exit 1; fi
  grep -Fxq 'PHP_SCANNER_EXECUTED=NO' "$DIR/out"
  grep -Fxq 'FINAL_STATUS=FAIL' "$DIR/out"
  PASS_COUNT=$((PASS_COUNT+1))
fi
printf 'MODE=SYNTHETIC_ONLY\nPRODUCTION_SOURCE_READ=NO\nPASS_COUNT=%s\nFAIL_COUNT=0\nFINAL_STATUS=PASS\nFINAL_RC=0\n' "$PASS_COUNT"
