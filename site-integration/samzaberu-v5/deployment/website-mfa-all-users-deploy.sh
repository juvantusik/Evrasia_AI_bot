#!/usr/bin/env bash
# Evrasia WEBSITE MFA all-user release, built ONLY from the accepted pilot.
# --self-test: offline mode, no production access or writes
# --preflight: live SHA/manifest/transform validation; no writes
# --apply: verified backups, five file staging and guarded activation
# --status <private-release-dir>: verify installed SHA; no writes
# --rollback <private-release-dir>: locally restore exact pre-install pilot
set -Eeuo pipefail
umask 077

MODE="${1:-}"
RELEASE_ARG="${2:-}"
SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
PACKAGE='/root/ev-web-mfa-release-packages/all-user-mfa-prepared-20261023-235710-4b06db78'
PARENT='/root/ev-web-mfa-release-packages'
LOCK="$PARENT/.all-users-website-mfa.lock"
BUDGET_REL='local/php_interface/website-mfa/GlobalSmsBudget.php'
BUDGET_TARGET="$SITE/$BUDGET_REL"
BUDGET_SOURCE_SHA='ef0ed60aafceea3d82dd65f239c01dfe9f708bddfdb68fb5a64f8078b3d38b2f'

NAMES=(enrollment signin account pin)
REL=(
 'local/components/eurasia/totp.enrollment/class.php'
 'local/components/eurasia/signin/class.php'
 'local/templates/eurasia/components/eurasia/account/main/template.php'
 'local/php_interface/pincode.php'
)
BEFORE=(
 '27de8a0d74b1613672740b2899afcc7a333b2f8e40557f5095fd98ad9ee6608e'
 'b63094e5d45ad74f28f9594b605944526adbfad9fd0dd507ccabf11f843ed174'
 '022991446ef70634fc6175e2e4b96cc9d318ac2dc51ff2112ecb73ff1e73a823'
 'dee875d300403f11ad13fdfafc760fabd52daf39e03f31ee89f847d4dd427510'
)
AFTER=(
 '763a0160f8e21d31bfe7a03cc9472bc94410835532299d57265ad55f06eb6ddc'
 'e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a'
 'DYNAMIC_WARNING_PATCH'
 '0b12ed47cd74bfa03c91bdcab2c4bfd07c7ccac7b67852e9540ed9a71390c94c'
)
PASS_COUNT=0
RELEASE=''
LIVE_TOUCHED=NO
VERIFIED=NO
ROLLBACK_OUTCOME=NOT_REQUIRED
STAGES=()
SITE_FILE_STAGING=NO
API_WRITE=NO
DATABASE_WRITE=NO
REDIS_WRITE=NO
SMS_SENT=NO
APP_SOURCE_WRITE=NO

pass() {
  PASS_COUNT=$((PASS_COUNT + 1))
  printf 'PASS: %s\n' "$1"
}
fail() {
  printf 'FAIL: %s\n' "$1"
  exit 1
}
sha() {
  local result
  result="$(sha256sum "$1")" || return 1
  printf '%s' "${result%% *}"
}
file_guard() {
  local f="$1"
  [[ -f "$f" && ! -L "$f" && -r "$f" ]] || fail 'expected safe regular file'
  [[ "$(realpath -e "$f")" == "$f" ]] || fail 'file realpath mismatch'
}
match_sha() {
  local target="$1" expected="$2" label="$3"
  file_guard "$target"
  [[ "$(sha "$target")" == "$expected" ]] || fail "$label SHA mismatch"
  pass "$label pinned SHA"
}
lint() {
  php -d short_open_tag=1 -d auto_prepend_file= -d auto_append_file= -l "$1" >/dev/null ||
    fail "PHP source lint refused"
}
metadata_guard() {
  local str="$1"
  [[ "$str" =~ ^[0-9]+:[0-9]+:0?[0-7]{3}$ ]] || fail 'file metadata invalid'
}
release_guard() {
  local d="$1"
  [[ "$d" =~ ^/root/ev-web-mfa-release-packages/all-users-website-mfa-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$ ]] ||
    fail 'release directory name mismatch'
  [[ -d "$d" && ! -L "$d" && "$(realpath -e "$d")" == "$d" ]] ||
    fail 'release directory missing or unsafe'
  [[ "$(stat -c '%u:%a' "$d")" == '0:700' ]] || fail 'release directory not root-only'
  [[ -f "$d/manifest.txt" && ! -L "$d/manifest.txt" ]] ||
    fail 'release manifest unavailable'
  grep -Fxq "PRIVATE_SOURCE=$PACKAGE" "$d/manifest.txt" ||
    fail 'release manifest source mismatch'
  grep -Fxq 'INSTALLER_SCOPE=WEBSITE_ALL_USERS_ONLY' "$d/manifest.txt" ||
    fail 'release manifest scope mismatch'
}
valid_parent() {
  [[ -d "$PARENT" && ! -L "$PARENT" &&
    "$(realpath -e "$PARENT")" == "$PARENT" &&
    "$(stat -c '%u:%a' "$PARENT")" == '0:700' ]] ||
    fail 'release parent permissions or realpath'
}
host_guard() {
  [[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] ||
    fail 'exact production host and root required'
  [[ -d "$SITE" && ! -L "$SITE" &&
    "$(realpath -e "$SITE")" == "$SITE" ]] || fail 'exact Bitrix root guard'
  valid_parent
  for tool in php sha256sum realpath stat cp mv mktemp grep chmod chown flock; do
    command -v "$tool" >/dev/null 2>&1 || fail "missing executable $tool"
  done
  pass 'exact host/root, release location and required tools'
}

# PHP code is never evaluated from Bitrix. Only source text is transformed.
# The previously verified private candidates are immutable.
derive_account() {
  php -d short_open_tag=1 -d auto_prepend_file= -d auto_append_file= -r '
$source=file_get_contents($argv[1]);
if (!is_string($source) || strlen($source)<200) exit(2);
$anchor="&& (int)\$GLOBALS[\x27USER\x27]->GetID() === (int)";
if (substr_count($source,$anchor)!==1
  || str_contains($source,"website-mfa-v4-warning")) exit(3);
$at=strpos($source,$anchor);
$close=strpos($source,") :", $at);
if ($close===false || $close-$at>220) exit(4);
$phpClose=strpos($source,"?>",$close);
if ($phpClose===false || $phpClose-$close>15) exit(5);
$pos=$phpClose+2;
$notice="\n<p class=\"website-mfa-v4-warning\" role=\"note\">"
."Важно: после добровольного подключения двухфакторной авторизации "
."повторный вход в мобильное приложение «СамЗаберу» текущей версии "
."может временно не работать. Уже открытые сеансы специально не завершаются."
."</p>\n";
$patched=substr_replace($source,$notice,$pos,0);
$reverse=substr_replace($patched,"",$pos,strlen($notice));
if (!hash_equals(hash("sha256",$source),hash("sha256",$reverse))) exit(6);
try { token_get_all($patched,TOKEN_PARSE); }
catch (ParseError) { exit(7); }
if ($argv[3]==="hash") { echo hash("sha256",$patched); exit(0); }
if ($argv[3]!=="write" || !isset($argv[2])
  || !is_file($argv[2]) || is_link($argv[2])
  || filesize($argv[2])!==0) exit(8);
if (file_put_contents($argv[2],$patched,LOCK_EX)!==strlen($patched)) exit(9);
echo hash("sha256",$patched);
' "$PACKAGE/account.candidate.php" "$2" "$1"
}

# No client-IP attribution has been verified. The third Redis cap is made
# REDUNDANT with the verified global per-hour ceiling, NOT removed.
# This avoids one shared reverse proxy being limited to only 20 SMS/hour.
# The per-user/phone/cooldown guards and global 100/hour, 500/day remain.
derive_budget() {
  php -d auto_prepend_file= -d auto_append_file= -r '
$source=file_get_contents($argv[1]);
if(!is_string($source)||strlen($source)<300)exit(2);
$old="public const IP_PER_HOUR = 20;";
$new="public const IP_PER_HOUR = 100;";
if(substr_count($source,$old)!==1
  || !str_contains($source,"public const GLOBAL_PER_HOUR = 100;")
  || !str_contains($source,"public const GLOBAL_PER_DAY = 500;"))exit(3);
$patched=str_replace($old,$new,$source);
if(!hash_equals(hash("sha256",$source),
     hash("sha256",str_replace($new,$old,$patched))))exit(4);
try{token_get_all($patched,TOKEN_PARSE);}catch(ParseError){exit(5);}
if($argv[3]==="hash"){echo hash("sha256",$patched);exit(0);}
if($argv[3]!=="write"||!is_file($argv[2])
  ||is_link($argv[2])||filesize($argv[2])!==0)exit(6);
if(file_put_contents($argv[2],$patched,LOCK_EX)!==strlen($patched))exit(7);
echo hash("sha256",$patched);
' "$PACKAGE/budget.candidate.php" "$2" "$1"
}

private_source_guard() {
  [[ -d "$PACKAGE" && ! -L "$PACKAGE" &&
    "$(realpath -e "$PACKAGE")" == "$PACKAGE" &&
    "$(stat -c '%u:%a' "$PACKAGE")" == '0:700' ]] ||
      fail 'private all-users candidate directory guard'
  local i
  for i in 0 1 2 3; do
    local name="${NAMES[$i]}"
    local src="$PACKAGE/$name.candidate.php"
    local before="$PACKAGE/$name.rollback.php"
    file_guard "$src"
    file_guard "$before"
    [[ "$(stat -c '%u:%a' "$src")" == '0:600' &&
       "$(stat -c '%u:%a' "$before")" == '0:600' ]] ||
         fail "$name private source permissions"
    [[ "$(sha "$before")" == "${BEFORE[$i]}" ]] ||
      fail "$name private rollback SHA drift"
    if (( i != 2 )); then
      [[ "$(sha "$src")" == "${AFTER[$i]}" ]] ||
        fail "$name candidate SHA drift"
    else
      [[ "$(sha "$src")" == 'afaa8d03db683a965a83d68eaf3fe5c03fdf1afb450749f2698eb540a5ccfec8' ]] ||
        fail 'account private source SHA drift'
    fi
    lint "$src"
    pass "$name original and private candidate"
  done
  local q="$PACKAGE/budget.candidate.php"
  file_guard "$q"
  [[ "$(stat -c '%u:%a' "$q")" == '0:600' &&
      "$(sha "$q")" == "$BUDGET_SOURCE_SHA" ]] ||
      fail 'private global SMS budget source guard'
  lint "$q"
  pass 'original private Redis SMS budget source and PHP lint'
}

native_sources_guard() {
  match_sha "$SITE/bitrix/modules/main/classes/general/user.php" \
    bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99 native_user
  match_sha "$SITE/bitrix/modules/security/lib/Mfa/Otp.php" \
    285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c native_otp
  match_sha "$SITE/local/php_interface/lib/Services/TrustedDeviceWebService.php" \
    28a28d7e92cd54512a45a477069735af182e5ce9b2142343fa881eb236a92b45 trusted_web
  match_sha "$SITE/local/php_interface/lib/Services/AuthAssuranceService.php" \
    e2d4b8f8351977f694738098b5655794b897f8cd1dcbdaeb580660db05dda1f9 assurance
  match_sha "$SITE/local/php_interface/Controllers/V4/Auth.php" \
    72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362 v4_unchanged
}
candidate_security_guard() {
  php -d auto_prepend_file= -d auto_append_file= -r '
$p=$argv[1];
$s=file_get_contents($p."/signin.candidate.php");
$e=file_get_contents($p."/enrollment.candidate.php");
$i=file_get_contents($p."/pin.candidate.php");
if(!is_string($s)||!is_string($e)||!is_string($i))exit(1);
$controls=[
 substr_count($s,"GlobalSmsBudget::reserve")===1,
 substr_count($e,"GlobalSmsBudget::reserve")===1,
 str_contains($s,"CUser::LoginInternal"),
 str_contains($s,"PASSWORD_ORIGINAL"),
 !str_contains($s,"self::PILOT_USER_ID"),
 str_contains($s,"LoginByOtp("),
 str_contains($s,"hasValidPilotTrust"),
 !str_contains($e,"self::PILOT_USER_ID"),
 str_contains($i,"hasValidCurrentTrust(\$userId)"),
 str_contains($i,"check_bitrix_sessid"),
 !str_contains($i,"880339"),
];
foreach($controls as $ok){if(!$ok)exit(3);}
' "$PACKAGE" || fail 'critical auth/SMS/PIN source invariants changed'
  pass 'critical login, native OTP, trusted PIN and both SMS budget callers'
}
preflight() {
  host_guard
  private_source_guard
  native_sources_guard
  candidate_security_guard
  local i
  for i in 0 1 2 3; do
    local target="$SITE/${REL[$i]}"
    match_sha "$target" "${BEFORE[$i]}" "${NAMES[$i]}_live_pilot"
    lint "$target"
    metadata_guard "$(stat -c '%u:%g:%a' "$target")"
  done
  [[ ! -e "$BUDGET_TARGET" && ! -L "$BUDGET_TARGET" ]] ||
    fail 'shared website SMS budget already installed unexpectedly'
  if [[ -e "$(dirname "$BUDGET_TARGET")" ]]; then
    [[ -d "$(dirname "$BUDGET_TARGET")" &&
       ! -L "$(dirname "$BUDGET_TARGET")" ]] ||
      fail 'SMS budget directory path unsafe'
  fi
  AFTER[2]="$(derive_account hash -)" ||
    fail 'cannot create exact account TOTP V4 warning from candidate'
  [[ "${AFTER[2]}" =~ ^[a-f0-9]{64}$ ]] ||
    fail 'invalid derived account PHP SHA'
  BUDGET_AFTER="$(derive_budget hash -)" ||
    fail 'cannot derive safe shared-proxy SMS quota candidate'
  [[ "$BUDGET_AFTER" =~ ^[a-f0-9]{64}$ ]] ||
    fail 'invalid derived budget SHA'
  pass 'exact website warning and proxy-safe quota derived in memory'
  echo 'WEB_TOTP_V4_WARNING=CANDIDATE_PREPARED'
  echo 'SMS_CAP_GLOBAL_HOUR=100'
  echo 'SMS_CAP_GLOBAL_DAY=500'
  echo 'SMS_CAP_EFFECTIVE_PER_REMOTE_ADDR_HOUR=100'
  echo 'REMOTE_ADDR_EXTERNAL_PROOF=NOT_REQUIRED_FOR_REDUNDANT_CAP'
  echo 'PAYABLE_SMS_CAPS=CONSERVATIVE_PROVISIONAL'
  echo 'MOBILE_V4_AUTH_WRITE=NO'
}

release_sources_guard() {
  local d="$1" i a q
  release_guard "$d"
  a="$(grep -E '^ACCOUNT_AFTER_SHA=[a-f0-9]{64}$' "$d/manifest.txt")" ||
    fail 'private release account candidate SHA missing'
  q="$(grep -E '^BUDGET_AFTER_SHA=[a-f0-9]{64}$' "$d/manifest.txt")" ||
    fail 'private release helper candidate SHA missing'
  [[ "$(grep -Ec '^ACCOUNT_AFTER_SHA=' "$d/manifest.txt")" == 1 &&
      "$(grep -Ec '^BUDGET_AFTER_SHA=' "$d/manifest.txt")" == 1 ]] ||
    fail 'private release candidate SHA manifest not unique'
  AFTER[2]="${a#ACCOUNT_AFTER_SHA=}"
  BUDGET_AFTER="${q#BUDGET_AFTER_SHA=}"
  for i in 0 1 2 3; do
    file_guard "$d/${NAMES[$i]}.rollback.php"
    [[ "$(stat -c '%u:%a' "$d/${NAMES[$i]}.rollback.php")" == '0:600' &&
       "$(sha "$d/${NAMES[$i]}.rollback.php")" == "${BEFORE[$i]}" ]] ||
      fail 'release backup altered'
    file_guard "$d/${NAMES[$i]}.candidate.php"
    [[ "$(stat -c '%u:%a' "$d/${NAMES[$i]}.candidate.php")" == '0:600' &&
       "$(sha "$d/${NAMES[$i]}.candidate.php")" == "${AFTER[$i]}" ]] ||
      fail 'release candidate SHA altered'
    file_guard "$d/${NAMES[$i]}.metadata"
    metadata_guard "$(cat "$d/${NAMES[$i]}.metadata")"
  done
  file_guard "$d/budget.candidate.php"
  [[ "$(stat -c '%u:%a' "$d/budget.candidate.php")" == '0:600' &&
     "$(sha "$d/budget.candidate.php")" == "$BUDGET_AFTER" ]] ||
    fail 'release SMS quota candidate SHA altered'
  grep -Fxq 'GLOBAL_SMS_HOUR=100' "$d/manifest.txt" ||
    fail 'release global hourly SMS limit altered'
  grep -Fxq 'GLOBAL_SMS_DAY=500' "$d/manifest.txt" ||
    fail 'release global daily SMS limit altered'
}

# Fail closed when any site's current bytes are neither old pilot nor this
# release candidate. Never overwrite a third-party concurrent change.
rollback_core() {
  local d="$1" i target current tempfile metadata owner group mode
  release_sources_guard "$d"
  for i in 0 1 2 3; do
    target="$SITE/${REL[$i]}"
    file_guard "$target"
    current="$(sha "$target")"
    [[ "$current" == "${BEFORE[$i]}" || "$current" == "${AFTER[$i]}" ]] ||
      fail 'rollback declined: unexpected third-party website PHP content'
  done
  if [[ -e "$BUDGET_TARGET" || -L "$BUDGET_TARGET" ]]; then
    file_guard "$BUDGET_TARGET"
    [[ "$(sha "$BUDGET_TARGET")" == "$BUDGET_AFTER" ]] ||
      fail 'rollback declined: unexpected SMS helper content'
  fi
  # Restore consumers first, and remove helper last.
  for i in 1 0 3 2; do
    target="$SITE/${REL[$i]}"
    current="$(sha "$target")"
    if [[ "$current" == "${BEFORE[$i]}" ]]; then continue; fi
    metadata="$(cat "$d/${NAMES[$i]}.metadata")"
    IFS=: read -r owner group mode <<<"$metadata"
    tempfile="$(mktemp "$(dirname "$target")/.ev-mfa-stage.XXXXXXXX")"
    STAGES+=("$tempfile")
    cp -- "$d/${NAMES[$i]}.rollback.php" "$tempfile"
    chown "$owner:$group" "$tempfile"
    chmod "$mode" "$tempfile"
    lint "$tempfile"
    [[ "$(sha "$tempfile")" == "${BEFORE[$i]}" ]] ||
      fail 'rollback staging checksum'
    mv -fT -- "$tempfile" "$target"
    [[ "$(sha "$target")" == "${BEFORE[$i]}" ]] ||
      fail 'rollback site source checksum'
  done
  if [[ -f "$BUDGET_TARGET" && ! -L "$BUDGET_TARGET" ]]; then
    [[ "$(sha "$BUDGET_TARGET")" == "$BUDGET_AFTER" ]] ||
      fail 'quota helper changed since original rollout'
    rm -f -- "$BUDGET_TARGET"
  fi
  for i in 0 1 2 3; do
    [[ "$(sha "$SITE/${REL[$i]}")" == "${BEFORE[$i]}" ]] ||
      fail 'rollback final website SHA'
  done
  [[ ! -e "$BUDGET_TARGET" && ! -L "$BUDGET_TARGET" ]] ||
    fail 'rollback helper removal failed'
  printf '%s\n' 'ROLLED_BACK_TO_ACCEPTED_PILOT' > "$d/state"
  chmod 600 "$d/state"
  ROLLBACK_OUTCOME=PASS
  pass 'pilot website PHP restored; SMS helper removed; V4/V5 unchanged'
}
finish() {
  local rc=$?
  trap - EXIT
  if [[ "$MODE" == '--apply' && "$LIVE_TOUCHED" == YES &&
        "$VERIFIED" != YES ]]; then
    echo '=== EMERGENCY AUTO ROLLBACK AFTER FAILED INSTALL ==='
    if ( rollback_core "$RELEASE" ); then
      ROLLBACK_OUTCOME=PASS
      echo 'AUTOMATIC_ROLLBACK=PASS'
    else
      ROLLBACK_OUTCOME=FAIL_MANUAL_RECOVERY_REQUIRED
      echo 'AUTOMATIC_ROLLBACK=FAIL_MANUAL_RECOVERY_REQUIRED'
      rc=1
    fi
  fi
  local temp
  for temp in "${STAGES[@]}"; do
    if [[ "$temp" == "$SITE"/*"/.ev-mfa-stage."* &&
          -f "$temp" && ! -L "$temp" ]]; then
      rm -f -- "$temp" || rc=1
    fi
  done
  echo
  echo '=== 5. RESULT ==='
  echo "MODE=$MODE"
  echo "PASS_COUNT=$PASS_COUNT"
  echo "RELEASE_DIRECTORY=${RELEASE:-NONE}"
  echo "LIVE_SITE_FILES_TOUCHED=$LIVE_TOUCHED"
  echo "INSTALL_VERIFIED=$VERIFIED"
  echo "ROLLBACK_OUTCOME=$ROLLBACK_OUTCOME"
  echo 'PRODUCTION_SITE_TEMP_STAGING=ONLY_IN_APPLY_OR_ROLLBACK'
  echo 'DATABASE_CONNECTION=NO'
  echo "DATABASE_WRITE=$DATABASE_WRITE"
  echo "REDIS_WRITE=$REDIS_WRITE"
  echo "SMS_SENT=$SMS_SENT"
  echo 'TRUSTED_DEVICE_WRITE=NO'
  echo "API_WRITE=$API_WRITE"
  echo 'API_V4_WRITE=NO'
  echo 'API_V5_AUTH_WRITE=NO'
  echo 'PIN_SMS_SECRETS_PRINTED=NO'
  if (( rc == 0 )); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
  echo "FINAL_RC=$rc"
  echo 'TERMINAL_WILL_STAY_OPEN=YES'
  exit "$rc"
}
trap finish EXIT

echo '=== 1. HOST / ENVIRONMENT ==='
case "$MODE" in
  --self-test)
    [[ "$#" -eq 1 ]] || fail 'self-test argument count'
    [[ "${#NAMES[@]}" -eq 4 && "${#REL[@]}" -eq 4 &&
      "${#BEFORE[@]}" -eq 4 && "${#AFTER[@]}" -eq 4 ]] ||
        fail 'static target array mismatch'
    [[ "$BUDGET_REL" == 'local/php_interface/website-mfa/GlobalSmsBudget.php' ]] ||
        fail 'unexpected shared budget target'
    pass 'offline exact-target, safety mode and rollback tables'
    echo 'SELF_TEST_ONLY=YES'
    VERIFIED=SELF_TEST_ONLY
    exit 0
    ;;
  --preflight)
    [[ "$#" -eq 1 ]] || fail 'preflight argument count'
    echo
    echo '=== 2. CHECKS ==='
    preflight
    echo '=== 3. ACTION ==='
    echo 'READ_ONLY_PREFLIGHT=COMPLETE'
    echo '=== 4. VERIFY ==='
    echo 'ALL_USER_INSTALL_ALLOWED_AFTER_THIS_CHECK=REQUIRES_APPLY'
    VERIFIED=READ_ONLY_PASS
    exit 0
    ;;
  --apply)
    [[ "$#" -eq 1 ]] || fail 'apply argument count'
    host_guard
    [[ ! -L "$LOCK" ]] || fail 'unsafe lock path'
    exec 9>"$LOCK"
    flock -x 9 || fail 'cannot acquire website release lock'
    echo
    echo '=== 2. CHECKS ==='
    preflight
    echo
    echo '=== 3. ACTION ==='
    RELEASE="$PARENT/all-users-website-mfa-$(date -u +%Y%m%d-%H%M%S)-$(php -r 'echo bin2hex(random_bytes(4));')"
    [[ "$RELEASE" =~ ^/root/ev-web-mfa-release-packages/all-users-website-mfa-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$ ]] ||
      fail 'release name guard'
    mkdir -m 700 -- "$RELEASE" || fail 'private release directory create'
    [[ "$(stat -c '%u:%a' "$RELEASE")" == '0:700' ]] ||
      fail 'private release permissions'
    cp -- "$0" "$RELEASE/restore.sh"
    chmod 600 "$RELEASE/restore.sh"
    bash -n "$RELEASE/restore.sh" || fail 'offline rollback syntax'
    {
      echo 'INSTALLER_SCOPE=WEBSITE_ALL_USERS_ONLY'
      echo "PRIVATE_SOURCE=$PACKAGE"
      echo 'GLOBAL_SMS_HOUR=100'
      echo 'GLOBAL_SMS_DAY=500'
      echo 'PER_IP_HOUR=REDUNDANT_100'
      echo 'USER_V4_TOTP_WARNING=ENABLED_IN_ACCOUNT_VIEW'
      echo "ACCOUNT_AFTER_SHA=${AFTER[2]}"
      echo "BUDGET_AFTER_SHA=$BUDGET_AFTER"
      echo 'PUBLIC_DOMAIN=evrasia.rest'
    } > "$RELEASE/manifest.txt"
    chmod 600 "$RELEASE/manifest.txt"
    printf '%s\n' 'PREPARED_NOT_INSTALLED' > "$RELEASE/state"
    chmod 600 "$RELEASE/state"
    for i in 0 1 2 3; do
      target="$SITE/${REL[$i]}"
      cp -- "$target" "$RELEASE/${NAMES[$i]}.rollback.php"
      chmod 600 "$RELEASE/${NAMES[$i]}.rollback.php"
      [[ "$(sha "$RELEASE/${NAMES[$i]}.rollback.php")" == "${BEFORE[$i]}" ]] ||
        fail 'backup verification failed'
      stat -c '%u:%g:%a' "$target" > "$RELEASE/${NAMES[$i]}.metadata"
      chmod 600 "$RELEASE/${NAMES[$i]}.metadata"
      metadata_guard "$(cat "$RELEASE/${NAMES[$i]}.metadata")"
      if (( i == 2 )); then
        : > "$RELEASE/account.candidate.php"
        [[ "$(derive_account write "$RELEASE/account.candidate.php")" == "${AFTER[2]}" ]] ||
          fail 'account warning private candidate source mismatch'
      else
        cp -- "$PACKAGE/${NAMES[$i]}.candidate.php" \
          "$RELEASE/${NAMES[$i]}.candidate.php"
      fi
      chmod 600 "$RELEASE/${NAMES[$i]}.candidate.php"
      [[ "$(sha "$RELEASE/${NAMES[$i]}.candidate.php")" == "${AFTER[$i]}" ]] ||
        fail 'release candidate copied SHA mismatch'
      lint "$RELEASE/${NAMES[$i]}.candidate.php"
    done
    : > "$RELEASE/budget.candidate.php"
    [[ "$(derive_budget write "$RELEASE/budget.candidate.php")" == "$BUDGET_AFTER" ]] ||
      fail 'SMS quota private candidate source mismatch'
    chmod 600 "$RELEASE/budget.candidate.php"
    lint "$RELEASE/budget.candidate.php"
    release_sources_guard "$RELEASE"
    pass 'fresh root-private backups, rollback tool and five release candidates'
    [[ ! -e "$BUDGET_TARGET" && ! -L "$BUDGET_TARGET" ]] ||
      fail 'helper unexpectedly installed during backup'
    helper_dir="$(dirname "$BUDGET_TARGET")"
    if [[ ! -e "$helper_dir" ]]; then
      mkdir -m 755 -- "$helper_dir"
      [[ -d "$helper_dir" && ! -L "$helper_dir" ]] ||
        fail 'new SMS helper directory unsafe'
    fi
    [[ -d "$helper_dir" && ! -L "$helper_dir" &&
        "$(realpath -e "$helper_dir")" == "$helper_dir" ]] ||
      fail 'SMS helper directory path changed'
    # Stage all source files before any activation. Maintain live UID/GID/mode.
    for i in 0 1 2 3; do
      target="$SITE/${REL[$i]}"
      stage="$(mktemp "$(dirname "$target")/.ev-mfa-stage.XXXXXXXX")"
      STAGES+=("$stage")
      cp -- "$RELEASE/${NAMES[$i]}.candidate.php" "$stage"
      metadata="$(cat "$RELEASE/${NAMES[$i]}.metadata")"
      IFS=: read -r owner group mode <<<"$metadata"
      chown "$owner:$group" "$stage"
      chmod "$mode" "$stage"
      lint "$stage"
      [[ "$(sha "$stage")" == "${AFTER[$i]}" ]] ||
        fail 'staged candidate altered'
    done
    budget_stage="$(mktemp "$helper_dir/.ev-mfa-stage.XXXXXXXX")"
    STAGES+=("$budget_stage")
    cp -- "$RELEASE/budget.candidate.php" "$budget_stage"
    meta="$(cat "$RELEASE/pin.metadata")"
    IFS=: read -r owner group mode <<<"$meta"
    chown "$owner:$group" "$budget_stage"
    chmod 644 "$budget_stage"
    lint "$budget_stage"
    [[ "$(sha "$budget_stage")" == "$BUDGET_AFTER" ]] ||
      fail 'staged SMS helper altered'
    printf '%s\n' 'INSTALLING' > "$RELEASE/state"
    LIVE_TOUCHED=YES
    APP_SOURCE_WRITE=YES
    mv -fT -- "$budget_stage" "$BUDGET_TARGET"
    [[ "$(sha "$BUDGET_TARGET")" == "$BUDGET_AFTER" ]] ||
      fail 'SMS helper install verification'
    # Activate account and PIN, enrollment and finally signin.
    for i in 2 3 0 1; do
      target="$SITE/${REL[$i]}"
      [[ "$(sha "$target")" == "${BEFORE[$i]}" ]] ||
        fail 'concurrent site mutation detected before atomic rename'
      mv -fT -- "${STAGES[$i]}" "$target"
      [[ "$(sha "$target")" == "${AFTER[$i]}" ]] ||
        fail 'site candidate post-rename mismatch'
    done
    echo
    echo '=== 4. VERIFY ==='
    for i in 0 1 2 3; do
      target="$SITE/${REL[$i]}"
      [[ "$(sha "$target")" == "${AFTER[$i]}" ]] ||
        fail 'installed site SHA mismatch'
      lint "$target"
    done
    [[ "$(sha "$BUDGET_TARGET")" == "$BUDGET_AFTER" ]] ||
      fail 'installed budget helper SHA mismatch'
    lint "$BUDGET_TARGET"
    printf '%s\n' 'INSTALLED_ALL_USERS' > "$RELEASE/state"
    VERIFIED=YES
    pass 'all users website sources + shared SMS helper installed and linted'
    echo 'ALL_USERS_WEBSITE_MFA=INSTALLED_SOURCE_ONLY'
    echo 'BROWSER_E2E=REQUIRED'
    echo 'ROLLBACK_NOT_TESTED_ON_LIVE=YES'
    echo "ROLLBACK_COMMAND=bash $RELEASE/restore.sh --rollback $RELEASE"
    ;;
  --status|--rollback)
    [[ "$#" -eq 2 ]] || fail 'status/rollback needs exact release directory'
    host_guard
    [[ ! -L "$LOCK" ]] || fail 'unsafe release lock'
    exec 9>"$LOCK"
    flock -x 9 || fail 'cannot acquire website release lock'
    RELEASE="$RELEASE_ARG"
    echo
    echo '=== 2. CHECKS ==='
    release_sources_guard "$RELEASE"
    echo
    echo '=== 3. ACTION ==='
    if [[ "$MODE" == '--rollback' ]]; then
      rollback_core "$RELEASE"
      LIVE_TOUCHED=YES
    else
      file_guard "$BUDGET_TARGET"
      [[ "$(sha "$BUDGET_TARGET")" == "$BUDGET_AFTER" ]] ||
        fail 'SMS budget installed SHA mismatch'
      for i in 0 1 2 3; do
        match_sha "$SITE/${REL[$i]}" "${AFTER[$i]}" "${NAMES[$i]}_installed"
      done
      pass 'all-users installed source state'
    fi
    echo
    echo '=== 4. VERIFY ==='
    echo "RELEASE_STATE=$(cat "$RELEASE/state")"
    echo "ROLLBACK_COMMAND=bash $RELEASE/restore.sh --rollback $RELEASE"
    VERIFIED=YES
    ;;
  *)
    fail 'supported modes: --self-test, --preflight, --apply, --status DIR, --rollback DIR'
    ;;
esac