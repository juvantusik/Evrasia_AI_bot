#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
SRC="$SITE/local/samzaberu-v5/src"
ROUTER="$SITE/api/v5/router.php"
V4="$SITE/api/v4/router.php"
BASE='https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/019f2717d71043418507c5ca1ab0c994e49413dd/site-integration/samzaberu-v5/src'
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

echo '=== 2. DOWNLOAD AND PHP LINT ==='
STAGE="$(mktemp -d "$SRC/.v5-signup-20261026.XXXXXXXX")"
cat > "$STAGE/manifest" <<'FILES'
V5AuthHttpHandler.php 0983b64af91215e978c03b80d2d325a7e12ca8d52973746c9ba0d8bc0e7e2228
V5LoginWorkflow.php 9b2b27a88229d582c385f04a485b18a4c3030394e524de8f2f83bca5991a51b9
V5RouteMap.php 2cc9ea54061685ac75764baecaf9965f5e026b391c4f6dec2594c49fda69f84a
V5ProductionAuthHttpComposition.php e052798caa8b997182e849224ce451d88ac184dca495ff90ae7d90e842678e0a
V5BitrixNativeRegistrationWriter.php 7c1da314fa44574023565c2b0b369a82da4f0c85891d55922c0fdfe29e7475a3
V5CurrentWebsiteLegalCatalogPort.php d04de45bce2a40ee4ab554b860c81319ac6edb2b19406c19e22c3b71b672320a
V5PublicSignupEndpoint.php 3cbfd08ba6351d1c860d15741a15296e2659ac8071f13c8e8dea5601ce02029a
FILES

while read -r name expected; do
    curl -q -fsSL --proto '=https' --proto-redir '=https' \
        --connect-timeout 8 --max-time 40 -o "$STAGE/$name" \
        "$BASE/$name" || fail "DOWNLOAD_$name"
    [[ "$(sha "$STAGE/$name")" == "$expected" ]] || fail "SHA_$name"
    php8.3 -l "$STAGE/$name" >/dev/null || fail "LINT_$name"
done < "$STAGE/manifest"
pass SEVEN_EXACT_V5_SOURCES_LINTED

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
