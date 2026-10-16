> **CI RESULT 2026-10-16: PASS (negative release guards only).** Code commit `884916fa132285cb9007c7981c33d6ec93b43699`; GitHub Actions offline `38898193783` SUCCESS/26 suites, ephemeral MariaDB `38898193583` SUCCESS/**543 total =43 sessions +500 dependent, including 13 new**. Source pin rejected test `Bitrix\\Security\\Mfa\\Otp` lookalike and arbitrary callback; no real core Otp.save/delete operation was performed. `NATIVE_OTP_SOURCE_MATCHED=NO`, `REAL_BITRIX_OTP_RUNTIME_TESTED=NO`. No production changes.

# СамЗаберу V5 — native Bitrix OTP pre-write adapter candidate

2026-10-16, GitHub-only. Production V5 OFF/HTTPS503; six new V5 InnoDB
tables created empty in production on 2026-10-15; per-user AUTH_EPOCH still
NOT initialized. No site factory, live OTP writes, SMS, native core patch,
or deployment in this change.

## Why a normal ORM hook is insufficient

Verified installed Bitrix native `Otp::save()` source hash:
`285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c`.
When the native Otp object was regenerated, save() clears recovery codes
BEFORE `UserTable::add()` or `update()`. The native
`RecoveryCodesTable::clearByUser()` ignores individual delete Result.
Native `Otp::delete()` returns `$this` without checking ORM Result, and
`UserTable::onAfterDelete` clears recovery codes after MFA delete SQL.
Therefore `OnBeforeAdd`/ordinary `OnAfter` handlers cannot guarantee a
common transaction for all native MFA operations or attest full deletion.
Official Bitrix docs describe public Otp::save/delete/getUserId/etc. API;
the checked source SHA is authoritative for our installed release.

## Code added, but deliberately not installed

`src/V5BitrixOtpNativeMutationPort.php` — exact-source-pinned,
non-HTTP adapter for a V5-owned, reviewed native MFA callsite. It requires:
- exact runtime class `Bitrix\Security\Mfa\Otp`, canonical source path,
  and exact prior observed SHA256. Any Bitrix version/source drift DENIES;
- an existing `V5BitrixPdoCompatibilityBridge` already in caller-owned native
  transaction with `Application::getConnection()` equal to native handle;
- the same explicit user ID as Otp::getUserId;
- for `OtpSaveAndEnrollment`, activated/initialized TOTP with a 20-byte
  secret, native `Otp::save() === true`, readback of unique locked `b_sec_user`
  row `ACTIVE=Y TYPE=totp SECRET=<expected hex>`, and NO rows in
  `b_sec_recovery_codes` under that transaction; secrets/codes NEVER logged;
- for `OtpDeleteAndDisable`, an existing MFA record BEFORE native
  `Otp::delete()`, then both MFA and recovery tables empty for user
  before COMMIT. Native delete returning `$this` alone is INSUFFICIENT.
- recovery count verification works only for this owned callsite and
  does not fix native independent recovery-code useCode race semantics.

`V5StagingNativeMutationScope` expands its supported mode enum only with
`OtpSaveAndEnrollment` and `OtpDeleteAndDisable`. It requires the exact
`V5BitrixOtpNativeMutationPort` typed adapter and matching mode BEFORE
BEGIN; arbitrary callbacks cannot claim OTP coverage. The existing
scope still requires independent FULL TEN-PATH native evidence and
native source/transaction proof. For accepted MFA operations it applies
`recordSecurityChanged` (epoch CAS) inside the same scope, retains the
SAVEPOINT COMMIT+BEGIN continuity guard and one commit/rollback policy.
No handler is registered; normal Bitrix MFA usage remains unaffected.

## Limitations / release blockers

- This is NOT a permanent fix for other Bitrix callsites. Global admin,
  `CSecurityUser::update`, `OtpEvents::onRecheckDeactivate`, recovery
  regeneration/consumption, password/reset/logout and phone paths still
  demand independently verified atomic hooks (10 total).
- `Otp::save()` may already clear recovery before ORM events; even positive
  postconditions cannot prove absence of a hidden earlier commit or a
  different native connection. SAVEPOINT guard plus exact source-pin and
  real isolated Bitrix transactional/concurrency E2E remain mandatory.
- This adapter explicitly supports TOTP only, not legacy HOTP. It doesn't
  activate native OTP, supply a secret, synchronize parameters, send SMS,
  or turn on V5; the trusted caller must already have a prepared Otp object.
- Real installed Bitrix source, MFA row semantics and recovery rows are not
  executed by ephemeral MariaDB CI; there is NO positive Bitrix native
  integration test. A synthetic Otp lookalike must be DENIED by the exact
  SHA256 source gate. Tests may prove negative routing/permissions only.
- The actual installed `b_sec_recovery_codes` table structure and native
  app transaction SQL behavior require targeted, separate preflight
  before any isolated real-write test. Do not guess or migrate production.
- Existing V4 accounts without AUTH_EPOCH must NOT have native MFA blocked
  globally. This adapter is a staged V5-only entrypoint candidate.

## Next

No production commands from this code change. CI must pass prior 530 checks
plus source-pin failure/unsafe-callback negative cases. Then validate the
candidate with a real Bitrix transaction on controlled isolated test records
and verify recovery cleanup rollback under forced failure. No general hooks
or per-user epoch initialization until complete ten-path evidence.
