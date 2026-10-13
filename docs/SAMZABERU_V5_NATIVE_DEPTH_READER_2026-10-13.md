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
