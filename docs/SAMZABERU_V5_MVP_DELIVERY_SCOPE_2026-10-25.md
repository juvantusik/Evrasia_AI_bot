# СамЗаберу API V5 — MVP delivery priority, 2026-10-25

## Decision
The user explicitly stopped speculative security hardening/repetitive source audits. Ship a working, independently routed V5 MVP as fast as practical, using the already-running V4 Bitrix business components. Additional security enhancements can follow **after** the MVP. Do not re-investigate previously verified native Bitrix/SQL/TLS/route guards without a concrete failure.

Do not delete existing functioning MFA/authorization safeguards or expose customer data. Keep minimal required identity/auth checks, server-side OTP verification, existing SMS abuse controls, and V4 independent. Never silently enable untested production auth based on a static code change.

## Confirmed live state
- Public client origin: `https://evrasia.rest/api/v5/`; externally confirmed IPv4: `217.197.238.66`. `192.168.103.141` is internal server network only.
- Existing V5 public health/legal routes already live. AUTH remains OFF.
- Existing V5 sources provide signin/trust-first/TOTP/SMS-challenge orchestrator, token/refresh, PIN and cart service contracts. This is **not** equivalent to production live auth implementation.
- Latest real production source-only result: `NATIVE_FIRST_FACTOR_REQUEST_SCOPE=PASS`, 20 shape assertions, `FINAL_RC=0`; no DB connection, no code changes.
- The GitHub-only physical-PDO extra-hardening introduced by `ecb8e1...` was reverted in `eb81d4855c0760c4ac214c600381bd93d2c7174a` to preserve the previously accepted shape contract and focus on functionality.

## Next functional delivery sequence
1. One-time obtain current V4 router route map and actual controller/service method names from production **without executing them**. V4 implementation is not present as importable source in this GitHub branch. Preserve a private root-only source snapshot for working implementation; do NOT ask again for completed security audits.
2. Implement independent V5 entrypoints by reusing the current V4 application/business services, not proxying unvalidated access/refresh tokens between V4 and V5, and not rewriting cart/order calculations.
3. Prioritize working account sign-in/session, profile, cart, orders, PIN, and signup/legal consent flow; retain already-live public legal endpoints.
4. Test actual V5 responses using existing real user journeys and rollout with an explicit rollback plan. Keep V4 untouched during migration.

For every terminal wrapper: clear screen/scrollback immediately at start with `printf '\\033[3J\\033[2J\\033[H'`, print concise statuses, preserve terminal prompt and `FINAL_STATUS/FINAL_RC/WRAPPER_RC`, no credentials/customer data.

## Implemented: independent V5 GET /profile (feature source only)

Latest user-approved direction: V5 must be independently removable from V4; copying V4 logic as a reference is allowed, runtime V4 controller/JWT/API calls are NOT. Production operator created immutable root-only V4 reference snapshot `/root/ev-v5-mvp.2eCDIApS` (11 controllers, checksum verified, V4 unchanged) and supplied routes/method names. This is a development reference only.

The V5 feature branch now implements `GET /api/v5/profile` end-to-end at source level:

1. `V5RouteMap`: canonical profile GET.
2. `V5ParallelHttpEntry`, `V5HttpTransportBoundary`: V5 bearer pre-check and GET dispatch, no cookies or JSON body.
3. `V5AuthHttpHandler`: separate `V5ProfileHttpReaderPort`, returns `{ "profile": {...} }`; absent provider is 503.
4. `V5MySqlStrongSessionStore`: `readProfileForAccess` checks V5 access JWT, current Bitrix user, epoch and locked V5 strong session, then reads with the same Bitrix transaction.
5. `V5BitrixLockedProfileReader`: V5-only read-only native `b_user` projection `ID,LOGIN,NAME,LAST_NAME,SECOND_NAME,EMAIL,PERSONAL_PHONE`, with exact current-user ID bound by the V5 token. No Controllers/V4 dependency.
6. `V5MySqlProfileRead`: adapter installed in existing V5 public/pilot compositions; no new auth/token service.
7. `V5ProductionNativeRequestBoundaryGuard` recognizes exact protected GET profile, not anonymous legal.
8. `tests/V5IndependentProfileHttpTest.php`: in-memory HTTP contract test without creating any synthetic guest, database, or JWT. SOURCE ONLY; runtime Bitrix read/real mobile JWT not yet accepted.

**NOT LIVE:** public V5 AUTH still OFF, feature only. Profile DTO minimal, not claimed full V4 field parity yet; current V4 source reference can be used to add any required V4 fields without V4 runtime dependency. Next functional tasks: private independent V5 cart ownership/read adapter and V5 orders adapter using native Bitrix business services, then signup and live app acceptance. Do not open a fresh security investigation.

## 2026-10-25 — Independent V5 profile test: first production-off run FAILED

Operator stdout from `run_v5_profile_mvp_readonly.sh` (GitHub commit 9032fbef...): host guard PASS; pinned V5 profile sources PASS; PHP syntax PASS; functional contract FAIL (runner suppressed PHP errors, no specific assertion visible). `INDEPENDENT_PROFILE_MVP_TEST=FAIL`, `FINAL_RC=2`, `WRAPPER_RC=2`. This is NOT a production auth regression: no Bitrix bootstrap/DB/production-code writes; V4 remains unchanged, V5 AUTH OFF. **Do not label the V5 profile endpoint tested or live**.

The test now has an error handler exposing ONLY sanitized `PROFILE_TEST_FAILURE` or PHP class+file+line, never bearer/PII; source pinned at commit `36fe4740721fe43b76aca9eaea73ad2c34e1b63f`. New single clear-screen runner at commit `e41874399572d9cfd778d70d24ec6b40390ad291` blob `a9b94b33e0ceb9b3aabdc2481bd76cd6a2d67d34`. Next: inspect operator's precise failure identifier, apply minimal fix in V5-only feature source, rerun one focused test; do not repeat SQL/security audits.
