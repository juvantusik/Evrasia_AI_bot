#!/usr/bin/env bash
# Additive OFF-only installation. No DB, native Bitrix, HTTP requests or service reload.
set -Eeuo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
readonly CODE=0caa0248c783188939398a71543ba740f064bb90
readonly PKG=site-integration/samzaberu-v5
readonly BASE=/home/site_evrasia/web/evrasia.spb.ru
readonly DOCROOT="$BASE/public_html" PRIVATE="$BASE/private"
readonly HOME_V5="$PRIVATE/samzaberu-v5"
readonly RELEASE="$HOME_V5/releases/$CODE" PUBLIC="$DOCROOT/api/v5"
readonly PHP=/usr/bin/php8.3
BACKUP='' PUBLISHED=NO PUBLIC_INODE='' BUILD='' SELFTEST=NO
fail() { printf 'FAIL: %s\n' "$1" >&2; exit 2; }
exists() { [ -e "$1" ] || [ -L "$1" ]; }
canonical_dir() { [ -d "$1" ] && [ ! -L "$1" ] && [ "$(realpath -e "$1")" = "$1" ]; }
sha() { sha256sum -- "$1" | awk '{print $1}'; }
plain_file() { [ -f "$1" ] && [ ! -L "$1" ] && [ "$(stat -c %h "$1")" = 1 ] && [ "$(realpath -e "$1")" = "$1" ]; }
# Never inherit credential helpers, URL rewrites, hooks or the operator's Git config.
clean_git() {
  env -i PATH="$PATH" HOME="$GIT_HOME" GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null \
    GIT_TERMINAL_PROMPT=0 git -c safe.directory="$REPO" -c core.hooksPath=/dev/null \
    -c credential.helper= -c protocol.file.allow=never -C "$REPO" "$@"
}
check_protected() {
  local rel expected
  while read -r expected rel; do
    plain_file "$DOCROOT/$rel" || fail "Unsafe protected file: $rel"
    [ "$(sha "$DOCROOT/$rel")" = "$expected" ] || fail "Protected file drift: $rel"
  done <<'PINS'
81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95 .htaccess
61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3 api/v4/.htaccess
3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50 api/v4/router.php
PINS
  ! exists "$DOCROOT/api/.htaccess" || fail 'New shared API rewrite; review before installing'
}
# Build ONLY immutable runtime files. CI test scripts and SQL are never installed.
build_payload() {
  local destination="$1" public_stage="$2" final_release="$3" root="$4"
  local oid name header mode type ignored file
  [ ! -e "$destination" ] && [ ! -e "$public_stage" ] || return 2
  [ "$(clean_git rev-parse "$REF:$PKG/src")" = 7d107b977971f4dc8b0d76439cc7e6e942e79909 ] || return 2
  [ "$(clean_git rev-parse "$REF:$PKG/api/v5/router.php")" = c06b576e776cdc3478caae2f6e6faa3184eb4ed5 ] || return 2
  [ "$(clean_git rev-parse "$REF:$PKG/api/v5/site.php")" = f158d2e7aca4a625f1c075aa2a498d75bcd166bf ] || return 2
  [ "$(clean_git rev-parse "$REF:$PKG/api/v5/.htaccess")" = a1a05fb6d630c24aed627558304f71e7ba0af9b9 ] || return 2
  mkdir -p -- "$destination/src" "$destination/api/v5" "$public_stage"
  clean_git ls-tree "$REF:$PKG/src" > "$BUILD/source-list"
  while IFS=$'\t' read -r header name; do
    read -r mode type oid ignored <<< "$header"
    [ "$mode" = 100644 ] && [ "$type" = blob ] && [ -z "${ignored:-}" ] || return 2
    [[ "$name" =~ ^[A-Za-z0-9_]+\.php$ ]] && [[ "$oid" =~ ^[a-f0-9]{40}$ ]] || return 2
    clean_git cat-file blob "$oid" > "$destination/src/$name"
  done < "$BUILD/source-list"
  for name in router.php site.php .htaccess; do
    clean_git show "$REF:$PKG/api/v5/$name" > "$destination/api/v5/$name"
  done
  clean_git show "$REF:$PKG/deployment/public-router.php" > "$BUILD/public-template.php"
  [ "$(clean_git hash-object "$BUILD/public-template.php")" = e1a02fc6dd94fbe78c04cad42289b716269798a1 ] || return 2
  "$PHP" -n -r '
    $s=file_get_contents($argv[1]);
    if(substr_count($s,"__PRIVATE_V5_RELEASE__")!==1 || substr_count($s,"__V5_DOCUMENT_ROOT__")!==1)exit(2);
    foreach([$argv[2],$argv[3]] as $p)if(!preg_match("~\\A/[a-zA-Z0-9_./-]+\\z~D",$p))exit(2);
    echo str_replace(["__PRIVATE_V5_RELEASE__","__V5_DOCUMENT_ROOT__"],[$argv[2],$argv[3]],$s);
  ' "$BUILD/public-template.php" "$final_release" "$root" > "$public_stage/router.php"
  cp -- "$destination/api/v5/.htaccess" "$public_stage/.htaccess"
  printf '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}\n' > "$destination/site-config.json"
  printf '%s\n' "$CODE" > "$destination/CODE_COMMIT"
  find "$destination" "$public_stage" -type f -name '*.php' -print > "$BUILD/php-list"
  while IFS= read -r file; do "$PHP" -n -l "$file" > /dev/null || return 2; done < "$BUILD/php-list"
  [ ! -e "$destination/site-factory.php" ] && [ ! -e "$destination/tests" ] && [ ! -e "$destination/schema" ]
}
smoke_off() {
  local shim="$1" release="$2" output="$3"
  local -a runner=()
  if [ "$SELFTEST" = NO ]; then runner=(runuser -u site_evrasia --); fi
  "${runner[@]}" "$PHP" -n -d display_errors=0 -d log_errors=0 -r '
    $shim=$argv[1];$release=$argv[2];
    if(!is_file($release."/site-config.json") || is_file($release."/site-factory.php"))exit(2);
    $config=json_decode(file_get_contents($release."/site-config.json"),true);
    if(($config["mode"]??null)!=="off")exit(2);
    register_shutdown_function(static function()use($shim,$release):void {
      if(http_response_code()!==503 || class_exists("Bitrix\\Main\\Application",false) || class_exists("CUser",false))exit(3);
      foreach(get_included_files() as $p)if($p!==$shim && !str_starts_with($p,$release."/"))exit(4);
      fwrite(STDERR,"OFF_SMOKE_PASS\n");
    });
    $_SERVER=["REQUEST_METHOD"=>"POST","REQUEST_URI"=>"/api/v5/auth/signin","HTTPS"=>"on","CONTENT_TYPE"=>"application/json"];
    require $shim;
  ' "$shim" "$release" > "$output.out" 2> "$output.err" || return 2
  [ "$(cat "$output.out")" = '{"error":"v5_unavailable"}' ] && [ "$(cat "$output.err")" = OFF_SMOKE_PASS ]
}
publish_once() {
  local from="$1" to="$2" inode
  ! exists "$to" || return 2
  inode="$(stat -c '%d:%i' "$from")"
  mv -T -n -- "$from" "$to"
  [ ! -e "$from" ] && [ "$(stat -c '%d:%i' "$to")" = "$inode" ]
}
finish() {
  local rc=$?
  trap - EXIT
  set +e
  if [ "$rc" -ne 0 ] && [ "$PUBLISHED" = YES ]; then
    if canonical_dir "$PUBLIC" && [ "$(stat -c '%d:%i' "$PUBLIC")" = "$PUBLIC_INODE" ] \
      && [ "$(sha "$PUBLIC/router.php")" = "$SHIM_HASH" ] \
      && [ "$(sha "$PUBLIC/.htaccess")" = "$REWRITE_HASH" ] \
      && [ "$(find "$PUBLIC" -mindepth 1 -maxdepth 1 | wc -l)" -eq 2 ]; then
      publish_once "$PUBLIC" "$HOME_V5/public-quarantined" && echo 'AUTO_ROLLBACK_PUBLIC=PASS'
    else
      echo 'AUTO_ROLLBACK_PUBLIC=REFUSED_UNEXPECTED_DRIFT'
    fi
  fi
  printf '\n=== 5. RESULT ===\n'
  if [ "$rc" -eq 0 ]; then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
  printf 'FINAL_RC=%s\n' "$rc"
  [ -z "$BACKUP" ] || printf 'BACKUP_DIR=%s\n' "$BACKUP"
  printf 'DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\nBITRIX_BOOTSTRAP=NO\nSMS_SENT=NO\nHTTP_TESTED=NO\nSERVICE_RELOAD=NO\n'
  exit "$rc"
}
trap finish EXIT

if [ "${1:-}" = --self-test ]; then
  SELFTEST=YES
  [ "$#" -eq 1 ] && [ "${GITHUB_ACTIONS:-}" = true ] || fail 'CI-only package self-test'
  case "$(hostname -s)" in evrasia|eur-bot-01) fail 'Self-test forbidden on site hosts';; esac
  canonical_dir "${GITHUB_WORKSPACE:-/missing}" || fail 'CI checkout unavailable'
  BUILD="$(mktemp -d /tmp/ev-v5-off-package.XXXXXXXX)"
  GIT_HOME="$BUILD" REPO="$GITHUB_WORKSPACE" REF=HEAD
  mkdir "$BUILD/docroot"
  build_payload "$BUILD/release" "$BUILD/public-stage" "$BUILD/release" "$BUILD/docroot"
  chmod 750 "$BUILD/release"
  chmod 640 "$BUILD/release/site-config.json"
  smoke_off "$BUILD/public-stage/router.php" "$BUILD/release" "$BUILD/smoke" || fail 'OFF package PHP smoke'
  publish_once "$BUILD/public-stage" "$BUILD/docroot/v5" || fail 'Atomic publication'
  smoke_off "$BUILD/docroot/v5/router.php" "$BUILD/release" "$BUILD/published" || fail 'Published OFF smoke'
  mkdir "$BUILD/collision"
  printf 'do-not-overwrite\n' > "$BUILD/collision/canary"
  if publish_once "$BUILD/collision" "$BUILD/docroot/v5"; then fail 'Collision was accepted'; fi
  [ "$(cat "$BUILD/collision/canary")" = do-not-overwrite ] || fail 'Collision damaged source'
  publish_once "$BUILD/docroot/v5" "$BUILD/quarantine" || fail 'Additive rollback'
  [ ! -e "$BUILD/docroot/v5" ] && [ -f "$BUILD/quarantine/router.php" ] || fail 'Rollback state'
  "$PHP" -n -r '$c=json_decode(file_get_contents($argv[1]),true);exit(($c["mode"]??null)==="off"?0:2);' "$BUILD/release/site-config.json"
  echo 'PASS: pinned runtime package, lint, OFF smoke, atomic publish, collision refusal, rollback'
  echo 'OFF_PACKAGE_SELFTEST=PASS'
  echo 'SITE_INSTALLATION_TESTED=NO'
  exit 0
fi

[ "$#" -eq 0 ] || fail 'Unsupported arguments'
echo '=== 1. HOST / ENVIRONMENT ==='
printf 'MODE=INSTALL_DISABLED_ONLY\nAPPLICATION_FILE_WRITE=YES\nHOST=%s\nUSER=%s\n' "$(hostname -s)" "$(id -un)"
printf 'CODE_COMMIT=%s\nDATABASE_WRITE=NO\nNATIVE_FACTORY_INSTALLED=NO\nSECRETS_PRINTED=NO\n' "$CODE"
echo '=== 2. GUARDS ==='
[ "$(hostname -s)" = evrasia ] && [ "$(id -u)" -eq 0 ] || fail 'Requires root on evrasia'
[ "$(id -u site_evrasia)" = 1005 ] && [ "$(id -g site_evrasia)" = 1005 ] || fail 'Site identity drift'
for cmd in git timeout flock realpath stat sha256sum awk tar runuser find install mv cmp df cp sort xargs cut; do
  command -v "$cmd" >/dev/null || fail "Missing tool: $cmd"
done
[ -x "$PHP" ] || fail 'Expected PHP 8.3 missing'
"$PHP" -n -r 'exit(PHP_VERSION==="8.3.21"?0:2);' || fail 'CLI PHP changed; review exact runtime'
for path in "$BASE" "$DOCROOT" "$DOCROOT/api" "$DOCROOT/api/v4" "$PRIVATE"; do
  canonical_dir "$path" || fail "Noncanonical directory: $path"
done
[ "$(stat -c %d "$PRIVATE")" = "$(stat -c %d "$DOCROOT/api")" ] || fail 'Atomic directory publish requires same filesystem'
[ "$(stat -c %u "$PRIVATE")" = 1005 ] || fail 'Private parent owner drift'
[ "$(df -Pk "$PRIVATE" | awk 'NR==2 {print $4}')" -ge 102400 ] || fail 'Less than 100 MiB free'
if exists /run/evrasia-v5-off-install.lock; then
  plain_file /run/evrasia-v5-off-install.lock && [ "$(stat -c %u /run/evrasia-v5-off-install.lock)" = 0 ] || fail 'Unsafe lock file'
fi
exec 9>/run/evrasia-v5-off-install.lock
flock -n 9 || fail 'Another installation is running'
check_protected
if exists "$HOME_V5" || exists "$PUBLIC"; then
  canonical_dir "$HOME_V5" && canonical_dir "$RELEASE" && canonical_dir "$PUBLIC" || fail 'Partial/unexpected existing installation; no overwrite'
  plain_file "$HOME_V5/installed.sha256" && [ "$(stat -c '%u:%a' "$HOME_V5/installed.sha256")" = 0:600 ] || fail 'Missing trusted installation record'
  sha256sum -c --status "$HOME_V5/installed.sha256" || fail 'Installed OFF package changed; no overwrite'
  [ ! -e "$RELEASE/site-factory.php" ] || fail 'Native factory now present; use reviewed upgrade instead'
  [ "$(find "$PUBLIC" -mindepth 1 -maxdepth 1 | wc -l)" -eq 2 ] || fail 'Extra public files'
  printf 'ALREADY_APPLIED=YES\nV5_INSTALLED_DISABLED=YES\n'
  exit 0
fi

# Everything below is new and additive; no existing V4/root file is overwritten.
BACKUP="$(mktemp -d /root/ev-v5-off-backup.XXXXXXXX)"
BUILD="$BACKUP/work"
mkdir -m 700 "$BUILD"
printf 'API_V5_PREVIOUSLY_ABSENT=YES\nPRIVATE_V5_PREVIOUSLY_ABSENT=YES\n' > "$BACKUP/before.txt"
tar -cpf "$BACKUP/protected-files.tar" -C "$DOCROOT" .htaccess api/v4/.htaccess api/v4/router.php
tar -df "$BACKUP/protected-files.tar" -C "$DOCROOT" >/dev/null || fail 'Backup comparison failed'
sha256sum "$BACKUP/protected-files.tar" > "$BACKUP/backup.sha256"
sha256sum -c --status "$BACKUP/backup.sha256" || fail 'Backup checksum failed'
echo 'PASS: baseline and recovery archive verified'

GIT_HOME="$BUILD" REPO="$BUILD/source.git" REF="$CODE"
mkdir "$REPO"
clean_git init --bare --quiet
# A fixed public commit, not a mutable branch; no repository checkout/hooks.
timeout 150 env -i PATH="$PATH" HOME="$GIT_HOME" GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null \
  GIT_TERMINAL_PROMPT=0 git -c core.hooksPath=/dev/null -c credential.helper= \
  -c protocol.file.allow=never -C "$REPO" fetch --quiet --no-tags --depth=1 \
  https://github.com/juvantusik/Evrasia_AI_bot.git "$CODE" > "$BUILD/fetch.log" 2>&1 || fail 'Pinned source download failed; site unchanged'
[ "$(clean_git rev-parse FETCH_HEAD)" = "$CODE" ] || fail 'Source commit mismatch'
clean_git fsck --strict --no-reflogs > "$BUILD/fsck.log" 2>&1 || fail 'Source object verification failed'
build_payload "$BUILD/release" "$BUILD/public" "$RELEASE" "$DOCROOT"
check_protected
! exists "$HOME_V5" && ! exists "$PUBLIC" || fail 'Target appeared during preparation'
echo '=== 3. INSTALL OFF-ONLY FILES ==='
install -d -o root -g 1005 -m 0750 "$HOME_V5" "$HOME_V5/releases"
# /root backup and /home site may reside on different filesystems.
# Copy while still PRIVATE, compare every file, then publish only the public dir.
cp -R -- "$BUILD/release" "$RELEASE"
while IFS= read -r -d '' file; do
  cmp -s -- "$file" "$RELEASE/${file#"$BUILD/release/"}" || fail 'Private copy mismatch'
done < <(find "$BUILD/release" -type f -print0)
chown -R root:1005 "$RELEASE"
find "$RELEASE" -type d -exec chmod 0750 {} +
find "$RELEASE" -type f -exec chmod 0640 {} +
# Public target is prepared privately and becomes visible in one same-FS rename.
cp -R -- "$BUILD/public" "$HOME_V5/public-ready"
for name in router.php .htaccess; do
  cmp -s -- "$BUILD/public/$name" "$HOME_V5/public-ready/$name" || fail 'Public copy mismatch'
done
chown -R root:1005 "$HOME_V5/public-ready"
chmod 0755 "$HOME_V5/public-ready"
chmod 0644 "$HOME_V5/public-ready/router.php" "$HOME_V5/public-ready/.htaccess"
smoke_off "$HOME_V5/public-ready/router.php" "$RELEASE" "$BUILD/prepublish" || fail 'Site-user OFF smoke failed; public not installed'
SHIM_HASH="$(sha "$HOME_V5/public-ready/router.php")"
REWRITE_HASH="$(sha "$HOME_V5/public-ready/.htaccess")"
PUBLIC_INODE="$(stat -c '%d:%i' "$HOME_V5/public-ready")"
check_protected
publish_once "$HOME_V5/public-ready" "$PUBLIC" || fail 'Public target collision; no overwrite'
PUBLISHED=YES

echo '=== 4. VERIFY ==='
smoke_off "$PUBLIC/router.php" "$RELEASE" "$BUILD/postpublish" || fail 'Post-install OFF smoke failed'
check_protected
tar -df "$BACKUP/protected-files.tar" -C "$DOCROOT" >/dev/null || fail 'Protected file/metadata changed'
find "$RELEASE" -type f -print0 | sort -z | xargs -0 sha256sum > "$HOME_V5/installed.sha256"
sha256sum "$PUBLIC/router.php" "$PUBLIC/.htaccess" >> "$HOME_V5/installed.sha256"
chmod 0600 "$HOME_V5/installed.sha256"
sha256sum -c --status "$HOME_V5/installed.sha256" || fail 'Installed manifest mismatch'
# Readable rollback command moves ONLY this verified V5 public directory aside.
{
  printf '#!/usr/bin/env bash\nset -Eeuo pipefail\numask 077\n'
  printf '[ "$(id -u)" = 0 ] && [ "$(hostname -s)" = evrasia ] || exit 2\n'
  printf 'exec 9>/run/evrasia-v5-off-install.lock\nflock -n 9 || exit 2\n'
  printf 'P=%q\nQ=%q\n' "$PUBLIC" "$HOME_V5/public-rollback"
  printf '[ ! -L "$P" ] && [ "$(realpath -e "$P")" = "$P" ] || exit 2\n'
  printf '[ "$(stat -c "%%d:%%i" "$P")" = %q ] || exit 2\n' "$PUBLIC_INODE"
  printf '[ "$(sha256sum "$P/router.php" | cut -d " " -f1)" = %q ] || exit 2\n' "$SHIM_HASH"
  printf '[ "$(sha256sum "$P/.htaccess" | cut -d " " -f1)" = %q ] || exit 2\n' "$REWRITE_HASH"
  printf '[ "$(find "$P" -mindepth 1 -maxdepth 1 | wc -l)" -eq 2 ] || exit 2\n'
  printf '[ ! -e "$Q" ] && [ ! -L "$Q" ] || exit 2\nmv -T -n -- "$P" "$Q"\n[ ! -e "$P" ] || exit 2\n'
  printf 'echo "V5_PUBLIC_REMOVED=YES"\necho "PRIVATE_RELEASE_RETAINED=YES"\necho "DATABASE_WRITE=NO"\necho "FINAL_STATUS=PASS"\necho "FINAL_RC=0"\n'
} > "$BACKUP/rollback.sh"
bash -n "$BACKUP/rollback.sh" || fail 'Rollback syntax'
printf 'PASS: OFF entry returned 503 as site_evrasia, without native Bitrix\nPASS: V4 and shared rewrite files unchanged\n'
printf 'V5_INSTALLED_DISABLED=YES\nV5_MODE=off\nPUBLIC_PATH=%s\nPRIVATE_RELEASE=%s\nROLLBACK_SCRIPT=%s/rollback.sh\n' "$PUBLIC" "$RELEASE" "$BACKUP"
