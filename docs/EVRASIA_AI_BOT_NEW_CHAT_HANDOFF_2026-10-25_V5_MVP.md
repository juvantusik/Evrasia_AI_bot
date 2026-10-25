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


## 11. Native checkout exact business shape — next operator stdout (2026-10-25)

Current source-only D7 builder GitHub commit `12fec975e2bb580a242b26583afb2d1e97b2fd2a` and mock CI `#40203115822 SUCCESS` (12 checkout HTTP + 17 D7 builder assertions); STILL no real native checkout, no idempotent order-save writer and no production write.

**NEXT exact runner**: `site-integration/samzaberu-v5/tests/diagnostics/run_v5_checkout_native_business_shape_readonly.sh` at commit `17c177a2514da55afedba07a4338f740e9cceaef`, Git blob `372986393a62847785007c420c2e12192329f082`. It reads ONLY exact pre-pinned site files `V4/Cart.php` and `CartApiService.php`, extracts masked structure of `validateOrderData`, `order` property lines and `getProductsForOrder`. Every other string literal is masked and large numeric literals are masked. No site source executed or copied to GitHub, no SQL/data/changes/SMS/payment; V5 AUTH OFF. First executable command in every wrapper AND inner runner must clear screen+scrollback with `printf '\\033[3J\\033[2J\\033[H'` (in shell single-quoted escape syntax). This is not a repeat of earlier broad audit: it is necessary to implement exact site restaurant/payment/pricing/order-property behavior in V5 independently. Await operator stdout; **do not claim native V5 checkout works**.


## RestIS -> Bitrix order status / PAYED source-only evidence, 2026-10-25

The user supplied operator PASS from pinned production source-only /orders/set_status trace:
- `RESTIS_PAYMENT_STATUS_SOURCE_TRACE=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`.
- The installed V4 `POST /orders/set_status` router closure authenticates a named `restis` caller through `checkAuth($users)`, reads an order key and `$_POST['status']`, maps external status strings to internal `STATUS_ID` (observed values include `P` and `F`), resolves a Bitrix order and calls `CSaleOrder::StatusOrder(order_id, newStatus)`.
- **No direct PAYED write in this route.** This proves RestIS status propagation independently from a specific payment flag; it does NOT prove whether payment automatically triggers completion, nor whether another handler sets `PAYED=Y`.
- Source-only application scan over 380 PHP files found high-signal candidates `local/php_interface/init.php` and `local/php_interface/lib/COrder.php` (both have PAYED, STATUS and Sale event markers), plus `local/php_interface/cron_order_status.php`, `order-subscribe.php`, `order-merch.php`.
- V4 still working, API V5 AUTH OFF. No Bitrix bootstrap, DB connection/write, site file write, customer data, payment, or orders created.

**NEXT exactly one focused source-only trace**: committed `tests/diagnostics/V5RestisOrderPaymentEventReader.php` at `65954b22f73274288d4457d49f1a1a74e68b5090` (blob `4258662a90e051925fd48374787dd8f8605640da`); pinned runner `tests/diagnostics/run_v5_restis_payment_event_readonly.sh` at `452b4d7b86e385f15efaa9441348668f10addfce` (blob `2f031dab3cebba96ad9bed302417fdbef15af925`). Exactly five existing production source files examined with all non-allowlisted literals masked, syntactic structure only, never require/eval. Runner verifies current V4 router SHA, five file hashes before/after, and production V5 OFF. Run once and interpret whether a paid event sets status or separate events perform each transition. Do NOT assume `PAYED=Y` implies `STATUS_ID=F` until proven, and never implement a synthetic V5 status writer to replace existing RestIS callback.


## 12. RestIS order-paid/status and mobile pickup clarification (2026-10-25)

Confirmed production SOURCE-ONLY read, `FINAL_RC=0`, `WRAPPER_RC=0`:

- `local/php_interface/lib/COrder.php` method `payOrder()`: `$payment->setPaid('Y')` at line 453, then `$order->setField('STATUS_ID','F')` at line 468. This **specific method** couples payment and completed status. It does **not** prove any independent PAYED transition automatically changes status.

- `local/php_interface/init.php` registers a Sale `OnSalePayOrder` callback (line 238). `order-subscribe.php` and `order-merch.php` call `COrder::payOrder()`. These are payment handler paths, NOT evidence that RestIS checkout calls `payOrder()`.

- The authenticated RestIS V4 `POST /orders/set_status` independently maps status into `CSaleOrder::StatusOrder`, including `F`, without a direct `PAYED` write; cron and agents also update status. Thus `STATUS_ID=F` need not imply `PAYED=Y`.

- **Business decision from user:** SamZaberu places orders only; guests pay in person at the restaurant. V5 must NOT require `payment` or `pay_system_id` from the mobile client, initiate online payment, or mark checkout as paid/completed. Server-owned configured unpaid pickup Sale pay-system may be necessary to record a payment COLLECTION item (not a charge); the real system ID is NOT VERIFIED and must be configured from site, no guessed defaults. No new V5 RestIS status writer.

- V5 feature-only checkout HTTP input and business-rule class updated accordingly, with contract tests; production V4 unchanged, V5 AUTH OFF. Live checkout writer and real Bitrix validation remain **NOT IMPLEMENTED/NOT TESTED**.


### Verified CI after no-client-payment correction

- GitHub Actions run `40207248310` **SUCCESS**, source-only, commit `89beb2175db8df9008e652eb14f98be0cdd9b10e`.

- `V5_CHECKOUT_HTTP_ADMISSION=PASS`, **16 assertions**.

- `V5_NATIVE_D7_ORDER_BUILDER=PASS`, **18 assertions** (mock Sale D7 only).

- `V5_CHECKOUT_BUSINESS_RULES=PASS`, **18 assertions** (fake Bitrix / OrderAPI only).

- No Bitrix bootstrap, production database access, payment or real order creation. No positive native checkout writer attached; app remains V5 AUTH OFF.


## 13. Standalone pickup checkout writer + idempotency candidate (2026-10-25)

- New `src/V5BitrixLockedPickupOrderWriter.php` at `39a1978510cc0c1dc9cf0386bf15dbb12d3d873d`: native V5 `V5LockedOrderPlacementPort` implementation using verified V5 user, server-only locked FUSER, current site ID, injected site-priced basket/materializer, existing `V5BitrixCheckoutBusinessRules`, and D7 saver. Uses **unique** `USER_ID + request_id` with SHA-256 checkout hash, lock/replay and immutable saved order receipt. Denies different-body retries and client payment fields. Does not import V4 code, client user IDs or JWT.
- `schema/PROPOSED_ev_v5_pickup_checkout_requests.sql` provides the unique InnoDB ledger. **PROPOSED ONLY**, not installed; no site/DB writes. Pending native D7 side-effects and transaction continuity acceptance before any real checkout is enabled. Do **not** state it is production idempotent until ledger is installed and real D7/savepoint continuity verified.
- Test `tests/V5LockedPickupOrderWriterTest.php` with a wholly in-memory PDO fake confirmed by GitHub Actions `40207565518`: `V5_PICKUP_ORDER_WRITER_MVP=PASS`, `PICKUP_WRITER_ASSERTIONS=16`. Other checkout source-only contract totals in the same run: HTTP **16**, builder **18**, business rules **18**. No database connection, no real customer or order; mock cannot prove native D7 transactions or interactions.
- Additional V5-only Bitrix D7 order builder guard commit `047913479de525792bf6e32283de9b0598133aa9`: after `Order::save()`, require `PAYED=N`, `Order::isPaid()===false`, `Payment::isPaid()===false`, and initial `STATUS_ID` in `N/P` (never `F`). Added negative mock checks for order/payment unexpectedly marked paid; GitHub Actions success must be verified separately before calling guard tests PASS.
- **Still unimplemented real site dependency:** `V5NativePickupOrderMaterializerPort` production provider to create a fresh **server-priced** basket, including existing discounts/gifts, and exact native Bitrix order properties from V4; site-configured unpaid pickup pay-system ID must be confirmed. Without that provider and transaction continuity, do NOT wire the writer into production V5; V5 AUTH OFF, no real orders.
- Next focused development: inspect only live `V4/Cart::order` basketFields, payment-mode mapping to Sale pay system and getPropertyByCode property codes (not broad audits), then write concrete V5 materializer. This avoids inventing checkout pricing and accidentally paying the customer online.


### Latest V5 pickup writer accepted CI + exact next production source dependency

- `V5BitrixLockedPickupOrderWriter.php` + proposed-only ledger DDL, commit `39a1978510cc0c1dc9cf0386bf15dbb12d3d873d`; CI run `40207565518` SUCCESS `V5_PICKUP_ORDER_WRITER_MVP=PASS`, `PICKUP_WRITER_ASSERTIONS=16`, MOCK_ONLY. Cannot operate live without the currently absent site business basket/property materializer and proposed ledger schema.

- `V5BitrixSaleD7OrderBuilder.php` now rejects unexpectedly paid payment/order and non-new status after D7 save, commit `047913479de525792bf6e32283de9b0598133aa9`. GitHub Actions run `40207640721` SUCCESS, source-only. The guest pays upon pickup; never mark PAID=Y or F when placing the order.

- Checkout CI still covers HTTP **16/16**, builder tests now **20/20** (including paid-on-create guards; check exact CI logs for count), business rules **18/18**, locked writer **16/16**. **All offline mocks**; no Bitrix production/customer/SQL writes.

- A single narrow *verified* `Cart::order` source-only basket field/property/pay-system mapping extractor: `site-integration/samzaberu-v5/tests/diagnostics/V5PickupBasketPropertyShape.php`, source commit `7bfc27e9da4c5e32ed9ec7765fa930383812cf86`, blob `8463851978c33c89c4aee6775d0f20edd9fc09cc`. GitHub Actions `40207764221` SUCCESS (PHP lint + self-test). Earlier initial extractor CI FAILED due colliding PHP builtin `extract()`, corrected and retested in the pinned version; only use corrected commit. No source execution, no arbitrary literals printed.

- **Next only necessary operator action:** one pinned SHA, source-only invocation of that extractor on `/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/Controllers/V4/Cart.php`, hash `73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039`; V5 OFF enforced; send output `BASKET_FIELDS`, `MODE ... SALE_ID`, `PROPERTY_CODE`. Then write REAL V5 basket/property materializer and attach writer in protected V5 composition only after native D7 transaction/ledger/real-account approval. No need to rerun previously accepted tests or wide audits.


### Next confirmed runner for exact native pickup basket/property assembly

- One focused read-only runner `site-integration/samzaberu-v5/tests/diagnostics/run_v5_pickup_basket_field_readonly.sh`, pinned commit `99c8c62f4d6a043443c2c2f82e1ecbe5fd9ec420`, Git blob `9a2e08025f74a819c8ea6563f35c0daf508feac0`. It internally fetches `V5PickupBasketPropertyShape.php` from commit `7bfc27e9da4c5e32ed9ec7765fa930383812cf86`, blob `8463851978c33c89c4aee6775d0f20edd9fc09cc`; verifies current V4 Cart and router SHA, V5 AUTH OFF, PHP syntax+self-test. Executes no site PHP and makes NO DB connection or changes.
- GitHub Actions `40207859347` SUCCESS for parser + wrapper (source-only checks; DOES NOT attest live Bitrix business parity).
- Expected operator fields: `BASKET_FIELDS_*` masked/allowlisted values, `MODE=... SALE_ID=...` current V4 payment-mode map, and `PROPERTY_CODE=...` current order property codes. This is the one missing concrete production business input for V5 native basket/property materializer, not a repeat of previous audits. Send full script stdout; then implement actual V5 native `V5NativePickupOrderMaterializerPort`. No automatic V5 checkout enablement.


## 14. Operator-verified V4 basket field map -> independent V5 pickup native materializer (2026-10-25)

**Real operator stdout ACCEPTED** from `run_v5_pickup_basket_field_readonly.sh`:

- `V5_PICKUP_NATIVE_BUSINESS_SHAPE=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`, V4 source pins unchanged, V5 AUTH OFF.

- V4 `Cart::order` creates basket lines with `QUANTITY = (int)$item['quantity']`, `NAME=$productInfo['NAME']`, `DETAIL_PAGE_URL`, `CURRENCY`, `LID`, `PRICE=$productInfo['PROPERTY_RED_PRICE_VALUE']`, `BASE_PRICE=$productInfo['CATALOG_PRICE_1']`, `CUSTOM_PRICE`, one masked provider field. Source exposes three payment options: `cash=>2`, `card=>5`, `sbp=>2`; only *cash-on-pickup* is the user-approved app flow, and only server selects Bitrix pay-system ID 2 (no app payment selection or gateway).

- V4 known order property codes: `restaurant`, `phone`, `cutlery`, `time`, `address`, `happytime`, `wok_data`, `client_dont_call`, `dispatch`, `source`, `pay_link`, `IGNORE_RESTIS`, `REST_ID`, `pay_expire`. **REST_ID is external RestIS order reference, NOT restaurant ID.** Do not set on initial V5 checkout; `pay_link` / `pay_expire` are for payment flow, not cash pickup. Preserve trusted site source and IGNORE_RESTIS policy; never invent values.

**Actual V5-only sources in GitHub (production not changed):**

- `src/V5BitrixNativePickupMaterializer.php`, commit `ff60db25865bdf35e85bcf7f92b3918da2a28cb6`: verifies installed shared `Evrasia\\Services\\CartApiService` by pinned SHA; uses V4-confirmed flags `useRedPrice(true)`, `useRedSku(false)`, `useGifts(false)`; reads current server FUSER cart and freshly loaded catalog products. Builds unsaved native Sale Basket with verified red/base prices and passes only exact site property codes plus mandatory native payer/phone, with separate trusted policy for `source` and `IGNORE_RESTIS`; never imports Controllers/V4/JWT or client FUSER/product prices.

- `src/V5PickupFreshBasketAssembler.php`, commit `9fa70ea0e0ecc4453b9bc1049196efb61fcb0fa0`: extracted independent testable unsaved basket assembly from already server-read items and current catalog products. No Bitrix save, no payments.

- `src/V5BitrixSaleD7OrderBuilder.php` and both tests updated at `4f5d93c9db9fb1c01c9161bc055c945fda4c5d39`: maps validated buyer's name to Bitrix special `getPayerName()` and phone to `getPhone()`, while ordinary `phone` property also remains compatible; no false REST_ID/restaurant assignment. Native builder verifies unpaid order and payment, and initial order status (not completed).

- **Fresh GitHub Actions SUCCESS**: `40208378744`, CI commit `4f5d93c9db9fb1c01c9161bc055c945fda4c5d39`. Source-only assertions: HTTP **16**, Bitrix D7 builder **24**, business rules **18**, idempotent locked writer **16**, pickup materializer **23**, fresh server-priced basket **23**. Total **120** mock-only assertions. No production DB, real Bitrix sale save, customer/order creation or SMS/payment.

- **Remaining actual integration blockers** before activation: source-mask hid one V4 provider basket-field key+value and CUSTOM_PRICE literal/module value; materializer requires these from verified installed site policy rather than invented defaults. Trusted V4 `source`, `IGNORE_RESTIS` values must be confirmed. Native Sale D7 event/transaction continuity and durable ledger schema are NOT installed/tested; the actual `V5NativePickupOrderMaterializerPort` and writer are **not wired into production composition** and V5 AUTH stays OFF. Do not claim this is a live order checkout.

**Next focused action:** confirm only those 3–4 masked installed V4 constants with a tiny source-only whitelist parser (NOT broad audits), then configure V5-only native provider and do native transaction acceptance before real-account pilot. Do NOT rerun profile/cart/orders/order-detail already accepted 12/16/18/19 checks.


## 15. Exact remaining checkout constants: source-only parser and trusted provider field (2026-10-25)

Previous operator supplied `V5_PICKUP_NATIVE_BUSINESS_SHAPE=PASS` from pinned V4 Cart::order:
- BasketFields: QUANTITY, NAME, DETAIL_PAGE_URL, CURRENCY, LID, **one literal field masked in original trace**, PRICE=PROPERTY_RED_PRICE_VALUE, BASE_PRICE=CATALOG_PRICE_1, CUSTOM_PRICE masked.
- V4 pay mapping `cash->2`, `card->5`, `sbp->2`; SamZaberu guests pay ON SITE, no gateway or app payment selector.
- Property codes confirmed, incl restaurant, phone, cutlery, time, source, dispatch, IGNORE_RESTIS; external REST_ID must never be fabricated.

**NEW GitHub source-only progress (no production writes):**
- Committed `tests/diagnostics/V5CheckoutNativeConstantsTrace.php`, pinned at `7c3ea14f0996c2fce7cb765d3bf234d3f6a5f1f2`, Git blob `7986f4bcc87eb5862a6e5871c9a6b65e9275c39d`. It does ONLY static `token_get_all` extraction of V4 Cart::order, allowlists basket key identifiers, small safe module/provider/flag values, plus `source` and `IGNORE_RESTIS` assignments. Arbitrary strings are MASKED.
- Read-only server runner `tests/diagnostics/run_v5_checkout_native_constants_readonly.sh`, commit `3d0b763d515fe6a39c45c35d85294df212cd4a0f`, Git blob `259d62a7192ea497e253d8d5c75722e81899be25`; exact pinned Cart.php SHA `73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039`, V4 router SHA `5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d`, V5 AUTH OFF guard. One script read-only, no DB, no real order or payment.
- GitHub Actions source-parser self-test `40208619538` SUCCESS. Early workflow run `40208595494` failed simply because the runner was not yet committed in that run; fixed by adding it; PASS at latest pin.
- V5 native basket provider field is no longer hardcoded. `V5PickupBasketNativeFieldPolicy` now requires explicit site-confirmed `providerFieldKey`, and `V5PickupFreshBasketAssembler` emits that key. Commit `5c7419d8b3ae0c8d6ba7142f830c5307bb7387c3`; GitHub Actions `40208708791` SUCCESS, source-only test suite: checkout HTTP 16, native D7 builder 24, business rules 18, idempotent writer 16, materializer 26, fresh basket 23 = **123/123 MOCK-ONLY**. No real Bitrix/order/DB writes. No production enablement.

**NEXT:** operator runs the *one* pinned read-only native constants wrapper and pastes full stdout. Interpret `BASKET_KEY`, `BASKET_CREATE_MODULE`, `PROPERTY_SOURCE_VALUE`, `PROPERTY_IGNORE_RESTIS_VALUE` without guessing masked values. Then set concrete V5-only trusted site policy and continue native D7 transaction-continuity/real-account checkout verification. No need to rerun accepted V5 offline profile/cart/orders/order-detail or 120/120 checkout tests. V4 unchanged, V5 AUTH OFF.


## 16. Verified V4 catalog NOTES field correction and RestIS order-create trace (2026-10-25)

User pasted real operator source-only PASS from pinned checkout constants runner, `FINAL_RC=0`, `WRAPPER_RC=0`, V4 unchanged, V5 OFF. Exact V4 `Cart::order` basket uses **9 keys**: `QUANTITY`, `NAME`, `DETAIL_PAGE_URL`, `CURRENCY`, `LID`, **`NOTES`** (literal still MASKED), `PRICE`, `BASE_PRICE`, `CUSTOM_PRICE='Y'`; `Basket::createItem('catalog',...)`. Verified order property `IGNORE_RESTIS='Y'`. `source` and `happytime` are still literal MASKED; `dispatch` and `client_dont_call` are expressions. **Earlier guessed V5 `PRODUCT_PROVIDER_CLASS` field was wrong.**

GitHub feature branch (not production):

- Commits `47cfef2af5886bdb86ff3090108730c030e8a076`, `a39be3dd80fc06f1b6fc1147faddba71d9c03e10`, test fixes through `16afc9428115e88b63ff23f86720749aecf949be` remove guessed provider field entirely. `V5PickupBasketNativeFieldPolicy` now pins module `catalog`, exact key `NOTES`, `CUSTOM_PRICE='Y'`, and injects unverified NOTES *value* only from trusted site configuration (never client). `V5PickupFreshBasketAssembler` uses this exact source-verified field shape.

- `V5PinnedPickupPropertyPolicy.php` at `4fb450b1060fa14e3cce7a11db70435558f4af1f`: only trusted site config supplies still unknown literal `source` and `happytime`; fixed `IGNORE_RESTIS='Y'` matches V4. `V5BitrixNativePickupMaterializer.php` denies IGNORE_RESTIS=N (`76c2dd3d388b6b066f10adf939fd92fbfc122733`). Tests added and CI updated by `af6126ab3fda6be3e013d325d07d3f551ffab15c`.

- **GitHub Actions SUCCESS** `40209368085` (complete source-only checkout test suite): HTTP 16, D7 builder 24, business 18, idempotent writer 16, materializer 23, fresh basket 24, pinned site property policy 12: **133/133 PASS, MOCK ONLY**. V4 production unchanged, V5 AUTH OFF, real checkout/DB writes NO.

**Critical next real business checkpoint:** V4 `Cart::order` calls `orderCreatedEvent`, and `COrder::orderSaveHook` is registered for Sale `OnSaleOrderSaved`. Need verify how `IGNORE_RESTIS='Y'` affects sending a newly created order to the restaurant before enabling native V5 checkout; otherwise a Bitrix order may exist without reaching RestIS. Source-only parser `tests/diagnostics/V5RestisOrderCreateTrace.php`, pinned `d0deedf711b52006dfbbb9d416127bc1c9878500` blob `f89dec6c0e387861bf7039ce99be27b86f5f4be9`. Read-only runner `tests/diagnostics/run_v5_restis_order_creation_readonly.sh`, pinned `a9848b72a595ae7297d4792efaf31b58fa15ec22` blob `0fa0fcb9dbfa7c0abc7205900384589eb478a88e`. Target SHA-pinned real `Cart.php`, `COrder.php`, router, V5 OFF, source tokenizer only. Return masked relevant shapes for Cart::order, COrder::orderSaveHook and generateXML. Do NOT print secrets/site PHP literals or execute source. Await operator stdout, then implement exact native event bridge or use existing hooks as verified, without V4 controller/JWT. No production enablement yet.


## 17. RestIS dispatch boundary confirmed — one remaining CartApiService event body (2026-10-25)

Actual operator output from pinned real `evrasia` static source inspection:
- `V5_RESTIS_ORDER_CREATE_SOURCE_TRACE=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`. All files match pinned SHA, V5 AUTH OFF, no source execution, no DB or production writes or RestIS requests.
- In `local/php_interface/Controllers/V4/Cart.php::order`: line 324 sets order property `IGNORE_RESTIS='Y'`; line 346 explicitly calls `$this->cartService->orderCreatedEvent()`; line 354 reads `REST_ID`.
- In `local/php_interface/lib/COrder.php::orderSaveHook`: around line 346, `if($propIgnoreRestis === 'Y' || !$phone)` prevents ordinary RestIS send; other path includes `COrder::generateXML($order)`. Thus **V5 must not be considered complete from D7 `Order::save()` alone** when `IGNORE_RESTIS='Y'`; user could receive an order number while the restaurant never receives it. It is not yet established whether V4's `orderCreatedEvent` sends to RestIS, queues an event, or clears the cart. Do NOT call it speculatively, duplicate dispatch, or choose `IGNORE_RESTIS=N` just to force the old hook.

One exact next source-only operator step (not broad audit):
- Extended `site-integration/samzaberu-v5/tests/diagnostics/V5RestisOrderCreateTrace.php` at commit `ce2533c52f338afc588ea4d12fcb7ad80d57690c`, blob `0d860b6a048e5068c5419aa5146ea2e77c9f77b5`, to print bounded masked statements of **`Evrasia\\Services\\CartApiService::orderCreatedEvent()`** in addition to already accepted `Cart::order`, `COrder::orderSaveHook` and `generateXML`.
- Pinned production read-only runner `site-integration/samzaberu-v5/tests/diagnostics/run_v5_restis_event_boundary_readonly.sh` at commit `2f0e5a62aa92d098da16251cf259e08fe788fbad`, blob `4213a86b1ea5c95aabfaffc627baa0ff5c580dd4`. Validates source-only exact SHA: V4 Cart `739890...`, COrder `ae70...`, CartApiService `61671c...`, V4 router `5af13...`, V5 OFF private config before/after; never executes any installed PHP, no DB/REST calls/production writes.
- GitHub CI `40209668103` SUCCESS for parser and wrapper PHP/Bash syntax and redaction self-test. This **does not** run on live Bitrix.
- Return stdout with `=== CART API SERVICE EVENT BOUNDARY ===` and method statements, decide whether checkout's RestIS dispatch is synchronous, queued or just a basket cleanup. Then adapt V5 save/dispatch with idempotency and transaction/commit ordering as established by real site code. Keep V4 unchanged, V5 AUTH OFF, no synthetic production accounts.


## 18. Operator proved orderCreatedEvent only runs two SQL queries (2026-10-25)

Received exact output from pinned real-production read-only script `run_v5_restis_event_boundary_readonly.sh`, `V5_RESTIS_ORDER_EVENT_BOUNDARY=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`, V4 source pinned/unchanged, V5 AUTH OFF.

Observed V4 checkout call chain:
- `Cart::order()` line 324 sets `IGNORE_RESTIS='Y'`, then `$this->cartService->orderCreatedEvent()` at line 346, then accesses `REST_ID` at line 354.
- `COrder::orderSaveHook()` at line 346 checks `$propIgnoreRestis === 'Y' || !$phone` before the standard `RESTIS=='Y'` / `generateXML()` branch. Therefore ordinary save-hook RestIS submission is bypassed when `IGNORE_RESTIS=Y`; this is verified source structure, not verified real dispatch.
- `CartApiService::orderCreatedEvent()` contained `global $DB;` then **exactly two** `$DB->Query('MASKED' . $this->id)` at real source lines 523/525. **No direct RestIS call** in that method. Original SQL literals were masked; unknown whether SQL deletes/updates cart or other tables. IMPORTANT: do not claim `orderCreatedEvent()` sends the order to RestIS, nor implement speculative V5 outbound RestIS requests or a checkout post-save call before mapping SQL effects.

Follow-on single-purpose source-only inventory:
- `tests/diagnostics/V5CheckoutPostSaveSqlScope.php` at commit `e2fbd660f81a14f5dd185a4f04e42dcfcd9821c8`, git blob `cf61b53f2f3ccfe31cddb160f74721b5da4affec`. Uses PHP tokenizer on only installed `CartApiService::orderCreatedEvent`, requires exactly 2 `$DB->Query()`, reports SQL operation and allowlisted affected Bitrix table/field identifiers. **No SQL literal contents output.** No site code execution or DB access.
- Pinned runner `tests/diagnostics/run_v5_checkout_postsave_sql_scope_readonly.sh` at commit `f9331312dbd952f5e15048ae0378209dd9848113`, git blob `86708ced300c5db2c913d8f2449314936ad346b3`. Checks real CartApiService SHA `61671c764f23a82ce66a990d5c9122ebf25fdac800ab51828c0805c8c9db18e0`, V4 router SHA `5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d`, V5 private OFF JSON, parser hash and self-test; no data read/write; no auth enable.
- GitHub Actions `40209857194` **SUCCESS**, verifies parser syntax+selftest and wrapper Bash syntax; this does **not** attest live SQL details. Await operator stdout of exactly this runner to decide cart cleanup/RestIS boundary.
- Next step after interpreting two SQL operations: implement V5-only post-checkout behavior using existing trusted site service semantics only if transaction and idempotency boundaries permit, and separately establish where **actual restaurant dispatch** occurs. Do not enable V5 production checkout or send SMS/payments/orders. All earlier 133/133 offline checkout tests remain accepted.


## 19. Real checkout post-save SQL read-only verification (2026-10-25)

**Operator output accepted**: `V5_POSTSAVE_SQL_SCOPE=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`, pinned real V4 `CartApiService`, V4 router, V5 AUTH OFF, SQL source-only (no DB connection/write).

Inside `CartApiService::orderCreatedEvent()` exactly TWO `$DB->Query()`:
- QUERY 1: `DELETE`, contains `FUSER_ID`, `WHERE`, uses `$this->id` service identity. Table not in narrow standard `B_SALE_*` allowlist.
- QUERY 2: `DELETE`, contains `WHERE`, uses `$this->id`. Table not in the narrow standard allowlist.
- **No actual RESTIS dispatch or enqueue within the method**, so this is a post-save **data deletion** concern, not a RestIS delivery bridge. Do NOT assume specific table names or side effects until safely classified; do NOT call after native V5 save speculatively.
- Existing COrder Sale save hook skips its usual send path when `IGNORE_RESTIS='Y'`; V4 Cart::order sets it to Y. Need independently identify actual restaurant order transport/fetch before enabling V5 order create; a successful Bitrix Sale save alone is not proven restaurant delivery.

Safe NEXT targeted operator script:
- `site-integration/samzaberu-v5/tests/diagnostics/V5CheckoutPostSaveSqlScope.php`, corrected at commit `524925aec78ad12eee069fd978ce950042662431` blob `498e471a23789f96db057f69021d0cd4e2570e22`: outputs only syntactically safe `DELETE FROM <identifier>` / `UPDATE <identifier>` table name and SQL operation, never SQL or literal contents. Explicit self-test includes both UPDATE and DELETE and no secret exposure. Earlier intermediate version failed CI due UPDATE test case; corrected and CI `40210217515` SUCCESS.
- Pinned runner `site-integration/samzaberu-v5/tests/diagnostics/run_v5_checkout_postsave_sql_scope_readonly.sh`, commit `0b86629d7ca68989e82cdc29bd971ca78557b1ed`, blob `2de5cb5fb9de099991c903dceed4776299c2a61f`. It checks exact installed CartApiService SHA, router SHA, V5 OFF before/after; only source reads. GitHub Actions `40210227811` SUCCESS with PHP lint, redaction self-test, Bash lint. Returns `QUERY_1_TARGET_IDENTIFIER`, `QUERY_2_TARGET_IDENTIFIER` to determine deletion scope; no SQL executed.
- Remain cautious: native V5 writer and ledger are feature-only offline tests, **not** deployed, no production DB schema and no real orders; V4 unchanged. Next development is exact post-save lifecycle and actual restaurant dispatch, then native transaction continuity validation, not new auth work.


## 20. Confirmed exact V4 two-table checkout cleanup; V5 guarded new-order cleanup lifecycle (2026-10-25)

User pasted **production pinned SOURCE-ONLY PASS** for `CartApiService::orderCreatedEvent`: `V5_POSTSAVE_SQL_SCOPE=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`, V4 unchanged, V5 AUTH OFF, no DB connection/order/payment/SMS.
- The method performs exactly TWO `DELETE` queries: `B_API_BASKET` with `FUSER_ID` and `$this->id` in predicate; `BASKET_CUSTOM_GIFTS` with a `WHERE` predicate and `$this->id`. **Second predicate column not yet verified**. No outbound RESTIS call. This method is **cart/gifts cleanup**, NOT RestIS submission. Thus calling it is not a way to deliver the order to a restaurant.
- V4 Cart::order sets `IGNORE_RESTIS=Y`, and COrder::orderSaveHook skips its ordinary RESTIS submission branch when Y. Actual restaurant fulfillment transport/fetch remains **UNVERIFIED**. Do not enable native V5 checkout without proving it reaches restaurant/RestIS.

**Feature-only production-unwired changes**, no production writes:
- `src/V5BitrixLockedPickupOrderWriter.php`, commit `9311fc9cae656f2f2619d7ed31b07690d7314565`: requires an injected **`V5NativePickupCartCleanupPort`** to do exactly-once server-owned cart cleanup after a NEW native Sale order was saved and before the durable receipt ledger is completed. This is ONLY a tiny transaction-aware seam, **not a new replacement for the existing shared CartApiService cleanup**.
- The port requires `assertNativeTransactionContinuity(PDO)` BEFORE D7 save, AFTER it, and AFTER cleanup; `clearSavedCart(PDO, user_id, verified_fuser_id, order_id)` only for an initial successful `request_id`. A replay returns the existing receipt immediately with NO native save and NO cart cleanup. Cleanup failure / native transaction loss must prevent any successful receipt and cause the V5 strong-session store transaction to rollback. It **must not be configured to execute** until one physical transaction shared by Bitrix Sale::save(), native Bitrix `$DB` and the V5 PDO ledger is verified with savepoint continuity. No positive production cleanup implementation has been installed.
- `tests/V5LockedPickupOrderWriterTest.php` added real fail-closed mock assertions for verified-before-save, 3 transaction-continuity checks, one cleanup on new order, none on replay, invalid transaction rejection before save, failed cleanup rejects the receipt. Commit `7c7254a0f971f111f8f51aa3ca5f61730ee10022`.
- GitHub Actions `40210438547` **SUCCESS** on latest code: checkout HTTP **16**, D7 builder **24**, checkout business **18**, locked writer **24**, native materializer **23**, fresh basket **24**, pinned site policy **12** = **141/141 MOCK-ONLY**. Intermediate commit `9311fc9` had failing CI because its test was not yet updated; final CI passed after the test update.
- NOT YET RESOLVED: precise WHERE field of `BASKET_CUSTOM_GIFTS`, Bitrix Sale native transaction identity with PDO and `$DB`, real successful order delivery to the restaurant, side-effects and post-save cart cleanup under concurrent FUSER mutation, real-account pilot. No proposed ledger table has been installed. V5 AUTH OFF, V4 unchanged, no production checkout invoked.

Next: prioritize narrowly verifying actual RESTIS fulfillment transport and DB same-transaction guarantees (no broad repetition of already accepted API auth/cart/history or 141 offline tests); only then implement site-local cleanup using existing shared logic rather than duplicating checkout business policy.


## 21. User directive: checkout ends at EXISTING LOCAL HANDOFF TO CALL CENTER (2026-10-25)

**OVERRIDES all earlier wording suggesting direct RESTIS/restaurant routing or requiring call-center infrastructure research.**
User explicitly clarified: the order travels `СамЗаберу -> V5 API -> Bitrix -> existing transmission to CALL CENTER -> restaurant`. **Do NOT access, audit, duplicate or change call-center / restaurant servers or internal routing. No such access exists or is needed.** Our responsibility ends at locally verified handoff from Bitrix into its already installed transmission mechanism. This is NOT proof of final delivery or call-center processing and should never be described as such.

Stable source facts (user/operator read-only PASS):
- `CartApiService::orderCreatedEvent()` executes two scoped DELETEs: `B_API_BASKET` (by FUSER_ID) and `BASKET_CUSTOM_GIFTS` (second WHERE key still unknown). It cleans the API cart/gifts, **does not send a call-center message**.
- `Cart::order()` sets `IGNORE_RESTIS=Y`; `COrder::orderSaveHook()` skips standard RestIS send when Y. Do not turn off this flag or introduce a new V5 RESTIS sender to bypass it. Existing local Bitrix-to-call-center acceptance must be confirmed at the Bitrix side only.
- New `V5PickupLocalCallCenterHandoffProofPort` in `src/V5BitrixLockedPickupOrderWriter.php`, commit `acaee760e050b91348c7b6814ba5cd9ed503afbe`. V5 writer verifies acceptance after NEW Sale D7 save but **before cart cleanup and persisted successful receipt**, inside its still-owned native transaction. Proof is intended to READ existing local Bitrix acceptance only; must never call outside servers or enqueue a duplicate. Replay bypasses proof/save/cleanup. Failure denies success and the strong session wrapper rolls back if transaction continuity holds.
- Mock tests update commit `5cc9f8c182e09747da9b48f379cdff1eb6ca0005`: verifies the new proof is mandatory, called once on new order, skipped on replay, rejection prevents cart wipe and returned receipt. GitHub Actions `40211268485` **SUCCESS**, 146/146 MOCK-ONLY: HTTP 16 + D7 builder 24 + business 18 + writer 29 + native materializer 23 + fresh basket 24 + site policy 12.
- A concise owner-focused contract and *only local* release gates: `docs/SAMZABERU_V5_ORDER_CALL_CENTER_BOUNDARY_2026-10-25.md` (commit `73dcca5e53fb32cf228349ec4feef0cd89a0cdb9`). It is intentionally not an operational integration specification for call center.

Release conditions **still NOT met**: implement/verify local Bitrix handoff proof; exact `BASKET_CUSTOM_GIFTS` cleanup WHERE key; one physical Bitrix D7/$DB/PDO bridge transaction (strict continuity); durable V5 request ledger DDL not deployed; real guest end-to-end acceptance not tested. V5 AUTH OFF, V4 unchanged, no production writes, no real orders or call center calls. No guessing / no synthetic users. Focus on finishing these **local** gates rather than more call-center / RestIS infrastructure discovery.


## 22. Local Bitrix-to-call-center boundary + exact pickup gift cleanup predicate reader (2026-10-25)

Owner's instruction remains STRICT: **only** confirm V5's existing LOCAL Bitrix handoff into the call-center channel. Once Bitrix has submitted/registered it in the installed path, do NOT inspect call-center servers, queues or further delivery to restaurants. Never demand access to call-center infrastructure. The route is SamZaberu -> V5 -> Bitrix -> existing call-center channel -> restaurant. `IGNORE_RESTIS=Y` in V4 is verified; no speculative direct RestIS HTTP writer.

Development completed in feature branch (no production changes):
- `src/V5BitrixLockedPickupOrderWriter.php` at `acaee760e050b91348c7b6814ba5cd9ed503afbe`: new **mandatory read-only** `V5PickupLocalCallCenterHandoffProofPort`, called only for NEW order after native D7 save and before cart cleanup and receipt ledger. Does NOT contact or enqueue anything itself. A replay bypasses proof; failing proof prevents clearing basket / reporting success and requires full transaction rollback. Explicitly **no site implementation yet** until exact LOCAL acceptance condition is verified.
- `tests/V5LockedPickupOrderWriterTest.php` at `5cc9f8c182e09747da9b48f379cdff1eb6ca0005` tests proof before cleanup, no proof on retry, proof rejection prevents receipt and cleanup. GitHub Actions `40211268485` **SUCCESS**, 146/146 mock-only assertions (HTTP 16, D7 24, business 18, writer 29, materializer 23, basket 24, site policy 12).
- User-facing contract/limits are written in `docs/SAMZABERU_V5_ORDER_CALL_CENTER_BOUNDARY_2026-10-25.md` commit `73dcca5e53fb32cf228349ec4feef0cd89a0cdb9`. This replaces any older instructions implying direct restaurant/RestIS integration.
- Existing source-only operator stdout verified DELETE of `B_API_BASKET` by FUSER_ID and DELETE of `BASKET_CUSTOM_GIFTS` by **unknown second WHERE key**. **No runtime SQL executed.** A minimal source-only reader in `tests/diagnostics/V5CheckoutPostSaveSqlScope.php` now extracts only `QUERY_1_WHERE_IDENTIFIER`, `QUERY_2_WHERE_IDENTIFIER`, and `COMPOUND_WHERE` yes/no, never SQL or values. Pinned parser commit `6351a4f61ee084ec195d163846791053ee3f47e5`, Git blob `00eedfa42596f5c6b3aa520cc53c1fe4483f8306`; runner `tests/diagnostics/run_v5_checkout_postsave_sql_scope_readonly.sh` commit `4860bea3a7c40c1e34733f7103c488b8a0292e95`, Git blob `1a4bf00e440a93dc876b0a01bb53a49f45813156`. GitHub Actions `40211394841` **SUCCESS**, pinned parser and Bash self-tests, NOT live database coverage.
- Next action: run exactly that pinned read-only runner on `root@evrasia` to obtain the missing `BASKET_CUSTOM_GIFTS WHERE` field, then match native V5-only cleanup policy to precise V4 behavior. Do not probe call-center internals. Do NOT wire native checkout/ledger until same-physical-transaction continuity, safe order-save side-effects, and existing **local Bitrix-to-call-center handoff** are verified using installed site code. V4 untouched, V5 AUTH OFF, no production writes, no synthetic users, no order/payment/SMS.


## 23. V5 native checkout two-table cleanup implemented feature-only; exact WHERE keys confirmed (2026-10-25)

User supplied exact production SOURCE-ONLY PASS `V5_POSTSAVE_SQL_SCOPE=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`, V4 unchanged, V5 AUTH OFF, no DB operations. The **confirmed two actual V4 CartApiService::orderCreatedEvent() statements** have targets and sole predicates:
- `DELETE FROM B_API_BASKET WHERE FUSER_ID = ?` (service-held FUSER).
- `DELETE FROM BASKET_CUSTOM_GIFTS WHERE UF_FUSER_ID = ?` (same service-held FUSER).
Both are single WHERE comparisons, no compound WHERE. They only clear local cart/gifts; no RestIS/call-center submission. **Further SQL source audits of these tables are not needed.**

Feature branch changes, still **NOT INSTALLED**:
- `src/V5BitrixNativePickupCartCleanup.php`, commit `87c4e563ee79776afea338fa9542a5a15add56e3`, is the first *concrete* `V5NativePickupCartCleanupPort`. Takes ONLY the V5 strong-session-verified, exclusively locked `FUSER_ID`; uses the original `V5BitrixPdoCompatibilityBridge`, no second PDO or global Bitrix `$DB`. Sends the two exact `DELETE ... WHERE ?` templates via native connection and checks strict transaction continuity before each / after each; no independent BEGIN/COMMIT and no call-center network call.
- `src/V5BitrixPdoCompatibilityBridge.php`, commit `989fb417d485a78fa7780364055db8f083699bf5`: exact-only SQL allowlist now also contains the V5 checkout request-ledger three existing templates and these two exact V4-verified DELETE templates. Added `assertOwnedCheckoutTransactionContinuity()`: **requires** FPM V5 verified ingress, strict native continuity mode, currently bridge-owned physical Bitrix Application connection, active native savepoint continuity, unpoisoned scope. No generic DELETE or generic table SQL permitted. The CLI bridge mock cannot satisfy this release proof.
- `tests/V5BitrixPdoCompatibilityBridgeTest.php` updated (last corrected source commit `5cec641ec3503d1675af98b25b1cd309917428c0`). Tests direct fake-native exact DELETE templates and same-transaction rollback, rejects wrong WHERE keys/unscoped DELETE/other tables, requires specific ledger SQL, and verifies that the concrete cleanup port FAILS CLOSED under CLI/non-strict bridge and bad IDs. Mock-only. Previously a syntax error in the newly added test was corrected before acceptance.
- `.github/workflows/v5-checkout-source-only.yml`, commit `4a242819d62c2a785ed3722844177d138df2bf98` includes new source/lint/bridge fake test. **GitHub Actions SUCCESS run `40211769954`** after test syntax correction: checkout sources remain **146/146 PASS**, native bridge test **147 PASS**. Those are separate fixture-only assertion groups (sum 293); NOT a real Bitrix Sale D7+PDO+native `$DB` end-to-end transaction proof.
- `V5BitrixLockedPickupOrderWriter.php` documentation corrected at `824a87ecead9d267c0d09e0193a2616ab807213d` to reflect exact `UF_FUSER_ID` and existing feature-only cleanup class. Writer still requires separate **read-only** proof of local Bitrix-to-call-center acceptance prior to cleanup. The user's explicit boundary is ONLY Bitrix's handoff to EXISTING call-center channel; do NOT inspect, request access to, or change call-center/restaurant infrastructure or create a new outbound transport.
- No table/schema deployment, no production code changes, no real order creation, no SMS/payment, no V4 changes; V5 AUTH OFF.

**Remaining release gates:**
1. Confirm one *physical* connection/transaction for native Bitrix Sale::save, existing Bitrix $DB, V5 strong-session bridge/ledger and two cleanup deletes; verify no hidden commit/event side effects using native guarded savepoint with a separately authorized test; currently NOT TESTED on real Bitrix.
2. Determine proof of acceptance by the **existing local Bitrix-to-call-center handoff**, without touching call-center servers. Port not yet wired; fail closed.
3. Site-only verified `NOTES` value, `source`, `happytime`; user-on-pickup policy and existing properties; checkout ledger DDL proposed but NOT installed. Real user E2E only after explicit approval. No synthetic users.

**Do not issue another cart table/source inspection script:** the two predicates and SQL shapes are now confirmed. Focus only on transaction and local Bitrix checkout acceptance readiness; leave V4 independent.


### Checkout cleanup operational readiness update (2026-10-25)

- Feature-only `src/V5BitrixNativePickupCartCleanup.php` adds **fail-closed metadata engine checks** for `B_API_BASKET` and `BASKET_CUSTOM_GIFTS` before executing either DELETE: both must be installed InnoDB tables in `eurasia_new`. This prevents assuming custom gifts support rollback. Commit `ad9bdaf0159983d3c3500ee4d658f0847fe2ebe0`.
- Latest GitHub Actions checkout suite **SUCCESS `40211875189`**. PHP lint and all checkout mock assertions PASS; native bridge 147 assertions PASS. The bridge tests permit only the two exact V4 verified DELETE templates and original 3 V5 ledger templates; wrong FUSER field, unbounded delete, SQL predicate broadening and missing transaction fail; fake native rollback restores both basket and gift rows. The actual cleanup port rejects nonstrict CLI bridge/invalid identity.
- Updated project-local checkout/call-center boundary document at `docs/SAMZABERU_V5_ORDER_CALL_CENTER_BOUNDARY_2026-10-25.md`, commit `f2db77913d82269569a1d1d7422d9ebf5eba7cfe`, confirms exact two DELETE keys and no need to probe call-center internals.
- Remaining release gates unchanged: *REAL* Bitrix Sale D7 save + PDO bridge + Bitrix $DB transaction/savepoint continuity, local Bitrix acceptance into existing call-center channel (not downstream delivery), verified configuration values and user-approved restricted live checkout. V5 AUTH OFF, V4 untouched, no real order, no DB DDL nor production changes.


## 24. Checkout SQL privilege isolation tightened — diagnostic native bridge cannot delete basket (2026-10-25)

IMPORTANT follow-up to section 23. The earlier positive fake native SQL tests ran the two V4 DELETE templates inside a **non-strict CLI** V5 bridge. Although the actual production cleanup class itself checked strict FPM transaction continuity, the native bridge had allowed the exact checkout SQL shapes to run under diagnostic mode. That was unnecessary privilege exposure. **Corrected in feature branch, production untouched.**

- `src/V5BitrixPdoCompatibilityBridge.php`, commit `e0bce5f2b9d87644571cd988d5c815f87aa4ccb7`: both `prepare()` and public `runStatement()` call `assertCheckoutSqlScope()` for all three checkout ledger SQL templates AND both exact cleanup DELETE templates. The new boundary requires `PHP_SAPI=fpm-fcgi`, verified V5 strict HTTP ingress, `requireNativeContinuity`, currently opened/unpoisoned **same** Bitrix Application native transaction, and armed native savepoint guard. Therefore a CLI/non-strict bridge CANNOT prepare or execute any checkout SQL, even if it calls beginTransaction(). It also denies direct `runStatement()` calls. Existing noncheckout auth SQL allowlist behavior is unchanged.
- `tests/V5BitrixPdoCompatibilityBridgeTest.php` updated at `698446d517a2230773f0d104d9388d09815f9514`: verifies exact SQL templates through isolated static shape gate, then asserts ALL five are refused by diagnostic CLI bridge before and after native mock BEGIN; direct `runStatement()` bypass refused; neither cart nor gifts mutated; invalid DELETE scope refused; existing concrete cleanup port likewise refuses CLI and bad identities. No positive production execution attempted. **Do NOT cite earlier fake-positive two-delete/rollback test as current coverage**; its positive execution was intentionally removed to enforce least privilege.
- GitHub Actions `40212422172` **SUCCESS**: 146/146 isolated V5 checkout contract assertions and 158/158 native bridge/mock assertions = **304/304**. The first intermediate run `40212394914` failed because the tests still expected diagnostic SQL to succeed; updated tests and reran successfully.
- Open launch gates: **real** native Sale D7 save+PDO bridge+`$DB` savepoint/transaction E2E, installed site local Bitrix handoff acceptance into existing call-center transmission (no downstream inspection), site-owned NOTES/source/happytime values, deployment of ledger schema only upon explicit approval, real account pilot only upon explicit user approval. No call-center server access, no real order or payment, no prod changes, API V4 untouched, V5 AUTH OFF.

### Final exact checkout-SQL template cross-file consistency (2026-10-25)

- Commit `9d437d985b34b4afae4b918cc6bb8e3748847df6` adds five explicit cross-source assertions: both `V5BitrixNativePickupCartCleanup::DELETE_*` and all three `V5BitrixLockedPickupOrderWriter::INSERT/LOCK/COMPLETE` SQL templates exactly match the native bridge's source-only allowlist. This avoids accidental future divergence and ensures CLI never executes checkout SQL.
- GitHub Actions `40212530778`: **SUCCESS**, checkout 146/146 plus native bridge 163/163 = **309/309 MOCK-ONLY PASS**. Checked that no checkout ledger or cart deletion can be prepared or directly run in diagnostic bridge with or without its own transaction. The strict FPM-native path still has no real Bitrix E2E approval.
- No production deployment/DDL/order/SMS/payment, no V4 changes. V5 AUTH remains OFF. Do not study call-center systems; acceptance boundary remains existing LOCAL Bitrix handoff only. Further development must address independently approved real native Sale D7 transaction consistency and local handoff policy, not repeat cart SQL source checks.


## 25. Durable checkout replay survives erased FUSER, native continuity checked after final ledger write (2026-10-25)

**User instruction remains binding**: SamZaberu -> V5 -> Bitrix -> EXISTING call-center channel -> restaurant. Never inspect, modify, probe or require access to call-center/restaurant servers. Scope stops at the local Bitrix side of the existing transmission.

Fixed a real correctness flaw in the source-only V5 checkout writer:
- The original `V5BitrixLockedPickupOrderWriter::placeLockedOrder()` acquired a current FUSER ownership record **before** looking up an already saved idempotency receipt. But checkout deletes the basket and a FUSER row could later disappear; then a retry with the original identical `request_id` would fail rather than returning the durable saved order.
- At final commit `0b6844894b8a512d5b2ea5c1f48b3035a063e8a1`, writer first performs exact `SELECT ... FROM ev_v5_pickup_checkout_requests ... FOR UPDATE` under the strong-session's already-locked user. Existing `STATE=saved` and matching input hash => return identical saved receipt WITHOUT any FUSER lookup, new Sale save, local-handoff proof, or cart cleanup. Bad hash / invalid saved row => fail closed. If request is genuinely new/pending, resolve and lock the unique verified FUSER **before** inserting the pending request ledger row, then proceed with the existing saved-order flow. This avoids creating even an uncommitted pending marker on a new request with an invalid/absent FUSER (important even for mocks and transient failures). The extra ledger pre-read uses an ALREADY EXACT-ALLOWLISTED SQL template, no widening of native bridge or new schema.
- Final ledger `COMPLETE` UPDATE is followed by `$this->cleanup->assertNativeTransactionContinuity($db)` before the writer returns. The outer `V5MySqlStrongSessionStore::transaction()` still owns COMMIT, validates its strong-session savepoint, and must COMMIT successfully **before** returning the receipt to HTTP. A lost transaction after the final ledger write denies success; callback exception requires full caller rollback. No pre-commit response.
- Tests `tests/V5LockedPickupOrderWriterTest.php` extended in `b86084e89fa5c55c943cd93a9be6e4540c7d090c`: replay with `Owner::deny=true` still returns exactly the original receipt and never touches FUSER/cart/order/handoff; strict validation of new-order invalid FUSER; mock-controlled final native guard failure immediately AFTER ledger COMPLETE denies receipt and requires outer rollback.
- Intermediate CI runs `40212746180` and `40212779251` failed because the first reordering issued an uncommitted pending ledger INSERT before checking the new order's FUSER in the fake mock. This was corrected by the pre-INSERT ledger read / FUSER verification above. **Final GitHub CI `40212849767` SUCCESS**: V5 HTTP 16, native D7 24, business 18, writer **34**, materializer 23, fresh basket 24, properties 12 = **151 checkout** checks; native compatibility bridge 163 = **314/314 MOCK-ONLY PASS**. No real Bitrix, no DB writes, no V4 changes and V5 AUTH remains OFF.

Remaining real release gates are UNCHANGED: native Bitrix Sale D7/strict bridge/`$DB` physical same-transaction and savepoint continuity on installed site; precise local acceptance into existing Bitrix-to-call-center transmission without any downstream inspection; unknown site-owned trusted `NOTES`, `source` and `happytime` values; V5 ledger DDL not deployed, V5 AUTH not enabled; no user-approved real guest order test. Do not claim offline tests prove these. No more cart SQL inspections needed.


## 26. Source-only persisted native Sale row verification before accepting V5 checkout (2026-10-25)

Owner's route requirement UNCHANGED: SamZaberu -> V5 -> Bitrix -> EXISTING LOCAL channel to call center -> restaurant. Never inspect call-center servers; V5 responsibility ends at the existing Bitrix-side channel boundary. Local order row persistence alone does NOT certify that channel accepted the order.

**New feature-only code (production untouched):**
- `src/V5BitrixLockedPickupOrderWriter.php`, commit `b42771d6235448a8b7f4a307275f91987ae01f37`: new mandatory injectable `V5PickupSavedNativeOrderProofPort`, called after native `Sale\\Order::save()` and strict transaction-continuity check, **before** `V5PickupLocalCallCenterHandoffProofPort` and **before** cart/gifts DELETE. This closes a gap in which D7 save/event hooks might persist paid/cancelled/altered order state while the in-memory PHP `Order` object still looked unpaid. It is intentionally a SEPARATE proof port from local call-center acceptance: order persistence alone cannot confirm dispatch.
- `src/V5BitrixNativePickupSavedOrderProof.php`, commit `a424b457940b77f54b7c345a6bf0646d44abbc62` and further pure row-policy refinement `52f92a9ddc7fbbb7f9d3420dec6cf11c41a4e200`: strict native V5 bridge only. Runs exact read `SELECT ID, USER_ID, ACCOUNT_NUMBER, STATUS_ID, PAYED, CANCELED FROM b_sale_order WHERE ID=? AND USER_ID=? LIMIT 2 FOR UPDATE` on same owned Bitrix transaction. Only matching one row, same user, same account number/status N/P, unpaid PAYED=N and not cancelled CANCELED=N passes; anything else fails closed. Strict native continuity checked before and after SELECT. No new DB connection; no real order/test in prod.
- `src/V5BitrixPdoCompatibilityBridge.php` exact checkout-specific allowlist extends only this SELECT (commit `7d0a21df5670958de682f74762c56663b0220483`), and its `assertCheckoutSqlScope` requires strict FPM V5 native transaction for BOTH prepare and execute. Source-only bridge tests confirm the SELECT is *refused* in CLI even if a diagnostic transaction is started; unscoped/select-without-FOR-UPDATE variants rejected.
- `tests/V5LockedPickupOrderWriterTest.php` final update `3c6787d7f9b6121fa5cf5b6f69f3ffc43ec7ae0a` mocks persisted proof and verifies before-handoff/cleanup order, fail-closed mismatch, no cleanup or handoff on failure, replay skips all additional proofs; source-only transaction continuity counts updated.
- `tests/V5BitrixPdoCompatibilityBridgeTest.php` commit `02e42db8bb43fcfec7a4dd434fff98039c34c18f`: pure positive and negative persisted row predicate fixtures (paid, cancelled, wrong user/ID/number/status), strict SQL template exactly matches source, no CLI native DB query.
- CI workflow lint includes new source, commit `c945faf8e5ed0f35ccfb676f468ccd0a278a80b2`.
- **GitHub Actions `40213227241` SUCCESS, final 342/342 MOCK-ONLY checks** (156 checkout + 186 bridge). Early workflow failures occurred during adding a mandatory port and adjusting mock counts; final run is successful. This is **NOT real Bitrix E2E or proof of acceptance into call center**.
- Updated owner-facing scope document `docs/SAMZABERU_V5_ORDER_CALL_CENTER_BOUNDARY_2026-10-25.md` commit `0367b87d9d0f3f1a5e5d6eab5938c552ad8d7039`; clarified that `BASKET_CUSTOM_GIFTS` WHERE is actually verified `UF_FUSER_ID`.

**Remaining release gates (do not claim complete):** Real single physical Bitrix Sale D7 / strict native bridge / V5 ledger transaction and event side effects on installed site; positive proof of submission into existing LOCAL Bitrix-to-call-center channel (no need for call-center access); trusted site exact NOTES/source/happytime values; V5 ledger DDL proposed but not deployed; explicit user approval before a real-user real-order pilot. V4 unchanged, V5 AUTH OFF, production unchanged, no synthetic accounts, no payments, no SMS.


## 27. Checkout cleanup must delete real API cart rows, and targeted native Sale save transaction source probe (2026-10-25)

**Owner's approved scope unchanged:** API V5 -> native Bitrix -> existing LOCAL handoff into call center. No access, probing or changes to call-center / restaurant systems. V4 untouched, V5 AUTH OFF, no real order, no payment/SMS, no production writes.

**Feature-only checkout code hardening:**
- `src/V5BitrixNativePickupCartCleanup.php` commit `0c934617262f0386e8348f513ae618154918ac95`: after each native strictly scoped delete, check `rowCount()`. NEW checkout must delete **at least one** `B_API_BASKET` row for verified FUSER (else fail closed / caller must rollback Sale save+ledger+cart); optional `BASKET_CUSTOM_GIFTS` may remove zero records. Implausibly huge/negative counts or unrecognized SQL fail closed. This prevents falsely reporting newly created checkout when its supposed API cart was already absent at cleanup. Does not change exact V4 DELETE templates or introduce external dispatch.
- `tests/V5BitrixPdoCompatibilityBridgeTest.php` commit `96b9b9c45f645b12fc7baefbf80cf996b27f76e7`: tests cart>0, gift>=0, zero-cart rejection, invalid SQL/count rejection. **GitHub Actions run `40213474006` SUCCESS**: checkout 156/156 and fake bridge 195/195 => **351/351 mock-only PASS**. No actual Bitrix order/DB operations were run.
- New highly targeted read-only Sale transaction *source* trace (no code execution, DB access or order created): `tests/diagnostics/V5SaleSaveTransactionSourceTrace.php` at commit `211c1458a11d45c26f8d2ac2d17deb3a7636002f` blob `ac6113c5cf1d8f51715fcb18ccf5dd3d468e6140`. Tokenizes installed `bitrix/modules/sale/lib/order.php` and `orderbase.php` solely to report direct `save()` method presence and allowlisted native transaction/event method name call counts. Never outputs PHP source, string values, SQL, secrets, customer info. **This is only source evidence, NOT a proof that all transitive D7/event/hook writes share one physical transaction.**
- Pinned operator runner `tests/diagnostics/run_v5_sale_save_transaction_readonly.sh` commit `f8fa345a9ce78169d33b75aefdc1d7f17b462212` Git blob `8ec0ad4adf4ce90b30718673bf7961557e11104b`. Runs on `root@evrasia`, guards installed V4 Cart and router SHA-256 and exact private V5 AUTH OFF JSON, reads installed Sale PHP sources only; downloads hash-pinned PHP tokenizer, runs syntax+selftest; no installed source executed, no Bitrix bootstrap/DB or real order. GitHub CI workflow `v5-sale-transaction-source-only.yml` at commit `56f3091f01a712ff8243e56036f31d49ef313b06`, **run `40213602488` SUCCESS**.
- Next: user runs ONE pinned operator source-only Sale transaction runner and returns stdout. Interpret direct `Order::save()` vs `OrderBase::save()` transaction evidence. If delegated or unknown, do NOT infer physical transaction continuity. Only a separately approved native non-destructive/isolated runtime proof can release actual checkout. In parallel, local Bitrix call-center acceptance proof remains **UNIMPLEMENTED**, and site-only `NOTES/source/happytime` remain unverified; do not try to investigate call-center servers. Durable ledger DDL not deployed.


## 28. Installed Bitrix Sale::save has no DIRECT transaction calls; delegation shape isolated (2026-10-25)

**Accepted operator result** on real `root@evrasia` from pinned `run_v5_sale_save_transaction_readonly.sh`:
- `V5_SALE_SAVE_TRANSACTION_SOURCE=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`. Bitrix bootstrap=NO, source code execution=NO, DB connection/write=NO, V4 unchanged, V5 AUTH OFF.
- Installed `bitrix/modules/sale/lib/order.php`: `SOURCE_ORDER_SAVE_DEFINED=YES`; 143 method-body tokens; `DIRECT_CALL_SAVE=1`; direct calls to startTransaction, beginTransaction, commitTransaction, rollbackTransaction, commit, rollBack: **ALL ZERO**.
- Installed `bitrix/modules/sale/lib/orderbase.php`: `SOURCE_ORDERBASE_SAVE_DEFINED=YES`; 591 method-body tokens; `DIRECT_CALL_SAVE=1`; all direct transaction terminal/start method calls **ZERO**. Do **NOT** infer that `Sale::save()` preserves the V5-owned native transaction: the call to `save` is still delegated and indirect event/manager callbacks may write or commit. The `NATIVE_TRANSACTION_E2E` field remains `NOT_TESTED`.
- Scope explicitly stops at Bitrix's **existing local handoff to call center**. User has no access to and does not want research of call-center/restaurant systems.

**One next source-only delegation clarification** (no repeat of prior full source audit):
- Source parser `site-integration/samzaberu-v5/tests/diagnostics/V5SaleSaveDelegationTrace.php`, commit `60a34f22bd313a667c90e1a34a8d03ee1bfbe9ce`, Git blob `0172f1aef686e945453dd93238d9d7c4c3a7cbc2`. In `Order::save` and `OrderBase::save`, reveals only vetted *receiver category* (THIS, PARENT, SELF, STATIC or OTHER), method name (strict allowlist: save/saveInternal/getConnection/start/commit/rollback/etc), and call count. No raw PHP lines/literals, usernames, SQL or secrets, no bootstrap/eval.
- Server runner `site-integration/samzaberu-v5/tests/diagnostics/run_v5_sale_save_delegation_readonly.sh`, commit `a9314d6186ea2b4edfac664a739f4f9e09a4181b`, Git blob `0feff4079097df461e0cce06cbec04e95d083079`: pinned source-only parser, V4 SHA and private V5 OFF guards, exact installed Sale PHP files. No production writes, no real order.
- CI workflow `v5-sale-save-delegation-source-only.yml`, final commit `419266a97a598f83ed46f71504741fe8827d4e7f`. **GitHub Actions `40213874973` SUCCESS** PHP lint+selftest + Bash lint. Initial workflow `40213834940` failed because `php -n` disabled tokenizer on GitHub runner; fixed workflow/runner to permit built-in tokenizer while clearing auto_prepend_file/auto_append_file, final green.
- **Operator next:** run ONLY the hash-pinned new wrapper, return `SAVE_ORDER_RECEIVER` / `SAVE_ORDERBASE_RECEIVER` and method counts, assess exact delegated save. Do not claim physical shared transaction complete from source-only shape. A live isolated savepoint continuity test or formally verified native transaction behavior still gates checkout. Do NOT create real order, deploy V5, modify V4, enable auth, send SMS/payment.


## 29. Owner's exact installed Bitrix Sale save delegation evidence and one final narrow receiver trace (2026-10-25)

**Accepted user/operator read-only output** from pinned real `root@evrasia` runner:
`V5_SALE_SAVE_DELEGATION_SOURCE=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`, mode SOURCE_ONLY_READ_ONLY, V4 unchanged, V5 AUTH OFF, no site source execution, Bitrix bootstrap, DB connection or real order.

- Installed `bitrix/modules/sale/lib/order.php`, `Order::save()`: `SAVE_ORDER_CALL_SHAPES=1`, `SAVE_ORDER_RECEIVER=PARENT METHOD=save COUNT=1`, no direct transaction terminals. Thus this override calls its parent save.
- Installed `bitrix/modules/sale/lib/orderbase.php`, `OrderBase::save()`: `SAVE_ORDERBASE_CALL_SHAPES=1`, `SAVE_ORDERBASE_RECEIVER=OTHER METHOD=save COUNT=1`, no direct transaction terminals. The **actual identity** of OTHER receiver and possible nested side effects remained unknown as of this operator output. DO NOT assume it is Basket or any other object, nor that the chain preserves the same physical transaction; `NATIVE_TRANSACTION_E2E=NOT_TESTED`.
- Product owner boundary remains: **SamZaberu -> V5 -> Bitrix -> existing local call-center handoff**. Do NOT inspect call-center / restaurant servers; no access required.

The only targeted follow-up is a static source-only CLASSIFIER for the **single `OTHER->save()` call site** in installed OrderBase::save(), plus optional last local assignment of its receiver. The classifier masks arbitrary source identifiers and all literal strings/numbers; emits only allowlisted method names/variable labels and expression shape:
- `tests/diagnostics/V5SaleSaveReceiverSourceTrace.php`, commit `754124399b4bd872d7e917bf5295ec8a338bec49`, Git blob `60335287366d40ce4f275da330c1ed80c2e27c0d`. PHP tokenizer, no require/eval, Bitrix bootstrap or SQL.
- Hash-pinned server runner `tests/diagnostics/run_v5_sale_save_receiver_readonly.sh`, commit `abe118d7c5d6ade1725685e34b6824d4bfd9672d`, blob `a7ed83cd20f29d5baa8d54acb3266080c155d4ef`, checks production hostname root, installed OrderBase source and exact V4 Cart/router hashes and V5 private OFF JSON before and after, parser self-test and SHA integrity.
- Updated workflow `.github/workflows/v5-sale-save-delegation-source-only.yml`, commit `45ba8c7795a6927c73a776d845de82e1859b5c08`. **GitHub Actions run `40214209403` SUCCESS**, PHP lint, redaction self-test, bash -n.
- Await user stdout: `ORDERBASE_SAVE_OTHER_RECEIVER_KIND`, `ORDERBASE_SAVE_OTHER_RECEIVER_SHAPE`, `ORDERBASE_SAVE_OTHER_LAST_ASSIGNMENT_SHAPE`. If identifier masked or unresolved, do NOT invent a class/type. Only then determine whether a bounded further check is worthwhile; avoid repetitive broad source scans. This is source structure only, **not** proof of native Bitrix Sale order transaction continuity.

No feature runtime changes, no deployment, DB schema, real orders or payments, no user-created accounts, no call-center network calls, V4 unchanged, V5 AUTH OFF.


## 30. Owner explicitly redirected from endless Sale::save investigation to API V5 IMPLEMENTATION (2026-10-25)

**Binding owner decision:** "дальше, давай к реализации v5". STOP repeated read-only `save()`/RESTIS/call-center research. Product order route: SamZaberu -> V5 -> native Bitrix Sale -> **existing LOCAL channel to call center** -> downstream restaurant. Do not access or explore call-center servers. Use existing V4 business services/policy without V4 auth/JWT/controller or production code changes.

Last accepted source-only operator output (retained for honesty, not another investigation):
`V5_SALE_OTHER_SAVE_RECEIVER=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`, `ORDERBASE_SAVE_OTHER_RECEIVER_KIND=LOCAL_VARIABLE`, `ORDERBASE_SAVE_OTHER_RECEIVER_SHAPE=$VAR`, `ORDERBASE_SAVE_OTHER_LAST_ASSIGNMENT_SHAPE=$this -> ID ( )`, no site execution/DB writes. The method name was redacted, **so the actual delegated class/transaction behavior remains unverified**; NEVER claim source-only checks prove native physical transaction atomicity. No follow-up parser needed unless specifically requested.

### Concrete API V5 implementation, in feature branch only

- **New** `site-integration/samzaberu-v5/src/V5NativePickupCheckoutComposition.php`, commit `7b2621dbf32a7f71292d941e39e7fa8c20440a7e`. Real object factory for the already authored independent V5-only checkout route `POST /api/v5/cart/order`:
  - `V5BitrixLockedCartFuserOwner`;
  - `V5BitrixNativePickupMaterializer` with `V5PickupBasketNativeFieldPolicy` + `V5PinnedPickupPropertyPolicy`;
  - `V5BitrixCheckoutBusinessRules` from explicit site values;
  - `V5NativePickupSaleD7Saver(new V5BitrixSaleD7OrderBuilder())`;
  - `V5BitrixNativePickupSavedOrderProof`;
  - `V5BitrixNativePickupCartCleanup`;
  - required, externally/site-supplied `V5PickupLocalCallCenterHandoffProofPort`;
  - `V5BitrixLockedPickupOrderWriter`, with preserved request-id receipt/replay and guarded transaction/cleanup.
- `V5NativePickupSiteConfiguration` is explicit validated immutable **server-owned** values: pay-on-pickup system ID, dispatch type list, time-check failure key, exact basket NOTES, source, happytime. No fake defaults or app-controlled properties. `V5NativePickupSiteReleaseEvidencePort` **mandatory** and not implemented in production: must confirm actual site-local readiness of native Sale transaction + existing Bitrix-to-call-center handoff. Factory `buildForStrictHttp(...)` refuses CLI, generic PDO, missing strict `fpm-fcgi` V5 native ingress, active native transaction during construction, denied/unknown/error site attestation. It cannot deploy or enable the site.
- Existing `V5MySqlPublicComposition::buildEmailFirst` / `build`, as well as pilot composition, already accept optional `checkoutWriter`; the concrete factory's output fits this injection, and existing V5RouteMap + V5MySqlOrderPlace + V5AuthHttpHandler provide the HTTP route. **No new router needed and no public route activated.** Real site composer/attestations must be supplied separately before live checkout.
- **New** `tests/V5NativePickupCheckoutCompositionTest.php`, commit `334f595ae1c63b29ec9d7037eb2d572f652f7743`: checks exact reflection wiring of all 7 real native port types, V4 IGNORE_RESTIS=Y property, trusted field sourcing, no DB or external sends, CLI rejection occurs before injected site-release mock can grant approval, invalid site policy rejected. No bootstrap.
- Checkout GitHub CI workflow updated `db55c5a5df713cae50b15532759905a803930c1b`. **Run `40214572165` SUCCESS**: HTTP 16 + Sale D7 24 + business 18 + writer 39 + native materializer 23 + fresh basket 24 + property 12 + native SQL bridge 195 + new composition 24 = **375/375 MOCK-ONLY PASS**. These tests are NOT a live Bitrix order.
- **New architecture/how-to document** `docs/SAMZABERU_V5_NATIVE_CHECKOUT_COMPOSITION_2026-10-25.md` commit `a8f277c619c835b415ae5d12c937a5182ec14968`; explains actual V5 object graph and how site can inject into existing V5 route *after* approval.

Next real development priority: continue implementing native V5 **feature functionality** and contract/diagnostics for independently deployable API, not broad transaction archaeology. Production remains untouched, V4 independent, V5 AUTH OFF, no real orders/accounts, no SMS/payment/call-center network actions. Remaining true release gates: proven physical native transaction & Sale side effects; independent positive site-only acceptance marker into existing local call-center channel; exact trusted site fields and offline request-ledger DDL not installed; owner-approved real guest pilot. Never misrepresent PASS mock as deployment/readiness.


## 31. API V5 mobile cart quantity/remove routes IMPLEMENTED FEATURE-ONLY (2026-10-25)

Following owner's instruction to prioritize implementation over repeated Bitrix `save()` archaeology, V5 has advanced beyond its existing `GET /cart`, `GET /orders`, `POST /cart/order`: two **new V5-only, strong-authenticated existing-item cart mutation actions**. No V4 code/router was modified, no production deployment or DB writes, V5 AUTH remains OFF.

**Routes** in `src/V5RouteMap.php` (commit `c803724fa06e92693cad684c1628a2b715722117`):
- `POST /api/v5/cart/items/quantity`: exact JSON `{"access_token":"<V5 JWT>","item_id":401,"quantity":2}`, integer quantity 1–1000.
- `POST /api/v5/cart/items/remove`: exact JSON `{"access_token":"<V5 JWT>","item_id":401}`.
- `item_id` is existing native Bitrix **basket-line ID** from `GET /cart items[].id`, NOT a product/catalog ID or FUSER_ID. No add-new-item route yet. No client price, user ID, FUSER ID, currency, payment parameters.

**New/updated feature code:**
- `src/V5CartMutationRequest.php` commit `8aae27433abc18ce77f80541d931d26a46eb1a24`: exact allowlist-only request DTO for set/remove, bounded ID/count, no extra fields.
- `src/V5MySqlStrongSessionStore.php` commit `cf3625e4991fd29b9f0e4baefcb1690abbaa0f42`: `V5LockedCartMutatorPort` + `changeCartForAccess()` executes under the existing signed V5 strong JWT, locked b_user, current epoch/security native coverage, strong session and guarded transaction. Output true only AFTER successful outer transaction COMMIT; null if auth/assurance rejected; false if native save/transaction failed and rolled back.
- `src/V5MySqlCartMutation.php` commit `59a510014b9729e57a4ba7bada8596603119e4c6`: V5 session to native mutator bridge, no V4 controller.
- `src/V5AuthHttpHandler.php` commit `aedc0dbdfaf91680139b7db6d9fdbee45bf2bbe2`: explicit optional `V5CartHttpMutationPort`, strict JSON/JWT, stable 200 success, 400 invalid, 401 auth, 503 unavailable/failed. Constructor optional at end preserves existing clients and OFF mode.
- `src/V5BitrixNativeCartMutator.php` commit `1057c3d917e5a25ebed163cbd4d174d28e47e201`: concrete existing D7 basket line update/remove, using strict `V5BitrixPdoCompatibilityBridge`, verified FUSER ownership (`V5BitrixLockedCartFuserOwner`), same transaction savepoint continuity, Bitrix Sale Basket::loadItemsForFUser, BasketItem::setField('QUANTITY')/delete(), Basket::save(), then reload persisted basket and assert requested result. Requires **independent site-provided** `V5NativeCartMutationReadinessPort` attestation prior to touching D7; no default positive implementation. NO production wiring installed; real Bitrix basket save/event-side-effects NOT TESTED.
- `src/V5MySqlPublicComposition.php` commit `3183f15917bab41e119cecd09f6365f5000b9534` and `V5MySqlPilotComposition.php` commit `fbe563a688178be9afb58ec49c3001e6e55fc475`: new optional `?V5LockedCartMutatorPort $cartMutator=null`, injected to `V5MySqlCartMutation` only when explicitly provided. No automatic enabling.
- `tests/V5CartMutationHttpTest.php` commit `77df29b7ffeaa9c7ccaa8e91faf715a555c1eb99` (37 assertions), plus checkout CI commit `01c913e02d14fc84832b1967aaf6747556a25285`. **GitHub Actions `40215020972` SUCCESS**, existing checkout 375 + new 37 = **412/412 mock-only PASS**, PHP lint includes all new sources. Test verifies exact app contract, forbidden client authority, no V4 JWT/cookie/price, HTTPS/method restrictions, 503 when port not wired, mocked safe reply; NO live D7 transaction test and no native basket writes.
- **New developer API document:** `docs/SAMZABERU_V5_CART_MUTATION_CONTRACT_2026-10-25.md`, commit `481972fefd93eccad63b429c101d109e8082b186` with exact example JSON and HTTP error contract, public domain `https://evrasia.rest`.

**Critical release gates unchanged:** strict physical Bitrix native Sale D7/V5 transaction and `Basket::save()` event side-effects need site attestation and separately approved real-user pilot; current native V5 AUTH OFF and no production activation. For checkout order creation there remains a separate Bitrix LOCAL handoff to the EXISTING call-center path (no call-center server research). No synthetic accounts or SMS/payment. Next implementation can tackle creating/adding basket items from native catalog, or other remaining V5 parity requirements, **not** repeat generic static save diagnostics.

## 32. Owner requested finish today / developer handoff — V5 cart add + source-of-truth parity (2026-10-25)

Owner: "нужна реализация, давай сегодня закончим и уже передадим данные разработчику". Stopped unbounded Sale::save research. Completed concrete V5-only mobile cart-add HTTP contract and server-side coordinator in feature branch, without production code/DB changes or enabling V5 AUTH. **Do not claim V5 is live ready.**

- V5 route POST /api/v5/cart/items/add, created in V5RouteMap commit 3a2d33ec6813fc147bafd1ecbac7a210755d920c.
- V5CartMutationRequest::parseAdd() exact input {access_token, product_id (positive int), quantity (1..1000 int)} with no user_id/FUSER_id/price/currency/product-name; commit 2e3abaff64831b247377fb3b81207407013af0fa.
- Strong-session addCartItemForAccess method and V5LockedCartAddPort in V5MySqlStrongSessionStore commit 9f5c96ba8dff2fbd9f8823179db9fb8fa96a5b66. Reuses V5 JWT, locked b_user, latest AUTH_EPOCH, assurance, session and transaction; returns basket-line ID after success+outer commit; fail closed.
- V5AuthHttpHandler cartAdd port and addCartItem response 201 {item:{id,product_id,quantity}}, commit d1334429fed8d2da04bc8e696bce3c9cd1ebebbb. V5MySqlCartAdd wrapper in V5MySqlCartMutation.php commit 1764ff927e9c7269dd6cac4bc5b9ef946a53ac87. Public/pilot composition optional cartAdder port, commits 680a587ed25f17c645f11db42421aa33b204702a and a4d0753d383adfc7024cbc87a9980fc995ce7184, OFF unless injected.
- **Major actual integration finding**: existing checkout reads CartApiService / custom B_API_BASKET while earlier V5 D7 cart reader/mutator used b_sale_basket. These are NOT proven to be the same cart. Never wire D7 fallback and assume checkout sees those items. Added V5BitrixExistingCartAdder (commit 370f45c135b6a44953d40493ad1c4e557b6ed477): validates existing locked owner, pinned installed CartApiService SHA, fresh getProductsForOrder and server price, requires separate site-native confirmed INSERT into SAME custom B_API_BASKET via V5InstalledExistingCartAddPort, with strict transaction continuity. **No site-positive writer** installed: cannot yet perform real add; it fails closed.
- Public/pilot compositions no longer silently instantiate D7 cart reader on FPM if no verified custom reader is provided, commits d5c626e7b42103cc42777e6b5e93c1ce5a0abd83 / 358b1c2f394c413c05f806d23cfb282965ee6064. GET /cart returns 503 if no site-bound matching reader; avoids false basket display.
- V5CartAddHttpTest.php commit d06ada1da35eb4331c1ac1fdc15e623144b7f476: 41 mock assertions; V5CartSourceParityTest.php commit b9ff29489521dfb2140189c167c3b3b78234aa18: 10 source-only regression assertions refusing D7 fallback. Checkout CI commits 99ecc024b494267f138cf4db463e71686542a31c and d33901aaeb14d728e29ae92ed791e97bfca360af; last GitHub Actions run 40215770189 SUCCESS: earlier 412 checks +41 add +10 parity=463/463, ALL MOCK/SOURCE ONLY; no real account/orders/database writes.
- **Developer-facing consolidated handoff**: docs/SAMZABERU_API_V5_DEVELOPER_HANDOFF_2026-10-25.md commit 9e402b5b8d2a53aa453fca8a189f42b7b26413f5. Full 19-route inventory and request/response examples covering signin/challenge/trust/SMS, PIN, profile, cart add/change/remove, checkout idempotency and existing Bitrix -> local call-center path, history and legal. Clearly distinguishes mock-ready from production OFF. Importantly V5 does not yet have POST /auth/register with native signup/consent recording (legal validate only read-only), and native cart add writer not installed.

**Gates to ACTUAL launch are unresolved; do not misrepresent:** prove a SINGLE server basket backing / stable basket-line id for GET/add/quantity/remove/checkout (custom B_API_BASKET vs D7), implement exact native custom cart write; validate/enable shared Bitrix Sale native transaction with events; prove existing LOCAL Bitrix handoff to call center without accessing call-center servers; install ledger schema; site-owned NOTES/source/happytime/payment/dispatch values; native signup/accept legal; approved live pilot. V4 entirely independent, V5 AUTH OFF, production not touched, no synthetic users/real orders/SMS/payment.

The developer can implement app UI/network client against the CURRENT DOCUMENTED CONTRACT and mock fixtures today; **cannot point it at live production V5 yet**. Priority next is the precise custom-cart site write and single cart identity plus native release checks, not more generic source-only audits.


## 33. V5 mobile auth TOTP / email-first / SMS fallback verified for developer handoff (2026-10-25)

The owner asked explicitly about TOTP, email and SMS integration for mobile SamZaberu V5, all part of the feature-only V5 auth contract. Website MFA acceptance and production V5 auth readiness are NOT the same.

- AuthDecision: currently strong, verified, server-revocable device trust skips repeated factor prompt even when website native TOTP exists; without strong trust, native Bitrix TOTP => totp challenge, otherwise email challenge (email-FIRST), not automatic SMS.
- V5LoginWorkflow: device ID exact sz_ + 64 lowercase hex, platform ios/android; challenge ID 64 lowercase hex; OTP code exactly six digits; server-driven challenge_type, exp <=300s.
- TOTP: staging-only V5BitrixLockedTotpFactor executes native Bitrix MFA verify with replay proof, but its live public FPM lifecycle requires independent positive site evidence. Not a standalone production-ready mobile verifier.
- Email: V5MySqlEmailOtp issues password-bound, verified-account-email challenges, HMAC and lifetime/attempt/epoch/device controls; public email-first composition uses existing website D7 mail event EVRASIA_WEB_EMAIL_LOGIN, template 95 site s1, Redis budget. Queued does not imply received in mailbox. Requires real live attestation and email ledger.
- SMS: manual email-to-SMS fallback, no automatic SMS and no TOTP downgrade. Adapter V5ExistingWebsiteSmsRecoveryAdapter exists, but site-native V5ExistingWebsiteSmsNativeTransitionPort send/transition positive implementation and proof remain missing. In email-first build ordinary SMS lifecycle is V5UnavailableSmsLifecycle, thus /auth/challenge/resend is not a working universal resend route.
- Full handoff section 3 in docs/SAMZABERU_API_V5_DEVELOPER_HANDOFF_2026-10-25.md corrected and expanded in commit 3b9a7f7815b06449305d89b1f6e72936221da146, including exact Android/iOS device ID, request/response JSON, trusted/TOTP/email policy, manual SMS and not-live status. Developer may implement client against mocks now; cannot test against live V5 until release conditions pass.
- No changes to V4 or production. V5 AUTH remains OFF. No SMS, mail or OTP was sent by the documentation update.


## 34. OVERRIDING OWNER DECISION: STOP REWRITING V4, REBASE V5 ON PROVEN V4 (2026-10-25)

Owner forcefully corrected strategy: working API V4 has been running for a year, device_id mobile registration/trusted service already deployed and E2E accepted on 2026-09-24, and V5 should have reused V4 business functionality while changing ONLY authentication and legal/registration. Previous standalone V5 cart/order rewrite and its hundreds of mock-only assertions were a strategic mistake and MUST NOT be treated as a production integration plan or sent as final developer handoff.

FACT: No installed API V4 PHP controllers/router exist in the accessible GitHub repo; they are only present on the production Bitrix host evrasia under /home/site_evrasia/web/evrasia.spb.ru/public_html. It is impossible to perform a precise source rebase until the original V4 source is securely made available. NEVER invent V4 handler signatures or route mappings.

Implemented next real step:
- New `site-integration/samzaberu-v5/deployment/snapshot-working-v4-for-v5-reuse.sh`, final commit `516b01bad85a9f72fea8cb427fc8c0b800f16a7b`, Git blob `bf715e6f9abf5b57c66ed1effd9bbb5b7ed71d43`. On root@evrasia copies only installed V4 router/controllers, CartApiService, TrustedDeviceMobileService and shared TrustedDeviceService to a NEW private root-only location. Hash guards two pinned working V4 files; no site code executed, no production writes, no DB, no uploads, no SMS. Private v4-source.tar.gz archive created for owner-approved secure transfer after review. Not yet run.
- GitHub CI workflow v5-checkout-source-only.yml includes bash -n check for the snapshot; run 40217125798 SUCCESS. CI proves bash syntax, NOT on-site source copy.
- New architecture decision doc `docs/SAMZABERU_V5_V4_FIRST_REBASE_2026-10-25.md`, commit `449b619102a407783f4047563de72fa8d7613a42`. Document plan: reuse exact proven V4 business APIs, route to existing call-center channel, existing mobile device_id trusted/antifraud, change only V5 strong session/TOTP/email/manual SMS and pre-signup legal catalog plus native signup-consent recording. No V4 JWT acceptance from V5 or implicit V4 authentication bypass. V4 unaffected. Avoid parallel b_sale_basket vs B_API_BASKET.
- Old experimental V5 developer handoff `docs/SAMZABERU_API_V5_DEVELOPER_HANDOFF_2026-10-25.md` now explicitly marked SUPERSEDED in commit `5cac76875178868d8351f9e7e882ca09e160b3fa`, so developer is not told to reimplement V4 business endpoints against wrong V5 DTOs.

**NEXT DIRECT ACTION:** Run the single pinned read-only source snapshot on root@evrasia; get OWNER-REVIEWED V4 PHP source archive into trusted workspace (must check for embedded secrets first; never upload blindly to public repo). Then clone the actual existing router/controllers in an independent V5 feature branch, identify only auth/legal/registration diff, keep business route semantics and site checkout unchanged, run targeted regression tests on existing data without synthetic accounts/orders, seek approval prior to production cutover. NEVER return to generic V5 Sale::save token tracing and never claim 463 mock passes mean real V5 completion. V4 still production, V5 AUTH OFF and no production changes.
