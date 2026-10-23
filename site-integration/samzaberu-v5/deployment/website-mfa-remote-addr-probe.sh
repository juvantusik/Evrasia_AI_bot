#!/usr/bin/env bash
# Evrasia website MFA, temporary HTTP PHP REMOTE_ADDR and forged header proof.
# No existing site files or database rows changed.
set -Eeuo pipefail
umask 077
MODE="${1:-}"
if [[ "$#" -ne 1 || ( "$MODE" != '--self-test' && "$MODE" != '--probe' ) ]]; then
    echo 'FAIL: supported modes --self-test and --probe'
    exit 2
fi
SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
CP='/root/ev-web-mfa-checkpoints/public-web-mfa-trust-v3-20261023-221046-448854e0'
PROBE=''
INODE=''
OUTPUT1=''
OUTPUT2=''
DONE=NO
REMOVED=NOT_CREATED
finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$PROBE" && -f "$PROBE" && ! -L "$PROBE" ]]; then
        if { [[ "$MODE" == '--probe' &&
            "$PROBE" =~ ^/home/site_evrasia/web/evrasia\.spb\.ru/public_html/__ev_mfa_ip_[a-f0-9]{24}\.php$ ]] ||
             [[ "$MODE" == '--self-test' &&
                "$PROBE" == /tmp/ev-mfa-ip-self.*.php ]]; } &&
             [[ -n "$INODE" && "$(stat -c '%i' "$PROBE")" == "$INODE" ]]; then
            if rm -f -- "$PROBE"; then
                REMOVED=YES
            else
                REMOVED=NO
                rc=1
            fi
        else
            echo 'FAIL: temporary file cleanup guard was not satisfied'
            REMOVED=NO
            rc=1
        fi
    fi
    for f in "$OUTPUT1" "$OUTPUT2"; do
        if [[ -n "$f" && "$f" == /tmp/ev-mfa-ip-response.*.json &&
              -f "$f" && ! -L "$f" ]]; then
            rm -f -- "$f" || rc=1
        fi
    done
    echo
    echo '=== 5. RESULT ==='
    echo "MODE=$MODE"
    echo "TEMPORARY_PHP_REMOVED=$REMOVED"
    echo "PROBE_COMPLETED=$DONE"
    echo 'PRODUCTION_EXISTING_FILES_CHANGED=NO'
    echo 'TEMPORARY_SITE_FILE_WRITE=YES_ONLY_IF_PROBE_MODE'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'SMS_SENT=NO'
    echo 'TRUSTED_DEVICES_CHANGED=NO'
    echo 'V4_WRITE=NO'
    echo 'V5_WRITE=NO'
    echo 'SECRETS_OR_RAW_IP_PRINTED=NO'
    echo 'PUBLIC_WEBSITE_MFA_DEPLOYED=NO'
    if (( rc == 0 )); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
    exit "$rc"
}
trap finish EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

for tool in php curl sha256sum stat awk; do
    command -v "$tool" >/dev/null 2>&1 || {
        echo "FAIL: missing $tool"; exit 1;
    }
done

make_php() {
    local file="$1" digest="$2" deadline="$3"
    [[ "$digest" =~ ^[a-f0-9]{64}$ && "$deadline" =~ ^[0-9]{10}$ ]] || {
        echo 'FAIL: invalid secret digest or deadline'; exit 1;
    }
    {
        printf '<?php\n'
        printf 'declare(strict_types=1);\n'
        printf '$digest = "%s";\n' "$digest"
        printf '$expires = %s;\n' "$deadline"
        cat <<'PHP'
if (time() > $expires
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
    || ($_SERVER['HTTP_HOST'] ?? '') !== 'evrasia.rest'
    || !isset($_SERVER['HTTP_X_EV_MFA_PROOF'])
    || !is_string($_SERVER['HTTP_X_EV_MFA_PROOF'])
    || !hash_equals(
        $digest, hash('sha256', $_SERVER['HTTP_X_EV_MFA_PROOF'])
    )) {
    http_response_code(404);
    exit;
}
$addr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!is_string($addr)
    || filter_var($addr, FILTER_VALIDATE_IP) === false) {
    http_response_code(503);
    exit;
}
header('Content-Type: application/json');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'php_executed' => true,
    'valid_ip' => true,
    // Request-scoped HMAC, never the actual address or a persistent hash.
    'ip_proof' => hash_hmac('sha256', $addr, $_SERVER['HTTP_X_EV_MFA_PROOF']),
], JSON_THROW_ON_ERROR);
PHP
    } > "$file"
}

echo '=== 1. HOST AND PHP TEMPLATE ==='
if [[ "$MODE" == '--self-test' ]]; then
    PROBE="$(mktemp /tmp/ev-mfa-ip-self.XXXXXXXX.php)"
    INODE="$(stat -c '%i' "$PROBE")"
    FIXTURE_DIGEST="$(printf '%s' fixture | sha256sum | awk '{print $1}')"
    make_php "$PROBE" "$FIXTURE_DIGEST" "$(( $(date +%s)+180 ))"
    php -d auto_prepend_file= -d auto_append_file= -l "$PROBE" >/dev/null
    echo 'PASS: temporary PHP template validated without website modification'
    DONE=YES
    exit 0
fi
[[ "$(hostname -s)" == 'evrasia' && "$(id -u)" -eq 0 ]] || {
    echo 'FAIL: wrong host or user'; exit 1;
}
[[ -d "$SITE" && ! -L "$SITE" && "$(realpath -e "$SITE")" == "$SITE" ]] || {
    echo 'FAIL: website root changed'; exit 1;
}
[[ -d "$CP" && ! -L "$CP" &&
    "$(realpath -e "$CP")" == "$CP" &&
    "$(stat -c '%u:%a' "$CP")" == '0:700' ]] || {
    echo 'FAIL: verified v3 checkpoint changed'; exit 1;
}
pinned() {
    local sha="$1" file="$2"
    [[ -f "$file" && ! -L "$file" &&
       "$(sha256sum "$file" | awk '{print $1}')" == "$sha" ]] || {
        echo 'FAIL: source or private candidate SHA drift'; exit 1;
    }
}
pinned '6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9' \
    "$SITE/local/components/eurasia/signin/class.php"
pinned '04d254d7667c29819557d4df269760fa09e1561d2e6185fe8ab68a3a2d7f3770' \
    "$CP/signin.candidate.php"
pinned 'ef0ed60aafceea3d82dd65f239c01dfe9f708bddfdb68fb5a64f8078b3d38b2f' \
    "$CP/budget.candidate.php"
echo 'PASS: site root, live auth and private candidates SHA-pinned'
echo 'PUBLIC_DOMAIN=evrasia.rest'

echo
echo '=== 2. SHORT-LIVED SECRET-GUARDED WEB PHP PROBE ==='
NAME="$(php -r 'echo bin2hex(random_bytes(12));')"
TOKEN="$(php -r 'echo bin2hex(random_bytes(32));')"
[[ "$NAME" =~ ^[a-f0-9]{24}$ && "$TOKEN" =~ ^[a-f0-9]{64}$ ]] || {
    echo 'FAIL: random proof values invalid'; exit 1;
}
PROBE="$SITE/__ev_mfa_ip_$NAME.php"
[[ ! -e "$PROBE" && ! -L "$PROBE" ]] || {
    echo 'FAIL: path unexpectedly occupied'; exit 1;
}
( set -C; : > "$PROBE" ) || {
    echo 'FAIL: exclusive temporary site-file creation failed'; exit 1;
}
INODE="$(stat -c '%i' "$PROBE")"
TOKEN_SHA="$(printf '%s' "$TOKEN" | sha256sum | awk '{print $1}')"
make_php "$PROBE" "$TOKEN_SHA" "$(( $(date +%s)+180 ))"
php -d auto_prepend_file= -d auto_append_file= -l "$PROBE" >/dev/null
chmod 644 "$PROBE"
OUTPUT1="$(mktemp /tmp/ev-mfa-ip-response.XXXXXXXX.json)"
OUTPUT2="$(mktemp /tmp/ev-mfa-ip-response.XXXXXXXX.json)"
chmod 600 "$OUTPUT1" "$OUTPUT2"
echo 'PASS: one random name, 180 second expiry, proof required in HTTP header'
echo 'PROOF_HEADER_VALUE_PRINTED=NO'

echo
echo '=== 3. CANONICAL HTTPS PROOF AND CLIENT-IP HEADER SPOOF TEST ==='
URL="https://evrasia.rest/$(basename "$PROBE")"
STATUS1="$(curl --ipv4 --noproxy '*' --silent --show-error --fail \
    --retry 0 --connect-timeout 8 --max-time 20 \
    -H 'Cache-Control: no-cache' \
    -H "X-EV-MFA-Proof: $TOKEN" \
    --output "$OUTPUT1" --write-out '%{http_code}' "$URL")"
[[ "$STATUS1" == 200 ]] || {
    echo 'FAIL: canonical HTTPS did not execute guarded PHP probe'; exit 1;
}
STATUS2="$(curl --ipv4 --noproxy '*' --silent --show-error --fail \
    --retry 0 --connect-timeout 8 --max-time 20 \
    -H 'Cache-Control: no-cache' \
    -H "X-EV-MFA-Proof: $TOKEN" \
    -H 'X-Forwarded-For: 203.0.113.77' \
    -H 'X-Real-IP: 203.0.113.78' \
    -H 'Forwarded: for=203.0.113.79' \
    --output "$OUTPUT2" --write-out '%{http_code}' "$URL")"
[[ "$STATUS2" == 200 ]] || {
    echo 'FAIL: canonical HTTPS forged-header request failed'; exit 1;
}

php -d auto_prepend_file= -d auto_append_file= -r '
try {
    $one=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    $two=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
    foreach ([$one,$two] as $v) {
        if (!is_array($v) || ($v["php_executed"]??false)!==true
            || ($v["valid_ip"]??false)!==true
            || !isset($v["ip_proof"])
            || !is_string($v["ip_proof"])
            || preg_match("/^[a-f0-9]{64}$/",$v["ip_proof"])!==1) {
            throw new RuntimeException("unexpected_response");
        }
    }
    $same=hash_equals($one["ip_proof"],$two["ip_proof"]);
    echo "CANONICAL_HTTPS_PHP_EXECUTED=YES\n";
    echo "PHP_REMOTE_ADDR_IS_VALID_IP=YES\n";
    echo "FORGED_CLIENT_HEADERS_CHANGED_REMOTE_ADDR="
        . ($same?"NO":"YES") . "\n";
    echo "INDEPENDENT_EXTERNAL_BROWSER_IP_TESTED=NO\n";
    if (!$same) exit(1);
} catch (Throwable) {
    echo "FAIL: HTTPS response validation failed\n";
    exit(1);
}
' "$OUTPUT1" "$OUTPUT2"

echo
echo '=== 4. PRE-DEPLOY RELEASE STATE ==='
echo 'PASS: two canonical-domain PHP requests agreed on server-side REMOTE_ADDR'
echo 'PUBLIC_MFA_IMPLEMENTATION_UNCHANGED=YES'
echo 'BROWSER_LOGIN_E2E=NOT_RUN'
echo 'SMS_LIMIT_THRESHOLDS=PROVISIONAL'
DONE=YES
