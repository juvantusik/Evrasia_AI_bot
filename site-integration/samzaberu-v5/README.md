# SamZaberu V5 — isolated site integration prototype

> **LATEST FEATURE MILESTONE 2026-10-15: TOTP bound challenge BEGIN + atomic paid SMS one-attempt CLAIM.** New `V5MySqlPasswordBoundTotpIssuer`: re-verifies current password/native-TOTP and shared AUTH_EPOCH under b_user lock before committing a 300-second device/epoch-bound TOTP challenge. `V5LoginWorkflow` forwards first-factor evidence over `V5PasswordBoundChallengePort`; unsafe legacy begin(userId) now denies. Real MariaDB CI confirms stale password / account block / epoch change deny, while synthetic TOTP HTTPS sign-in→verify→JWT→protected PIN works without SMS. Separate `V5MySqlSmsDispatchClaims`: atomic DB-only `queued → claimed → sent|failed|unknown`, one paid delivery claimed once across concurrent independent MariaDB workers, wrong idempotency receipt/expired challenge/native-denied send refuses; claimed and unknown delivery never auto-retried. Provider terminal `sent` in CI is **SYNTHETIC ONLY**. [Real MariaDB integration CI 38722513054](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38722513054) **156/156 assertions PASS (43 V5 session + 113 SMS/TOTP/dispatcher);** [offline CI 38722513056](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38722513056) **26/26 PASS**. **No live site/SMS/Bitrix use.** SMS begin/resend still fail closed pending source-verified native lock/phone confirmation/version, real paid SMS provider API/idempotency and actual server IP context. Native locked password/TOTP adapter, multi-path `AUTH_EPOCH` hook proof, reliable verified phone writer, real PIN reader and isolated Bitrix/MySQL staging remain release blockers. All proposed SQL only used in GitHub ephemeral CI; router remains disabled HTTP503 and V4/prod untouched. All previous synthetic-only statements below are historical.

> **2026-10-15 / REAL disposable MariaDB HTTP OTP-to-V5-session PASS, begin/resend deliberately DISABLED.** Added `src/V5MySqlChallengeHttpPort.php` partial `V5AtomicChallengePort` adapter: `verifyAndConsume` uses `V5MySqlChallengeConsumption` and the fresh `V5MySqlConsumedChallengeProof` before committed JWT mint; without independently attested native `V5ChallengeLifecyclePort`, `begin()` and `resendSms()` refuse. Expanded `tests/integration/V5EphemeralMySqlSmsOutboxTest.php` to test staged HTTPS POST JSON `/auth/challenge/verify` against **REAL isolated GitHub Actions MariaDB** with synthetic already-password-proven, artificially provider-`sent` SMS outbox, six-digit HMAC, one-time consumed grant, JWT, PIN, refresh, replay 401, revocation. Real MariaDB [GitHub Actions **38720526004**](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38720526004): **68 SMS/challenge/HTTP assertions + 43 session/HTTP assertions, 111 PASS**, including independent two-process first SMS queue and refresh races. Offline [CI 38720525771](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38720525771) **26/26 PASS**. Synthetic sent receipt is NOT real paid SMS; real native Bitrix TOTP/replay, verified phone/PHONE_EVENT_VERSION and AUTH_EPOCH hooks, password-bound begin, SMS delivery/price bounds and RestIS PIN source remain UNATTESTED. New V5 MySQL schema is only CI fixture; no actual isolated Bitrix staging verified, no production SQL/SMS/V4 code changes and deployed V5 remains HTTP503 disabled. Earlier milestone notes below are historical.

> **V5 milestone 2026-10-15 — real InnoDB SMS queue/verification/grant-to-session tested.** `src/V5MySqlSmsOutbox.php` existing encrypted, HMAC-only paid SMS queue now has **REAL disposable MariaDB** tests for money limits, atomic quota rollback, resend cooldown, code rotation and one first-send under two-process race. New `src/V5MySqlChallengeConsumption.php` consumes already-created SMS/TOTP challenge once in a locked transaction: 5-attempt lockout, SMS code digest check only against a **provider-confirmed SENT** outbox row, native TOTP replay callback required, no SMS gateway called. New `src/V5MySqlConsumedChallengeProof.php` rechecks the consumed grant in the session issuer's own locked transaction before V5 JWT mint, and UNIQUE grant digest blocks duplicate session creation. `tests/integration/V5EphemeralMySqlSmsOutboxTest.php` **57 assertions PASS** (native phone/MFA provider deliberately synthetic), existing real session HTTP/refresh test 43 PASS; [GitHub Actions 38719790944](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38719790944) **SUCCESS**, offline [38719790916](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38719790916) **SUCCESS 26/26 suites**. No actual SMS/TOTP/Bitrix/RestIS, no real verified phone writer, no production database migration, no V4 modifications. Only disposable GitHub Actions MariaDB. Real SMS dispatcher, initial password-proven challenge issuance/resend, native TOTP/stamp providers and strong-session hook coverage must be implemented and confirmed in **isolated Bitrix staging**. The main /api/v5/router.php remains HTTP503. No fresh production audits needed.

> **2026-10-15 / latest REAL ephemeral MariaDB + V5 HTTP E2E checkpoint.** `V5EphemeralMySqlStrongSessionsTest.php` exercises an actual disposable GitHub Actions MariaDB 11.4 InnoDB database with 2-process concurrent refresh attempts and complete staged HTTPS/POST JSON transport→synthetic password+strong trust→committed JWT→locked PIN→refresh→`AUTH_EPOCH` invalidation→re-login→revoke scenario. [Real DB CI 38717872313](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38717872313) **43/43 PASS**, and [offline CI 38717872305](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38717872305) **26/26 PASS**. No Bitrix runtime/native hook or actual RestIS tested; `REAL_SITE_STAGING_ATTESTED=NO`, `NATIVE_SECURITY_HOOKS_ATTESTED=NO`; real MariaDB CI does **NOT** qualify as isolated Bitrix+MySQL staging. Site API V5 remains 503 disabled; V4 and production unchanged, no production SQL. Earlier smaller synthetic-only scope claims below are historical. Critical path now is to attest a separate Bitrix staging instance and implement/prove real native password/MFA/phone/epoch and PIN providers before any HTTP exposure.

> **NEW V5 milestone 2026-10-15: OFFLINE HTTP GUARDS AND REAL EPHEMERAL MariaDB PASS.** `src/V5HttpTransportBoundary.php` + `src/V5PhpHttpRequestFactory.php` are staging-only pure request adapters: accept only trusted `HTTPS=on/1`, POST, five exact canonical V5 paths, strict JSON/8KB body; block query-string credentials, malformed Content-Length and spoofed `Forwarded`/`X-Forwarded-Proto`. Emit `Cache-Control:no-store`, `nosniff`, no cookies/CORS. Default constructor returns HTTP503 without handlers, existing website `api/v5/router.php` remains disabled. `V5HttpTransportBoundaryTest.php` **147 assertions**, full synthetic HTTP→Bitrix→session→PIN→refresh→revoke **193 assertions**; [offline CI 38717171914](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38717171914) **26/26 PASS**. Crucially, `tests/integration/V5EphemeralMySqlStrongSessionsTest.php` and `.github/workflows/v5-ephemeral-mariadb.yml` exercised actual **disposable MariaDB 11.4 InnoDB** with **27 assertions** and concurrent independent OS-process refresh attempts, exactly one success and refresh_generation advanced once; [real ephemeral DB CI 38717261322](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38717261322) **SUCCESS**. Tested real InnoDB transactions, epoch invalidation and replay, WITHOUT real Bitrix or production data. **No production migrations, stage deployment, SMS, RestIS, V4 changes or real native hooks.** Physically separate real Bitrix/MySQL staging remains UNATTESTED; native phone confirmation writer, MFA/recovery event coverage, actual proof providers and RestIS PIN port remain release blockers. CI MariaDB is not a substitute for site staging. Earlier claims below that SQL/session operations are only synthetic are HISTORICAL after this checkpoint.

> **АКТУАЛЬНЫЙ CHECKPOINT — 2026-10-15 / V5 FULL HTTP + SECURITY EVENT E2E OFFLINE, JWT POST-COMMIT.** Feature-only commits `0c46f3485a97a44cf4f30e44e708cd14462004b1`, `03562305cd6838639d4a0403b9a1e7caea54afe6`, `2420bcc5ee0312a17287b072226adad96f251a34`: existing `V5BitrixStrongSessionBridgeIntegrationTest.php` now runs native security-event writer against the **SYNTHETIC** Bitrix Mysqli bridge and the same V5 session store: same E.164 phone reconfirm increments both `PHONE_EVENT_VERSION` and `AUTH_EPOCH` and changes the confirmation stamp; unsuccessful phone-event mismatch rolls back; standalone security event increases epoch without changing phone generation; previous access/refresh credentials then fail closed and cannot access PIN. This is test evidence only — native Bitrix hooks on the real site are **NOT installed or verified**. The same test now performs full synthetic HTTP `V5AuthHttpHandler → V5LoginWorkflow → V5MySqlStrongSessionStore → V5BitrixPdoCompatibilityBridge → V5MySqlSessionActions`: valid synthetic password+strong trust returns JWT with **no SMS**, protected PIN is accessible without another SMS, refresh rotates, replay returns generic 401, and revocation disables PIN/refresh. The optional HTTP actions default to 503 without injection, and the actual website router stays disabled. `V5MySqlStrongSessionStore` now creates/signs the V5 access JWT and exposes opaque refresh credentials **only AFTER successful owned transaction COMMIT**, rather than while locks are held; failure/uncertain COMMIT returns no bearer. [GitHub Actions **38715012481 — SUCCESS**](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38715012481): **25/25 offline suites, 179 synthetic native-bridge E2E assertions, 249 session-store assertions, 65 HTTP handler assertions, FINAL_STATUS=PASS**. **NOT DONE / release blockers unchanged:** physically isolated Bitrix+MySQL staging and real concurrent transactions, native security hooks/lock coverage incl password/MFA/logout/phone reconfirm, actual strong trusted-device/grant proof, native phone writer, external RestIS PIN reader, real V5 challenge/SMS providers, schema installation and server-side HTTP activation. Current V4 and production untouched. Previous "NEXT" requests for phone lexical probes are CLOSED; do not repeat. Next work must focus on truthful staging readiness and real adapter implementation; never claim deployment or production success from fake tests.

> **Latest feature-only milestone 2026-10-15 — V5 protected refresh/PIN integration OFFLINE PASS.** Existing V5 JWT and strong-session store now run through `V5BitrixPdoCompatibilityBridge` for exactly five allowed session-table SQL shapes. An injected `V5SessionActionsPort` supports independent refresh and bonus PIN via `V5MySqlSessionActions`; absent an explicit provider, both V5AuthHttpHandler actions return 503 exactly as before, and the actual website `api/v5/router.php` is still disabled (last documented HTTP503 baseline). `V5MySqlStrongSessionStore::showBonusPinForAccess` validates V5 JWT + active server assurance + same native locked AUTH_EPOCH and reads the protected PIN inside the same transaction, without a new SMS challenge; the actual site PIN reader is mandatory/unimplemented. Fake native Bitrix full-path integration 120 assertions PASS, fake session store 249 PASS, JSON handler 65 PASS. [CI run 38713271095](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38713271095) SUCCESS: **25/25 offline suites**. Back-end failures use generic temporarily-unavailable; credential rejection uses generic authentication_failed. **NOT a live site integration**: unknown native phone confirmation writer and same-number PHONE_EVENT_VERSION, native strong-proof/grant, native hook/epoch atomicity, real Bitrix+MySQL staging/concurrent transactions, proposed table installation, and RestIS PIN retrieval remain unimplemented/unattested. No database, Bitrix bootstrap, real SMS, V4, or production changes. Previous descriptions below of missing bridge SQL support and refresh/PIN code are historical. Next action is physically isolated staging attestation and trusted provider integration before HTTP activation.

> **2026-10-15 / V5 STRONG SESSION & REFRESH TRANSACTION CANDIDATE IMPLEMENTED (GITHUB-ONLY).** `src/V5MySqlStrongSessionStore.php` implements the existing `V5StrongSessionPort` for trusted device and server-consumed TOTP/SMS grant, backed by mandatory (not-yet-live) `V5LockedStrongSessionProofPort` and `V5NativeSecurityHookCoveragePort`. Freshly check a proof under the user's b_user row lock, require verified native hook coverage and shared `ev_samzaberu_auth_state.AUTH_EPOCH`, insert hashed-only device/grant/refresh values, then return access/refresh tokens after commit. Refresh rotates digest+generation with row lock + CAS and no sliding expiry. Grant uniqueness prevents second session for same challenge. Revocation and guarded server assurance snapshot implemented; **a snapshot is NOT permission to perform a sensitive write after releasing locks**. DDL `schema/PROPOSED_ev_v5_strong_sessions.sql` is **NOT INSTALLED**. `tests/V5MySqlStrongSessionStoreTest.php` 165 assertions PASS in [CI 38709333879](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38709333879); 24/24 offline suites PASS. Dependency `V5SessionTokens.php` / 87 access-JWT tests retained. **No deployed Bitrix proof provider, grant replay/epoch native hook coverage, approved SQL-bridge allowlist, physically isolated staging or real MySQL concurrency acceptance. Do not turn on /api/v5 routes or assume JWT/PIN E2E ready.** V4 and production unchanged.

> **2026-10-15 — V5 credential primitives IMPLEMENTED OFFLINE (NOT ISSUING LIVE SESSIONS).** `src/V5SessionTokens.php` adds `V5AccessJwtCodec` with V5-exclusive HS256 issuer/audience, strict access-only JWT validation, independent 32+ byte configured V5 keys / `kid` rotation and ≤15-minute configurable TTL (300s default), plus `V5OpaqueRefreshTokens` for random V5-only refresh bearers and domain-separated keyed digests. The codec requires *authoritative committed* active/revocation-verified strong-assurance session state before signing, but does NOT itself prove that state, implement persistent session records, grant replay guards or authorize PIN access; `V5SessionAssurancePolicy` still requires fresh server-side revocation/epoch checks after cryptographic verification. `tests/V5SessionTokensTest.php`: **87 offline assertions PASS** incl V4 separation, forged/expired/wrong-issuer JWTs, signature/key rotation, invalid session snapshots and opaque refresh. [GitHub Actions 38706946637](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38706946637) **SUCCESS**, **23/23** V5 offline suites. **Refresh token atomic DB rotation remains NOT IMPLEMENTED; `V5StrongSessionPort`, staging Bitrix/MySQL, deployment and live API remain NOT IMPLEMENTED.** Never use V4 signing keys or issue tokens directly from web requests. No production/V4 changes.

> **STRUCTURES / SOURCE OF TRUTH — 2026-10-11:** [docs/STRUCTURE_REGISTRY.md](../../docs/STRUCTURE_REGISTRY.md). The eight required **physical** `b_user` columns are verified in production metadata; no account rows were read. Runtime ORM map / real SQL use and login-policy integration remain pending. V5 is not deployed. Reuse verified Bitrix/V4/TOTP/Trusted Device structure rather than repeating full diagnostics.

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


## 2026-10-11 — V5 account source compatibility checkpoint (ORM fields)

Operator read-only inspection on `evrasia` returned `FINAL_STATUS=PASS` without running Bitrix or querying the database. Exact production source `bitrix/modules/main/lib/UserTable.php` SHA256 was `e9574a33afa1a592b7f4555ba1f5a5bb54e2fa70f84968360feeb56396e725a4`. Its static text contained `ID`, `LOGIN`, `EMAIL`, `PASSWORD`, `ACTIVE`, `BLOCKED`, but did **not** contain `PASSWORD_EXPIRED` or `LOGIN_ATTEMPTS`. The legacy PHP login source contains the latter tokens. **A source-text absence is not proof of physical DB or runtime ORM field absence.** **SUPERSEDED 2026-10-11:** the production physical `b_user` schema WAS verified: all 8 required columns exist; `MISSING_COLUMN_COUNT=0`, `FINAL_STATUS=PASS`. Runtime ORM field map is still a separate unverified integration detail.

The prior ORM staging candidate incorrectly assumed all eight fields were available. Corrected, without touching V4 or production:

- `src/V5SiteAccountReader.php`: the `V5NativeBitrixUserRowSource` now inspects the runtime ORM entity map **before issuing its query**; if any required field is unmapped, it throws and cannot silently skip password expiry or failed-login limits. The rest of `V5SiteAccountReader` and its fail-closed identity/policy rules are unchanged.
- `tests/V5SiteAccountReaderTest.php`: **94/94 local assertions PASS** on PHP 8.4.24, including a regression where either missing field prevents the ORM query.
- `src/V5PdoUserRowSource.php`: a separate **staging-only** parameterized `b_user` SELECT candidate for those columns, restricted to `EMAIL` or `LOGIN`, returning at most two rows. It accepts an injected PDO connection; it does not open its own DB connection and may only be wired after a real read-only table-schema check.
- `tests/V5PdoUserRowSourceTest.php`: **28/28 local assertions PASS** with mocked PDO (no actual SQL connection). Covers parameter binding, duplicate detection, allowlisted identifiers, error paths and ensuring no blocked-account prefilter.

All four locally tested file Git blob hashes were independently matched against files fetched from the GitHub feature branch.

**All required `b_user` columns are confirmed in the production DB schema**, but no live PDO account-row read was performed by the V5 candidate, and the native Bitrix login-policy hooks have not yet been integrated. It remains a candidate, not an activated adapter. Do not select a provider based on this static diagnostic alone. Next: finish the `V5ExistingLoginPolicyPort` implementation based on actual site login lockout rules; do NOT rerun schema inventory.

`api/v5/router.php` still returns HTTP 503 to every request, V4 is unmodified, and no SMS or JWTs were sent.


## 2026-10-11 — current login controls + mandatory MFA policy boundary

New **offline / GitHub-only** components:

- `src/V5ExistingLoginPolicy.php` implements `V5ExistingLoginPolicyPort` with `V5StrictExistingLoginPolicy`. The `LOGIN_ATTEMPTS` count from the verified `b_user` schema is compared only against a **live-sourced positive limit**, not a guessed numeric threshold; the decision also requires affirmative current evidence of no temporary lock, no security restriction and no blocking site-specific login rule. Unknown state returns `null`/denial; a known restriction returns `false`.
- `src/V5NoMfaFallbackPolicy.php` implements `V5NoMfaFallbackPolicyPort` through `V5StrictNoMfaFallbackPolicy`. It requires current global native Bitrix MFA settings and affirmative account/group SMS eligibility. Native-option reader candidate uses only `Otp::isOtpEnabled()` and `Otp::isMandatoryUsing()`. The existing `V5NativeTotpStatus` calls the fallback policy only after a **confirmed missing** `b_sec_user` row, never for a configured/disabled factor.
- `tests/V5AuthPoliciesTest.php`: **98 assertions PASS** (PHP 8.4.24); the two sources and test match exact GitHub blob hashes of the locally tested files. Full local regression: workflow 86, JSON handler 48, native MFA status 63, new auth policies 98 = **295/295 PASS**.

**Still unimplemented production dependencies:** current no-side-effect `V5CurrentLoginControlsPort` source based on actual Bitrix policy/lockout semantics; `V5PerUserSmsEligibilityPort` based on current per-account/group MFA policy; and deployment/approval of the new standalone first-factor limiter (described below). The native-global-options adapter is staging-only and has not been runtime-tested against production Bitrix. Do NOT call `CUser->Login()` or replay `OnBeforeUserLogin` from a password-only precheck: those can mutate authentication state. No SMS/JWT, no DB/production writes, no change to V4, and the V5 router remains fail-closed (HTTP 503).

**Canonical already-inspected structures:** `docs/STRUCTURE_REGISTRY.md`; all eight `b_user` columns were proved physically present. No repeat table inventory is needed.


## 2026-10-11 — four-scope atomic first-factor rate limiter (GitHub only)

New staged files:
- `src/V5FirstFactorAttemptGate.php`: an implementation of the existing `V5FirstFactorAttemptGatePort`, injected into `V5BitrixPasswordProofPort` without changing the signin flow. Reserves FOUR budgets before account lookup/password hashing: canonical login (via the established phone normalizer), server-observed IP, mobile installation/platform, and IP+login. Every quota and window length is **required deployment configuration**, not a guessed production value.
- Only HMAC-SHA256 keyed digests and attempt counters are stored, NEVER raw login, phone, IP or device identity. The HMAC key must be a securely generated shared secret. The HTTP router must construct a fresh per-request gate and bind its device/platform to validated signin input. `fromServerRemoteAddr()` ignores caller-controlled `X-Forwarded-For`; a verified trusted-proxy resolver is needed if a proxy obscures the client IP.
- `src/V5MySqlAttemptWindowStore.php`: staging-only MySQL/InnoDB transactional candidate. Verifies PDO driver/exception mode and table engine, rejects nested transactions. Acquires four row locks in deterministic order, checks counters, increments in ONE transaction or rolls back ALL. DB errors fail closed.
- `schema/PROPOSED_ev_v5_auth_attempt_windows.sql`: proposal for a NEW table, NOT executed on either site or staging. Production migration requires backup, verified schema, grants, rollback and approval.
- `tests/V5FirstFactorAttemptGateTest.php`: 70 assertions PASS (including integration with unchanged `V5BitrixPasswordProofPort`). `tests/V5MySqlAttemptWindowStoreTest.php`: 74 PASS on synthetic PDO transaction engine. **144/144 PASS**, PHP 8.4.24. Four committed PHP blobs match locally tested bytes, with current GitHub versions of the dependent V5 proof/reader classes.

**Not yet tested:** real MySQL concurrency, deadlock recovery, server clock alignment, deployment across workers, approved quotas, proxy IP resolution, rate-window expiry maintenance. Fixed windows may burst at boundaries; consider the shared-IP and deliberate account-lockout tradeoff. This protects **password attempts**, NOT SMS sending budgets. No live DB connection, no migration, no V5 JWT/SMS, no production or V4 changes, router remains HTTP 503.

Remaining actual website dependencies: `V5CurrentLoginControlsPort` and `V5PerUserSmsEligibilityPort`, plus challenge/TOTP-SMS/JWT/PIN integrations. The canonical Bitrix schema and V4 facts are already fixed in `docs/STRUCTURE_REGISTRY.md`; never rerun those audits as part of implementing the limiter.

## 2026-10-11 — real MySQL concurrency acceptance harness (not yet run)

Prepared two new files under `tests/integration/`: `V5MySqlAttemptStoreIntegration.php` and `run_mysql_attempt_concurrency.sh`, plus its `README.md`. **Only a dedicated pre-existing `evrasia_v5_test_*` staging MySQL schema is permitted**; both entry points refuse `evrasia`/`eur-bot-01`, mismatched DB identities or missing explicit test authorization. No production connection is permitted.

The runner synchronizes **24 independently connected MySQL PHP workers** over the same four rate buckets, tests that **exactly 7** requests are admitted and **17** denied, proves each committed bucket equals **7**, then tests rollback of earlier increments when the last bucket reaches its limit. It deletes only its own eight synthetic bucket keys after verification, leaving the test table in the isolated schema.

**Verification so far:** PHP 8.4.24 source lint PASS, Bash syntax PASS and `--lint-only` PASS. A run without explicit staging configuration was correctly refused before any DB connection. Both committed worker and Bash runner blob hashes match local linted files. **Actual MySQL contention remains PENDING:** this environment has no installed MySQL server or `pdo_mysql`. Do not claim an E2E pass until the operator supplies output from a dedicated staging run. The original 144 mocked-PDO/logic assertions remain a separate earlier milestone.

The test itself writes only synthetic counters into a new **test** schema and is not a production migration. V4 and production remain unchanged, and `api/v5/router.php` still fails closed with 503. Next implementation work can proceed on real site login/MFA evidence and SMS anti-spam budgets in parallel with this independent acceptance test.


## 2026-10-11 — SMS sending/financial anti-spam budget (OFFLINE, NOT LIVE)

New `src/V5SmsDispatchBudget.php` prepares **10** scope-separated HMAC-SHA256 quota windows covering account, verified phone, server-observed IP, mobile device, whole-service SMS count and whole-service **monetary upper-bound reservation**. Phone is returned only by `V5VerifiedSmsDestinationPort` (server-verified account phone), and price only by `V5SmsPriceCeilingPort` (authoritative upper price in currency minor units). HTTP does not accept a phone or tariff. No actual production tariff or quota values were invented. Daily windows must be at least 86400s; burst windows at least 60s.

`V5AtomicSmsOutboxPort::reserveAndQueue()` is a **security contract, not yet implemented**: must lock the password-proven SMS challenge, revalidate account/MFA/verified phone, enforce existing `ChallengeRules` (300s TTL, >=60s resend cooldown, max 3 SMS sends), atomically reserve all 10 quotas, and persist encrypted outbox + stable delivery idempotency key in the SAME transaction. Only after commit may a separate worker send a real SMS using a verified gateway. On unknown provider delivery outcome without proven idempotency, do not retry with a new paid send automatically. No JWT based on queueing.

`tests/V5SmsDispatchBudgetTest.php`: **111/111 PASS** on local PHP 8.4.24 using entirely synthetic destination/tariff and fake in-memory atomic outbox (no SQL/SMS). Regression workflow 86 + JSON 48 + password gate 70 + fake-PDO first-factor store 74 + SMS budget 111 = **389/389 PASS**. The two new committed PHP blobs match tested local files exactly.

Read the complete invariant and next implementation gate in `docs/SAMZABERU_V5_SMS_SEND_GUARDS_2026-10-11.md`. Real Bitrix phone resolver, current provider maximum tariff, transactional challenge/outbox/weighted quota store and SMS gateway are **NOT implemented**, staging E2E NOT run, production/V4 not changed and V5 router remains HTTP 503.


## 2026-10-12 — locked SMS challenge + weighted quotas + encrypted SQL outbox (staging candidate)

Added `src/V5MySqlSmsOutbox.php` behind the previously defined `V5AtomicSmsOutboxPort` with mandatory `V5SmsLockedEligibilityPort` and authenticated XChaCha20-Poly1305 encrypted `V5SodiumSmsMessageFactory`. Unlike the previous pure `V5SmsDispatchBudget`, this is a concrete **proposed MySQL/InnoDB transaction**: lock existing password-proven pending SMS challenge and server-HMAC-bound installation; enforce `ChallengeRules` TTL/attempts, resend cooldown and maximum three sends; require a live account/MFA/verified-phone recheck **with locked same-PDO rows**; reserve ten weighted HMAC budgets under sorted InnoDB locks; update code HMAC/challenge send count and insert a durable encrypted outbox delivery in one transaction. All-or-none rollback, including a last-bucket spending denial. The application uses DB time to avoid bypass via app-node clock drift.

Proposed **unapplied** schema: `schema/PROPOSED_ev_v5_sms_challenge_outbox.sql` (three new tables `ev_v5_auth_challenges`, `ev_v5_sms_budget_windows`, `ev_v5_sms_outbox`). No site tables, DB migrations or SMS were touched. The transaction does not create a new password-proof challenge; it consumes an existing one produced by a **future** securely bound challenge creator. The locked Bitrix account/MFA/verified-phone provider is **NOT implemented**, and the transaction adapter is NOT wired to `V5LoginWorkflow` or live HTTP.

New `tests/V5MySqlSmsOutboxTest.php`: **123/123 offline PASS** with fake PDO and synthetic phone and crypto keys, on PHP 8.4.24. Source/test/DDL GitHub blob hashes match locally tested files. Static schema field review PASS; **no real MySQL concurrency test** has been run for this SMS store. Existing 389 assertions were from the earlier milestone; do not count them as rerun in this milestone.

A dispatcher must only send **after COMMIT**, decrypt under a separate permission boundary, recheck challenge `current_delivery_id`/expiry/status and verified phone stamp, and use provider-confirmed idempotency (otherwise mark an ambiguous outcome `unknown` and never automatically perform a second paid send). **No worker or provider integration exists yet**, no independent V5 JWT, and V5 remains fail-closed HTTP 503. Canonical decisions: `docs/SAMZABERU_V5_SMS_SEND_GUARDS_2026-10-11.md`.


## 2026-10-12 — fail-closed locked Bitrix identity + verified phone candidate

Added `src/V5BitrixLockedSmsEligibility.php`, providing `V5BitrixLockedSmsEligibility` (a staging-only implementation of `V5SmsLockedEligibilityPort`) and `V5BitrixVerifiedSmsPhone` (server-side confirmed-phone preflight). This uses *already inspected* native tables `b_user`, `b_sec_user`, `b_user_phone_auth` with deterministic same-PDO `FOR UPDATE` ordering for locked decisions; no OTP secret is queried, and the presence of ANY MFA row disallows SMS downgrade. `ACTIVE`, `BLOCKED`, `PASSWORD_EXPIRED`, `LOGIN_ATTEMPTS`, account phone confirmation and user identity are validated before allowing the previously defined strict login and no-MFA policy ports.

Both preflight and locked recheck require a **durable verification event version** via `V5SmsPhoneVerificationVersionPort`, including repeat verification of an unchanged number. A separate `V5SmsLockedAuthEpochPort` must prove current password/MFA/security epoch under the same PDO. Neither site provider has been implemented or deployed; no fake HMAC-from-phone shortcut. Safe `V5StrictE164Phone` refuses unknown Bitrix phone formats until the live normalization contract is confirmed. Underlying MFA absent-row locks require explicit cross-flow enrollment serialization/epoch invalidation acceptance. These are **mandatory blockers**, not optional enhancements.

`tests/V5BitrixLockedSmsEligibilityTest.php` — **88/88 local fake-PDO assertions PASS** on PHP 8.4.24; test fixtures use actual `V5SmsQueueReservation` constructor shape and 10 windows. Committed source/test blob hashes equal the locally tested files. Production DB/V4 remain untouched; no live Bitrix integration, SMS gateway or V5 JWT; router remains HTTP 503. Source of truth: `docs/SAMZABERU_V5_SMS_SEND_GUARDS_2026-10-11.md` §6.


## 2026-10-12 — shared native V3C auth and verified-phone event versions (not live)

`src/V5SecurityEventVersions.php` implements the previously pending `V5SmsPhoneVerificationVersionPort` and `V5SmsLockedAuthEpochPort` (plus a trusted event writer). It extends the already planned **V3C** `ev_samzaberu_auth_state.AUTH_EPOCH` instead of proposing a parallel authorization table. Proposed initial, **UNAPPLIED** DDL: `schema/PROPOSED_ev_samzaberu_auth_state_v5.sql`, adding `PHONE_EVENT_VERSION` and `PHONE_BINDING_HMAC`. Do not infer an installed schema from this file.

A phone verification stamp now needs both (1) a real Bitrix `b_user_phone_auth.CONFIRMED=Y` row and (2) a persisted **confirmation event generation** and HMAC binding to the same verified E.164. Reconfirming the same number rotates its generation. Auth-epoch stamp uses the positive V3C `AUTH_EPOCH` plus the actual stored password hash and user ID; after a password/MFA/block/logout/recovery security event, old challenges must be invalidated. Stamping is unavailable if the backing state/coverage is missing.

The staged `V5SecurityEventVersionWriter` requires a pre-existing same-PDO transaction and locks `b_user → b_sec_user → b_user_phone_auth → auth_state` before CAS bumps. This DOES NOT prove native Bitrix action hooks execute in the same transaction. No row auto-creation/backfill and no production event registrations exist. A required unimplemented `V5NativeSecurityHookCoveragePort` fails closed until trusted code proves complete native event and lock coverage. The caller must never hold a challenge lock after native user locks in a way that inverts the existing SMS transaction order.

`tests/V5SecurityEventVersionsTest.php`: **84/84 offline PASS** (fake PDO, includes the real `V5BitrixLockedSmsEligibility`). Existing locked eligibility regression: **88/88 PASS**; two-test total **172/172** on PHP 8.4.24. The new source/test/schema Git blobs match locally verified bytes. **No real Bitrix or MySQL connection was used.** Next is a narrow compatibility test of the native Bitrix security-event transaction entrypoints and staging-only hook integration, NOT a new table inventory. V5 is still HTTP 503, production V4 unchanged.



## 2026-10-12 — release guard: native security mutation coverage (60 offline assertions)

A targeted review of the already archived 2026-10-11 native Bitrix source excerpts identified **unproven shared-transaction coverage**: `Otp::save()` calls `UserTable::add()`, `Otp::delete()` calls `UserTable::delete()`, the independent `OtpEvents::onRecheckDeactivate()` agent calls `UserTable::update()`, and `CSecurityUser::onAfterUserUpdate()` in the inspected excerpt merely clears OTP cache. This does **not** demonstrate that these native writes share the exact V5 PDO/MySQL transaction, b_user mutex and auth-epoch rollback. No claim is made that Bitrix itself has no transactions. A generic OnAfter handler is **NOT** acceptable evidence of atomic epoch tracking.

Added `src/V5NativeMutationCoverage.php` with `V5NativeMutationPath`, immutable `V5NativeMutationProof`, strict `V5NativeMutationCoverageMatrix` and `V5StrictNativeSecurityHookCoverage` implementing the pre-existing `V5NativeSecurityHookCoveragePort`. The matrix requires ten independent security mutation categories, and each needs a matching reviewed source SHA plus evidence of same PDO, b_user lock before native write, epoch update in the same transaction, shared rollback, and accepted staging concurrency test. Missing/unknown/duplicate evidence denies. `V5TrustedInstalledNativeCoveragePort` is **unimplemented for any live site**: do not wire synthetic allowlists/booleans as native proof.

`tests/V5NativeMutationCoverageTest.php`: **60/60 local PHP 8.4.24 PASS**, fake PDO and synthetic attestation only; new source/test Git blobs verified. This is an **integration/release gate**, NOT native hook installation. Full findings/next gate: `docs/SAMZABERU_V5_NATIVE_MUTATION_COVERAGE_2026-10-12.md`. No production/staging writes, V4 unchanged, V5 HTTP 503.



## 2026-10-12 — native Bitrix Mysqli transaction bridge (OFFLINE TESTED 80/80)

**NEW** `src/V5BitrixPdoCompatibilityBridge.php` adapts the original untouched `V5MySqlSmsOutbox`, `V5BitrixLockedSmsEligibility` and `V5SecurityEventVersions` to the SAME `Bitrix\Main\Application::getConnection()` / `Bitrix\Main\DB\MysqliConnection` used by native ORM, without rewriting challenge rules, ten SMS quotas, encryption or outbox transaction. It extends `PDO` only as a **minimal compatibility facade**, executing SQL with native Bitrix `query/queryExecute` and `SqlHelper::forSql`. It is NOT native PDO, NOT server-side prepared statements, and has NOT been used on the website.

Explicit `V5NativeBitrixExclusiveTransactionPermitPort` is mandatory: no real site implementation exists. It must independently prove no foreign native transaction; fake/config Boolean is forbidden. Denied/failed BEGIN never triggers rollback of a foreign transaction. Errors/`false` of native transaction/SQL fail closed. SQL read/write allowlists and direct execution guards apply.

`tests/V5BitrixPdoCompatibilityBridgeTest.php`: **80/80 PASS**, local PHP 8.4.24 using synthetic Bitrix connection and SQL helper, including commit/rollback of fake budget/outbox on the same fake native connection, CAS, no foreign rollback, uncertain commit, invalid SQL and silent native `false` returns. Source/test Git blobs **84aa6072d8f20a6065518b8059de23c3ad9306f0 / e5607ead2d3fb86b3a59c7e8b1426796d480efe5** match tested files. **NOT** real MySQL/Bitrix concurrency E2E; the existing 123 outbox assertions are previous separate work, not rerun here.

Source audit provided by user: `MysqliConnection` (Bitrix settings MYSQLI), transaction methods declared in `Connection`/`MysqlCommonConnection`. Full accepted architecture/guards: `docs/SAMZABERU_V5_BITRIX_TRANSACTION_BRIDGE_2026-10-12.md`. Outstanding: real transaction-scope attestation, exact SqlHelper/Error semantics, native ORM same DB handle, 10-path native security/MFA atomics, staging migrations and tests. No live HTTP integration, V5 503 and V4/prod unchanged.



## 2026-10-12 — Real Bitrix ORM/MySQLi transaction acceptance harness (STAGING PENDING)

Added `tests/integration/V5BitrixNativeOrmTransactionAcceptance.php` and
`tests/integration/BITRIX_NATIVE_TRANSACTION_ACCEPTANCE.md`, plus a **proposed,
unapplied** single-table staging-only schema
`schema/PROPOSED_ev_v5_native_tx_probe.sql`.

This is the NEXT check after the 80/80 **synthetic** Bitrix bridge unit
test: actual native `Application::getConnection()`, `DataManager::getConnection()`
object identity, ORM INSERT visible from native SELECT and native INSERT
visible from ORM SELECT within their native transaction, and correct InnoDB
`ROLLBACK` in both directions on a synthetic isolated staging table.

**Guards:** PHP CLI, explicit staging acknowledgement, no production hosts
or production document root, allowlisted `evrasia_v5_test_*` schema and
verified DB server hostname, autocommit=1 and inactive transaction before
writes, pre-existing InnoDB test table. The script imports Bitrix **only
after** initial guards; it never reads/writes real user or 2FA tables,
sends SMS or issues JWT.

Verified: PHP 8.4.24 lint PASS; with no stage credentials/configuration
the runner correctly exits `FINAL_STATUS=FAIL / DATABASE_WRITE=NO` before
Bitrix bootstrap/DB. Exact PHP source Git blob
`4263d2131a2bf67769416da347a486b0c37c6817`,
test DDL `87c7f74eb0e085119e7b7b116afd071b6e83f197` matched the
local linted/tested assets. **NO REAL STAGING RUN occurred**, because
a dedicated staging Bitrix + MariaDB/MySQL test installation is not yet
available here.

Even real native ORM rollback PASS is only *one* acceptance gate:
the trusted `V5NativeBitrixExclusiveTransactionPermitPort` and complete
native 10-path MFA/security AUTH_EPOCH/verified-phone hooks are still
unimplemented and cannot be bypassed. V5 remains 503 and V4/prod unchanged.



## 2026-10-12 — strict staging-only native transaction preflight (62/62 offline)

`src/V5StagingNativeTransactionPermit.php` is a CLI-only, **nonproduction**
candidate for the previously missing `V5NativeBitrixExclusiveTransactionPermitPort`.
Before the existing `V5BitrixPdoCompatibilityBridge` may call
`startTransaction()`, this guard requires an explicit isolated staging
acknowledgement, canonical nonprod root/app host, the exact
`evrasia_v5_test_*` DB schema and nonprod server host, the SAME
`Application::getConnection()` instance, **two identical** read-only
MySQL-session snapshots (database, server, connection ID, autocommit=1,
in_transaction=0), AND an independently verified internal Bitrix
transaction nesting depth of zero both times.

**IMPORTANT:** `V5VerifiedNativeTransactionDepthPort` remains a trusted
**unimplemented site dependency** — no default that returns zero is
provided. Hence no real deployment/HTTP authorization is possible from
this alone. The guard does NOT write to MySQL or begin a transaction.

`tests/V5StagingNativeTransactionPermitTest.php`: **62/62 synthetic assertions
PASS** on PHP 8.4.24; fake Bitrix/MySQL/depth only. Tested host/root/schema/
transaction/connection-change denials, SQL failure and strict connection IDs.
Source/test Git blobs `097a8dbd0e627a7cfbe7eedfcd9c207619b1a992` /
`0aa6af73091c68733d0a5dcf5e6c224f2bf03bf4` match locally executed
bytes. Real staging and native depth introspection still PENDING.

Authoritative checkpoint:
`docs/SAMZABERU_V5_STAGING_TRANSACTION_PERMIT_2026-10-12.md`. The earlier
Bitrix ORM/native rollback acceptance remains **PREPARED, NOT RUN**,
native MFA/epoch hook atomicity is unproven, and `api/v5/router.php` is 503.
Production/V4 and all databases untouched.


## 2026-10-12 — FINAL HANDOFF FOR NEW CHAT (native transaction depth read-only output next)

**Authoritative continuation:** `docs/SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-12.md`; keep canonical `docs/STRUCTURE_REGISTRY.md` identical in main + this feature. No more general b_user/V4/TOTP audits.

**Next user operation** is running the exact committed `tests/diagnostics/v5_native_depth_readonly.sh` on the **existing production host `evrasia` in READ_ONLY mode** (NOT any staging writer test). Its Git blob is `5718f64f6eb6256645bffd0d526bb19ab0c0eee4` / file SHA256 `37bb42963c25c72f34430eafb6cc77672d9e550c22d263ce07c1fe3c568265e6`. The Bash syntax, embedded-PHP parser syntax, fake parser input and wrong-host denial were tested locally; the script has **NOT yet run on evrasia**. It verifies SHA of three pinned Bitrix DB source files and extracts **method/transaction nesting metadata only**; no MySQL connection, Bitrix bootstrap, raw source text, secrets, or production mutations.

The operator will paste the **entire output as the FIRST message of the next chat**. The next assistant should immediately analyze `TRANSACTION_RELATED_VARIABLES`, `METHOD=startTransaction/commitTransaction/rollbackTransaction` and source SHA. A script `PASS` is ONLY evidence of successful static inspection; `V5VerifiedNativeTransactionDepthPort` remains NOT IMPLEMENTED. Latest permit source/test **62/62 fake PASS**, real isolated Bitrix/MySQL E2E NOT RUN. Preserve V5 HTTP 503, V4/production unchanged, no live hooks, SMS or DDL.


## 2026-10-13 — Native-depth staging preflight prepared (NOT RUN on staging)

The operator-provided, checksum-matched Bitrix source output proved the inherited `protected int $transactionLevel = 0` and `START TRANSACTION / SAVEPOINT / COMMIT / ROLLBACK` mechanics in the installed `MysqlCommonConnection`. A pinned Reflection reader was implemented as `src/V5VerifiedBitrixNativeTransactionDepth.php` (18 synthetic checks on the previous milestone, **not** a real runtime proof).

New `tests/integration/V5BitrixNativeDepthReadOnlyPreflight.php` checks this provider on a physically isolated staging Bitrix installation, together with the existing strict transaction permit, without explicit DML/BEGIN. Its PHP 8.4.24 lint and two expected negative pre-bootstrap exits passed locally. Bitrix bootstrap can have side effects and the script must never be pointed at the production site/database.

The prepared `tests/integration/V5BitrixNativeOrmTransactionAcceptance.php` now checks pinned depth zero and the actual staging permit before **each** synthetic BEGIN, then checks native depth 1/0, `CONNECTION_ID()` stability and MySQL transaction state around each rollback. A 14-check synthetic PHP test covered its SQL-session-state helper; the updated whole file and real MySQL/Bitrix E2E remain **not accepted**. Do not treat the original fake smoke of 2026-10-12 as a rerun of this revision.

See `docs/SAMZABERU_V5_NATIVE_DEPTH_READER_2026-10-13.md` and `tests/integration/BITRIX_NATIVE_TRANSACTION_ACCEPTANCE.md`. Next: independently prove staging isolation; run read-only preflight there; review full output; only afterward authorize isolated staging synthetic writes. Production/V4 untouched; V5 HTTP 503 per last confirmed documentation. 


## 2026-10-13 — CI verified / dedicated staging still missing

A feature-branch-only, no-network/read-only PHP 8.4 workflow `.github/workflows/v5-offline-safety.yml` is now active; real GitHub Actions [run 38361251912](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38361251912) **completed SUCCESS** with PHP 8.4.26: native depth **18**, staging permit **62**, PDO compatibility bridge **80** synthetic assertions (160 total); lint for eight PHP files and both mandatory negative pre-bootstrap guards. The CI does **not** bootstrap Bitrix or connect to MySQL.

Canonical preparation/infrastructure contract: `docs/SAMZABERU_V5_ISOLATED_STAGING_READINESS_2026-10-13.md`. A dedicated Bitrix/MySQL staging has not been independently attested; do not confuse the bot's production Compose temporary-file staging with a separate Bitrix stage. Before any staging bootstrap, independently prove nonproduction host, DB server/credentials, sanitized data and blocked external SMS/RestIS/email/Telegram agents. Start with `V5BitrixNativeDepthReadOnlyPreflight.php` there; only after real PASS and separate operator approval permit the synthetic rollback test. Neither real stage script has run.

`PRODUCTION_WRITE=NO`; `V4_UNCHANGED=YES`; `REAL_STAGING_E2E=NOT_RUN`; `V5_HTTP=503` (last documented baseline).


## 2026-10-13 — Full reviewed offline CI coverage: 22 suites

The feature-only, network-isolated / read-only GitHub Action now tests the entire reviewed V5 offline matrix rather than only the newest native transaction classes. [Run 38363042677](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38363042677) at commit `e034a9c751c99315307c648094c509103322edb4` **SUCCESS** on PHP 8.4.26: **48 PHP files lint PASS**, **22 approved offline test suites PASS**, and both staging entrypoints reject missing authorization before Bitrix bootstrap. The full regression covers authentication, TOTP decision/fallback, account reader and password proof, first-factor attempt budgets, SMS quota/outbox/verified phone, event epoch/10-path coverage, V5 session policy, native Mysqli bridge and depth reader. Tests remain synthetic/static only.

**Do not run positive real integration on production.** A separately isolated staging Bitrix / MySQL instance and security-isolated credentials have not been confirmed; the next real step is staging isolation attestation per `docs/SAMZABERU_V5_ISOLATED_STAGING_READINESS_2026-10-13.md`, then the already prepared read-only staging depth preflight. `V4_UNCHANGED=YES`, `PRODUCTION_WRITE=NO`, `V5_HTTP=503` (last confirmed baseline).
