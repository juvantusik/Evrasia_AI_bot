#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
SRC="$SITE/local/samzaberu-v5/src"
TARGET="$SRC/V5ProductionAuthHttpComposition.php"
OLD='693329cf2594ed957f79c7b846088e4a253d19802c335c57d3f83a82cbf71b74'
NEW='6ec15fbd541c386a2d0a951be34fc462532d9b923b87f320a5799bbf77796633'
COMMIT='6aac5a471b5793901693b443e67dcec57b73c4f7'
V4='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
V5='a134380714cb2299ee47eb46ab6b248985a863f8aa5b7104290bde14847fa266'
URL="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$COMMIT/site-integration/samzaberu-v5/src/V5ProductionAuthHttpComposition.php"
STAGE=''
BACKUP=''
CHANGED=NO
ALREADY_APPLIED=NO

sha() { sha256sum -- "$1" | cut -d' ' -f1; }
fail() { echo "FAIL: $1"; exit 2; }
finish() {
    local rc=$?
    trap - EXIT
    set +e
    if ((rc != 0)) && [[ "$CHANGED" == YES ]]; then
        if [[ -f "$TARGET" && ! -L "$TARGET" && "$(sha "$TARGET")" == "$NEW" ]]; then
            local restore
            restore="$(mktemp "$SRC/.v5-business-restore.XXXXXXXX.php")"
            cp -p -- "$BACKUP/original.php" "$restore"
            mv -T -- "$restore" "$TARGET"
            [[ "$(sha "$TARGET")" == "$OLD" ]] && echo 'ROLLBACK_STATUS=PASS' || echo 'ROLLBACK_STATUS=FAIL'
        else
            echo 'ROLLBACK_STATUS=MANUAL_REQUIRED'
        fi
    fi
    [[ -z "$STAGE" ]] || rm -f -- "$STAGE"
    echo '=== 5. RESULT ==='
    if ((rc == 0)); then echo FINAL_STATUS=PASS; else echo FINAL_STATUS=FAIL; fi
    echo "FINAL_RC=$rc"
    echo "ALREADY_APPLIED=$ALREADY_APPLIED"
    echo "BACKUP_DIR=$BACKUP"
    echo 'MODE=V5_ONLY_CODE_INSTALL'
    echo 'DATABASE_WRITE=NO'
    echo 'API_V4_WRITE=NO'
    echo 'PUBLIC_ROUTER_WRITE=NO'
    echo 'V5_AUTH_ENABLED=NO'
    echo 'SMS_SENT=NO'
    echo 'SECRETS_PRINTED=NO'
    echo 'PUTTY_SESSION=KEEP_OPEN'
}
trap finish EXIT

echo '=== 1. HOST / SOURCE GUARDS ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] || fail HOST_OR_ROOT
[[ -d "$SRC" && ! -L "$SRC" && -f "$TARGET" && ! -L "$TARGET" ]] || fail TARGET
[[ "$(sha "$SITE/api/v4/router.php")" == "$V4" ]] || fail V4_CHANGED
[[ "$(sha "$SITE/api/v5/router.php")" == "$V5" ]] || fail V5_ROUTER_CHANGED
exec 9>/run/ev-v5-business-http.lock
flock -n 9 || fail OTHER_INSTALLER
CURRENT="$(sha "$TARGET")"
[[ "$CURRENT" == "$OLD" || "$CURRENT" == "$NEW" ]] || fail UNKNOWN_PRODUCTION_SOURCE
echo 'PASS: EXACT_V5_TARGET_AND_ROUTERS'

echo '=== 2. FETCH BUSINESS COMPOSITION ==='
STAGE="$(mktemp "$SRC/.v5-business-http.XXXXXXXX.php")"
curl -q -fsSL --proto '=https' --proto-redir '=https' --max-time 30 -o "$STAGE" "$URL" || fail DOWNLOAD
[[ "$(sha "$STAGE")" == "$NEW" ]] || fail SOURCE_SHA
php8.3 -l "$STAGE" || fail PHP_LINT
echo 'PASS: EXACT_GITHUB_SOURCE'

echo '=== 3. EIGHT ROUTES / SAME SESSION ==='
php8.3 -r '
$s=file_get_contents($argv[1]);
$markers=[
"new V5MySqlProfileRead(","new V5MySqlOrdersRead(",
"new V5MySqlOrderDetailRead(","new V5MySqlCartRead(",
"new V5MySqlCartAdd(","new V5MySqlCartMutation(",
"new V5MySqlOrderPlace(","V5ProductionStrongSessionFactory::buildForProtectedHttp("
];
foreach ($markers as $marker) {
    if (!str_contains($s,$marker)) exit(2);
}
if (str_contains($s,"CUser::Login")
    || str_contains($s,"JwtAuthorization::")) exit(3);
echo "PASS: V5_BUSINESS_ROUTES_WIRED\n";
' "$STAGE" || fail BUSINESS_ROUTE_CONTRACT

echo '=== 4. BACKUP / INSTALL / VERIFY ==='
if [[ "$CURRENT" == "$NEW" ]]; then
    ALREADY_APPLIED=YES
else
    BACKUP="$(mktemp -d /root/ev-v5-business-http.XXXXXXXX)"
    chmod 0700 "$BACKUP"
    cp -p -- "$TARGET" "$BACKUP/original.php"
    chmod 0600 "$BACKUP/original.php"
    [[ "$(sha "$BACKUP/original.php")" == "$OLD" ]] || fail BACKUP
    [[ "$(sha "$TARGET")" == "$OLD" ]] || fail CONCURRENT_SOURCE_CHANGE
    chown --reference="$TARGET" "$STAGE"
    chmod --reference="$TARGET" "$STAGE"
    mv -T -- "$STAGE" "$TARGET"
    STAGE=''
    CHANGED=YES
fi
[[ "$(sha "$TARGET")" == "$NEW" ]] || fail INSTALLED_SHA
php8.3 -l "$TARGET" || fail INSTALLED_PHP_LINT
echo 'PASS: V5_BUSINESS_COMPOSITION_INSTALLED'
echo "INSTALLED_SHA256=$(sha "$TARGET")"
echo 'NEXT_STEP=WIRE_REAL_AUTH_AND_HTTP_FACTORY'
