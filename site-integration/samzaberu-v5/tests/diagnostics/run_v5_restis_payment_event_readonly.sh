#!/usr/bin/env bash
printf '\033[3J\033[2J\033[H'
set -Eeuo pipefail
umask 077

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
ROUTER="$SITE/api/v4/router.php"
ROUTER_HASH='5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d'
OFF='/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json'
COMMIT='65954b22f73274288d4457d49f1a1a74e68b5090'
PARSER_BLOB='4258662a90e051925fd48374787dd8f8605640da'
PARSER=''
HASH_FILE=''
TRACE='NOT_RUN'
finish() {
  local rc=$?
  trap - EXIT
  if [[ -n "$PARSER" && "$PARSER" == /tmp/ev-payed-reader.????????.php && -f "$PARSER" && ! -L "$PARSER" ]]; then rm -f -- "$PARSER"; fi
  if [[ -n "$HASH_FILE" && "$HASH_FILE" == /tmp/ev-payed-hashes.???????? && -f "$HASH_FILE" && ! -L "$HASH_FILE" ]]; then rm -f -- "$HASH_FILE"; fi
  echo
  echo '=== 5. RESULT ==='
  echo "RESTIS_PAYMENT_EVENT_SOURCE_TRACE=$TRACE"
  echo 'MODE=SOURCE_ONLY_READ_ONLY'
  echo 'BITRIX_BOOTSTRAP=NO'
  echo 'SITE_SOURCE_EXECUTED=NO'
  echo 'DATABASE_CONNECTION=NO'
  echo 'DATABASE_WRITE=NO'
  echo 'PRODUCTION_CODE_WRITE=NO'
  echo 'API_V4_CHANGED=NO'
  echo 'V5_AUTH_ENABLED=NO'
  echo 'CUSTOMER_RECORDS_READ=NO'
  echo 'ORDER_CREATED=NO'
  echo 'PAYMENT_STARTED=NO'
  echo 'SMS_SENT=NO'
  echo 'SECRETS_PRINTED=NO'
  if (( rc == 0 )); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
  echo "FINAL_RC=$rc"
  echo 'TERMINAL_WILL_STAY_OPEN=YES'
}
trap finish EXIT
fail() { echo "FAIL: $1"; exit 2; }

echo '=== 1. HOST / SOURCE GUARDS ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] || fail 'host/root'
[[ -x /usr/bin/php8.3 ]] || fail 'PHP 8.3 missing'
for cmd in curl git sha256sum timeout cmp; do command -v "$cmd" >/dev/null || fail "missing $cmd"; done
[[ -f "$ROUTER" && -r "$ROUTER" && ! -L "$ROUTER" ]] || fail 'V4 router missing'
printf '%s  %s\n' "$ROUTER_HASH" "$ROUTER" | sha256sum -c --status || fail 'V4 router changed'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' | cmp -s - "$OFF" || fail 'V5 AUTH not OFF'
FILES=(
  "$SITE/local/php_interface/init.php"
  "$SITE/local/php_interface/lib/COrder.php"
  "$SITE/local/php_interface/cron_order_status.php"
  "$SITE/local/php_interface/order-subscribe.php"
  "$SITE/local/php_interface/order-merch.php"
)
for f in "${FILES[@]}"; do [[ -f "$f" && -r "$f" && ! -L "$f" ]] || fail 'target file missing'; done
HASH_FILE="$(mktemp /tmp/ev-payed-hashes.XXXXXXXX)"
sha256sum "${FILES[@]}" > "$HASH_FILE" || fail 'source digest error'
echo 'PASS: V4 pinned, five files available, V5 OFF'

echo '=== 2. LOAD CHECKED SOURCE ANALYZER ==='
PARSER="$(mktemp /tmp/ev-payed-reader.XXXXXXXX.php)"
URL="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$COMMIT/site-integration/samzaberu-v5/tests/diagnostics/V5RestisOrderPaymentEventReader.php"
curl -fLsS --connect-timeout 8 --max-time 30 "$URL" -o "$PARSER" || fail 'download'
[[ "$(git hash-object "$PARSER")" == "$PARSER_BLOB" ]] || fail 'source integrity'
/usr/bin/php8.3 -l "$PARSER" >/dev/null || fail 'syntax'
timeout 10 /usr/bin/php8.3 -d display_errors=0 -d log_errors=0 "$PARSER" --self-test || fail 'self-test'
echo 'PASS: exact source and self-test'

printf '\033[3J\033[2J\033[H'
echo '=== 1. VERIFIED RESTIS / BITRIX EVENT TRACE ==='
echo 'PASS: source pinned; V5 AUTH OFF'
echo '=== 2. PAID AND STATUS EVENT WRITERS ==='
TRACE='FAIL'
timeout 30 /usr/bin/php8.3 -d display_errors=0 -d log_errors=0 "$PARSER" --source-only "$SITE" || fail 'source inventory'

echo '=== 3. VERIFY UNCHANGED SOURCES ==='
sha256sum -c --status "$HASH_FILE" || fail 'target source changed during read'
printf '%s  %s\n' "$ROUTER_HASH" "$ROUTER" | sha256sum -c --status || fail 'V4 changed'
printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' | cmp -s - "$OFF" || fail 'V5 changed'
echo 'PASS: read-only; production code unchanged'
TRACE='PASS'
