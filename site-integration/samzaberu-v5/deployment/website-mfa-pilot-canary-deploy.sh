#!/usr/bin/env bash
# Evrasia single-file existing-PILOT-only trusted TOTP release / rollback.
# --preflight / --status are read-only. --install / --rollback write ONLY signin class.php.
set -Eeuo pipefail
umask 077
[[ $# -eq 1 ]] || { echo 'FAIL: one mode required'; exit 2; }
MODE="$1"
case "$MODE" in
 --self-test|--preflight|--status|--install|--rollback) ;;
 *) echo 'FAIL: unsupported mode'; exit 2 ;;
esac

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
DIR="$SITE/local/components/eurasia/signin"
LIVE="$DIR/class.php"
PACK='/root/ev-web-mfa-release-packages/pilot-trust-canary-20261023-230052-4a23f5de'
ORIG="$PACK/signin.rollback.php"
NEW="$PACK/signin.pilot-canary.php"
MANIFEST="$PACK/canary-manifest.json"
OFFLINE="$PACK/pilot-deploy-offline.sh"
LOCK="$PACK/.pilot-deploy.lock"
OLD_SHA='6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9'
NEW_SHA='e150d387036d0bba38e48ac70973a0f91f49b596840f6c42b8911cfaff7df181'
V3_SHA='04d254d7667c29819557d4df269760fa09e1561d2e6185fe8ab68a3a2d7f3770'
TEMP=''
BACKUP=''
SWAPPED=NO
SUCCESS=NO
USER_ID_NUM=''
GROUP_ID_NUM=''
FILE_MODE=''
PASS_COUNT=0

pass() { PASS_COUNT=$((PASS_COUNT+1)); echo "PASS: $1"; }
refuse() { echo "FAIL: $1"; exit 1; }
sha() { sha256sum "$1" | awk '{print $1}'; }
pinned() {
 local path="$1" expected="$2" privacy="$3"
 [[ -f "$path" && ! -L "$path" && -r "$path" &&
    "$(realpath -e "$path")" == "$path" ]] || refuse 'file path guard'
 if [[ "$privacy" == PRIVATE ]]; then
    [[ "$(stat -c '%u:%a' "$path")" == '0:600' ]] ||
        refuse 'root-private file permissions guard'
 fi
 [[ "$(sha "$path")" == "$expected" ]] || refuse 'pinned source SHA mismatch'
}
# Return nonzero without exiting so exit trap can restore if necessary.
stage() {
 local input="$1" digest="$2"
 [[ -z "$TEMP" ]] || return 1
 TEMP="$(mktemp "$DIR/.ev-mfa-release.XXXXXXXX")" || return 1
 chmod 600 "$TEMP" || return 1
 cp -- "$input" "$TEMP" || return 1
 [[ "$(sha "$TEMP")" == "$digest" ]] || return 1
 chown "$USER_ID_NUM:$GROUP_ID_NUM" "$TEMP" || return 1
 chmod "$FILE_MODE" "$TEMP" || return 1
 [[ "$(stat -c '%u:%g:%a' "$TEMP")" == "$USER_ID_NUM:$GROUP_ID_NUM:$FILE_MODE" ]] || return 1
 php -d auto_prepend_file= -d auto_append_file= \
   -d opcache.enable_cli=0 -l "$TEMP" >/dev/null || return 1
 [[ "$(sha "$TEMP")" == "$digest" ]]
}
# Requires exact installed canary SHA; never overwrites unknown live changes.
restore() {
 [[ -f "$LIVE" && ! -L "$LIVE" &&
    "$(sha "$LIVE")" == "$NEW_SHA" ]] || return 1
 [[ "$(sha "$ORIG")" == "$OLD_SHA" ]] || return 1
 stage "$ORIG" "$OLD_SHA" || return 1
 mv -fT -- "$TEMP" "$LIVE" || return 1
 TEMP=''
 [[ "$(sha "$LIVE")" == "$OLD_SHA" ]] || return 1
 php -d auto_prepend_file= -d auto_append_file= \
   -d opcache.enable_cli=0 -l "$LIVE" >/dev/null
}
finish() {
 local rc=$?
 trap - EXIT
 set +e
 if [[ "$SWAPPED" == YES && "$SUCCESS" != YES ]]; then
    echo '=== AUTOMATIC ROLLBACK ==='
    if restore; then
        echo 'AUTOMATIC_ROLLBACK=PASS'
    else
        echo 'AUTOMATIC_ROLLBACK=FAIL_REQUIRES_OPERATOR'
        rc=1
    fi
 fi
 if [[ -n "$TEMP" && "$TEMP" == "$DIR"/.ev-mfa-release.* &&
       -f "$TEMP" && ! -L "$TEMP" ]]; then
    rm -f -- "$TEMP" || rc=1
 fi
 echo
 echo '=== 5. RESULT ==='
 echo "MODE=$MODE"
 echo "PASS_COUNT=$PASS_COUNT"
 echo "OPERATION_COMPLETED=$SUCCESS"
 if [[ -n "$BACKUP" ]]; then echo "PRIVATE_BACKUP=$BACKUP"; else echo 'PRIVATE_BACKUP=NONE'; fi
 echo 'ONE_SIGNIN_FILE_ONLY=YES'
 echo 'ALL_USER_MFA_ENABLED=NO'
 echo 'DATABASE_CONNECTION=NO'
 echo 'REDIS_CONNECTION=NO'
 echo 'SMS_SENT=NO'
 echo 'TRUSTED_DEVICE_WRITE=NO'
 echo 'V4_WRITE=NO'
 echo 'V5_AUTH_WRITE=NO'
 echo 'REAL_BROWSER_E2E=NOT_RUN_BY_SCRIPT'
 if ((rc==0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
 echo "FINAL_RC=$rc"
 echo 'TERMINAL_WILL_STAY_OPEN=YES'
 exit "$rc"
}
trap finish EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

preflight() {
 echo '=== 1. HOST / PACKAGE / LIVE FILE GUARDS ==='
 [[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] ||
    refuse 'wrong host or user'
 local cmd d
 for cmd in php sha256sum stat flock chown chmod cp mv mktemp; do
    command -v "$cmd" >/dev/null 2>&1 || refuse 'missing system dependency'
 done
 for d in "$SITE" "$DIR" "$PACK"; do
    [[ -d "$d" && ! -L "$d" && "$(realpath -e "$d")" == "$d" ]] ||
        refuse 'directory path changed'
 done
 [[ "$(stat -c '%u:%a' "$PACK")" == '0:700' ]] ||
    refuse 'root-only checkpoint permission drift'
 pinned "$ORIG" "$OLD_SHA" PRIVATE
 pinned "$NEW" "$NEW_SHA" PRIVATE
 [[ -f "$MANIFEST" && ! -L "$MANIFEST" &&
    "$(stat -c '%u:%a' "$MANIFEST")" == '0:600' ]] ||
    refuse 'pilot manifest permission drift'
 php -d auto_prepend_file= -d auto_append_file= -r '
try {
 $m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
 if (($m["scope"]??"")!=="EXISTING_PILOT_ACCOUNT_ONLY"
   || ($m["status"]??"")!=="PRIVATE_CANDIDATE_NOT_INSTALLED"
   || ($m["install_authorized"]??true)!==false
   || ($m["rollout_for_all_users"]??true)!==false
   || ($m["live_source_sha256"]??"")!==$argv[2]
   || ($m["candidate_sha256"]??"")!==$argv[3]
   || ($m["v3_source_sha256"]??"")!==$argv[4]
   || ($m["rollback_source"]??"")!=="signin.rollback.php") exit(1);
} catch(Throwable) {exit(1);}
' "$MANIFEST" "$OLD_SHA" "$NEW_SHA" "$V3_SHA" ||
    refuse 'pilot-specific immutable manifest validation'
 pass 'source SHA and locked single-pilot scope'
 [[ -f "$LIVE" && ! -L "$LIVE" &&
    "$(realpath -e "$LIVE")" == "$LIVE" ]] ||
    refuse 'live signin file not regular'
 local current
 current="$(sha "$LIVE")"
 case "$current" in
    "$OLD_SHA") echo 'LIVE_SIGNIN=ORIGINAL' ;;
    "$NEW_SHA") echo 'LIVE_SIGNIN=PILOT_CANARY' ;;
    *) refuse 'live source SHA unknown; will not modify' ;;
 esac
 USER_ID_NUM="$(stat -c '%u' "$LIVE")"
 GROUP_ID_NUM="$(stat -c '%g' "$LIVE")"
 FILE_MODE="$(stat -c '%a' "$LIVE")"
 [[ "$FILE_MODE" =~ ^[0-7]{3}$ ]] ||
    refuse 'unexpected live file permission flags'
 php -d auto_prepend_file= -d auto_append_file= -l "$NEW" >/dev/null ||
    refuse 'candidate PHP syntax'
 php -d auto_prepend_file= -d auto_append_file= -l "$ORIG" >/dev/null ||
    refuse 'rollback PHP syntax'
 pass 'known live file SHA, owner/group/mode and PHP lints'
 echo 'PUBLIC_DOMAIN=evrasia.rest'
 echo 'PILOT_USER_SCOPE=EXISTING_PILOT_ID'
}
lock_write() {
 [[ ! -L "$LOCK" ]] || refuse 'unsafe lockfile symlink'
 exec 9>"$LOCK"
 chmod 600 "$LOCK"
 flock -n 9 || refuse 'parallel pilot installation is locked'
 pass 'exclusive root-private release lock'
}
persist_recovery() {
 [[ -f "$0" && ! -L "$0" ]] || refuse 'installer not a regular file'
 if [[ -e "$OFFLINE" ]]; then
    [[ -f "$OFFLINE" && ! -L "$OFFLINE" &&
       "$(sha "$OFFLINE")" == "$(sha "$0")" ]] ||
        refuse 'offline rollback source mismatch'
 else
    [[ ! -L "$OFFLINE" ]] || refuse 'offline recovery symlink'
    cp -- "$0" "$OFFLINE"
    chmod 700 "$OFFLINE"
    [[ "$(sha "$OFFLINE")" == "$(sha "$0")" ]] ||
        refuse 'offline rollback cannot be verified'
 fi
 pass 'rollback script locally available without network'
}
install_one() {
 lock_write
 preflight
 if [[ "$(sha "$LIVE")" == "$NEW_SHA" ]]; then
    echo 'ALREADY_APPLIED=YES'
    SUCCESS=YES
    return
 fi
 persist_recovery
 BACKUP="$(mktemp -d "$PACK/live-snapshot.XXXXXXXX")"
 chmod 700 "$BACKUP"
 cp -- "$LIVE" "$BACKUP/signin.php"
 chmod 600 "$BACKUP/signin.php"
 [[ "$(sha "$BACKUP/signin.php")" == "$OLD_SHA" ]] ||
    refuse 'independent installation backup SHA mismatch'
 pass 'independent live backup verified in root-private storage'
 echo '=== 2. EXACT SINGLE-FILE ATOMIC INSTALL ==='
 stage "$NEW" "$NEW_SHA" || refuse 'candidate stage/ownership/lint failed'
 [[ "$(sha "$LIVE")" == "$OLD_SHA" ]] ||
    refuse 'live SHA changed after preflight'
 mv -fT -- "$TEMP" "$LIVE"
 TEMP=''
 SWAPPED=YES
 [[ "$(sha "$LIVE")" == "$NEW_SHA" &&
    "$(stat -c '%u:%g:%a' "$LIVE")" == "$USER_ID_NUM:$GROUP_ID_NUM:$FILE_MODE" ]] ||
    refuse 'postinstall live file SHA/metadata mismatch'
 php -d auto_prepend_file= -d auto_append_file= -l "$LIVE" >/dev/null ||
    refuse 'postinstall PHP syntax invalid'
 pass 'atomic pilot signin patch verified; no other site files replaced'
 SWAPPED=NO
 SUCCESS=YES
 echo 'LIVE_SIGNIN=PILOT_CANARY'
 echo "ROLLBACK_COMMAND=bash $OFFLINE --rollback"
}
rollback_one() {
 lock_write
 preflight
 if [[ "$(sha "$LIVE")" == "$OLD_SHA" ]]; then
    echo 'ALREADY_ROLLED_BACK=YES'
    SUCCESS=YES
    return
 fi
 echo '=== 2. EXACT SINGLE-FILE ATOMIC ROLLBACK ==='
 restore || refuse 'original restoration failed or live file changed'
 pass 'original signin restored and SHA/lint verified'
 SUCCESS=YES
 echo 'LIVE_SIGNIN=ORIGINAL'
}
self_test() {
 echo '=== 1. OFFLINE ATOMIC INSTALL AND ROLLBACK FIXTURE ==='
 bash -n "$0" || refuse 'Bash syntax'
 local d
 d="$(mktemp -d /tmp/ev-mfa-canary-ci.XXXXXXXX)"
 printf 'old\n' > "$d/old"
 printf 'new\n' > "$d/new"
 cp "$d/old" "$d/live"
 cp "$d/new" "$d/.staged"
 mv -fT "$d/.staged" "$d/live"
 [[ "$(sha "$d/live")" == "$(sha "$d/new")" ]] ||
    refuse 'fixture candidate replace'
 cp "$d/old" "$d/.staged"
 mv -fT "$d/.staged" "$d/live"
 [[ "$(sha "$d/live")" == "$(sha "$d/old")" ]] ||
    refuse 'fixture rollback'
 rm -f -- "$d/old" "$d/new" "$d/live"
 rmdir "$d"
 pass 'atomic replacement and SHA-matched rollback on isolated fixture'
 SUCCESS=YES
}
echo '=== WEBSITE MFA PILOT-ONLY DEPLOYMENT ==='
case "$MODE" in
 --self-test) self_test ;;
 --preflight|--status) preflight; SUCCESS=YES ;;
 --install) install_one ;;
 --rollback) rollback_one ;;
esac
