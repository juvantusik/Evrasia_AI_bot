# V5 MySQL atomic attempt store — isolated staging acceptance

Status: **READY FOR STAGING EXECUTION; NOT YET EXECUTED AGAINST A REAL MYSQL SERVER**.

This is a focused concurrency test for the existing offline-tested
`V5MySqlAttemptWindowStore`. It does NOT deploy API V5, validate Bitrix
credentials, send SMS, read a real user, or authorize a session.

## Files

- `V5MySqlAttemptStoreIntegration.php` — performs guarded actions inside an
  explicitly identified, pre-existing, disposable local test database.
- `run_mysql_attempt_concurrency.sh` — starts 24 independent PHP CLI processes,
  waits until all 24 have opened checked database connections, releases them
  simultaneously, and verifies the counters and a second rollback scenario.

## Safety guards

**Never run this test on `evrasia` or `eur-bot-01`.**
Both the Bash launcher and PHP worker reject those application hostnames.

Required staging-only environment variables (load via a secure test secrets
mechanism, never paste credentials into chat, commands or CI logs):

| Variable | Constraint |
|---|---|
| `V5_TEST_WRITE_ACK` | exactly `ISOLATED_STAGING_ONLY` |
| `V5_TEST_DSN` | exactly `mysql:host=127.0.0.1;port=3306;dbname=evrasia_v5_test_<unique>;charset=utf8mb4` (port may differ; named schema must begin `evrasia_v5_test_`) |
| `V5_TEST_DB_USER` | dedicated staging test user, grants restricted to this schema |
| `V5_TEST_DB_PASSWORD` | password from a staging secret provider; never print |
| `V5_TEST_MYSQL_HOSTNAME` | exact return of the test server's `SELECT @@hostname`, **not** a production hostname |

Pre-create only the **empty test database** with its own least-privilege MySQL
user on a dedicated staging server. At least 24 concurrent local MySQL sessions
must be permitted. Do **not** use any .settings.php credentials, live schema,
production database connection, or live V4/V5 endpoint.

The PHP worker independently checks the exact DSN shape, resolved database
name, DB server hostname, local host and explicit test-only acknowledgement
before SQL changes. On first `prepare` it may create only the
`ev_v5_auth_attempt_windows` table inside the dedicated test schema.
This table is NOT the production table or a Bitrix migration. The runner
generates a unique synthetic run ID, inserts only 8 synthetic HMAC keys and
removes **those exact keys** on exit. It leaves the test table in place.

## How to use (on staging only)

Syntax check, **no database access or writes**:

`bash site-integration/samzaberu-v5/tests/integration/run_mysql_attempt_concurrency.sh --lint-only`

After a staging operator has supplied the five variables through the staging
secret environment, run the same script **without** `--lint-only`. The script
must produce structured `PASS/FAIL` output with credentials hidden. If cleanup
cannot be confirmed it prints `TEST_ROWS_CLEANED=NOT_CONFIRMED` and an operator
must reconcile the dedicated test schema before the next run.

## Mandatory acceptance conditions

1. 24 **independent** database-connected workers reach the synchronization barrier.
2. With four shared test windows each capped at 7, exactly **7 ALLOWED**, **17
   DENIED**, and **0 ERRORS** result.
3. Four database rows each contain exactly `used_count = 7`, proving no
   concurrent oversubscription.
4. A second synthetic case caps only the last sorted bucket at 1. The first
   reservation succeeds, the second is denied, and **all four counters remain 1**.
   This proves previously incremented counters were rolled back.
5. The exact eight generated HMAC keys are removed; no other table is deleted
   or truncated, and the test DDL remains isolated.
6. `FINAL_STATUS=PASS` is only valid if every check and cleanup succeeds.

**Local status as of 2026-10-11:** PHP 8.4.24 lint PASS, Bash lint PASS.
The no-authorization guard rejected execution in the local environment before
a DB connection. **No real MySQL server or `pdo_mysql` driver was available
here.** Therefore true MySQL concurrency remains **PENDING**, regardless of
the earlier 144/144 mocked-PDO/logic assertions.

## What still blocks enabling V5

This test covers **first-factor login attempt caps only**, not SMS quotas,
V3C/native TOTP atomic replay, verified-account phone selection, independent
V5 JWT assurance, Trusted Device strong-enrollment proofs, or Bitrix
`V5CurrentLoginControlsPort` / `V5PerUserSmsEligibilityPort` production adapters.
V5 still serves 503. V4 and production are unchanged.
