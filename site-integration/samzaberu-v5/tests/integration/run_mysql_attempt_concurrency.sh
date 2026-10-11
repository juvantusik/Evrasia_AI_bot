#!/usr/bin/env bash
# STAGING ONLY: isolated real MySQL concurrency E2E for the V5 rate store.
# The dedicated evrasia_v5_test_* schema must already exist; NEVER pass the
# production Bitrix DSN. This script cannot create/modify any Bitrix table.
set -Eeuo pipefail
umask 077

HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
WORKER="$HERE/V5MySqlAttemptStoreIntegration.php"

if [[ "${1:-}" == '--lint-only' ]]; then
  bash -n -- "${BASH_SOURCE[0]}"
  php -l "$WORKER"
  printf 'MODE=LINT_ONLY\nDATABASE_WRITE=NO\nFINAL_STATUS=PASS\nFINAL_RC=0\n'
  exit 0
fi

printf '=== 1. STAGING / ENVIRONMENT ===\n'
HOST="$(hostname -s)"
printf 'MODE=ISOLATED_STAGING_TEST\n'
if [[ "$HOST" == 'evrasia' || "$HOST" == 'eur-bot-01' \
   || "${V5_TEST_WRITE_ACK:-}" != 'ISOLATED_STAGING_ONLY' \
   || ! -r "$WORKER" ]]; then
  printf 'FAIL: staging host / explicit test authorization guard\n'
  printf 'FINAL_STATUS=FAIL\nFINAL_RC=1\n'
  exit 1
fi
for var in V5_TEST_DSN V5_TEST_DB_USER V5_TEST_MYSQL_HOSTNAME; do
  if [[ -z "${!var:-}" ]]; then
    printf 'FAIL: missing isolated test connection setting\n'
    printf 'FINAL_STATUS=FAIL\nFINAL_RC=1\n'
    exit 1
  fi
done
if [[ ! "${V5_TEST_DSN}" =~ ^mysql:host=127\.0\.0\.1\;port=[1-9][0-9]{0,4}\;dbname=evrasia_v5_test_[a-z0-9_]{1,30}\;charset=utf8mb4$ ]]; then
  printf 'FAIL: dedicated test DSN guard\n'
  printf 'FINAL_STATUS=FAIL\nFINAL_RC=1\n'
  exit 1
fi
if ! php -m | grep -qi '^pdo_mysql$'; then
  printf 'FAIL: staging PHP lacks pdo_mysql\n'
  printf 'FINAL_STATUS=FAIL\nFINAL_RC=1\n'
  exit 1
fi
php -l "$WORKER" >/dev/null || exit 1
printf 'PASS: staging preflight (no DB connection yet)\n'

export V5_TEST_WRITE_ACK V5_TEST_DSN V5_TEST_DB_USER V5_TEST_MYSQL_HOSTNAME
# May be an empty password on an isolated local test-only database.
export V5_TEST_DB_PASSWORD="${V5_TEST_DB_PASSWORD:-}"
V5_TEST_RUN="$(php -r 'echo bin2hex(random_bytes(16));')"
V5_TEST_EXPIRY="$(( $(date -u +%s) + 3600 ))"
export V5_TEST_RUN V5_TEST_EXPIRY
V5_TEST_CONTROL_DIR="$(mktemp -d /tmp/evrasia-v5-concurrency.XXXXXXXX)"
export V5_TEST_CONTROL_DIR
STAGE_PREPARED=0
pids=()
WORKERS_REAPED=0

finish() {
  local rc=$?
  trap - EXIT
  set +e
  local clean_rc=0
  if (( WORKERS_REAPED == 0 && ${#pids[@]} > 0 )); then
    for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || true; done
    for pid in "${pids[@]}"; do wait "$pid" 2>/dev/null || true; done
  fi
  if (( STAGE_PREPARED == 1 )); then
    php "$WORKER" cleanup >/dev/null 2>&1
    clean_rc=$?
  fi
  rm -f -- "$V5_TEST_CONTROL_DIR/release" "$V5_TEST_CONTROL_DIR"/ready-* \
    "$V5_TEST_CONTROL_DIR"/worker-*
  rmdir -- "$V5_TEST_CONTROL_DIR" 2>/dev/null
  printf '\n=== 5. RESULT ===\n'
  printf 'MODE=ISOLATED_STAGING_TEST\nPRODUCTION_DATABASE_WRITE=NO\n'
  printf 'BITRIX_BOOTSTRAP=NO\nREAL_USER_DATA_READ=NO\n'
  printf 'SMS_SENT=NO\nJWT_CREATED=NO\nDB_CREDENTIALS_PRINTED=NO\n'
  if (( STAGE_PREPARED == 1 && clean_rc == 0 )); then
    printf 'TEST_ROWS_CLEANED=YES\n'
  else
    printf 'TEST_ROWS_CLEANED=NOT_CONFIRMED\n'
  fi
  if (( rc == 0 && clean_rc == 0 )); then
    printf 'FINAL_STATUS=PASS\nFINAL_RC=0\n'
    exit 0
  fi
  printf 'FINAL_STATUS=FAIL\nFINAL_RC=1\n'
  exit 1
}
trap finish EXIT

printf '\n=== 2. ISOLATED TEST TABLE CHECK ===\n'
# This creates at most the fixed V5 test table INSIDE the specifically
# allowlisted pre-existing staging schema. No production account data read.
php "$WORKER" prepare
STAGE_PREPARED=1
printf 'PASS: dedicated InnoDB table ready, test keys absent\n'

printf '\n=== 3. TRUE MULTI-PROCESS CONTENTION ===\n'
WORKERS=24
EXPECTED_ADMITTED=7
for (( i=1; i<=WORKERS; i++ )); do
  php "$WORKER" worker >"$V5_TEST_CONTROL_DIR/worker-$i" 2>&1 &
  pids+=("$!")
done
# Wait for each worker to open an independent, identity-checked MySQL
# connection, then release them together to contend for the same four rows.
ready_count=0
for (( n=0; n<300; n++ )); do
  ready_count=0
  for file in "$V5_TEST_CONTROL_DIR"/ready-*; do
    if [[ -f "$file" ]]; then
      ready_count=$((ready_count+1))
    fi
  done
  if (( ready_count == WORKERS )); then
    break
  fi
  sleep 0.1
done
if (( ready_count != WORKERS )); then
  printf 'FAIL: not all independent MySQL workers reached the barrier\n'
  exit 1
fi
printf 'PASS: %s independent workers ready\n' "$WORKERS"
printf 'release\n' > "$V5_TEST_CONTROL_DIR/release"

allowed=0
denied=0
errors=0
for (( i=1; i<=WORKERS; i++ )); do
  if ! wait "${pids[$((i-1))]}"; then
    errors=$((errors+1))
    continue
  fi
  result="$(cat -- "$V5_TEST_CONTROL_DIR/worker-$i")"
  case "$result" in
    ALLOWED) allowed=$((allowed+1)) ;;
    DENIED) denied=$((denied+1)) ;;
    *) errors=$((errors+1)) ;;
  esac
done
WORKERS_REAPED=1
printf 'WORKERS=%s\nALLOWED=%s\nDENIED=%s\nERRORS=%s\n' \
  "$WORKERS" "$allowed" "$denied" "$errors"
if (( allowed != EXPECTED_ADMITTED || denied != WORKERS-EXPECTED_ADMITTED || errors != 0 )); then
  printf 'FAIL: concurrent admission invariant\n'
  exit 1
fi
php "$WORKER" verify-parallel
printf 'PASS: all four counters equal the admitted worker count\n'

printf '\n=== 4. ATOMIC ROLLBACK ON LAST BUCKET ===\n'
first="$(php "$WORKER" rollback)"
second="$(php "$WORKER" rollback)"
if [[ "$first" != 'ALLOWED' || "$second" != 'DENIED' ]]; then
  printf 'FAIL: last-bucket denial did not preserve atomicity\n'
  exit 1
fi
php "$WORKER" verify-rollback
printf 'PASS: all prior bucket increments rolled back on last-bucket denial\n'
