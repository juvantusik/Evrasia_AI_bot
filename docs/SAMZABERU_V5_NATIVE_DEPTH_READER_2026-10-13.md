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
