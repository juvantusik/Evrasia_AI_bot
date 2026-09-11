# Website legal documents and consent integration

> Status: website legal catalog, offer, privacy policy, standalone consents, registration consent persistence and the existing-user account consent gate are **PRODUCTION**.
>
> Updated: **2026-09-11** after controlled production E2E of the existing-user account consent gate.

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

## 3. Native Bitrix consent persistence — PRODUCTION

Consent events use `Bitrix\Main\UserConsent\Consent::addByContext(...)`; no custom consent ledger/table was created.

Current active versioned agreements:
- ID 1 / `EVRASIA_OFFER_20260911` → `/legal/public-offer/`
- ID 2 / `EVRASIA_PD_20260911` → `/legal/personal-data-consent/`
- ID 3 / `EVRASIA_MARKETING_20260911` → `/legal/marketing-consent/`

Historical users were not bulk backfilled. Prior production inspection found no historical native Bitrix offer/PD consent-event ledger in the inspected mechanisms. Existing `UF_SMS` and `UF_SUBSCRIBE` values are mailing/subscription state and must not be treated as historical versioned offer/PD consent records.

## 4. Existing-user personal-account consent gate — PRODUCTION / E2E ACCEPTED

A mandatory consent popup was implemented for authenticated existing users entering the personal account when the current offer and/or PD agreement event is missing.

Initial rollout remains hard-gated to the controlled production test user **Bitrix USER_ID 880339**. Do not infer that the gate has been enabled for all historical users yet.

Current behavior:
- checks current versioned offer agreement ID 1 and PD agreement ID 2;
- popup requests only missing required agreements;
- offer links to `/legal/public-offer/`;
- PD text links to `/legal/personal-data-consent/` and `/legal/privacy-policy/`;
- marketing is deliberately absent from this popup;
- existing `UF_SMS` and `UF_SUBSCRIBE` preferences are not modified;
- acceptance writes only native Bitrix consent events for the missing required agreements;
- no duplicate consent event is required when an agreement is already present;
- user may accept or leave/logout rather than continue through the account without the required current-version acceptance.

Controlled E2E on USER_ID 880339 succeeded. After user interaction production contained exactly:
- consent ID 57 → agreement ID 1 / `EVRASIA_OFFER_20260911`;
- consent ID 58 → agreement ID 2 / `EVRASIA_PD_20260911`;
- both inserted 11.09.2026 14:16:11;
- `ORIGINATOR_ID=evrasia_account_gate`;
- `ORIGIN_ID=account_880339`;
- agreement 1 count = 1;
- agreement 2 count = 1;
- agreement 3 count = 0.

This verifies the account-gate offer + PD persistence path in production. Marketing was not created by the gate.

The user visually confirmed that the popup itself works correctly.

## 5. Account-gate button style — PRODUCTION

The popup logout action originally had a local custom style. It was corrected to reuse the existing Evrasia personal-account button classes exactly:

`btn border_inverse exit_btn`

The local `.evrasia-consent-gate__logout` CSS was removed. No custom font size, weight, border or dimensions remain for that action.

Production `/account/index.php` SHA after this correction:
`cb2a01f0462308c69953695e4db3306252ebf586b28f8581022dca76c0013efc`

Rollback backup:
`/home/site_evrasia/web/evrasia.spb.ru/backups/account-consent-button/20260911-143102`

Deployment result: **13 PASS / 0 FAIL**, no rollback. Consent endpoint reference and test-user restriction remained unchanged.

## 6. Public legal documents

Public offer revision 11.09.2026 is production. The repaired offer header was visually accepted earlier on 2026-09-11.

Privacy policy is **PRODUCTION / ACCEPTED**. Standalone PD consent and standalone voluntary marketing consent are production.

Public legal documents must not disclose internal Anti-Fraud/device-linking mechanics.

## 7. SamZaberu legacy PDF compatibility — NOT YET DEPLOYED IN THIS CHECKPOINT

The application «Самзаберу» was identified as still referencing the legacy URL:
`/upload/docs/protection_personal_data.pdf`.

The user supplied the current 9-page public-offer PDF, revision 11.09.2026, for use at that legacy filename. At the time of this checkpoint, replacement of the production legacy PDF was discussed but **not confirmed as deployed**. Do not mark it complete without a production verification.

Longer term, application code should point to the canonical public-offer URL rather than relying on the legacy filename; do not perform that application change without inspecting the current SamZaberu implementation.

## 8. Anti-Fraud constraints

- consent choices are not fraud/risk/grouping evidence;
- do not auto-block based on consent state;
- Anti-Fraud remains advisory/operator-controlled;
- public legal documents must not reveal internal detection mechanics;
- protected site-side APIs remain preferred over distributing site/RestIS credentials.

## 9. Continuation point

Confirmed production state:
- legal catalog/canonical pages: **PRODUCTION**;
- public offer revision 11.09.2026: **PRODUCTION**;
- privacy policy: **PRODUCTION / ACCEPTED**;
- signup three-checkbox implementation: **PRODUCTION**;
- native Bitrix versioned agreements: **PRODUCTION**;
- existing-user personal-account offer/PD popup: **PRODUCTION / CONTROLLED E2E ACCEPTED for USER_ID 880339**;
- popup native button styling: **PRODUCTION**;
- rollout of account gate to all historical users: **NOT DONE**;
- SamZaberu legacy PDF replacement: **NOT CONFIRMED / DO NOT ASSUME DEPLOYED**.

Next chat should restore context from this document and `docs/PROJECT_CHECKPOINT.md`, then inspect factual production state before any further mutation.