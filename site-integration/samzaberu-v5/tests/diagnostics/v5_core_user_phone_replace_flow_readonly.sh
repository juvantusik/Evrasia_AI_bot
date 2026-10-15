#!/usr/bin/env bash
# DRIFT SNAPSHOT ONLY. Narrow CUser::Update delete/add args; no site PHP execution or baseline approval.
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
echo 'CORE_SOURCE_STATUS=SHA_DRIFT_OBSERVED_NOT_APPROVED'
echo 'CORE_SOURCE_PREVIOUS_REFERENCE_SHA256=542034ca4eee2c30bdc033f45d792945c10392aeaf2e482c01958e2bd585b9d0'
echo 'CORE_SOURCE_OBSERVED_SNAPSHOT_SHA256=bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99'
echo 'CORE_SOURCE_OBSERVED_MTIME_UTC=2026-10-12T11:09:05Z'
echo 'CORE_SOURCE_PROVENANCE_VERIFIED=NO'
echo 'CORE_SOURCE_CHANGE_REASON=NOT_DETERMINED'
echo 'PRODUCTION_BASELINE_REPLACED=NO'
echo '=== 2. EXACT OBSERVED CORE SOURCE SHA ==='
while IFS='|' read -r rel sha; do
  file="$ROOT/$rel"
  [[ -f "$file" && -r "$file" && ! -L "$file" && "$(realpath -- "$file")" == "$file" ]] || fail "canonical source guard: $rel"
  bytes="$(wc -c < "$file")"
  ((bytes>=20 && bytes<=4000000)) || fail "source size cap: $rel"
  actual="$(sha256sum -- "$file")"; actual="${actual%% *}"
  [[ "$actual" == "$sha" ]] || fail "source checksum drift: $rel"
  echo "SOURCE=$rel"; echo "SOURCE_SHA256=$actual"
  pass "pinned source: $rel"
done <<'PINS'
bitrix/modules/main/classes/general/user.php|bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99
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
PARSER="$HERE/V5CoreUserPhoneReplaceFlow.php"
[[ -f "$PARSER" && -r "$PARSER" && ! -L "$PARSER" && "$(realpath -- "$PARSER")" == "$PARSER" && "$(stat -c '%u' "$PARSER")" == 0 ]] || fail 'trusted PHP parser'
php "${PHP_ARGS[@]}" -l "$PARSER" >/dev/null || fail 'PHP syntax'
pass 'trusted PHP tokenizer and parser syntax'
echo '=== 4. PINNED CUSER PHONE CALL BRANCH / DELETE ARGUMENT LEXICAL FLOW ==='
REPORT="$(mktemp /tmp/v5-phone-flow-report.XXXXXXXX)"
PART="$(mktemp /tmp/v5-phone-flow-part.XXXXXXXX)"
PHP_SCANNER_EXECUTED=YES
php "${PHP_ARGS[@]}" "$PARSER" --source-only \
  --core-user "$ROOT/bitrix/modules/main/classes/general/user.php" \
  --expected-sha256 'bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99' \
  > "$PART" || fail 'bounded CUser Update phone arg lexer refused or failed'
cat "$PART" >> "$REPORT"
cat "$REPORT"
pass 'CUser phone mutation branch and delete argument lexical hints; baseline not approved'
