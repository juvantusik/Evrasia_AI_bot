#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Always clear screen AND scrollback before printing any old diagnostic data.
printf '\033[3J\033[2J\033[H'

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
PIN='6fa8773746e9a40e22008d67636c896eefeec3ed'
CAND="/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/candidates/$PIN"
OFF='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
SOURCE_COMMIT='0ce0c720c6745c7ec54ca096ee35a2ccbed52862'
BASE="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$SOURCE_COMMIT/site-integration/samzaberu-v5"

WORK=''
V4_SHA=''
TEST='NOT_RUN'

finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$WORK" && "$WORK" == /tmp/ev-v5-factory-scope.???????? &&
          -d "$WORK" && ! -L "$WORK" ]]; then
        for f in "$WORK"/src/*.php "$WORK"/tests/*.php; do
            if [[ -f "$f" && ! -L "$f" ]]; then
                rm -f -- "$f"
            fi
        done
        rmdir -- "$WORK/src" "$WORK/tests" "$WORK" 2>/dev/null || true
    fi
    echo
    echo '=== 5. RESULT ==='
    echo 'MODE=OFFLINE_REQUEST_SCOPE'
    echo "FACTORY_SCOPE_TEST=$TEST"
    echo 'BITRIX_BOOTSTRAP=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'API_V4_WRITE=NO'
    echo 'API_V5_WRITE=NO'
    echo 'PRODUCTION_PERMIT_INSTALLED=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'SYNTHETIC_GUEST_ACCOUNTS=NO'
    echo 'SMS_SENT=NO'
    echo 'EMAIL_SENT=NO'
    echo 'SECRETS_PRINTED=NO'
    if ((rc == 0)); then
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
[[ "$(hostname -s)" == 'evrasia' && "$(id -u)" == 0 ]] ||
    fail 'wrong host or user'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP 8.3 missing'
id site_evrasia >/dev/null || fail 'site user missing'
for command in git curl timeout runuser sha256sum; do
    command -v "$command" >/dev/null || fail "missing command: $command"
done
[[ -d "$CAND" && ! -L "$CAND" && -f "$CAND/CODE_COMMIT" ]] ||
    fail 'private candidate missing'
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
echo 'PASS: production boundary and V5 OFF'

echo
echo '=== 2. PREPARE EXACT FEATURE SOURCES ==='
WORK="$(mktemp -d /tmp/ev-v5-factory-scope.XXXXXXXX)"
mkdir -- "$WORK/src" "$WORK/tests"
chown -R root:site_evrasia "$WORK"
chmod 750 "$WORK" "$WORK/src" "$WORK/tests"

for source in "$CAND"/src/*.php; do
    [[ -f "$source" && ! -L "$source" ]] ||
        fail 'unexpected candidate PHP source'
    cp -- "$source" "$WORK/src/$(basename "$source")"
done

while read -r file hash; do
    dest="$WORK/$file"
    curl -fLsS --connect-timeout 8 --max-time 30 \
        "$BASE/$file" -o "$dest" || fail 'GitHub source download'
    [[ "$(git hash-object "$dest")" == "$hash" ]] ||
        fail 'GitHub blob mismatch'
done <<'PINNED_FILES'
src/V5ParallelHttpEntry.php 8021d9f1589b36269a7718b6101091fa4e060d9a
src/V5PrivatePilotConfig.php bc92665e0c0a6951fdd149152ca70ae86ac68b12
tests/V5FactoryRequestScopeTest.php 7b605995f9197f3ec34445302cab9731983c09f3
PINNED_FILES

chown -R root:site_evrasia "$WORK"
chmod 640 "$WORK"/src/*.php "$WORK"/tests/*.php
echo 'PASS: private candidate unchanged'
echo 'PASS: exact three feature sources'

echo
echo '=== 3. PHP / REQUEST SCOPE ==='
for file in \
    "$WORK/src/V5ParallelHttpEntry.php" \
    "$WORK/src/V5PrivatePilotConfig.php" \
    "$WORK/tests/V5FactoryRequestScopeTest.php"
do
    /usr/bin/php8.3 -l "$file" >/dev/null ||
        fail "PHP syntax: $(basename "$file")"
done
echo 'PASS: PHP syntax'
TEST='FAIL'
timeout 20 runuser -u site_evrasia -- \
    /usr/bin/php8.3 \
    -d auto_prepend_file= \
    -d auto_append_file= \
    -d display_errors=0 \
    -d log_errors=0 \
    "$WORK/tests/V5FactoryRequestScopeTest.php" ||
    fail 'typed request factory regression'
TEST='PASS'

echo
echo '=== 4. VERIFY ==='
[[ "$(sha256sum "$SITE/api/v4/router.php" | awk '{print $1}')" == "$V4_SHA" ]] ||
    fail 'V4 router modified'
printf '%s  %s\n' \
'f502ae4718a3193d2765d30b75dff638a684cb75078534fecfd3524dce3b94d8' \
"$SITE/api/v5/router.php" | sha256sum -c --status ||
    fail 'V5 public router modified'
(
    cd "$CAND"
    sha256sum -c --status SOURCE_SHA256
) || fail 'private V5 candidate modified'
echo 'PASS: V4 unchanged'
echo 'PASS: V5 unchanged and OFF'
echo 'PASS: no permanent deployment'
