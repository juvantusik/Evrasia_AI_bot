# Evrasia AI Bot — Current Project Checkpoint

> **Authoritative continuation checkpoint.**
>
> Updated: **2026-09-20** after PR #58 production acceptance, Check-in Scout production verification and Bitrix phone-resolver completion for Anti-Fraud Step 2.
>
> Source priority: **production actual state → current GitHub → staging/test → current docs → older discussion**.

## 1. Bot production baseline

Host: `eur-bot-01` (`192.168.103.200`).

Current accepted bot application:

- repo: `juvantusik/Evrasia_AI_bot`
- production revision: `700422b3c9004c2d92092a166e50ac5e8e8a6d33`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:b9ef12f9ea198c31d253ff9e07821c9c2aaa3aaa98fc286c0322c6c2534f5348`
- image ID: `sha256:700a55f7cc915f4945a65955c06f65c2a739be98678fb2fd963cd50edfa5564d`
- Anti-Fraud threshold: 40000
- scheduler: enabled, 15 min
- production migrations: **24**
- app container: **running healthy** at acceptance.

PR #58 is **PRODUCTION / OPERATOR ACCEPTED**.

PR #58 UI semantics:

- one USER_ID on a Trusted Device hash → **Устройство**;
- multiple USER_ID on the same hash → **Общее устройство**;
- both labels are the same Trusted Device hash type; the count of linked accounts is the distinction;
- if a second USER_ID later appears on the same hash, after sync/scoring it can become shared grouping evidence.

Scout UI wording after PR #58:

- first field renders as `дней с 3 чекинами за 7 дней`;
- second remains `дней с 3+ чекинами за 60 дней`.

Critical nuance: the first change is **display-only**. Backend `days_2plus_7d` still counts days with `>=2` physical check-ins. Do not infer a backend threshold change from the label.

Production deployment backup for PR #58:

- `/opt/evrasia-ai-bot/backups/pr58-ui-labels-continuation-20260920-084234`

Deployment/auth invariant:

- production mutation on `eur-bot-01` is performed as `root`;
- GHCR credentials belong to user `tech` in `/home/tech/.docker/config.json`;
- root intentionally has no copied GHCR token;
- pull with the existing `tech` Docker config; never print/copy the credential.

Compose staging invariant confirmed by PR #58:

- the canonical Compose uses relative paths;
- stage temporary Compose in `/opt/evrasia-ai-bot/prod`, not `/tmp`;
- validate before replacing the canonical file.

Full current Anti-Fraud continuation:

`docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md`

## 2. Anti-Fraud invariants

- advisory/investigative; risk is not proof of fraud; blocking is operator-controlled;
- grouping evidence is distinct from risk evidence;
- Bitrix is account-state source of truth;
- `ACTIVE=Y,BLOCKED=N` = Активен; `ACTIVE=N,BLOCKED=N` = Неактивен; any `BLOCKED=Y` = Заблокирован;
- blocked/inactive excluded from operational views by default while retaining distinct state;
- consent choices must not become fraud/risk/grouping evidence merely because they exist;
- public legal documents must not expose internal Anti-Fraud/device-linking mechanics.

Current Anti-Fraud DB relevant objects:
- `anti_fraud_accounts` — account identity/state cache; does **not** equal current web-visible Anti-Fraud rows and must not be used as a proxy for UI population;
- `anti_fraud_case_state` — case dynamics snapshots;
- `anti_fraud_web_account_state` — first appearance in web Anti-Fraud for 24h `Новый` semantics and the correct source for web-visible USER_ID continuity checks;
- `anti_fraud_sync_runs` / `anti_fraud_sync_state` — authoritative protected-cycle/sync state.

Read-only production check on 2026-09-12:
- `anti_fraud_accounts`: 8318 cached account rows;
- `anti_fraud_web_account_state`: 139 web-visible account rows at inspection time.

Important diagnostic rule:
- never compare consent coverage against all `anti_fraud_accounts` when the question is about accounts visible in the Anti-Fraud web UI;
- first obtain the web-visible USER_ID set from `anti_fraud_web_account_state`, then intersect with Bitrix consent events.

## 2A. Anti-Fraud / Check-in Scout / manual investigation — current continuation

Check-in Scout Step 1 is **DONE / PRODUCTION / VERIFIED**.

Authoritative physical source:

- Bitrix offline-order/check-in store fed by `VIP_TODAY`;
- `VIP_HISTORY` is not a reliable physical-visit counter because one physical visit can produce multiple monetary/event rows.

Current Scout code semantics:

- 1 physical check-in/day = normal;
- 2+ in one Moscow calendar day = persistent WATCH;
- 3rd check-in/day = immediate targeted deep check;
- another WATCH day with 2+ = deep check;
- WATCH persists through current day + two subsequent calendar days;
- deep confirmation = 3 different days with 2+ in 7d OR 3 different days with 3+ in 60d;
- confirmed frequency independently gives Risk 100 / Critical;
- no auto-block.

PR #56 is the corrected Scout implementation. PR #55's unsafe image must never be deployed.

Bitrix protected Scout endpoint is production:

- route: `/api/internal/anti-fraud/checkins`;
- actual route file: `/home/site_evrasia/web/evrasia.spb.ru/public_html/local/routes/api.php`;
- Scout service SHA: `fd497e84b1ddb3afc16e497395215576dd38feb9d5e73132b4f9278b48f16e9b`.

Manual-investigation Step 2 status:

- requirement: **«Добавить на проверку»** by phone;
- protected Bitrix phone resolver: **DONE / PRODUCTION / VERIFIED**;
- route: `/api/internal/anti-fraud/phone-resolve`;
- current Bitrix route SHA after resolver deployment: `39abfc79b1cb4291688f48c1cb47ce53f844fb627138267ee3aaf6b3947f792e`;
- resolver service SHA: `2a9ed0b8b8e87d7965d9e9f1ff474121e4605d0fa4c399dbdcee6f30bc5c8d8e`;
- unique → HTTP 200;
- invalid → 400;
- not found → 404;
- ambiguous → 409 without choosing a USER_ID;
- internal/candidate-limit problem → 503;
- no Bitrix write / no blocking.

Bot-side Step 2 is **MERGED / DEPLOYED / VERIFIED** via PR #60:

1. protected phone gateway using exact request field `phone`;
2. persistent `anti_fraud_operator_investigations` state;
3. explicit operator-authorized 60-day enrichment without faking automatic risk gate;
4. normal scoring;
5. visibility even at automatic Risk 0;
6. separate operator source/reason;
7. immediate first-seen persistence for the existing 24-hour `Новый` rule;
8. no auto-block;
9. restart-safe worker and scheduler resume;
10. CI + production API/UI/schema smoke.

Production acceptance: revision `f98d10c327e14b6dd5a34a9117ce25310ed6180e`, immutable digest `sha256:6b21a15ad09bd82643401e6d1f3a2c18ab8dd42adcdfb1f4997b26a71f487e40`, migrations 25, 13 PASS / 0 FAIL / 0 WARN, rollback not required.

Hard evidence boundary:

- operator-confirmed control purchase is operator evidence;
- do not present it as if automatic telemetry discovered it;
- automatic device/frequency/contact reasons remain separate.

The current migration journal includes `0024_anti_fraud_operator_investigation`; production migration count is **25**. Inspect current main before creating any next migration.

See `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md` for the full handoff.

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

Forensic production verification on 2026-09-12 confirmed that signup-created consent events use:
- `ORIGINATOR_ID=evrasia_signup`
- `ORIGIN_ID=<USER_ID>`
- agreement IDs 1 and 2 always for successful new signup;
- agreement ID 3 only when optional marketing was accepted.

## 5. Native Bitrix consent subsystem — exact storage map

Bitrix production DB inspected read-only:
- database: `eurasia_new`
- consent subsystem uses native Bitrix tables, not custom `UF_*` fields.

Current active agreements were created at `2026-09-11 13:09:00`:
- ID 1 `EVRASIA_OFFER_20260911` — Договор об участии в программе лояльности «Бонусный Клуб Евразия» — редакция 11.09.2026;
- ID 2 `EVRASIA_PD_20260911` — Согласие на обработку персональных данных — редакция 11.09.2026;
- ID 3 `EVRASIA_MARKETING_20260911` — Согласие на получение рекламных и информационных сообщений — редакция 11.09.2026.

Consent definitions table:
- `b_consent_agreement`
- important columns: `ID`, `CODE`, `DATE_INSERT`, `ACTIVE`, `NAME`, `TYPE`, `LANGUAGE_ID`, `DATA_PROVIDER`, `AGREEMENT_TEXT`, `LABEL_TEXT`, `SECURITY_CODE`, `USE_URL`, `URL`, `IS_AGREEMENT_TEXT_HTML`.

Consent events table (key table for “who accepted what, when, and by which mechanism”):
- `b_consent_user_consent`
- columns:
  - `ID`
  - `DATE_INSERT`
  - `AGREEMENT_ID`
  - `USER_ID`
  - `IP`
  - `URL`
  - `ORIGIN_ID`
  - `ORIGINATOR_ID`.

Other consent tables:
- `b_consent_field` — columns `ID`, `AGREEMENT_ID`, `CODE`, `VALUE`; 0 rows at inspection;
- `b_consent_user_consent_item` — columns `ID`, `USER_CONSENT_ID`, `VALUE`; 0 rows at inspection.

Important search rule:
- do **not** look for current legal acceptance in USER `UF_*` fields; read `b_consent_user_consent` by `AGREEMENT_ID` and `USER_ID`;
- use `DATE_INSERT`, `ORIGINATOR_ID` and `ORIGIN_ID` before inferring how a consent was created;
- consent lookup is read-only unless explicitly approved otherwise;
- do not expose IP addresses, phones or other unnecessary personal data in diagnostic output;
- for current mandatory acceptance use agreement IDs 1 and 2; agreement 3 is optional marketing consent.

Consent persistence uses `Bitrix\Main\UserConsent\Consent::addByContext(...)`; no custom ledger was created.

### Verified consent origins for web-visible Anti-Fraud users

At the 2026-09-12 read-only check, 139 USER_ID were present in `anti_fraud_web_account_state`.

Five of those web-visible USER_ID also had both current mandatory agreements 1+2. Forensic inspection proved all five were **new registrations after the consent rollout**, not existing-user popup acceptances:

- USER_ID `2591066`: registered `2026-09-11 15:50:44`; agreements 1+2 written `15:50:45`; `ORIGINATOR_ID=evrasia_signup`;
- USER_ID `2591074`: registered `2026-09-11 15:58:24`; agreements 1+2 written `15:58:24`; `ORIGINATOR_ID=evrasia_signup`;
- USER_ID `2591261`: registered `2026-09-11 18:49:03`; agreements 1+2 written `18:49:03`; `ORIGINATOR_ID=evrasia_signup`;
- USER_ID `2591291`: registered `2026-09-11 19:22:23`; agreements 1+2+3 written `19:22:23`; `ORIGINATOR_ID=evrasia_signup`;
- USER_ID `2591297`: registered `2026-09-11 19:26:57`; agreements 1+2+3 written `19:26:57`; `ORIGINATOR_ID=evrasia_signup`.

Therefore these five are expected and do **not** indicate that the existing-user account gate was enabled globally.

## 6. Existing-user personal-account consent popup — PRODUCTION / CONTROLLED E2E ACCEPTED

A mandatory popup is implemented for authenticated existing users who are missing the current offer and/or PD agreement acceptance.

**Current rollout scope remains intentionally limited to Bitrix USER_ID 880339. It is not enabled for all historical users.**

Popup behavior:
- checks current offer agreement ID 1 and PD agreement ID 2;
- requires only missing required agreement(s);
- links to current offer, PD consent and Privacy Policy;
- does not include marketing;
- does not modify existing `UF_SMS` or `UF_SUBSCRIBE` preferences;
- writes native Bitrix consent events only for required offer/PD acceptance;
- version-aware agreement IDs/codes provide the basis for future re-consent when a new legal version is created.

Latest forensic production state for controlled USER_ID `880339`:
- registered `2022-02-06 15:47:53`;
- consent ID 79 → agreement 1 / `EVRASIA_OFFER_20260911`;
- consent ID 80 → agreement 2 / `EVRASIA_PD_20260911`;
- timestamp `2026-09-11 14:34:31`;
- `ORIGINATOR_ID=evrasia_account_gate`;
- `ORIGIN_ID=account_880339`.

These are the currently verified physical rows in `b_consent_user_consent`; they supersede earlier checkpoint values 57/58 at 14:16:11.

The user confirmed the popup itself works correctly. This account-gate path is therefore **E2E ACCEPTED for the controlled user only**.

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
- web-visible Anti-Fraud set must be read from `anti_fraud_web_account_state`, not inferred from all `anti_fraud_accounts`;
- website legal catalog: **PRODUCTION**;
- offer 11.09.2026: **PRODUCTION**;
- privacy policy: **PRODUCTION / ACCEPTED**;
- signup consent implementation: **PRODUCTION**;
- native Bitrix agreements: **PRODUCTION**;
- native consent event location: `eurasia_new.b_consent_user_consent`;
- existing-user account popup: **PRODUCTION / E2E ACCEPTED for USER_ID 880339 only**;
- rollout to all historical users: **NOT DONE**;
- five web-visible Anti-Fraud users with agreements 1+2 were verified as post-rollout `evrasia_signup` registrations, not account-gate acceptances;
- legacy compatibility PDF replacement: **PRODUCTION**;
- `/club/` bonus-program link points to current compatibility PDF.

For future consent inspection:
1. obtain web-visible USER_ID from `eur-bot-01` PostgreSQL table `anti_fraud_web_account_state`;
2. legal consent events are on website host `evrasia` / MySQL DB `eurasia_new` / table `b_consent_user_consent`;
3. required current agreements are IDs 1 and 2;
4. join logically by Bitrix `USER_ID` / `bitrix_user_id`;
5. inspect `DATE_INSERT`, `ORIGINATOR_ID`, `ORIGIN_ID` before classifying the consent source;
6. keep diagnostics read-only and do not output unnecessary personal data.

Next chat: read this file, `docs/WEBSITE_LEGAL_CONSENT_INTEGRATION.md` and `docs/SERVER_SCRIPT_RULES.md`, then inspect factual production state before any mutation.


## 9. TOTP 2FA / protected-profile workstream — PLANNED / READ-ONLY INVESTIGATED

Dedicated authoritative checkpoint:

`docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`

Continuation keyword:

`ПАНДА ДВА`

Operator request:

- optional TOTP 2FA for guest profiles using Яндекс Ключ / Google Authenticator / compatible TOTP apps;
- enrollment must first confirm the current account phone by SMS, so stolen login/password alone cannot enroll an attacker's authenticator;
- TOTP does **not** replace the bonus-spending PIN;
- after a profile is safely enrolled in 2FA, the intended benefit is to allow the bonus-spending PIN to be displayed directly in the personal account instead of waiting for VK/SMS delivery;
- ordinary profiles must keep the current PIN-delivery behavior.

Pilot is restricted to Bitrix `USER_ID=880339`.

Production read-only facts confirmed on 2026-09-18:

- Bitrix native MFA exists: `Bitrix\Security\Mfa\Otp`;
- global runtime state: `OTP_ENABLED=NO`, recovery codes disabled, Bitrix OTP SMS capability reports enabled;
- native storage: `b_sec_user`; recovery storage: `b_sec_recovery_codes`;
- pilot user currently has no initialized/activated OTP and no secret;
- current website AJAX login uses `CUser->Login(...)` in `local/components/eurasia/signin/class.php`, while the older/general signin component already contains `Mfa\Otp::isOtpRequired()` rendering logic;
- current web bonus-PIN endpoint is `/local/php_interface/pincode.php`: it obtains the real PIN from RestIS and sends it by VK when available, otherwise by SMS;
- current V4 mobile PIN controller has analogous VK/SMS behavior;
- existing global logout/session-revocation logic can revoke Bitrix auth actions and mobile JWT/refresh state.

Security invariants:

- SMS ownership confirmation must happen **before** exposing the TOTP provisioning QR/secret;
- direct PIN must never be authorized only by `b_sec_user.ACTIVE=Y`;
- the current session must itself be known to have passed TOTP, or old sessions must be invalidated and a post-TOTP session marker used;
- after 2FA activation, pre-2FA sessions must be revoked;
- users without 2FA must continue to log in and receive PINs exactly as before;
- no custom TOTP store should be created unless native Bitrix MFA proves unsuitable;
- no 2FA production mutation has happened yet.

Exact continuation:

1. run the final pre-write audit described in `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md` to inspect existing `b_sec_user` population, exact Bitrix OTP options, optional/mandatory behavior, native setup call sequence, and login event wiring;
2. if safe, guarded pilot WRITE for USER_ID 880339 only: optional native Bitrix OTP + profile UI + SMS ownership check + QR + TOTP verify/activate + session revoke + login E2E;
3. only after that E2E acceptance, add direct web bonus-PIN behavior for a second-factor-confirmed pilot session.

When the operator says **ПАНДА ДВА**, resume from this checkpoint and do not repeat the already completed 2FA discovery audits.
