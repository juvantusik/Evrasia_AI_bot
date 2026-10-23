#!/usr/bin/env bash
# Publish V5 legal/registration only into the VERIFIED two-file OFF bootstrap.
# The public shim, site.php, V4, DB and original stale manifest NEVER change.
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
BASE=/home/site_evrasia/web/evrasia.spb.ru
WEB="$BASE/public_html"
V5="$WEB/local/samzaberu-v5"
API="$V5/api/v5"
SRC="$V5/src"
PUBLIC="$WEB/api/v5/router.php"
SITE="$API/site.php"
INNER="$API/router.php"
MANIFEST="$BASE/private/samzaberu-v5/installed.sha256"
CHECKPOINTS=/root/ev-v5-checkpoints
ORIGIN=192.168.103.141
HOST=evrasia.spb.ru
PHP=/usr/bin/php8.3
COMMIT=0a035f9603686d07643ecefadd3e4057c7fae34a
RAW="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$COMMIT/site-integration/samzaberu-v5"
PUBLIC_SHA=f502ae4718a3193d2765d30b75dff638a684cb75078534fecfd3524dce3b94d8
SITE_SHA=595698a40449b78e3b32335ec09a6c75bbe62fe9f0533acf0d3f274a262e7a51
INNER_OLD_SHA=6e534f93bcf873eda337439443cb3670f634f0afc1bfe9938e8acc4cef5ae37d
MANIFEST_PUBLIC_SHA=fa794b1404ebb3b0b44d82bb326af9406c5c9a186dcd462f8142f2783bf1ef74
V5_PUBLIC_REWRITE_SHA=5d10b20b840fe3f36a348a64a4fe5aee2b9050559cb5d429d6e12196929ed08f
V4_SHA=5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d
ROOT_REWRITE_SHA=81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95
V4_REWRITE_SHA=61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3
INNER_BLOB=93c02ff1483d7f49aff567ac7cda3f6a12578dc1
CATALOG_BLOB=241d28491ac42e4e6052bf18df7758ffb1307d85
CONTRACT_BLOB=0239f2350ce1f7eb49a79711cc58ff9d9c094532
VALIDATOR_BLOB=1e6014a101483f8c04b03e6304fabd375e5cc6d8
BACKUP=''
STAGING=''
NEW_ROUTER_SHA=''
SWITCHED=NO
ROLLBACK_STATUS=NOT_NEEDED
ORIGIN_ACCEPTED=NO
ADDED=0
fail() { echo "FAIL: $1" >&2; exit 2; }
plain() {
  [[ -f "$1" && -r "$1" && ! -L "$1" && "$(realpath -e "$1")" == "$1" &&
     "$(stat -c %h "$1")" == 1 ]]
}
dir() { [[ -d "$1" && ! -L "$1" && "$(realpath -e "$1")" == "$1" ]]; }
hash() { sha256sum -- "$1" | cut -c1-64; }
pin() {
  plain "$1" || fail "unsafe file for $3"
  [[ "$(hash "$1")" == "$2" ]] || fail "source SHA drift for $3"
}
finalize() {
  local rc=$?
  trap - EXIT
  set +e
  if [[ "$rc" -ne 0 && "$SWITCHED" == YES ]]; then
    if [[ -n "$BACKUP" && -f "$BACKUP/inner.before" && -f "$INNER" &&
      "$(hash "$INNER")" == "$NEW_ROUTER_SHA" &&
      "$(hash "$BACKUP/inner.before")" == "$INNER_OLD_SHA" ]]; then
      local restore
      restore="$(mktemp "$API/.v5-restore.XXXXXXXX.php")"
      cp -- "$BACKUP/inner.before" "$restore"
      chown root:1005 "$restore"
      chmod 0644 "$restore"
      if [[ "$(hash "$restore")" == "$INNER_OLD_SHA" ]]; then
        mv -Tf -- "$restore" "$INNER"
        if [[ "$(hash "$INNER")" == "$INNER_OLD_SHA" ]]; then
          ROLLBACK_STATUS=PASS
        else
          ROLLBACK_STATUS=FAIL
        fi
      else
        ROLLBACK_STATUS=FAIL
      fi
    else
      ROLLBACK_STATUS=REFUSED_CONCURRENT_DRIFT
    fi
  fi
  if [[ -n "$STAGING" && -d "$STAGING" && ! -L "$STAGING" &&
    "$STAGING" == /tmp/ev-v5-twofile.* ]]; then
    rm -f -- "$STAGING/router.php" "$STAGING/V5LegalConsentContract.php" \
      "$STAGING/V5BitrixPublicLegalCatalog.php" "$STAGING/V5PublicLegalCodeValidation.php"
    rmdir -- "$STAGING"
  fi
  echo
  echo '=== 5. RESULT ==='
  if [[ "$rc" -eq 0 ]]; then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
  echo "FINAL_RC=$rc"
  echo "ROLLBACK_STATUS=$ROLLBACK_STATUS"
  [[ -z "$BACKUP" ]] || echo "PRIVATE_BACKUP=$BACKUP"
  echo "LOCAL_V5_ROUTER_SWITCHED=$SWITCHED"
  echo "LOCAL_V5_LIBRARIES_ADDED=$ADDED"
  echo "ORIGIN_HTTPS_ACCEPTED=$ORIGIN_ACCEPTED"
  echo 'EXTERNAL_EVRASIA_REST_ACCEPTANCE=PENDING'
  echo 'LEGACY_INSTALLED_MANIFEST_WRITE=NO'
  echo 'PUBLIC_V5_ROUTER_WRITE=NO'
  echo 'LOCAL_V5_SITE_WRITE=NO'
  echo 'API_V4_WRITE=NO'
  echo 'DATABASE_WRITE=NO'
  echo 'ACCOUNT_WRITE=NO'
  echo 'SMS_SENT_BY_SCRIPT=NO'
  echo 'V5_AUTH_FACTORY_INSTALLED=NO'
  echo 'SECRETS_PRINTED=NO'
  echo 'TERMINAL_WILL_STAY_OPEN=YES'
  exit "$rc"
}
trap finalize EXIT

echo '=== 1. HOST / FIXED PRODUCTION GUARDS ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] ||
  fail 'expected host/root'
[[ "$(id -u site_evrasia)" == 1005 && "$(id -g site_evrasia)" == 1005 ]] ||
  fail 'site user mismatch'
for cmd in git php8.3 curl sha256sum realpath stat awk cmp \
  flock cut grep mktemp mv cp chown chmod ln rm; do
  command -v "$cmd" >/dev/null || fail "missing $cmd"
done
for d in "$BASE" "$WEB" "$WEB/api" "$WEB/api/v4" "$WEB/api/v5" \
  "$V5" "$API" "$SRC" "$CHECKPOINTS"; do
  dir "$d" || fail 'unexpected directory'
done
[[ "$(stat -c %u "$CHECKPOINTS")" == 0 ]] ||
  fail 'checkpoint parent not root owned'
[[ ! -L /run/evrasia-v5-twofile-addon.lock ]] ||
  fail 'unsafe lock symlink'
exec 9>/run/evrasia-v5-twofile-addon.lock
flock -n 9 || fail 'concurrent V5 update'
pin "$PUBLIC" "$PUBLIC_SHA" public_router
pin "$SITE" "$SITE_SHA" site_entry
pin "$INNER" "$INNER_OLD_SHA" local_inner_router
pin "$WEB/api/v5/.htaccess" "$V5_PUBLIC_REWRITE_SHA" public_v5_rewrite
pin "$WEB/api/v4/router.php" "$V4_SHA" v4_router
pin "$WEB/api/v4/.htaccess" "$V4_REWRITE_SHA" v4_htaccess
pin "$WEB/.htaccess" "$ROOT_REWRITE_SHA" root_htaccess
plain "$MANIFEST" || fail 'unsafe legacy SHA manifest'
[[ "$(stat -c '%u:%a' "$MANIFEST")" == '0:600' ]] ||
  fail 'legacy manifest ownership'
echo 'PASS: installed two-file bootstrap + all original V4 pins'
echo 'MODE=WRITE_V5_LOCAL_ONLY'

echo
echo '=== 2. ATTEST 41 CORRECT BASELINE RECORDS ==='
total=0
good=0
known_mismatch=0
while read -r expected path; do
  ((total+=1))
  [[ "$expected" =~ ^[a-f0-9]{64}$ && "$path" == /* ]] ||
    fail 'unexpected manifest format'
  if [[ "$path" == "$PUBLIC" ]]; then
    [[ "$expected" == "$MANIFEST_PUBLIC_SHA" &&
       "$(hash "$path")" == "$PUBLIC_SHA" ]] ||
      fail 'public V5 mismatch differs from prior verified source'
    ((known_mismatch+=1))
  else
    # Original install-disabled-v5.sh wrote 40 private release records,
    # followed by BOTH public router.php and public .htaccess. This one
    # nonrouter public file belongs to the 41 *valid* original records.
    # Authorize its exact path AND separately pinned Git-derived SHA only.
    case "$path" in
      "$BASE"/private/samzaberu-v5/releases/*) ;;
      "$WEB/api/v5/.htaccess")
        [[ "$expected" == "$V5_PUBLIC_REWRITE_SHA" ]] ||
          fail 'public V5 rewrite record SHA not original installer source'
        ;;
      *) fail 'manifest entry outside allowed original V5 install paths';;
    esac
    pin "$path" "$expected" original_manifest_entry
    ((good+=1))
  fi
done < "$MANIFEST"
[[ "$total" -eq 42 && "$good" -eq 41 && "$known_mismatch" -eq 1 ]] ||
  fail 'baseline does not equal operator-verified 42/41/1 state'
echo 'PASS: 41 protected original records; one intentionally retained stale shim record'

echo
echo '=== 3. PINNED SOURCE / VERIFIED BACKUP / V5-ONLY INSTALL ==='
STAGING="$(mktemp -d /tmp/ev-v5-twofile.XXXXXXXX)"
fetch() {
  local rel="$1" output="$2" gitsha="$3"
  curl -q --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' \
    --connect-timeout 10 --max-time 45 --max-filesize 500000 \
    --output "$output" "$RAW/$rel" || fail 'pinned source fetch'
  [[ "$(git hash-object "$output")" == "$gitsha" ]] ||
    fail 'unexpected source Git blob'
  "$PHP" -n -l "$output" >/dev/null || fail 'source PHP lint'
}
fetch api/v5/router.php "$STAGING/router.php" "$INNER_BLOB"
fetch src/V5LegalConsentContract.php "$STAGING/V5LegalConsentContract.php" "$CONTRACT_BLOB"
fetch src/V5BitrixPublicLegalCatalog.php "$STAGING/V5BitrixPublicLegalCatalog.php" "$CATALOG_BLOB"
fetch src/V5PublicLegalCodeValidation.php "$STAGING/V5PublicLegalCodeValidation.php" "$VALIDATOR_BLOB"
NEW_ROUTER_SHA="$(hash "$STAGING/router.php")"
for name in V5LegalConsentContract V5BitrixPublicLegalCatalog V5PublicLegalCodeValidation; do
  target="$SRC/$name.php"
  if [[ -e "$target" || -L "$target" ]]; then
    plain "$target" || fail "unsafe current V5 dependency: $name"
    [[ "$(hash "$target")" == "$(hash "$STAGING/$name.php")" ]] ||
      fail "existing V5 dependency requires separate review: $name"
  fi
done
[[ ! -e "$BASE/private/samzaberu-v5/site-factory.php" ]] ||
  fail 'unexpected V5 private auth factory'
[[ ! -e "$V5/site-factory.php" ]] ||
  fail 'unexpected V5 local auth factory'
BACKUP="$(mktemp -d "$CHECKPOINTS/v5-legal-twofile-20261023.XXXXXXXX")"
chmod 0700 "$BACKUP"
cp -a -- "$PUBLIC" "$BACKUP/public.before"
cp -a -- "$SITE" "$BACKUP/site.before"
cp -a -- "$INNER" "$BACKUP/inner.before"
cp -a -- "$MANIFEST" "$BACKUP/manifest.before"
pin "$BACKUP/public.before" "$PUBLIC_SHA" public_backup
pin "$BACKUP/site.before" "$SITE_SHA" site_backup
pin "$BACKUP/inner.before" "$INNER_OLD_SHA" inner_backup
cmp -s "$MANIFEST" "$BACKUP/manifest.before" ||
  fail 'original manifest backup invalid'
echo 'PASS: four checked source files downloaded; private rollback checkpoint verified'

# A real GET was previously served on this Bitrix origin. Check it BEFORE
# adding any code. No credential, user ID or SMS is submitted by this script.
https() {
  local method="$1" uri="$2" label="$3" expected="$4" data=""
  if [[ "$#" -eq 5 ]]; then data="$5"; fi
  local meta code tls ip extra
  if [[ "$method" == GET ]]; then
    meta="$(env -i PATH="$PATH" HOME="$BACKUP" curl -q --silent --show-error \
      --noproxy '*' --proto '=https' --max-redirs 0 --ipv4 \
      --resolve "$HOST:443:$ORIGIN" --connect-timeout 5 --max-time 20 \
      --max-filesize 131072 -H 'Accept: application/json' \
      --dump-header "$BACKUP/$label.headers" --output "$BACKUP/$label.json" \
      --write-out '%{http_code} %{ssl_verify_result} %{remote_ip}' \
      "https://$HOST$uri")" || fail "origin GET network: $label"
  else
    meta="$(env -i PATH="$PATH" HOME="$BACKUP" curl -q --silent --show-error \
      --noproxy '*' --proto '=https' --max-redirs 0 --ipv4 \
      --resolve "$HOST:443:$ORIGIN" --connect-timeout 5 --max-time 20 \
      --max-filesize 131072 -H 'Accept: application/json' \
      -H 'Content-Type: application/json' --data-binary "$data" \
      --dump-header "$BACKUP/$label.headers" --output "$BACKUP/$label.json" \
      --write-out '%{http_code} %{ssl_verify_result} %{remote_ip}' \
      "https://$HOST$uri")" || fail "origin POST network: $label"
  fi
  read -r code tls ip extra <<< "$meta"
  [[ "$code" == "$expected" && "$tls" == 0 && "$ip" == "$ORIGIN"
    && -z "$extra" ]] || fail "origin HTTPS status/TLS: $label"
  grep -qi '^content-type:[[:space:]]*application/json' "$BACKUP/$label.headers" ||
    fail "origin content type: $label"
  grep -qi '^cache-control:.*no-store' "$BACKUP/$label.headers" ||
    fail "origin response cache policy: $label"
  if grep -qiE '^(set-cookie|location|access-control-allow-origin):' "$BACKUP/$label.headers"; then
    fail "origin cookie/redirect/CORS: $label"
  fi
  echo "PASS: $method $uri HTTP $expected on origin"
}
https GET /api/v5/health pre-health 200
"$PHP" -n -r '
  $j=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);
  exit(($j["status"]??null)==="online" && ($j["auth_ready"]??null)===false?0:2);
' "$BACKUP/pre-health.json" || fail 'current V5 status not OFF'

for name in V5LegalConsentContract V5BitrixPublicLegalCatalog V5PublicLegalCodeValidation; do
  target="$SRC/$name.php"
  if [[ -f "$target" ]]; then
    echo "ALREADY_PRESENT=$name"
    continue
  fi
  temp="$(mktemp "$SRC/.v5-legal-next.XXXXXXXX.php")"
  cp -- "$STAGING/$name.php" "$temp"
  chown root:1005 "$temp"
  chmod 0640 "$temp"
  [[ "$(hash "$temp")" == "$(hash "$STAGING/$name.php")" ]] ||
    fail 'dependency staged wrong SHA'
  [[ ! -e "$target" && ! -L "$target" ]] ||
    fail 'V5 dependency appeared during staging'
  ln -- "$temp" "$target" || fail 'exclusive dependency publish failed'
  rm -f -- "$temp"
  ((ADDED+=1))
  [[ "$(hash "$target")" == "$(hash "$STAGING/$name.php")" ]] ||
    fail 'published V5 dependency drift'
done
pin "$PUBLIC" "$PUBLIC_SHA" public_before_switch
pin "$SITE" "$SITE_SHA" site_before_switch
pin "$INNER" "$INNER_OLD_SHA" old_inner_before_switch
next="$(mktemp "$API/.v5-inner-next.XXXXXXXX.php")"
cp -- "$STAGING/router.php" "$next"
chown root:1005 "$next"
chmod 0644 "$next"
[[ "$(hash "$next")" == "$NEW_ROUTER_SHA" ]] || fail 'new inner route staging drift'
mv -Tf -- "$next" "$INNER" || fail 'atomic V5 inner router switch failed'
SWITCHED=YES
echo 'PASS: replaced ONLY local V5 router, kept public shim and site.php'

echo
echo '=== 4. READ-ONLY HTTPS POSTCHECK / ROLLBACK GUARDS ==='
https GET /api/v5/health health 200
"$PHP" -n -r '
  $j=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);
  exit(($j["status"]??null)==="online" && ($j["auth_ready"]??null)===false?0:2);
' "$BACKUP/health.json" || fail 'V5 health unexpectedly enables auth'
https GET /api/v5/legal/consents consents 200
https GET /api/v5/legal/registration-documents registration 200
"$PHP" -n -r '
  $a=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);
  $b=json_decode(file_get_contents($argv[2]),true,16,JSON_THROW_ON_ERROR);
  if(!is_array($a["documents"]??null) || count($a["documents"])!==3 ||
    ($a["documents"]??null)!==($b["documents"]??null))exit(2);
  $req=[]; $types=[];
  foreach($a["documents"] as $d) {
    if(!is_array($d)||!is_string($d["code"]??null)||
       !is_string($d["type"]??null))exit(2);
    $types[$d["type"]]=true;
    if(($d["required"]??null)===true)$req[]=$d["code"];
  }
  if(count($req)!==2 || !isset($types["offer"],$types["personal_data"],$types["marketing"]))exit(2);
  file_put_contents($argv[3],json_encode(["agreements"=>$req],JSON_THROW_ON_ERROR));
' "$BACKUP/consents.json" "$BACKUP/registration.json" "$BACKUP/request.json" ||
  fail 'native registration document mismatch'
https POST /api/v5/legal/consents/validate consent-validate 200 \
  "$(cat "$BACKUP/request.json")"
"$PHP" -n -r '
  $v=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);
  $r=json_decode(file_get_contents($argv[2]),true,16,JSON_THROW_ON_ERROR);
  exit(($v["valid"]??null)===true && ($v["agreements"]??null)===$r["agreements"]?0:2);
' "$BACKUP/consent-validate.json" "$BACKUP/request.json" ||
  fail 'native agreement code validation failed'
https POST /api/v5/auth/signin signin-off 503 '{}'
printf '%s' '{"error":"v5_unavailable"}' | cmp -s - "$BACKUP/signin-off.json" ||
  fail 'V5 AUTH enabled unexpectedly'
pin "$PUBLIC" "$PUBLIC_SHA" public_after
pin "$SITE" "$SITE_SHA" site_after
pin "$WEB/api/v5/.htaccess" "$V5_PUBLIC_REWRITE_SHA" public_v5_rewrite_after
pin "$WEB/api/v4/router.php" "$V4_SHA" v4_after
pin "$WEB/api/v4/.htaccess" "$V4_REWRITE_SHA" v4_htaccess_after
pin "$WEB/.htaccess" "$ROOT_REWRITE_SHA" root_htaccess_after
[[ "$(hash "$INNER")" == "$NEW_ROUTER_SHA" ]] ||
  fail 'new V5 code changed during verification'
cmp -s "$MANIFEST" "$BACKUP/manifest.before" ||
  fail 'legacy installed SHA manifest changed'
ORIGIN_ACCEPTED=YES
echo 'PASS: current legal and registration documents + validation from real Bitrix'
echo 'V5_AUTH_ON_ORIGIN=NO'
echo 'PUBLIC_V5_SHIM_CHANGED=NO'
echo 'V4_CHANGED=NO'
echo 'NOTE: evrasia.rest cannot be certified from the host due possible hairpin; independent external smoke still required'
