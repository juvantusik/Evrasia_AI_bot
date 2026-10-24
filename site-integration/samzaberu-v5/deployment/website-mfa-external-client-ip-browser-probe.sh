#!/usr/bin/env bash
# Ephemeral WEBSITE MFA PHP REMOTE_ADDR proof for two independent external networks.
# Does not modify existing website sources, Bitrix, SMS, Redis, DB or trust.
# --self-test: local PHP syntax fixture, no website access.
# --probe: one expiring random public PHP endpoint for at most 180 seconds.
# Output includes no raw IP, passwords, cookies, session secrets or HMAC key.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
PACKAGE='/root/ev-web-mfa-release-packages/all-user-mfa-prepared-20261023-235710-4b06db78'
PROBE=''
INODE=''
OUTPUT=''
PROBE_CREATED=NO
PROBE_REMOVED=NOT_CREATED
VERIFIED=NO
EXTERNAL_EVIDENCE=NOT_COLLECTED_BY_TERMINAL
PASS_COUNT=0

pass() {
    PASS_COUNT=$((PASS_COUNT + 1))
    printf 'PASS: %s\n' "$1"
}
fail() {
    printf 'FAIL: %s\n' "$1"
    exit 1
}
cleanup() {
    local rc=$?
    trap - EXIT
    if [[ "$PROBE_CREATED" == YES ]]; then
        if [[ -n "$PROBE" && "$PROBE" =~ ^/home/site_evrasia/web/evrasia\.spb\.ru/public_html/__ev_mfa_external_ip_[a-f0-9]{32}\.php$ &&
              -f "$PROBE" && ! -L "$PROBE" &&
              -n "$INODE" && "$(stat -c '%i' "$PROBE")" == "$INODE" ]]; then
            if rm -f -- "$PROBE"; then
                PROBE_REMOVED=YES
            else
                PROBE_REMOVED=NO
                rc=1
            fi
        elif [[ "$MODE" == '--self-test' && "$PROBE" == /tmp/ev-mfa-ext-ip.*.php &&
                -f "$PROBE" && ! -L "$PROBE" &&
                -n "$INODE" && "$(stat -c '%i' "$PROBE")" == "$INODE" ]]; then
            if rm -f -- "$PROBE"; then PROBE_REMOVED=YES; else PROBE_REMOVED=NO; rc=1; fi
        else
            echo 'FAIL: temporary probe identity changed; manual inspection required'
            PROBE_REMOVED=NO
            rc=1
        fi
    fi
    if [[ -n "$OUTPUT" && "$OUTPUT" == /tmp/ev-mfa-ext-ip-response.*.json &&
          -f "$OUTPUT" && ! -L "$OUTPUT" ]]; then
        rm -f -- "$OUTPUT" || rc=1
    fi
    echo
    echo '=== 5. RESULT ==='
    echo "MODE=$MODE"
    echo "PASS_COUNT=$PASS_COUNT"
    echo "TEMPORARY_SITE_PHP_CREATED=$PROBE_CREATED"
    echo "TEMPORARY_SITE_PHP_REMOVED=$PROBE_REMOVED"
    echo "ORIGIN_ROUTE_VERIFIED=$VERIFIED"
    echo "EXTERNAL_EVIDENCE=$EXTERNAL_EVIDENCE"
    echo 'SITE_EXISTING_FILES_CHANGED=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'REDIS_CONNECTION=NO'
    echo 'REDIS_WRITE=NO'
    echo 'SMS_SENT=NO'
    echo 'TRUSTED_DEVICE_WRITE=NO'
    echo 'V4_WRITE=NO'
    echo 'V5_WRITE=NO'
    echo 'SECRETS_PRINTED=NO'
    echo 'RAW_CLIENT_IP_PRINTED=NO'
    echo 'MASS_MFA_ROLLOUT=NOT_PERFORMED'
    echo 'RELEASE_INSTALL_ALLOWED=NO'
    if (( rc == 0 )); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
    exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

create_php() {
    local target="$1" key="$2" deadline="$3"
    [[ "$key" =~ ^[a-f0-9]{64}$ && "$deadline" =~ ^[0-9]{10}$ ]] ||
        fail 'invalid ephemeral PHP parameters'
    {
        printf '%s\n' '<?php' 'declare(strict_types=1);'
        printf '$probeKeyHex = "%s";\n$expires = %d;\n' "$key" "$deadline"
        cat <<'PHP'
if (time() > $expires
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
    || strtolower((string)($_SERVER['HTTP_HOST'] ?? '')) !== 'evrasia.rest') {
    http_response_code(404);
    exit;
}
$addr = $_SERVER['REMOTE_ADDR'] ?? null;
if (!is_string($addr) || filter_var($addr, FILTER_VALIDATE_IP) === false) {
    http_response_code(503);
    exit;
}
$packed = @inet_pton($addr);
$key = hex2bin($probeKeyHex);
if (!is_string($packed) || !is_string($key)) {
    http_response_code(503);
    exit;
}
// Normalize IPv4-mapped IPv6 into the same bytes as the IPv4 spelling.
if (strlen($packed) === 16
    && substr($packed, 0, 12) === hex2bin('00000000000000000000ffff')) {
    $packed = substr($packed, 12);
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'probe' => 'evrasia-website-mfa-client-ip-v1',
    'server_php_executed' => true,
    'remote_addr_scope' => filter_var(
        $addr, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false ? 'PUBLIC' : 'LOCAL_OR_RESERVED',
    'remote_addr_fingerprint' => substr(hash_hmac('sha256', $packed, $key), 0, 24),
], JSON_THROW_ON_ERROR);
PHP
    } > "$target"
}

echo '=== 1. HOST / ENVIRONMENT ==='
[[ "$#" -eq 1 && ( "$MODE" == --self-test || "$MODE" == --probe ) ]] ||
    fail 'supported modes --self-test and --probe'
for utility in php bash curl sha256sum stat realpath mktemp ip awk sort; do
    command -v "$utility" >/dev/null 2>&1 || fail "required utility $utility absent"
done
if [[ "$MODE" == --self-test ]]; then
    PROBE="$(mktemp /tmp/ev-mfa-ext-ip.XXXXXXXX.php)"
    PROBE_CREATED=YES
    INODE="$(stat -c '%i' "$PROBE")"
    create_php "$PROBE" "$(php -r 'echo bin2hex(random_bytes(32));')" "$(( $(date +%s) + 180 ))"
    php -d auto_prepend_file= -d auto_append_file= -l "$PROBE" >/dev/null ||
        fail 'selftest PHP lint'
    grep -Fq 'remote_addr_fingerprint' "$PROBE" || fail 'selftest response field'
    grep -Fq "header('Cache-Control: private, no-store" "$PROBE" ||
        fail 'selftest cache protection'
    pass 'no-bootstrap expiring PHP template lint and privacy fixtures'
    VERIFIED=SELF_TEST_ONLY
    exit 0
fi
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] ||
    fail 'exact production hostname/root required'
[[ -d "$SITE" && ! -L "$SITE" && "$(realpath -e "$SITE")" == "$SITE" ]] ||
    fail 'site directory realpath guard'
[[ -d "$PACKAGE" && ! -L "$PACKAGE" &&
   "$(realpath -e "$PACKAGE")" == "$PACKAGE" &&
   "$(stat -c '%u:%a' "$PACKAGE")" == '0:700' ]] ||
    fail 'private package root-only guard'
pass 'production host and verified release directory'

echo
echo '=== 2. LIVE PILOT / CANDIDATE GUARDS ==='
pinned() {
    local target="$1" expected="$2" name="$3" actual
    [[ -f "$target" && ! -L "$target" &&
       "$(realpath -e "$target")" == "$target" ]] ||
        fail "$name file guard"
    actual="$(sha256sum "$target")"
    [[ "${actual%% *}" == "$expected" ]] || fail "$name SHA mismatch"
    pass "$name SHA"
}
pinned "$SITE/local/components/eurasia/signin/class.php" \
    b63094e5d45ad74f28f9594b605944526adbfad9fd0dd507ccabf11f843ed174 login_live
pinned "$SITE/local/php_interface/pincode.php" \
    dee875d300403f11ad13fdfafc760fabd52daf39e03f31ee89f847d4dd427510 pin_live
pinned "$PACKAGE/signin.candidate.php" \
    e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a signin_candidate

echo
echo '=== 3. VERIFIED ORIGIN HTTPS ROUTE BEFORE TEMP FILE ==='
ROUTE=NONE
ROUTE_ARGS=(--ipv4 --noproxy '*')
if status="$(curl "${ROUTE_ARGS[@]}" --silent --head --retry 0 \
    --connect-timeout 4 --max-time 8 --output /dev/null \
    --write-out '%{http_code}' https://evrasia.rest/ 2>/dev/null)" &&
    [[ "$status" =~ ^[234][0-9][0-9]$ ]]; then
    ROUTE=DIRECT_DNS
else
    ROUTE_ARGS=(--ipv4 --noproxy '*' --resolve 'evrasia.rest:443:127.0.0.1')
    if status="$(curl "${ROUTE_ARGS[@]}" --silent --head --retry 0 \
        --connect-timeout 4 --max-time 8 --output /dev/null \
        --write-out '%{http_code}' https://evrasia.rest/ 2>/dev/null)" &&
        [[ "$status" =~ ^[234][0-9][0-9]$ ]]; then
        ROUTE=LOOPBACK_TLS
    fi
fi
if [[ "$ROUTE" == NONE ]]; then
    while IFS= read -r assigned_ip; do
        [[ "$assigned_ip" =~ ^([0-9]{1,3}[.]){3}[0-9]{1,3}$ ]] || continue
        candidate=(--ipv4 --noproxy '*' --resolve "evrasia.rest:443:$assigned_ip")
        if status="$(curl "${candidate[@]}" --silent --head --retry 0 \
            --connect-timeout 4 --max-time 8 --output /dev/null \
            --write-out '%{http_code}' https://evrasia.rest/ 2>/dev/null)" &&
            [[ "$status" =~ ^[234][0-9][0-9]$ ]]; then
            ROUTE_ARGS=("${candidate[@]}")
            ROUTE=ORIGIN_BOUND_INTERFACE_TLS
            break
        fi
    done < <({ ip -o -4 addr show scope global 2>/dev/null || true; } |
        awk 'NF>=4 {split($4,ip,"/");print ip[1]}' | sort -u | head -n 4)
fi
echo "HTTPS_ROUTE=$ROUTE"
[[ "$ROUTE" != NONE ]] || fail 'no TLS-verified canonical route; no site file created'
pass 'TLS validation intact; no -k; origin HTTPS available'

echo
echo '=== 4. TEMPORARY EXTERNAL BROWSER CLIENT-IP EVIDENCE ==='
NAME="$(php -r 'echo bin2hex(random_bytes(16));')"
SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
[[ "$NAME" =~ ^[a-f0-9]{32}$ && "$SECRET" =~ ^[a-f0-9]{64}$ ]] ||
    fail 'ephemeral identifier generation'
PROBE="$SITE/__ev_mfa_external_ip_$NAME.php"
[[ ! -e "$PROBE" && ! -L "$PROBE" ]] || fail 'unexpected temporary file collision'
( set -C; : > "$PROBE" ) || fail 'exclusive temporary file create'
PROBE_CREATED=YES
INODE="$(stat -c '%i' "$PROBE")"
create_php "$PROBE" "$SECRET" "$(( $(date +%s) + 180 ))"
php -d auto_prepend_file= -d auto_append_file= -l "$PROBE" >/dev/null ||
    fail 'probe PHP lint'
chmod 644 "$PROBE"
OUTPUT="$(mktemp /tmp/ev-mfa-ext-ip-response.XXXXXXXX.json)"
chmod 600 "$OUTPUT"
URL="https://evrasia.rest/$(basename "$PROBE")"
status="$(curl "${ROUTE_ARGS[@]}" --silent --retry 0 \
    --connect-timeout 6 --max-time 12 --output "$OUTPUT" \
    --write-out '%{http_code}' "$URL" 2>/dev/null)" ||
    fail 'origin-bound PHP request failed'
[[ "$status" == 200 ]] || fail 'origin-bound PHP probe did not return 200'
php -d auto_prepend_file= -d auto_append_file= -r '
try {
    $o=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($o) || ($o["server_php_executed"]??false)!==true
        || !in_array(($o["remote_addr_scope"]??null),["PUBLIC","LOCAL_OR_RESERVED"],true)
        || preg_match("/^[a-f0-9]{24}$/",($o["remote_addr_fingerprint"]??""))!==1) {
        throw new RuntimeException();
    }
    echo "ORIGIN_REMOTE_ADDR_SCOPE=".$o["remote_addr_scope"]."\n";
    echo "ORIGIN_IP_FINGERPRINT=".$o["remote_addr_fingerprint"]."\n";
} catch (Throwable) {
    echo "FAIL: unexpected origin PHP response\n";
    exit(1);
}' "$OUTPUT" || fail 'origin PHP response validation'
VERIFIED=YES
pass 'origin-bound PHP execution and nonidentifying fingerprint'

echo
echo 'Open this non-authenticating temporary diagnostic link from TWO networks:'
echo '  1. A desktop browser (usual Wi-Fi).'
echo '  2. A phone browser with Wi-Fi OFF (cellular data).'
echo 'Use incognito/private tabs. No login or SMS is required.'
echo "TEMPORARY_TEST_URL=$URL"
echo 'Copy the JSON from BOTH browser responses.'
echo 'We will compare the two remote_addr_fingerprint values;'
echo 'they should differ between independent networks.'
echo 'Do NOT send raw IP addresses or cookie values.'
echo 'This URL expires automatically and the PHP file is cleaned up.'
echo
echo 'Press ENTER in this SSH terminal AFTER both browser tests.'
echo 'The command also ends after ~165 seconds, even without ENTER.'
if [[ -r /dev/tty ]]; then
    read -r -t 165 -p 'Browser probes completed? Press ENTER: ' _ </dev/tty || true
else
    echo 'FAIL: /dev/tty unavailable for bounded external probe window'
    exit 1
fi
EXTERNAL_EVIDENCE=OPERATOR_BROWSER_RESPONSES_REQUIRED
echo 'EXTERNAL_IP_ATTRIBUTION_FINAL=AWAITING_TWO_BROWSER_JSON_RESPONSES'
