# Evrasia AI Bot — Current Project Checkpoint

> **Authoritative continuation checkpoint.**
>
> Updated: **2026-09-11** after production deployment of PR #47 Anti-Fraud `Новый` 24h semantics and consent-ledger structure inspection.
>
> Source priority: **production actual state → current GitHub → staging/test → current docs → older discussion**.

## 1. Bot production baseline

Host: `eur-bot-01` (`192.168.103.200`).

Current accepted bot application:
- repo: `juvantusik/Evrasia_AI_bot`
- production revision: `1ee4540150ef4aaba1e5c121a75e1c12b965dba2`
- image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:3defaa7388f2278dfa7767e1ea79d2c12c1f0121f73eb208c034b153ade2d280`
- image config: `sha256:0b16a8d6af7b1bd1e4b71c6ce5a02fb6933995350d074a1def022ded68bbd525`
- platform: `linux/amd64`
- Anti-Fraud threshold: 40000
- scheduler: enabled, 15 min
- production migrations: 21
- app container healthy, restart count 0 at acceptance.

PR #47 is **PRODUCTION / ACCEPTED**.

PR #47 Anti-Fraud `Новый` semantics:
- `Новый` means the account first appeared in the **web Anti-Fraud interface** less than 24 hours ago;
- repeated scheduler refreshes do not extend/reset that 24h window;
- existing visible accounts were bootstrapped as old during migration so the UI did not light up all historical accounts;
- table: `anti_fraud_web_account_state(bitrix_user_id, first_seen_at)`;
- production bootstrap after migration: 132 rows, 0 recent rows;
- dynamics API exposes `recentAccountIds`;
- forensic case delta remains separately available and is not the operator-facing 24h definition.

Production deployment backup for PR #47:
- `/opt/evrasia-ai-bot/backups/pr47-new-24h-20260911-201030`
- DB dump: `/opt/evrasia-ai-bot/backups/pr47-new-24h-20260911-201030/evrasia_ai_bot.dump`

Deployment/auth invariant:
- production mutation on `eur-bot-01` is performed as `root`;
- GHCR credentials belong to user `tech` in `/home/tech/.docker/config.json`;
- root intentionally has no GHCR auth entry;
- for image pull, reuse `tech` auth (for example `runuser -u tech -- env HOME=/home/tech DOCKER_CONFIG=/home/tech/.docker docker pull ...`), never copy token to root and never ask for a new token unless existing auth is proven unusable.

## 2. Anti-Fraud invariants

- advisory/investigative; risk is not proof of fraud; blocking is operator-controlled;
- grouping evidence is distinct from risk evidence;
- Bitrix is account-state source of truth;
- `ACTIVE=Y,BLOCKED=N` = Активен; `ACTIVE=N,BLOCKED=N` = Неактивен; any `BLOCKED=Y` = Заблокирован;
- blocked/inactive excluded from operational views by default while retaining distinct state;
- consent choices must not become fraud/risk/grouping evidence merely because they exist;
- public legal documents must not expose internal Anti-Fraud/device-linking mechanics.

Current Anti-Fraud DB relevant objects:
- `anti_fraud_accounts` — account identity/state cache; does **not** store current legal-consent events;
- `anti_fraud_case_state` — case dynamics snapshots;
- `anti_fraud_web_account_state` — first appearance in web Anti-Fraud for 24h `Новый` semantics;
- `anti_fraud_sync_runs` / `anti_fraud_sync_state` — authoritative protected-cycle/sync state.

## 3. Website legal production state

Website host: `evrasia.spb.ru` / alias `evrasia.rest`.
Document root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`.

Canonical legal catalog:
- `/legal/public-offer/`
- `/legal/personal-data-consent/`
- `/legal/privacy-policy/`
- `/legal/marketing-consent/`

Public offer revision **11.09.2026** is production and includes §1.19 `Недобросовестное использование`. Privacy policy is **PRODUCTION / ACCEPTED**. PD and marketing consents are separate documents; marketing remains voluntary.

Legacy compatibility PDF:
- public URL: `https://evrasia.spb.ru/upload/docs/protection_personal_data.pdf`
- production SHA256: `f11866dcaa33fae115b0710261a4231fb07ff590b323f4bb1054a3bd76e46cad`
- size: 267105 bytes
- this is the corrected 9-page current offer PDF used for legacy integrations.

`/club/` source file:
- `/home/site_evrasia/web/evrasia.spb.ru/public_html/club/index.php`
- old bonus-program PDF reference was `/upload/docs/bonus_rules.pdf?v2025`;
- production page was updated to point to `/upload/docs/protection_personal_data.pdf` (relative URL so both `evrasia.spb.ru` and alias `evrasia.rest` work against the same docroot).

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

## 5. Native Bitrix consent subsystem — exact storage map

Bitrix production DB inspected read-only on 2026-09-11:
- database: `eurasia_new`
- consent subsystem uses native Bitrix tables, not custom `UF_*` fields.

Current active agreements:
- ID 1 `EVRASIA_OFFER_20260911` — Договор об участии в программе лояльности «Бонусный Клуб Евразия» — редакция 11.09.2026;
- ID 2 `EVRASIA_PD_20260911` — Согласие на обработку персональных данных — редакция 11.09.2026;
- ID 3 `EVRASIA_MARKETING_20260911` — Согласие на получение рекламных и информационных сообщений — редакция 11.09.2026.

Consent definitions table:
- `b_consent_agreement`
- important columns: `ID`, `CODE`, `DATE_INSERT`, `ACTIVE`, `NAME`, `TYPE`, `LANGUAGE_ID`, `DATA_PROVIDER`, `AGREEMENT_TEXT`, `LABEL_TEXT`, `SECURITY_CODE`, `USE_URL`, `URL`, `IS_AGREEMENT_TEXT_HTML`.

Consent events table (this is the key table for answering “who accepted what and when”):
- `b_consent_user_consent`
- columns:
  - `ID`
  - `DATE_INSERT`
  - `AGREEMENT_ID`
  - `USER_ID`
  - `IP`
  - `URL`
  - `ORIGIN_ID`
  - `ORIGINATOR_ID`
- production row count at inspection: 337.

Other consent tables:
- `b_consent_field` — columns `ID`, `AGREEMENT_ID`, `CODE`, `VALUE`; currently 0 rows;
- `b_consent_user_consent_item` — columns `ID`, `USER_CONSENT_ID`, `VALUE`; currently 0 rows.

Important search rule:
- do **not** look for current legal acceptance in USER `UF_*` fields; read `b_consent_user_consent` by `AGREEMENT_ID` and `USER_ID`;
- consent lookup is read-only unless explicitly approved otherwise;
- do not expose IP addresses, phones or other unnecessary personal data in diagnostic output;
- for current mandatory acceptance use agreement IDs 1 and 2; agreement 3 is optional marketing consent.

Consent persistence uses `Bitrix\Main\UserConsent\Consent::addByContext(...)`; no custom ledger was created.

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

## 7. Legacy offer compatibility — PRODUCTION

The legacy URL used by SamZaberu and other old links:
`https://evrasia.spb.ru/upload/docs/protection_personal_data.pdf`

Production now serves the approved corrected 9-page offer PDF, revision 11.09.2026:
- SHA256 `f11866dcaa33fae115b0710261a4231fb07ff590b323f4bb1054a3bd76e46cad`
- size 267105 bytes.

Known backups:
- original legacy PDF backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/legacy-offer-pdf/20260911-150823/protection_personal_data.pdf`
- prior 9-page revision backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/legacy-offer-pdf/20260911-151421/protection_personal_data.pdf`.

Longer term, inspect SamZaberu code and migrate it to the canonical public-offer URL rather than relying on the obsolete filename.

## 8. Continuation point for new chat

Confirmed:
- Anti-Fraud PR #47 `Новый` 24h semantics: **PRODUCTION / ACCEPTED**;
- bot production image: `sha256:3defaa7388f2278dfa7767e1ea79d2c12c1f0121f73eb208c034b153ade2d280`;
- bot DB migrations: **21**;
- website legal catalog: **PRODUCTION**;
- offer 11.09.2026: **PRODUCTION**;
- privacy policy: **PRODUCTION / ACCEPTED**;
- signup consent implementation: **PRODUCTION**;
- native Bitrix agreements: **PRODUCTION**;
- native consent event location: `eurasia_new.b_consent_user_consent`;
- existing-user account popup: **PRODUCTION / E2E ACCEPTED for USER_ID 880339**;
- rollout to all historical users: **NOT DONE**;
- legacy compatibility PDF replacement: **PRODUCTION**;
- `/club/` bonus-program link points to current compatibility PDF.

For future consent inspection:
1. Anti-Fraud USER_ID source is on `eur-bot-01` / PostgreSQL `anti_fraud_accounts`;
2. legal consent events are on website host `evrasia` / MySQL DB `eurasia_new` / table `b_consent_user_consent`;
3. required current agreements are IDs 1 and 2;
4. join logically by Bitrix `USER_ID` / `bitrix_user_id`;
5. keep diagnostics read-only and do not output unnecessary personal data.

Next chat: read this file, `docs/WEBSITE_LEGAL_CONSENT_INTEGRATION.md` and `docs/SERVER_SCRIPT_RULES.md`, then inspect factual production state before any mutation.
