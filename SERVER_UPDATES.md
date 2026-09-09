# Server updates

> Current production operations note for Evrasia AI Bot.
>
> Last updated: **2026-09-09** after PR #45 production visual acceptance.

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
- accepted deployed revision: `b7402cbe19b14f4d84c77870c8be876fe6f7bf42`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:11b7adfe1fc4c488a85a87c9417afc562707cdc1b034cda7577483a61609aefe`
- image/config ID: `sha256:2febff91d52d3ce0481ddaa96dcbee7b3513a8a4d45417e57c20e71204aa479e`
- platform: `linux/amd64`
- app port: `127.0.0.1:18080 -> 8080`
- status: production UI operator-accepted after PR #45

A later docs-only GitHub commit may advance `main`; it does not by itself change the deployed application identity above. Before any future mutation, re-read factual runtime revision/image from production.

## PostgreSQL

- service/container: `evrasia-ai-bot-db`
- image: `postgres:16-bookworm`
- role/user: `evrasia_ai_bot`
- production DB: `evrasia_ai_bot`
- retained test DB: `evrasia_ai_bot_antifraud_test`
- volume: `evrasia-postgres-prod-data`
- network: `evrasia-prod-internal`
- production migrations: **20**
- latest migration journal timestamp: `1788769200000`

Migrations 0018/0019 provide Anti-Fraud blocked-state fields and internal block audit.

PR #43/#44/#45 introduced no new migration and no schema change.

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

The latest operator-settings/UI milestone is complete and production accepted on PR #45 revision `b7402cbe19b14f4d84c77870c8be876fe6f7bf42` with immutable CI digest `sha256:11b7adfe1fc4c488a85a87c9417afc562707cdc1b034cda7577483a61609aefe`.

PR #44 remains an important historical intermediate deployment but is superseded visually by PR #45.

No repeat modal fix, block/unblock acceptance or similarity refresh is pending.
