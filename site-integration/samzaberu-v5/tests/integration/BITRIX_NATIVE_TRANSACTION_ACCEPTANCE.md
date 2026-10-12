# V5 — staging acceptance for native Bitrix ORM ↔ MySQLi transaction identity

**Status: PREPARED / PHP 8.4.24 lint PASS / NOT EXECUTED WITH REAL BITRIX OR MYSQL.**

This is a narrow acceptance test of the **existing** Bitrix native
`Application::getConnection()` and `DataManager` transaction relationship.
It is **not** authorization, SMS sending, the 10-quota store, or native MFA
hook proof. It should be run only on an operator-provisioned **isolated
Bitrix staging installation backed by its own synthetic MySQL schema**.

## Scope and hard safeguards

Test file: `V5BitrixNativeOrmTransactionAcceptance.php`.

- Requires CLI, `V5_STAGE_WRITE_ACK=ISOLATED_STAGING_ONLY` and staging
  root path `V5_STAGE_BITRIX_ROOT` that is canonical and not the known
  production site document root.
- Rejects the known production/application hosts `evrasia` and
  `eur-bot-01` **before Bitrix bootstrap**.
- Requires exact `V5_STAGE_DB_NAME` matching
  `evrasia_v5_test_[a-z0-9_]+`, and `V5_STAGE_DB_HOSTNAME` identifying
  the *actual nonproduction MySQL server*.
- Rechecks `SELECT DATABASE()`, `@@hostname`, `@@session.autocommit`,
  `@@session.in_transaction` from the **native** Bitrix connection;
  before any writes, requires the expected schema/host, autocommit 1,
  and no active native transaction.
- Requires a precreated, known `ev_v5_native_tx_probe` **InnoDB** table
  in the dedicated staging schema. SQL proposal only:
  `../../schema/PROPOSED_ev_v5_native_tx_probe.sql`; DO NOT apply on production.
- Never runs `CUser->Login`, tests a real account, selects a real
  `b_user` record, sends SMS, writes MFA, or installs an event hook.
  Generates new random synthetic 64-hex identifiers for both rows.
  **No CREATE/DROP/DELETE by the test.**
- In each test, writes *only* to `ev_v5_native_tx_probe` within its
  owned staging transaction, explicitly rolls back, and checks the
  synthetic row is gone.
- An exception or changed database identity fails closed; unexpected
  rollback/commit state requires operator review of the isolated table.
  The test does not silently delete unknown data.

## Two-direction runtime acceptance

1. `Application::getConnection()` and
   `V5NativeTxProbeTable::getConnection()` must return the **same PHP object**.
2. **ORM → native SQL:** native `startTransaction()`; ORM
   `V5NativeTxProbeTable::add()`; native SELECT sees the new row inside
   that transaction; native `rollbackTransaction()`; native SELECT sees
   no row afterward.
3. **Native SQL → ORM:** native `startTransaction()`; native SQL INSERT;
   ORM `V5NativeTxProbeTable::getList()` sees it inside transaction;
   native `rollbackTransaction()`; both ORM and native SELECT see no row.
4. Accept only when all checks pass, both directions report success,
   `TEST_ROWS_PERSISTED=0` and `FINAL_STATUS=PASS`.

This provides useful *real staging evidence* that the installed Bitrix
ORM is on the same native connection and that InnoDB rollback works. It
does **not** prove V5's exclusive transaction permit, all 10 native MFA
writers sharing b_user locks, or SMS outbox concurrency.

## Operator-only staging procedure (NOT for production)

Provision a dedicated staging Bitrix copy with a database whose name
matches `evrasia_v5_test_*` and its own credentials. Back up and
identify the staging database and server. After explicit staging change
approval, create only the one proposed test table. Supply these
nonsecret environment values locally on the staging host:

- `V5_STAGE_WRITE_ACK` = `ISOLATED_STAGING_ONLY`
- `V5_STAGE_BITRIX_ROOT` = staging Bitrix document root
- `V5_STAGE_DB_NAME` = dedicated test schema name
- `V5_STAGE_DB_HOSTNAME` = literal output of `SELECT @@hostname` for the
  isolated test server (not an application/production host)

Execute `php -l tests/integration/V5BitrixNativeOrmTransactionAcceptance.php`
first, then `php tests/integration/V5BitrixNativeOrmTransactionAcceptance.php`
from the `site-integration/samzaberu-v5` directory on **staging only**.
Do not paste credentials into GitHub issues/chats or set the test variables
on production. The test imports its Bitrix bootstrap **only after all
filesystem/acknowledgement/host guards have passed**. It performs a second
database-identity check **before** any synthetic write.

### Verified now (2026-10-12)

- PHP lint PASS, PHP 8.4.24.
- Without explicit staging variables, local dry execution **fails closed
  before bootstrap or DB**, `FINAL_STATUS=FAIL` and `DATABASE_WRITE=NO`.
- Exact Git blob of locally linted acceptance file:
  `4263d2131a2bf67769416da347a486b0c37c6817`.
- Proposed test DDL Git blob:
  `87c7f74eb0e085119e7b7b116afd071b6e83f197`.
- **Real Bitrix acceptance pending** — no staging site or MySQL was
  available to this authoring environment.

## Subsequent blockers

Even a real **PASS** above would not authorize V5:
`V5NativeBitrixExclusiveTransactionPermitPort` is not implemented;
`V5NativeSecurityHookCoveragePort` requires the already defined
**10-path atomic MFA/security event proof** (including the background
agent); verified phone event versions and AUTH_EPOCH native writers are
not installed; staging SMS/financial quotas, challenge consume, SMS
provider/idempotency and assured V5 JWT remain outstanding.

The V5 HTTP router still fails closed (503); V4 and production unchanged.


### LOCAL SYNTHETIC SMOKE (2026-10-12)

To validate the **acceptance script itself**, it was run with an
ephemeral local-only fake `Bitrix\Main\Application`,
`Bitrix\Main\ORM\Data\DataManager` and fake native connection.
This was **NOT real Bitrix, NOT MariaDB/MySQL** and is not a replacement
for the staging requirements above.

- `TESTED_DIRECTIONS=2`, `TEST_ROWS_PERSISTED=0`, `FINAL_STATUS=PASS`,
  `FINAL_RC=0` under the synthetic adapter.
- With the expected staging database server hostname deliberately
  mismatched, the script returned `FINAL_STATUS=FAIL`, `FINAL_RC=1`
  **before any INSERT**.
- Without explicit environment acknowledgement, the script returned
  `FINAL_STATUS=FAIL`, `DATABASE_WRITE=NO` before bootstrap.
- Source file PHP lint **PASS**, exact committed Git blob confirmed.

No fake Bitrix object, synthetic database credential or test source is
installed on the real website. `REAL_BITRIX_ORM_E2E=NOT_RUN`.
