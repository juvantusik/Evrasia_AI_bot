# Anti-Fraud mobile session revoke — production verification 2026-09-15

## Status

**Production / E2E accepted.**

Anti-Fraud manual block now revokes an already issued mobile access JWT immediately. Unblocking the Bitrix account does not resurrect the old JWT; the user must obtain a fresh JWT after unblock.

## Production Bitrix host

- host: `evrasia` / `evrasia.spb.ru`
- local IP: `192.168.103.141`
- Bitrix document root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`

## Production files and accepted SHA-256

### `local/php_interface/lib/JwtAuthorization.php`

- before: `70ee4e8f41d5a78706a6217b2aba5a45f9f56336154b451d81ff58bf1701fbd2`
- accepted production SHA: `0e539789a6322b2c209b9e8e937bb04e43344aa9092bcac5e627136c30fbc7d9`

### `local/php_interface/lib/Services/AntiFraudBlockService.php`

- before: `2c4fcd1394f94dfa03605987e1874d9aeea6a30dfec04cda2eaf2c372655e9b8`
- accepted production SHA: `d22c14b42644500d4d2fb2566ca6909a149dc2ba832f268eb06d5c0a589e23c4`

Deployment backup:

`/home/site_evrasia/web/evrasia.spb.ru/backups/anti-fraud-mobile-jwt-revoke/20260915-162656`

## Root cause

The mobile access token was a stateless JWT. `JwtAuthorization::getAuthUserId()` / `checkAuth()` verified signature/expiry and returned `user_id`, but did not consult server-side session state. Deleting rows from `jwt_tokens` and `access_token` therefore prevented refresh / removed stored tokens but did not invalidate an already issued access JWT until its normal expiry.

## Implemented contract

Anti-Fraud block performs the existing Bitrix/session revoke and additionally writes a Redis revoke timestamp:

`evrasia:jwt:revoked_after:<BITRIX_USER_ID>`

TTL: **86700 seconds** (24 hours + 300 seconds).

`JwtAuthorization` compares JWT `iat` with the Redis marker:

- `iat <= revoked_after` -> access JWT is rejected;
- `iat > revoked_after` -> access JWT may proceed through normal validation.

The Redis marker intentionally survives account unblock. Therefore an old pre-block JWT cannot become valid again after unblock. A newly issued JWT after unblock has a later `iat` and is accepted.

Redis outage handling in normal JWT validation is fail-open for the Redis-specific check, so Redis failure does not globally break the mobile API. Anti-Fraud session revoke, however, reports failure if the revoke marker cannot be written, because immediate logout cannot then be guaranteed.

## Refresh-token behavior

Refresh tokens remain protected by the existing DB-backed refresh flow. Anti-Fraud block deletes the relevant token rows, so an old refresh token cannot be used to mint a new access JWT after block.

## Trusted Device invariant

`ev_trusted_devices` / Trusted Device state is **not modified** by block, unblock, mobile access-token revoke, or the acceptance test.

## Production E2E acceptance

Test account: Bitrix user ID `880339`.

Strict authenticated probe: `GET /api/v4/orders/`.

Why this endpoint was chosen: it calls `JwtAuthorization::checkAuth()`, then `$USER->Authorize($userId)`, and stops on failed authorization. `/api/v4/cart/id` was rejected as an acceptance probe because it can still return an anonymous Fuser/cart id when JWT auth fails.

Verified sequence:

1. pre-state: `ACTIVE=Y`, `BLOCKED=N`, no ` - блок ИТ` suffix;
2. fresh access JWT -> `/api/v4/orders/` returns authenticated success (`HTTP 200`);
3. Anti-Fraud block -> `HTTP 200`, `RESULT=blocked`, `SESSION_REVOKED=YES`;
4. factual Bitrix state -> `ACTIVE=N`, `BLOCKED=Y`, IT block suffix present;
5. Redis revoke marker exists and TTL refreshed to `86700`;
6. exact same pre-block JWT -> `HTTP 401`;
7. Anti-Fraud unblock -> `RESULT=unblocked`;
8. exact same old JWT after unblock -> still `HTTP 401`;
9. fresh JWT issued after unblock -> authenticated success (`HTTP 200`);
10. final state -> `ACTIVE=Y`, `BLOCKED=N`, IT block suffix removed;
11. acceptance summary -> `PASS_COUNT=16`, `FAIL_COUNT=0`, `E2E_COMPLETE=YES`, `FINAL_STATUS=PASS`, `FINAL_RC=0`.

Real mobile-app verification also passed: after the Anti-Fraud block, the already logged-in user was actually logged out of the mobile application.

## Было -> Стало -> Причина

**Было:** Anti-Fraud changed Bitrix block state and removed stored JWT/access-token rows, but an already issued mobile access JWT remained usable until expiry.

**Стало:** Anti-Fraud writes a per-user Redis `revoked_after` timestamp; central JWT validation rejects every access JWT issued at or before that timestamp. The marker survives unblock, while a fresh post-unblock JWT works normally.

**Причина:** blocking a fraud account must terminate an already authenticated mobile session immediately and must not allow the old session to revive merely because the account was later unblocked.

## Follow-up

The customer-facing site button **«Выйти со всех устройств»** must be inspected separately. It should use the same session-revoke semantics (including mobile JWT revoke) rather than only Bitrix/web logout. Do not change it until its current production implementation is read and verified.
