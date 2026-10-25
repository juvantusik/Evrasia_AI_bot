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
