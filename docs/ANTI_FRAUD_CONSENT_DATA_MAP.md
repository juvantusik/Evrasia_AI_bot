# Evrasia AI Bot — Anti-Fraud / Consent Data Map

> Updated 2026-09-12 from live production inspection and successful account-map consent extension.

## 1. Systems and hosts

### Anti-Fraud bot
- host: `eur-bot-01`
- production IP used in project: `192.168.103.200`
- production mutation user: `root`
- GHCR auth owner: `tech`
- PostgreSQL container: `evrasia-ai-bot-db`
- PostgreSQL DB/role: `evrasia_ai_bot`

### Website / Bitrix
- host short name: `evrasia`
- full hostname: `evrasia.spb.ru`
- production document root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`
- Bitrix DB: `eurasia_new`
- production server work is performed as `root`

## 2. Anti-Fraud account population

### `anti_fraud_accounts`
Broad account identity/state cache used by Anti-Fraud. It is **not** the same thing as the set of accounts currently visible in the Anti-Fraud web UI.

A read-only production check on 2026-09-12 showed 8318 rows.

### `anti_fraud_web_account_state`
Schema:
- `bitrix_user_id`
- `first_seen_at`

Purpose:
- records first appearance in the web Anti-Fraud interface;
- drives the operator-facing 24h `Новый` status;
- correct continuity source when the question concerns accounts visible in the web Anti-Fraud interface.

A read-only production check on 2026-09-12 showed 139 rows at that moment.

### Mandatory correlation rule
When correlating consent state with **accounts visible in the web Anti-Fraud UI**, obtain USER_ID from `anti_fraud_web_account_state`, not all rows from `anti_fraud_accounts`.

## 3. Native Bitrix consent storage

Current consent subsystem uses native Bitrix tables, not USER `UF_*` fields.

### Agreements
Table: `b_consent_agreement`

Current production agreements created `2026-09-11 13:09:00`:
- `ID=1`, `CODE=EVRASIA_OFFER_20260911` — current public-offer agreement;
- `ID=2`, `CODE=EVRASIA_PD_20260911` — current personal-data agreement;
- `ID=3`, `CODE=EVRASIA_MARKETING_20260911` — optional marketing agreement.

Relevant columns:
- `ID`
- `CODE`
- `DATE_INSERT`
- `ACTIVE`
- `NAME`
- `TYPE`
- `LANGUAGE_ID`
- `DATA_PROVIDER`
- `AGREEMENT_TEXT`
- `LABEL_TEXT`
- `SECURITY_CODE`
- `USE_URL`
- `URL`
- `IS_AGREEMENT_TEXT_HTML`

### Consent events
Table: `b_consent_user_consent`

Relevant columns:
- `ID`
- `DATE_INSERT`
- `AGREEMENT_ID`
- `USER_ID`
- `IP`
- `URL`
- `ORIGIN_ID`
- `ORIGINATOR_ID`

This is the authoritative table for:
- whether a current agreement event exists;
- when it was recorded;
- which application flow created it.

Do not expose IP/URL when not necessary for the operator task.

### Other consent tables
`b_consent_field`
- `ID`
- `AGREEMENT_ID`
- `CODE`
- `VALUE`
- 0 rows at inspection.

`b_consent_user_consent_item`
- `ID`
- `USER_CONSENT_ID`
- `VALUE`
- 0 rows at inspection.

## 4. Verified consent origins

### Signup
Native consent events created by registration use:
- `ORIGINATOR_ID=evrasia_signup`
- `ORIGIN_ID=<USER_ID>`

For successful current signup:
- agreements 1 + 2 are required;
- agreement 3 is created only when optional marketing consent is accepted.

### Existing-user account gate
Native consent events created by the personal-account popup use:
- `ORIGINATOR_ID=evrasia_account_gate`
- `ORIGIN_ID=account_<USER_ID>`

Known forensic control account:
- USER_ID `880339`
- registered `2022-02-06 15:47:53`
- consent IDs `79` and `80`
- agreements `1` and `2`
- `DATE_INSERT=2026-09-11 14:34:31`
- `ORIGINATOR_ID=evrasia_account_gate`
- `ORIGIN_ID=account_880339`

These values supersede earlier provisional consent IDs/times recorded during earlier diagnostics.

## 5. Existing-user gate rollout

Initial controlled rollout was restricted to USER_ID `880339`.

On 2026-09-12 the hard-coded test-user restriction was removed from production `/account/index.php` while preserving `$USER->IsAuthorized()`.

Production result:
- rollout target: all authenticated users missing current required agreement events;
- offer + PD logic unchanged;
- marketing not added to the popup;
- `UF_SMS` unchanged;
- `UF_SUBSCRIBE` unchanged;
- production PHP syntax PASS;
- final `/account/index.php` SHA256: `9bf6ec5eb1ecf4435131ffe488037f60ba13bcee2b1a795dd5478179493ff94b`;
- rollback backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/account-consent-rollout/20260912-052122/index.php`;
- deployment result: 14 PASS / 0 FAIL; rollback not required.

## 6. Protected site-side Anti-Fraud account-map

Route:
`POST /api/internal/anti-fraud/account-map`

Route registration:
`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/routes/api.php`

Service:
`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/lib/Services/AntiFraudAccountMapService.php`

Request contract:
- protected by `X-Anti-Fraud-Token` / Bearer fallback;
- accepts address list `user_ids`;
- max 500 USER_ID;
- validates positive integer USER_ID;
- does not dump the whole Bitrix user table.

`loadUsers()` behavior:
- gets one Bitrix DB connection via `Application::getConnection()`;
- builds one validated integer `idList`;
- attempts canonical phones from `b_user_phone_auth`;
- loads block reason via `UF_AF_BLOCK_REASON` using `Bitrix\Main\UserTable`;
- loads base user rows from `b_user`;
- loads current consent rows in one additional batch query for the entire `idList`;
- does not do one consent query per user.

### Production consent extension — DEPLOYED 2026-09-12

Baseline service SHA before extension:
`c6832c1d16f496d6c7afc8bac64a111ec3c60860a8cf80e889fd0fef7061ab18`

Production service SHA after extension:
`5705d7586c35ced55975a3d228ed2acc148fff7bc9fbc7c217b7337368141a77`

Rollback backup:
`/home/site_evrasia/web/evrasia.spb.ru/backups/account-map-consent/20260912-053811/AntiFraudAccountMapService.php`

Deployment result:
- 15 PASS / 0 FAIL;
- rollback not required;
- database write: NO;
- Bitrix DB write: NO;
- marketing change: NO;
- Anti-Fraud scoring change: NO;
- production PHP syntax: valid.

Current response fields now include the original account-state fields plus:
- `offer_accepted` — boolean, current agreement ID 1 exists;
- `offer_accepted_at` — latest agreement 1 `DATE_INSERT` as ISO-8601 / Europe-Moscow, or null;
- `offer_source` — normalized source or null;
- `pd_accepted` — boolean, current agreement ID 2 exists;
- `pd_accepted_at` — latest agreement 2 `DATE_INSERT` as ISO-8601 / Europe-Moscow, or null;
- `pd_source` — normalized source or null.

Source normalization in production:
- `evrasia_signup` -> `signup`
- `evrasia_account_gate` -> `account_gate`
- any other originator -> `other`

Consent query contract:
- queries only `b_consent_user_consent`;
- only `AGREEMENT_ID IN (1,2)`;
- only requested USER_ID from current validated `idList`;
- orders by `USER_ID, AGREEMENT_ID, DATE_INSERT DESC, ID DESC`;
- first row per user/agreement is therefore the latest physical event;
- IP and URL are not exposed to the bot API.

Functional production verification succeeded for six known users and returned 12 expected agreement rows:
- USER_ID `880339`: agreements 1+2 at `2026-09-11T14:34:31+03:00`, source `account_gate`;
- USER_ID `2591066`: agreements 1+2 at `2026-09-11T15:50:45+03:00`, source `signup`;
- USER_ID `2591074`: agreements 1+2 at `2026-09-11T15:58:24+03:00`, source `signup`;
- USER_ID `2591261`: agreements 1+2 at `2026-09-11T18:49:03+03:00`, source `signup`;
- USER_ID `2591291`: agreements 1+2 at `2026-09-11T19:22:23+03:00`, source `signup`;
- USER_ID `2591297`: agreements 1+2 at `2026-09-11T19:26:57+03:00`, source `signup`.

The consent fields are informational only and must not affect scoring, grouping, blocking or case membership.

## 7. Planned bot/UI propagation

Operator requirement for Anti-Fraud UI: compactly show to the right of account name:
- current offer accepted / not accepted;
- current PD accepted / not accepted;
- source (`Регистрация` / `ЛК` in UI);
- acceptance time.

Preferred protected data flow:

`Bitrix b_consent_user_consent -> existing protected account-map -> bot sync/API -> Anti-Fraud UI`

The site-side `account-map` part is now production-ready. Next work is bot-side propagation of the six fields through gateway -> persistence/API -> React UI.

Do not give the bot direct MySQL access to the website DB merely for consent display.

Do not collapse offer and PD into one timestamp/source because they are independent legal events even when they currently occur together.

## 8. Errors / lessons — DO NOT REPEAT

1. **Wrong Anti-Fraud population**
   - Mistake: consent coverage was initially intersected with all 8318 cached rows in `anti_fraud_accounts`.
   - Correct rule: for web UI questions use `anti_fraud_web_account_state`.

2. **Consent-source inference without timestamps**
   - Mistake: USER_ID range was used to hypothesize signup origin before checking time/source.
   - Correct rule: inspect `DATE_INSERT`, `ORIGINATOR_ID`, `ORIGIN_ID`, and registration time.

3. **Unquoted heredoc**
   - Mistake: PHP embedded in unquoted shell heredoc caused shell interpretation/backtick command substitution.
   - Correct rule: use quoted heredocs (`<<'PHP'`, `<<'BASH'`) for literal embedded code.

4. **False PHP diagnostic PASS**
   - Mistake: a Bitrix error page occurred while shell RC was treated as success.
   - Correct rule: verify expected output/state in addition to RC.

5. **Wrong website hostname guard**
   - Mistake: one script required hostname `evrasia` while `hostname` returned `evrasia.spb.ru`.
   - Correct rule: use verified `hostname -s=evrasia` and/or full hostname `evrasia.spb.ru` according to the guard.

6. **Unrelated account-gate marker guard**
   - Mistake: rollout script required `evrasia_account_gate` text to be present directly in `/account/index.php`, but that marker was not located there.
   - Correct rule: inspect the exact current source and guard only the actual hard-coded rollout variable/condition being changed.

7. **Root/GHCR auth confusion**
   - Production deployment runs as root, but GHCR credentials are intentionally owned by `tech`.
   - Reuse `tech` auth without exposing token values; do not request/recreate credentials unnecessarily.

8. **Do not infer success from planned code**
   - Documentation may mark a structure or deployment as current only after successful production output or direct verification.
   - Planned fields belong under planned state until production SHA, syntax, functional checks and final status are confirmed.
