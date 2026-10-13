# V5 — pinned read-only native Bitrix transaction depth reader

**2026-10-13. Status: GITHUB-ONLY / OFFLINE TESTED 18/18 / NOT DEPLOYED.**

## Confirmed source evidence

Operator read-only output on `evrasia` returned `FINAL_STATUS=PASS`, `PASS_COUNT=6`, `FAIL_COUNT=0`, pinned SHA matched for `mysqlcommonconnection.php` and `mysqliconnection.php`. Actual `MysqlCommonConnection` declares `protected int $transactionLevel = 0` at line 12. `MysqliConnection extends MysqlCommonConnection`. In `startTransaction`, level 0 issues `START TRANSACTION`, nested level issues `SAVEPOINT TRANS{level}`, then increments depth. `commitTransaction` decrements and issues `COMMIT` only at zero. `rollbackTransaction` decrements and issues `ROLLBACK` at zero; nested rollback issues `ROLLBACK TO SAVEPOINT` then throws `TransactionException`. No public depth accessor was found in these source files.

This is a static source observation, NOT runtime transaction-ownership evidence.

## New candidate

`site-integration/samzaberu-v5/src/V5VerifiedBitrixNativeTransactionDepth.php` implements the previously missing `V5VerifiedNativeTransactionDepthPort` without modifying the established staging permit or bridge. `V5NativeDepthShapeReader` uses PHP Reflection to read, never set, the protected depth integer. It checks exact class, native inheritance, declared transaction methods, protected nonstatic int field, default zero and initialized nonnegative value. The site-oriented provider also requires CLI, staging acknowledgement, nonproduction hostname and exact source SHA-256 pins for three Bitrix DB classes, all from the same directory. Any unknown/mismatched condition returns `null`.

This is ONLY a proposed input to the existing `V5StagingNativeTransactionPermit`: a depth of zero is not sufficient to prove ownership, and the permit separately requires exact `Application::getConnection()` identity, two SQL session snapshots, isolation and explicit staging authorization. No live code was loaded on production.

## Verification / limitations

`tests/V5VerifiedBitrixNativeTransactionDepthTest.php` passed **18/18 synthetic PHP 8.4.24 assertions** (along with source/test PHP lint). The shape-only reader exercised 0→1→2→1→0, unknown subclass/object, negative/uninitialized depth. The pinned provider correctly denied a synthetic source whose SHA does not match real Bitrix. **There has been NO successful real-Bitrix runtime read**, no real MySQL staging transaction, and no staging E2E. Real hash-pinned positive path, query/commit/rollback semantics and concurrent ownership remain unverified.

Git blobs: source `a4f67cc39049dd53133d883e9bdd138e6d3cebe6`; test `02e56bdf16311fd4d7eb35734af160fbb4e3f809`.

**Next:** target a physically isolated Bitrix staging instance; first prove the hash-pinned reader on the actual loaded classes without beginning any transaction, then the existing permit + guarded bidirectional ORM/native rollback acceptance, followed by 10-path native MFA/security event atomics. No production SQL, hooks, migration, SMS or V5 activation.

`PRODUCTION_WRITE=NO` · `V4_UNCHANGED=YES` · `V5_HTTP=503` · `REAL_STAGING_E2E=NOT_RUN`.

## Staging preflight & acceptance hardening (2026-10-13)

Implemented `tests/integration/V5BitrixNativeDepthReadOnlyPreflight.php` as a fail-closed *real staging-only* CLI preflight. After strict pre-bootstrap guards for application hostname, canonical staging Bitrix root and dedicated MySQL schema/server, it loads the actual Bitrix classes and checks the pinned Reflection depth reader plus the existing `V5StagingNativeTransactionPermit` (two read-only SQL snapshots; no explicit BEGIN/COMMIT/ROLLBACK or script DML). Bitrix bootstrap **may have side effects**, so preflight is prohibited on production and must only use credentials for physically isolated staging. PHP 8.4.24 lint PASS and two missing/invalid-stage negative launches returned `STAGING_BITRIX_BOOTSTRAP=NO` / `FINAL_STATUS=FAIL`, before any DB access. New preflight Git blob `2fe2effbbaf634059698d4f4206eb55eb923291e` matched the linted local bytes.

The existing `tests/integration/V5BitrixNativeOrmTransactionAcceptance.php` was minimally hardened to require the **actual pinned** depth reader and staging permit before both transactions, stable `CONNECTION_ID()`, session `in_transaction=1` and Bitrix depth 1 after BEGIN, and both reset to zero/idle after ROLLBACK. Unknown ownership cannot trigger an automatic rollback of another scope. The standalone SQL session-state helper passed **14 synthetic checks** under PHP 8.4.24; full revised acceptance lint / real execution and its staging E2E remain pending. The original fake two-direction smoke was for a **prior file revision** and cannot be represented as a pass for the new version. New acceptance Git blob `eb0e5f0ad6326eb45abb09b98964781fd0ba670c`.

**Next approved sequence:** establish isolated staging Bitrix+MySQL with independently controlled credentials; run only the read-only preflight, capture its complete result; after a real preflight PASS and explicit staging-write approval, lint and run the separately guarded ORM/native rollback test on the *synthetic-only* test table; then independently investigate the 10-path native MFA/security event atomics. No staging test has actually been run.

`PRODUCTION_WRITE=NO` · `BITRIX_SITE_WRITE=NO` · `V4_UNCHANGED=YES` · `V5_HTTP=503` (last documented state) · `REAL_STAGING_E2E=NOT_RUN`.


## 2026-10-13 — isolated PHP CI and staging readiness

GitHub-only `.github/workflows/v5-offline-safety.yml` added in the V5 feature branch. Runs only for that branch's V5 path/workflow changes or manually; restricted `permissions: contents: read`, `actions/checkout@v5` with `persist-credentials: false`, Docker PHP 8.4 CLI with `--network none`, `--read-only`, read-only source mount and ephemeral `/tmp`. It does not use repository secrets, start Bitrix, connect to MySQL, send SMS, build the server image, or deploy.

**Actually verified GitHub Actions run:** [38361251912](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38361251912), commit `8feacb94810b6ba8ff11c5382b3bfe1ee482b094`, `completed/success`, PHP **8.4.26**. Source tests: pinned native-depth reader **18 assertions PASS**, staging permit **62 PASS**, Bitrix Mysqli compatibility bridge **80 PASS** (total **160 synthetic assertions**). Eight PHP source/test/entrypoint lint checks passed, and **both** staging preflight/rollback entrypoints correctly failed before bootstrap/DB when the staging acknowledgement was absent. This is a checked CI run, not a real Bitrix runtime / staging E2E.

A **physically isolated Bitrix staging is not recorded as provisioned** in the current GitHub docs. Historical `staging` Compose changes for the production bot are NOT an isolated Bitrix. New phase gates and independence requirements are recorded at `docs/SAMZABERU_V5_ISOLATED_STAGING_READINESS_2026-10-13.md` (feature branch only). Do not run `V5BitrixNativeDepthReadOnlyPreflight.php` on `evrasia`: even though it has no explicit DML or BEGIN, the Bitrix bootstrap may have side effects.

**Current blocking condition:** `STAGING_ISOLATION_ATTESTED=NO`; `REAL_STAGING_BITRIX_RUNTIME_PROOF=NOT_RUN`; `REAL_ORM_MYSQL_ROLLBACK=NOT_RUN`; `V5_HTTP=503` (last documented production baseline). No production or V4 writes.


## 2026-10-13 — full V5 offline CI regression: 22 suites

The previous 3-suite CI risked missing regressions outside native transaction code (login, MFA, SMS cost, outbox, auth epoch). The existing feature-only workflow `.github/workflows/v5-offline-safety.yml` was updated to lint **all 48 PHP files** in `src`, `tests` and `api` and run a **fixed reviewed list of 22 offline synthetic test suites**. Staging integration executables are **linted but NOT executed positively**. Both missing-ack staging pre-bootstrap tests remain mandatory negative checks. Same Docker controls: `--network none`, `--read-only`, read-only checkout mount, tmpfs `/tmp`, PHP 8.4 CLI, no production credentials.

**Verified GitHub Actions run 38363042677** at commit `e034a9c751c99315307c648094c509103322edb4` finished `completed/success` with PHP 8.4.26, **PHP lint 48/48**, **offline suite PASS 22/22**, both negative staging entrypoint guards PASS, `FINAL_STATUS=PASS` and `FINAL_RC=0`. This supersedes the earlier narrower 3-suite CI run as the newest test coverage. Some test suites report cases rather than individual assertions; do not misleadingly sum heterogeneous counters or claim real-world E2E. All 22 remain synthetic or static source-contract checks.

**Current remaining blocker:** no authoritative evidence of an independently provisioned/isolated Bitrix + MariaDB/MySQL staging host. Real native Reflection depth proof, Bitrix bootstrap, physical ORM/MySQLi rollback and 10-path native security-event atomics remain untested. Do not run staging scripts on production `evrasia` or production bot `eur-bot-01`. Production/V4/DB/SMS unchanged; V5 503 last recorded baseline.
