#!/usr/bin/env bash
# Two anonymous HTTPS GETs against the installed, disabled V5 entry only.
set -Eeuo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
BASE=/home/site_evrasia/web/evrasia.spb.ru
DOCROOT="$BASE/public_html"
V5HOME="$BASE/private/samzaberu-v5"
CODE=0caa0248c783188939398a71543ba740f064bb90
RELEASE="$V5HOME/releases/$CODE"
BACKUP=/root/ev-v5-off-backup.f89wzMkA
WORK='' HTTP_COUNT=0 HTTP_PASS=0
fail() { printf 'FAIL: %s\n' "$1" >&2; exit 2; }
finish() {
  rc=$?
  trap - EXIT
  set +e
  if [ -n "$WORK" ] && [ -d "$WORK" ] && [ ! -L "$WORK" ]; then
    case "$WORK" in /tmp/ev-v5-http.????????)
      rm -f -- "$WORK/headers" "$WORK/body" "$WORK/meta" "$WORK/error" "$WORK/header-check.awk"
      rmdir -- "$WORK"
      ;; esac
  fi
  printf '\n=== 5. RESULT ===\n'
  if [ "$rc" -eq 0 ]; then echo FINAL_STATUS=PASS; else echo FINAL_STATUS=FAIL; fi
  printf 'FINAL_RC=%s\nHTTP_REQUESTS=%s\nHTTP_OFF_PATHS_PASS=%s\n' "$rc" "$HTTP_COUNT" "$HTTP_PASS"
  printf 'APPLICATION_WRITE=NO\nDIRECT_DATABASE_CONNECTION=NO\nSERVICE_RELOAD=NO\nPILOT_ACTIVATION_PERFORMED=NO\n'
  printf 'WEB_ACCESS_LOG_MAY_CHANGE=YES\nNATIVE_SIDE_EFFECTS_INSTRUMENTED=NO\n'
  printf 'PHP_HTTPS_AND_PEER_MAPPING=NOT_ATTESTED\nV4_HTTP_FUNCTIONAL_TEST=NOT_RUN\n'
  exit "$rc"
}
trap finish EXIT
plain() {
  [ -f "$1" ] && [ ! -L "$1" ] && [ "$(stat -c %h "$1")" = 1 ] \
    && [ "$(realpath -e "$1")" = "$1" ] || fail 'Unsafe file path'
}
check_state() {
  local expected rel
  while read -r expected rel; do
    plain "$DOCROOT/$rel"
    [ "$(sha256sum "$DOCROOT/$rel" | awk '{print $1}')" = "$expected" ] || fail "Baseline changed: $rel"
  done <<'PINS'
81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95 .htaccess
61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3 api/v4/.htaccess
3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50 api/v4/router.php
PINS
  [ ! -e "$DOCROOT/api/.htaccess" ] && [ ! -L "$DOCROOT/api/.htaccess" ] || fail 'Shared API rewrite changed'
  plain "$V5HOME/installed.sha256"
  [ "$(stat -c '%u:%a' "$V5HOME/installed.sha256")" = 0:600 ] || fail 'Untrusted installation manifest'
  sha256sum -c --status "$V5HOME/installed.sha256" || fail 'Installed package drift'
  plain "$RELEASE/site-config.json"
  printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' \
    | cmp -s - "$RELEASE/site-config.json" || fail 'V5 no longer in exact installed OFF state'
  [ ! -e "$RELEASE/site-factory.php" ] && [ ! -L "$RELEASE/site-factory.php" ] || fail 'Native factory appeared; stop OFF-only check'
  [ "$(cat "$RELEASE/CODE_COMMIT")" = "$CODE" ] || fail 'Unexpected release'
  [ "$(find "$DOCROOT/api/v5" -mindepth 1 -maxdepth 1 | wc -l)" -eq 2 ] || fail 'Unexpected public files'
}
printf '=== 1. HOST / ENVIRONMENT ===\nHOST=%s\nUSER=%s\n' "$(hostname -s)" "$(id -un)"
printf 'MODE=READ_ONLY_OFF_HTTP_CHECK\nSITE_FILE_WRITE=NO\nTEMPORARY_FILES_ONLY=YES\nCREDENTIALS_SENT=NO\n'
[ "$(hostname -s)" = evrasia ] && [ "$(id -u)" -eq 0 ] || fail 'Run as root on evrasia'
for cmd in curl sha256sum cmp stat realpath awk find wc mktemp; do
  command -v "$cmd" >/dev/null || fail "Missing tool: $cmd"
done
printf '\n=== 2. GUARDS ===\n'
for dir in "$DOCROOT" "$DOCROOT/api/v5" "$V5HOME" "$RELEASE" "$BACKUP"; do
  [ -d "$dir" ] && [ ! -L "$dir" ] && [ "$(realpath -e "$dir")" = "$dir" ] || fail 'Unexpected directory'
done
check_state
plain "$BACKUP/rollback.sh"
bash -n "$BACKUP/rollback.sh"
plain "$BACKUP/backup.sha256"
sha256sum -c --status "$BACKUP/backup.sha256" || fail 'Backup checksum mismatch'
echo 'PASS: OFF package, V4 baseline and saved rollback verified'
WORK="$(mktemp -d /tmp/ev-v5-http.XXXXXXXX)"
cat > "$WORK/header-check.awk" <<'AWK'
BEGIN { type=0; cache=0; cookie=0; redirect=0; cors=0; age=0; seen=0 }
{ sub(/\r$/, ""); line=tolower($0) }
line ~ /^http\/[0-9.]+[ \t]+[0-9][0-9][0-9]/ { type=0; cache=0; seen=1 }
line ~ /^content-type:[ \t]*application\/json([; \t]|$)/ { type=1 }
line ~ /^cache-control:/ && line ~ /(^|[ ,:\t])no-store([ ,\t]|$)/ { cache=1 }
line ~ /^set-cookie:/ { cookie=1 }
line ~ /^location:/ { redirect=1 }
line ~ /^access-control-allow-origin:/ { cors=1 }
line ~ /^age:/ { split(line,a,":"); if ((a[2]+0)>0) age=1 }
END {
  printf "JSON_CONTENT_TYPE=%s\nCACHE_NO_STORE=%s\n", type?"YES":"NO", cache?"YES":"NO"
  printf "SET_COOKIE_PRESENT=%s\nREDIRECT_PRESENT=%s\n", cookie?"YES":"NO", redirect?"YES":"NO"
  printf "CORS_HEADER_PRESENT=%s\nCACHED_AGE_PRESENT=%s\n", cors?"YES":"NO", age?"YES":"NO"
  exit !(seen && type && cache && !cookie && !redirect && !cors && !age)
}
AWK
probe() {
  local path="$1" code tls extra curl_rc=0 body_ok=NO headers_ok=YES
  case "$path" in /api/v5/router.php|/api/v5/auth/signin) ;; *) fail 'Unexpected HTTP target';; esac
  check_state
  printf 'PATH=%s\n' "$path"
  HTTP_COUNT=$((HTTP_COUNT+1))
  # No curlrc, proxy environment, cookies, authorization, redirects or retries.
  # Cap temporary output also on older curl versions with partial size checks.
  ( ulimit -f 64
    exec env -i PATH="$PATH" LC_ALL=C HOME="$WORK" curl -q --silent \
      --noproxy '*' --proto '=https' --max-redirs 0 \
      --connect-timeout 5 --max-time 20 --max-filesize 16384 \
      --header 'Accept: application/json' --header 'Cache-Control: no-cache' \
      --header 'Pragma: no-cache' --user-agent 'Evrasia-V5-Off-Check/1' \
      --dump-header "$WORK/headers" --output "$WORK/body" \
      --write-out '%{http_code} %{ssl_verify_result}\n' \
      "https://evrasia.rest$path"
  ) > "$WORK/meta" 2> "$WORK/error" || curl_rc=$?
  printf 'CURL_RC=%s\n' "$curl_rc"
  [ "$curl_rc" -eq 0 ] || fail 'HTTPS transport failed; no redirect or insecure retry attempted'
  read -r code tls extra < "$WORK/meta"
  [[ "$code" =~ ^[0-9]{3}$ ]] && [[ "$tls" =~ ^[0-9]+$ ]] && [ -z "$extra" ] || fail 'Invalid curl metadata'
  if printf '%s' '{"error":"v5_unavailable"}' | cmp -s - "$WORK/body"; then body_ok=YES; fi
  printf 'HTTP_STATUS=%s\nTLS_VERIFY_RESULT=%s\nEXPECTED_OFF_JSON=%s\n' "$code" "$tls" "$body_ok"
  awk -f "$WORK/header-check.awk" "$WORK/headers" || headers_ok=NO
  [ "$code" = 503 ] && [ "$tls" = 0 ] && [ "$body_ok" = YES ] && [ "$headers_ok" = YES ] \
    || fail 'Unexpected HTTP response; raw headers/body withheld; later requests skipped'
  HTTP_PASS=$((HTTP_PASS+1))
  echo 'PASS: HTTPS returned the expected disabled V5 response'
}
printf '\n=== 3. PUBLIC HTTPS: DIRECT ENTRY THEN NESTED ROUTE ===\n'
probe /api/v5/router.php
probe /api/v5/auth/signin
printf '\n=== 4. VERIFY ===\n'
check_state
printf 'PASS: package, OFF configuration, V4 and shared rewrite unchanged\n'
printf 'V5_INSTALLED_DISABLED=YES\nV5_MODE=off\nHTTP_OFF_BOUNDARY=PASS\n'
printf 'REAL_NATIVE_AUTHENTICATION_TEST=NOT_RUN\n'
