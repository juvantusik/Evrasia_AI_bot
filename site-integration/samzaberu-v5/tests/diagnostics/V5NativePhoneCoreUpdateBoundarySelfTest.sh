#!/usr/bin/env bash
# No production sources: controlled synthetic PHP fragments only.
set -Eeuo pipefail
umask 077
[[ $# -eq 1 && "$1" == '--synthetic-only' ]] || { echo 'FAIL: synthetic-only required'; exit 2; }
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PARSER="$HERE/V5NativePhoneCoreUpdateBoundary.php"
LAUNCHER="$HERE/v5_native_phone_core_update_boundary_readonly.sh"
TMP="$(mktemp -d /tmp/v5-native-core-test.XXXXXXXX)"
NATIVE="$TMP/bitrix/modules/main/lib/UserPhoneAuthTable.php"
CORE="$TMP/bitrix/modules/main/classes/general/user.php"
cleanup() {
  rm -f -- "$NATIVE" "$CORE" "$TMP/out" "$TMP/err"
  rmdir -- "$TMP/bitrix/modules/main/lib" "$TMP/bitrix/modules/main/classes/general" \
    "$TMP/bitrix/modules/main/classes" "$TMP/bitrix/modules/main" \
    "$TMP/bitrix/modules" "$TMP/bitrix" "$TMP"
}
trap cleanup EXIT
mkdir -p -- "$TMP/bitrix/modules/main/lib" "$TMP/bitrix/modules/main/classes/general"
php -n -l "$PARSER" >/dev/null
bash -n "$LAUNCHER"
bash -n "${BASH_SOURCE[0]}"
PASS_COUNT=0

write_native() {
  local mode="$1"
  case "$mode" in
    N)
      cat > "$NATIVE" <<'PHP'
<?php
namespace Bitrix\Main;
class UserPhoneAuthTable {
  public static function getMap() {
    return [ new BooleanField('CONFIRMED', ['values'=>['N','Y'], 'default_value'=>'N']) ];
  }
  public static function onBeforeAdd($x) { return $x; }
  // 'CONFIRMED' => 'Y'   CANARY_COMMENT_WOULD_BE_FALSE_POSITIVE
}
PHP
      ;;
    Y)
      cat > "$NATIVE" <<'PHP'
<?php
namespace Bitrix\Main;
class UserPhoneAuthTable {
  public static function getMap() {
    return [ new BooleanField('CONFIRMED', ['default_value'=>'Y']) ];
  }
}
PHP
      ;;
    NONE)
      cat > "$NATIVE" <<'PHP'
<?php
namespace Bitrix\Main;
class UserPhoneAuthTable {
  public static function getMap() {
    return [ new BooleanField('CONFIRMED', ['values'=>['N','Y']]) ];
  }
}
PHP
      ;;
    COMMENT)
      cat > "$NATIVE" <<'PHP'
<?php
namespace Bitrix\Main;
class UserPhoneAuthTable {
  public static function getMap() {
    // new BooleanField('CONFIRMED', ['default_value'=>'Y']);
    return [];
  }
}
PHP
      ;;
  esac
}
write_core() {
  local mode="$1"
  case "$mode" in
    STATIC)
      cat > "$CORE" <<'PHP'
<?php
class CAllUser {
    public function Update($CANARY_SECRET) {
        \Bitrix\Main\UserPhoneAuthTable::update(['CONFIRMED'=>'Y']);
        $CANARY_SECRET = "CANARY_PRIVATE_VALUE";
        // UserPhoneAuthTable::delete(['CONFIRMED'=>'Y']);
        "Literal SQL UPDATE b_user_phone_auth SET CONFIRMED = 'Y'";
    }
}
PHP
      ;;
    NO_CALLS)
      cat > "$CORE" <<'PHP'
<?php
class CUser {
    public function Update($CANARY_SECRET) {
        // UserPhoneAuthTable::add(['CONFIRMED'=>'Y']);
        $CANARY_SECRET = "CANARY_PRIVATE_VALUE";
        $sql = 'UPDATE b_user_phone_auth SET CONFIRMED = "Y"';
    }
}
PHP
      ;;
    STRING)
      cat > "$CORE" <<'PHP'
<?php
class CAllUser {
    public function Update($CANARY_SECRET) {
        "$CANARY_PRIVATE->foo({$CANARY_PRIVATE->check()})";
        $sql = "UserPhoneAuthTable::update(['CONFIRMED' => 'Y'])";
    }
}
PHP
      ;;
  esac
}
scan() {
  local h1 h2
  h1="$(sha256sum -- "$NATIVE")"; h1="${h1%% *}"
  h2="$(sha256sum -- "$CORE")"; h2="${h2%% *}"
  php -n "$PARSER" --source-only --native "$NATIVE" --native-sha256 "$h1" \
    --core-user "$CORE" --core-user-sha256 "$h2" > "$TMP/out" 2> "$TMP/err"
}
masked() {
  if grep -Eq 'CANARY|PRIVATE|SECRET|b_user_phone_auth SET|Bitrix\\\\Main\\\\User' "$TMP/out" "$TMP/err"; then
    echo 'FAIL: synthetic canary leak'
    exit 1
  fi
}
success() {
  scan
  [[ ! -s "$TMP/err" ]]
  grep -Fxq 'FINAL_STATUS=PASS' "$TMP/out"
  grep -Fxq 'BOTH_ANALYZED_SOURCE_SHA_MATCHED=YES' "$TMP/out"
  grep -Fxq 'NATIVE_VERIFIED_PHONE_WRITE_PROVEN=NO' "$TMP/out"
  grep -Fxq 'SOURCE_STATEMENTS_AND_LITERALS_PRINTED=NO' "$TMP/out"
  masked
  PASS_COUNT=$((PASS_COUNT + 1))
}
refused() {
  local why="$1" rc
  if scan; then echo 'FAIL: malformed or commented source accepted'; exit 1; else rc=$?; fi
  [[ "$rc" == 2 && ! -s "$TMP/out" ]]
  grep -Fxq "FAIL: $why" "$TMP/err"
  masked
  PASS_COUNT=$((PASS_COUNT + 1))
}

write_native N
write_core STATIC
success
grep -Fxq 'NATIVE_CONFIRMED_DEFAULT_LEXICAL_KIND=LITERAL_N' "$TMP/out"
grep -Fxq 'CORE_UPDATE_1_OWNER_HINT=CALLUSER' "$TMP/out"
grep -Fxq 'CORE_UPDATE_DIRECT_PHONE_TABLE_MUTATORS=1' "$TMP/out"
grep -Fxq 'DIRECT_MUTATOR_1_METHOD=UPDATE' "$TMP/out"
grep -Fxq 'CORE_UPDATE_LITERAL_CONFIRMED_REFERENCES=1' "$TMP/out"

write_native Y
success
grep -Fxq 'NATIVE_CONFIRMED_DEFAULT_LEXICAL_KIND=LITERAL_Y' "$TMP/out"

write_native NONE
write_core NO_CALLS
success
grep -Fxq 'NATIVE_CONFIRMED_DEFAULT_LEXICAL_KIND=NO_LITERAL_DEFAULT_IN_FIELD_CALL' "$TMP/out"
grep -Fxq 'CORE_UPDATE_1_OWNER_HINT=CUSER' "$TMP/out"
grep -Fxq 'CORE_UPDATE_DIRECT_PHONE_TABLE_MUTATORS=0' "$TMP/out"
grep -Fxq 'CORE_UPDATE_LITERAL_CONFIRMED_REFERENCES=0' "$TMP/out"

write_native N
write_core STRING
success
grep -Fxq 'CORE_UPDATE_DIRECT_PHONE_TABLE_MUTATORS=0' "$TMP/out"
grep -Fxq 'CORE_UPDATE_LITERAL_CONFIRMED_REFERENCES=0' "$TMP/out"

write_native COMMENT
refused 'native CONFIRMED field anchor guard'

write_native N
write_core STATIC
expected="$(sha256sum -- "$NATIVE")"; expected="${expected%% *}"
if php -n "$PARSER" --source-only --native "$NATIVE" --native-sha256 "$(printf '%064d' 0)" \
    --core-user "$CORE" --core-user-sha256 "$(sha256sum "$CORE" | cut -d' ' -f1)" \
    > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: altered native SHA accepted'; exit 1
fi
[[ ! -s "$TMP/out" ]]
grep -Fxq 'FAIL: exact analyzed bytes checksum guard' "$TMP/err"
masked
PASS_COUNT=$((PASS_COUNT + 1))

# A matching native file does not permit a mismatched core-user buffer.
native_sha="$(sha256sum -- "$NATIVE")"; native_sha="${native_sha%% *}"
if php -n "$PARSER" --source-only --native "$NATIVE" --native-sha256 "$native_sha" \
    --core-user "$CORE" --core-user-sha256 "$(printf '%064d' 0)" \
    > "$TMP/out" 2> "$TMP/err"; then
  echo 'FAIL: altered core-user SHA accepted'; exit 1
fi
[[ ! -s "$TMP/out" ]]
grep -Fxq 'FAIL: exact analyzed bytes checksum guard' "$TMP/err"
masked
PASS_COUNT=$((PASS_COUNT + 1))

# Immutable newly observed snapshot is a READ_ONLY analysis gate, not an approved release baseline.
OBSERVED_LAUNCHER="$HERE/v5_native_phone_core_observed_snapshot_readonly.sh"
bash -n "$OBSERVED_LAUNCHER"
OBSERVED='bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99'
HISTORICAL='542034ca4eee2c30bdc033f45d792945c10392aeaf2e482c01958e2bd585b9d0'
[[ "$(grep -Fc "$OBSERVED" "$OBSERVED_LAUNCHER")" == 3 ]]
[[ "$(grep -Fc "$HISTORICAL" "$OBSERVED_LAUNCHER")" == 1 ]]
grep -Fq "CORE_SOURCE_STATUS=SHA_DRIFT_OBSERVED_NOT_APPROVED" "$OBSERVED_LAUNCHER"
grep -Fq "CORE_SOURCE_PROVENANCE_VERIFIED=NO" "$OBSERVED_LAUNCHER"
grep -Fq "PRODUCTION_BASELINE_REPLACED=NO" "$OBSERVED_LAUNCHER"
grep -Fq -- "--core-user-sha256 '$OBSERVED'" "$OBSERVED_LAUNCHER"
PASS_COUNT=$((PASS_COUNT + 1))

if [[ "$(hostname -s)" != evrasia ]]; then
  if bash "$LAUNCHER" > "$TMP/out" 2> "$TMP/err"; then
    echo 'FAIL: wrong host accepted'
    exit 1
  fi
  grep -Fxq 'PHP_SCANNER_EXECUTED=NO' "$TMP/out"
  grep -Fxq 'FINAL_STATUS=FAIL' "$TMP/out"
  PASS_COUNT=$((PASS_COUNT + 1))
  if bash "$OBSERVED_LAUNCHER" > "$TMP/out" 2> "$TMP/err"; then
    echo 'FAIL: observed snapshot accepted a nonproduction CI host'; exit 1
  fi
  grep -Fxq 'PHP_SCANNER_EXECUTED=NO' "$TMP/out"
  grep -Fxq 'FINAL_STATUS=FAIL' "$TMP/out"
  PASS_COUNT=$((PASS_COUNT + 1))
fi

echo 'MODE=SYNTHETIC_ONLY'
echo 'PRODUCTION_SOURCE_READ=NO'
echo 'SITE_PHP_EXECUTED=NO'
printf 'PASS_COUNT=%s\nFAIL_COUNT=0\nFINAL_STATUS=PASS\nFINAL_RC=0\n' "$PASS_COUNT"
