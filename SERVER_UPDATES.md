# Server updates

> Current production operations note for Evrasia AI Bot.
>
> Last updated: **2026-09-23** after PR #67 UI acceptance and legacy physical-snapshot backfill.

## Current production host

- host: `eur-bot-01`
- IP: `192.168.103.200`
- OS: Debian 13 (trixie)
- Docker Engine: 26.1.5
- Docker Compose: 2.26.1-4
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`

The old Debian 9 / `/home/tech/samzaberu-bot` deployment is not the active production topology.

## Current accepted deployed application

Application:

- service/container: `evrasia-ai-bot-app`
- accepted deployed revision: `b0d12a112577de2a35e0a49e55367e3bc459bc07`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:a9545807cf8b09c0a159e6d7bf8b3a1850ee5a7356966826c4d10a25bbf98767`
- image ID: `sha256:44916797485a87a94ead3e4cfc8445727b0a1752c08d9fa81123dd5172ae34a1`
- canonical Compose SHA256 at acceptance: `8f9246704bf8cc75b2b9c2b6b849953766668e2af27790f4b05ea83a082d2d1c`
- app port: `127.0.0.1:18080 -> 8080`
- production migrations: **26**
- status: running healthy / PR #67 production and browser-visible UI accepted; restart count 0.

PR #62 acceptance facts:

- PR #62 makes the real result of manual operator investigation visible in the Anti-Fraud case card;
- exact latest manual-investigation window is shown rather than accumulated historical coverage;
- UI shows physical visit count, visit-day count, restaurant count, first/last event and an expandable day summary;
- Trusted Device is shown as the existing safe 16-character prefix plus `…`;
- linked accounts are shown as Bitrix USER_ID values when such links actually exist;
- the five existing numeric categories are explicitly labeled `Risk по категориям`;
- no DB migration, scoring rule, grouping rule or blocking behavior changed.

Production read-only acceptance on USER_ID `1969724`:

- operator status: `ready`;
- 60-day manual window present;
- physical visits: **10**;
- visit days: **9**;
- restaurants: **8**;
- day rows: **9**;
- Trusted Device count: **1**;
- displayed Device ID prefix: `3578df691292f7bc…`;
- linked-account count: **0** at acceptance, which is valid factual state;
- deployed frontend bundle contains all PR #62 operator labels;
- acceptance result: **15 PASS / 0 FAIL / 1 WARN**; the WARN was only that this account currently has no linked accounts.

The production runtime was already on the PR #62 target when the guarded deployment script was attempted. The exact guard stopped before mutation (`CUTOVER_STARTED=NO`), so that script did not perform a second cutover and no rollback was required. The prior cutover actor/mechanism was not established by that diagnostic and must not be guessed.

A later docs-only GitHub commit may advance `main`; it does not by itself change the deployed application identity above. Before any future mutation, re-read factual runtime revision/image from production.

## 2026-09-23 — PR #65 operator physical-history snapshot

PR #65 **Anti-Fraud: separate operator physical history from risk telemetry** is **MERGED / DEPLOYED / PRODUCTION / VERIFIED / ACCEPTED**.

Architecture:

- manual 60-day physical history comes from the targeted protected Check-in endpoint;
- snapshot table: `anti_fraud_operator_investigation_visits`;
- snapshot is scoped by `investigation_id`;
- `anti_fraud_operator_investigations` stores `physical_history_from` / `physical_history_until`;
- UI visit/day/restaurant metrics read the dedicated snapshot;
- operator physical snapshot events do not enter `anti_fraud_visits`;
- unresolved targeted card mappings fail closed.

Website targeted Check-in dedup:

- old service SHA: `fd497e84b1ddb3afc16e497395215576dd38feb9d5e73132b4f9278b48f16e9b`;
- accepted service SHA: `5f65703d91ee31a9d829cd64cefd011309c8a6c44d3fa96d2d8c77a1f81541e9`;
- backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/anti-fraud-targeted-dedup-20260923-095449`;
- USER_ID `6645`: 17 raw rows → 13 old Scout-style events → 10 targeted physical events;
- external endpoint/gateway verification: 10 records, 10 unique source IDs, unresolved 0.

Bot deployment:

- revision: `d1746ceabb513727baad729adbd3333328fc2dab`;
- immutable digest: `sha256:ca788e0dcc62fbcc4a810c79866a684f2160d062c2486e4c7577c520374c72b8`;
- image ID: `sha256:34e3c50395b0a34a3b8efe044fc1ad6e7b90771824449a38354babaefdea451c`;
- migrations: **26**;
- deployment result: **61 PASS / 0 FAIL / 0 WARN**;
- rollback: not required;
- backup: `/opt/evrasia-ai-bot/backups/pr65-operator-physical-history-20260923-102119`;
- DB backup SHA256: `22643ef67f2d016886aa87c53c18cfcdc3c113ee502b2d76272255086747403f`.

Production acceptance:

- USER_ID `6645`: status ready; source 10 = snapshot 10; UI 10 visits / 9 days / 4 restaurants; snapshot-to-`anti_fraud_visits` intersection 0;
- USER_ID `408974`: status ready; source 8 = snapshot 8; UI 8 visits / 7 days / 7 restaurants; snapshot-to-`anti_fraud_visits` intersection 0;
- combined result: **31 PASS / 0 FAIL / 0 WARN**.

Authoritative acceptance record:

`docs/ANTI_FRAUD_PR65_PRODUCTION_ACCEPTANCE_2026-09-23.md`

## 2026-09-23 — PR #67 visible operator history + legacy backfill

PR #67 fixed the final UI-only blocker after PR #65: the React history panel incorrectly required legacy `loyaltyHistoryLoadedAt`, so valid PR #65 physical snapshots could be hidden for multi-card accounts.

Accepted production change:

- physical-history UI gate uses the PR #65 snapshot completion fields only;
- no migration;
- no scoring/grouping/blocking change;
- no Anti-Fraud refresh;
- new frontend asset: `/assets/antifraud-2JsE0ngX.js`;
- deployment: **41 PASS / 0 FAIL / 0 WARN**;
- backup: `/opt/evrasia-ai-bot/backups/pr67-operator-history-ui-20260923-105939`;
- rollback not required.

Legacy investigation audit then found 5 latest-ready investigations created before physical-snapshot persistence. A one-time backfill populated only operator snapshot/window state, without scoring writes or `anti_fraud_visits` writes.

Backfill backup:

`/opt/evrasia-ai-bot/backups/pr65-legacy-physical-backfill-20260923-112902/operator-history.before-backfill.dump`

SHA256:

`634ebbc3954153b2644482462c4b0becd28beaeabfee4d0a5aee257d4157a84f`

Result:

- 5 backfilled;
- 0 latest-ready investigations still missing physical snapshot;
- 7 latest-ready investigations have physical coverage;
- backfill: **55 PASS / 0 FAIL / 0 WARN**;
- snapshot-to-`anti_fraud_visits` intersection remained 0 for every account.

Final browser validation was performed by the operator and confirmed correct visible output. This closes the operator physical-history workstream.

## PostgreSQL

- service/container: `evrasia-ai-bot-db`
- image: `postgres:16-bookworm`
- role/user: `evrasia_ai_bot`
- production DB: `evrasia_ai_bot`
- retained test DB: `evrasia_ai_bot_antifraud_test`
- volume: `evrasia-postgres-prod-data`
- network: `evrasia-prod-internal`
- production migrations: **26**
- latest relevant Anti-Fraud migration tag: `0025_anti_fraud_operator_physical_history`

PR #65 added the dedicated operator physical-history snapshot table and physical-history window columns. PR #56 Scout state remains separate.

## 2026-09-19 → 2026-09-20 — Check-in Scout, phone resolver, Trusted Device display

### Check-in Scout corrected rollout

PR #56 is the accepted Scout implementation.

Key facts:

- physical source is the protected Bitrix check-in endpoint backed by the offline-order/VIP_TODAY path;
- 1 check-in/day is normal and does not persist as Scout state;
- WATCH/deep/confirmed state is stored separately;
- Scout physical snapshot does not write into `anti_fraud_visits`;
- confirmation can independently produce Risk 100 / Critical;
- no auto-block.

Initial corrected production cycle:

- fetched: 5758;
- persisted Scout candidates: 332;
- confirmed: 2;
- deep_check: 31;
- watching: 299;
- `SCOUT_ROWS_IN_ANTI_FRAUD_VISITS=0`.

PR #55's first Scout image is unsafe/superseded and must never be deployed.

### Bitrix protected phone resolver

Production route:

`/api/internal/anti-fraud/phone-resolve`

Actual routes file:

`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/routes/api.php`

Current route SHA:

`39abfc79b1cb4291688f48c1cb47ce53f844fb627138267ee3aaf6b3947f792e`

Resolver service SHA:

`2a9ed0b8b8e87d7965d9e9f1ff474121e4605d0fa4c399dbdcee6f30bc5c8d8e`

Accepted response contract:

- 401 unauthorized;
- 200 unique;
- 400 invalid;
- 404 not found;
- 409 ambiguous, no USER_ID selection;
- 503 resolver/candidate-limit error.

Successful resolver backup:

`/home/site_evrasia/backups/anti-fraud-phone-resolver-20260919-195427`

No Bitrix account write, DB write or block was performed by the resolver deployment.

### PR #57 / PR #58 Trusted Device case display

PR #57 exposed all case Trusted Device hashes as display context while retaining shared devices separately as grouping evidence.

PR #58 corrected the operator-facing labels:

- one linked USER_ID → `Устройство`;
- multiple USER_ID → `Общее устройство`.

Both are the same hash type.

PR #58 also changed the first Scout display phrase from `2+` to `3 чекинами` per operator request. This was display-only; the backend `days_2plus_7d >= 2` calculation did not change.

### PR #58 Compose deployment lesson

Two attempts stopped safely before mutation because a staged Compose file was outside the production Compose directory.

Production Compose uses relative resources. The accepted pattern is:

- stage temporary Compose in `/opt/evrasia-ai-bot/prod`;
- validate there;
- only then replace canonical Compose;
- verify immutable image/revision/digest and migration count.

Full current continuation:

`docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md`

## Current Anti-Fraud operator setting

Production-confirmed setting baseline:

- UI button: `Настройка`, immediately left of `Обновить сейчас`
- setting: `Порог бонусного баланса`
- persisted key: `anti_fraud_bonus_balance_threshold`
- storage: existing `bot_settings`
- current/default value: `40000`
- strict rule: `bonus_balance > threshold` adds +50 and opens targeted 60-day history gate
- equality does not trigger
- save does not itself start refresh
- next scheduled/manual scoring cycle reads the value
- no auto-block and no grouping-rule change

`Новый` badge uses case-dynamics `addedAccountIds` and only marks an account newly entering an already observed case. First observation of a whole case does not badge all members.

## PR #43 — configurable threshold and `Новый` badge

Merge / production revision:

`3ce9f8c1904351d77696e70314bba3c60afeffa5`

Title: `Anti-Fraud: configurable bonus threshold and new-account badge`.

Production deployment result previously recorded:

- app-only recreation
- DB container unchanged
- migrations remained 20
- threshold API returned/preserved `40000`
- no setting write by deployment script
- no refresh triggered by deployment
- no Bitrix user-state write
- scheduler enabled/idle/15m/no error
- production identity matched exact CI artifact
- deployment `PASS_COUNT=50`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`

This became the first production baseline with the operator setting and `Новый` marker.

## PR #44 — intermediate modal viewport fix

Merge / deployed revision:

`a45554b25a820615c95b3a3148a38640a4a27507`

Title: `Fix Anti-Fraud settings modal viewport overflow`.

Exact deployed image at that stage:

- digest: `sha256:e8ca3c26a053f4f1943ace9a1df1c498ad0544bdc47f3a80fc0c0b447afb52fc`
- config: `sha256:32b8d6255ea828fd06cbce73f4fc2410b5ffb8a02f3f56712fbd37eba064f27e`

Recorded deployment result:

- `PASS_COUNT=28`
- `FAIL_COUNT=0`
- app-only recreation
- DB container unchanged
- migrations remained 20
- environment/mounts/ports preserved
- settings API healthy
- scheduler enabled/idle/clean
- no refresh, setting write or Bitrix write by deployment
- fresh backup: `/opt/evrasia-ai-bot/backups/pr44-modal-fix-20260909-111032`
- `FINAL_STATUS=PASS`

However, infrastructure/application health did **not** mean visual acceptance. The operator found the modal layout worse/unsatisfactory. PR #44 is therefore a superseded intermediate UI state, not the final accepted UI.

## PR #45 — final modal viewport regression fix

Merge / accepted deployed revision:

`b7402cbe19b14f4d84c77870c8be876fe6f7bf42`

Title: `Fix Anti-Fraud settings modal viewport regression`.

Exact CI artifact selected for production:

- immutable digest: `sha256:11b7adfe1fc4c488a85a87c9417afc562707cdc1b034cda7577483a61609aefe`
- config ID: `sha256:2febff91d52d3ce0481ddaa96dcbee7b3513a8a4d45417e57c20e71204aa479e`
- platform: `linux/amd64`

Root-cause fix:

- settings overlay rendered via React portal into `document.body`
- fixed positioning becomes viewport-relative instead of being constrained by sticky-header/backdrop-filter containing block
- one internal vertical scroll container retained
- existing desktop/mobile design preserved
- no backend, DB, scheduler, threshold/risk semantics or Bitrix changes

Operator final production visual acceptance on 2026-09-09:

**«все супер, отображение как надо»**.

The exact final PR #45 deployment transcript and generated backup directory were not pasted back into chat. Do not invent that path. Before future rollback/cleanup, inspect current server state and `/opt/evrasia-ai-bot/backups` directly.

## Deployment-guard incident and lesson from PR #45

An initial PR #45 deployment attempt correctly stopped before cutover because the script expected stale production baseline `3ce9f8c...`, while production had already advanced to PR #44 revision `a45554b...`.

Observed safe result:

- `CUTOVER_STARTED=NO`
- no rollback attempted
- production remained healthy on PR #44
- failure was `unexpected current production revision`

This is now a permanent operational lesson:

- deployment guards must use the factual current production baseline at the time the script is generated/run;
- never assume the previous known revision is still deployed after an intervening cutover;
- if exact-revision/image guard finds a different healthy baseline before cutover, stop without rollback;
- confirm why production advanced and regenerate/resume using the new verified baseline;
- never weaken or bypass the exact-baseline guard merely to continue deployment.

The guard behavior was correct; the stale expected values in the generated script were the mistake.

## Current Anti-Fraud account-state behavior

Bitrix remains source of truth:

- `ACTIVE=Y`, `BLOCKED=N` → `Активен`
- `ACTIVE=N`, `BLOCKED=N` → `Неактивен`
- any `BLOCKED=Y` → `Заблокирован`

Only `BLOCKED=Y` is a true Bitrix block.

Operationally:

- blocked and inactive hidden from ordinary Anti-Fraud lists by default
- toggle: `Показать заблокированных и неактивных`
- KPI/shared-device/duplicate-contact summaries exclude both
- group bulk block targets active unblocked accounts only
- group risk/bonus evidence still includes all case accounts
- inactive remains `Неактивен` when shown

Localization remains accepted, including Russian rendering of `max_devices_for_same_pair=...` and related generated reason keys.

## Manual block/unblock acceptance

Safe USER_ID `880339` completed a controlled production backend round-trip:

- initial `ACTIVE=Y`, `BLOCKED=N`
- block `ACTIVE=N`, `BLOCKED=Y`
- unblock restored `ACTIVE=Y`, `BLOCKED=N`
- block reason preserved
- exactly two audit transitions
- risk/history/bonus unchanged
- 27 PASS / 0 FAIL / 0 WARN
- no real customer mutation

Do not repeat merely for reassurance. The safe account is risk-0 and has no current case; never mutate a real customer or fabricate risk data to create a visual fixture.

## Similarity performance acceptance

PR #38 performance acceptance remains valid:

- old protected cycle: 206 s
- optimized cycle: 53 s
- health probes: 24/24 HTTP 200, max 3 ms
- scheduler probes: 24/24 HTTP 200, max 6 ms
- app restart count 0

`anti_fraud_risk_scoring` ~3.1 s does not include the subsequent similarity overlay and is not a valid performance gate for that optimization.

Retained similarity backup:

`/opt/evrasia-ai-bot/backups/anti-fraud-similarity-hotfix-20260908-090617`

## Canonical routes / architecture continuity

One production app contains:

1. Phonebook (`/phonebook`)
2. Anti-Fraud (`/antifraud` + scheduler)
3. SamZaberu Telegram scenario
4. Corporate communications / MegaFon Telegram scenario

Canonical routes:

- `/phonebook` = current Phonebook UI
- `/antifraud` = current Anti-Fraud UI
- `/directory` = expected 404
- `/api/directory/...` = expected 404

Legacy `evrasia-ai-bot-v17-test` remains exited/archival.

## Backups to retain

Do not clean without explicit operator approval.

Known retained backups include:

- `/opt/evrasia-ai-bot/backups/pr65-operator-physical-history-20260923-102119`
- `/opt/evrasia-ai-bot/backups/pr44-modal-fix-20260909-111032`
- `/opt/evrasia-ai-bot/backups/pr41-inactive-ui-20260908-110846`
- `/opt/evrasia-ai-bot/backups/anti-fraud-similarity-hotfix-20260908-090617`
- `/opt/evrasia-ai-bot/backups/production-v17-phase1-20260907-053318`
- `/opt/evrasia-ai-bot/backups/production-v17-phase2-dbrename-20260907-054218`
- `/opt/evrasia-ai-bot/backups/app-only-remove-directory-20260907-090654`

Exact PR #45 backup path is intentionally not guessed because its final deployment transcript was not pasted.

## Deployment policy / operational lessons

Use immutable image digest for production cutover.

For server scripts follow `docs/SERVER_SCRIPT_RULES.md`.

Mandatory lessons include:

- one complete copy/paste block
- long script: quoted heredoc wrapper → `bash -n` → execute when valid → remove temp file
- current production/app/code is source of truth before writing guards
- exact current production baseline must be checked immediately before cutover
- no unrelated Telegram/RestIS probes as Anti-Fraud deployment blockers
- bot container intentionally has no RestIS credentials
- GHCR auth is owned by user `tech`; do not invent new root credentials first
- stage Compose in `/opt/evrasia-ai-bot/prod` when relative paths exist
- scheduler race → bounded wait, then revalidate
- HTTP 202 plus later timeout is not proof of background-job failure
- persisted Anti-Fraud run state is authoritative for async work
- validate performance metric boundaries before using them as gates
- validate fixture eligibility before production mutation
- never mutate a real customer just to make a visual fixture convenient
- create/verify backup before production mutation
- preserve env/mounts/ports/secrets during app-only recreation
- never print secret values
- keep terminal open after operator scripts

## Current release status

Current accepted production application is PR #65:

- revision: `d1746ceabb513727baad729adbd3333328fc2dab`;
- immutable digest: `sha256:ca788e0dcc62fbcc4a810c79866a684f2160d062c2486e4c7577c520374c72b8`;
- image ID: `sha256:34e3c50395b0a34a3b8efe044fc1ad6e7b90771824449a38354babaefdea451c`;
- canonical Compose SHA256: `bdcba0082691165ce17c6eca05a28f8bdab42b86ef3edbc9a2fbb5181d0ce097`;
- migrations: **26**;
- runtime: healthy at acceptance, restart count 0;
- migration `0025_anti_fraud_operator_physical_history`: applied and schema verified;
- deployment backup: `/opt/evrasia-ai-bot/backups/pr65-operator-physical-history-20260923-102119`;
- backup DB SHA256: `22643ef67f2d016886aa87c53c18cfcdc3c113ee502b2d76272255086747403f`;
- deployment result: **61 PASS / 0 FAIL / 0 WARN**;
- end-to-end operator-history acceptance: **31 PASS / 0 FAIL / 0 WARN**;
- rollback: not required.

Manual investigation by phone, operator-visible evidence, dedicated physical-history snapshots and targeted multi-card dedup are now production-verified.

Do not repeat PR #65 deployment, USER_ID 6645 / 408974 acceptance, site-side dedup investigation, modal fixes, block/unblock acceptance, Check-in Scout deployment, PR #57/#58 UI deployment or phone-resolver deployment merely for reassurance.

Current continuation record: `docs/ANTI_FRAUD_PR65_PRODUCTION_ACCEPTANCE_2026-09-23.md`.

