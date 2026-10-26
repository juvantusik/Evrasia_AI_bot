#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
SRC="$SITE/local/samzaberu-v5/src"
ROUTER="$SITE/api/v5/router.php"
V4="$SITE/api/v4/router.php"
BASE='https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/c7e8676acec87875f2e8832d965a740547603b4e/site-integration/samzaberu-v5/src'
V4_SHA='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
ROUTER_SHA='a134380714cb2299ee47eb46ab6b248985a863f8aa5b7104290bde14847fa266'
MARKER='EV_V5_NATIVE_PUBLIC_SIGNUP_20261026'
STAGE=''
BACKUP=''
PUBLISHED=NO
NEW_ROUTER_SHA=''
PASS_COUNT=0
FAIL_COUNT=0

sha() { sha256sum -- "$1" | cut -d ' ' -f1; }
pass() { echo "PASS: $1"; PASS_COUNT=$((PASS_COUNT+1)); }
fail() { echo "FAIL: $1"; FAIL_COUNT=$((FAIL_COUNT+1)); exit 2; }

finish() {
    local rc=$?
    trap - EXIT
    set +e
    if ((rc != 0)) && [[ "$PUBLISHED" == YES ]]; then
        if [[ -f "$ROUTER" && "$(sha "$ROUTER")" == "$NEW_ROUTER_SHA" ]]; then
            local restore
            restore="$(mktemp "$SITE/api/v5/.v5-signup-router-rollback.XXXXXXXX.php")"
            cp -p -- "$BACKUP/router.php" "$restore"
            mv -T -- "$restore" "$ROUTER"
            echo 'ROLLBACK_ROUTER=PASS'
        else
            echo 'ROLLBACK_ROUTER=MANUAL_REVIEW'
        fi
    fi
    if ((rc != 0)) && [[ -d "$BACKUP" ]]; then
        while read -r f original; do
            [[ -n "$f" ]] || continue
            if [[ "$original" == ABSENT ]]; then
                if [[ -f "$SRC/$f" && ! -L "$SRC/$f" ]]; then
                    local expected
                    expected="$(grep -E "^$f " "$STAGE/manifest" | cut -d ' ' -f2)"
                    [[ "$(sha "$SRC/$f")" == "$expected" ]] && rm -f -- "$SRC/$f"
                fi
            else
                local expected
                expected="$(grep -E "^$f " "$STAGE/manifest" | cut -d ' ' -f2)"
                if [[ -f "$SRC/$f" && "$(sha "$SRC/$f")" == "$expected" ]]; then
                    local tmp
                    tmp="$(mktemp "$SRC/.v5-signup-rollback.XXXXXXXX.php")"
                    cp -p -- "$BACKUP/$f" "$tmp"
                    mv -T -- "$tmp" "$SRC/$f"
                fi
            fi
        done < "$BACKUP/original.sha"
    fi
    [[ -z "$STAGE" ]] || rm -rf -- "$STAGE"
    echo '=== 5. RESULT ==='
    if ((rc == 0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo "PASS_COUNT=$PASS_COUNT"
    echo "FAIL_COUNT=$FAIL_COUNT"
    echo "BACKUP=$BACKUP"
    echo 'MODE=V5_REGISTRATION_AND_MFA_ROUTES'
    echo 'API_V4_WRITE=NO'
    echo 'DATABASE_MIGRATION=NO'
    echo 'LIVE_TEST_USER_CREATED=NO'
    echo 'SMS_SENT_BY_INSTALLER=NO'
    echo 'V5_SIGNIN_ENABLED=NO'
    echo 'SECRETS_PRINTED=NO'
    echo 'PUTTY_SESSION=KEEP_OPEN'
}
trap finish EXIT

echo '=== 1. HOST / ROUTER GUARDS ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] || fail WRONG_HOST_OR_USER
[[ -d "$SRC" && ! -L "$SRC" && -f "$ROUTER" && ! -L "$ROUTER" ]] || fail SOURCE_LAYOUT
[[ "$(sha "$V4")" == "$V4_SHA" ]] || fail V4_ROUTER_DRIFT
[[ "$(sha "$ROUTER")" == "$ROUTER_SHA" ]] || fail V5_ROUTER_DRIFT
! grep -Fq "$MARKER" "$ROUTER" || fail ALREADY_PATCHED
for cmd in python3 php8.3 curl sha256sum flock; do command -v "$cmd" >/dev/null || fail "COMMAND_$cmd"; done
exec 9>/run/ev-v5-signup-20261026.lock
flock -n 9 || fail CONCURRENT_INSTALL
pass EXACT_PRODUCTION_V4_V5_ROUTER

echo '=== 2. DOWNLOAD COMPLETE V5 SIGNUP DEPENDENCIES AND PHP LINT ==='
STAGE="$(mktemp -d "$SRC/.v5-signup-20261026.XXXXXXXX")"
cat > "$STAGE/manifest" <<'FILES'
V5AuthHttpHandler.php d573c60ca6e76515f13940f98b28e86f12dcc0d8f9881b9b3fba456585889f76
V5LoginWorkflow.php 18dce5abb06ccf47ebff613a3ae2f061a032c53be523b470cd667274fae076dc
V5RouteMap.php 8fb51ea35030997a3eefbd834ab5da03ee4d01b72427a16b03b383271061b8e4
V5ProductionAuthHttpComposition.php 3bf9f7f770b4fbcf90a3f0df4b4b320ca35e8a3feaab83720c0d98641b7f1d67
V5BitrixNativeRegistrationWriter.php 6cedb14876efff57be9426997c84b24cdd41e1f7b5419d2452c0015cb6ae3a09
V5CurrentWebsiteLegalCatalogPort.php 362f06d14320a056244873e3d6c13679f8a562b417ee49b597fa5d44d08b6780
V5PublicSignupEndpoint.php d01acbb0a4c6e934171f4f86f1060c17744e96aec339d7c16ec370f4030d1666
AuthDecision.php f947e35476ad6191482989dec1d0444407c2199f88d1443f2017e46d42eb474b
V5LegalConsentContract.php dce2310763e014d541f940e68e5646079f32ccef73027c0fc9d357ad53eaddcf
V5PublicLegalCodeValidation.php c77d891b5f84605cbb9714be834b397027d2192c4d79b83ae8d3817f36f9d3be
V5CheckoutRequest.php 5b6cbc58294974582c450826e7a78e01137d9ad9170ae4f4e585b865ca1600bc
V5CartMutationRequest.php 1a01fcd4daefd42218608a617145d3a35a424efb324bcdf012039905abe64ef6
V5BitrixPublicLegalCatalog.php 674fc81060828cf51c7068e51938b3f81fbde6bbff48ea0231f20a5c23c88489
V5SignupNativeConsentPlan.php c63f19d946cce77578259dd123a2b549ab6759bddaf160e8ed46020ee353fc9c
V5BitrixCurrentLegalCatalog.php 1017930000142e151a54e768fb0512f3b8ba05d537a9b418522762cae74684de
FILES

while read -r name expected; do
    curl -q -fsSL --proto '=https' --proto-redir '=https' \
        --connect-timeout 8 --max-time 40 -o "$STAGE/$name" \
        "$BASE/$name" || fail "DOWNLOAD_$name"
    [[ "$(sha "$STAGE/$name")" == "$expected" ]] || fail "SHA_$name"
    php8.3 -l "$STAGE/$name" >/dev/null || fail "LINT_$name"
done < "$STAGE/manifest"
pass FIFTEEN_EXACT_V5_SOURCES_LINTED

echo '=== 3. PREPARE V5 ROUTER GUARD ==='
python3 - "$ROUTER" "$STAGE/router.new.php" "$MARKER" <<'PY'
import pathlib, re, sys
old = pathlib.Path(sys.argv[1]).read_text()
match = re.match(r'\A<\?php\s*(?:declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*)?',old)
if not match or sys.argv[3] in old:
    raise SystemExit('FAIL: UNSUPPORTED_V5_ROUTER_START')
patch = r'''
// EV_V5_NATIVE_PUBLIC_SIGNUP_20261026
if (($_SERVER['REQUEST_METHOD'] ?? null) === 'POST'
    && ($_SERVER['REQUEST_URI'] ?? null) === '/api/v5/auth/signup') {
    $status = 503;
    $body = ['error' => 'temporarily_unavailable'];
    $level = ob_get_level();
    ob_start();
    try {
        $root = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
        if (!in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on','1'], true)) {
            throw new \RuntimeException('v5_signup_https_unavailable');
        }
        require_once $root . '/bitrix/modules/main/include/prolog_before.php';
        require_once $root . '/local/samzaberu-v5/src/V5PublicSignupEndpoint.php';
        $raw = file_get_contents('php://input', false, null, 0, 8193);
        if (is_string($raw)) {
            $result = \Evrasia\Samzaberu\V5\V5PublicSignupEndpoint::handle(
                $_SERVER, $raw, $root . '/bitrix/.settings.php'
            );
            $status = $result->status;
            $body = $result->body;
        }
    } catch (\Throwable) {
        $status = 503;
        $body = ['error' => 'temporarily_unavailable'];
    }
    $unexpected = false;
    while (ob_get_level() > $level) {
        $unexpected = (ob_get_length() > 0) || $unexpected;
        ob_end_clean();
    }
    if ($unexpected) {
        $status = 503;
        $body = ['error' => 'temporarily_unavailable'];
    }
    if (headers_sent()) exit;
    header_remove('Set-Cookie');
    header_remove('Location');
    header_remove('Access-Control-Allow-Origin');
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
'''
new = old[:match.end()] + '\n' + patch + '\n' + old[match.end():]
pathlib.Path(sys.argv[2]).write_text(new)
PY
php8.3 -l "$STAGE/router.new.php" >/dev/null || fail PATCH_ROUTER_LINT
NEW_ROUTER_SHA="$(sha "$STAGE/router.new.php")"
pass SINGLE_V5_SIGNUP_ROUTE_PATCH

echo '=== 4. BACKUP / INSTALL / HTTP POSTCHECK ==='
BACKUP="$(mktemp -d /root/ev-v5-signup-20261026.XXXXXXXX)"
chmod 0700 "$BACKUP"
cp -p -- "$ROUTER" "$BACKUP/router.php"
[[ "$(sha "$BACKUP/router.php")" == "$ROUTER_SHA" ]] || fail ROUTER_BACKUP
: > "$BACKUP/original.sha"
while read -r name expected; do
    if [[ -e "$SRC/$name" || -L "$SRC/$name" ]]; then
        [[ -f "$SRC/$name" && ! -L "$SRC/$name" ]] || fail "UNSAFE_$name"
        original="$(sha "$SRC/$name")"
        cp -p -- "$SRC/$name" "$BACKUP/$name"
        # Keep owner/mode for correct PHP-FPM rollback. Backup directory is
        # private root-only (0700), so files need not be chmod 0600.
        echo "$name $original" >> "$BACKUP/original.sha"
    else
        echo "$name ABSENT" >> "$BACKUP/original.sha"
    fi
done < "$STAGE/manifest"
pass VERIFIED_BACKUPS

while read -r name expected; do
    tmp="$(mktemp "$SRC/.v5-signup-source.XXXXXXXX.php")"
    install -o site_evrasia -g site_evrasia -m 0644 "$STAGE/$name" "$tmp"
    [[ "$(sha "$tmp")" == "$expected" ]] || fail SOURCE_STAGING
    mv -T -- "$tmp" "$SRC/$name"
done < "$STAGE/manifest"
pass V5_SOURCE_INSTALLED

[[ "$(sha "$ROUTER")" == "$ROUTER_SHA" ]] || fail CONCURRENT_ROUTER_CHANGE
tmp="$(mktemp "$SITE/api/v5/.v5-signup-public.XXXXXXXX.php")"
cp -p -- "$STAGE/router.new.php" "$tmp"
chown --reference="$ROUTER" "$tmp"
chmod --reference="$ROUTER" "$tmp"
mv -T -- "$tmp" "$ROUTER"
PUBLISHED=YES
[[ "$(sha "$ROUTER")" == "$NEW_ROUTER_SHA" ]] || fail PUBLIC_SHA
php8.3 -l "$ROUTER" >/dev/null || fail PUBLIC_LINT

# Invalid signup MUST be rejected without creating a real or synthetic user.
http="$(curl -q -sS --noproxy '*' --proto '=https' \
   --connect-timeout 5 --max-time 15 \
   --resolve 'evrasia.rest:443:192.168.103.141' \
   -H 'Content-Type: application/json' --data-binary '{}' \
   -o /dev/null -w '%{http_code}' \
   https://evrasia.rest/api/v5/auth/signup)" || fail SIGNUP_HTTP_PROBE
[[ "$http" == 400 ]] || fail "SIGNUP_HTTP_$http"
pass INVALID_SIGNUP_REJECTED_WITHOUT_USER_CREATION

[[ "$(sha "$V4")" == "$V4_SHA" ]] || fail V4_POSTCHECK
echo "PUBLIC_ROUTER_SHA256=$(sha "$ROUTER")"
pass V4_UNCHANGED_PUBLIC_SIGNUP_HTTP_ACTIVE
echo 'NEXT_STEP=ENABLE_NATIVE_MFA_SIGNIN_AND_CHALLENGES'
