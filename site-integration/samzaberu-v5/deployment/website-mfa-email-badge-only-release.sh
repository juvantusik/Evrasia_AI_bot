#!/usr/bin/env bash
# SOURCE-VERIFIED WEBSITE ACCOUNT BADGE ONLY.
# NO DB writes, no SMS/email sending, no login/PIN/V4/V5 changes.
# Only explicitly staged two account templates and four passive PHP helpers.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
STAGE='/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H'
RELEASE="$STAGE/live-badge-release"
HELPER_DIR="$SITE/local/php_interface/website-mfa"

MAIN_REL='local/templates/eurasia/components/eurasia/account/main/template.php'
CACHED_REL='local/templates/eurasia/components/eurasia/account/cached/template.php'
SIGNIN_REL='local/components/eurasia/signin/class.php'

MAIN_OLD='42e4ae2bae706f5c63b37d1029621d2a6a9c924fef2a34560be0a369f5e23b24'
MAIN_NEW='7d0db7e8a16f190f0dfd59a2d4337f45f088ea96705e24c60cef1c96d0cd2654'
CACHED_OLD='6c9c92bc9ea2b4653a2882909137fa6f0007874232e0bee5414ed54a0ba35ecd'
CACHED_NEW='66ae330cc0af3b2ed54a3d92c224fec60919bec4bfd279e1d9debdd7eacd59bb'
SIGNIN_SHA='e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a'

declare -a HELPERS=(
  VerifiedEmailStatus.php
  EmailVerificationBadge.php
  EmailOwnershipProofStore.php
  EmailAccountBadgeBridge.php
)
declare -a HELPER_BLOBS=(
  0d71b354cd99f3129aacf6a9a3c2cb5d3c478586
  fdbeefffed9187bdae18e0f11276146fbced7a96
  f86074e201f0de1de654237ec51d09fb04173207
  0585273a5f6eb3d8196fe178d6cc3be5e82d1b6b
)

PASS_COUNT=0
LIVE_TOUCHED=NO
ROLLED_BACK=NO
LOCK_FD=''
fatal(){ echo "FAIL: $1"; return 1; }
pass(){ PASS_COUNT=$((PASS_COUNT+1)); echo "PASS: $1"; }

sha(){
  local v
  v="$(sha256sum "$1")" || return 1
  printf '%s' "${v%% *}"
}
gitblob(){
  php -d auto_prepend_file= -d auto_append_file= -r '
    $src=file_get_contents($argv[1]);
    if(!is_string($src)) exit(2);
    echo sha1("blob ".strlen($src)."\0".$src);
  ' "$1"
}
mode_result(){
  local rc="$1"
  echo
  echo '=== 5. RESULT ==='
  echo "MODE=$MODE"
  echo "PASS_COUNT=$PASS_COUNT"
  echo "RELEASE_DIRECTORY=$RELEASE"
  echo "LIVE_TOUCHED=$LIVE_TOUCHED"
  echo "AUTOMATIC_ROLLBACK=$ROLLED_BACK"
  echo 'DATABASE_CONNECTION=NO'
  echo 'DATABASE_WRITE=NO'
  echo 'SITE_WRITE_SCOPE=ACCOUNT_TEMPLATES_AND_FOUR_PASSIVE_HELPERS_ONLY'
  echo 'SIGNIN_WRITE=NO'
  echo 'BITRIX_CORE_WRITE=NO'
  echo 'PIN_WRITE=NO'
  echo 'TRUSTED_DEVICE_WRITE=NO'
  echo 'REDIS_WRITE=NO'
  echo 'EMAIL_SENT=NO'
  echo 'SMS_SENT=NO'
  echo 'API_V4_WRITE=NO'
  echo 'API_V5_WRITE=NO'
  echo 'EMAIL_FIRST_LOGIN_ACTIVATED=NO'
  echo 'EMAIL_CONFIRMATION_ACTIVATED=NO'
  echo 'VERIFIED_EMAIL_DATABASE_MIGRATED=NO'
  echo 'EMAIL_BADGE_ACTIVE='
  if [[ "$MODE" == '--install' && "$rc" -eq 0 || "$MODE" == '--status' && "$rc" -eq 0 ]]; then
    echo 'YES'
  else
    echo 'NOT_CONFIRMED'
  fi
  if ((rc==0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
  echo "FINAL_RC=$rc"
  echo 'TERMINAL_WILL_STAY_OPEN=YES'
}
on_exit(){
  local rc=$?
  trap - EXIT
  if [[ "$MODE" == --install && "$rc" -ne 0 && "$LIVE_TOUCHED" == YES ]]; then
    echo
    echo '=== EMERGENCY RESTORE ==='
    if rollback_internal; then
      ROLLED_BACK=YES
      echo 'PASS: automatic source-pinned restore'
    else
      echo 'FAIL: automatic restore could not fully complete, use exact release restore.sh'
    fi
  fi
  mode_result "$rc"
  exit "$rc"
}
trap on_exit EXIT

assert_file(){
  local path="$1" expected="$2" label="$3"
  [[ -f "$path" && ! -L "$path" && -r "$path" ]] ||
    { fatal "$label missing/unsafe file"; return 1; }
  [[ "$(realpath -e "$path")" == "$path" ]] ||
    { fatal "$label realpath"; return 1; }
  [[ "$(sha "$path")" == "$expected" ]] ||
    { fatal "$label source SHA drift"; return 1; }
}
safe_temp_copy(){
  local source="$1" destination="$2" reference="$3"
  local tmp=''
  tmp="$(mktemp "${destination}.new.XXXXXXXX")" ||
    { fatal 'same-filesystem mktemp failed'; return 1; }
  if ! cp -- "$source" "$tmp" ||
     ! chown --reference="$reference" "$tmp" ||
     ! chmod --reference="$reference" "$tmp" ||
     ! php -d short_open_tag=1 -d auto_prepend_file= \
          -d auto_append_file= -l "$tmp" >/dev/null; then
    rm -f -- "$tmp"
    fatal 'atomic staged PHP copy failed'
    return 1
  fi
  mv -f -T -- "$tmp" "$destination"
}
safe_new_helper(){
  local source="$1" destination="$2" reference="$3"
  local tmp=''
  tmp="$(mktemp "${destination}.new.XXXXXXXX")" ||
    { fatal 'helper temp failed'; return 1; }
  if ! cp -- "$source" "$tmp" ||
     ! chown --reference="$reference" "$tmp" ||
     ! chmod 0644 "$tmp" ||
     ! php -d short_open_tag=1 -d auto_prepend_file= \
          -d auto_append_file= -l "$tmp" >/dev/null; then
    rm -f -- "$tmp"
    fatal 'helper staging/lint failed'
    return 1
  fi
  mv -f -T -- "$tmp" "$destination"
}
self_test(){
  [[ "$#" -eq 1 ]] || { fatal 'exactly one mode'; return 1; }
  [[ "${#HELPERS[@]}" -eq 4 && "${#HELPER_BLOBS[@]}" -eq 4 ]] ||
    { fatal 'helper mapping mismatch'; return 1; }
  local x
  for x in "$MAIN_OLD" "$MAIN_NEW" "$CACHED_OLD" "$CACHED_NEW" \
      "$SIGNIN_SHA" "${HELPER_BLOBS[@]}"; do
    [[ "$x" =~ ^[a-f0-9]{64}$ || "$x" =~ ^[a-f0-9]{40}$ ]] ||
      { fatal 'source hash format invalid'; return 1; }
  done
  pass 'exact pinned source hashes and helper manifest'
  echo 'PHASE=BADGE_ONLY'
  echo 'SOURCE_CHECK=OFFLINE'
}
guard_host(){
  [[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] ||
    { fatal 'wrong host or not root'; return 1; }
  [[ -d "$SITE" && ! -L "$SITE" &&
     "$(realpath -e "$SITE")" == "$SITE" ]] ||
    { fatal 'Bitrix root invalid'; return 1; }
  [[ -d "$STAGE" && ! -L "$STAGE" &&
     "$(realpath -e "$STAGE")" == "$STAGE" &&
     "$(stat -c '%u:%a' "$STAGE")" == '0:700' ]] ||
    { fatal 'private stage guard'; return 1; }
  [[ -d "$HELPER_DIR" && ! -L "$HELPER_DIR" &&
     "$(realpath -e "$HELPER_DIR")" == "$HELPER_DIR" ]] ||
    { fatal 'installed SMS helper directory guard'; return 1; }
  [[ -f "$HELPER_DIR/GlobalSmsBudget.php" &&
     ! -L "$HELPER_DIR/GlobalSmsBudget.php" ]] ||
    { fatal 'original global SMS quota missing'; return 1; }
  assert_file "$SITE/$SIGNIN_REL" "$SIGNIN_SHA" 'existing all-users signin'
  pass 'exact evrasia server, current all-user MFA and private stage'
}
lock_release(){
  local lock="$STAGE/.badge-only-release.lock"
  exec {LOCK_FD}>"$lock"
  flock -x -w 10 "$LOCK_FD" ||
    { fatal 'other badge release operation has lock'; return 1; }
  pass 'exclusive badge release lock'
}
guard_staged(){
  assert_file "$STAGE/account-main.rollback.php" "$MAIN_OLD" 'main rollback snapshot'
  assert_file "$STAGE/account-cached.rollback.php" "$CACHED_OLD" 'cached rollback snapshot'
  assert_file "$STAGE/account-main.candidate.php" "$MAIN_NEW" 'main badge candidate'
  assert_file "$STAGE/account-cached.candidate.php" "$CACHED_NEW" 'cached badge candidate'

  local manifest="$STAGE/manifest.txt"
  [[ -f "$manifest" && ! -L "$manifest" ]] ||
    { fatal 'source stage manifest missing'; return 1; }
  grep -Fxq 'STATUS=PREPARED_PRIVATE_ONLY' "$manifest" ||
    { fatal 'wrong stage status'; return 1; }
  grep -Fxq 'EMAIL_SIGNIN_SWITCH=NOT_BUILT' "$manifest" ||
    { fatal 'login switch must remain disabled'; return 1; }
  grep -Fxq "MAIN_BEFORE_SHA=$MAIN_OLD" "$manifest" ||
    { fatal 'main stage source mismatch'; return 1; }
  grep -Fxq "CACHED_BEFORE_SHA=$CACHED_OLD" "$manifest" ||
    { fatal 'cached stage source mismatch'; return 1; }

  local i name file actual
  for i in "${!HELPERS[@]}"; do
    name="${HELPERS[$i]}"
    file="$STAGE/helpers/$name"
    [[ -f "$file" && ! -L "$file" && -r "$file" ]] ||
      { fatal "private helper missing $name"; return 1; }
    actual="$(gitblob "$file")" ||
      { fatal "helper hash compute failed $name"; return 1; }
    [[ "$actual" == "${HELPER_BLOBS[$i]}" ]] ||
      { fatal "helper immutable git blob mismatch $name"; return 1; }
    php -d auto_prepend_file= -d auto_append_file= \
      -l "$file" >/dev/null || { fatal "$name syntax"; return 1; }
  done
  for file in "$STAGE/account-main.candidate.php" \
              "$STAGE/account-cached.candidate.php"; do
    php -d short_open_tag=1 -d auto_prepend_file= \
      -d auto_append_file= -l "$file" >/dev/null ||
      { fatal 'account badge template PHP lint'; return 1; }
  done
  pass 'two byte-reversible account candidates and four immutable helpers'
}
current_state(){
  local main cached
  main="$(sha "$SITE/$MAIN_REL")" || return 1
  cached="$(sha "$SITE/$CACHED_REL")" || return 1
  if [[ "$main" == "$MAIN_OLD" && "$cached" == "$CACHED_OLD" ]]; then
    echo 'UNINSTALLED'
  elif [[ "$main" == "$MAIN_NEW" && "$cached" == "$CACHED_NEW" ]]; then
    echo 'INSTALLED'
  else
    echo 'DRIFT_OR_PARTIAL'
  fi
}
guard_fresh(){
  local state
  state="$(current_state)"
  [[ "$state" == UNINSTALLED ]] ||
    { fatal "expected untouched account UI, got $state"; return 1; }
  local name
  for name in "${HELPERS[@]}"; do
    [[ ! -e "$HELPER_DIR/$name" && ! -L "$HELPER_DIR/$name" ]] ||
      { fatal "existing email helper $name; manual review required"; return 1; }
  done
  [[ ! -e "$HELPER_DIR/email-ownership-secret.php" &&
     ! -L "$HELPER_DIR/email-ownership-secret.php" ]] ||
    { fatal 'email secret unexpectedly already installed'; return 1; }
  pass 'badge not installed, no existing ownership secret or helper collision'
}
prepare_release(){
  [[ ! -e "$RELEASE" && ! -L "$RELEASE" ]] ||
    { fatal 'activation checkpoint already exists, use status or rollback'; return 1; }
  mkdir -m 700 "$RELEASE"
  [[ "$(stat -c '%u:%a' "$RELEASE")" == '0:700' ]] ||
    { fatal 'checkpoint permissions'; return 1; }
  cp -p -- "$SITE/$MAIN_REL" "$RELEASE/account-main.backup.php"
  cp -p -- "$SITE/$CACHED_REL" "$RELEASE/account-cached.backup.php"
  cp -- "$0" "$RELEASE/restore.sh"
  chmod 600 "$RELEASE/restore.sh"
  assert_file "$RELEASE/account-main.backup.php" "$MAIN_OLD" 'fresh main backup'
  assert_file "$RELEASE/account-cached.backup.php" "$CACHED_OLD" 'fresh cached backup'
  {
    echo 'RELEASE_SCOPE=BADGE_ONLY_2_ACCOUNT_TEMPLATES_4_PASSIVE_HELPERS'
    echo "STAGE=$STAGE"
    echo "MAIN_BEFORE_SHA=$MAIN_OLD"
    echo "MAIN_INSTALLED_SHA=$MAIN_NEW"
    echo "CACHED_BEFORE_SHA=$CACHED_OLD"
    echo "CACHED_INSTALLED_SHA=$CACHED_NEW"
    echo "SIGNIN_UNCHANGED_SHA=$SIGNIN_SHA"
    echo 'EMAIL_FIRST_AUTH=OFF'
    echo 'EMAIL_OWNERSHIP_DB_WRITE=NO'
    echo "RESTORE_SCRIPT_SHA=$(sha "$RELEASE/restore.sh")"
    stat -c 'MAIN_ORIGINAL_METADATA=%u:%g:%a' "$RELEASE/account-main.backup.php"
    stat -c 'CACHED_ORIGINAL_METADATA=%u:%g:%a' "$RELEASE/account-cached.backup.php"
  } > "$RELEASE/manifest.txt"
  chmod 600 "$RELEASE/manifest.txt"
  pass 'fresh immutable source backups and self-contained rollback script'
}
release_guard(){
  [[ -d "$RELEASE" && ! -L "$RELEASE" &&
     "$(stat -c '%u:%a' "$RELEASE")" == '0:700' ]] ||
    { fatal 'release checkpoint absent/unsafe'; return 1; }
  local m="$RELEASE/manifest.txt"
  [[ -f "$m" && ! -L "$m" &&
     "$(stat -c '%u:%a' "$m")" == '0:600' ]] ||
    { fatal 'release checkpoint manifest missing'; return 1; }
  grep -Fxq 'RELEASE_SCOPE=BADGE_ONLY_2_ACCOUNT_TEMPLATES_4_PASSIVE_HELPERS' "$m" ||
    { fatal 'release scope not exact'; return 1; }
  grep -Fxq "MAIN_INSTALLED_SHA=$MAIN_NEW" "$m" ||
    { fatal 'bad source checkpoint'; return 1; }
  assert_file "$RELEASE/account-main.backup.php" "$MAIN_OLD" 'rollback main backup'
  assert_file "$RELEASE/account-cached.backup.php" "$CACHED_OLD" 'rollback cached backup'
  pass 'exact root-only release checkpoint and backups'
}
helper_installed(){
  local i name file
  for i in "${!HELPERS[@]}"; do
    name="${HELPERS[$i]}"
    file="$HELPER_DIR/$name"
    [[ -f "$file" && ! -L "$file" ]] || return 1
    [[ "$(gitblob "$file")" == "${HELPER_BLOBS[$i]}" ]] || return 1
  done
}
status_core(){
  release_guard
  [[ "$(current_state)" == INSTALLED ]] ||
    { fatal 'account template SHA not installed'; return 1; }
  helper_installed || { fatal 'email badge helper SHA mismatch'; return 1; }
  assert_file "$SITE/$SIGNIN_REL" "$SIGNIN_SHA" 'no signin mutation'
  echo "WEBSITE_EMAIL_BADGE=INSTALLED"
  echo "EMAIL_VERIFICATION_OR_LOGIN=NOT_ACTIVATED"
  echo "CURRENT_STATUS_ON_PROOF_ABSENCE=UNVERIFIED_RED"
  pass 'both account templates and passive helper sources installed'
}
rollback_internal(){
  release_guard || return 1
  local actual source target name i
  # Check EVERYTHING before the first mutation, allow partial install state.
  actual="$(sha "$SITE/$MAIN_REL")" || return 1
  [[ "$actual" == "$MAIN_OLD" || "$actual" == "$MAIN_NEW" ]] ||
    { fatal 'main changed by another actor; refuse restore'; return 1; }
  actual="$(sha "$SITE/$CACHED_REL")" || return 1
  [[ "$actual" == "$CACHED_OLD" || "$actual" == "$CACHED_NEW" ]] ||
    { fatal 'cached changed by another actor; refuse restore'; return 1; }
  for i in "${!HELPERS[@]}"; do
    name="${HELPERS[$i]}"
    target="$HELPER_DIR/$name"
    if [[ -e "$target" || -L "$target" ]]; then
      [[ -f "$target" && ! -L "$target" &&
         "$(gitblob "$target")" == "${HELPER_BLOBS[$i]}" ]] ||
        { fatal "helper $name modified by other actor"; return 1; }
    fi
  done
  # Restore original templates atomically, metadata from fresh backups.
  if [[ "$(sha "$SITE/$MAIN_REL")" == "$MAIN_NEW" ]]; then
    safe_temp_copy "$RELEASE/account-main.backup.php" \
      "$SITE/$MAIN_REL" "$RELEASE/account-main.backup.php"
  fi
  if [[ "$(sha "$SITE/$CACHED_REL")" == "$CACHED_NEW" ]]; then
    safe_temp_copy "$RELEASE/account-cached.backup.php" \
      "$SITE/$CACHED_REL" "$RELEASE/account-cached.backup.php"
  fi
  for name in "${HELPERS[@]}"; do
    target="$HELPER_DIR/$name"
    [[ ! -e "$target" && ! -L "$target" ]] || rm -f -- "$target"
  done
  assert_file "$SITE/$MAIN_REL" "$MAIN_OLD" 'restored main'
  assert_file "$SITE/$CACHED_REL" "$CACHED_OLD" 'restored cached'
  assert_file "$SITE/$SIGNIN_REL" "$SIGNIN_SHA" 'signin still unchanged'
  echo 'ACCOUNT_BADGE_ACTIVE=NO'
  pass 'both original account templates restored and four passive helpers removed'
}
install_core(){
  if [[ -d "$RELEASE" && "$(current_state)" == INSTALLED ]]; then
    status_core
    echo 'ALREADY_APPLIED=YES'
    return 0
  fi
  guard_fresh
  guard_staged
  prepare_release

  echo
  echo '=== 3. ACTIVATE WEBSITE ACCOUNT BADGE ONLY ==='
  local name
  LIVE_TOUCHED=YES
  for name in "${HELPERS[@]}"; do
    safe_new_helper "$STAGE/helpers/$name" \
      "$HELPER_DIR/$name" "$SITE/$MAIN_REL" ||
      return 1
  done
  pass 'four passive source-only badge helpers atomically installed'

  safe_temp_copy "$STAGE/account-main.candidate.php" \
    "$SITE/$MAIN_REL" "$RELEASE/account-main.backup.php" || return 1
  safe_temp_copy "$STAGE/account-cached.candidate.php" \
    "$SITE/$CACHED_REL" "$RELEASE/account-cached.backup.php" || return 1
  pass 'two cached/main account templates atomically installed'

  echo
  echo '=== 4. VERIFY INSTALLED SHA AND MFA ISOLATION ==='
  status_core
  LIVE_TOUCHED=NO
  echo 'BADGE_ONLY_ROLLOUT=INSTALLED'
}
main(){
  echo '=== 1. ENVIRONMENT / OPERATION ==='
  if [[ "$MODE" == --self-test ]]; then self_test "$@"; return; fi
  [[ "$#" -eq 1 ]] || { fatal 'one mode required'; return 1; }
  for c in php sha256sum grep stat realpath cp chown chmod mv mkdir rm flock mktemp; do
    command -v "$c" >/dev/null 2>&1 ||
      { fatal "required command missing $c"; return 1; }
  done
  guard_host
  lock_release

  echo
  echo '=== 2. GUARDS AND SCOPE ==='
  case "$MODE" in
    --preflight)
      guard_fresh
      guard_staged
      echo 'MODE=READ_ONLY'
      echo 'PRODUCTION_SITE_WRITE=NO'
      echo 'PREPARED_BADGE_RELEASE=READY'
      ;;
    --install) install_core ;;
    --status) status_core ;;
    --rollback)
      rollback_internal
      echo "ROLLBACK=COMPLETED"
      ;;
    *) fatal 'valid modes self-test preflight install status rollback'; return 1 ;;
  esac
}
main "$@"
