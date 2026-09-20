# Anti-Fraud / Check-in Scout / Manual Investigation — checkpoint 2026-09-20

> **Authoritative continuation document for the current Anti-Fraud workstream.**
>
> Use this document first when a new chat continues the Anti-Fraud / Trusted Device / Check-in Scout / manual-investigation work.
>
> Source priority remains: **actual production → current GitHub → staging/test → current docs → older discussion**.
>
> This checkpoint records the factual state after PR #58 was merged, deployed and operator-accepted on 2026-09-20.

---

## 1. Immediate continuation point

The current UI/device-label cleanup is **DONE / PRODUCTION / OPERATOR ACCEPTED**.

The next work item is **Step 2: manual Anti-Fraud investigation by phone**.

Already completed for Step 2:

- Bitrix protected phone resolver: **DONE / PRODUCTION / VERIFIED**.

Still pending in the bot:

1. bot gateway for the protected Bitrix phone resolver;
2. persistent PostgreSQL operator-investigation model;
3. explicit operator-authorized 60-day loyalty/history enrichment without faking an automatic risk gate;
4. normal Anti-Fraud scoring after enrichment;
5. web UI action **«Добавить на проверку»**, phone-first;
6. visible persistent operator reason/source, e.g. **Авито**, separated from automatic signals;
7. tests;
8. staged rollout and production verification;
9. documentation update after implementation.

Do **not** restart Check-in Scout Step 1 or the Bitrix resolver work. Those parts are already production-proven.

Issue tracking the manual-investigation requirement:

- GitHub issue #54: https://github.com/juvantusik/Evrasia_AI_bot/issues/54

---

## 2. Current bot production baseline — factual after PR #58

Production host:

- host: `eur-bot-01`
- IP: `192.168.103.200`
- app container: `evrasia-ai-bot-app`
- DB container: `evrasia-ai-bot-db`
- DB / role: `evrasia_ai_bot`
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- network: `evrasia-prod-internal`
- volume: `evrasia-postgres-prod-data`

Accepted production application after PR #58:

- revision: `700422b3c9004c2d92092a166e50ac5e8e8a6d33`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:b9ef12f9ea198c31d253ff9e07821c9c2aaa3aaa98fc286c0322c6c2534f5348`
- image ID: `sha256:700a55f7cc915f4945a65955c06f65c2a739be98678fb2fd963cd50edfa5564d`
- production migrations: **24**
- container state at acceptance: `running healthy`
- PR #58 deployment: **7 PASS / 0 FAIL**
- rollback: not required.

PR #58 deployment backup:

`/opt/evrasia-ai-bot/backups/pr58-ui-labels-continuation-20260920-084234`

Important: later documentation-only commits may advance GitHub `main` while production remains on the application revision above. Before any future production mutation, always read the actual runtime revision/image again.

---

## 3. PR lineage that matters for the current workstream

### PR #52 — generic operator watchlist

- merged;
- generic operator watchlist support exists through bot settings;
- this is **not** the final persistent manual-investigation model required by Step 2.

### PR #53 — temporary persistent Avito override experiment

- branch/head existed and CI passed;
- **closed unmerged**;
- never claim it was merged or deployed.

### PR #55 — first Check-in Scout implementation

- merged code produced an unsafe image that persisted far too many users;
- image/tag from that merge must **never be deployed**;
- superseded by PR #56.

### PR #56 — corrected Check-in Scout

- merged;
- deployed and verified in production;
- introduced migration `0023_anti_fraud_checkin_scout.sql`;
- production migration count became 24;
- Scout no longer writes its physical-checkin snapshot into `anti_fraud_visits`;
- only WATCH/deep/confirmed candidates persist;
- physical history is fetched through the protected site endpoint;
- no automatic blocking.

### PR #57 — show Trusted Device identifiers in risk cases

- merged and deployed;
- backend kept grouping semantics intact:
  - `devices` = shared devices used as linking evidence;
  - `trustedDevices` = all Trusted Device identifiers currently present in the bot link data for case accounts, including single-account identifiers;
- grouping still uses shared-linking evidence only;
- UI exposure made single-account device hashes visible and initially labeled them `Device ID`.

### PR #58 — Russian device labels + Scout display wording

PR:

https://github.com/juvantusik/Evrasia_AI_bot/pull/58

Merged revision:

`700422b3c9004c2d92092a166e50ac5e8e8a6d33`

Production accepted on 2026-09-20.

UI behavior after PR #58:

- one account on a device hash → **«Устройство»**;
- two or more accounts on the same device hash → **«Общее устройство»**;
- these are **not different identifier types**; both display the same Trusted Device hash type;
- the number of linked accounts is what changes the label.

If a hash that currently belongs to one account later becomes linked to another account, after the next sync/scoring cycle it becomes a shared device and participates in case grouping.

---

## 4. Trusted Device identity semantics — do not confuse the operator

A Trusted Device hash is an identifier of the web/device identity mechanism, not a hardware serial number.

For the website:

- browser cookie: `__Host-evrasia_device_id`;
- the server stores SHA-256, not the raw cookie;
- the same physical computer can legitimately accumulate multiple IDs after cookie deletion, another browser/profile, etc.;
- one account can therefore have multiple Trusted Device hashes;
- one hash can also be associated with several USER_ID values when several accounts are used with that identity.

Critical data-model invariant:

- `DEVICE_ID_HASH` must **not** be globally unique;
- the meaningful association is `(USER_ID, DEVICE_ID_HASH)`.

Do not treat IP address as device identity.

For authoritative Trusted Device accumulation diagnostics, use the Bitrix table `ev_trusted_devices` on host `evrasia`; see `docs/TRUSTED_DEVICE_DIAGNOSTICS.md`.

---

## 5. Check-in Scout — production architecture

### Why Scout exists

The old 60-day loyalty-history path is downstream of a risk gate. Therefore a frequency-only account could remain Risk 0 and never receive enough physical history for the intended frequency analysis.

Check-in Scout is a separate upstream detector of physical check-in frequency.

### Authoritative physical source

The authoritative physical source is the Bitrix offline-order/check-in store fed by `VIP_TODAY`, not `VIP_HISTORY`.

Existing Bitrix cron behavior:

- `cron_order_history.php`;
- approximately every 10 minutes;
- fetches `<VIP_TODAY pagesize="100" />`;
- persists offline order/check-in rows through `COfflineOrderHl`;
- relevant source identity includes `UF_RESTIS_ID`.

`VIP_HISTORY` is monetary/event-oriented and can contain multiple rows for one physical visit. It must not be used as a physical-visit counter.

Observed control-history duplication that led to this design:

- one control account: 76 `VIP_HISTORY` rows → 66 deduplicated physical-like tuples;
- another control account: 11 rows → 9 tuples.

Even `sourceRestisId` alone is not a universal physical-visit identity outside the dedicated Scout source.

### Current Scout business logic in code

Timezone: **Europe/Moscow**.

Current production logic:

1. 1 physical check-in in a Moscow calendar day → normal; no persistent Scout row.
2. 2+ unique physical check-ins in one day → persistent WATCH.
3. 3rd check-in in a day → immediate deep 60-day physical-history check.
4. While WATCH is active, another day with 2+ → deep 60-day check.
5. WATCH is retained through the current day + two subsequent calendar days.
6. If no qualifying repeat occurs, WATCH expires.

Deep-history confirmation is currently:

- **3 different days with 2+ physical check-ins during the last 7 days**, OR
- **3 different days with 3+ physical check-ins during the last 60 days**.

Confirmed frequency:

- independent of device/contact/multiaccount signals;
- produces Risk 100 / Critical;
- never auto-blocks.

### Important PR #58 UI wording nuance

The underlying technical field and calculation remain:

`days_2plus_7d` = days in the 7-day window whose physical count is **>= 2**.

Per the operator's requested business-facing wording, PR #58 changed only the rendered text from:

`дней с 2+ чекинами за 7 дней`

to:

`дней с 3 чекинами за 7 дней`

The second rendered field remains:

`дней с 3+ чекинами за 60 дней`.

**Do not infer from the new label that the backend threshold was changed. It was not.**  
Changing the actual Scout threshold requires a separate explicit business decision, code change and tests.

Technical DB/API keys were intentionally left unchanged in PR #58.

---

## 6. Check-in Scout production proof

Initial corrected production cycle after PR #56:

- fetched physical records: 5758
- persisted WATCH/deep/confirmed candidates: 332
- records resolved for deeper processing: 41
- statuses:
  - confirmed: 2
  - deep_check: 31
  - watching: 299
- triggers:
  - double_checkin: 291
  - repeated_double: 8
  - triple_checkin: 33
- Scout rows written into `anti_fraud_visits`: **0**

This verified the critical fix from PR #56: the Scout snapshot no longer pollutes the loyalty-visit table.

Known operator-confirmed control accounts used during investigation:

- USER_ID `27987` — operator-confirmed control purchase; Scout later confirmed persistent frequency; Risk 100;
- USER_ID `1969724` — operator-confirmed control purchase; Scout initially WATCH only; automatic Risk could remain 0.

These operator facts must remain distinct from automatic telemetry.

---

## 7. Bitrix Check-in Scout endpoint — production

Bitrix production host:

- host: `evrasia`
- FQDN: `evrasia.spb.ru`
- IP: `192.168.103.141`
- web root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`

Actual routes file:

`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/routes/api.php`

Do **not** use the obsolete guessed path `/local/php_interface/routes.php`.

Scout service:

`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/lib/Services/AntiFraudCheckinScoutService.php`

Accepted service SHA:

`fd497e84b1ddb3afc16e497395215576dd38feb9d5e73132b4f9278b48f16e9b`

Protected route:

`/api/internal/anti-fraud/checkins`

Modes:

- full snapshot: max 3 days;
- targeted history: max 60 days and a small explicit USER_ID list.

Raw RestIS/card data remain server-side.

Protected Anti-Fraud authentication is service-side. Reuse the existing token mechanism; never print the token value.

---

## 8. Step 2 phone resolver — DONE / PRODUCTION

The operator requirement is phone-first:

- UI action: **«Добавить на проверку»**;
- operator enters phone;
- USER_ID must be resolved server-side;
- USER_ID may exist as fallback/admin input, but phone is primary;
- ambiguous phone must fail clearly and must not select an account;
- result must become a persistent operator-origin investigation;
- operator source/reason (for example `Авито`) must be recorded separately from automatic signals;
- no auto-block.

### Resolver architecture chosen after production read-only research

Do **not** use `b_user_phone_auth` as the primary reverse lookup.

Reason:

- it had ~59k unique rows but did not cover the two control accounts;
- the actual profile phone lived in `b_user.PERSONAL_PHONE`.

Final production resolver strategy:

`input phone → normalize → FULLTEXT SEARCH_ADMIN_CONTENT candidate lookup → exact normalized b_user.PERSONAL_PHONE verification → 0 / 1 / many`

Why this design:

- `b_user.PERSONAL_PHONE` is source-of-truth verification;
- `b_user_index.SEARCH_ADMIN_CONTENT` had 100% coverage in a tested sample of 500 profile phones;
- `SEARCH_USER_CONTENT` had 0% phone coverage in that sample;
- direct equality on formatted profile phone was not chosen as the primary search because storage formatting is variable and unindexed.

### Production endpoint

Protected route:

`/api/internal/anti-fraud/phone-resolve`

Current route SHA after resolver deployment:

`39abfc79b1cb4291688f48c1cb47ce53f844fb627138267ee3aaf6b3947f792e`

Resolver service SHA:

`2a9ed0b8b8e87d7965d9e9f1ff474121e4605d0fa4c399dbdcee6f30bc5c8d8e`

Verified behavior:

- unauthorized → HTTP 401;
- valid unique profile phone → HTTP 200 + unique USER_ID;
- invalid input → HTTP 400;
- no match → HTTP 404;
- ambiguous phone → HTTP 409 + match count, **without choosing USER_ID**;
- candidate-limit/internal resolver problem → HTTP 503.

The resolver reuses the existing protected Anti-Fraud token mechanism. Token value is never printed.

Deployment verification:

- both known control accounts resolved correctly;
- synthetic ambiguous test resolved as ambiguous rather than being silently selected;
- no Bitrix account write;
- no DB write;
- no blocking;
- 15 PASS / 0 FAIL.

Backup from successful resolver deployment:

`/home/site_evrasia/backups/anti-fraud-phone-resolver-20260919-195427`

---

## 9. Step 2 bot-side design — accepted direction, not yet implemented

### A. Bitrix protected phone resolver

**DONE / PRODUCTION**.

### B. Bot resolver gateway

Pending.

Must:

- call the protected site endpoint;
- normalize and validate response contract;
- never leak protected HTTP body/secrets;
- preserve clear 400/404/409/503 semantics for the web layer.

### C. Persistent operator investigation model

Pending.

Current migration journal ends at:

- index/tag `0023_anti_fraud_checkin_scout`;
- production migration count 24.

Therefore the next bot migration is expected to be `0024_...`, but inspect current GitHub `main` immediately before implementation; documentation-only commits do not add a migration but another application change might.

Persistent state must survive refresh/restart and must not rely on the generic bot-setting watchlist.

### D. Operator-authorized 60-day history

Pending.

Current `anti-fraud-restis-history-enricher.ts` explicitly requires:

`riskGateConfirmed: true`

and refuses enrichment otherwise.

For manual investigation, **do not lie** by passing a fake automatic risk gate.

Implement an explicit operator-authorized path/contract or a separate orchestrator with auditable semantics.

### E. Scoring and visibility

Pending.

After phone resolution and account mapping:

- persist operator investigation;
- collect/sync the resolved Anti-Fraud account as needed;
- trigger the explicitly authorized 60-day enrichment;
- run the normal risk engine;
- show the account in Anti-Fraud even if automatic Risk remains 0;
- display operator-confirmed source/reason separately from automatic reasons;
- no automatic block.

### F. UI

Pending.

Required action:

**«Добавить на проверку»**

Primary input:

- phone.

Operator evidence/source examples:

- `Авито`.

USER_ID:

- optional fallback/admin use only.

Ambiguous phone:

- clear error;
- do not choose an account.

### G/H. Tests / rollout / docs

Pending.

---

## 10. Why the manual investigation model is necessary

Current case-builder behavior starts from automatic Risk > 0 and then expands through linking evidence.

Therefore an operator-confirmed account with Risk 0 can legitimately be absent from `/anti-fraud/cases` and even from the normal accounts search if it is not otherwise a candidate.

This was observed for USER_ID `1969724`:

- Scout status: WATCH;
- automatic overall Risk: 0;
- no automatic risk reasons;
- no multiaccount device evidence;
- absent from risk cases/accounts web search.

That absence was current design, not evidence that the physical check-in was lost.

Step 2 must solve that by making operator investigation a first-class persistent source of visibility.

---

## 11. Operator evidence vs automatic evidence — hard invariant

Operator-confirmed evidence and system-observed evidence are different categories.

Example:

- operator confirms a control purchase / known Avito seller;
- system may observe device sharing, physical frequency, contact similarity, or none of those.

Do not rewrite operator evidence as if the system discovered it automatically.

For the operator-confirmed workflow:

- source/reason must be explicit and auditable;
- an operator-confirmed case may intentionally be set to Risk 100 / Critical according to the accepted business requirement;
- the reason must say that it is operator-confirmed evidence;
- no auto-block.

---

## 12. Device grouping semantics after PR #57/#58

Backend keeps two concepts:

- `devices` — shared devices with multiple USER_ID values; used as linking/grouping evidence;
- `trustedDevices` — all Trusted Device identifiers attached to accounts in the case, including single-account ones; display context.

The UI currently renders all `trustedDevices` in the case details and labels:

- one USER_ID → **Устройство**;
- multiple USER_ID → **Общее устройство**.

The hash prefix is the same kind of field in both cases.

A single-account hash does **not** group accounts by itself.

When another USER_ID later appears on the same hash, the next sync/risk cycle can make it a shared linking device and group the accounts.

Do not confuse this with a statement that the two labels represent two different hash formats.

---

## 13. Production deployment lessons added by PR #58

### Failed attempts were safe

Two PR #58 deployment attempts stopped before production mutation because staged Compose validation failed.

Observed state:

- `APPLICATION_IMAGE_WRITE=NO`;
- rollback not required;
- production stayed on the old healthy revision.

### Root cause

The temporary Compose file had been staged in `/tmp`.

Current production Compose depends on relative paths. Moving only the YAML to another directory changes relative resolution.

Correct rule:

- stage temporary Compose in `/opt/evrasia-ai-bot/prod`;
- or explicitly use the real project directory while keeping relative resources resolvable;
- validate staged Compose before replacing canonical Compose.

The successful continuation used:

- temp Compose in the production Compose directory;
- exact old revision/image guard;
- verified immutable target image;
- verified backup;
- validation before write;
- post-deploy image/revision/digest check;
- migration count stayed 24.

### Heredoc / Docker stdin rule

When a diagnostic or migration feeds code through STDIN to a container, use:

`docker exec -i ...`

Without `-i`, a Node/PHP/Python command reading STDIN can exit 0 while never receiving/executing the intended script.

---

## 13A. Historical failed attempts that must not be repeated blindly

### Phone resolver V1

The first WRITE attempt failed safely before lasting change because a local curl verification used the public hostname path incorrectly from the Bitrix host.

Correct production verification pattern used the internal vhost resolution:

`--resolve evrasia.rest:443:192.168.103.141`

The first attempt rolled back successfully.

Backup:

`/home/site_evrasia/backups/anti-fraud-phone-resolver-20260919-144021`

### Phone resolver V2

The endpoint behavior itself was correct, but the deployment script incorrectly assumed a chosen synthetic phone would be `not_found`.

That phone actually resolved to **two real profiles**, so the endpoint correctly returned HTTP 409 ambiguous.

The script treated that expected-safe ambiguity as a failed test and rolled back.

Backup:

`/home/site_evrasia/backups/anti-fraud-phone-resolver-20260919-144452`

Lesson:

- never assume a synthetic-looking phone is absent from a production customer DB;
- ambiguous is a valid safety outcome;
- do not choose a USER_ID when multiple exact normalized profile matches exist.

### Scout deployment first continuation attempt

An early deployment wrapper failed before mutation because of a Python syntax error in Compose generation.

Observed:

- deployment not started;
- production unchanged;
- backup already existed and remained valid.

Relevant retained backup:

`/opt/evrasia-ai-bot/backups/checkin-scout-deploy-20260919-120910`

DB dump in that backup was verified before the successful continuation.

### PR #58 deployment attempts

Two attempts stopped before mutation because staged Compose validation ran against a temporary file outside the production Compose directory.

This was a tooling/staging-path problem, not an application/config defect.

The successful continuation fixed the staging location and did not repeat already-proven image-pull facts unnecessarily.

## 14. Protected files / safety invariants

Never delete, move or modify:

`/home/site_evrasia/web/3d-tour.evrasia.rest/public_html/tours.zip`

Production is critical.

Before a mutation always verify:

- hostname;
- user/root requirement;
- exact service/container;
- exact current image/revision;
- DB/migration baseline if relevant;
- backup and rollback;
- no secrets printed.

Bitrix/PHP can produce an error page while a shell/PHP command exits 0. Acceptance must verify factual result/content, not RC alone.

---

## 15. TOTP 2FA workstream remains paused

Do not resume TOTP work unless the operator explicitly writes:

**ПАНДА ДВА**

Authoritative TOTP checkpoint:

`docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`

Do not mix TOTP work into the current Anti-Fraud Step 2 task.

---

## 16. What a new chat should do immediately

When continuing this work in a new chat:

1. read `docs/PROJECT_CHECKPOINT.md`;
2. read this file;
3. read `docs/AI_PROJECT_CONTEXT.md`;
4. inspect actual GitHub `main`;
5. inspect actual production runtime before any write;
6. do not redo PR #56/#57/#58, Scout deployment, Bitrix phone resolver research or resolver deployment;
7. continue **Step 2 bot side** from the current production/code state.

The likely first implementation task is:

**inspect current main for the cleanest minimal bot gateway + persistent operator-investigation schema before creating migration 0024.**

Do not make the generic operator watchlist the final Step 2 persistence layer.

---

## 17. Documentation discipline requested by the operator

After every material change to architecture, production, DB, protected API, Anti-Fraud business logic, deployment baseline or continuation point:

- update the central checkpoint;
- preserve exact state transitions: planned → implemented → tested → merged → deployed → accepted;
- record immutable production revision/image where applicable;
- record backup/rollback facts;
- record known failed attempts when they teach a safety/architecture lesson;
- distinguish actual production from GitHub-only changes;
- keep the immediate next step explicit enough that a new chat can continue without asking the operator to reconstruct context.

Do not leave the newest factual state only in chat history.
