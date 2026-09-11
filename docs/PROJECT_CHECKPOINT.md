# Evrasia AI Bot — Current Project Checkpoint

> **Authoritative continuation checkpoint.**
>
> Updated: **2026-09-11** after production E2E acceptance of the existing-user personal-account consent popup.
>
> Source priority: **production actual state → current GitHub → staging/test → current docs → older discussion**.

## 1. Bot production baseline

Host: `eur-bot-01` (`192.168.103.200`).

Current accepted bot application:
- repo: `juvantusik/Evrasia_AI_bot`
- PR #46 application revision: `555e19f0b9d0a76d629962d538c66df6ec9a4000`
- image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:531eff8c80a91a707abf51d5b2c51f8fc8f8bb8e29336a9b493364a8f9f170a3`
- config: `sha256:94e3575d8c92f88328ec0d638e66b00ff0069d6c2ea7df8144ccd99bfe9664cb`
- Anti-Fraud threshold: 40000
- scheduler: 15 min.

PR #46 is **PRODUCTION / ACCEPTED**. Trusted Device KPI and case-level `Новый` remain accepted.

## 2. Anti-Fraud invariants

- advisory/investigative; risk is not proof of fraud; blocking is operator-controlled;
- grouping evidence is distinct from risk evidence;
- Bitrix is account-state source of truth;
- `ACTIVE=Y,BLOCKED=N` = Активен; `ACTIVE=N,BLOCKED=N` = Неактивен; any `BLOCKED=Y` = Заблокирован;
- blocked/inactive excluded from operational views by default while retaining distinct state;
- consent choices must not become fraud/risk/grouping evidence merely because they exist;
- public legal documents must not expose internal Anti-Fraud/device-linking mechanics.

## 3. Website legal production state

Website host: `evrasia.spb.ru`.
Document root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`.

Canonical legal catalog:
- `/legal/public-offer/`
- `/legal/personal-data-consent/`
- `/legal/privacy-policy/`
- `/legal/marketing-consent/`

Public offer revision **11.09.2026** is production and includes §1.19 `Недобросовестное использование`. Privacy policy is **PRODUCTION / ACCEPTED**. PD and marketing consents are separate documents; marketing remains voluntary.

## 4. Signup consent implementation — PRODUCTION

Active template:
`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/templates/eurasia/components/eurasia/signup/main/template.php`

Active backend:
`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/components/eurasia/signup/class.php`

Signup contract:
1. required offer acceptance;
2. required PD consent + Privacy Policy acknowledgement;
3. optional marketing consent.

Installed SHA values:
- template `0baba7a1d994616848c5e8d7ad39cf0929d251250bda9c2f64396e4240ff921f`
- backend `1389951029525c046a00206397c2bc45ec14a0b5acaccbe75686b63fb2b83b84`
- backup `/home/site_evrasia/web/evrasia.spb.ru/backups/signup-consents/20260911-131502`.

## 5. Native Bitrix consent subsystem

Current active custom versioned agreements:
- ID 1 `EVRASIA_OFFER_20260911` → `/legal/public-offer/`
- ID 2 `EVRASIA_PD_20260911` → `/legal/personal-data-consent/`
- ID 3 `EVRASIA_MARKETING_20260911` → `/legal/marketing-consent/`.

Consent persistence uses `Bitrix\Main\UserConsent\Consent::addByContext(...)`; no custom ledger was created.

Historical inspection found no native Bitrix offer/PD consent-event ledger in the inspected mechanisms. Existing `UF_SMS` and `UF_SUBSCRIBE` values are mailing/subscription state, not historical versioned legal acceptance records.

## 6. Existing-user personal-account consent popup — PRODUCTION / CONTROLLED E2E ACCEPTED

A mandatory popup is implemented for authenticated existing users who are missing the current offer and/or PD agreement acceptance.

**Current rollout scope is intentionally limited to Bitrix USER_ID 880339. It is not yet enabled for all historical users.**

Popup behavior:
- checks current offer agreement ID 1 and PD agreement ID 2;
- requires only missing required agreement(s);
- links to current offer, PD consent and Privacy Policy;
- does not include marketing;
- does not modify existing `UF_SMS` or `UF_SUBSCRIBE` preferences;
- writes native Bitrix consent events only for required offer/PD acceptance;
- version-aware agreement IDs/codes provide the basis for future re-consent when a new legal version is created.

Controlled production E2E on USER_ID 880339 succeeded after the user accepted the popup:
- consent ID 57 → agreement 1 / `EVRASIA_OFFER_20260911`;
- consent ID 58 → agreement 2 / `EVRASIA_PD_20260911`;
- timestamp 11.09.2026 14:16:11;
- `ORIGINATOR_ID=evrasia_account_gate`;
- `ORIGIN_ID=account_880339`;
- agreement 1 count = 1;
- agreement 2 count = 1;
- agreement 3 count = 0.

The user confirmed the popup itself works correctly. This account-gate path is therefore **E2E ACCEPTED for the controlled user**.

### Logout button styling

The popup logout action was corrected to use the site's existing personal-account classes exactly:
`btn border_inverse exit_btn`.

Custom `.evrasia-consent-gate__logout` styling was removed, so typography, border and sizing come from the established Evrasia UI rather than custom popup CSS.

Current known production `/account/index.php` SHA after that correction:
`cb2a01f0462308c69953695e4db3306252ebf586b28f8581022dca76c0013efc`

Backup:
`/home/site_evrasia/web/evrasia.spb.ru/backups/account-consent-button/20260911-143102`

Deployment: **13 PASS / 0 FAIL**, rollback not required. Consent logic and the single-user test gate remained unchanged.

## 7. SamZaberu legacy offer link — OPEN

The «Самзаберу» application was identified as still using legacy URL:
`https://evrasia.spb.ru/upload/docs/protection_personal_data.pdf`.

The user supplied an up-to-date 9-page PDF of the public offer, revision 11.09.2026, intended to replace the bytes served under that legacy filename for compatibility. **Production replacement was not confirmed in this chat. Do not assume it is deployed.**

Future work should verify actual production before replacement. Longer term, inspect SamZaberu code and migrate it to the canonical public-offer URL rather than relying on the obsolete filename.

## 8. Continuation point for new chat

Confirmed:
- Anti-Fraud PR #46: **PRODUCTION / ACCEPTED**;
- website legal catalog: **PRODUCTION**;
- offer 11.09.2026: **PRODUCTION**;
- privacy policy: **PRODUCTION / ACCEPTED**;
- signup consent implementation: **PRODUCTION**;
- native Bitrix agreements: **PRODUCTION**;
- existing-user account popup: **PRODUCTION / E2E ACCEPTED for USER_ID 880339**;
- account popup native button style: **PRODUCTION**;
- rollout to all historical users: **NOT DONE**;
- SamZaberu legacy PDF replacement: **OPEN / NOT CONFIRMED DEPLOYED**.

Next chat: read this file and `docs/WEBSITE_LEGAL_CONSENT_INTEGRATION.md`, then inspect factual production state before any mutation. Continue using `docs/SERVER_SCRIPT_RULES.md` for production operations.