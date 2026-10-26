#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
DIR="$SITE/local/samzaberu-v5/src"
BRIDGE="$DIR/V5ProductionNativeSecurityEventBridge.php"
OTP="$DIR/V5BitrixOtpNativeMutationPort.php"
OLD_BRIDGE='f0fc371d395c7b5b90a5d8e8a4e858ab8d8ead5ea773d5a264ce7d113ae0a758'
NEW_BRIDGE='5a3151e46cdc8a1966f4cac095db16b863df7f508a425dfacf22fa8310afc16b'
OLD_OTP='5a3e42d3d8ef825d420612b131e723d370814b8fd9ed6a7ef12d39d03eb7d794'
NEW_OTP='d19767ad4761406a3ad7db4ca2c9f992f42554760d6d30773ec66dbaa09501de'
V4_SHA='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
V5_SHA='a134380714cb2299ee47eb46ab6b248985a863f8aa5b7104290bde14847fa266'
BRIDGE_COMMIT='e54e1ecc137fda7f46aba373237b8265645f5945'
OTP_COMMIT='20445e21c97ef021a472ee2e03d42fc7af5c45c3'
BASE='https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/'
STAGE_B=''
STAGE_O=''
BACKUP=''
B_CHANGED=NO
O_CHANGED=NO
ALREADY_APPLIED=NO
ORIGINAL_OTP=ABSENT
ORIGINAL_BRIDGE=''
ROLLBACK=NOT_REQUIRED
PASS_COUNT=0
FAIL_COUNT=0

sha() { sha256sum -- "$1" | cut -d ' ' -f1; }
pass() { echo "PASS: $1"; PASS_COUNT=$((PASS_COUNT + 1)); }
fail() { echo "FAIL: $1"; FAIL_COUNT=$((FAIL_COUNT + 1)); exit 2; }
finish() {
    local rc=$?
    trap - EXIT
    set +e
    if ((rc != 0)) && [[ "$B_CHANGED" == YES || "$O_CHANGED" == YES ]]; then
        ROLLBACK=PASS
        if [[ "$B_CHANGED" == YES ]]; then
            if [[ -f "$BRIDGE" && "$(sha "$BRIDGE")" == "$NEW_BRIDGE" ]]; then
                local tmp
                tmp="$(mktemp "$DIR/.ev-v5-rollback-b.XXXXXXXX.php")"
                cp -p -- "$BACKUP/bridge.php" "$tmp"
                mv -T -- "$tmp" "$BRIDGE"
                [[ "$(sha "$BRIDGE")" == "$ORIGINAL_BRIDGE" ]] || ROLLBACK=FAIL
            else
                ROLLBACK=FAIL
            fi
        fi
        if [[ "$O_CHANGED" == YES ]]; then
            if [[ -f "$OTP" && "$(sha "$OTP")" == "$NEW_OTP" ]]; then
                if [[ "$ORIGINAL_OTP" == ABSENT ]]; then
                    rm -f -- "$OTP"
                    [[ ! -e "$OTP" ]] || ROLLBACK=FAIL
                else
                    local tmp
                    tmp="$(mktemp "$DIR/.ev-v5-rollback-o.XXXXXXXX.php")"
                    cp -p -- "$BACKUP/otp.php" "$tmp"
                    mv -T -- "$tmp" "$OTP"
                    [[ "$(sha "$OTP")" == "$ORIGINAL_OTP" ]] || ROLLBACK=FAIL
                fi
            else
                ROLLBACK=FAIL
            fi
        fi
    fi
    [[ -z "$STAGE_B" ]] || rm -f -- "$STAGE_B"
    [[ -z "$STAGE_O" ]] || rm -f -- "$STAGE_O"
    echo '=== 5. RESULT ==='
    if ((rc == 0)); then echo FINAL_STATUS=PASS; else echo FINAL_STATUS=FAIL; fi
    echo "FINAL_RC=$rc"
    echo "PASS_COUNT=$PASS_COUNT"
    echo "FAIL_COUNT=$FAIL_COUNT"
    echo "ALREADY_APPLIED=$ALREADY_APPLIED"
    echo "ROLLBACK_STATUS=$ROLLBACK"
    echo "BACKUP_DIR=$BACKUP"
    echo MODE=V5_SOURCE_ONLY
    echo DATABASE_WRITE=NO
    echo API_V4_WRITE=NO
    echo PUBLIC_ROUTER_WRITE=NO
    echo V5_AUTH_ENABLED=NO
    echo NATIVE_OTP_MUTATION=NO
    echo SMS_SENT=NO
    echo SECRETS_PRINTED=NO
    echo PUTTY_SESSION=KEEP_OPEN
}
trap finish EXIT

echo '=== 1. GUARDS ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] || fail WRONG_HOST_OR_USER
[[ -d "$DIR" && ! -L "$DIR" ]] || fail V5_SOURCE_DIRECTORY
for c in php8.3 curl sha256sum mktemp flock; do
    command -v "$c" >/dev/null || fail "COMMAND_$c"
done
exec 9>/run/ev-v5-native-otp-source.lock
flock -n 9 || fail CONCURRENT_INSTALL
[[ "$(sha "$SITE/api/v4/router.php")" == "$V4_SHA" ]] || fail V4_ROUTER_CHANGED
[[ "$(sha "$SITE/api/v5/router.php")" == "$V5_SHA" ]] || fail V5_ROUTER_CHANGED
for f in V5SecurityEventVersions.php V5BitrixPdoCompatibilityBridge.php V5StagingNativeMutationScope.php V5StagingNativeTransactionPermit.php V5NativeMutationCoverage.php; do
    [[ -f "$DIR/$f" && ! -L "$DIR/$f" ]] || fail "MISSING_DEPENDENCY_$f"
done
[[ -f "$BRIDGE" && ! -L "$BRIDGE" ]] || fail BRIDGE_MISSING
ORIGINAL_BRIDGE="$(sha "$BRIDGE")"
[[ "$ORIGINAL_BRIDGE" == "$OLD_BRIDGE" || "$ORIGINAL_BRIDGE" == "$NEW_BRIDGE" ]] || fail BRIDGE_UNKNOWN_VERSION
if [[ -e "$OTP" || -L "$OTP" ]]; then
    [[ -f "$OTP" && ! -L "$OTP" ]] || fail OTP_UNSAFE
    ORIGINAL_OTP="$(sha "$OTP")"
    [[ "$ORIGINAL_OTP" == "$OLD_OTP" || "$ORIGINAL_OTP" == "$NEW_OTP" ]] || fail OTP_UNKNOWN_VERSION
fi
pass EXACT_SITE_AND_BASELINE
echo '=== 2. SOURCE AND SYNTAX ==='
STAGE_B="$(mktemp "$DIR/.ev-v5-bridge.XXXXXXXX.php")"
STAGE_O="$(mktemp "$DIR/.ev-v5-otp.XXXXXXXX.php")"
curl -q -fsSL --proto '=https' --proto-redir '=https' --max-time 30 -o "$STAGE_B" "$BASE$BRIDGE_COMMIT/site-integration/samzaberu-v5/src/V5ProductionNativeSecurityEventBridge.php" || fail BRIDGE_DOWNLOAD
curl -q -fsSL --proto '=https' --proto-redir '=https' --max-time 30 -o "$STAGE_O" "$BASE$OTP_COMMIT/site-integration/samzaberu-v5/src/V5BitrixOtpNativeMutationPort.php" || fail OTP_DOWNLOAD
[[ "$(sha "$STAGE_B")" == "$NEW_BRIDGE" ]] || fail BRIDGE_SHA
[[ "$(sha "$STAGE_O")" == "$NEW_OTP" ]] || fail OTP_SHA
php8.3 -l "$STAGE_B" >/dev/null || fail BRIDGE_LINT
php8.3 -l "$STAGE_O" >/dev/null || fail OTP_LINT
pass EXACT_SOURCE_AND_PHP_SYNTAX
echo '=== 3. CLI FAIL-CLOSED TEST ==='
php8.3 -r '
require $argv[1];
require $argv[2];
$p="Evrasia\\Samzaberu\\V5\\";
$b=$p."V5ProductionNativeSecurityEventBridge";
$o=$p."V5BitrixOtpNativeMutationPort";
$d=(new ReflectionClass($p."V5BitrixPdoCompatibilityBridge"))->newInstanceWithoutConstructor();
$w=(new ReflectionClass($p."V5SecurityEventVersionWriter"))->newInstanceWithoutConstructor();
$otp=(new ReflectionClass($o))->newInstanceWithoutConstructor();
$denied=false;
try {$b::applyReviewedNativeOtp($d,$w,1,$otp);}
catch (RuntimeException $e) {
    $denied=$e->getMessage()==="v5_reviewed_native_otp_scope_denied";
}
if (!$denied || !method_exists($o,"usesSameOwnedBridge")) exit(2);
echo "PASS: NATIVE_OTP_DENIED_OUTSIDE_HTTP\n";
' "$STAGE_B" "$STAGE_O" || fail CLI_GUARD_TEST
pass NATIVE_SECURITY_ENTRYPOINT_DENIED_IN_CLI
echo '=== 4. BACKUP / INSTALL / VERIFY ==='
if [[ "$ORIGINAL_BRIDGE" == "$NEW_BRIDGE" && "$ORIGINAL_OTP" == "$NEW_OTP" ]]; then
    ALREADY_APPLIED=YES
    pass EXACT_PAIR_ALREADY_PRESENT
else
    [[ "$ORIGINAL_BRIDGE" == "$OLD_BRIDGE" ]] || fail PARTIAL_PAIR_NOT_AUTOMATIC
    [[ "$ORIGINAL_OTP" == ABSENT || "$ORIGINAL_OTP" == "$OLD_OTP" ]] || fail PARTIAL_PAIR_NOT_AUTOMATIC
    BACKUP="$(mktemp -d /root/ev-v5-otp-epoch.XXXXXXXX)"
    chmod 0700 "$BACKUP"
    cp -p -- "$BRIDGE" "$BACKUP/bridge.php"
    chmod 0600 "$BACKUP/bridge.php"
    [[ "$(sha "$BACKUP/bridge.php")" == "$OLD_BRIDGE" ]] || fail BACKUP_BRIDGE
    if [[ "$ORIGINAL_OTP" != ABSENT ]]; then
        cp -p -- "$OTP" "$BACKUP/otp.php"
        chmod 0600 "$BACKUP/otp.php"
        [[ "$(sha "$BACKUP/otp.php")" == "$ORIGINAL_OTP" ]] || fail BACKUP_OTP
    fi
    pass PRIVATE_BACKUPS_VERIFIED
    [[ "$(sha "$BRIDGE")" == "$OLD_BRIDGE" ]] || fail BRIDGE_RACE
    if [[ "$ORIGINAL_OTP" == ABSENT ]]; then
        [[ ! -e "$OTP" && ! -L "$OTP" ]] || fail OTP_RACE
    else
        [[ "$(sha "$OTP")" == "$OLD_OTP" ]] || fail OTP_RACE
    fi
    chown --reference="$BRIDGE" "$STAGE_B" "$STAGE_O"
    chmod --reference="$BRIDGE" "$STAGE_B" "$STAGE_O"
    mv -T -- "$STAGE_O" "$OTP"
    STAGE_O=''
    O_CHANGED=YES
    mv -T -- "$STAGE_B" "$BRIDGE"
    STAGE_B=''
    B_CHANGED=YES
    pass TWO_V5_SOURCES_REPLACED
fi
[[ "$(sha "$BRIDGE")" == "$NEW_BRIDGE" && "$(sha "$OTP")" == "$NEW_OTP" ]] || fail FINAL_SHA
php8.3 -l "$BRIDGE" >/dev/null || fail FINAL_BRIDGE_LINT
php8.3 -l "$OTP" >/dev/null || fail FINAL_OTP_LINT
pass FINAL_V5_PAIR_VERIFIED
echo "BRIDGE_SHA256=$(sha "$BRIDGE")"
echo "OTP_SHA256=$(sha "$OTP")"
echo NEXT_STEP=REAL_NATIVE_OTP_TRANSACTION_E2E_AND_RELEASE_EVIDENCE
