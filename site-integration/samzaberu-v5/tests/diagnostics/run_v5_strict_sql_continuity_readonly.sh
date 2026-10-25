#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
PIN='6fa8773746e9a40e22008d67636c896eefeec3ed'
V5="/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/candidates/$PIN"
COMMIT='75a00c6cc4fe374ba3e872ae93ed5337aa9f1214'
BASE="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$COMMIT/site-integration/samzaberu-v5"
V4="$SITE/api/v4/router.php"
V5_ROUTER="$SITE/api/v5/router.php"
OFF="/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json"
ROUTER_SHA='f502ae4718a3193d2765d30b75dff638a684cb75078534fecfd3524dce3b94d8'
PHP='/usr/bin/php8.3'
WORK=''
V4_SHA=''
STATUS='NOT_RUN'

finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$WORK" && "$WORK" == /tmp/ev-v5-sql-continuity.???????? &&
          -d "$WORK" && ! -L "$WORK" ]]; then
        for file in \
            V5BitrixPdoCompatibilityBridge.php \
            V5StagingNativeTransactionPermit.php \
            V5VerifiedBitrixNativeTransactionDepth.php \
            V5ProductionNativeTransactionContinuity.php \
            v5_native_continuity_readonly.php
        do
            if [[ -f "$WORK/$file" && ! -L "$WORK/$file" ]]; then
                rm -f -- "$WORK/$file"
            fi
        done
        rmdir -- "$WORK" 2>/dev/null || true
    fi
    echo
    echo '=== 5. RESULT ==='
    echo 'MODE=REAL_BITRIX_READ_ONLY_SQL_CONTINUITY'
    echo "STRICT_SQL_CONTINUITY_TEST=$STATUS"
    echo 'DATABASE_WRITE_BY_PROBE=NO'
    echo 'USER_RECORDS_READ_BY_PROBE=NO'
    echo 'BITRIX_BOOTSTRAP_SIDE_EFFECTS=NOT_ATTESTED'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'API_V4_WRITE=NO'
    echo 'PUBLIC_V5_ROUTER_WRITE=NO'
    echo 'PRIVATE_V5_CANDIDATE_WRITE=NO'
    echo 'PRODUCTION_EXCLUSIVE_PERMIT_INSTALLED=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'SMS_SENT_BY_PROBE=NO'
    echo 'EMAIL_SENT_BY_PROBE=NO'
    echo 'SECRETS_PRINTED=NO'
    if ((rc == 0)); then
        echo 'FINAL_STATUS=PASS'
    else
        echo 'FINAL_STATUS=FAIL'
    fi
    echo "FINAL_RC=$rc"
}

trap finish EXIT

fail() { echo "FAIL: $1"; exit 2; }

echo '=== 1. HOST / SOURCE GUARDS ==='
[[ "$(hostname -s)" == evrasia ]] || fail 'wrong host'
[[ "$(id -u)" == 0 ]] || fail 'root required'
[[ -x "$PHP" ]] || fail 'PHP 8.3 unavailable'
id site_evrasia >/dev/null || fail 'site user missing'
for command in curl git runuser timeout sha256sum; do
    command -v "$command" >/dev/null || fail "missing command: $command"
done
[[ -d "$V5" && ! -L "$V5" ]] || fail 'private V5 candidate missing'
[[ "$(cat "$V5/CODE_COMMIT")" == "$PIN" ]] || fail 'candidate version changed'
(
    cd "$V5"
    sha256sum -c --status SOURCE_SHA256
) || fail 'candidate integrity'
printf '%s  %s\n' "$ROUTER_SHA" "$V5_ROUTER" | sha256sum -c --status ||
    fail 'public V5 router changed'
[[ -f "$V4" && ! -L "$V4" ]] || fail 'V4 missing'
V4_SHA="$(sha256sum "$V4" | awk '{print $1}')"
printf '%s\n' \
'{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 OFF state changed'
echo 'PASS: production guards'
echo 'PASS: V4/V5 OFF boundaries'
echo 'PASS: candidate integrity'

echo
echo '=== 2. FETCH EXACT TEST SOURCES ==='
WORK="$(mktemp -d /tmp/ev-v5-sql-continuity.XXXXXXXX)"
chown root:site_evrasia "$WORK"
chmod 750 "$WORK"

for file in \
    V5StagingNativeTransactionPermit.php \
    V5VerifiedBitrixNativeTransactionDepth.php
do
    [[ -f "$V5/src/$file" && ! -L "$V5/src/$file" ]] ||
        fail "source dependency missing: $file"
    cp -- "$V5/src/$file" "$WORK/$file"
done

for file in \
    V5BitrixPdoCompatibilityBridge.php \
    V5ProductionNativeTransactionContinuity.php
do
    curl -fLsS --connect-timeout 8 --max-time 30 \
        "$BASE/src/$file" -o "$WORK/$file" ||
        fail "download failed: $file"
done

curl -fLsS --connect-timeout 8 --max-time 30 \
    "$BASE/tests/diagnostics/v5_native_continuity_readonly.php" \
    -o "$WORK/v5_native_continuity_readonly.php" ||
    fail 'test download failed'

check_blob() {
    [[ "$(git hash-object "$WORK/$1")" == "$2" ]] ||
        fail "Git blob mismatch: $1"
}

check_blob V5BitrixPdoCompatibilityBridge.php \
    f7fc7474a717320f8a4424fda67b84bc7ea066c9
check_blob V5ProductionNativeTransactionContinuity.php \
    e4f1366049a58448db1ce92df535933719a464ef
check_blob v5_native_continuity_readonly.php \
    eaaecf125b63d215aa1f5b6ef9b2cce779165972

chown root:site_evrasia "$WORK"/*.php
chmod 640 "$WORK"/*.php
for file in "$WORK"/*.php; do
    "$PHP" -l "$file" >/dev/null || fail "PHP syntax: $(basename "$file")"
done
echo 'PASS: immutable GitHub blobs verified'
echo 'PASS: PHP syntax'
echo 'PASS: temporary CLI-only files'

echo
echo '=== 3. REAL NATIVE STRICT STATEMENT CONTINUITY ==='
STATUS='FAIL'
timeout 40 runuser -u site_evrasia -- \
    "$PHP" -d auto_prepend_file= -d auto_append_file= \
    -d display_errors=0 -d log_errors=0 \
    "$WORK/v5_native_continuity_readonly.php" "$SITE" "$WORK" ||
    fail 'strict native SQL continuity test failed'
STATUS='PASS'

echo
echo '=== 4. VERIFY PRODUCTION BOUNDARIES ==='
[[ "$(sha256sum "$V4" | awk '{print $1}')" == "$V4_SHA" ]] ||
    fail 'V4 changed'
printf '%s  %s\n' "$ROUTER_SHA" "$V5_ROUTER" |
    sha256sum -c --status || fail 'V5 router changed'
printf '%s\n' \
'{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' |
    cmp -s - "$OFF" || fail 'V5 OFF state changed'
(
    cd "$V5"
    sha256sum -c --status SOURCE_SHA256
) || fail 'private candidate changed'
echo 'PASS: V4 unchanged'
echo 'PASS: V5 remains OFF'
echo 'PASS: private candidate unchanged'
echo 'PASS: no permanent installation'
