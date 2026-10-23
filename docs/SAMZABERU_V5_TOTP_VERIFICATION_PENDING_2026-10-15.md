# V5 TOTP verification: pending work, 15 October 2026

> **SUPERSEDED BY NEW IMPLEMENTATION 23.10.2026:** Original October 15 staging draft was never committed and this historic note was accurate then. New actual feature source `src/V5BitrixLockedTotpFactor.php` now EXISTS in [commit `c1eb8767`](https://github.com/juvantusik/Evrasia_AI_bot/commit/c1eb87674fb4008d56c4f5106dc2a4b0d064fe35), with replay evidence offline tests and pinned native-source/transaction guard. It is still **STAGING-ONLY, NOT real Bitrix runtime accepted, NOT production enabled**. Current accepted readme: [SAMZABERU_V5_NATIVE_TOTP_FACTOR_2026-10-23.md](SAMZABERU_V5_NATIVE_TOTP_FACTOR_2026-10-23.md). The earlier upload block applied to the earlier draft and must not be mistaken for a passing October 15 test. 

## Status: DRAFT / LOCAL_LINT_ONLY / NOT_CI_TESTED

This is a continuation note, NOT a new passing code checkpoint. The authoritative tested code remains `c3f92ba3197157e1cd19f9aa21df3d353862279d`: offline run 38743961486 and disposable MariaDB run 38743961127 passed; the previous total remains 329 checks. The feature ref was reread as `45024505e0b2d668733d0bdb45b373c820961aea` immediately before this documentation-only addition. Do not describe this note's commit as tested application code.

The next draft component is `V5BitrixLockedTotpFactor`, implementing the existing `V5LockedChallengeNativeFactorPort` for TOTP only. It is NOT in the branch's src tree. Its draft Git blob `cbc584ebb03b67729e56c34a577dbce44805fa56` was created, but no code tree/commit/ref update was made. It is unreviewed, untested against the full dependency tree and NOT deployable. Two local PHP files passed syntax lint; that is not an integration test.

Uploading the new test file through the GitHub connector was blocked by the tool's safety check. The blocked payload was not resent through a different encoding, endpoint or delivery route. No new CI was started, and no new assertion count is claimed. The incomplete implementation was deliberately not promoted into the working branch.

## Intended integration, not an accepted implementation

Reuse native `Bitrix\Security\Mfa\Otp::verify($code, true)`, rather than broad `verifyUser`, recovery fallback or a second TOTP secret store. Native verification must stay inside the existing challenge consumer transaction. A native true return alone is insufficient: verify persisted replay parameters, unchanged enrollment/account/epoch, same Application and MFA ORM connection, SQL connection identity, active transaction and verified native depth. Recheck the native cached object against the locked row before invoking it.

The draft also considers a non-writing repeat check against the persisted native parameters. It conservatively rejects a wall-clock-second transition during the two checks to avoid confusing expiration with replay rejection. This is a DRAFT availability restriction, not an approved production requirement. Its latency, timing boundaries, supported native PARAMS/INIT_PARAMS representation and legitimate verification behavior require review and real staging acceptance. Do not weaken those checks just to obtain a passing synthetic fixture.

Target tests still to run: two different password-bound challenges sharing one code; no second grant on replay; native success without persistence denied; native exception or unexpected mutation rolls back replay state together with challenge; native cache mismatch, wrong connection/depth, epoch/enrollment change and timing ambiguity denied; committed grant integrates with V5 JWT and synthetic PIN without SMS. Any synthetic native facade tests would prove only the V5 integration protocol, NOT the actual Bitrix TOTP algorithm or installed native mutation paths.

## Sources checked for the native API contract

- Official Bitrix Otp::verify signature and updateParams parameter: https://dev.1c-bitrix.ru/api_d7/bitrix/security/mfa/otp/verify.php
- Official Otp method reference: https://docs.1c-bitrix.ru/api/classes/Bitrix-Security-Mfa-Otp.html
- Existing project evidence: TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md; SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md.

These sources do not establish the behavior of the exact installed source revision, full native replay atomicity or isolated staging acceptance. No production source-only audit was repeated.

## Infrastructure remains an independent blocker

Issue #76 still has no comments at this read. Previously retrieved project history and available integration discovery did not establish a dedicated staging host, hypervisor, private address or infrastructure control connection. Do not infer them from production hosts or from a proposed hostname. Reuse SAMZABERU_V5_STAGING_PROVISIONING_PLAN_2026-10-15.md; do not prepare another equivalent plan.

The next actual infrastructure action requires an identified independent VM or an approved target hypervisor/host on which to allocate it, followed by the Stage A isolation evidence. Do not use evrasia or eur-bot-01 as a test host. No provisioning, site bootstrap, production DB connection, production DDL, SMS/payment, V4 change or public V5 activation occurred in this iteration. HTTP 503 remains the historical baseline, not a new live result.
