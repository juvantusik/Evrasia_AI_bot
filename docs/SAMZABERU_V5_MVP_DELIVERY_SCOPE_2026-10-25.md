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

## 2026-10-25 — V5 profile functional test loader fix after repeated FAIL

Second operator run (runner `e4187439`) again produced `PASS: PHP syntax`, `FAIL: V5 profile HTTP functional contract`, `FINAL_RC=2`, **without sanitized PHP error**. Inspection of the runner revealed it copies the older private candidate sources and overlays new V5 profile files but omitted the new **mandatory** `src/V5ProductionNativeTransactionPreflight.php` dependency of `V5ProductionNativeRequestBoundaryGuard.php`. The test previously included source files **before** installing its sanitized exception/shutdown handlers, making a missing dependency error invisible. This is a runner/source-overlay defect, NOT evidence of an unsafe real profile or production outage.

Fixed by explicitly fetching/pinning blob `9e295478a589c3e4ebed29dfa1ac7142c16b77e2` for `V5ProductionNativeTransactionPreflight.php` alongside the guard, and registering sanitized test exception diagnostics BEFORE all requires. Last fixed test `b7e426c1aa2d63d24c018fdca5e98ecac7fd9b72` blob `3b958607a8281a666faa31b0d4ea785178a774fb`; corrected pinned launcher `25d18db37b65d763bdb0198b4c3fe9c8445b5b8e` blob `c798f4ece41a276c10b8c57c84f9288a398f8d65`. **Operator rerun PENDING**; do not report PASS. No new security audit, no Bitrix bootstrap, DB or production writes; V4/V5 AUTH OFF unchanged.

## 2026-10-25 — Profile accepted, next independent cart adapter implemented

Operator's actual output for fixed profile runner: `V5_INDEPENDENT_PROFILE_ROUTE=PASS`, `PROFILE_CONTRACT_ASSERTIONS=12`, `INDEPENDENT_PROFILE_MVP_TEST=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`. **This is offline HTTP-contract acceptance, NOT production profile with real V5 auth**. V4 unchanged, V5 AUTH OFF.

Feature-only new `src/V5BitrixLockedCartReader.php` implements existing `V5LockedCartReaderPort` using installed platform D7 `Bitrix\\Sale\\Basket::loadItemsForFUser` and current `Bitrix\\Main\\Context::getCurrent()->getSite()`, not V4 controllers. Requires existing V5 session store's locked authenticated Bitrix USER_ID and exact `b_sale_fuser` row `SELECT ID,USER_ID ... WHERE USER_ID=? LIMIT 2 FOR UPDATE`, already admitted by existing V5 Bitrix bridge. Empty FUSER returns an empty cart without generating one. No Fuser::getId/getIdByUserId, no cookies, no basket save. D7 basket returns native stored item prices, line prices, total price, base price and quantities; do not claim that V4's custom discounts/gifts/delivery pricing is identical until live parity tests. Both `V5MySqlPublicComposition` and `V5MySqlPilotComposition` now wire this adapter by default only under actual PHP-FPM, preserving old CLI unit fixtures; native V5 JWT/epoch/session admission remains mandatory before cart read. No V4 runtime imports.

New `tests/V5IndependentNativeCartReadTest.php` exercises native adapter with in-memory Bitrix D7/PDO test doubles only, including owner mismatch, duplicate FUSER, empty cart, live native totals; **no account creation, DB, SMS**. Has early safe PHP failure diagnostics. Last pinned single clear-screen launcher `tests/diagnostics/run_v5_native_cart_mvp_readonly.sh` commit `0bd4fd9be26472525d5cf37c963ab07090e35440`, blob `218c47908c509fc1c09e2a8220905db05c1e646b`. **Test operator stdout not yet received**, don't claim PASS. Site's V4 and installed V5 source unaffected; V5 AUTH OFF. Next: finish V5 independent orders/profile parity and real app enablement, not new security audits.

## 2026-10-25 — Orders list MVP added (V5 independent, feature-only)

Operator actual cart stdout: `V5_INDEPENDENT_NATIVE_CART_READER=PASS`, `NATIVE_CART_ASSERTIONS=16`, `V5_NATIVE_CART_MVP=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`. MOCK-only D7 contract, no real user/DB and V5 AUTH OFF. Do not repeat the accepted profile/cart tests.

**New API V5 business functionality** `GET /api/v5/orders` added, independently of V4:
- `V5RouteMap`, `V5ParallelHttpEntry`, `V5HttpTransportBoundary`, `V5ProductionNativeRequestBoundaryGuard` support exact protected `GET /orders` with V5 JWT in Authorization header, no query/body, no V4 JWT.
- `V5AuthHttpHandler::handleProtectedOrdersGet` and `V5OrdersHttpReaderPort` return `{"orders":[...]}` with list shape, 503 without native provider.
- `V5MySqlStrongSessionStore::readOrdersForAccess` reuses existing V5 bearer / user / session / AUTH_EPOCH locked scope. No separate authentication mechanism.
- `V5BitrixLockedOrdersReader` uses native read-only `Bitrix\Sale\Internals\OrderTable::getList` with server-derived `USER_ID`, last 50 by ID DESC; returns ID, account number, DATE_INSERT ISO8601, STATUS_ID, PRICE, CURRENCY, PAID, CANCELED; doesn't instantiate V4 controllers or use V4 HTTP routes.
- `V5MySqlOrdersRead` is included in existing public/pilot compositions (the private native factory and production AUTH are not activated).
- New offline `tests/V5IndependentOrdersMvpTest.php` tests native D7 read with mock rows (not real accounts), empty list/foreign owner, no-bearer denial, exact HTTP route, missing provider 503. **Actual test result PENDING.** No new security research.

Single pinned one-block root@evrasia launcher `site-integration/samzaberu-v5/tests/diagnostics/run_v5_orders_mvp_readonly.sh`, commit `7ed3844583fede422522ab596c88ec480a1ef859`, blob `91edb7da92b56d661c536e636fac6d1aac7c5fb9`. Clears screen+scrollback at start and operator wrapper must clear before source download and before execution. Checks pinned production V4/V5 OFF and old candidate, assembles temp feature source, runs PHP syntax + one orders D7/HTTP test, cleans temp source and preserves interactive root terminal. No DB, customer records, or production writes.

**Status**: V4 continues independently; API V5 AUTH OFF on production. Orders history feature built but not yet Bitrix native real E2E; detail/order-placement and cancellation are separately unimplemented. Next functional implementation after result: order details and line items, then independent order placement using Bitrix Sale D7 and relevant V4 business rules as reference only.

## 2026-10-25 — Order history accepted; own V5 order details implemented

Last real operator `evrasia` stdout: `V5_INDEPENDENT_ORDERS_MVP=PASS`, `ORDERS_MVP_ASSERTIONS=18`, `V5_ORDERS_MVP_TEST=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`. Offline D7 double only, no real customer data. V4 intact, V5 AUTH OFF.

Next functionality committed in feature: `POST /api/v5/orders/detail` with JSON `{"access_token":"<V5 access JWT>","order_id":401}` (route is static, no query, no client USER_ID). `V5BitrixLockedOrderDetailReader` queries native `OrderTable::getList` matching BOTH order ID and V5-authenticated USER_ID and reads `BasketTable::getList` scoped to that order ID. Returns order number/status/date/price/currency/payment/cancellation and up to 200 line items (Bitrix stored price, base price, quantity, computed line total). No V4 controllers, V4 tokens, cookies or order mutations. `V5MySqlStrongSessionStore::readOrderDetailForAccess` reuses existing signed V5 JWT/current session/epoch/Bitrix user-lock transaction. New `V5MySqlOrderDetailRead` wired into both V5 compositions and `V5AuthHttpHandler`. Route and existing native ingress updated. **Not yet live**, profile/cart/order-list and detail still feature-only.

New dedicated `tests/V5IndependentOrderDetailMvpTest.php` uses Bitrix D7 and PDO *test doubles* only (no fake registered guest/account). Checks native owner-bound order/items projection, foreign order rejection before basket read, invalid ID/transaction, own V5 HTTP route and malformed request refusal. Source-only test **operator result pending**. Single clear-screen runner `site-integration/samzaberu-v5/tests/diagnostics/run_v5_order_detail_mvp_readonly.sh`, commit `08f221b860f43284adeffce49fd85befbd470bef`, exact blob SHA `73bf58f3409f1b3999c8447cc3cb326e745a4f2e`. It verifies V4 current router hash and V5 OFF, checks Git blobs, lints PHP, runs only new mock-only business test, cleans temporary root-owned source overlay. Never deploys, connects DB, modifies V4/V5 or sends SMS. Do NOT report PASS until operator result.

MVP next after accepted details: independent order placement via Bitrix Sale D7 (existing cart/business policy as reference), and real V5 native integration. No new security audit/invented V4 bridge.
