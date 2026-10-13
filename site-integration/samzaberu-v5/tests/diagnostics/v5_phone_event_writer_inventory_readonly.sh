#!/usr/bin/env bash
# Bitrix/evrasia production: source-only, no config, account data or SMS calls.
# Purpose: locate EXACT native phone verification writers for V5 atomicity work.
set -Eeuo pipefail
umask 077

ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
MAIN="$ROOT/bitrix/modules/main/lib"
LOCAL="$ROOT/local/php_interface"
PASS_COUNT=0
FAIL_COUNT=0
DISCOVERED=0

pass() { PASS_COUNT=$((PASS_COUNT+1)); printf 'PASS: %s\n' "$1"; }
fail() { FAIL_COUNT=$((FAIL_COUNT+1)); printf 'FAIL: %s\n' "$1"; }
finish() {
  local rc=$?
  trap - EXIT
  printf '\n=== 5. RESULT ===\n'
  printf 'MODE=READ_ONLY\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n'
  printf 'PRODUCTION_APPLICATION_WRITE=NO\nBITRIX_BOOTSTRAP=NO\n'
  printf 'NATIVE_CODE_EXECUTED=NO\nUSER_DATA_READ=NO\n'
  printf 'CONFIG_FILES_READ=NO\nSECRETS_PRINTED=NO\nSMS_SENT=NO\n'
  printf 'NATIVE_PHONE_EVENT_ATOMICITY_PROVEN=NO\n'
  printf 'PASS_COUNT=%s\nFAIL_COUNT=%s\n' "$PASS_COUNT" "$FAIL_COUNT"
  if (( rc == 0 && FAIL_COUNT == 0 )); then
    printf 'FINAL_STATUS=PASS\nFINAL_RC=0\n'
  else
    printf 'FINAL_STATUS=FAIL\nFINAL_RC=1\n'
  fi
  printf 'TERMINAL_WILL_STAY_OPEN=YES\n'
}
trap finish EXIT

printf '=== 1. HOST / ENVIRONMENT ===\n'
if [[ "$(hostname -s)" != evrasia || "$(id -u)" -ne 0 ||
      ! -d "$MAIN" || ! -d "$LOCAL" ]]; then
  fail 'production host/root/source guard'
  exit 1
fi
for cmd in awk sha256sum find grep realpath wc; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    fail "required read-only utility missing: $cmd"
    exit 1
  fi
done
pass 'host/root/source guards satisfied'

printf '\n=== 2. PINNED ENTRYPOINT SOURCE INTEGRITY ===\n'
while IFS='|' read -r rel expected; do
  [[ -n "$rel" ]] || continue
  file="$ROOT/$rel"
  if [[ ! -f "$file" || ! -r "$file" || -L "$file" ||
        "$(realpath -- "$file")" != "$file" ]]; then
    fail "pinned source unavailable: $rel"
    exit 1
  fi
  actual="$(sha256sum -- "$file" | awk '{print $1}')"
  if [[ "$actual" != "$expected" ]]; then
    fail "pinned source changed since accepted baseline: $rel"
    exit 1
  fi
  pass "pinned PHP source: $rel"
done <<'PINS'
local/php_interface/lib/PhoneAuth.php|a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27
local/php_interface/Controllers/V4/Auth.php|72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362
PINS

# IMPORTANT: This emits ONLY method names and marker tags, never source
# statements, argument literals, phone values, SMS URLs or credentials.
scan_metadata() {
  local rel="$1" file="$2" bytes
  [[ -f "$file" && -r "$file" && ! -L "$file" ]] || return 1
  [[ "$(realpath -- "$file")" == "$file" ]] || return 1
  bytes="$(wc -c < "$file")"
  (( bytes >= 20 && bytes <= 1200000 )) || return 1
  printf 'SOURCE=%s\nSHA256=%s\n' "$rel" "$(sha256sum -- "$file" | awk '{print $1}')"
  awk '
    {
      raw=$0
      lo=tolower(raw)
      if (match(raw, /function[[:space:]]+[a-zA-Z_][a-zA-Z_0-9]*/)) {
        fn=substr(raw, RSTART, RLENGTH)
        sub(/^function[[:space:]]+/, "", fn)
        printf "METHOD_LINE=%d METHOD=%s\n", NR, fn
        f++
      }
      if (index(lo, "userphoneauthtable")) {
        printf "MARKER_LINE=%d TAG=USER_PHONE_AUTH_TABLE\n", NR
        m++
      }
      if (index(lo, "b_user_phone_auth")) {
        printf "MARKER_LINE=%d TAG=PHONE_AUTH_DB_NAME\n", NR
        m++
      }
      if (index(lo, "confirmed")) {
        printf "MARKER_LINE=%d TAG=CONFIRMED_STATE_REFERENCE\n", NR
        m++
      }
      if (index(lo, "onbeforeuserupdate") || index(lo, "onafteruserupdate")) {
        printf "MARKER_LINE=%d TAG=USER_UPDATE_EVENT\n", NR
        m++
      }
      if (index(lo, "onuserphone") || index(lo, "onafterphone") ||
          index(lo, "onbeforephone")) {
        printf "MARKER_LINE=%d TAG=PHONE_EVENT_REFERENCE\n", NR
        m++
      }
    }
    END {printf "SOURCE_METHODS=%d\nSOURCE_MARKERS=%d\n", f, m}
  ' "$file"
}

printf '\n=== 3. EXISTING LOCAL PHONE AUTH ENTRYPOINTS ===\n'
scan_metadata 'local/php_interface/lib/PhoneAuth.php' "$LOCAL/lib/PhoneAuth.php" || {
  fail 'local PhoneAuth source metadata'
  exit 1
}
scan_metadata 'local/php_interface/Controllers/V4/Auth.php' "$LOCAL/Controllers/V4/Auth.php" || {
  fail 'local V4 signin source metadata'
  exit 1
}
pass 'existing local phone/auth method and marker inventory'

printf '\n=== 4. NATIVE BITRIX PHONE AUTH TABLE CANDIDATES ===\n'
TABLE_CLASS_FOUND=0
while IFS= read -r -d '' file; do
  [[ -f "$file" && -r "$file" && ! -L "$file" ]] || continue
  [[ "$(realpath -- "$file")" == "$file" ]] || continue
  if grep -Eq 'class[[:space:]]+UserPhoneAuthTable([[:space:]]|$)' "$file"; then
    TABLE_CLASS_FOUND=$((TABLE_CLASS_FOUND+1))
    if ((TABLE_CLASS_FOUND > 1)); then
      fail 'ambiguous native UserPhoneAuthTable source'
      exit 1
    fi
    rel="$(realpath --relative-to="$ROOT" -- "$file")"
    scan_metadata "$rel" "$file" || {
      fail 'native UserPhoneAuthTable metadata failed'
      exit 1
    }
  fi
done < <(
  find "$MAIN" -maxdepth 3 -type f \
    \( -iname '*phone*auth*.php' -o -iname '*phone*table*.php' \) -print0
)
if (( TABLE_CLASS_FOUND != 1 )); then
  fail 'exactly one native UserPhoneAuthTable declaration required'
  exit 1
fi
pass 'native Bitrix phone-auth table source found without executing it'

printf '\nPHONE_WRITER_SCOPE=SOURCE_METADATA_ONLY\n'
printf 'PHONE_RECONFIRM_SAME_NUMBER_ATOMICITY=NOT_PROVEN\n'
printf 'PHONE_EVENT_VERSION_WRITER=NOT_INSTALLED\n'
