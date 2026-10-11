# SamZaberu V5 — isolated site integration prototype

**Status:** offline-tested decision policy only. **No V5 HTTP route, Bitrix integration, JWT issuance, SMS send, database migration or production deployment exists yet.** This directory is deliberately independent of the running Evrasia bot and website.

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
