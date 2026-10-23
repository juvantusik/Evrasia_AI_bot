# Реальные бизнес-маршруты V4 → переиспользование в API V5 (23.10.2026)

## 1. Production SOURCE-ONLY evidence (not a V5 endpoint deployment)

Operator on actual \`evrasia\` executed the pinned, masked lexical PHP inventory, all guards PASS:

- \`FINAL_STATUS=PASS\`, \`FINAL_RC=0\`, \`WRAPPER_RC=0\`.
- \`ROUTE_REGISTRATIONS=62\`, \`CONTROLLER_FILES=11\`, sources unchanged before and after.
- Exact installed \`api/v4/router.php\` SHA256 **\`5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d\`**, 9018 bytes, owner UID/GID 1005:1005, mode 644; mtime 2026-10-22 19:50:30 +0300. This SHA supersedes **only the previously archived *read-only diagnostic* router pin** \`3b263c...65539be50\`. The reason for the changed router bytes and runtime effects are not yet independently attested; do not silently replace any production deployment checksum based on this.
- All 11 controller hashes shown below; dynamic Bramus route closure mapping (e.g. \`GET /orders\`) is **not resolved by token-only inspection**.
- \`SITE_SOURCE_EXECUTED=NO\`, \`DATABASE_CONNECTION=NO\`, \`DATABASE_WRITE=NO\`, \`PRODUCTION_CODE_WRITE=NO\`, \`SMS_SENT=NO\`. No user information or secrets were read; V4 kept intact, V5 AUTH remains OFF.
- Canonical mobile/public API domain **\`https://evrasia.rest\`**, Bitrix installation directory retains \`evrasia.spb.ru\`.

## 2. Verified lexical business route declarations and intended V5 reuse

Routes are relative to \`/api/v4\` and **not automatically published** under \`/api/v5\`.

| V4 method + registered path | Direct owner / lexical callback | V5 approach |
|---|---|---|
| \`GET /restaurants-stop-order\` | \`V4\Restaurants::stopOrder\` | Reuse existing Bitrix restaurant availability/read logic; verify method body, response and side effects |
| \`GET /orders\` | Bramus callback **UNRESOLVED** | Source-map exact order service, then V5-protected read; no V4 JWT |
| \`GET /orders/(\d+)\` | Callback **UNRESOLVED** | Bind order ID to V5 user; do not expose others' orders |
| \`GET /orders/(\d+)/push\` | Callback **UNRESOLVED** | Read semantics / side effects **unknown**; do not assume safe GET |
| \`POST /orders/(\d+)/evaluate\` | Callback **UNRESOLVED** | Existing order rating business service after V5 authentication |
| \`POST /orders/(\d+)/cancel\` | Callback **UNRESOLVED** | Native authorization and ownership + reuse mutation |
| \`POST /orders/(\d+)/pay\` | Callback **UNRESOLVED** | High-risk payment; do not forward without idempotency and ownership |
| \`POST /orders/(\d+)/repeat\` | \`V4\Cart::repeatOrder\` | Reuse cart/order service after V5 account/ownership check |
| \`GET /cart/id\` | \`V4\Cart::getCartId\` in \`mount('/cart')\` | Preserve native FUSER / Bitrix cart semantics |
| \`GET /cart/\` | \`V4\Cart::getCart\` under mount | **First protected read to integrate**, using V5 strong session; existing V4 service only |
| \`POST /cart/\`, \`POST /cart/add\` | \`V4\Cart::add\` | Reuse existing price/availability/basket service |
| \`DELETE /cart/\`, \`POST /cart/remove\` | \`V4\Cart::remove\` | Same business service with V5 binding |
| \`POST /cart/plus\`, \`POST /cart/minus\` | \`V4\Cart::plus/minus\` | No parallel calculation implementation |
| \`POST /cart/clear\` | \`V4\Cart::removeAll\` | Native basket removal after V5 ownership |
| \`POST /cart/gifts/add\`, \`POST /cart/gifts/remove\` | \`V4\Cart::addGift/removeGift\` | Existing gift eligibility; avoid reimplementing |
| \`POST /cart/apply_sale\` | \`V4\Cart::applySale\` | Existing coupon calculation |
| \`POST /cart/order\` | \`V4\Cart::order\` | Existing checkout/delivery/payment after V5 authorization |
| \`POST /edit-profile/\` | \`V4\Profile::update\` | Reuse actual profile writer after V5 session and native ownership rules |
| \`POST /profile/change_email_request\`, \`POST /profile/change_email_confirm\` | \`V4\Profile\` | Existing Bitrix/email flow with security epoch rechecks |
| \`POST /profile/change_email\`, \`POST /profile/confirm_email\`, \`POST /profile/hit_email_confirmation\` | \`UserController\` (outside direct V4 controllers) | Separate live source review; no owner guess |
| \`GET /qr_order/{order_id}\`, \`POST /qr_order/qr_code\` | \`V4\QrOrder::show/qr_code\` | Reuse existing \`QrBasketService\` with order ownership |
| \`POST /pincode\` | \`V4\PinCode::send\` | **Do NOT directly call**: V4 path may send VK/SMS and uses V4 JWT. Reuse \`CRestis\` PIN *read* only after strong V5 session; do not repeat SMS |
| \`GET /legal/consents\`, \`GET /legal/registration-documents\`, \`POST /legal/consents/accept\` | \`V4\LegalConsents\` | Existing V5 public legal GET/read-only validation separate; signup consent writer pending |
| \`POST /signup\`, \`POST /signin\`, \`POST /send_sms\`, \`POST /send-sms\` | V4 auth/SMS controllers | **NEVER tunnel V4 password-only session/JWT or unbudgeted SMS into V5** |
| \`POST /signin/mobileid\`, \`POST /signin/mobileid/status\`, \`POST /signin/mobileid/code\` | \`V4\AuthMobilId\` | Deliberately separate V5 auth migration, not a shortcut |
| \`POST /confirm/email/request_code\`, \`POST /confirm/email\`, \`POST /confirm/phone/request_code\`, \`POST /confirm/phone\` | \`V4\UserConfirmation\` | Preserve verified account phone and V5 SMS budget/epoch |
| \`GET /system_notifications\`, \`POST /history/update\` | Main controllers not in the 11 V4 directory | Source mapping later; do not invent V5 controllers |
| \`POST /mobileid/notification\`, \`POST /mobileid/callback\`, \`POST /mobileid/otp_check\` | Bramus closures | External-provider callbacks; not client-facing V5 business aliases |
| \`POST /orders/set_status\` | Router closure with Bitrix DB dependencies | Not safe to expose as a user-driven V5 mutation without separate auth |
| \`DELETE /profile\` | Router closure calling \`CUser::Update\` and Logout | High-risk account deletion; separate native security/epoch acceptance |
| \`POST /vkbot/*\` | \`V4\VkBot::signIn/signOut\` under mount | Not an auth shortcut for V5 |

**No \`GET /profile\` was observed among the 62 registered V4 router entries.** Do not invent its V4 route; later inspect the actual Bitrix user/profile reader independently. Grouped \`mount('/cart')\` prefix reconstruction is lexical; verify HTTP runtime before publishing a client V5 route.

## 3. Exact production V4 controller SHA256 pins

| Controller | SHA256 |
|---|---|
| Auth.php | \`72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362\` |
| AuthMobilId.php | \`f34c9b69a89aca924719c13e2e6ad2035dc56e52cf19231545ec55a0c4bed8cd\` |
| Cart.php | \`73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039\` |
| LegalConsents.php | \`9682bb568a3d01d30a8466cf9c1b5301f25a5c45305b23c07c3f90a075a780ac\` |
| PinCode.php | \`7734ddbaba0e7ec9ddfc9f0a9008c0da0c0a1523e505e52caebac2bbaf637cd0\` |
| Profile.php | \`fa82a243315bb14189b91421e0abc8a7841f4c8fb373124bccb6c2358639b603\` |
| QrOrder.php | \`95123bbe605ec786307e1a6b2c36c8c96cb823d3d9f9354257671dcdafd63604\` |
| Restaurants.php | \`c9560839aac8b9640ab65b778736deeed1366096a4a1a0393f4bf72462d80deb\` |
| Sms.php | \`c8f741cec13a1a00ba729f1fb97ee00d3f4fe47df1e393f1724c6435d49b340c\` |
| UserConfirmation.php | \`532a1b01ce5feca276534de48c316227a63567ead1ad9bb83c4ce977d3d8ae34\` |
| VkBot.php | \`0cf9ee76ccf9eef0380b8b2ae1c35c266d5e4b758ebf1413e565bc889b18ef16\` |

## 4. Immediate implementation target — reuse existing cart/restaurant services

Live \`V4\Cart\` has \`getCart, getUserId, getFuserId, calcTotal\` and instantiates **\`Evrasia\Services\CartApiService\`**. The accurate constructor/return/authorization/Sale Fuser contract is NOT in the GitHub package and must not be guessed. \`V4\Restaurants::stopOrder\` is the public read route, direct Bitrix \`CIBlockElement\` dependency. **Next one narrow read-only step** scans only pinned \`Cart.php\`, pinned \`Restaurants.php\`, and exact-filename \`CartApiService.php\` in the existing \`local/php_interface\` service tree; string literals and comments masked, no source executed. Then implement the first **actual V5 cart read adapter** using the verified existing service, requiring a strong revocation-checked V5 user identity and independent V5 session. Do not invoke V4 HTTP controller, JWT or SMS.

Status: V4 production unchanged; V5 production AUTH OFF; real V5 business adapter not yet enabled or installed. Do not copy V4 router wholesale.
