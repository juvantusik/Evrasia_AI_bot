# SamZaberu mobile — API V5 authorization contract

> Status: **ISOLATED OFFLINE COMPONENTS IMPLEMENTED / SITE INTEGRATION PENDING / NOT DEPLOYED**
>
> Date: **2026-10-11**
> **STRUCTURE SOURCE OF TRUTH (2026-10-11):** [docs/STRUCTURE_REGISTRY.md](STRUCTURE_REGISTRY.md). All 8 required physical `b_user` columns were confirmed in production DB schema, including `PASSWORD_EXPIRED` and `LOGIN_ATTEMPTS`. Do **not** repeat schema/Bitrix/V4/TOTP/Trusted Device discovery. Static ORM field-map status and the not-deployed V5 state are recorded separately. Earlier sections retain historical checkpoints, but this notice supersedes an older 'schema not verified' statement.
>
> Approved product decision: develop a separate API V5 for a future SamZaberu mobile app version. Leave the current V4 API and current mobile app behavior unchanged while V5 is developed and tested.
>
> This is a contract/implementation checkpoint, **not** evidence that routes, migrations, SMS challenges, client changes, or V5 JWT protections exist in production.

## 1. Confirmed existing production context

The site-side Bitrix production host is `evrasia`, with website root `/home/site_evrasia/web/evrasia.spb.ru/public_html`.

The existing V4 mobile signin implementation is site-local code, **not** the Telegram/Anti-Fraud application in this repository:

- `local/php_interface/Controllers/V4/Auth.php::signin()`;
- `api/v4/router.php`;
- `local/php_interface/lib/Services/TrustedDeviceMobileService.php`;
- `local/php_interface/lib/Services/TrustedDeviceService.php`;
- `local/php_interface/lib/PhoneAuth.php`;
- `local/php_interface/Controllers/V4/PinCode.php`.

Read-only production inspection on 2026-10-11 found:

- `Auth::signin()`: `CUser->Login(...)` at line ~226; `JwtAuthorization::createTokens()` at ~238; `TrustedDeviceMobileService::registerSuccessfulAuthentication(... AUTH_PASSWORD ...)` at ~241–251; token response at ~255.
- The direct `signin()` body does not show a separate trust validation or TOTP challenge. `CUser->Login()` has its own Bitrix authentication/MFA semantics; **do not assume password-only authentication actually succeeds for OTP users until correctly tested**.
- `TrustedDeviceMobileService::validateTrust()` already accepts a mobile request with `device_id`, `platform`, `trust_token`, normalizes the namespaced `device_id` and delegates to `TrustedDeviceService::validateTrust()`.
- The trust core validates active user–device linkage, presented token hash, and expiry; `device_id` alone is not authorization.
- `registerSuccessfulAuthentication()` returns a `trust_token`, `token_version`, `expires_at`, `platform` and other metadata. The current V4 password signin disregards that return value. Do not infer that an old mobile client stores the token.
- `PhoneAuth::check()` has its own SMS-based JWT issuance and trusted-device registration.
- `transferAuthenticatedSession()` is a separate existing SMS/Mobile ID flow with explicit mobile-session revocation; do not redirect normal password/TOTP flow through it casually.

Established production Trusted Device mobile identity: `device_id = "sz_" + 64 lowercase hex` (67 chars); `platform = ios|android`. The accepted adapter hashes the entire namespaced external ID before using the existing core service. Do **not** strip `sz_`, change the shared core format, or treat it as a hardware ID.

**Source change warning:** On 2026-10-11 the production source SHA values for the mobile adapter/core differed from the historical 2026-09-24 acceptance record. Preserve current production semantics; always check current source before any patch.

## 2. Scope and strict non-goals

Implement for the **new SamZaberu mobile app**:

1. Password first factor.
2. If native Bitrix TOTP is active for this user: verify a current code from the **existing** authenticator/Bitrix secret. No second enrollment or separate secret store.
3. Otherwise, check active `device_id + platform + trust_token` from an independently established trusted device. Valid trusted devices bypass SMS.
4. Otherwise, issue a server-controlled, rate-limited SMS challenge to the existing verified account phone, **never** to a phone supplied by the client.
5. Only after required confirmation, issue V5-authenticated mobile JWT/refresh credentials.
6. After V5-authenticated login, reveal the RestIS bonus-spending PIN on explicit user action without another VK/SMS challenge (subject to the accepted security/session policy).

Non-goals during the first phase: changing V4 routes/responses, Bitrix vendor core, existing web 2FA enrollment, existing Mobile ID/PhoneAuth flows, the Anti-Fraud device graph/scoring, or unrelated signup/legal-consent workflows.

## 3. Proposed API surface (NOT YET LIVE)

Route names below are **new V5 proposals**, not claims about deployed endpoints. Final filenames/bootstrap must be chosen after a **focused** review of the existing site-side V4 router (do not replicate the entire V4 implementation).

| HTTP | Proposed route | Use |
|---|---|---|
| POST | `/api/v5/auth/signin` | Verify password, decide if native TOTP / trusted device / SMS is required |
| POST | `/api/v5/auth/challenge/verify` | Verify OTP or SMS bound to pending login challenge; issue JWT only on success |
| POST | `/api/v5/auth/challenge/resend` | Resend SMS for an existing challenge with strict rate limits |
| POST | `/api/v5/auth/refresh` | Refresh **only** a V5 session with server-verifiable assurance |
| POST | `/api/v5/loyalty/pin` | Obtain/display RestIS PIN only from a sufficiently authenticated V5 session |

Other V5 endpoints should initially reuse vetted business services rather than duplicating V4 internals. Define logout, registration, profile and Mobile ID versioning only as the migration plan requires.

### 3.1 Signin request (proposed)

```json
{
  "login": "<login-or-email>",
  "password": "<user-password>",
  "device_id": "sz_<64 lowercase hex>",
  "platform": "ios",
  "trust_token": "<optional token from platform secure storage>"
}
```

Actual secrets must only travel over HTTPS; never log the request body.

On pending verification, respond with `challenge_id`, `challenge_type` (`totp` or `sms`), `expires_in`, limited resend metadata. **Never return JWT, refresh token, Bitrix session privilege, user OTP secret, full phone, or bonus PIN at this stage.** SMS number is resolved on the server from the authenticated account.

When 2FA is OFF and valid approved trust is supplied, signin may complete immediately with V5 JWT; do not bypass TOTP for users with 2FA ON.

### 3.2 Challenge verification (proposed)

```json
{
  "challenge_id": "<opaque random id>",
  "code": "<six digits>"
}
```

The server checks challenge TTL, retry counters, one-time consumption, binding to first-factor user and intended installation. Any OTP verification uses the existing native Bitrix TOTP state and replay policy, without cloning a second secret store or weakening native OTP reuse checks.

Successful result includes V5 access/refresh token data and, when appropriate, a new mobile `trusted_device` object with `trust_token`, version and expiry for iOS Keychain / Android Keystore-backed secure storage. Do not return this for a failed/partial challenge.

### 3.3 SMS resend (proposed)

```json
{
  "challenge_id": "<opaque random id>"
}
```

No `phone` parameter. Enforce user/IP/device/channel limits before sending; server-side SMS delivery only; resend never extends a challenge indefinitely or bypasses verification.

## 4. Authorization state machine

```text
POST V5 signin (password, device_id, platform, optional trust_token)
        |
        +-- first-factor invalid / blocked / inactive
        |       -> generic failure, no challenge, no SMS, no JWT
        |
        +-- account native TOTP active
        |       -> pending TOTP challenge -> valid current Bitrix OTP
        |
        +-- no TOTP, approved trust_token valid for this user/device
        |       -> no SMS
        |
        +-- no TOTP, no approved trust
                -> pending SMS challenge to account phone
                   -> valid bound one-time SMS code
        |
        v
issue V5 authenticated session / refresh / JWT
        |
        v
register/rotate approved mobile trust as policy permits
        |
        v
authenticated PIN view without additional SMS
```

**Critical:** Do not use `CUser->Login()` in a way that sets usable session cookies or grants an authenticated context before the required second factor. Inspect native Bitrix semantics at the V5 first-factor boundary and implement/test the correct deferred-login behavior. Do not modify vendor Bitrix files.

## 5. Trust migration and assurance

- Keep the `sz_ + 64hex` mobile installation ID contract, the existing adapter's `toCoreDeviceId()` and core `validateTrust()`.
- A stored row created by **password-only V4 login is not proof of SMS/TOTP confirmation**. Do not automatically whitelist these rows just because a trust token can exist.
- Eligibility for the V5 no-SMS path must be based on a **server-verifiable prior strong authentication**, not on client-supplied `auth_method`, device ID, app version or arbitrary JWT claim.
- A new device becomes trusted only after the full required V5 authentication. Reissuing/rotating `trust_token` must be reflected in the response so the app can update secure storage.
- Do not use `transferAuthenticatedSession()` for ordinary password/TOTP login; that existing method can revoke unrelated mobile sessions.
- If mobile `device_id` or platform is missing/invalid, do not silently grant trust. Define explicit compatibility errors for V5 clients.

## 6. OTP, SMS and token security

- Use native Bitrix TOTP secret/verification/replay behavior; after using the same time-step code on the website, immediate reuse in SamZaberu may correctly fail. Do **not** defeat replay prevention for convenience.
- Pending challenges must be opaque, high-entropy, short-lived (initial proposal: <=5 minutes), bound to verified first factor, consumed once and rate-limited (initial proposal: <=5 verification attempts).
- SMS sending must be rate-limited across user/phone/device/IP, include cooldown, hourly/daily budgets, alerts, and abuse-safe failure responses. Provisional example: >=60s resend cooldown; finalize against existing SMS gateway and historical abuse limits. Do not send SMS before successful password verification.
- TOTP enrollment/reset/recovery remains a separate protected procedure; do not allow SMS fallback to downgrade a 2FA-protected account.
- OTP code, password, phone, secret, raw `device_id`, `trust_token`, access token and refresh token must not appear in application logs, diagnostic output, analytics or Anti-Fraud display.
- Bonus-PIN disclosure requires a **server-verifiable V5 session assurance marker**; a V4 JWT, a user flag "2FA enabled", and a forged `amr` request attribute are insufficient. Determine representation in the actual `JwtAuthorization` code before implementation. Verify refresh cannot elevate old sessions or lose assurance.
- Existing web sign-in / logout / session revocation and current anti-fraud blocking semantics must remain effective.

## 7. Important V4 bypass / rollout boundary

Versioning routes does **not** by itself close old authentication methods.

During isolated development, V4 stays operational to avoid disrupting installed app versions. Thus V5 alone must **not** be represented as system-wide protection until the operator approves and deploys an enforced switch.

Before declaring a user migrated:

- prevent that user from bypassing required login verification through V4 password sign-in;
- account for V4 PhoneAuth, Mobile ID and other JWT issuance paths according to the final approved policy;
- prevent old V4 refresh credentials from upgrading into protected V5 sessions;
- ensure bonus-PIN V5 access cannot be obtained using a legacy/low-assurance JWT;
- enforce rollout on the **server**, not merely by an app-version header (which a caller can spoof);
- handle old mobile app versions with an explicit "update required" path for users switched to V5.

This should be controlled per pilot USER_ID before widening the rollout. The initial known pilot USER_ID is 880339, but re-check current account/MFA state before running any real pilot.

## 8. Implementation sequence and acceptance

1. **READ_ONLY site routing audit:** inspect exactly `api/v4/router.php`, entrypoint/bootstrapping and JWT session issue/refresh boundary. Do not repeat global Bitrix/Anti-Fraud audits.
2. **Isolated V5 code:** separate router/controller/challenge service; no modifications to V4; no public V5 auth access before tests pass. Choose challenge persistence based on actual deployed storage and transaction capabilities. No guessed DB tables.
3. **Offline/staging tests:** invalid password; account blocked/inactive; native TOTP on/off; correct/wrong/replayed TOTP; trust valid/expired/revoked/password-only; SMS spam prevention, resend, wrong/expired/replayed code; concurrent verify; token refresh; direct PIN assurance; Mobile ID and V4 regression.
4. **Pilot:** only after backup, syntax/unit tests, documented rollback and explicit operator approval of production writes; start with a single approved account and check old/new app compatibility.
5. **App handoff:** implement V5 calls, TOTP screen, SMS challenge screen without phone input, secure `trust_token` storage/rotation, explicit update-required behavior, and API error contracts.
6. **Rollout:** server-enforced migration from V4 auth to V5 for eligible users; gradually enable broader users only after E2E acceptance.

## 9. Current completion status

- [x] Product decision: separate V5, keep V4 while developing.
- [x] V4 source flow and existing mobile trust methods surveyed read-only on 2026-10-11.
- [x] V5 **proposed contract documented**.
- [ ] Actual V5 router/controllers/services created.
- [ ] V5 JWT assurance and refresh implemented.
- [ ] V5 SMS challenge persistence/rate limits implemented.
- [ ] Native Bitrix TOTP V5 E2E confirmed.
- [ ] Stage/pilot and app developer acceptance.
- [ ] Secure V4-to-V5 migration enforcement.

No file/database/route changes on website production are authorized or implied by this document.

## 10. Isolated V5 policy prototype (2026-10-11)

First implementation increment is committed to the isolated GitHub branch `feature/samzaberu-api-v5-auth-contract` in `site-integration/samzaberu-v5/`:

- `src/AuthDecision.php` — PHP native type-safe factor selector (DENY, native TOTP, approved trust, SMS).
- `tests/AuthDecisionTest.php` — 32/32 exhaustive truth-table cases passed locally on PHP 8.4; PHP lint passed; code targets PHP >=8.2.
- `README.md` — explicit integration boundaries and fail-closed requirements.

**Important:** this is testable pure code, **not** a website controller/router and **not deployed**. It does not create JWT, send SMS or access a DB. Integration still requires the live `api/v4/router.php` and `JwtAuthorization` behavior to be inspected. V4, production website and Bitrix core remain unchanged.

## 11. V4 router / JWT integration checkpoint (2026-10-11)

A focused read-only site inspection on `evrasia` completed: **7 PASS, 0 FAIL, 0 WARN**. Production sources and database were not changed.

- V4 routes use `Bramus\Router\Router` and existing site-specific middleware. V5 must have its own entrypoint/router, without modifying or cloning all V4 endpoints.
- V4 JWT supports access/refresh credentials and legacy compatibility flows. **Do not reuse V4 token-verification or refresh methods to authorize V5**. V5 requires independently authenticated, server-verifiable assurance and a fail-closed revocation policy.
- Current production logs and compatibility paths require a separate access-controlled security review before any shared changes are authorized. Avoid copying debug request logging or response diagnostics into V5.
- The last unresolved implementation prerequisites are the live API bootstrap/entrypoint convention, the existing refresh boundary, and the existing site-side storage API for short-lived challenges.

**Do not publish sensitive vulnerability details, current keys, credentials or security-audit raw outputs in this repository; keep detailed findings in the access-controlled operational review.**

**Deployment status:** the V5 code in this branch is an isolated offline decision prototype, not a deployed API. V4 and Bitrix production remain unchanged.


## 12. Focused V4 entrypoint and refresh checkpoint (2026-10-11)

Read-only production diagnostic returned `FINAL_STATUS=PASS`, `RUN_RC=0` and matched previous SHA baselines for the V4 router and JWT class.

- V4 routing runs through `Bramus\\Router\\Router::run()` at the end of `api/v4/router.php`, with an `api/v4/.htaccess` present; `api/v4/index.php` is absent. **The rewrite contents are not yet inspected**, so do not guess the V5 public entrypoint or Apache rewrites.
- Legacy refresh `JwtAuthorization::makeRefresh()` uses its V4 refresh token/DB state and then calls `createTokens()`. **It does not demonstrate V5 MFA/session assurance**; V5 must implement and enforce a separate refresh boundary, not pass V4 refresh tokens into V5.
- Site production was read-only; no V5 router exists on that host.
- In the isolated GitHub prototype, `src/ChallengeRules.php` with `tests/ChallengeRulesTest.php` adds a **locally verified 55-case** pending-challenge policy. Rules require strong server-side binding, short TTL and SMS resend cooldown; durable atomic storage and gateway rate limits are still missing.

Next site dependency: inspect only the relevant nonsecret `api/v4/.htaccess` rewrite target and the actual bootstrap wiring before authoring V5 `.htaccess`/`router.php`. Do not modify or duplicate V4.

## 13. V5 router scaffold isolated in GitHub (2026-10-11)

The API V5 branch contains a new **disabled/fail-closed routing scaffold**, using the established Bramus router only behind an explicit readiness guard. Five V5 POST route names are registered separately from V4. All callbacks remain unavailable (503), no matter whether the guard is set. This is *not a deployed API*: production Bitrix and V4 are unchanged.

Files: `site-integration/samzaberu-v5/api/v5/router.php`, `src/V5RouteMap.php`, `tests/V5RouteMapTest.php`. Offline PHP lint, route-map test and default-deny smoke check passed.

The operational paste after the last step contains repeated V4/JWT entrypoint inspections, **not** the `api/v4/.htaccess` rewrite-target result. Do not invent V5 rewrite/bootstrap behavior until verified.


## 14. Rewrite checkpoint and V5 candidate (2026-10-11)

The operator's read-only production log verifies `api/v4/.htaccess`: `RewriteBase` present, three rewrite conditions and one rule targeting `router.php`; SHA256 `61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3`; `FINAL_STATUS=PASS`. The V4 router and JWT flow have already been mapped; repeating that broad audit is unnecessary.

Added `site-integration/samzaberu-v5/api/v5/.htaccess` as an **independent, staging-only rewrite candidate**, plus a PHP static contract test. Offline PHP 8.4 lint and test passed. This is not a replica of V4's unseen rewrite conditions and **not a live Apache validation**. The V5 router still returns HTTP 503; no V5 auth endpoints, SMS, TOTP or tokens can be used.

**Next implementation gate:** build the real V5 Bitrix adapters and atomic challenge/session persistence in a non-production environment. Verify Apache rewrite, router base path, and package layout against the live bootstrap before any pilot.


## 15. Implemented V5 login orchestration and JSON handler (2026-10-11)

Added to `feature/samzaberu-api-v5-auth-contract` (no production deployment):

- `site-integration/samzaberu-v5/src/V5LoginWorkflow.php` implements the typed login state transitions through server-provided password proof, native TOTP challenge, verified MFA-proven Trusted Device, or SMS challenge; confirmation and SMS resend are separate operations. No JWT on pending challenge, no weak V4 trusted-device bypass.
- `site-integration/samzaberu-v5/src/V5AuthHttpHandler.php` provides strict JSON I/O for the first three endpoints. Refresh and PIN handlers remain intentionally unavailable.
- `tests/V5LoginWorkflowTest.php`: **86 locally passing assertions**. `tests/V5AuthHttpHandlerTest.php`: **48 locally passing assertions**. Source and tests match verified GitHub blob SHA to locally tested bytes.

No production Bitrix session or user table was touched. The backend interfaces deliberately require implementations that prove the password **without creating a Bitrix session**, verify the **existing** native TOTP with replay protection, use a transactional/atomic OTP challenge store and SMS quotas, validate both trust cryptography **and** prior MFA enrollment, and issue **separate V5** JWT sessions with server-side assurance checks.

**Remaining before live pilot:** actual Bitrix adapter methods based on current site version; atomic challenge persistence; SMS integration and global budgets; V5 JWT access/refresh signing, independent keys and server-verifiable assurance; Apache/Bitrix staging integration and authenticated end-to-end test. All V5 router requests still return **HTTP 503**. V4 and production remain untouched.


## 16. Pre-existing Bitrix-native TOTP implementation assets — reuse (2026-10-11)

Project history confirms that native Bitrix TOTP code and replay behavior were already studied in read-only audits earlier on 2026-10-11. The V3C prototype includes `NativeTotpVerifierV3C`, `PdoTotpStoreV3C`, a locked-row native candidate, locked identity/version projections, and an offline test harness. The verifier's `verifyAndConsume(Account,code)` contract requires password proof, device binding, strict rate limits and per-user locking; old V3C state tables are **not installed** and those classes are **not live**.

**Accepted next move:** adapt the existing V3C native OTP implementation behind V5's `V5AtomicChallengePort` after checking original source hashes and confirming atomic behavior in staging. Do NOT recreate TOTP secrets/algorithms or claim V3C was deployed. The missing separate prerequisite is a proven Bitrix **password verification method with no PHP session authorization side effect**. Secure SMS challenge storage and V5 JWT issuance remain distinct implementation tasks.


## 17. Installed Bitrix Password::equals verified and V5 no-login adapter (2026-10-11)

The operator's most recent **read-only** test on `evrasia` verified that `bitrix/modules/main/lib/Security/Password.php` (case-sensitive path, SHA256 `a1ffbba30510caa373087127d3af5714d36be526f5df854610f80ff227a10296`) defines a callable public static `\Bitrix\Main\Security\Password::equals()`. Synthetic SHA-512 and legacy salted-MD5 comparisons both correctly accepted the right password and rejected the wrong one. No real user records were accessed, no login/session/OTP was performed, and no production source was changed.

Added `src/V5BitrixPasswordProofPort.php` and `tests/V5BitrixPasswordProofPortTest.php` to the isolated GitHub V5 branch. Locally, 39 unit-test assertions passed using an interface-compatible stub and fake reader/gate/comparator. The resulting GitHub blobs match the locally tested source/test file bytes. This provides password hash verification without `CUser->Login()` and demands a fail-closed atomic attempt reservation, a unique server-side account lookup and verified active/blocked/native-TOTP flags. There is **no actual Bitrix account resolver or throttle implementation yet**.

Before wiring the new port, reconcile all site-specific login restrictions including `PASSWORD_EXPIRED`, `LOGIN_ATTEMPTS` policy and any account/Anti-Fraud blocking semantics; do not mistake correct hash verification for permission to log in. Existing V3C native TOTP provider remains a separate staged component. **V5 is not deployed**; the V5 router still responds 503. V4, production files and DB remain unchanged.


## 19. Fail-closed native Bitrix MFA state (2026-10-11)

The independent V5 branch now includes `src/V5NativeTotpStatus.php` and `tests/V5NativeTotpStatusTest.php`, **63 offline assertions PASS** on PHP 8.4; committed files were compared with locally tested Git blobs. This reader queries `Bitrix\Security\Mfa\UserTable` directly as a **staging candidate** (rather than cached `Otp::getByUser()`); no real Bitrix data was queried. Active valid TOTP is selected, while inactive/temporarily deactivated, incompatible or malformed configured MFA remains *unknown and denied*. No-MFA/SMS fallback is permitted only after both row absence and independently verified non-mandatory Bitrix policy. Its mandatory-policy provider is **not implemented yet**. V3C remains the planned atomic native OTP verifier for the challenge itself; this status reader never validates OTP codes.

Next review must establish the live `Bitrix\Main\UserTable` ORM map fields plus the existing site login and mandatory-2FA policies by read-only, no-bootstrap source checks. Avoid bypassing lockout, treating inactive OTP as absent, or issuing tokens without a later locked re-check. All V5 HTTP routes remain HTTP 503. V4 and production unchanged.


## 20. Bitrix UserTable static field mismatch and safe V5 account source candidate (2026-10-11)

The new read-only operator result on `evrasia` is `FINAL_STATUS=PASS`: live source guards for unchanged V4 authentication and native MFA passed. The file `bitrix/modules/main/lib/UserTable.php` (SHA256 `e9574a33afa1a592b7f4555ba1f5a5bb54e2fa70f84968360feeb56396e725a4`) did not reveal `PASSWORD_EXPIRED` or `LOGIN_ATTEMPTS` in a static field-name search, although the standard Bitrix login PHP source uses those identifiers. This is **not a live ORM metadata check and not a database schema inspection**.

Changed `site-integration/samzaberu-v5/src/V5SiteAccountReader.php` (branch only) to **refuse the native ORM query** when the required fields are missing from the runtime ORM entity field map. Expanded its offline test to **94/94 PASS**. Added `src/V5PdoUserRowSource.php` as an alternative parameterized, two-result-limit SQL **candidate**, and `tests/V5PdoUserRowSourceTest.php` (**28/28 PASS** with mocked PDO, no DB connection). All four GitHub blobs were matched to the locally tested bytes. This minimizes changes to the existing V5 login workflow.

**SUPERSEDED BY 2026-10-11 SCHEMA CHECK:** all 8 `b_user` columns are now verified in the production physical schema (`MISSING_COLUMN_COUNT=0`, `FINAL_STATUS=PASS`, no user records read). The ORM guard and new SQL provider remain not deployed; ORM runtime map and real PDO account-query execution are still unverified. Next gate is live login/lockout policy semantics, not another general table inventory. Do not bypass any blocked/expired/unknown account state. The V5 HTTP router remains disabled (503); V4 and production unchanged.


## 21. Login-policy enforcement and SMS-fallback eligibility (2026-10-11)

Implemented in the isolated branch **only**:

- `src/V5ExistingLoginPolicy.php`: strict `LOGIN_ATTEMPTS` evaluator, requiring independently retrieved *current* native attempt ceiling and three authoritative no-restriction signals. It does not guess lockout thresholds or invoke a Bitrix login action.
- `src/V5NoMfaFallbackPolicy.php`: a no-downgrade fallback rule that requires both live native global mandatory-MFA settings and explicit per-user SMS eligibility. Optional native `Otp::isOtpEnabled()`/`Otp::isMandatoryUsing()` adapter is **staging candidate only**.
- `tests/V5AuthPoliciesTest.php`: 98/98 local assertions PASS; regression runs for V5 workflow (86), JSON (48), native MFA state (63) plus policies (98) totaled **295/295 PASS**. New GitHub blobs verified byte-for-byte against the locally tested files.

The current trusted inputs `V5CurrentLoginControlsPort` and `V5PerUserSmsEligibilityPort` are still missing their real website implementations, as is deployment of the standalone atomic attempt limiter (described below) and any end-to-end session issuer/challenge backend. This is an application-policy implementation, **not a working Bitrix login integration**. Use previously documented source contracts rather than rerunning the `b_user`, V4, OTP and Trusted Device structure audits. The canonical structure registry is `docs/STRUCTURE_REGISTRY.md`. Existing production V4 and website remain unchanged; V5 still responds 503.


## 22. Atomic first-factor attempt budgets (GitHub-only, 2026-10-11)

Implemented `src/V5FirstFactorAttemptGate.php` and `src/V5MySqlAttemptWindowStore.php` under the previously defined `V5FirstFactorAttemptGatePort`, with `schema/PROPOSED_ev_v5_auth_attempt_windows.sql` defining a new, **NOT INSTALLED** InnoDB attempt-count table. The gate reserves four keyed-HMAC windows (login, observed IP, installation/platform, IP+login) before account lookup/password proof; no raw identity is stored. Every quota/window is provided explicitly in future production configuration, not hard-coded or assumed. Its request context must be bound to validated mobile input and trustworthy source IP rather than untrusted forwarding headers.

The InnoDB candidate takes deterministic row locks and commits all four reservations or rolls back the entire operation; an absent/non-InnoDB table and unexpected transaction/connection state deny access. Tests on local PHP 8.4.24: gate 70/70 and fake-PDO transactional checks 74/74 (**144/144 PASS**); GitHub source/test blobs matched locally tested bytes. **Real MySQL concurrent stress is NOT done**, and no table migration or data change occurred.

Acceptance before enabling: staging backup and migration, InnoDB/permission checks, concurrent competing sessions/rollback, approved quotas and lockout UX, shared HMAC secret and synchronized clock, trusted-proxy resolver, bounded cleanup of expired counters. SMS financial/burst budgets are separate and still unimplemented. `V5CurrentLoginControlsPort` and `V5PerUserSmsEligibilityPort` still require authoritative website adapters; V5 router remains HTTP 503 and production V4 unchanged.

## 23. MySQL concurrency acceptance harness prepared (2026-10-11)

Added `site-integration/samzaberu-v5/tests/integration/V5MySqlAttemptStoreIntegration.php`, `run_mysql_attempt_concurrency.sh` and `README.md` to the isolated branch. The test has explicit local-test-schema, MySQL-host identity and production-host guards. It verifies 24 concurrent connections, expects exactly 7/24 successful reservations under contention, checks all four counters, and separately verifies full rollback on a last-bucket denial. Synthetic HMAC counters are removed by a narrowly scoped cleanup; no Bitrix source, user accounts or V4 sessions are involved.

**State: READY FOR STAGING / NOT EXECUTED against a live MySQL server.** Local PHP 8.4.24 lint PASS, Bash `--lint-only` PASS, invalid-environment guard FAIL-CLOSED before DB. GH blobs match the locally linted copies. Real MySQL or `pdo_mysql` were unavailable in this environment; therefore concurrency acceptance is **not** completed. Executing the harness requires operator provisioning of a disposable staging schema and a dedicated test DB account. This step makes no production changes; it does not remove other requirements on SMS, TOTP replay, account policy, refresh or JWT.


## 24. SMS anti-spam, verified phone and financial upper bound (2026-10-11)

New branch-only `site-integration/samzaberu-v5/src/V5SmsDispatchBudget.php` prepares ten opaque HMAC quota windows with independently configured counters for account, verified server-side phone, IP, mobile device, global SMS and global **maximum potential spend** in minor currency units. Unknown verified phone/price/current currency/quota fails closed; pricing and destination MUST be returned by trusted server ports, never client JSON. Fake values in unit tests do not establish a live SMS tariff.

`tests/V5SmsDispatchBudgetTest.php`: **111/111 OFFLINE PASS**; current five-group regression 86 + 48 + 70 + 74 + 111 = **389/389 PASS** on local PHP 8.4.24, all tests entirely synthetic. Both new GitHub blobs match the exact locally linted and tested file bytes.

No actual `V5AtomicSmsOutboxPort` is implemented. It must atomically recheck the password-proven pending challenge, OTP/MFA state, account/phone verification epoch and device binding, enforce 300s TTL/60s SMS resend cooldown/max3 sends under a lock, reserve all ten monetary and count budgets, and create one encrypted durable outbox record with a provider idempotency key. A separate dispatcher sends SMS after COMMIT. Uncertain delivery results must not silently send twice. Challenge verification and independent V5 JWT remain distinct.

Detailed guard/acceptance checkpoint: `docs/SAMZABERU_V5_SMS_SEND_GUARDS_2026-10-11.md`. Real verified-phone integration, actual tariff source, transactional outbox, provider, staging and production activation remain **PENDING**; the V5 HTTP router still returns 503 and V4 is untouched.


## 25. Durable, atomic SMS challenge and encrypted outbox candidate (2026-10-12)

In the isolated GitHub-only V5 branch, `src/V5MySqlSmsOutbox.php` now implements the previously unimplemented **generic MySQL candidate** of `V5AtomicSmsOutboxPort`: a locked existing password-proven SMS challenge, mandatory same-PDO site lock/recheck (`V5SmsLockedEligibilityPort`, **site provider still missing**), ten HMAC-count/monetary budgets committed together, and a server-side SMS-code HMAC and authenticated encrypted outbox message. The challenge uses its original TTL (no extension), device HMAC binding, current auth epoch and last verified-phone stamp. The actual worker/verified phone resolver/provider are NOT wired, and the V5 HTTP router remains HTTP 503.

Proposed schema only (no migration): `schema/PROPOSED_ev_v5_sms_challenge_outbox.sql` creates three new isolated InnoDB tables for challenges, budgets and outbox; it does not touch native `b_user` or `b_sec_user`. The encrypted payload is produced by `V5SodiumSmsMessageFactory` with XChaCha20-Poly1305 and per-delivery nonce; no plaintext OTP/phone is inserted into SMS-outbox tables. Current code does not send SMS, mint V5 JWT or verify/consume the submitted code.

`tests/V5MySqlSmsOutboxTest.php`: **123/123 local synthetic fake-PDO assertions PASS** with PHP 8.4.24, covering resend, expired/blocked challenges, last monetary-budget rollback, PDO failures and authenticated encryption. Exact source/test/DDL Git blob hashes matched the local verified files. **Real MySQL concurrency, staging deployment and actual SMS delivery remain UNTESTED.** The previous 389 assertions are earlier recorded milestones, not independently rerun today.

Critical dispatch-side guard yet to implement: read the current `current_delivery_id` and verified phone stamp, refuse superseded/expired challenges, preserve provider idempotency or flag delivery outcome `unknown` without blind repeat. Next safe step is the actual site policy+verified phone lock adapter and separate staging InnoDB concurrency acceptance, without any repeat Bitrix structure audit. Full explanation: `docs/SAMZABERU_V5_SMS_SEND_GUARDS_2026-10-11.md`.


## 26. Native Bitrix locked account / MFA absence / verified-phone recheck (2026-10-12)

`src/V5BitrixLockedSmsEligibility.php` is a concrete **staging-only** candidate for `V5SmsLockedEligibilityPort`. Reuses prior V3C lock order (`b_user → b_sec_user → b_user_phone_auth`) under the SMS-outbox transaction, validates account status and failed-login counter, rejects ANY native MFA row, and requires a unique confirmed SMS destination and matching verification event version. It calls previously implemented strict login and non-mandatory MFA policy interfaces and requires separate locked auth-epoch proof. No native secret or user session is accessed.

A new `V5BitrixVerifiedSmsPhone` preflight reads only `b_user_phone_auth` and cannot use a caller-submitted number. A strict E.164 normalizer fails closed for unknown production phone formatting. **88/88 offline unit assertions passed** on PHP 8.4.24 with fake PDO, including invalid/stale phone, native 2FA downgrade, inactive accounts and transaction/SQL faults. Source and test blobs match local verified files; the test now constructs the actual existing `V5SmsQueueReservation` shape (10 windows).

**Still NOT implemented:** authoritative event-version provider for confirmed phone; native security/auth epoch issued at password proof and invalidated on MFA/password/logout/block events; proof that Bitrix MFA enrollment shares the required locking/epoch contract; real configured lockout/per-user SMS policy providers; live PDO/MySQL concurrent acceptance. The old V3C proposed `ev_samzaberu_auth_state` table is not deployed. Do not replace these with a hash of the current phone or password. No production changes, API V5 remains 503.


## 27. Native V3C-compatible security event epochs and phone verification (2026-10-12)

`site-integration/samzaberu-v5/src/V5SecurityEventVersions.php` now supplies the **staging-only** `V5SmsPhoneVerificationVersionPort` and `V5SmsLockedAuthEpochPort` needed by the locked SMS eligibility adapter, plus `V5SecurityEventVersionWriter` for security/phone events. It reuses the **uninstalled** V3C `ev_samzaberu_auth_state.AUTH_EPOCH` (positive integer), with proposal `schema/PROPOSED_ev_samzaberu_auth_state_v5.sql` adding `PHONE_EVENT_VERSION` and HMAC-only `PHONE_BINDING_HMAC`. **No DDL ran and no Bitrix security events were modified.**

The V5 challenge receives a 64-character HMAC auth token derived from USER_ID, authoritative AUTH_EPOCH and the current stored password hash, only while the real account is locked after first-factor proof. On recheck a changed password or incremented security epoch denies. The SMS phone stamp is derived from a durable *verification event* counter, the verified E.164 and a keyed binding; verifying the same number again MUST change the counter and invalidate the previous SMS challenge. A missing state row, unknown phone-event version, unconfirmed phone or mismatched binding denies. No raw phone in auth-state table.

Strict precondition `V5NativeSecurityHookCoveragePort` is **not implemented** for the site: its trusted provider must attest native Bitrix password/MFA activation/reset/recovery/phone verification/logout/block event coverage and shared b_user mutex. The event writer itself only performs CAS increments **inside an existing caller-owned PDO transaction** and assumes native state changes were made in that same transaction. Concurrent MFA enrollment must acquire the same b_user user lock; missing `b_sec_user` row locks alone do not guarantee it. Without this atomic hook coverage, V5 authentication MUST remain disabled.

Offline tests: `V5SecurityEventVersionsTest.php` **84/84**, existing `V5BitrixLockedSmsEligibilityTest.php` **88/88**, total **172/172 PASS** with fake PDO/PHP 8.4.24. Three new committed Git blobs were compared to tested local files. This is not live MySQL or Bitrix integration. Next gate is native hook transaction compatibility and targeted staging concurrency, not rediscovery of `b_user` or OTP structure.



## 28. Native Bitrix event atomicity compatibility gate — 2026-10-12

Previously planned native V5 `auth_epoch` and verified-phone event writers require an atomic native Bitrix transaction. Already saved source excerpts show `Otp::save()` calls `UserTable::add()`; `Otp::delete()` calls `UserTable::delete()`; a separate `OtpEvents::onRecheckDeactivate()` agent calls `UserTable::update()`; `CSecurityUser::onAfterUserUpdate()` in the audited excerpt only invalidates OTP cache. These are verified paths but **not proof of shared transaction or shared b_user row mutex**, and a post-commit callback cannot ensure atomic epoch rollback. Do NOT wire speculative native hooks or make V5 available.

Added a strict **GitHub-only** `V5NativeMutationCoverage.php` release gate requiring all ten groups of native security mutation, each with source fingerprint, same PDO, b_user lock, same-tx epoch increment, same-tx rollback and real staging race acceptance. `V5TrustedInstalledNativeCoveragePort` remains a missing privileged integration dependency; HTTP-supplied proof or a simple enabled boolean is forbidden. Tests `V5NativeMutationCoverageTest.php`: **60/60 PASS** in local PHP 8.4.24 using fake PDO and synthetic positive proof. This does NOT mean any site-native hook passed.

Detailed matrix and source-backed compatibility findings: `docs/SAMZABERU_V5_NATIVE_MUTATION_COVERAGE_2026-10-12.md`. Before activation, establish the transaction/connection boundary of every native writer and test concurrency against the SMS outbox and V3C epoch. Production/V4 unaffected; V5 still HTTP 503.
