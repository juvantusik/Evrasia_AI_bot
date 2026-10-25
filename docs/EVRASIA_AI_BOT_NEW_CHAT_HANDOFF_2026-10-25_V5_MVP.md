# Evrasia AI Bot / СамЗаберу API V5 — HANDOFF 25.10.2026

Purpose: authoritative continuation checkpoint for the next chat. This is ONE ongoing Project. Do not redo completed tests, re-investigate old architecture, or replace a working business implementation with another security abstraction.

## 1. User-approved decision / architectural invariants

- IMMEDIATE PRIORITY: ship a FUNCTIONING, independent API V5 MVP for the СамЗаберу mobile app. User explicitly stopped speculative repeated security audits and additional hardening. After a real MVP works, review further hardening separately; never remove basic authentication/authorization or access controls.
- V5 MUST be independent of API V4. New V5 source may consult/copy/adapt V4 algorithms as development reference, but at runtime must NOT import local/php_interface/Controllers/V4, V4 JWT/session, or invoke /api/v4 HTTP endpoints. Both versions coexist until the app migrates, after which V4 should be removable without affecting V5.
- Use native Bitrix D7 sale, users, orders, catalog, existing company-specific business rules; do not invent alternate prices, discounts or gift policy. Existing V4 business logic is REFERENCE ONLY when native D7 alone misses customization. Preserve existing UI/API agreements and terminology.
- Reuse already developed V5 JWT/session/trust/TOTP/SMS challenge logic, minimal server-side validation, SMS anti-abuse; no synthetic real-site users.
- Trusted device overrides repeated TOTP/SMS when its CURRENT strong trust proof is valid; otherwise TOTP if enrolled, else agreed fallback. Website MFA pilot was accepted, user approved all-user site rollout, but do not confuse approval with verified live production enablement. Existing newer V5 email-first + manual SMS recovery design must be checked against app contract; don't silently change policy or send SMS.
- Public client host https://evrasia.rest (not evrasia.spb.ru); direct public IPv4 217.197.238.66, internal address 192.168.103.141 only for internal diagnosis. No secrets printed.
- Production is critical; V4 stays working. API V5 AUTH MUST remain OFF until native provider wiring and real-user test are accepted explicitly. No production edits merely to satisfy offline tests.
- Avoid unrequested investigation/security loops. Use minimum code changes on current GitHub HEAD and advance business functionality.

## 2. Hosts, source of truth, locations

Repo: juvantusik/Evrasia_AI_bot
Active feature branch: feature/v5-production-native-preflight-20261025
Main site host (root terminal): evrasia; Bitrix document root /home/site_evrasia/web/evrasia.spb.ru/public_html
MariaDB Bitrix database: eurasia_new
Production V4 router: /home/site_evrasia/web/evrasia.spb.ru/public_html/api/v4/router.php
Latest operator-pinned V4 router SHA256: 5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d
Production V5 router SHA256 in recent guards: f502ae4718a3193d2765d30b75dff638a684cb75078534fecfd3524dce3b94d8
V5 private OFF config path:
 /home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/site-config.json
Expected mode: {"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}
Existing readonly V5 candidate:
 /home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/candidates/6fa8773746e9a40e22008d67636c896eefeec3ed
Private root-only snapshot created successfully from REAL existing V4 code:
 /root/ev-v5-mvp.2eCDIApS
Snapshot verification PASS, 11 controllers, SOURCE_SHA256 present. This is a REFERENCE / development snapshot, not a V5 runtime dependency.

Source priority: production runtime > current branch GitHub > staging > current docs > user-confirmed decisions > old chat. The exact hashes above are from recent production checks, not universal eternal pins; when a real deploy is contemplated verify current runtime.

## 3. Real confirmed operator results — do not repeat

- V4 snapshot: V5_MVP_SOURCE_READY=PASS; V4_CONTROLLER_FILES=11; production V4 router hash as above; FINAL_RC=0, WRAPPER_RC=0. Snapshot contains V4 router/controllers and selected libraries. No production mutations.
- Earlier V5 request factory transport: V5_FACTORY_REQUEST_SCOPE_TEST=PASS, FACTORY_SCOPE_TEST=PASS; validated POST, anonymous cart denied before factory, protected GET no body read; FINAL_RC=0, WRAPPER_RC=0.
- Native first-factor HTTP parameter extraction: NATIVE_FIRST_FACTOR_REQUEST_SCOPE=PASS, V5_SCOPED_FIRST_FACTOR_SHAPE=PASS, SCOPED_ASSERTIONS=20; no DB connection, FINAL_RC=0, WRAPPER_RC=0.
- Independent V5 GET /api/v5/profile: initial twice FAIL from missing test overlay dependency; fixed; REAL SUCCESS: V5_INDEPENDENT_PROFILE_ROUTE=PASS, PROFILE_CONTRACT_ASSERTIONS=12, INDEPENDENT_PROFILE_MVP_TEST=PASS, FINAL_RC=0, WRAPPER_RC=0. Read-only offline mock/HTTP contract, NOT live site E2E.
- Independent V5 GET /api/v5/cart: V5_INDEPENDENT_NATIVE_CART_READER=PASS, NATIVE_CART_ASSERTIONS=16, V5_NATIVE_CART_MVP=PASS, FINAL_RC=0, WRAPPER_RC=0. Bitrix Sale D7 is mocked in this test; no real DB/user.
- Independent V5 GET /api/v5/orders (latest 50): V5_INDEPENDENT_ORDERS_MVP=PASS, ORDERS_MVP_ASSERTIONS=18, V5_ORDERS_MVP_TEST=PASS, FINAL_RC=0, WRAPPER_RC=0. Offline Bitrix D7 mock only.
- All tests above were CODE/OFFLINE against pinned GitHub sources, with V4 untouched, no DB connection, no synthetic site accounts, no SMS, no production code write, V5 AUTH OFF. PASS does NOT mean the protected endpoints are live or the actual V5 login is ready.
- Existing public V5 health/legal endpoints were previously documented as live; protected auth/profile/cart/orders were not enabled. Do not confuse them.
- Site MFA website pilot earlier user-confirmed working; user approved wider rollout. Do not assert current all-user live status without a runtime check.

## 4. The actual latest INCOMPLETE step — order detail

New V5 route: POST /api/v5/orders/detail
JSON request: {"access_token":"REAL_V5_ACCESS_TOKEN","order_id":401}
Response on success: {"order":{"id":401,"number":"...","date":"ISO8601","status":"...","price":0,"currency":"RUB","paid":false,"canceled":false,"items":[...]}}
Files and design:
- src/V5BitrixLockedOrderDetailReader.php: native Bitrix Sale D7 OrderTable::getList restricted by BOTH current V5 user ID and order ID; BasketTable::getList for lines. Read-only; no V4 dependency.
- src/V5MySqlOrderDetailRead.php: V5 HTTP port using strong V5 session.
- src/V5MySqlStrongSessionStore.php: readOrderDetailForAccess under existing strong JWT, locked b_user, AUTH_EPOCH and session scope.
- src/V5AuthHttpHandler.php: readOrderDetail action (JSON, static route, not dynamic URL).
- src/V5RouteMap.php, src/V5ProductionNativeRequestBoundaryGuard.php and public/pilot compositions wired in feature.
- src/V5HttpTransportBoundary.php and src/V5ParallelHttpEntry.php must BOTH be overlaid in offline tests; otherwise the older candidate does not recognize /orders/detail.
- tests/V5IndependentOrderDetailMvpTest.php: mock-only native D7/HTTP order-detail contract, never live user.

Important ACTUAL latest GitHub documentation, added after initial first runner:
- One real operator attempt with the earlier order-detail runner FAILED:
  V5_ORDER_DETAIL_MVP=FAIL, FINAL_RC=2, WRAPPER_RC=2.
  DETAIL_TEST_EXCEPTION=RuntimeException at test line 81, which was its generic assertion wrapper (not enough to identify exact failing assertion).
- The cause found by inspecting the source-overlay launcher was missing the updated V5HttpTransportBoundary.php and V5ParallelHttpEntry.php. These are now included. The test exception handler was also updated to print allowlisted DETAIL_FAILED_ASSERTION=DETAIL_... without leaking sensitive data.
- CURRENT fixed launcher:
  site-integration/samzaberu-v5/tests/diagnostics/run_v5_order_detail_mvp_readonly.sh
  FIXED LAUNCHER commit: cc085321dbe8b45c64d1620eeab05c9d021b5d3a
  FIXED LAUNCHER exact git blob: 5db83d2e1d8e40af017d7d2abcac2e9c97701935
  Fixed test/sources pinned by script to source commit 55eedd7346800b0dc12e72bc484835a67c1ca431.
- There is NO confirmed operator PASS of this latest corrected launcher yet. If user has its result, assess it FIRST. Otherwise send ONLY the exact corrected launcher in one clear-screen wrapper for root@evrasia and request full stdout. If it fails, fix that specific assertion/loader, don't branch into security audit. If it passes, close detail and continue to order placement.

## 5. Independent V5 business functionality developed (GitHub feature, NOT deployed)

- GET /api/v5/profile: V5BitrixLockedProfileReader, V5MySqlProfileRead; native b_user ID, login, name, last name, second name, email, phone. Minimal DTO, not full V4 profile parity yet.
- GET /api/v5/cart: V5BitrixLockedCartReader, V5MySqlCartRead; native server-owned b_sale_fuser ID, Bitrix Sale Basket::loadItemsForFUser, item/base/current prices, totals, quantity, no FUSER creation. Company-specific custom discounts/gifts/delivery pricing NOT YET PROVEN identical to V4.
- GET /api/v5/orders: V5BitrixLockedOrdersReader, V5MySqlOrdersRead; native OrderTable list, latest 50, user-scoped, order status/payment/cancellation.
- POST /api/v5/orders/detail: V5BitrixLockedOrderDetailReader, V5MySqlOrderDetailRead, line items. Pending corrected contract test.
- Auth architecture in repo: V5ParallelHttpEntry, V5HttpTransportBoundary, V5RouteMap, V5AuthHttpHandler, V5LoginWorkflow, V5MySqlStrongSessionStore, V5BitrixNativeFirstFactorComposition, V5MySqlPublicComposition, V5MySqlPilotComposition. Own V5 JWT and factors; actual production provider assembly not complete, V5 AUTH OFF.
- Existing public legal: /api/v5/legal/consents, /api/v5/legal/registration-documents, /api/v5/legal/consents/validate. Legal documents at registration requested by SamZaberu developer.

The exact current code/commit is always the LIVE GitHub branch, not one of the intermediate commits cited for chronology. Before making the next functional change, read the currently fetched feature files and recent commits. Avoid overwriting somebody else's concurrent changes.

## 6. Next execution plan: implement functioning V5, not more preflights

1. FIRST consume the operator result of fixed order-detail test; fix a concrete FAIL if present. Do not repeat profile/cart/order-list tests that already passed.
2. Implement independent V5 ORDER PLACEMENT / checkout using Bitrix Sale D7 and business rules observed in the V4 reference, including delivery/restaurant, timing, payment, promo/bonuses, gifts as required by app. This is not just an OrderTable insert and must not invent pricing or silently skip existing validations.
3. Implement cart mutations in own V5 (add, plus, minus, remove, clear, gifts, repeat order, apply sale), as required by app API. Native Bitrix Sale D7 and existing company services are sources of truth; V4 is code reference only.
4. Complete order detail parity and order cancel/status/payment as app contract requires; do not expose admin-only change-status method to ordinary guest.
5. Complete profile update/change email, registration and legal consent acceptance, PIN/bonus display, auth JWT/refresh/trusted device/TOTP/email/SMS recovery using already existing site components. Prioritize real end-to-end flow, not new security layers.
6. Reconcile API contract for СамЗаберу developer (old V4 routes/payloads vs V5 autonomous routes), provide response examples, preserve required legal docs at registration.
7. Run narrow tests against real Bitrix/site using already existing legitimate account flows and credentials without printing them, backup/rollback. Activate V5 AUTH only with user permission; keep V4 independent until real mobile migration, then retire V4 later.
8. Update this handoff after each material change with implementation status: DISCUSSED, FEATURE CODE, OFFLINE PASS, STAGING, LIVE, or REVERTED.

Do NOT declare a protected endpoint 'in production' merely because its offline mock passed. Do NOT create new authentication 'adapters', do not restart TLS/SQL audits without actual blocker. No synthetic production guest accounts.

## 7. ABSOLUTELY MANDATORY: terminal screen and scrollback clearing

The user has insisted on this repeatedly. EVERY one-block command intended for the user's terminal MUST begin with the literal FIRST command:

    printf '\033[3J\033[2J\033[H'

This clears terminal screen AND scrollback in compatible terminals. Put it before umask/set output, before any curl, any echo, any here-document/script creation. Also repeat it in the internal script's first executable lines, and immediately before starting actual test output, so old output is never mixed with new. Do NOT insert it after useful current diagnostic data starts unless deliberate. Do not use 'clear' alone or 'printf \033[2J' alone; these generally leave old scrollback.

Provide ONE complete, ready-to-paste command block, not 12 individual commands. For complex blocks use guarded heredoc wrapper, bash -n syntax check then run, clean temporary SCRIPT with a trap, don't close root SSH shell. Terminal results:
=== 1. HOST / ENVIRONMENT ===
=== 2. ACTION ===
=== 3. FUNCTIONAL TEST ===
=== 4. VERIFY ===
=== 5. RESULT ===
PASS / FAIL; FINAL_STATUS=PASS|FAIL; FINAL_RC=N; WRAPPER_RC=N.
Always label MODE, PRODUCTION_CODE_WRITE, DATABASE_WRITE, API_V4_CHANGED, API_V5_AUTH_ENABLED, SMS_SENT, SECRETS_PRINTED.
Never output passwords/API keys/auth headers/cookies/customer profile/order contents or raw source from production.
Production guards before modifications (hostname=evrasia, root, current V4/V5 router, V5 OFF, exact paths). Backup/rollback before live changes. Safe repeated run. No rm -rf broad targets. Keep SSH prompt open.

## 8. Ready-to-run fixed order-detail check, only if user has no result

Use the verified FIXED runner, not the old 08f221b8 runner.
Copy/paste as ONE shell block into root@evrasia:

~~~bash
printf '\033[3J\033[2J\033[H'
umask 077
(
  set -Eeuo pipefail
  if [[ "$(hostname -s)" != evrasia || "$(id -u)" -ne 0 ]]; then
    echo 'FINAL_STATUS=FAIL'; echo 'FINAL_REASON=HOST_OR_USER'; exit 2
  fi
  SCRIPT="$(mktemp /tmp/ev-v5-order-detail-handoff.XXXXXXXX.sh)"
  trap 'rm -f -- "$SCRIPT"' EXIT
  COMMIT='cc085321dbe8b45c64d1620eeab05c9d021b5d3a'
  URL="https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$COMMIT/site-integration/samzaberu-v5/tests/diagnostics/run_v5_order_detail_mvp_readonly.sh"
  curl -fLsS --connect-timeout 8 --max-time 30 "$URL" -o "$SCRIPT"
  [[ "$(git hash-object "$SCRIPT")" == '5db83d2e1d8e40af017d7d2abcac2e9c97701935' ]] || {
    echo 'FINAL_STATUS=FAIL'; echo 'FINAL_REASON=SOURCE_INTEGRITY'; exit 2
  }
  bash -n "$SCRIPT" || {
    echo 'FINAL_STATUS=FAIL'; echo 'FINAL_REASON=SHELL_SYNTAX'; exit 2
  }
  printf '\033[3J\033[2J\033[H'
  bash "$SCRIPT"
)
RC=$?
echo "WRAPPER_RC=$RC"
~~~

Note: if the user supplies last output before using this block, analyze it and DO NOT run the test again. Current fixed script already clears terminal at the beginning.


## 9. Operator-verified order-detail acceptance — 2026-10-25 (supersedes pending status)

User supplied REAL stdout from corrected pinned launcher `cc085321dbe8b45c64d1620eeab05c9d021b5d3a`:

- `V5_INDEPENDENT_ORDER_DETAIL_MVP=PASS`
- `ORDER_DETAIL_ASSERTIONS=19`
- `V5_ORDER_DETAIL_MVP=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`
- `BITRIX_D7_ADAPTER_EXERCISED=MOCK_ONLY`; `BITRIX_BOOTSTRAP=NO`; `DATABASE_CONNECTION=NO`.
- `V4_RUNTIME_IMPORTED=NO`; `PRODUCTION_CODE_WRITE=NO`; `DATABASE_WRITE=NO`; `V5_AUTH_ENABLED=NO`; no synthetic guest or SMS.

ACCEPTED independent V5 offline MVP checks: profile **12/12**, cart read **16/16**, orders list **18/18**, order detail **19/19** — total **65/65**. Do **NOT** rerun these fixed offline tests or say that protected V5 API is LIVE.

**Immediate next development:** independent V5 checkout / order placement, using Bitrix Sale D7 and real business rules of the existing V4 `Cart::order` / shared `CartApiService` as *reference*, with **zero** runtime dependency on Controllers/V4 or legacy JWT. Do not fake order placement by directly inserting OrderTable rows or bypassing restaurant/delivery/payment/gift/price policy. V4 checkout source exists only in the operator's existing root-only production snapshot `/root/ev-v5-mvp.2eCDIApS`, not in the feature GitHub tree; for exact input and D7 call contract use one **narrow** masked, read-only checkout-symbol extraction on already pinned V4 sources, not broad security or architecture audits. No production/site/database writes without separate approval. Current V5 AUTH OFF.


## 10. V5 checkout HTTP admission / independent order placement (2026-10-25)

After operator-confirmed 19/19 order-detail PASS, the feature-only V5 checkout seam was implemented in `bc009edb46d72e2d1f38b16cec765c0d0a12f034`:
- Static **POST /api/v5/cart/order** with JSON `access_token`, `request_id` (128-bit client retry identity), `checkout` (bounded current V4-named fields only). This is a **new V5 provisional payload**, not confirmed V4 exact parity; dynamic restaurant/payment/price rules belong to real native writer.
- No V4 controllers/JWT runtime. `V5MySqlStrongSessionStore::placeOrderForAccess` rechecks own strong JWT, locked user, epoch and session, calls **mandatory** `V5LockedOrderPlacementPort` under the same transaction. Invalid/partial native save receipt aborts and rolls back. Writer contract requires durable request-ID deduplication and existing native Sale D7/company business rules; **no positive production native writer is installed**.
- New `V5CheckoutRequest.php`, `V5MySqlOrderPlace.php`, route guard and handler/composition opt-in. Without explicit native writer checkout remains **503**, V5 AUTH stays OFF. No site/DB writes.
- `tests/V5IndependentCheckoutHttpTest.php` is source-only and uses in-memory port, not Bitrix D7 or real orders. Do not call checkout production-ready until actual native D7 checkout, restaurant/delivery/payment/discount/gift rules and real-account E2E have been verified.
- Latest operator's read-only V4 method-name report proves calls to `validateOrderData`, `getProductsForOrder`, Sale D7 `Order::create`, `createItem`, `setBasket`, `doFinalAction`, `save` plus payment/restaurant/shipment/property operations. It does **not** reveal method arguments, required input shape or monetary semantics. Next: one narrow masked source trace of V4 `Cart::order` business call argument structure, then implement site-owned D7 writer from verified native rules. No additional security audit.

### Checkout HTTP validation + source-shape verification (PENDING operator stdout)

- Independent V5 checkout source commit: `bc009edb46d72e2d1f38b16cec765c0d0a12f034`.
- Targeted HTTP-contract test / regex correction: `fe5387a901a1bdfc9ecf8f866f3b29080d3504cd`, `tests/V5IndependentCheckoutHttpTest.php`.
- Scoped CI workflow added: `121e230a057f3e6e2aa0c9ba947591f7882a66d5`. Its successful run has NOT been verified, so `CI_NOT_CONFIRMED` as of this handoff update.
- Narrow V4 checkout call-argument-shape extractor: `tests/diagnostics/V5CheckoutBusinessCallTrace.php`; masks all PHP string literals and does not execute source.
- **NEXT one-time operator runner**: `site-integration/samzaberu-v5/tests/diagnostics/run_v5_checkout_contract_readonly.sh` at commit `8f087a4f631e940b77eacb484033992f21bd3610`, Git blob `675843de0e2642e5a47adcdeb3e633cf86cb39d4`. It pins the new HTTP source SHA, runs **only new** source-only checkout contract test, then emits limited call-shape hints for current pinned `Cart::order`. V4/site/DB stay unchanged, V5 AUTH stays OFF, no order placed. Requires actual operator stdout before declaring its test PASS.
- Use the exact pinned business hints from the result to implement the **real native D7 V5 checkout writer**, preserving existing `CartApiService`, restaurant, timing, delivery, coupons, gifts, and payment; never create a fake persistence/PAY success claim or a V4 runtime dependency.


### Operator checkout result and Bitrix Sale D7 builder (2026-10-25)

Confirmed operator root@evrasia output from pinned checkout runner `8f087a4f631e940b77eacb484033992f21bd3610`:
- `V5_CHECKOUT_HTTP_ADMISSION=PASS`, `CHECKOUT_HTTP_ASSERTIONS=12`, `V5_CHECKOUT_HTTP_TEST=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`.
- Real installed V4 `Cart::order` code shape (source-only, untouched) confirmed: `validateOrderData($_POST)`, `getProductsForOrder($productIds)`, `Sale\\Order::create(site,user)`, payer type **1**, delivery service **1**, `createItem`, `setFields`, `setBasket`, shipments/quantities, pay system, `doFinalAction(true)`, order properties, `save()`, `orderCreatedEvent`, gateway `pay`. This is a *method-call trace*, not native V5 parity or a confirmed payment integration.
- `BITRIX_D7_ADAPTER_EXERCISED=NO`, `ORDER_CREATED=NO`, `DATABASE_CONNECTION=NO`, `PRODUCTION_CODE_WRITE=NO`, `API_V4_CHANGED=NO`, `V5_AUTH_ENABLED=NO`.

GitHub feature-only native D7 order builder added at commit `12fec975e2bb580a242b26583afb2d1e97b2fd2a`, `src/V5BitrixSaleD7OrderBuilder.php`: creates Sale D7 order, attaches a site-prepared basket, native shipment, payment record, properties and calls `Order::save()`. It does **not** call external payment gateway or import V4. It is NOT wired as a positive `V5LockedOrderPlacementPort` yet: real site checkout validation, prepared products/prices, idempotent durable request ledger, and transaction continuity remain necessary before production writes.

GitHub Actions workflow #40203115822 **SUCCESS** (source-only): `V5_CHECKOUT_HTTP_ADMISSION=PASS` **12 assertions**, `V5_NATIVE_D7_ORDER_BUILDER=PASS` **17 assertions**, PHP lint PASS; D7 was MOCK-ONLY, `REAL_ORDER_CREATED=NO`. CI URL: https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40203115822 .

**NEXT concrete implementation dependency, not security audit:** read the *narrow masked method bodies* of installed V4 `Cart::validateOrderData`, V4 `Cart::order` order-property mappings and shared `CartApiService::getProductsForOrder` to port exact site rules, pricing and required fields into an independent V5 checkout planner. Follow with native V5 placement code, not another generic security system. Retain V4/runtime independence, no synthetic accounts and V5 AUTH OFF.
