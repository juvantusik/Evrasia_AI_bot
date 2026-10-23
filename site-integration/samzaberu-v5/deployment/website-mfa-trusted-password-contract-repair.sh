#!/usr/bin/env bash
# Repair exactly TWO wrong LoginInternal parameters in existing pilot-only MFA.
# --self-test (offline) / --preflight (read-only) / --apply / --rollback
# --rollback returns to original pre-pilot signin, not the broken pilot.
set -Eeuo pipefail
umask 077
[[ $# -eq 1 ]] || { echo 'FAIL: one mode required'; exit 2; }
MODE="$1"
case "$MODE" in
 --self-test|--preflight|--apply|--rollback|--status) ;;
 *) echo 'FAIL: unsupported mode'; exit 2 ;;
esac
SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
LIVE="$SITE/local/components/eurasia/signin/class.php"
DIR="$SITE/local/components/eurasia/signin"
NATIVE="$SITE/bitrix/modules/main/classes/general/user.php"
PKG='/root/ev-web-mfa-release-packages/pilot-trust-canary-20261023-230052-4a23f5de'
CANARY="$PKG/signin.pilot-canary.php"
ORIG="$PKG/signin.rollback.php"
LOCK="$PKG/.pilot-deploy.lock"
OFFLINE="$PKG/trusted-password-contract-repair-offline.sh"
NATIVE_SHA='bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99'
CANARY_SHA='e150d387036d0bba38e48ac70973a0f91f49b596840f6c42b8911cfaff7df181'
ORIG_SHA='6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9'
FIXED_SHA=''
STAGE=''
FIXTURE=''
BACKUP=''
REPLACED=NO
VERIFIED=NO
PASS=0
OWNER=''
GROUP=''
PERMS=''
pass(){ PASS=$((PASS+1));echo "PASS: $1"; }
fail(){ echo "FAIL: $1";exit 1; }
sha(){ sha256sum "$1" | awk '{print $1}'; }
pin(){ [[ -f "$1" && ! -L "$1" && -r "$1" && "$(sha "$1")" == "$2" ]] || fail 'source SHA or path guard'; }
PATCH="$(cat <<'PHP'
$source=file_get_contents($argv[1]);$native=file_get_contents($argv[2]);
if(!is_string($source)||!is_string($native))exit(1);
if(!str_contains($native,'isset($arParams["PASSWORD_ORIGINAL"])')
  ||!str_contains($native,'Password::equals('))exit(2);
$mark='private function loginByExistingTrustedTotp(';
$a="\$applicationId = 'Y';";
$b="'APPLICATION_ID' => &\$applicationId,";
$c="\$passwordOriginal = 'Y';";
$d="'PASSWORD_ORIGINAL' => &\$passwordOriginal,";
if(substr_count($source,$mark)!==1||substr_count($source,$a)!==1
  ||substr_count($source,$b)!==1)exit(3);
$start=strpos($source,$mark);
$p=strpos($source,$a);$q=strpos($source,$b);
if($p<=$start||$q<=$p||$q>$start+3500)exit(4);
// Other original login/SMS methods may legitimately already use
// PASSWORD_ORIGINAL. Change only the two unique BAD expressions in
// the new pilot helper, not these independent existing native flows.
$helperSlice=substr($source,$start,3500);
if(str_contains($helperSlice,$c)||str_contains($helperSlice,$d))exit(4);
$new=str_replace($b,$d,str_replace($a,$c,$source));
$shift=strlen($c)-strlen($a);
if(substr($new,$p,strlen($c))!==$c
  ||substr($new,$q+$shift,strlen($d))!==$d)exit(5);
// Reverse only the two known offsets, never a global PASSWORD_ORIGINAL,
// preserving all pre-existing original login and SMS code byte-for-byte.
$reverse=substr_replace($new,$b,$q+$shift,strlen($d));
$reverse=substr_replace($reverse,$a,$p,strlen($c));
if(!hash_equals(hash('sha256',$source),hash('sha256',$reverse)))exit(5);
token_get_all($new,TOKEN_PARSE);
if($argv[3]==='sha'){echo hash('sha256',$new);exit(0);}
if($argv[3]==='write' && isset($argv[4]) && is_file($argv[4])
  &&!is_link($argv[4])&&filesize($argv[4])===0){
 if(file_put_contents($argv[4],$new,LOCK_EX)!==strlen($new))exit(6);
 echo hash('sha256',$new);exit(0);
}
exit(7);
PHP
)"
patched_sha(){ php -d auto_prepend_file= -d auto_append_file= -r "$PATCH" "$1" "$2" sha; }
restore(){
 local cur temp
 [[ -f "$LIVE" && ! -L "$LIVE" ]] || return 1
 cur="$(sha "$LIVE")"
 [[ "$cur" == "$ORIG_SHA" ]] && return 0
 [[ "$cur" == "$FIXED_SHA" || "$cur" == "$CANARY_SHA" ]] || return 1
 [[ -f "$ORIG" && ! -L "$ORIG" && "$(sha "$ORIG")" == "$ORIG_SHA" ]] || return 1
 temp="$(mktemp "$DIR/.ev-trust-rollback.XXXXXXXX")" || return 1
 cp -- "$ORIG" "$temp" || return 1
 chown "$OWNER:$GROUP" "$temp" || return 1
 chmod "$PERMS" "$temp" || return 1
 php -d auto_prepend_file= -d auto_append_file= -l "$temp" >/dev/null || return 1
 [[ "$(sha "$temp")" == "$ORIG_SHA" ]] || return 1
 mv -fT -- "$temp" "$LIVE" || return 1
 [[ "$(sha "$LIVE")" == "$ORIG_SHA" ]]
}
finish(){
 local rc=$?
 trap - EXIT
 set +e
 if [[ "$REPLACED" == YES && "$VERIFIED" != YES ]];then
   echo '=== AUTOMATIC RESTORE OF ORIGINAL LOGIN ==='
   if restore;then echo 'AUTOMATIC_ROLLBACK=PASS';else
     echo 'AUTOMATIC_ROLLBACK=FAIL_MANUAL_RECOVERY_REQUIRED';rc=1
   fi
 fi
 if [[ -n "$STAGE" && "$STAGE" == "$DIR"/.ev-trust-fixed.* &&
   -f "$STAGE" && ! -L "$STAGE" ]];then rm -f -- "$STAGE" || rc=1;fi
 if [[ -n "$FIXTURE" && "$FIXTURE" == /tmp/ev-trust-contract.* &&
   -d "$FIXTURE" && ! -L "$FIXTURE" ]];then
   rm -f -- "$FIXTURE/canary.php" "$FIXTURE/native.php" \
      "$FIXTURE/output.php" || rc=1
   rmdir -- "$FIXTURE" || rc=1
 fi
 echo
 echo '=== 5. RESULT ==='
 echo "MODE=$MODE"
 echo "PASS_COUNT=$PASS"
 echo "OPERATION_VERIFIED=$VERIFIED"
 if [[ -n "$BACKUP" ]];then echo "PRIVATE_BACKUP=$BACKUP";else echo 'PRIVATE_BACKUP=NONE';fi
 echo 'ONLY_PILOT_SIGNIN_FILE_WRITABLE=YES'
 echo 'DB_CONNECTION=NO'
 echo 'REDIS_CONNECTION=NO'
 echo 'SMS_SENT=NO'
 echo 'TRUSTED_DEVICE_WRITE=NO'
 echo 'API_V4_WRITE=NO'
 echo 'API_V5_AUTH_WRITE=NO'
 echo 'MASS_MFA_ROLLOUT=NO'
 echo 'BROWSER_E2E=NOT_PERFORMED_BY_SCRIPT'
 if ((rc==0));then echo 'FINAL_STATUS=PASS';else echo 'FINAL_STATUS=FAIL';fi
 echo "FINAL_RC=$rc"
 echo 'TERMINAL_WILL_STAY_OPEN=YES'
 exit "$rc"
}
trap finish EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
preflight(){
 echo '=== 1. ENVIRONMENT / VERIFIED BITRIX PASSWORD CONTRACT ==='
 [[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] ||
   fail 'wrong host or user'
 for cmd in php stat sha256sum flock mktemp chown chmod cp mv;do
   command -v "$cmd" >/dev/null 2>&1 || fail 'missing executable';done
 for directory in "$SITE" "$DIR" "$PKG";do
   [[ -d "$directory" && ! -L "$directory" &&
     "$(realpath -e "$directory")" == "$directory" ]] ||
     fail 'directory changed';done
 [[ "$(stat -c '%u:%a' "$PKG")" == '0:700' ]] ||
   fail 'root-only release package changed'
 pin "$NATIVE" "$NATIVE_SHA"
 pin "$CANARY" "$CANARY_SHA"
 pin "$ORIG" "$ORIG_SHA"
 [[ "$(stat -c '%u:%a' "$CANARY")" == '0:600' &&
    "$(stat -c '%u:%a' "$ORIG")" == '0:600' ]] ||
    fail 'private backup permissions'
 [[ -f "$LIVE" && ! -L "$LIVE" &&
   "$(realpath -e "$LIVE")" == "$LIVE" ]] ||
   fail 'production signin file changed'
 OWNER="$(stat -c '%u' "$LIVE")"
 GROUP="$(stat -c '%g' "$LIVE")"
 PERMS="$(stat -c '%a' "$LIVE")"
 [[ "$PERMS" =~ ^[0-7]{3}$ ]] || fail 'unknown signin file mode'
 FIXED_SHA="$(patched_sha "$CANARY" "$NATIVE")" ||
   fail 'native PASSWORD_ORIGINAL semantics or exact patch check'
 [[ "$FIXED_SHA" =~ ^[0-9a-f]{64}$ &&
    "$FIXED_SHA" != "$CANARY_SHA" &&
    "$FIXED_SHA" != "$ORIG_SHA" ]] ||
   fail 'invalid fixed SHA'
 case "$(sha "$LIVE")" in
   "$CANARY_SHA")echo 'LIVE_SIGNIN=BUGGY_PILOT_CANARY' ;;
   "$FIXED_SHA")echo 'LIVE_SIGNIN=FIXED_PILOT_CANARY' ;;
   "$ORIG_SHA")echo 'LIVE_SIGNIN=ORIGINAL' ;;
   *)fail 'unknown live login SHA' ;;
 esac
 php -d auto_prepend_file= -d auto_append_file= -l "$LIVE" >/dev/null ||
    fail 'installed PHP syntax'
 pass 'native Bitrix PASSWORD_ORIGINAL is required'
 pass 'exact canary/backup SHA and live login version'
 echo "FIXED_CANDIDATE_SHA256=$FIXED_SHA"
 echo 'PATCH_SCOPE=EXACTLY_TWO_EXPRESSIONS'
 echo 'NON_PILOT_LOGIN_CODE_UNTOUCHED=YES'
}
locked(){
 [[ ! -L "$LOCK" && ( ! -e "$LOCK" || -f "$LOCK" ) ]] ||
   fail 'unsafe lockfile'
 exec 9>> "$LOCK"
 chmod 600 "$LOCK"
 flock -n 9 || fail 'other pilot release in progress'
 pass 'exclusive release lock'
}
selftest(){
 echo '=== 1. OFFLINE PASSWORD CONTRACT PATCH TEST ==='
 bash -n "$0" || fail 'shell grammar'
 FIXTURE="$(mktemp -d /tmp/ev-trust-contract.XXXXXXXX)"
 cat > "$FIXTURE/native.php" <<'PHP'
<?php $original = isset($arParams["PASSWORD_ORIGINAL"]);
Password::equals($arUser["PASSWORD"], $arParams["PASSWORD"], $original);
PHP
 cat > "$FIXTURE/canary.php" <<'PHP'
<?php class A {
  private function startPilotSmsChallenge() {
    $passwordOriginal = 'Y';
    $params = ['PASSWORD_ORIGINAL' => &$passwordOriginal,];
    return $params;
  }
  private function loginByExistingTrustedTotp() {
    $applicationId = 'Y';
    $params = ['APPLICATION_ID' => &$applicationId,];
  }
}
PHP
 local expect got
 expect="$(patched_sha "$FIXTURE/canary.php" "$FIXTURE/native.php")" ||
   fail 'synthetic fixed sha denied'
 : > "$FIXTURE/output.php"
 got="$(php -d auto_prepend_file= -d auto_append_file= \
    -r "$PATCH" "$FIXTURE/canary.php" "$FIXTURE/native.php" \
    write "$FIXTURE/output.php")" || fail 'synthetic write denied'
 [[ "$expect" == "$got" && "$(sha "$FIXTURE/output.php")" == "$expect" ]] ||
   fail 'fixture output hash mismatch'
 php -d auto_prepend_file= -d auto_append_file= \
    -l "$FIXTURE/output.php" >/dev/null || fail 'fixture PHP lint'
 printf '<?php /* no native Password Original */' > "$FIXTURE/native.php"
 if patched_sha "$FIXTURE/canary.php" "$FIXTURE/native.php" \
   >/dev/null 2>&1;then fail 'missing native API contract accepted';fi
 pass 'exact two-expression change, reverse hash proof and PHP syntax'
 pass 'missing native semantics denied'
 VERIFIED=YES
}
echo '=== TRUSTED TOTP PILOT PASSWORD CHECK REPAIR ==='
case "$MODE" in
 --self-test)selftest;;
 --preflight|--status)preflight;VERIFIED=YES;;
 --apply)
   locked
   preflight
   [[ "$(sha "$LIVE")" == "$FIXED_SHA" ]] && {
      echo 'ALREADY_APPLIED=YES';VERIFIED=YES;exit 0;
   }
   [[ "$(sha "$LIVE")" == "$CANARY_SHA" ]] ||
      fail 'only known buggy pilot canary can be patched'
   [[ -f "$0" && ! -L "$0" ]] || fail 'release script not regular file'
   if [[ ! -e "$OFFLINE" && ! -L "$OFFLINE" ]];then
      cp -- "$0" "$OFFLINE"
      chmod 700 "$OFFLINE"
   fi
   [[ -f "$OFFLINE" && ! -L "$OFFLINE" &&
      "$(sha "$OFFLINE")" == "$(sha "$0")" ]] ||
      fail 'offline rollback script mismatch'
   pass 'verified offline local rollback script'
   BACKUP="$(mktemp -d "$PKG/password-fix-backup.XXXXXXXX")"
   chmod 700 "$BACKUP"
   cp -- "$LIVE" "$BACKUP/signin.before.php"
   chmod 600 "$BACKUP/signin.before.php"
   [[ "$(sha "$BACKUP/signin.before.php")" == "$CANARY_SHA" ]] ||
     fail 'independent pre-fix live backup SHA'
   pass 'private independent live backup'
   STAGE="$(mktemp "$DIR/.ev-trust-fixed.XXXXXXXX")"
   chmod 600 "$STAGE"
   got="$(php -d auto_prepend_file= -d auto_append_file= \
      -r "$PATCH" "$CANARY" "$NATIVE" write "$STAGE")" ||
      fail 'native contract patch builder failed'
   [[ "$got" == "$FIXED_SHA" && "$(sha "$STAGE")" == "$FIXED_SHA" ]] ||
      fail 'fixed staging SHA mismatch'
   chown "$OWNER:$GROUP" "$STAGE"
   chmod "$PERMS" "$STAGE"
   [[ "$(stat -c '%u:%g:%a' "$STAGE")" == "$OWNER:$GROUP:$PERMS" ]] ||
      fail 'staged owner/group/mode'
   php -d auto_prepend_file= -d auto_append_file= \
      -l "$STAGE" >/dev/null || fail 'staged source PHP lint'
   [[ "$(sha "$LIVE")" == "$CANARY_SHA" ]] ||
      fail 'production source changed during release'
   echo '=== 2. ATOMIC SINGLE-FILE PILOT PATCH ==='
   REPLACED=YES
   mv -fT -- "$STAGE" "$LIVE"
   STAGE=''
   [[ "$(sha "$LIVE")" == "$FIXED_SHA" &&
      "$(stat -c '%u:%g:%a' "$LIVE")" == "$OWNER:$GROUP:$PERMS" ]] ||
      fail 'installed SHA or metadata invalid'
   php -d auto_prepend_file= -d auto_append_file= \
      -l "$LIVE" >/dev/null || fail 'installed PHP lint'
   pass 'corrected login parameter and source SHA postcheck'
   VERIFIED=YES
   REPLACED=NO
   echo 'LIVE_SIGNIN=FIXED_PILOT_CANARY'
   echo "OFFLINE_ROLLBACK_COMMAND=bash $OFFLINE --rollback"
   ;;
 --rollback)
   locked
   preflight
   echo '=== 2. REVERT TO ORIGINAL PRE-PILOT LOGIN ==='
   restore || fail 'pinned original restoration refused'
   pass 'original pre-pilot login restored'
   VERIFIED=YES
   echo 'LIVE_SIGNIN=ORIGINAL'
   ;;
esac
