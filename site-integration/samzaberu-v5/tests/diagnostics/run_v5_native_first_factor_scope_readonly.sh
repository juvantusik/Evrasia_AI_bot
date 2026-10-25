#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
PIN='6fa8773746e9a40e22008d67636c896eefeec3ed'
CAND="/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/candidates/$PIN"
OFF='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
SOURCE_COMMIT='1215c0c41873e5055a827fef74d41638c15a9e83'
BASE="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$SOURCE_COMMIT/site-integration/samzaberu-v5"
WORK=''
TEST='NOT_RUN'
V4_SHA=''

finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$WORK" && "$WORK" == /tmp/ev-v5-native-first.???????? &&
          -d "$WORK" && ! -L "$WORK" ]]; then
        rm -r -- "$WORK"
    fi
    echo
    echo '=== 5. RESULT ==='
    echo "NATIVE_FIRST_FACTOR_REQUEST_SCOPE=$TEST"
    echo 'MODE=OFFLINE_READ_ONLY'
    echo 'BITRIX_BOOTSTRAP=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'API_V4_WRITE=NO'
    echo 'API_V5_WRITE=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'SMS_SENT=NO'
    echo 'EMAIL_SENT=NO'
    echo 'SYNTHETIC_GUEST_ACCOUNTS=NO'
    echo 'PRODUCTION_PERMIT_INSTALLED=NO'
    echo 'SECRETS_PRINTED=NO'
    if ((rc==0)); then
        echo 'FINAL_STATUS=PASS'
    else
        echo 'FINAL_STATUS=FAIL'
    fi
    echo "FINAL_RC=$rc"
}
trap finish EXIT

fail() {
    echo "FAIL: $1"
    exit 2
}

echo '=== 1. HOST / ENVIRONMENT ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] ||
    fail 'wrong host or user'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP 8.3 unavailable'
id site_evrasia >/dev/null || fail 'site user missing'
for command in git curl sha256sum runuser timeout; do
    command -v "$command" >/dev/null || fail "command missing: $command"
done
[[ -d "$CAND/src" && ! -L "$CAND" && ! -L "$CAND/src" ]] ||
    fail 'candidate missing'
[[ "$(cat "$CAND/CODE_COMMIT")" == "$PIN" ]] ||
    fail 'candidate fingerprint changed'
(
    cd "$CAND"
    sha256sum -c --status SOURCE_SHA256
) || fail 'private candidate integrity'
[[ -f "$SITE/api/v4/router.php" && ! -L "$SITE/api/v4/router.php" ]] ||
    fail 'V4 router missing'
V4_SHA="$(sha256sum "$SITE/api/v4/router.php" | awk '{print $1}')"
printf '%s  %s\n' \
'f502ae4718a3193d2765d30b75dff638a684cb75078534fecfd3524dce3b94d8' \
"$SITE/api/v5/router.php" | sha256sum -c --status ||
    fail 'public V5 router changed'
printf '%s\n' \
'{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 no longer OFF'
echo 'PASS: site production guard'
echo 'PASS: V4/V5 OFF and pinned candidate'

echo
echo '=== 2. PREPARE EXACT SOURCE OVERLAY ==='
WORK="$(mktemp -d /tmp/ev-v5-native-first.XXXXXXXX)"
mkdir -- "$WORK/src" "$WORK/tests"
cp -- "$CAND"/src/*.php "$WORK/src/"
while read -r path expected; do
    curl -fLsS --connect-timeout 8 --max-time 30 \
        "$BASE/$path" -o "$WORK/$path" || fail 'GitHub download'
    [[ "$(git hash-object "$WORK/$path")" == "$expected" ]] ||
        fail "blob mismatch: $path"
done <<'FILES'
src/V5BitrixNativeFirstFactorComposition.php 564aa1a1b0ab01e01e1da1f1cafc8cb76b36701b
tests/V5NativeFirstFactorRequestScopeTest.php 55f17e486e8317562344f6fff41064d0c4f31208
FILES
chown -R root:site_evrasia "$WORK"
chmod 750 "$WORK" "$WORK/src" "$WORK/tests"
chmod 640 "$WORK"/src/*.php "$WORK"/tests/*.php
echo 'PASS: immutable source verified'
echo 'PASS: no production installation'

echo
echo '=== 3. NEW FIRST-FACTOR SCOPE TEST ==='
for file in \
    "$WORK/src/V5BitrixNativeFirstFactorComposition.php" \
    "$WORK/tests/V5NativeFirstFactorRequestScopeTest.php"; do
    /usr/bin/php8.3 -l "$file" >/dev/null ||
        fail "PHP syntax: $(basename "$file")"
done
echo 'PASS: targeted PHP syntax'
TEST='FAIL'
timeout 20 runuser -u site_evrasia -- /usr/bin/php8.3 \
    -d auto_prepend_file= \
    -d auto_append_file= \
    -d display_errors=0 \
    -d log_errors=0 \
    "$WORK/tests/V5NativeFirstFactorRequestScopeTest.php" ||
    fail 'native first-factor request test'
TEST='PASS'

echo
echo '=== 4. VERIFY ==='
[[ "$(sha256sum "$SITE/api/v4/router.php" | awk '{print $1}')" == "$V4_SHA" ]] ||
    fail 'V4 changed'
printf '%s  %s\n' \
'f502ae4718a3193d2765d30b75dff638a684cb75078534fecfd3524dce3b94d8' \
"$SITE/api/v5/router.php" | sha256sum -c --status ||
    fail 'V5 public router changed'
(
    cd "$CAND"
    sha256sum -c --status SOURCE_SHA256
) || fail 'V5 candidate changed'
echo 'PASS: V4 unchanged'
echo 'PASS: V5 AUTH OFF, candidate unchanged'
