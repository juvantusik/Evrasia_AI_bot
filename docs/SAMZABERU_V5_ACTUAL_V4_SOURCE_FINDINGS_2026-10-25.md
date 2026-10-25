# V5 from actual working V4 — verified uploaded archive
Date: 2026-10-25. Status: **source inspected, V4-first reuse prototype tested locally, NOT deployed**.

## Source verified

The owner uploaded private source archive `evrasia-v4-source-for-v5.tar.gz` from the already verified `root@evrasia` snapshot. Locally validated safe tar structure (56 PHP/.htaccess files, 3 unrelated files skipped on server); six source SHA-256 fingerprints exactly match original installed V4, including router, V4 Cart, V4 Profile, V4 LegalConsents, CartApiService and TrustedDeviceMobileService. No site PHP/Bitrix was executed and no production DB or user/orders accessed.

**Actual V4 architecture, now confirmed directly from user-provided PHP, not guessed:** `api/v4/router.php` is the real Bramus router. `Evrasia\Controllers\V4\Cart` implements getCart, add, plus, minus, remove, order, etc. (~1515 lines); it uses BOTH existing CartApiService custom basket and native Bitrix Sale\Basket within the original working flow. Must reuse together, not rebuild two parallel carts. V4 `Cart` has protected overridable getUserId/getFuserId/checkFUserId/logData methods. `Profile` has protected getUserId/checkAuth. `UserOrder` underlies real order history/detail. V4 `LegalConsents::registrationDocuments` is already public, and `status/accept` already use native Bitrix UserConsent::addByContext. Mobile device_id and TrustedDeviceMobileService already work, must not be changed.

**Important:** V4 router contains embedded internal Basic Auth credentials and non-mobile administrative/webhook routes. DO NOT publish/copy raw V4 router to public V5. V4 Cart::order logs full `$_SERVER` and `$_POST`, including possibly a new V5 bearer; suppress/sanitize for V5 rather than blindly copying. Never print embedded secret values.

## Concrete implementation created in current conversation (not yet committed/deployed into GitHub)

The owner can download the generated, source-safe V4-first package from this conversation, `evrasia_api_v5_v4_first_reuse_2026-10-25.zip`. This **does not** contain the raw 56 source files or internal credential literals, and is NOT a production deployment.

- `V5V4BusinessIdentity.php`: mandatory independent site-owned V5 strong-session JWT/epoch/revocation/USER_ID and FUSER owner proof port, no V4 JWT fallback.
- `V5V4Cart.php`: extends **installed real V4 Cart**, overriding only four identity/FUSER/logging hooks; original methods remain unchanged. It ignores client-supplied `cart_id` for user-bound V5 reads.
- `V5V4Profile.php`: extends real V4 Profile, V5 strong identity.
- `V5V4Orders.php`: delegates history/detail to real `UserOrder` business service.
- `V5V4PublicLegal.php`: delegates pre-registration legal document catalog to the real V4 native legal controller.
- `V5V4BusinessGateway.php`: exact V4-based GET /cart/, GET /orders, GET /orders/{id} and pre-auth GET /legal/registration-documents; **ALL POST/write/payments/SMS OFF** until an attested V5 site implementation exists.
- `build_private_legal_v5.py`: SHA-guarded private copy of actual V4 LegalConsents, changing only namespace and two V4 JWT checks to V5 verified identity; preserving existing native consent logic. Privately generated PHP lint PASS.
- `check_real_v4_snapshot.py`: source guard 56 files, 6 trusted SHA, structural Cart and legal checks, no source export.
- Local PHP mock-only tests: 12 Cart/Profile + 5 Orders/Legal + 19 Gateway = **36/36 PASS**, plus actual source SHA and generated legal PHP syntax PASS.

**Pending important release work:** install and attest real `StrongV5BusinessIdentityProofPort` using existing mobile V5 auth, verify native FUSER ownership and epoch continuously; approve minimal V4-based POST route integration using unchanged original site flow with same transaction/handoff; implement signup with atomic current offer/PD code consent storage (marketing optional); bind server V5 public router and test with real user. No live V5 routes enabled, no production writes, no synthetic accounts or SMS.

The experimental independent V5 cart/order branch is superseded by this owner-approved V4-first path; do not repeat fake basket adapters or long static Sale::save investigations.
