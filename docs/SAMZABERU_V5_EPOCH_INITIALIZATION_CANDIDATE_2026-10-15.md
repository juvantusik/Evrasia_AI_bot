> **RESULT 2026-10-15: feature CI PASS.** Small NULL check fix in commit `45782ed0653b74a4eb5b4659551bfb70ad4a0ec1`; first ephemeral run `38782204373` found a real regression, corrected. Offline `38782468148` success/26 suites; ephemeral `38782468454` success/**39 new assertions**, total **445** actual InnoDB assertions across dependent suites. Positive native evidence is synthetic; neither this candidate nor tests provide the missing installed ten-path hooks/production transaction permit. No deployment; production release `0caa0248...` still OFF.

# V5 Pilot AUTH_EPOCH: one-user initializer (GitHub candidate, NOT deployed)

15 October 2026. Production DDL of six V5 tables finished PASS at 17:49. Tables are
empty; V5 still OFF/HTTPS503; no native factory and no AUTH_EPOCH rows.
Evidence: `/root/ev-v5-ddl-backup.20261015T174912Z.2010173` (schema only).
No operator action is needed for this GitHub-only increment.

## Why initialization is not a blanket INSERT

Existing `V5SecurityEventVersions` and `V5SecurityEventVersionWriter` deliberately
require an existing trusted `ev_samzaberu_auth_state` row. Even with tables present,
a user without such a row cannot pass password-bound epoch/phone checks. Native
Bitrix password/MFA/phone/logout/block/recovery mutations still lack attested
atomic ten-path hooks. Simply setting AUTH_EPOCH=1 for every user would create
false native-event coverage and is NOT an acceptable migration.

## New candidate code

`src/V5PilotAuthEpochInitializer.php` (new, not in installed release):
- constructor requires the **concrete** `V5BitrixPdoCompatibilityBridge` and
  **concrete** `V5StrictNativeSecurityHookCoverage`; generic true callback
  cannot satisfy that constructor;
- explicit operator-approved `list<int>` cohort of 1–32 unique IDs in signed
  native Bitrix `b_user.ID` range; no wildcard/strings/request-derived IDs;
- `initializeWithNativeLocks($bridge, $id)` requires same bridge identity,
  an already-owned native transaction and mysql exception mode; never begins
  a new transaction, commits, rolls back or changes `b_user`/V4;
- locks in established order `b_user` → `b_sec_user` →
  `b_user_phone_auth` → `ev_samzaberu_auth_state`. Missing MFA/phone rows
  are allowed only because all native enrollment paths MUST first lock b_user;
- verifies current user ACTIVE=Y/BLOCKED=N/PASSWORD_EXPIRED=N; then calls
  the strict release+per-user matrix, all TEN source/lock/rollback/race flags.
  An incomplete release or uncertain user coverage rejects the init;
- existing valid row returns false without bumping/resetting versions;
  malformed row raises, never auto-repairs;
- a truly missing row is inserted as exactly (USER_ID,AUTH_EPOCH=1,
  PHONE_EVENT_VERSION=0,PHONE_BINDING_HMAC=NULL), verified under lock;
- **confirmed phone text is not retroactive proof**. Native confirm/reconfirm
  must later increment PHONE_EVENT_VERSION and write keyed binding HMAC in
  the same native transaction; no phone or SMS bypass at initialization.

This class is a narrow utility for a future reviewed native composition,
NOT itself an event hook or proof that native writers have been installed.
No production site-factory, approved cohort or trusted installed evidence
provider has been shipped. The present OFF release remains unchanged.

## Tests / limits

`tests/integration/V5EphemeralMySqlAuthEpochInitializationTest.php`
runs at the end of the existing CI-only 406-check MariaDB suite. Positive
trusted source proofs and Bitrix facade are **synthetic**, but actual InnoDB
row locks/transaction rollback, independent session visibility, insert
idempotency, write fault and invalid account/cohort gates are exercised on a
disposable `ev_v5_ci_synthetic` DB only. The test creates synthetic
`b_user_phone_auth` in CI, not the production database; the staging
fixture is intentionally different from the real schema.

The workflow `.github/workflows/v5-ephemeral-mariadb.yml` invokes the new
test last, which imports the previous suites exactly once. Offline workflow
will also lint the new PHP files. CI success is not Bitrix production
native event attestation, native TOTP-code/replay acceptance, strong trust
enrollment, SMS provider or RestIS acceptance.

## Outstanding before live initialization

1. Source-pinned, **installed** native hook coverage for all 10 paths;
   atomic lock order b_user first and epoch CAS in the **same** MySQL tx.
2. Independently accepted transaction ownership/effective PDO–Bitrix shared
   connection semantics on a testable isolated Bitrix instance; synthetic
   facade alone does not suffice.
3. A trusted release/user evidence provider, reviewed call site and operator
   pilot-account allowlist. Do not expose the initializer as an API endpoint.
4. Only after these: explicit separate approval for initializing selected
   real pilot users; backup/pre/post-check; leave V5 OFF until factor providers,
   PIN, SMS and all release gates pass.

Previous native source-only audits are archived; do not rerun broadly.
The pending TOTP verifier draft remains unaccepted and is not used.
