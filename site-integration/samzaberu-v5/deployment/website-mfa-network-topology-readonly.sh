#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

if [[ $# -ne 1 || ( "$1" != '--audit' && "$1" != '--self-test' ) ]]; then
    echo 'FAIL: only --audit or --self-test'
    exit 2
fi
MODE="$1"
finish() {
    local rc=$?
    trap - EXIT
    echo
    echo '=== 5. RESULT ==='
    echo "MODE=$MODE"
    echo 'MODE_PRODUCTION=READ_ONLY'
    echo 'SITE_CODE_WRITE=NO'
    echo 'TEMP_PUBLIC_PHP_CREATED=NO'
    echo 'PUBLIC_PHP_FILE_CREATED=NO'
    echo 'DB_DIRECT_CONNECTION=NO'
    echo 'REDIS_CONNECTION=NO'
    echo 'SMS_SENT=NO'
    echo 'TRUSTED_DEVICE_WRITE=NO'
    echo 'V4_WRITE=NO'
    echo 'V5_WRITE=NO'
    echo 'RAW_IP_AND_CREDENTIALS_PRINTED=NO'
    echo 'HTTP_HEAD_MAY_CREATE_ACCESS_LOGS=YES'
    echo 'PUBLIC_MFA_NOT_DEPLOYED=YES'
    if (( rc == 0 )); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
    exit "$rc"
}
trap finish EXIT

count_port() {
    local port="$1"
    awk -v suffix=":$port" '
      NF>=5 {
        a=$4
        if (length(a)>=length(suffix) &&
            substr(a,length(a)-length(suffix)+1)==suffix) n++
      }
      END {print n+0}'
}

if [[ "$MODE" == '--self-test' ]]; then
    bash -n "$0"
    fixture="$(printf '%s\n' 'LISTEN 0 128 127.0.0.1:80 0.0.0.0:*' \
        'LISTEN 0 128 *:443 *:*' 'LISTEN 0 128 [::]:443 [::]:*')"
    [[ "$(printf '%s\n' "$fixture" | count_port 443)" == 2 ]] || {
        echo 'FAIL: synthetic listener parser'; exit 1;
    }
    echo 'PASS: Bash syntax and synthetic port count'
    exit 0
fi

echo '=== 1. PINNED HOST / WEBSITE ==='
SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
CP='/root/ev-web-mfa-checkpoints/public-web-mfa-trust-v3-20261023-221046-448854e0'
[[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] || {
    echo 'FAIL: wrong host or user'; exit 1;
}
[[ -d "$SITE" && ! -L "$SITE" && "$(realpath -e "$SITE")" == "$SITE" ]] || {
    echo 'FAIL: unexpected Bitrix site root'; exit 1;
}
[[ -d "$CP" && ! -L "$CP" && "$(realpath -e "$CP")" == "$CP" ]] || {
    echo 'FAIL: verified checkpoint absent'; exit 1;
}
for tool in ss curl sha256sum awk getent sort comm wc; do
    command -v "$tool" >/dev/null 2>&1 || {
        echo "FAIL: missing $tool"; exit 1;
    }
done
pin() {
    local f="$1" sha="$2" current
    [[ -f "$f" && ! -L "$f" ]] || { echo 'FAIL: source file guard'; exit 1; }
    current="$(sha256sum "$f" | awk '{print $1}')"
    [[ "$current" == "$sha" ]] || { echo 'FAIL: source SHA drift'; exit 1; }
}
pin "$SITE/local/components/eurasia/signin/class.php" \
    '6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9'
pin "$CP/signin.candidate.php" \
    '04d254d7667c29819557d4df269760fa09e1561d2e6185fe8ab68a3a2d7f3770'
pin "$CP/budget.candidate.php" \
    'ef0ed60aafceea3d82dd65f239c01dfe9f708bddfdb68fb5a64f8078b3d38b2f'
echo 'PASS: live website SHA and v3 checkpoint'
echo 'PUBLIC_DOMAIN=evrasia.rest'

echo
echo '=== 2. LISTENING WEB PORTS ==='
sockets="$(ss -H -ltn 2>/dev/null)"
for port in 80 443 8080 8081 8443 8000; do
    n="$(printf '%s\n' "$sockets" | count_port "$port")"
    echo "TCP_LISTEN_PORT_$port=$n"
done
if command -v systemctl >/dev/null 2>&1; then
    for svc in apache2 nginx haproxy; do
        status="$(systemctl is-active "$svc" 2>/dev/null || true)"
        case "$status" in
            active|inactive|failed|activating|deactivating) ;;
            *) status='UNKNOWN_OR_ABSENT' ;;
        esac
        echo "SERVICE_$svc=$status"
    done
fi

echo
echo '=== 3. CANONICAL DOMAIN DNS TO LOCAL INTERFACE ==='
dns4="$({ getent ahostsv4 evrasia.rest 2>/dev/null || true; } |
    awk 'NF>0 {print $1}' | sort -u)"
local4=''
if command -v ip >/dev/null 2>&1; then
    local4="$({ ip -o -4 addr show scope global 2>/dev/null || true; } |
        awk 'NF>=4 {split($4,a,"/"); print a[1]}' | sort -u)"
fi
dns_n="$(printf '%s\n' "$dns4" | awk 'NF {n++} END {print n+0}')"
local_n="$(printf '%s\n' "$local4" | awk 'NF {n++} END {print n+0}')"
common="$(comm -12 <(printf '%s\n' "$dns4" | awk 'NF' | sort -u) \
    <(printf '%s\n' "$local4" | awk 'NF' | sort -u) | wc -l | tr -d ' ')"
echo "PUBLIC_DNS_IPV4_COUNT=$dns_n"
echo "HOST_LOCAL_INTERFACE_IPV4_COUNT=$local_n"
echo "CANONICAL_DNS_MATCHES_LOCAL_INTERFACE=$common"
echo 'RAW_IP_VALUES_PRINTED=NO'

echo
echo '=== 4. HTTPS / INTERNAL HTTP ROUTES (HTTP STATUS OR CURL EXIT ONLY) ==='
probe() {
    local label="$1" code rc
    shift
    if code="$(curl --silent --head --noproxy '*' \
        --connect-timeout 3 --max-time 6 --retry 0 \
        --output /dev/null --write-out '%{http_code}' "$@" 2>/dev/null)"; then
        if [[ "$code" =~ ^[1-5][0-9][0-9]$ ]]; then
            echo "$label=HTTP_$code"
        else
            echo "$label=CURL_SUCCESS_NO_HTTP"
        fi
    else
        rc=$?
        case "$rc" in
            6) echo "$label=DNS_FAILURE_6" ;;
            7) echo "$label=CONNECT_FAILURE_7" ;;
            28) echo "$label=TIMEOUT_28" ;;
            35) echo "$label=TLS_HANDSHAKE_FAILURE_35" ;;
            51|60) echo "$label=TLS_CERTIFICATE_FAILURE_$rc" ;;
            *) echo "$label=OTHER_CURL_ERROR_$rc" ;;
        esac
    fi
}
echo 'TLS_VERIFICATION=ENABLED'
probe 'CANONICAL_PUBLIC_HTTPS' 'https://evrasia.rest/'
probe 'LOCAL_LOOPBACK_HTTPS_443' \
    --resolve 'evrasia.rest:443:127.0.0.1' 'https://evrasia.rest/'
for port in 80 8080 8081 8000; do
    n="$(printf '%s\n' "$sockets" | count_port "$port")"
    if (( n > 0 )); then
        probe "LOCAL_HTTP_$port" \
            --resolve "evrasia.rest:$port:127.0.0.1" \
            "http://evrasia.rest:$port/"
    else
        echo "LOCAL_HTTP_$port=NO_LISTENER"
    fi
done
n="$(printf '%s\n' "$sockets" | count_port 8443)"
if (( n > 0 )); then
    probe 'LOCAL_HTTPS_8443' \
        --resolve 'evrasia.rest:8443:127.0.0.1' 'https://evrasia.rest:8443/'
else
    echo 'LOCAL_HTTPS_8443=NO_LISTENER'
fi
attempts=0
while IFS= read -r address; do
    [[ -n "$address" ]] || continue
    if (( attempts >= 4 )); then break; fi
    attempts=$((attempts+1))
    probe "HOST_INTERFACE_HTTPS_443_ROUTE_$attempts" \
        --resolve "evrasia.rest:443:$address" 'https://evrasia.rest/'
done <<< "$local4"
echo 'EXTERNAL_CLIENT_PHP_REMOTE_ADDR_STILL_UNVERIFIED=YES'
echo 'PRODUCTION_INSTALL_APPROVED_BY_THIS_TEST=NO'
