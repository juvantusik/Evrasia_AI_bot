#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
ROOT=/home/site_evrasia/web/evrasia.spb.ru/public_html
PASS_COUNT=0
FAIL_COUNT=0
PHP_SCANNER_EXECUTED=NO
REPORT=''
PART=''
fail(){ FAIL_COUNT=$((FAIL_COUNT+1)); echo "FAIL: $1"; exit 1; }
pass(){ PASS_COUNT=$((PASS_COUNT+1)); echo "PASS: $1"; }
finish(){
  local rc=$?
  trap - EXIT
  [[ -z "$REPORT" ]] || rm -f -- "$REPORT"
  [[ -z "$PART" ]] || rm -f -- "$PART"
  echo '=== 5. RESULT ==='
  echo 'MODE=READ_ONLY'
  echo 'DATABASE_CONNECTION=NO'
  echo 'DATABASE_WRITE=NO'
  echo 'PRODUCTION_APPLICATION_WRITE=NO'
  echo 'SITE_PHP_EXECUTED=NO'
  echo 'BITRIX_BOOTSTRAP=NO'
  echo 'SMS_SENT=NO'
  echo 'USER_DATA_READ=NO'
  echo 'CONFIG_FILES_READ=NO'
  echo "PHP_SCANNER_EXECUTED=$PHP_SCANNER_EXECUTED"
  echo "PASS_COUNT=$PASS_COUNT"
  echo "FAIL_COUNT=$FAIL_COUNT"
  if ((rc==0 && FAIL_COUNT==0)); then echo 'FINAL_STATUS=PASS'; echo 'FINAL_RC=0'; else echo 'FINAL_STATUS=FAIL'; echo 'FINAL_RC=1'; fi
  echo 'TERMINAL_WILL_STAY_OPEN=YES'
}
trap finish EXIT
echo '=== 1. HOST / ENVIRONMENT ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] || fail 'production host/root mismatch'
for b in realpath sha256sum stat wc php mktemp cat; do command -v "$b" >/dev/null || fail "utility absent: $b"; done
[[ "$(realpath -- "$ROOT")" == "$ROOT" ]] || fail 'root path guard'
pass 'root and pinned production root'
echo '=== 2. EXACT SOURCE SHA ==='
while IFS='|' read -r rel sha; do
  file="$ROOT/$rel"
  [[ -f "$file" && -r "$file" && ! -L "$file" && "$(realpath -- "$file")" == "$file" ]] || fail "canonical source guard: $rel"
  bytes="$(wc -c < "$file")"
  ((bytes>=20 && bytes<=1000000)) || fail "source size cap: $rel"
  actual="$(sha256sum -- "$file")"; actual="${actual%% *}"
  [[ "$actual" == "$sha" ]] || fail "source checksum drift: $rel"
  echo "SOURCE=$rel"; echo "SOURCE_SHA256=$actual"
  pass "pinned source: $rel"
done <<'PINS'
local/php_interface/Controllers/V4/PinCode.php|7734ddbaba0e7ec9ddfc9f0a9008c0da0c0a1523e505e52caebac2bbaf637cd0
local/php_interface/lib/PhoneAuth.php|a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27
PINS
echo '=== 3. TRUSTED PHP TOKENIZER / PARSER ==='
PHP_ARGS=(-n -d display_errors=stderr -d log_errors=0 -d zend.exception_ignore_args=1)
if php -n -r 'exit(function_exists("token_get_all")?0:2);' 2>/dev/null; then
  echo 'TOKENIZER_LOAD_MODE=BUILT_IN'
else
  ext="$(php -n -r 'echo ini_get("extension_dir");' 2>/dev/null)" || fail 'PHP extension directory'
  case "$ext" in /usr/lib/php/*) ;; *) fail 'untrusted extension path';; esac
  module="$ext/tokenizer.so"
  [[ -f "$module" && -r "$module" && ! -L "$module" && "$(realpath -- "$module")" == "$module" && "$(stat -c '%u' "$module")" == 0 ]] || fail 'system tokenizer trust'
  PHP_ARGS+=(-d "extension=$module")
  php "${PHP_ARGS[@]}" -r 'exit(function_exists("token_get_all")?0:2);' 2>/dev/null || fail 'PHP tokenizer load'
  echo 'TOKENIZER_LOAD_MODE=EXPLICIT_ROOT_OWNED_TOKENIZER_SO'
fi
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PARSER="$HERE/V5ObjectCheckClassLinkage.php"
[[ -f "$PARSER" && -r "$PARSER" && ! -L "$PARSER" && "$(realpath -- "$PARSER")" == "$PARSER" && "$(stat -c '%u' "$PARSER")" == 0 ]] || fail 'trusted PHP parser'
php "${PHP_ARGS[@]}" -l "$PARSER" >/dev/null || fail 'PHP syntax'
pass 'trusted PHP tokenizer and parser syntax'
echo '=== 4. CROSS-FILE MASKED LEXICAL CLASS LINKAGE ==='
REPORT="$(mktemp /tmp/v5-origin-report.XXXXXXXX)"
PART="$(mktemp /tmp/v5-origin-part.XXXXXXXX)"
PHP_SCANNER_EXECUTED=YES
php "${PHP_ARGS[@]}" "$PARSER" --source-only \
  --pincode "$ROOT/local/php_interface/Controllers/V4/PinCode.php" \
  --pincode-sha '7734ddbaba0e7ec9ddfc9f0a9008c0da0c0a1523e505e52caebac2bbaf637cd0' \
  --phoneauth "$ROOT/local/php_interface/lib/PhoneAuth.php" \
  --phoneauth-sha 'a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27' \
  > "$PART" || fail 'cross-file lexical class linkage refused or failed'
cat "$PART" >> "$REPORT"
cat "$REPORT"
pass 'two pinned PHP source buffers compared lexically'
