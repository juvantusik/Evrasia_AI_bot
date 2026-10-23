#!/usr/bin/env bash
# Evrasia website public MFA release preflight, read-only config/Redis.
# Never echoes raw client IP, URI, HTTP header, credentials or logs.
set -Eeuo pipefail
umask 077

MODE="$1"
if [[ "$#" -ne 1 || ( "$MODE" != '--audit' && "$MODE" != '--self-test' ) ]]; then
    echo 'FAIL: expected --self-test or --audit'
    exit 2
fi

if [[ "$MODE" == '--self-test' ]]; then
    bash -n "$0"
    php -d auto_prepend_file= -d auto_append_file= -r '
$pattern = "~\\" . chr(36)
    . "redis\\s*->\\s*connect\\s*\\(\\s*([\"\\x27])([^\"\\x27]+)\\1\\s*,\\s*([0-9]{1,5})~";
$fixture = "\$redis->connect(\"127.0.0.1\",6379,0.75);";
$n = preg_match_all($pattern, $fixture, $m, PREG_SET_ORDER);
if ($n !== 1 || ($m[0][2] ?? null) !== "127.0.0.1"
    || (int)($m[0][3] ?? 0) !== 6379) {
    echo "FAIL: Redis target source parser fixture\n";
    exit(1);
}
echo "PASS: Redis source-only loopback endpoint parser fixture\n";
'
    echo 'PASS: source-only proxy/Redis preflight Bash grammar'
    echo 'MODE=OFFLINE_SELF_TEST'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'FINAL_STATUS=PASS'
    exit 0
fi

FAILURES=0
WARNINGS=0
finish() {
    local rc=$?
    trap - EXIT
    echo
    echo '=== 5. RESULT ==='
    echo 'MODE=READ_ONLY'
    echo "PASSIVE_WARNINGS=$WARNINGS"
    echo 'WEBSITE_PUBLIC_ROLLOUT=NOT_DEPLOYED'
    echo 'RELEASE_READY=NO'
    echo 'MYSQL_CONNECTION=NO'
    echo 'MYSQL_WRITE=NO'
    echo 'REDIS_DATA_WRITE=NO'
    echo 'SITE_CODE_WRITE=NO'
    echo 'SMS_SENT=NO'
    echo 'TRUSTED_DEVICE_WRITE=NO'
    echo 'V4_WRITE=NO'
    echo 'V5_AUTH_WRITE=NO'
    echo 'IP_OR_CREDENTIAL_VALUES_PRINTED=NO'
    if (( rc == 0 )); then
        echo 'FINAL_STATUS=PASS'
    else
        echo 'FINAL_STATUS=FAIL'
    fi
    echo "FINAL_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
    exit "$rc"
}
trap finish EXIT

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
CP='/root/ev-web-mfa-checkpoints/public-web-mfa-trust-v3-20261023-221046-448854e0'

echo '=== 1. HOST / SOURCE PIN GUARDS ==='
[[ "$(hostname -s)" == 'evrasia' && "$(id -u)" -eq 0 ]] || {
    echo 'FAIL: wrong host or user'; exit 1;
}
[[ -d "$SITE" && ! -L "$SITE" && "$(realpath -e "$SITE")" == "$SITE" ]] || {
    echo 'FAIL: site root drift'; exit 1;
}
[[ -d "$CP" && ! -L "$CP" && "$(realpath -e "$CP")" == "$CP" &&
    "$(stat -c '%u:%a' "$CP")" == '0:700' ]] || {
    echo 'FAIL: pinned private checkpoint missing or unsafe'; exit 1;
}
for cmd in php sha256sum grep awk wc tail stat; do
    command -v "$cmd" >/dev/null 2>&1 || {
        echo "FAIL: missing $cmd"; exit 1;
    }
done

pinned() {
    local expected="$1" f="$2"
    [[ -f "$f" && ! -L "$f" && -r "$f" ]] || {
        echo 'FAIL: protected source missing'; exit 1;
    }
    [[ "$(sha256sum "$f" | awk '{print $1}')" == "$expected" ]] || {
        echo 'FAIL: source SHA changed'; exit 1;
    }
}
pinned '6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9' \
    "$SITE/local/components/eurasia/signin/class.php"
pinned '27de8a0d74b1613672740b2899afcc7a333b2f8e40557f5095fd98ad9ee6608e' \
    "$SITE/local/components/eurasia/totp.enrollment/class.php"
pinned '04d254d7667c29819557d4df269760fa09e1561d2e6185fe8ab68a3a2d7f3770' \
    "$CP/signin.candidate.php"
pinned '763a0160f8e21d31bfe7a03cc9472bc94410835532299d57265ad55f06eb6ddc' \
    "$CP/enrollment.candidate.php"
pinned 'ef0ed60aafceea3d82dd65f239c01dfe9f708bddfdb68fb5a64f8078b3d38b2f' \
    "$CP/budget.candidate.php"
echo 'PASS: live auth, TOTP, v3 signin/enrollment and SMS quota SHA'

echo
echo '=== 2. PROXY DIRECTIVE STRUCTURAL REVIEW ==='
count_directives() {
    local pattern="$1"
    shift
    {
        grep -rEi --include='*.conf' --include='*.include' "$pattern" "$@" 2>/dev/null || true
    } | wc -l | tr -d ' '
}
APACHE_HEADER=0
APACHE_TRUSTED=0
APACHE_INTERNAL=0
APACHE_MODULE='UNKNOWN'
if [[ -d /etc/apache2 ]]; then
    APACHE_HEADER="$(count_directives '^[[:space:]]*RemoteIPHeader[[:space:]]+' /etc/apache2)"
    APACHE_TRUSTED="$(count_directives '^[[:space:]]*RemoteIPTrustedProxy[[:space:]]+' /etc/apache2)"
    APACHE_INTERNAL="$(count_directives '^[[:space:]]*RemoteIPInternalProxy[[:space:]]+' /etc/apache2)"
    if command -v apache2ctl >/dev/null 2>&1; then
        if { apache2ctl -M 2>/dev/null || true; } |
            grep -Eqi 'remoteip_module'; then
            APACHE_MODULE='YES'
        else
            APACHE_MODULE='NO_OR_UNAVAILABLE'
        fi
    fi
fi
NGINX_FORWARDED=0
NGINX_REAL=0
for dir in /etc/nginx /home/site_evrasia/conf/web; do
    if [[ -d "$dir" ]]; then
        n="$(count_directives '^[[:space:]]*proxy_set_header[[:space:]]+X-Forwarded-For[[:space:]]+' "$dir")"
        NGINX_FORWARDED=$((NGINX_FORWARDED+n))
        n="$(count_directives '^[[:space:]]*proxy_set_header[[:space:]]+X-Real-IP[[:space:]]+' "$dir")"
        NGINX_REAL=$((NGINX_REAL+n))
    fi
done
echo "APACHE_REMOTEIP_MODULE=$APACHE_MODULE"
echo "APACHE_REMOTEIPHEADER_DIRECTIVES=$APACHE_HEADER"
echo "APACHE_TRUSTED_PROXY_DIRECTIVES=$APACHE_TRUSTED"
echo "APACHE_INTERNAL_PROXY_DIRECTIVES=$APACHE_INTERNAL"
echo "NGINX_FORWARDED_FOR_DIRECTIVES=$NGINX_FORWARDED"
echo "NGINX_REAL_IP_FORWARDING_DIRECTIVES=$NGINX_REAL"
if [[ "$APACHE_MODULE" == YES && "$APACHE_HEADER" -gt 0 &&
    $((APACHE_TRUSTED+APACHE_INTERNAL)) -gt 0 ]]; then
    echo 'APACHE_CLIENT_IP_CONFIGURATION=STRUCTURALLY_PRESENT'
else
    echo 'APACHE_CLIENT_IP_CONFIGURATION=UNPROVEN'
    WARNINGS=$((WARNINGS+1))
fi
echo 'PHP_REMOTE_ADDR_REAL_REQUEST_CONFIRMED=NO'

echo
echo '=== 3. APACHE ACCESS LOG IP CARDINALITY (NUMBERS ONLY) ==='
LATEST=''
for f in /var/log/apache2/*access*.log /home/site_evrasia/logs/*access*.log; do
    [[ -f "$f" && ! -L "$f" && -r "$f" ]] || continue
    if [[ -z "$LATEST" || "$f" -nt "$LATEST" ]]; then
        LATEST="$f"
    fi
done
if [[ -n "$LATEST" ]]; then
    tail -n 2500 "$LATEST" | php -d auto_prepend_file= -d auto_append_file= -r '
$counts = [];
$valid = 0; $invalid = 0;
while (($line = fgets(STDIN)) !== false) {
    $space = strcspn($line, " \t");
    $addr = substr($line, 0, $space);
    if (filter_var($addr, FILTER_VALIDATE_IP) === false) {
        ++$invalid; continue;
    }
    ++$valid;
    $key = hash("sha256", $addr);
    $counts[$key] = ($counts[$key] ?? 0) + 1;
}
echo "APACHE_ACCESS_SAMPLE_VALID_IP_ROWS=$valid\n";
echo "APACHE_ACCESS_SAMPLE_UNRECOGNIZED_ROWS=$invalid\n";
echo "APACHE_ACCESS_SAMPLE_UNIQUE_IP_COUNT=" . count($counts) . "\n";
echo "APACHE_ACCESS_SAMPLE_TOP_IP_SHARE_PCT="
    . ($valid > 0 ? round(100 * max($counts) / $valid, 1) : "UNAVAILABLE")
    . "\n";
echo "APACHE_ACCESS_SAMPLE_IS_NOT_PHP_REMOTE_ADDR_PROOF=YES\n";
'
else
    echo 'APACHE_ACCESS_SAMPLE=UNAVAILABLE'
    WARNINGS=$((WARNINGS+1))
fi

echo
echo '=== 4. PHPREDIS LOOPBACK READ-ONLY EVAL PROBE ==='
php -d auto_prepend_file= -d auto_append_file= \
    -d opcache.enable_cli=0 -r '
$root="/home/site_evrasia/web/evrasia.spb.ru/public_html/";
$files=[
    "local/components/eurasia/signin/class.php",
    "local/components/eurasia/totp.enrollment/class.php"
];
echo "PHPREDIS_EXTENSION=" . (extension_loaded("redis") ? "YES":"NO") . "\n";
if (!extension_loaded("redis")) {
    echo "REDIS_EVAL_READONLY=UNAVAILABLE\n";
    exit(0);
}
$targets = [];
foreach ($files as $path) {
    $source=file_get_contents($root . $path);
    if (!is_string($source)) {
        echo "REDIS_TARGET_READING=FAILED\n"; exit(0);
    }
    // Avoid PHP variable interpolation when matching the literal "$redis".
    $pattern = "~\\" . chr(36)
        . "redis\\s*->\\s*connect\\s*\\(\\s*([\"\\x27])([^\"\\x27]+)\\1\\s*,\\s*([0-9]{1,5})~";
    if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === false) {
        echo "REDIS_TARGET_PARSER=FAILED\n";
        exit(0);
    }
    foreach ($matches as $m) {
        $host=$m[2]; $port=(int)$m[3];
        if (!in_array($host, ["127.0.0.1","localhost","::1"], true)
            || $port < 1 || $port > 65535) {
            echo "REDIS_TARGET_SCOPE=NONLOOPBACK_OR_UNKNOWN\n";
            echo "REDIS_EVAL_READONLY=NOT_EXECUTED\n";
            exit(0);
        }
        $targets[$host . ":" . $port]=[$host,$port];
    }
}
echo "REDIS_STATIC_LOOPBACK_TARGETS=" . count($targets) . "\n";
if (count($targets) === 0) {
    echo "REDIS_EVAL_READONLY=TARGET_UNRESOLVED\n";
    exit(0);
}
$passed=0;$failed=0;
foreach ($targets as [$host,$port]) {
    try {
        $redis = new Redis();
        if (!$redis->connect($host,$port,0.75)) {
            ++$failed; continue;
        }
        // No KEYS, no INCR, no EXPIRE, no SMS, no quota counter touched.
        $value=$redis->eval("return 1",[],0);
        if ($value === 1 || $value === "1") ++$passed;
        else ++$failed;
        $redis->close();
    } catch (Throwable $e) {
        ++$failed;
    }
}
echo "REDIS_READONLY_EVAL_PASS_COUNT=$passed\n";
echo "REDIS_READONLY_EVAL_FAIL_COUNT=$failed\n";
echo "REDIS_INCR_EXPIRE_WRITE_PERMISSIONS_TESTED=NO\n";
echo "GLOBAL_SMS_BUDGET_PRODUCTION_ACTIVE=NO\n";
'
echo 'PASS: read-only evidence captured; RELEASE_READY=NO'
