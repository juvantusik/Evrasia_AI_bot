#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
CAND='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/candidates/6fa8773746e9a40e22008d67636c896eefeec3ed'
OFF='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
SRC_COMMIT='b7e426c1aa2d63d24c018fdca5e98ecac7fd9b72'
BASE="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$SRC_COMMIT/site-integration/samzaberu-v5"
WORK=''
TEST='NOT_RUN'

finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$WORK" && "$WORK" == /tmp/ev-v5-profile.???????? &&
          -d "$WORK" && ! -L "$WORK" ]]; then
        rm -r -- "$WORK"
    fi
    echo
    echo '=== 5. RESULT ==='
    echo "INDEPENDENT_PROFILE_MVP_TEST=$TEST"
    echo 'MODE=GITHUB_SOURCE_OFFLINE'
    echo 'V4_RUNTIME_IMPORTED=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'SYNTHETIC_GUEST_CREATED=NO'
    echo 'SMS_SENT=NO'
    echo 'SECRETS_PRINTED=NO'
    if ((rc == 0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
}
trap finish EXIT

fail() { echo "FAIL: $1"; exit 2; }

echo '=== 1. ENVIRONMENT ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] || fail 'wrong host/user'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP unavailable'
id site_evrasia >/dev/null || fail 'site user unavailable'
for command in git curl runuser sha256sum timeout; do
    command -v "$command" >/dev/null || fail "missing command: $command"
done
[[ -d "$CAND/src" && ! -L "$CAND" && ! -L "$CAND/src" ]] ||
    fail 'V5 candidate missing'
(
    cd "$CAND"
    sha256sum -c --status SOURCE_SHA256
) || fail 'candidate changed'
printf '%s  %s\n' \
'5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d' \
"$SITE/api/v4/router.php" | sha256sum -c --status ||
    fail 'V4 router changed'
printf '%s  %s\n' \
'f502ae4718a3193d2765d30b75dff638a684cb75078534fecfd3524dce3b94d8' \
"$SITE/api/v5/router.php" | sha256sum -c --status ||
    fail 'V5 router changed'
printf '%s\n' \
'{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 unexpectedly enabled'
echo 'PASS: production unchanged, V5 OFF'

echo
echo '=== 2. GET INDEPENDENT V5 PROFILE SOURCE ==='
WORK="$(mktemp -d /tmp/ev-v5-profile.XXXXXXXX)"
mkdir -- "$WORK/src" "$WORK/tests"
cp -- "$CAND"/src/*.php "$WORK/src/"
while read -r file expected; do
    curl -fLsS --connect-timeout 8 --max-time 30 \
        "$BASE/$file" -o "$WORK/$file" || fail "fetch $file"
    [[ "$(git hash-object "$WORK/$file")" == "$expected" ]] ||
        fail "source mismatch $file"
done <<'FILES'
src/V5RouteMap.php b6e67ce8008f0b8fe179cfc3ec7202c7251b91e4
src/V5MySqlStrongSessionStore.php 7c0779ea12c2e15cac36f4e2e9a200c065cb3331
src/V5AuthHttpHandler.php 5c3f8b299ec2faa052e9299779795dfeb73e4671
src/V5HttpTransportBoundary.php 09e2de9367ff8d57fe8bf723790870f84703b3fb
src/V5ParallelHttpEntry.php 243fc0eb34202007d98c823b9cca23a55f0c779d
src/V5ProductionNativeRequestBoundaryGuard.php 0e720abaaf1293b4a4e67b51f9d4050842043e65
src/V5ProductionNativeTransactionPreflight.php 9e295478a589c3e4ebed29dfa1ac7142c16b77e2
src/V5MySqlPublicComposition.php e7dc7d176b83029fbbc599a9c2cd17bf101addf6
src/V5MySqlPilotComposition.php 98b95e89fe9255cc495cd7e1f6b7dc19f2abf1db
src/V5MySqlProfileRead.php cacc66ea043c652f62d837657ce26543191daa93
src/V5BitrixLockedProfileReader.php fdc41da315c358df0f992d5efb3d260f9501b92c
tests/V5IndependentProfileHttpTest.php 3b958607a8281a666faa31b0d4ea785178a774fb
FILES
chown -R root:site_evrasia "$WORK"
chmod 750 "$WORK" "$WORK/src" "$WORK/tests"
chmod 640 "$WORK"/src/*.php "$WORK"/tests/*.php
echo 'PASS: independent native V5 sources verified'

echo
echo '=== 3. FUNCTIONAL PROFILE ROUTE ==='
for file in "$WORK"/src/*.php "$WORK"/tests/*.php; do
    /usr/bin/php8.3 -l "$file" >/dev/null || fail "PHP syntax: $(basename "$file")"
done
echo 'PASS: PHP syntax'
TEST='FAIL'
timeout 20 runuser -u site_evrasia -- \
    /usr/bin/php8.3 -d auto_prepend_file= -d auto_append_file= \
    -d display_errors=0 -d log_errors=0 \
    "$WORK/tests/V5IndependentProfileHttpTest.php" ||
    fail 'V5 profile HTTP functional contract'
TEST='PASS'

echo
echo '=== 4. VERIFY ==='
(
    cd "$CAND"
    sha256sum -c --status SOURCE_SHA256
) || fail 'private candidate altered'
printf '%s  %s\n' \
'5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d' \
"$SITE/api/v4/router.php" | sha256sum -c --status ||
    fail 'V4 modified'
echo 'PASS: V4 and V5 unchanged'
echo 'PASS: no installation or authentication enabled'
