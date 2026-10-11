# SamZaberu mobile — API V5 authorization contract

> Status: **DESIGN DRAFT / ISOLATED GITHUB BRANCH / NOT IMPLEMENTED / NOT DEPLOYED**
>
> Date: **2026-10-11**
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
