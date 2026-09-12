# Anti-Fraud consent UI implementation

> Created 2026-09-12. This document records the confirmed bot-side data flow and the implementation state for displaying current Offer/PD consents in the Anti-Fraud operator UI.

## 1. Confirmed architecture

The protected website endpoint is production-verified:

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

PR #48 was merged into `main`.

Merge revision:
`397a06adbeb9b8cde9ed4c088da7e3b820b0eba9`

Production immutable image:
`ghcr.io/juvantusik/evrasia_ai_bot@sha256:6230a8a198cefe39a5ef0e3f6934f74660ce7e4629d0486cd46f0859a4cc2830`

Production deployment on `eur-bot-01` completed successfully on 2026-09-12.

Confirmed production facts:
- predeploy runtime image matched the previous immutable baseline `sha256:3defaa7388f2278dfa7767e1ea79d2c12c1f0121f73eb208c034b153ade2d280`;
- predeploy DB migration count was 21;
- PostgreSQL backup was created and verified readable;
- deployment used existing GHCR authorization of console user `tech` while deployment itself ran as `root`;
- new image OCI revision matched merge revision `397a06adbeb9b8cde9ed4c088da7e3b820b0eba9`;
- new runtime image is the exact immutable digest above;
- application health is `healthy`;
- restart count after deploy was 0;
- migration `0021` applied successfully and migration count is now 22;
- all six consent snapshot columns exist in `anti_fraud_accounts`;
- `/api/healthz` returned `{"status":"ok"}`;
- `/api/anti-fraud/case-dynamics` returned HTTP 200;
- scheduler is enabled and idle after deploy;
- rollback was not needed.

Production backup:
- directory: `/opt/evrasia-ai-bot/backups/pr48-consent-ui-20260912-081533`
- database dump: `/opt/evrasia-ai-bot/backups/pr48-consent-ui-20260912-081533/evrasia_ai_bot.dump`
- DB dump size at creation: `2134177` bytes

Immediately after container recreation, `CONSENT_POPULATED_ROWS=0`. This was the expected pre-refresh state before the first account-map refresh.

## 8. Production refresh verification / acceptance

A manual production account refresh was executed through the built CLI:

`/app/dist/anti-fraud-bitrix-account-cli.mjs`

Refresh result:
- `runId=791dc45f-56f2-47aa-a643-7892bf78087c`
- `requestedAccounts=8493`
- `resolvedAccounts=8490`
- `unresolvedAccounts=3`
- `updatedAccounts=8490`
- CLI exit code 0 / `status=ok`

Consent snapshot population changed:
- before refresh: `0`
- after refresh: `8490`

Known production controls verified from local PostgreSQL:
- USER_ID `880339`: Offer=TRUE, PD=TRUE, source=`account_gate`, both at `2026-09-11 14:34:31` Moscow time;
- USER_ID `2591066`: Offer=TRUE, PD=TRUE, source=`signup`, both at `2026-09-11 15:50:45`;
- USER_ID `2591074`: Offer=TRUE, PD=TRUE, source=`signup`, both at `2026-09-11 15:58:24`;
- USER_ID `2591261`: Offer=TRUE, PD=TRUE, source=`signup`, both at `2026-09-11 18:49:03`;
- USER_ID `2591291`: Offer=TRUE, PD=TRUE, source=`signup`, both at `2026-09-11 19:22:23`;
- USER_ID `2591297`: Offer=TRUE, PD=TRUE, source=`signup`, both at `2026-09-11 19:26:57`.

Additional production checks:
- `/api/anti-fraud/accounts` returned HTTP 200 and exposes consent fields;
- application health remained OK after refresh;
- scheduler remained enabled;
- no warnings or failures in the refresh verification (`PASS_COUNT=10`, `WARN_COUNT=0`, `FAIL_COUNT=0`).

Status:
- backend consent propagation is `PRODUCTION ACCEPTED`;
- data path `Bitrix -> account-map -> bot collector -> PostgreSQL -> Anti-Fraud API` is fully verified;
- remaining acceptance item is visual UI verification in the browser only.

## 9. Final visual verification requirement

Open the production Anti-Fraud web interface and verify visually:
1. accepted users display green `Оферта ✓` and `ПД ✓` badges;
2. USER_ID `880339` displays source label `ЛК` with the expected date/time;
3. at least one known signup control displays `Регистрация` with the expected date/time;
4. layout remains compact next to the account name on the relevant account views;
5. no visual regression in account/case layout.

Risk scoring/case membership must remain unaffected by the consent fields.

## 10. CI / migration rule

Confirmed failure mode on PR #48 before CI correction:
- code typecheck passed;
- all 75 automated tests passed;
- API and frontend builds passed;
- smoke-test failed because the workflow still expected 21 migrations after migration `0021` had been added.

Rule for all future migrations:
- when a migration is added, update the CI expected migration count in the same change;
- add a schema-level smoke assertion for the structures created or changed by that migration;
- do not treat a successful image build alone as proof that the migration contract is correct;
- a PR with a stale migration-count smoke check is not ready for merge even if application tests are green.

For `0021`, CI verifies:
- migration count = 22;
- `anti_fraud_accounts.offer_accepted` exists;
- `anti_fraud_accounts.offer_accepted_at` exists;
- `anti_fraud_accounts.offer_source` exists;
- `anti_fraud_accounts.pd_accepted` exists;
- `anti_fraud_accounts.pd_accepted_at` exists;
- `anti_fraud_accounts.pd_source` exists.

## 11. DO NOT REPEAT

- Do not derive consent from USER_ID age/range. Use ledger time/source.
- Do not confuse all `anti_fraud_accounts` rows with web-visible Anti-Fraud accounts.
- Do not give the bot direct website MySQL access for this UI.
- Do not fetch consent from Bitrix per browser render.
- Do not default new consent snapshot booleans to FALSE; NULL has a distinct `not synchronized` meaning.
- Do not collapse offer and PD permanently into one source/time field; they are separate legal events.
- Do not let consent state affect scoring, grouping or blocking unless a future explicit business decision changes that rule.
- Do not add a DB migration without updating both the CI migration-count expectation and a schema-smoke for the new database contract.
- Do not treat `CONSENT_POPULATED_ROWS=0` immediately after application recreation as proof of broken consent sync when the scheduler has not yet completed its first post-deploy refresh. Verify scheduler timing/status first, then verify the snapshot after a successful refresh.
