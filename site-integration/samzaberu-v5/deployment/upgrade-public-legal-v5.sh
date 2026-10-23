#!/usr/bin/env bash
# V5-only live public legal validation upgrade. No account, JWT, V4 or SMS change.
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C

BASE='/home/site_evrasia/web/evrasia.spb.ru'
DOCROOT="$BASE/public_html"
HOME_V5="$BASE/private/samzaberu-v5"
OLD_CODE='0caa0248c783188939398a71543ba740f064bb90'
PATCH_CODE='d7c5d5e87f2d5a80d621f08e42b857963a6c3506'
OLD="$HOME_V5/releases/$OLD_CODE"
NEW="$HOME_V5/releases/public-legal-d7c5d5e87f2d"
PUBLIC="$DOCROOT/api/v5"
LIVE="$PUBLIC/router.php"
MANIFEST="$HOME_V5/installed.sha256"
PHP='/usr/bin/php8.3'
ORIGIN='192.168.103.141'
VHOST='evrasia.spb.ru'
PKG='site-integration/samzaberu-v5'
ROUTER_BLOB='4d158a86dc78fcb0fc11d98a7d164bb47a293cff'
VALIDATOR_BLOB='1e6014a101483f8c04b03e6304fabd375e5cc6d8'

BACKUP='' NEXT_ROUTER='' NEXT_MANIFEST='' UPDATED=NO NEW_HASH='' ROLLBACK=NOT_NEEDED
fail() { echo "FAIL: $1" >&2; exit 2; }
plain() { [ -f "$1" ] && [ ! -L "$1" ] && [ "$(stat -c %h "$1")" = 1 ] && [ "$(realpath -e "$1")" = "$1" ]; }
canonical() { [ -d "$1" ] && [ ! -L "$1" ] && [ "$(realpath -e "$1")" = "$1" ]; }
digest() { sha256sum "$1" | cut -d ' ' -f1; }
finish() {
  local rc=$?
  trap - EXIT
  set +e
  if [ "$rc" -ne 0 ] && [ "$UPDATED" = YES ] && [ -n "$BACKUP" ]; then
    ROLLBACK=REFUSED
    if plain "$LIVE" && [ "$(digest "$LIVE")" = "$NEW_HASH" ] \
      && plain "$BACKUP/router.before" && plain "$BACKUP/manifest.before"; then
      cp -p -- "$BACKUP/router.before" "$HOME_V5/.router-rollback-$$.php"
      chmod 0644 "$HOME_V5/.router-rollback-$$.php"
      mv -Tf -- "$HOME_V5/.router-rollback-$$.php" "$LIVE"
      cp -p -- "$BACKUP/manifest.before" "$HOME_V5/.manifest-rollback-$$"
      chmod 0600 "$HOME_V5/.manifest-rollback-$$"
      mv -Tf -- "$HOME_V5/.manifest-rollback-$$" "$MANIFEST"
      if sha256sum -c --status "$MANIFEST"; then ROLLBACK=PASS; fi
    fi
  fi
  echo '=== 5. RESULT ==='
  if [ "$rc" -eq 0 ]; then echo FINAL_STATUS=PASS; else echo FINAL_STATUS=FAIL; fi
  printf 'FINAL_RC=%s\nROLLBACK_STATUS=%s\n' "$rc" "$ROLLBACK"
  echo 'MODE=V5_PUBLIC_LEGAL_WRITE_ONLY'
  echo 'DATABASE_WRITE=NO'
  echo 'ACCOUNT_WRITE=NO'
  echo 'SMS_SENT=NO'
  echo 'V4_MODIFIED=NO'
  echo 'V5_AUTH_ENABLED=NO'
  echo 'SECRETS_PRINTED=NO'
  [ -z "$BACKUP" ] || printf 'BACKUP_DIRECTORY=%s\n' "$BACKUP"
  exit "$rc"
}
trap finish EXIT

echo '=== 1. HOST / ENVIRONMENT ==='
[ "$#" -eq 0 ] || fail 'Unsupported arguments'
[ "$(hostname -s)" = evrasia ] && [ "$(id -u)" -eq 0 ] || fail 'Wrong host/root'
[ "$(id -u site_evrasia)" = 1005 ] && [ "$(id -g site_evrasia)" = 1005 ] || fail 'Site user drift'
for cmd in git php8.3 runuser curl realpath stat sha256sum awk find xargs flock mktemp mv cp chmod chown cmp sort df cut; do
  command -v "$cmd" >/dev/null || fail "Missing $cmd"
done
for dir in "$BASE" "$DOCROOT" "$DOCROOT/api" "$PUBLIC" "$HOME_V5" "$HOME_V5/releases" "$OLD"; do
  canonical "$dir" || fail 'Unexpected site directory'
done
plain "$LIVE" && plain "$PUBLIC/.htaccess" && plain "$MANIFEST" && plain "$OLD/site-config.json" || fail 'Unsafe installed V5 files'
[ "$(stat -c '%u:%a' "$MANIFEST")" = 0:600 ] || fail 'Manifest permissions changed'
sha256sum -c --status "$MANIFEST" || fail 'Installed SHA baseline differs'
[ "$(cat "$OLD/CODE_COMMIT")" = "$OLD_CODE" ] || fail 'Existing release differs'
[ ! -e "$OLD/site-factory.php" ] && [ ! -e "$HOME_V5/site-factory.php" ] \
  && [ ! -e "$HOME_V5/site-config.json" ] || fail 'Private auth/pilot configuration exists'
grep -Fq "$OLD" "$LIVE" || fail 'Existing shim does not refer to expected OFF release'
[ -z "$(find "$OLD" -type l -print -quit)" ] || fail 'Unexpected old-release symlink'
while read -r sum file; do
  printf '%s  %s/%s\n' "$sum" "$DOCROOT" "$file" | sha256sum -c --status || fail 'V4 or root changed'
done <<'PINS'
81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95 .htaccess
61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3 api/v4/.htaccess
3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50 api/v4/router.php
PINS
if [ -e "$NEW" ] || [ -L "$NEW" ]; then
  canonical "$NEW" && grep -Fq "$NEW" "$LIVE" \
    && sha256sum -c --status "$MANIFEST" || fail 'Unrecognized preexisting V5 patch'
  echo 'ALREADY_APPLIED=YES'
  exit 0
fi
[ "$(df -Pk "$BASE" | awk 'NR==2{print $4}')" -ge 102400 ] || fail 'Insufficient disk'
if [ -e /run/evrasia-v5-off-install.lock ] || [ -L /run/evrasia-v5-off-install.lock ]; then
  plain /run/evrasia-v5-off-install.lock && [ "$(stat -c '%u' /run/evrasia-v5-off-install.lock)" = 0 ] || fail 'Unsafe existing V5 lock file'
fi
exec 9>/run/evrasia-v5-off-install.lock
flock -n 9 || fail 'Existing V5 deployment lock held'
echo 'PASS: pinned OFF bootstrap, V4 and manifest; root account'
echo 'V5_AUTH=OFF'

echo '=== 2. BACKUP / SOURCE ==='
BACKUP="$(mktemp -d /root/ev-v5-legal-upgrade.XXXXXXXX)"
cp -p -- "$LIVE" "$BACKUP/router.before"
cp -p -- "$MANIFEST" "$BACKUP/manifest.before"
sha256sum "$BACKUP/router.before" "$BACKUP/manifest.before" > "$BACKUP/backup.sha256"
sha256sum -c --status "$BACKUP/backup.sha256" || fail 'Backup verification failed'
env -i PATH="$PATH" HOME="$BACKUP" GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null \
  git init --bare -q "$BACKUP/source.git" || fail 'Failed creating private source repo'
git_safe() {
  env -i PATH="$PATH" HOME="$BACKUP" GIT_CONFIG_NOSYSTEM=1 \
    GIT_CONFIG_GLOBAL=/dev/null GIT_TERMINAL_PROMPT=0 \
    git -c core.hooksPath=/dev/null -c credential.helper= \
    -c protocol.file.allow=never --git-dir="$BACKUP/source.git" "$@"
}
git_safe fetch -q --no-tags --depth=1 \
  https://github.com/juvantusik/Evrasia_AI_bot.git "$PATCH_CODE" \
  > "$BACKUP/fetch.log" 2>&1 || fail 'Pinned GitHub commit fetch failed'
[ "$(git_safe rev-parse FETCH_HEAD)" = "$PATCH_CODE" ] || fail 'Source commit mismatch'
[ "$(git_safe rev-parse "$PATCH_CODE:$PKG/api/v5/router.php")" = "$ROUTER_BLOB" ] || fail 'Router object mismatch'
[ "$(git_safe rev-parse "$PATCH_CODE:$PKG/src/V5PublicLegalCodeValidation.php")" = "$VALIDATOR_BLOB" ] || fail 'Validator object mismatch'
echo 'PASS: immutable Git commit and two approved PHP blobs'

echo '=== 3. V5-ONLY UPGRADE ==='
cp -a -- "$OLD" "$NEW" || fail 'Cannot copy OFF release'
git_safe show "$PATCH_CODE:$PKG/api/v5/router.php" > "$NEW/api/v5/router.php" || fail 'Router copy failed'
git_safe show "$PATCH_CODE:$PKG/src/V5PublicLegalCodeValidation.php" \
  > "$NEW/src/V5PublicLegalCodeValidation.php" || fail 'Validator copy failed'
printf '%s\n' "$PATCH_CODE" > "$NEW/PUBLIC_LEGAL_PATCH_COMMIT"
chown -R root:1005 "$NEW"
chmod 0750 "$NEW" "$NEW/src" "$NEW/api" "$NEW/api/v5"
chmod 0640 "$NEW/api/v5/router.php" "$NEW/src/V5PublicLegalCodeValidation.php" "$NEW/PUBLIC_LEGAL_PATCH_COMMIT"
[ ! -e "$NEW/site-factory.php" ] || fail 'Unexpected V5 auth factory'
"$PHP" -n -l "$NEW/api/v5/router.php" >/dev/null
"$PHP" -n -l "$NEW/src/V5PublicLegalCodeValidation.php" >/dev/null

NEXT_ROUTER="$(mktemp "$HOME_V5/.router-next.XXXXXXXX.php")"
"$PHP" -n -r '
  $s=file_get_contents($argv[1]);
  if(substr_count($s,$argv[2])!==1 || !str_contains($s,"EVRASIA_SAMZABERU_V5_DOCUMENT_ROOT"))exit(2);
  echo str_replace($argv[2],$argv[3],$s);
' "$LIVE" "$OLD" "$NEW" > "$NEXT_ROUTER" || fail 'Cannot stage public shim'
chown root:1005 "$NEXT_ROUTER"
chmod 0644 "$NEXT_ROUTER"
"$PHP" -n -l "$NEXT_ROUTER" >/dev/null
smoke() {
  local got
  got="$(runuser -u site_evrasia -- "$PHP" -n -d display_errors=0 -r '
    $_SERVER=["HTTPS"=>"on","REQUEST_METHOD"=>$argv[2],"REQUEST_URI"=>$argv[3]];
    require $argv[1];
  ' "$NEXT_ROUTER" "$1" "$2")" || fail 'Staged router smoke failed'
  [ "$got" = "$3" ] || fail 'Staged router changes OFF contract'
}
smoke GET /api/v5/health '{"status":"online","service":"samzaberu-v5","auth_ready":false}'
smoke POST /api/v5/auth/signin '{"error":"v5_unavailable"}'
smoke POST /api/v5/legal/consents/validate '{"error":"unsupported_media_type"}'

NEXT_MANIFEST="$(mktemp "$HOME_V5/.manifest-next.XXXXXXXX")"
awk -v target="$LIVE" '$2!=target{print}' "$MANIFEST" > "$NEXT_MANIFEST"
find "$NEW" -type f -print0 | sort -z | xargs -0 sha256sum >> "$NEXT_MANIFEST"
NEW_HASH="$(digest "$NEXT_ROUTER")"
printf '%s  %s\n' "$NEW_HASH" "$LIVE" >> "$NEXT_MANIFEST"
chmod 0600 "$NEXT_MANIFEST"
sha256sum -c --status "$MANIFEST" || fail 'Baseline changed before publish'
cmp -s "$BACKUP/router.before" "$LIVE" || fail 'Public router concurrent change'
mv -Tf -- "$NEXT_ROUTER" "$LIVE" || fail 'Public V5 shim switch failed'
UPDATED=YES
mv -Tf -- "$NEXT_MANIFEST" "$MANIFEST" || fail 'Manifest update failed'
sha256sum -c --status "$MANIFEST" || fail 'Published checksum verification failed'
echo 'PASS: atomic V5 shim switch, immutable old release retained'

echo '=== 4. REAL ORIGIN HTTPS VERIFICATION ==='
https() {
  local method="$1" uri="$2" tag="$3" expected="$4" payload='' meta code tls peer extra
  if [ "$#" -eq 5 ]; then payload="$5"; fi
  local -a args=()
  if [ "$method" = POST ]; then
    [ -f "$payload" ] || fail 'Missing JSON body file'
    args=(--request POST --header 'Content-Type: application/json' --data-binary "@$payload")
  fi
  meta="$(env -i PATH="$PATH" HOME="$BACKUP" curl -q -sS --noproxy '*' \
    --proto '=https' --max-redirs 0 --ipv4 --resolve "$VHOST:443:$ORIGIN" \
    --connect-timeout 5 --max-time 20 --max-filesize 131072 \
    --header 'Accept: application/json' \
    --dump-header "$BACKUP/$tag.headers" --output "$BACKUP/$tag.json" \
    --write-out '%{http_code} %{ssl_verify_result} %{remote_ip}' \
    "${args[@]}" "https://$VHOST$uri")" || fail 'Origin HTTPS unavailable'
  read -r code tls peer extra <<< "$meta"
  [ "$code" = "$expected" ] && [ "$tls" = 0 ] && [ "$peer" = "$ORIGIN" ] \
    && [ -z "$extra" ] || fail 'Origin HTTP/TLS/peer mismatch'
  grep -qi '^content-type:[[:space:]]*application/json' "$BACKUP/$tag.headers" || fail 'Invalid content-type'
  grep -qi '^cache-control:.*no-store' "$BACKUP/$tag.headers" || fail 'Missing no-store'
  if grep -qiE '^(set-cookie|location|access-control-allow-origin):' "$BACKUP/$tag.headers"; then
    fail 'Cookie/redirect/CORS exposed'
  fi
  printf 'PASS: %s %s HTTP %s\n' "$method" "$uri" "$code"
}
https GET /api/v5/health health 200
"$PHP" -n -r '
  $s=json_decode(file_get_contents($argv[1]),true);
  exit(($s["status"]??null)==="online" && ($s["auth_ready"]??null)===false?0:2);
' "$BACKUP/health.json" || fail 'V5 health changed'
https GET /api/v5/legal/consents legal 200
"$PHP" -n -r '
  $j=json_decode(file_get_contents($argv[1]),true);$d=$j["documents"]??null;
  if(!is_array($d)||count($d)!==3)exit(2);
  $required=[];$types=[];
  foreach($d as $x){
    if(!is_array($x)||!is_string($x["code"]??null)||!is_string($x["type"]??null))exit(2);
    $types[$x["type"]]=true;
    if(($x["required"]??null)===true)$required[]=$x["code"];
  }
  if(count($required)!==2 || !isset($types["offer"],$types["personal_data"],$types["marketing"]))exit(2);
  file_put_contents($argv[2],json_encode(["agreements"=>$required],JSON_THROW_ON_ERROR));
' "$BACKUP/legal.json" "$BACKUP/legal-request.json" || fail 'Live agreement catalog changed'
https POST /api/v5/legal/consents/validate validate 200 "$BACKUP/legal-request.json"
"$PHP" -n -r '
  $v=json_decode(file_get_contents($argv[1]),true);
  $r=json_decode(file_get_contents($argv[2]),true);
  exit(($v["valid"]??null)===true && ($v["agreements"]??null)===$r["agreements"]?0:2);
' "$BACKUP/validate.json" "$BACKUP/legal-request.json" || fail 'Live legal validate incorrect'
printf '{}\n' > "$BACKUP/auth-disabled-body.json"
https POST /api/v5/auth/signin auth-disabled 503 "$BACKUP/auth-disabled-body.json"
printf '%s' '{"error":"v5_unavailable"}' | cmp -s - "$BACKUP/auth-disabled.json" \
  || fail 'V5 AUTH was unexpectedly enabled'
sha256sum -c --status "$MANIFEST" || fail 'Final V5 manifest drift'
while read -r sum file; do
  printf '%s  %s/%s\n' "$sum" "$DOCROOT" "$file" | sha256sum -c --status || fail 'V4 post-check changed'
done <<'PINS'
81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95 .htaccess
61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3 api/v4/.htaccess
3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50 api/v4/router.php
PINS
echo 'REAL_PUBLIC_LEGAL_VALIDATION=PASS'
echo 'V5_AUTH_RELEASE_GATE=DENIED'
