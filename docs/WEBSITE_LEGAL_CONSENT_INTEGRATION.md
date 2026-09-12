# Website legal documents and consent integration

> Status: website legal catalog, offer, privacy policy, standalone consents, registration consent persistence and the existing-user account consent gate are **PRODUCTION**.
>
> Updated: **2026-09-12** after global rollout of the existing-user consent gate and production extension of Anti-Fraud account-map with consent fields.

## 1. Scope and production website

Production website host: `evrasia.spb.ru`.
Document root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`.

Canonical legal pages:
- `/legal/public-offer/`
- `/legal/personal-data-consent/`
- `/legal/privacy-policy/`
- `/legal/marketing-consent/`

Current public-offer revision: **11.09.2026**. It includes §1.19 `Недобросовестное использование`. PD consent is separate from the offer; advertising consent is separate and voluntary.

## 2. Registration consent implementation — PRODUCTION

Active signup template:
`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/templates/eurasia/components/eurasia/signup/main/template.php`

Active backend:
`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/components/eurasia/signup/class.php`

Signup contract:
1. required offer acceptance;
2. required PD consent + Privacy Policy acknowledgement;
3. optional marketing consent.

For new registrations the single marketing choice maps to the existing `UF_SMS` and `UF_SUBSCRIBE` delivery-state fields. Marketing remains independent of offer/PD acceptance.

Installed production SHA values from signup cutover:
- template: `0baba7a1d994616848c5e8d7ad39cf0929d251250bda9c2f64396e4240ff921f`
- backend: `1389951029525c046a00206397c2bc45ec14a0b5acaccbe75686b63fb2b83b84`
- rollback backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/signup-consents/20260911-131502`

Verified signup consent origin:
- `ORIGINATOR_ID=evrasia_signup`
- `ORIGIN_ID=<USER_ID>`
- agreements 1 and 2 are mandatory;
- agreement 3 is optional marketing.

## 3. Native Bitrix consent persistence — PRODUCTION

Consent events use `Bitrix\Main\UserConsent\Consent::addByContext(...)`; no custom consent ledger/table was created.

Current active versioned agreements:
- ID 1 / `EVRASIA_OFFER_20260911` → `/legal/public-offer/`
- ID 2 / `EVRASIA_PD_20260911` → `/legal/personal-data-consent/`
- ID 3 / `EVRASIA_MARKETING_20260911` → `/legal/marketing-consent/`

Authoritative event table:
- DB: `eurasia_new`
- table: `b_consent_user_consent`
- key columns: `ID`, `DATE_INSERT`, `AGREEMENT_ID`, `USER_ID`, `IP`, `URL`, `ORIGIN_ID`, `ORIGINATOR_ID`.

Historical consent lookup must use this table, not USER `UF_*` fields. Existing `UF_SMS` and `UF_SUBSCRIBE` values are mailing/subscription state and must not be treated as versioned offer/PD consent records.

## 4. Existing-user personal-account consent gate — PRODUCTION / GLOBAL ROLLOUT

A mandatory consent popup is implemented for authenticated existing users entering the personal account when the current offer and/or PD agreement event is missing.

Initial rollout was hard-gated to controlled USER_ID `880339`. On **2026-09-12** that single-user restriction was removed in production while preserving `$USER->IsAuthorized()`.

Current behavior:
- applies to all authenticated users;
- checks current agreement ID 1 and agreement ID 2;
- popup requests only missing required agreements;
- offer links to `/legal/public-offer/`;
- PD text links to `/legal/personal-data-consent/` and `/legal/privacy-policy/`;
- marketing is deliberately absent from this popup;
- existing `UF_SMS` and `UF_SUBSCRIBE` preferences are not modified;
- acceptance writes only native Bitrix consent events for missing required agreements;
- no duplicate consent event is required when an agreement is already present.

Production rollout result:
- `/account/index.php` final SHA256: `9bf6ec5eb1ecf4435131ffe488037f60ba13bcee2b1a795dd5478179493ff94b`;
- backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/account-consent-rollout/20260912-052122/index.php`;
- 14 PASS / 0 FAIL;
- rollback not required;
- marketing change: NO;
- `UF_SMS` change: NO;
- `UF_SUBSCRIBE` change: NO.

Controlled forensic reference USER_ID `880339`:
- registered `2022-02-06 15:47:53`;
- consent ID 79 → agreement 1;
- consent ID 80 → agreement 2;
- timestamp `2026-09-11 14:34:31`;
- `ORIGINATOR_ID=evrasia_account_gate`;
- `ORIGIN_ID=account_880339`.

These physical rows supersede earlier provisional values 57/58 at 14:16:11.

## 5. Account-gate button style — PRODUCTION

The popup logout action uses the existing Evrasia personal-account button classes exactly:

`btn border_inverse exit_btn`

The local `.evrasia-consent-gate__logout` CSS was removed. No custom font size, weight, border or dimensions remain for that action.

## 6. Protected Anti-Fraud account-map consent export — PRODUCTION

Route:
`POST /api/internal/anti-fraud/account-map`

Service:
`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/lib/Services/AntiFraudAccountMapService.php`

The service remains token-protected and accepts only an address list of 1..500 validated USER_ID.

On 2026-09-12 it was extended in production to export current mandatory consent state using **one additional batch query** to `b_consent_user_consent` for the whole requested USER_ID list. No N+1 query per user is used.

New response fields:
- `offer_accepted`
- `offer_accepted_at`
- `offer_source`
- `pd_accepted`
- `pd_accepted_at`
- `pd_source`

Current agreement mapping:
- offer = agreement ID 1;
- PD = agreement ID 2.

For duplicate events, the latest physical event is selected by:
`ORDER BY USER_ID, AGREEMENT_ID, DATE_INSERT DESC, ID DESC`.

Source normalization:
- `evrasia_signup` → `signup`
- `evrasia_account_gate` → `account_gate`
- other originators → `other`.

Timestamp output is ISO-8601 using `Europe/Moscow`.

Privacy/scope:
- IP and URL are not returned in account-map;
- marketing consent was not added to this Anti-Fraud contract;
- these fields are informational only and do not affect scoring, grouping, blocking or case membership.

Production deployment:
- baseline SHA256: `c6832c1d16f496d6c7afc8bac64a111ec3c60860a8cf80e889fd0fef7061ab18`;
- final SHA256: `5705d7586c35ced55975a3d228ed2acc148fff7bc9fbc7c217b7337368141a77`;
- backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/account-map-consent/20260912-053811/AntiFraudAccountMapService.php`;
- result: 15 PASS / 0 FAIL;
- rollback not required;
- database write: NO;
- Bitrix DB write: NO;
- scoring change: NO.

Functional read-only verification returned 12 expected agreement rows for six known users and correctly normalized `account_gate` / `signup` sources.

## 7. Public legal documents

Public offer revision 11.09.2026 is production. The repaired offer header was visually accepted earlier on 2026-09-11.

Privacy policy is **PRODUCTION / ACCEPTED**. Standalone PD consent and standalone voluntary marketing consent are production.

Public legal documents must not disclose internal Anti-Fraud/device-linking mechanics.

## 8. Legacy compatibility PDF — PRODUCTION

Legacy URL:
`https://evrasia.spb.ru/upload/docs/protection_personal_data.pdf`

Production serves the corrected current 9-page offer PDF:
- SHA256 `f11866dcaa33fae115b0710261a4231fb07ff590b323f4bb1054a3bd76e46cad`
- size 267105 bytes.

Known backups:
- `/home/site_evrasia/web/evrasia.spb.ru/backups/legacy-offer-pdf/20260911-150823/protection_personal_data.pdf`
- `/home/site_evrasia/web/evrasia.spb.ru/backups/legacy-offer-pdf/20260911-151421/protection_personal_data.pdf`.

The `/club/` page was updated away from `/upload/docs/bonus_rules.pdf?v2025` to the current compatibility PDF.

## 9. Anti-Fraud constraints

- consent choices are not fraud/risk/grouping evidence;
- do not auto-block based on consent state;
- Anti-Fraud remains advisory/operator-controlled;
- public legal documents must not reveal internal detection mechanics;
- protected site-side APIs remain preferred over distributing site/RestIS credentials;
- bot must not receive direct MySQL access solely for consent display.

## 10. Continuation point

Confirmed production state:
- legal catalog/canonical pages: **PRODUCTION**;
- public offer revision 11.09.2026: **PRODUCTION**;
- privacy policy: **PRODUCTION / ACCEPTED**;
- signup consent implementation: **PRODUCTION**;
- native Bitrix versioned agreements: **PRODUCTION**;
- existing-user personal-account offer/PD popup: **PRODUCTION / GLOBAL ROLLOUT**;
- popup native button styling: **PRODUCTION**;
- protected `account-map` consent export: **PRODUCTION**;
- current `account-map` final SHA: `5705d7586c35ced55975a3d228ed2acc148fff7bc9fbc7c217b7337368141a77`;
- legacy compatibility PDF replacement: **PRODUCTION**;
- Anti-Fraud bot/UI still needs propagation of the six consent fields from gateway through persistence/API to React UI.

Next chat should restore context from this document, `docs/PROJECT_CHECKPOINT.md`, `docs/ANTI_FRAUD_CONSENT_DATA_MAP.md` and `docs/SERVER_SCRIPT_RULES.md`, then inspect factual production state before any further mutation.