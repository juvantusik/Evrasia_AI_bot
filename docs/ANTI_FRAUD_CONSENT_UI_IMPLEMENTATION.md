# Anti-Fraud consent UI implementation

> Created 2026-09-12. This document records the confirmed bot-side data flow and the implementation state for displaying current Offer/PD consents in the Anti-Fraud operator UI.

## 1. Confirmed architecture

The protected website endpoint is already production-verified:

`Bitrix b_consent_user_consent -> POST /api/internal/anti-fraud/account-map`

The bot-side propagation path is:

`account-map -> BitrixAntiFraudAccountGateway -> syncBitrixAccountsOnce -> anti_fraud_accounts -> loadContactMap()/exposeFullContacts -> /api/anti-fraud/cases + /api/anti-fraud/accounts -> AntiFraudPage.tsx`

Important architectural decision:
- do not query Bitrix/MySQL from the browser;
- do not call the protected website endpoint on every page render;
- keep consent as the same kind of periodically refreshed Bitrix-derived snapshot already used for account status;
- consent fields are informational only and do not participate in scoring, grouping, case formation, recommendations, blocking or unblock logic.

## 2. Source contract

Current website account-map fields:
- `offer_accepted`
- `offer_accepted_at`
- `offer_source`
- `pd_accepted`
- `pd_accepted_at`
- `pd_source`

Allowed normalized sources:
- `signup`
- `account_gate`
- `other`
- `null` when no current consent exists

Bot gateway maps them to:
- `offerAccepted`
- `offerAcceptedAt`
- `offerSource`
- `pdAccepted`
- `pdAcceptedAt`
- `pdSource`

The gateway validates booleans, ISO-compatible dates and the source allowlist. Unknown source values are rejected rather than silently accepted.

## 3. Local PostgreSQL snapshot

Migration: `0021_anti_fraud_consent_status.sql`.

Table: `anti_fraud_accounts`.

New nullable columns:
- `offer_accepted boolean`
- `offer_accepted_at timestamptz`
- `offer_source text`
- `pd_accepted boolean`
- `pd_accepted_at timestamptz`
- `pd_source text`

`offer_source` and `pd_source` are constrained to `signup`, `account_gate`, `other` or NULL.

Why nullable:
- immediately after migration and before the first successful account-map refresh, NULL means `not synchronized yet`;
- FALSE means the source was successfully checked and the current agreement event is absent;
- TRUE means the current agreement event exists.

This distinction must not be removed by adding a FALSE default.

## 4. Collector persistence

`syncBitrixAccountsOnce()` updates the six consent columns together with other account-map state on each account refresh.

Change detection uses `IS DISTINCT FROM`, so a change in consent presence, time or source marks the account snapshot as updated.

If no source data changed, `last_synced_at` is still refreshed as before.

## 5. Web API

`artifacts/api-server/src/routes/anti-fraud-web.ts` reads the consent snapshot from `anti_fraud_accounts` in the existing `loadContactMap()` query.

`exposeFullContacts()` attaches the six consent values to both:
- `/api/anti-fraud/cases`
- `/api/anti-fraud/accounts`

No extra website/Bitrix call occurs during a browser page load.

## 6. Operator UI

Target placement: compactly to the right of the account name in expanded case account cards and the Accounts tab.

Display contract:
- `Оферта ✓` when current offer consent exists;
- `ПД ✓` when current PD consent exists;
- neutral `Оферта —` / `ПД —` when the source was synchronized and consent is absent;
- `?` when the bot DB field is still NULL/not synchronized;
- same source/time is collapsed to compact metadata such as `Регистрация · 11.09 19:22` or `ЛК · 12.09 08:15`;
- exact per-agreement source/time remains available in the HTML title tooltip;
- if offer and PD differ, the UI says `раздельные события` instead of inventing one combined timestamp/source.

Source labels:
- `signup` -> `Регистрация`
- `account_gate` -> `ЛК`
- `other` -> `Другой источник`

Accepted badges are green. Missing consent is intentionally neutral rather than red because absence is not itself an Anti-Fraud alert.

## 7. Current implementation state

Feature branch:
`feature/v1.7-antifraud-consent-ui`

Implemented in branch:
- migration `0021`;
- Drizzle schema fields;
- Drizzle journal entry idx 21;
- strict gateway parsing;
- gateway tests updated for the new contract;
- collector persistence;
- web API propagation;
- compact React badges and CSS;
- CI migration expectation updated from 21 to 22;
- CI schema-smoke now verifies all six consent columns in `anti_fraud_accounts`.

Production deployment status:
- NOT DEPLOYED;
- production bot DB is still at migration count 21 through `0020` until this branch is reviewed/merged/deployed;
- website account-map production endpoint is already deployed and verified independently.

## 8. Deployment verification requirements

Before production cutover:
1. PR CI/build must pass.
2. Confirm migration count baseline and backup production PostgreSQL.
3. Deploy immutable image by digest using the established root/tech-GHCR procedure.
4. Verify migration count increments from 21 to 22 and columns exist.
5. Run/await one successful account-map refresh.
6. Verify known users in `anti_fraud_accounts`, including at least one `signup` and USER_ID 880339 `account_gate` control.
7. Verify `/api/anti-fraud/cases` or `/accounts` exposes the six fields.
8. Verify UI visually shows green accepted badges and source/time.
9. Confirm risk scores/case membership are unchanged by the consent fields.

## 9. CI / migration rule

Confirmed failure mode on PR #48, run 347:
- code typecheck passed;
- all 75 automated tests passed;
- API and frontend builds passed;
- smoke-test failed because the workflow still expected 21 migrations after migration `0021` had been added.

Rule for all future migrations:
- when a migration is added, update the CI expected migration count in the same change;
- add a schema-level smoke assertion for the structures created or changed by that migration;
- do not treat a successful image build alone as proof that the migration contract is correct;
- a PR with a stale migration-count smoke check is not ready for merge even if application tests are green.

For `0021`, CI must verify:
- migration count = 22;
- `anti_fraud_accounts.offer_accepted` exists;
- `anti_fraud_accounts.offer_accepted_at` exists;
- `anti_fraud_accounts.offer_source` exists;
- `anti_fraud_accounts.pd_accepted` exists;
- `anti_fraud_accounts.pd_accepted_at` exists;
- `anti_fraud_accounts.pd_source` exists.

## 10. DO NOT REPEAT

- Do not derive consent from USER_ID age/range. Use ledger time/source.
- Do not confuse all `anti_fraud_accounts` rows with web-visible Anti-Fraud accounts.
- Do not give the bot direct website MySQL access for this UI.
- Do not fetch consent from Bitrix per browser render.
- Do not default new consent snapshot booleans to FALSE; NULL has a distinct `not synchronized` meaning.
- Do not collapse offer and PD permanently into one source/time field; they are separate legal events.
- Do not let consent state affect scoring, grouping or blocking unless a future explicit business decision changes that rule.
- Do not add a DB migration without updating both the CI migration-count expectation and a schema-smoke for the new database contract.
