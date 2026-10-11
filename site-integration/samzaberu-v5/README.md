# SamZaberu V5 — isolated site integration prototype

**Status:** offline-tested V5 policy components only. **No V5 HTTP route, Bitrix integration, JWT issuance, SMS send, database migration or production deployment exists yet.** This directory is deliberately independent of the running Evrasia bot and website.

## Current files

- `src/AuthDecision.php`: pure factor selection after a **server-verified** first-factor password check.
- `tests/AuthDecisionTest.php`: exhaustive 32-case matrix including disabled/blocked accounts, native TOTP priority, missing/invalid trust, and old password-only trusted-device records.

## Test (PHP 8.2+)

```bash
php -l site-integration/samzaberu-v5/src/AuthDecision.php
php -l site-integration/samzaberu-v5/tests/AuthDecisionTest.php
php site-integration/samzaberu-v5/tests/AuthDecisionTest.php
```

Expected: `PASS: V5 decision matrix (32 cases)`.

## Security semantics

`AuthDecision::afterPassword($passwordVerified, $accountAllowed, $nativeTotpActive, $trustTokenValid, $trustStronglyEnrolled)` accepts **server-derived facts only**. The last two arguments are deliberately separate:

- `trustTokenValid` = existing Bitrix Trusted Device core validates user, namespaced mobile installation identity, trust token and expiry;
- `trustStronglyEnrolled` = a **separate, server-verifiable historical assurance** that the current device/token was established through an accepted SMS or TOTP challenge, rather than V4 password-only device registration.

The current production implementation has not yet established a trustworthy source for `trustStronglyEnrolled`. **Never set it to true from a client field, JWT claim, or simply because `validateTrust()` succeeds.** Until its persistence/verification is defined, the fail-closed V5 behavior is SMS for non-TOTP users. No V5 tokens may be minted by this prototype.

Native Bitrix TOTP overrides a trusted device. Password failure, blocked/inactive account, or missing account must terminate before any SMS/TOTP challenge or token creation. Password verification integration must not establish an authorized Bitrix session before the required second factor.

## Integration prerequisites before writing controllers or public routes

1. Inspect the actual production `api/v4/router.php` and website entrypoint/bootstrap conventions. These PHP site sources are not currently versioned in this bot repository.
2. Inspect actual `JwtAuthorization` creation, refresh/revocation and current route enforcement; define server-verifiable V5 session assurance and separation from V4 credentials.
3. Determine safe Bitrix first-factor password verification without premature cookie/session authorization, and native TOTP validation with replay protection.
4. Determine current password-only vs SMS/TOTP trusted-device provenance using existing **verified** DB columns/events, or design a minimal versioned assurance record after inspecting real schema.
5. Design durable TTL-bound challenge storage with atomic consumption and SMS rate limits. Do not invent database tables, change the vendor Bitrix core, or deploy until source and staging tests justify it.
6. Implement isolated V5 controllers/routes and offline/isolated tests; only then consider a guarded pilot on USER_ID 880339.

See `docs/SAMZABERU_API_V5_AUTH_CONTRACT_2026-10-11.md` on this branch.

**V4 and production must remain untouched** during this stage.

## 2026-10-11 — V5 assurance policy increment

- `src/V5SessionAssurancePolicy.php` provides a **fail-closed second gate** for protected V5 operations (e.g. bonus-PIN disclosure).
- `tests/V5SessionAssurancePolicyTest.php` tests valid server-confirmed TOTP/SMS/trusted sessions, V4 JWT rejection, missing claims, mismatched user/session/epoch, revoked sessions, unverified revocation and expired credentials. Tested locally on PHP 8.4: **50 PASS**.
- Test with `php site-integration/samzaberu-v5/tests/V5SessionAssurancePolicyTest.php`.
- **Not an HTTP authentication verifier**: its `verifiedClaims` argument must be produced **only after** independent cryptographic JWT verification using a new V5 issuer/audience and key; its session argument must come **only from** an authoritative server-side assurance store after a successful fail-closed revocation check. It does not validate JWT signatures itself or create sessions, tokens, or persistent records.
- These site-side integration dependencies have **not been implemented or deployed**. Never pass client-supplied claims or trust flags to this policy, and never route V5 requests through the legacy V4 `JwtAuthorization::checkAuth()` path.

## 2026-10-11 — pending challenge and SMS-resend rules

- `src/ChallengeRules.php` — fail-closed validation of a server-side pending password-first challenge, binding to user and mobile device, 5-minute maximum TTL, up to 5 failed attempts.
- `tests/ChallengeRulesTest.php` — **55/55 cases PASS**, local PHP 8.4.24 syntax/lint and execution. The GitHub content hashes of both files were matched against tested local files.
- SMS resend requires verified clear **global** SMS limits, at least a 60-second cooldown and fewer than 3 sends per challenge. These are **initial V5 policy defaults**, not yet an approved complete provider quota. Gateway/phone/IP/user-wide budgets must be implemented and configured against actual operator limits before enabling SMS.
- The rule evaluator cannot by itself prevent concurrency or SMS spam. **Every verification attempt (including wrong codes), challenge consumption and resend reservation must be persisted atomically** by a verified transactional/compare-and-swap backend. Do not rely on in-process state or a pre-check plus unlocked write.
- A native TOTP code is verified only through existing Bitrix state. This helper never knows, copies or logs the TOTP secret.
- Test with `php site-integration/samzaberu-v5/tests/ChallengeRulesTest.php`.

**No V5 challenge database table, Bitrix integration, SMS sender or HTTP route is deployed.** Only the offline evaluator and tests exist.

## 2026-10-11 — fail-closed V5 HTTP router scaffold

- `src/V5RouteMap.php`: five proposed V5 POST routes in an isolated registry.
- `api/v5/router.php`: a **disabled-by-default offline scaffold**; it responds with HTTP 503 `{"error":"v5_unavailable"}`. Even if its explicit readiness flag is set, registered route callbacks **still return HTTP 503**. No client can log in through this scaffold, and it must **not** be copied to production yet.
- `tests/V5RouteMapTest.php`: registry test. Offline PHP 8.4.24 checks passed, including syntax checks and disabled-router CLI smoke test. Source repository files were verified after commit.
- The **V4** rewrite target has now been confirmed by the operator's production read-only check (see rewrite candidate section below); actual Apache V5 integration and isolated Bitrix bootstrap tests remain pending.

There is **no live V5 handler**, no JWT issuer, no TOTP/SMS verifier, no SMS send and no challenge DB persistence. This step only establishes a safe, version-separated routing structure.


## 2026-10-11 — site rewrite checkpoint and isolated candidate

- The operator's latest `evrasia` log confirms `api/v4/.htaccess` SHA256 `61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3`, with `RewriteBase` present, three conditions, one rule and a `router.php` rewrite target. Final server result: `FINAL_STATUS=PASS / FINAL_RC=0`; **read-only, no deployment**.
- `api/v5/.htaccess` is a **separately designed candidate** for the offline V5 router, **not** a copy of V4's unknown condition details. It sends routes to `router.php`, whose current implementation denies access with HTTP 503. This code must **not** be installed in production until the full site bootstrap and Apache behavior are tested in staging.
- `tests/V5RewriteContractTest.php` asserts the candidate's rewrite directives and separation from V4. Locally tested with PHP 8.4.24: lint and static contract PASS. **APACHE_INTEGRATION_TESTED=NO**.
- Test with `php site-integration/samzaberu-v5/tests/V5RewriteContractTest.php`.

`api/v5/router.php` currently requires its route map via the **offline repository layout**; production packaging paths are not yet defined. Do not copy these files individually to the Bitrix document root.
