# Evrasia AI Bot — Current Project Checkpoint

> **Authoritative continuation checkpoint.**
>
> Updated: **2026-09-11** after production signup-consent implementation and visual acceptance of the repaired public-offer header.
>
> Source priority: **production actual state → current GitHub → staging/test → current docs → older discussion**.

## 1. Bot production baseline

Host: `eur-bot-01` (`192.168.103.200`).

Current accepted deployed bot application:
- repo: `juvantusik/Evrasia_AI_bot`
- PR #46 application revision: `555e19f0b9d0a76d629962d538c66df6ec9a4000`
- image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:531eff8c80a91a707abf51d5b2c51f8fc8f8bb8e29336a9b493364a8f9f170a3`
- config: `sha256:94e3575d8c92f88328ec0d638e66b00ff0069d6c2ea7df8144ccd99bfe9664cb`
- migrations: 20; latest journal timestamp `1788769200000`
- Anti-Fraud threshold: 40000
- scheduler: enabled / 15 min
- PR #46 deployment backup: `/opt/evrasia-ai-bot/backups/pr46-redeploy-20260910-101450-gCNdgw`

PR #46 is **PRODUCTION / ACCEPTED**. Trusted Device KPI and case-level `Новый` behavior remain accepted. Do not redeploy/retest merely for reassurance.

## 2. Anti-Fraud invariants

- advisory/investigative; risk is not proof of fraud; blocking is operator-controlled;
- grouping evidence is distinct from risk evidence;
- Bitrix is account-state source of truth;
- `ACTIVE=Y,BLOCKED=N` = Активен; `ACTIVE=N,BLOCKED=N` = Неактивен; any `BLOCKED=Y` = Заблокирован;
- blocked/inactive excluded from operational views by default while retaining distinct state;
- consent choices must not become fraud/risk/grouping evidence merely because they exist;
- public legal documents must not expose internal Anti-Fraud/device-linking mechanics.

## 3. Website legal / consent production state

Website host: `evrasia.spb.ru`; docroot `/home/site_evrasia/web/evrasia.spb.ru/public_html`.

Canonical legal catalog:
- `/legal/public-offer/`
- `/legal/personal-data-consent/`
- `/legal/privacy-policy/`
- `/legal/marketing-consent/`

Legacy legal URLs redirect to canonical pages. The obsolete `protection_personal_data.pdf` is not a current legal source and is not referenced by live signup code.

### Public offer

Revision **11.09.2026** is production. It includes §1.19 `Недобросовестное использование` and the current separate-consent model.

On 2026-09-11 the offer page regressed visually because DOCX-derived `offer-meta` header rows remained in HTML while their presentation CSS was absent. Read-only baseline SHA was `e489c95b196127a0cd3079251243dde6992dd8793df7d7bedd381a9e2aff3651`. A header-only CSS repair hid duplicated metadata rows and restored the intended visible subtitle `Публичная оферта · Редакция от 11.09.2026`. User confirmed: **«все получилось, супер!»**. Final post-fix SHA/output was not pasted; re-read production before future mutation.

### Privacy / consent documents

Privacy policy is **PRODUCTION / ACCEPTED**. Standalone concise `Согласие на обработку персональных данных` and separate marketing consent are the current legal model. The former “additional personal data” concept is superseded and must not be reintroduced.

## 4. Signup consent implementation — production installed 2026-09-11

Active template:
`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/templates/eurasia/components/eurasia/signup/main/template.php`

Active backend:
`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/components/eurasia/signup/class.php`

Current checkbox contract:
1. required offer acceptance;
2. required PD consent + Policy acknowledgement;
3. optional marketing consent.

Old separate `dispatch1`/`dispatch2` signup controls are removed. One marketing choice maps to both existing `UF_SMS` and `UF_SUBSCRIBE` state fields for new registrations.

Installed SHA values:
- template `0baba7a1d994616848c5e8d7ad39cf0929d251250bda9c2f64396e4240ff921f`
- backend `1389951029525c046a00206397c2bc45ec14a0b5acaccbe75686b63fb2b83b84`
- backup `/home/site_evrasia/web/evrasia.spb.ru/backups/signup-consents/20260911-131502`
- install verification 12 PASS / 0 FAIL; no rollback.

## 5. Native Bitrix consent subsystem

Production inspection before implementation found the native tables present but empty:
- `b_consent_agreement` = 0
- `b_consent_field` = 0
- `b_consent_user_consent` = 0
- `b_consent_user_consent_item` = 0
- `b_sender_agreement` = 0

Installed API signatures/source were inspected. Consent events use `Bitrix\Main\UserConsent\Consent::addByContext(...)`; no custom ledger/table was created.

Three active custom versioned agreements now exist:
- ID 1 `EVRASIA_OFFER_20260911` → `/legal/public-offer/`
- ID 2 `EVRASIA_PD_20260911` → `/legal/personal-data-consent/`
- ID 3 `EVRASIA_MARKETING_20260911` → `/legal/marketing-consent/`

The first agreement-create attempt failed because `TYPE` was required; its transaction rolled back and left zero rows. Installed metadata then confirmed `C` = custom, after which the corrected transaction created exactly the three agreements.

The registration backend now records offer + PD consent for every successful new registration and marketing consent only when selected. User creation and consent creation are transactionally coupled; required-consent persistence failure rolls back the registration.

Historical users were not modified or backfilled.

### Important historical finding

Before this implementation, the inspected native Bitrix consent/event subsystem contained no agreement or consent-event rows, and `b_sender_agreement` was also empty. Historical `UF_SMS` and `UF_SUBSCRIBE` values existed, but these are boolean mailing/subscription state, not a versioned legal acceptance ledger.

Therefore we found no native auditable Bitrix record of historical offer acceptance or PD consent in the inspected subsystem. This conclusion is limited to mechanisms actually inspected and does not prove that no evidence exists in an unrelated external archive/system.

## 6. Consent acceptance status

Three-checkbox UI/backend and native persistence code are **PRODUCTION INSTALLED**. The deployment itself passed structural/syntax/contract checks.

A controlled end-to-end registration is still pending. Before marking consent persistence **E2E ACCEPTED**, verify:
- marketing OFF → exactly offer + PD consent events, expected `UF_SMS`/`UF_SUBSCRIBE` state;
- marketing ON → offer + PD + marketing consent events, expected `UF_SMS`/`UF_SUBSCRIBE` state.

Do not backfill historical users as accepted without evidence.

## 7. Continuation point

Bot PR #46 remains accepted and untouched by this website work.

Next website step is controlled signup E2E verification. After factual PASS, update this checkpoint and `docs/WEBSITE_LEGAL_CONSENT_INTEGRATION.md` from **PRODUCTION INSTALLED / E2E PENDING** to **PRODUCTION / ACCEPTED**.

For all production scripts continue using `docs/SERVER_SCRIPT_RULES.md`: one guarded copy-paste wrapper, syntax check, automatic execution, structured PASS/FAIL result, no secrets printed.
