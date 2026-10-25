#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
CAND='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/candidates/6fa8773746e9a40e22008d67636c896eefeec3ed'
OFF='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
COMMIT='55eedd7346800b0dc12e72bc484835a67c1ca431'
BASE="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$COMMIT/site-integration/samzaberu-v5"
WORK=''
TEST='NOT_RUN'
finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$WORK" && "$WORK" == /tmp/ev-v5-order-detail.???????? &&
        -d "$WORK" && ! -L "$WORK" ]]; then
        rm -r -- "$WORK"
    fi
    echo
    echo '=== 5. RESULT ==='
    echo "V5_ORDER_DETAIL_MVP=$TEST"
    echo 'MODE=OFFLINE_FUNCTIONAL'
    echo 'V4_RUNTIME_IMPORTED=NO'
    echo 'BITRIX_BOOTSTRAP=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'SYNTHETIC_GUEST_CREATED=NO'
    echo 'SMS_SENT=NO'
    echo 'SECRETS_PRINTED=NO'
    if ((rc == 0)); then echo 'FINAL_STATUS=PASS'
    else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
}
trap finish EXIT
fail() { echo "FAIL: $1"; exit 2; }

echo '=== 1. ENVIRONMENT ==='
[[ "$(hostname -s)" == 'evrasia' && "$(id -u)" == 0 ]] ||
    fail 'wrong host/user'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP unavailable'
id site_evrasia >/dev/null || fail 'site user missing'
for command in git curl sha256sum runuser timeout; do
    command -v "$command" >/dev/null || fail "missing $command"
done
[[ -d "$CAND/src" && ! -L "$CAND" && ! -L "$CAND/src" ]] ||
    fail 'V5 source candidate missing'
(
    cd "$CAND"
    sha256sum -c --status SOURCE_SHA256
) || fail 'V5 candidate changed'
printf '%s  %s\n' \
'5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d' \
"$SITE/api/v4/router.php" | sha256sum -c --status || fail 'V4 router changed'
printf '%s  %s\n' \
'f502ae4718a3193d2765d30b75dff638a684cb75078534fecfd3524dce3b94d8' \
"$SITE/api/v5/router.php" | sha256sum -c --status || fail 'V5 router changed'
printf '%s\n' \
'{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 unexpectedly enabled'
echo 'PASS: V4 unchanged, V5 AUTH OFF'

echo
echo '=== 2. FETCH NEW V5 ORDER DETAIL SOURCES ==='
WORK="$(mktemp -d /tmp/ev-v5-order-detail.XXXXXXXX)"
mkdir -- "$WORK/src" "$WORK/tests"
cp -- "$CAND"/src/*.php "$WORK/src/"
while read -r file expected; do
    curl -fLsS --connect-timeout 8 --max-time 30 \
        "$BASE/$file" -o "$WORK/$file" || fail "GitHub fetch: $file"
    [[ "$(git hash-object "$WORK/$file")" == "$expected" ]] ||
        fail "source mismatch: $file"
done <<'FILES'
src/V5BitrixLockedOrderDetailReader.php 3aceebe8c35bfdcb69108316291fe1f55a4d5add
src/V5MySqlOrderDetailRead.php 1a75fdd4e9cac3c7307702f64c95752218a547c2
src/V5MySqlStrongSessionStore.php 4e80b4f443990a02e51dd48c3393301ea52d13e1
src/V5AuthHttpHandler.php c6305ba7d03140358b4175e405874733bb4bb2cc
src/V5RouteMap.php e4ec25713585e0a3f4ef3c22b60a2eb09a04c7f2
src/V5HttpTransportBoundary.php 4a047a6b1ac7ccf644ff5371f7cceef6785b7bb5
src/V5ParallelHttpEntry.php e8d7084d8ab415a723ece3977152238b536853c8
src/V5ProductionNativeRequestBoundaryGuard.php 62fa1abb9b7a9d6689df97e244ee98eba024ccba
src/V5MySqlPublicComposition.php 515b681d2628b8871fbc85719ef4d33eb8d7742e
src/V5MySqlPilotComposition.php 750669a2a848ba4a113f5d4e41cfa6880e0ef5a1
tests/V5IndependentOrderDetailMvpTest.php 58476e73c1367de0cc95a802a68630e279199350
FILES
chown -R root:site_evrasia "$WORK"
chmod 750 "$WORK" "$WORK/src" "$WORK/tests"
chmod 640 "$WORK"/src/*.php "$WORK"/tests/*.php
echo 'PASS: exact new V5 order detail sources verified'

echo
echo '=== 3. FUNCTIONAL ORDER DETAIL TEST ==='
for file in \
    "$WORK/src/V5BitrixLockedOrderDetailReader.php" \
    "$WORK/src/V5MySqlOrderDetailRead.php" \
    "$WORK/src/V5MySqlStrongSessionStore.php" \
    "$WORK/src/V5AuthHttpHandler.php" \
    "$WORK/src/V5RouteMap.php" \
    "$WORK/src/V5HttpTransportBoundary.php" \
    "$WORK/src/V5ParallelHttpEntry.php" \
    "$WORK/src/V5MySqlPublicComposition.php" \
    "$WORK/src/V5MySqlPilotComposition.php" \
    "$WORK/tests/V5IndependentOrderDetailMvpTest.php"; do
    /usr/bin/php8.3 -l "$file" >/dev/null ||
        fail "PHP syntax: $(basename "$file")"
done
echo 'PASS: targeted PHP syntax'
TEST='FAIL'
timeout 20 runuser -u site_evrasia -- /usr/bin/php8.3 \
    -d auto_prepend_file= -d auto_append_file= \
    -d display_errors=0 -d log_errors=0 \
    "$WORK/tests/V5IndependentOrderDetailMvpTest.php" ||
    fail 'V5 order-detail functionality'
TEST='PASS'

echo
echo '=== 4. VERIFY ==='
(
    cd "$CAND"
    sha256sum -c --status SOURCE_SHA256
) || fail 'V5 private candidate changed'
printf '%s  %s\n' \
'5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d' \
"$SITE/api/v4/router.php" | sha256sum -c --status || fail 'V4 changed'
printf '%s\n' \
'{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 enabled'
echo 'PASS: V4 unchanged, V5 OFF, no installation'
