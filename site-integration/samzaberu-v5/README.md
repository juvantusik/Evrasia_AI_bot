# SamZaberu V5 — isolated site integration prototype

**Status:** offline-tested V5 policy and JSON-handler components only. **No live V5 HTTP endpoint, Bitrix integration, JWT issuance, SMS send, database migration or production deployment exists yet.** This directory is deliberately independent of the running Evrasia bot and website.

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


## 2026-10-11 — server-side login workflow and JSON auth handler (implemented offline)

New files, committed to this **isolated branch only**:

- `src/V5LoginWorkflow.php`: a typed orchestration layer for `signIn`, `verifyChallenge`, and `resendSms`. Native Bitrix TOTP takes priority over trust; weak V4 password-only trust cannot bypass SMS. Password verification MUST have no Bitrix/PHP authorization side effect. Pending challenges never carry JWTs. Verification depends on an **atomic, one-time** server-side grant and user/device binding.
- `src/V5AuthHttpHandler.php`: strict JSON request/response mapping for the first three V5 auth routes, with a bounded 8 KiB JSON body, no arbitrary phone field, no exposure of upstream errors. The independent V5 `refresh` and bonus-PIN operations remain unavailable (`503`) until session verification is implemented.
- `tests/V5LoginWorkflowTest.php`: **86 assertions PASS** on local PHP 8.4.24, including password denial, blocked accounts, mandatory TOTP, strong/weak trusted devices, wrong/replayed codes, device mismatch, SMS outages and rate-limit failures.
- `tests/V5AuthHttpHandlerTest.php`: **48 assertions PASS** on local PHP 8.4.24, including exact JSON response shapes, refusal of user-supplied phone/user IDs, invalid input types, credential-free pending states, replay rejection, no error-detail leakage, and fail-closed disabled endpoints.
- Both files and both test files were fetched back from GitHub and **their Git blob hashes matched the locally tested files exactly**.

Offline tests:

```bash
php -l site-integration/samzaberu-v5/src/V5LoginWorkflow.php
php -l site-integration/samzaberu-v5/src/V5AuthHttpHandler.php
php site-integration/samzaberu-v5/tests/V5LoginWorkflowTest.php
php site-integration/samzaberu-v5/tests/V5AuthHttpHandlerTest.php
```

**Strict integration gate:** these are real, tested application-layer and JSON-handling implementations, **not** functional production endpoints. There is deliberately no site-side `V5PasswordProofPort` (no-side-effect Bitrix password proof), `V5TrustProofPort` (cryptographic trust plus strong-enrollment provenance), `V5AtomicChallengePort` (durable challenge and anti-spam SMS/native TOTP anti-replay), or `V5StrongSessionPort` (server-verified assurance plus independent V5 access/refresh JWT) implementation. The existing `api/v5/router.php` continues to respond `503` to **every request**, and must not be activated until these providers and integration tests are complete.

Client response examples (placeholders, **not live API**):

```json
{"status":"challenge_required","challenge_id":"<opaque server ID>","challenge_type":"sms","expires_in":150}
```

A successful authenticator challenge uses `"challenge_type":"totp"`. The first response contains no access or refresh token. Only after verified MFA/strong trust may an authenticated response contain `access_token`, `refresh_token`, and optionally `trusted_device`.

Next dependency: verify a **non-authorizing Bitrix password proof mechanism** for the actual site version, then adapt the already-staged V3C native TOTP verifier to the V5 atomic challenge port; do not reimplement or repeat the completed native TOTP static audits. Implement challenge storage, SMS budget reserve, and V5 JWT issuer in a non-production environment. Do not use `CUser->Login()` as password proof unless it can be demonstrated not to authorize the current PHP session.


### Reuse existing native Bitrix TOTP V3C stage — do not redo

Earlier project work on 2026-10-11 already prepared and statically checked the **offline** `NativeTotpVerifierV3C`, `PdoTotpStoreV3C`, native Bitrix locked-row candidate, canonical identity projection, credential marker and version-proof pieces, and a non-production E2E harness candidate. The relevant Bitrix OTP algorithm and replay-state code was previously inspected against then-current SHA baselines.

The V3C verifier exposes `verifyAndConsume(Account, code): bool` and explicitly requires a password-proven user, device binding, rate limiting and shared per-user mutex. The V3C state table was **not** created, and the V3C verifier was **not** deployed. The new V5 `V5AtomicChallengePort` is the integration boundary to **reuse that existing candidate**, after checking that its staged files still exist, match the recorded hashes, and pass updated integration/transaction tests. Do NOT bypass existing native Bitrix anti-replay protection, do NOT call the V3C provider directly with an untrusted `user_id`, and do NOT assert production readiness based on static analysis alone.

Native TOTP work is NOT a fresh design task. The actual remaining implementation gaps are a verified non-authorizing Bitrix password proof, durable challenge state + SMS quotas, proof-bound session persistence and independent V5 JWT signing/refresh, and live staging integration.


## 2026-10-11 — no-login Bitrix first-factor adapter

A focused operator-run read-only check on production `evrasia` confirmed that the actual installed file is `bitrix/modules/main/lib/Security/Password.php` (capital `Security`, not lower-case on this filesystem), SHA256 `a1ffbba30510caa373087127d3af5714d36be526f5df854610f80ff227a10296`. Loading **only that PHP class** with `php -n` and synthetic password/hash values passed: public static `Password::equals()`, SHA-512 match and non-match, legacy salted-MD5 match and non-match. No real account read, Bitrix bootstrap, `CUser->Login()`, JWT or SMS.

New files, **GitHub branch only**:
- `src/V5BitrixPasswordProofPort.php` — dependency-injected V5 password proof adapter calling `\Bitrix\Main\Security\Password::equals()`, without invoking Bitrix login, creating a session, or minting tokens. Unknown Bitrix active, anti-fraud/block or MFA state rejects the attempt; a reserved first-factor attempt is mandatory *before* account lookup, even for nonexistent logins. Invalid account snapshots are rejected.
- `tests/V5BitrixPasswordProofPortTest.php` — **39 isolated PASS assertions** (PHP 8.4.24, locally tested against a minimal compatibility stub of existing `V5LoginWorkflow` interfaces), including correct/wrong password, nonexistent login, blocked/inactive state, TOTP active/unknown, rate-limit denial/outage, reader failure, comparator failure and invalid inputs. Both GitHub source/test blob hashes match those locally tested exactly.
- The actual native Bitrix hash comparator was **separately tested on the production server with only artificial data** (operator log); this GitHub unit test uses stub components and does not run Bitrix.

**Not yet implemented**: `V5PasswordAccountReaderPort` concrete site-side lookup, or `V5FirstFactorAttemptGatePort` atomic throttle backend. These MUST be implemented against verified active Bitrix data and anti-fraud rules before any public routing. In particular, the reader must also enforce all existing Bitrix login restrictions such as password-expired state and login-attempt lockouts: the current snapshot's `bitrixActive` and `antiFraudAllowed` **are not sufficient to claim full login-policy compatibility on their own**. No guessed production table/column/lookup semantics are accepted. If live login policy needs additional explicit fields, extend the account snapshot and tests before staging.

Next: finish the site-specific unique-login reader and account-policy/throttle integration using verified current site code, then reuse the existing V3C OTP atomic verification stage for challenges. `api/v5/router.php` is still intentionally disabled/HTTP 503. V4 and production were not changed.


## 2026-10-11 — V4-compatible unique-login account reader for V5

Latest `evrasia` read-only diagnostic: `FINAL_STATUS=PASS`, `FINAL_RC=0`. The **unchanged** V4 `Auth.php` proves phone login is normalized by `PhoneConfirm::setPhoneMask()`, V4 uses `EMAIL` for email login and `LOGIN` for phone, and account lookup uses `\Bitrix\Main\UserTable::getList()` with a one-row limit. `BLOCKED` is involved in V4's filter but the diagnostic did not disclose every key/value: do not assume that V4's exact ORM filter has been fully recovered. No DB queries were executed.

Implemented in this **isolated GitHub branch only**:

- `src/V5SiteAccountReader.php`: `V5SiteAccountReader` consumes authoritative current account rows through `V5AccountRowSourcePort`, existing phone mask behavior through `V5PhoneLoginNormalizerPort`, live native 2FA state through `V5NativeTotpStatusPort`, and real login-attempt/lockout semantics through `V5ExistingLoginPolicyPort`. Unknown state **denies** login. It requires exactly one matching record; duplicates are rejected.
- `V5NativeBitrixUserRowSource` is a **staging-only ORM candidate** using `UserTable::getList()` with `limit => 2` and `EMAIL`/`LOGIN` filters. Unlike V4, it does not pre-filter inactive/blocked records, so duplicates cannot be silently bypassed. The eight selected fields are based on prior V3C SQL source audits and the V4 login flow, but their availability in the **actual ORM map** must still be confirmed before using this class on the site.
- `V5NativePhoneLoginNormalizer` wraps the existing `PhoneConfirm::setPhoneMask()`; its runtime side effects and required bootstrap remain an integration check, not an assumption.
- `tests/V5SiteAccountReaderTest.php`: **90 offline assertions PASS** on PHP 8.4, covering email/phone identity, ambiguous accounts, blocked/inactive/expired accounts, unknown TOTP and login policy state, corrupt fields, ORM query shape, normalization and dependency outages. Local source and test SHA match the committed GitHub blobs exactly.

**Still missing:** operational implementations of `V5NativeTotpStatusPort` and `V5ExistingLoginPolicyPort`, atomic rate limiting, and the challenge/JWT service integrations. `PASSWORD_EXPIRED` and `LOGIN_ATTEMPTS` must be reconciled with the *live* Bitrix policy, including currently configured lockout thresholds. The V4 optional explicit login `type` flag is not in the current V5 JSON contract; V5 follows V4's default `@`-based branch.

**Deployment remains OFF:** production, Bitrix DB, V4 and the disabled V5 router are unchanged; no real user records, SMS or JWTs were accessed.


## 2026-10-11 — conservative Bitrix MFA status source

- New `src/V5NativeTotpStatus.php`: a guarded native `Bitrix\Security\Mfa\UserTable` **staging candidate**, using fresh `b_sec_user` ORM rows (not cached `Otp::getByUser()`), an injected site-level `V5NoMfaFallbackPolicyPort`, and explicit tri-state treatment. Active valid TOTP returns `true`; a truly absent MFA row returns `false` **only if** mandatory-MFA policy explicitly confirms that password plus SMS is allowed. Any inactive, temporarily deactivated, malformed, incompatible or unavailable factor returns unknown/rejection, not SMS fallback.
- New `tests/V5NativeTotpStatusTest.php`: **63/63 offline assertions PASS** with PHP 8.4.24, using a stub of the existing V5 status interface and a synthetic Bitrix ORM source. Both committed Git blobs match the locally linted/tested bytes. No site DB connection, native OTP secret or real account was used.
- **Not a production-ready provider.** The `V5NoMfaFallbackPolicyPort` has no real implementation. The live Bitrix MFA mandatory/skip/deactivation rules and ORM bootstrap require staging confirmation. The existing V3C challenge-side anti-replay protection remains authoritative; this source is only an initial factor-selection signal. Recheck the current MFA state under appropriate locks before any challenge consumption or JWT.
- A temporarily disabled, manually disabled or unsupported configured OTP row currently **denies login** rather than silently downgrading to SMS. If product policy should allow a recovery path, design a separate verified recovery flow; do not equate inactive OTP with 'no 2FA'.

**Operational state:** all V5 routes remain HTTP 503; no production code, database, SMS or session was modified. Next narrow site read-only check: confirm the `Bitrix\Main\UserTable` ORM map includes all selected user columns and identify the actual site policy/lockout entrypoint, without running Bitrix bootstrap or reading account rows.
