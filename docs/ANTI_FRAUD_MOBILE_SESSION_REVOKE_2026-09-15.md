# Evrasia session revocation — production accepted 2026-09-15

## Status

**PRODUCTION / E2E / REAL CLIENT ACCEPTED.**

This document is the authoritative technical record for the 2026-09-15 Bitrix/mobile session-revocation work. It covers both:

1. Anti-Fraud block/unblock forced logout;
2. customer-facing site action **«Выйти со всех устройств»**.

Both flows now invalidate already-issued mobile access JWTs immediately. Trusted Device identity is intentionally preserved.

## Production perimeter

Bitrix/site host:

- short host: `evrasia`
- site: `evrasia.spb.ru` / alias `evrasia.rest`
- local IP: `192.168.103.141`
- document root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`

Relevant production files:

- `local/php_interface/lib/JwtAuthorization.php`
- `local/php_interface/lib/Services/AntiFraudBlockService.php`
- `local/php_interface/logout.php`
- `local/php_interface/lib/orm/JwtAccessTokenTable.php`
- `local/php_interface/lib/orm/AccessTokenTable.php`
- account UI callers:
  - `local/templates/eurasia/components/eurasia/account/main/template.php`
  - `local/templates/eurasia/js/account.js`
  - `local/templates/eurasia_qr_order/components/eurasia/account/main/template.php`
  - `local/templates/eurasia_qr_order/js/account.js`

## Accepted production SHA-256

### `JwtAuthorization.php`

- original pre-revoke baseline: `70ee4e8f41d5a78706a6217b2aba5a45f9f56336154b451d81ff58bf1701fbd2`
- current accepted production SHA: `0e539789a6322b2c209b9e8e937bb04e43344aa9092bcac5e627136c30fbc7d9`

### `AntiFraudBlockService.php`

- original pre-session-revoke baseline: `2c4fcd1394f94dfa03605987e1874d9aeea6a30dfec04cda2eaf2c372655e9b8`
- after first mobile-revoke deployment: `d22c14b42644500d4d2fb2566ca6909a149dc2ba832f268eb06d5c0a589e23c4`
- current accepted production SHA after shared logout-all reuse: `aaec0e8b918e173e39db9e764a976da256ed410cc200dcef0af65c5d9a0d5702`

### `logout.php`

- before mobile revoke: `95c7c79f5da1187cef8dcab56e411d7e7403f220588d501bf0bfcba29cb74dfb`
- current accepted production SHA: `e4933de270e85b10dd189628ccae9c0a8a0a83f8a403edd01760cd90eb392471`

Deployment backups:

- first Anti-Fraud mobile revoke: `/home/site_evrasia/web/evrasia.spb.ru/backups/anti-fraud-mobile-jwt-revoke/20260915-162656`
- shared global-logout mobile revoke: `/home/site_evrasia/web/evrasia.spb.ru/backups/global-logout-mobile-revoke/20260915-164933`

## Root cause found

Mobile access tokens are Firebase HS256 JWTs. The access JWT is self-contained and was not stored as a revocable server-side session record.

Before the fix, `JwtAuthorization::getAuthUserId()` and `JwtAuthorization::checkAuth()` decoded the JWT, verified normal JWT validity, and returned `user_id` without consulting:

- `jwt_tokens`;
- `access_token`;
- Bitrix `ACTIVE/BLOCKED` state;
- any server-side access-token revocation generation/timestamp.

Therefore deleting token rows during block/logout prevented refresh or removed legacy stored tokens but **did not invalidate an already-issued access JWT**. It remained usable until its normal expiry, up to 24 hours.

## Token/storage structures discovered

### Access JWT

`JwtAuthorization::createAccessToken()` creates a stateless access JWT containing at least:

- `subject=access`;
- `iat`;
- `nbf`;
- `exp`;
- `user_id`.

Access-token lifetime is 86400 seconds.

The current access JWT is not inserted into `access_token` by this flow. Therefore `access_token` is not authoritative for current mobile JWT validity.

### Refresh JWT / `jwt_tokens`

Refresh lifetime is 30 days.

`createRefreshToken()` stores a SHA-256 hash of the full refresh JWT in `jwt_tokens`.

`makeRefresh()`:

1. decodes the refresh JWT;
2. hashes the raw refresh token;
3. looks up the exact hash in `JwtAccessTokenTable` / `jwt_tokens`;
4. requires it not blocked/refreshed;
5. marks the old refresh as refreshed;
6. issues new tokens.

Deleting the user's `jwt_tokens` rows therefore revokes refresh capability.

### `access_token`

`access_token` belongs to the older authorization/token path. It is still cleared during all-session revoke for compatibility, but it is not the validity source for the current stateless mobile access JWT.

### Trusted Device

`ev_trusted_devices` is device identity/trust state, not current authentication-session state.

**Invariant:** session logout, Anti-Fraud block/unblock and mobile JWT revocation must not delete or mutate Trusted Device rows merely to terminate authentication.

## Redis access-JWT revoke contract

Key:

`evrasia:jwt:revoked_after:<BITRIX_USER_ID>`

Value: Unix timestamp of revocation.

TTL: **86700 seconds** = access-JWT lifetime 86400 + 300 seconds safety margin.

Central JWT validation compares decoded `iat` with the marker:

- `iat <= revoked_after` -> reject JWT;
- `iat > revoked_after` -> token may continue through normal validation.

The marker is per runtime Bitrix USER_ID. There is no hardcoded test USER_ID in Anti-Fraud session revoke.

The marker intentionally survives unblock/logout. Old JWTs remain dead; a later fresh login receives a newer `iat` and works normally.

### Second-resolution nuance

JWT `iat` and revoke marker use second resolution. A token issued in exactly the same second as the marker may also be rejected. Controlled tests therefore wait at least 2 seconds before proving a fresh post-revoke JWT.

## Redis failure semantics

Normal JWT validation is **fail-open only for the Redis marker check**: Redis failure must not globally take down all mobile API authentication.

Anti-Fraud forced-session revoke is stricter: if the marker cannot be written, the block may remain factually applied but the operation must not claim guaranteed immediate full session logout.

The site global-logout endpoint now also returns `success=false` if the mobile revoke marker cannot be written, rather than falsely reporting successful logout from all devices.

## Anti-Fraud generic block/session-revoke flow

`AntiFraudBlockService` normalizes requested USER_ID values, loads factual Bitrix state and loops over runtime `$userId` values. Session revoke is invoked with that runtime ID.

There is **no hardcoded USER_ID 880339** in production Anti-Fraud logic. `880339` was only the controlled acceptance fixture.

For each relevant blocked user, `revokeUserSessions(int $userId)` performs:

1. `Bitrix\Main\UserAuthActionTable::addLogoutAction($userId)`;
2. delete `jwt_tokens` rows where `UF_USER_ID=$userId`;
3. delete `access_token` rows where `UF_USER_ID=$userId`;
4. verify both token tables have zero rows for that user;
5. call `revokeMobileAccessTokens($userId)`;
6. write `evrasia:jwt:revoked_after:<userId>` with TTL 86700.

This applies to **every account blocked through Anti-Fraud**, not only the test account.

Bitrix block contract remains:

- block: `ACTIVE=N`, `BLOCKED=Y`, append ` - блок ИТ`, write Anti-Fraud block reason;
- unblock: restore active/unblocked state and remove IT suffix;
- historical reason is retained;
- unblock does not remove the Redis marker.

## Shared mobile revoke primitive

After the site global-logout work, `AntiFraudBlockService::revokeMobileAccessTokens(int $userId)` is a reusable **public static** method.

This intentionally avoids a second independent Redis implementation. Anti-Fraud and the customer-facing global logout use the same mobile access-JWT revoke primitive.

Do not duplicate the Redis marker algorithm elsewhere unless architecture is deliberately changed.

## Site «Выйти со всех устройств» — exact production structure

UI button exists in both account templates and is wired by both account JS variants to:

`POST /local/php_interface/logout.php`

Before the 2026-09-15 fix, `logout.php`:

1. required an authorized Bitrix `$USER`;
2. read current `(int)$USER->GetID()`;
3. called `UserAuthActionTable::addLogoutAction($userId)`;
4. deleted that user's `jwt_tokens`;
5. deleted that user's `access_token`;
6. returned success.

Problem: an already-issued stateless mobile access JWT survived.

Current production `logout.php` additionally loads `AntiFraudBlockService` and calls:

`AntiFraudBlockService::revokeMobileAccessTokens($userId)`

If mobile revoke fails, it returns `success=false` with a generic mobile-session-revoke error instead of falsely claiming complete all-device logout.

The site action **does not block the account**. After logout-all the account remains active/unblocked and the user may authenticate again. Old JWTs remain revoked; fresh later JWTs work.

## Strict authentication probe discovered

Canonical acceptance probe:

`GET /api/v4/orders/`

Important: trailing slash is required. `/api/v4/orders` redirects with HTTP 301.

`/api/v4/orders/` is strict because it calls `JwtAuthorization::checkAuth()`, then `$USER->Authorize($userId)`, and stops on failed authorization.

Do **not** use `/api/v4/cart/id` as a strict JWT acceptance probe: it can still produce an anonymous Fuser/cart ID after JWT auth fails, creating a false conclusion.

Other observed auth callers include:

- `/api/v4/user_info/` -> `JwtAuthorization::checkAuth()`;
- `/api/v4/notifications/` -> `JwtAuthorization::checkAuth()` and Bitrix authorize.

## Anti-Fraud production E2E acceptance

Controlled fixture: Bitrix USER_ID `880339`.

Verified sequence:

1. initial `ACTIVE=Y`, `BLOCKED=N`, no IT suffix;
2. fresh pre-block JWT authenticates on `/api/v4/orders/` -> HTTP 200;
3. Anti-Fraud block -> HTTP 200, `RESULT=blocked`, `SESSION_REVOKED=YES`;
4. factual state -> `ACTIVE=N`, `BLOCKED=Y`, suffix present;
5. Redis marker refreshed with TTL 86700;
6. exact old JWT -> HTTP 401 immediately after block;
7. unblock -> `RESULT=unblocked`;
8. exact old JWT after unblock -> still HTTP 401;
9. fresh JWT after unblock -> HTTP 200;
10. final account state restored `ACTIVE=Y`, `BLOCKED=N`, no suffix;
11. Trusted Device untouched;
12. `PASS_COUNT=16`, `FAIL_COUNT=0`, `E2E_COMPLETE=YES`, `FINAL_STATUS=PASS`, `FINAL_RC=0`.

Real mobile-client verification also passed: the already logged-in mobile application was actually forced out after Anti-Fraud block.

## Global logout production deployment acceptance

Deployment changed only:

- `AntiFraudBlockService.php` — exposed the already-proven mobile revoke primitive for reuse;
- `logout.php` — calls the shared primitive.

It did **not** modify:

- `JwtAuthorization.php`;
- DB/schema;
- Trusted Device;
- user/account state during deployment;
- Redis during deployment.

Deployment result:

- staged PHP lint passed;
- production PHP lint passed;
- shared helper exactly once;
- logout mobile revoke call exactly once;
- service require exactly once;
- existing Redis revoke implementation preserved;
- Trusted Device reference in logout = 0;
- `PASS_COUNT=27`;
- `FAIL_COUNT=0`;
- `CUTOVER_STARTED=YES`;
- `ROLLBACK_ATTEMPTED=NO`;
- `FINAL_STATUS=PASS`;
- `FINAL_RC=0`.

Real user acceptance then passed: while authenticated on the website and mobile application under the same account, pressing **«Выйти со всех устройств»** logged the user out of the mobile application as intended.

Therefore global logout is **PRODUCTION / REAL CLIENT ACCEPTED**.

## Security findings / operational rules

During diagnostics, legacy source contained hardcoded/commented credentials. Do not print, quote or propagate those values into documentation, chat output or diagnostic logs.

Diagnostics around auth code must avoid broad source dumps when secret-bearing files are possible. Prefer narrow structural checks and explicit `SECRETS_PRINTED=NO`.

A shell/PHP return code 0 alone is not sufficient evidence when Bitrix can render an error page. Controlled scripts should require explicit completion markers such as `DIAGNOSTIC_COMPLETE=YES` / `E2E_COMPLETE=YES` plus factual postchecks.

## Было -> Стало -> Причина

### Anti-Fraud mobile session

**Было:** Bitrix block state changed and stored token rows were deleted, but an already-issued stateless mobile access JWT could remain valid until expiry.

**Стало:** per-user Redis `revoked_after` is written during session revoke and central JWT validation rejects every access JWT with `iat <= revoked_after`. The marker survives unblock.

**Причина:** a blocked account must lose an already-authenticated mobile session immediately, and the old session must not revive after unblock.

### Site «Выйти со всех устройств»

**Было:** the site revoked Bitrix/web sessions and DB-backed refresh/legacy tokens, but did not revoke already-issued stateless mobile access JWTs.

**Стало:** the site global logout invokes the same shared per-user mobile revoke primitive as Anti-Fraud. Old mobile JWTs die immediately; account state is not blocked; a fresh later login works.

**Причина:** the user-facing promise «Выйти со всех устройств» must include the mobile application, not only website/DB-backed sessions.

## Final invariant

For future work, keep these concepts separate:

- **Bitrix block state** = whether the account may operate;
- **Bitrix logout action** = web/session invalidation;
- **`jwt_tokens`** = DB-backed refresh-token validity;
- **`access_token`** = legacy token storage, still cleared for compatibility;
- **Redis `revoked_after`** = immediate revocation boundary for already-issued stateless mobile access JWTs;
- **`ev_trusted_devices`** = device trust identity, deliberately preserved across session logout/revoke.

Do not solve session invalidation by deleting Trusted Device identity.