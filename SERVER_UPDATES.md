# Server updates

> Current production operations note for Evrasia AI Bot.
>
> Last updated: **2026-09-15** after PR #50 production deployment and `/phonebook` stale-link cleanup.

## Current production host

- host: `eur-bot-01`
- IP: `192.168.103.200`
- OS: Debian 13 (trixie)
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- app container: `evrasia-ai-bot-app`
- DB container: `evrasia-ai-bot-db`
- DB / role: `evrasia_ai_bot`

The old Debian 9 / `/home/tech/samzaberu-bot` deployment is not the active production topology.

## Current accepted deployed application

Application:

- deployed revision: `1ed726927c5064198d769bb998597889c6ad07d1`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:f86c59983ef1857cf26b9763cd019d809ff821a8e2b01904c2b3b408f8f0eb5c`
- app status: `running`
- app health: `healthy`
- restart count: `0`
- DB health: `healthy`
- production migrations: **23**

A later docs-only GitHub commit may advance `main`; it does not by itself change deployed application identity. Before any future mutation, re-read factual runtime revision/image from production.

## PR #50 — `/phonebook` legal-entity delete UX

Merge/deployed revision:

`1ed726927c5064198d769bb998597889c6ad07d1`

Production image:

`ghcr.io/juvantusik/evrasia_ai_bot@sha256:f86c59983ef1857cf26b9763cd019d809ff821a8e2b01904c2b3b408f8f0eb5c`

Behavior change:

- legal-entity delete/archive no longer prompts for `PHONEBOOK_WEB_WRITE_TOKEN`;
- UI uses ordinary confirmation `Уверены, что хотите удалить?`;
- backend DELETE legal-entity no longer uses the editor-token gate;
- create/edit protection remains unchanged;
- archive guard for active phone/restaurant references remains unchanged.

Deployment result:

- exact immutable image pulled using existing `tech` GHCR credentials;
- app-only recreation;
- health became `healthy` after startup;
- restart count `0`;
- DB remained healthy;
- migrations remained `23`;
- rollback not required;
- final `FINAL_STATUS=PASS`.

## PR #50 deployment incident / Compose validation lesson

The first deployment attempt stopped safely before cutover because staged `compose.yml.new` was validated from a backup directory. Relative file/env references were therefore resolved against the wrong directory and `docker compose config` returned nonzero.

The staged compose itself was valid and differed only by intended image replacement.

Correct validation used production project-directory semantics. After that, cutover succeeded.

Permanent lesson:

- when staged Compose is stored outside `/opt/evrasia-ai-bot/prod`, validate using the production project directory so relative paths resolve correctly;
- never print full resolved `docker compose config` output in diagnostics because it may expose secret environment values;
- a pre-cutover guard failure is a safety success; do not bypass it blindly.

## `/phonebook` v1.8 DB baseline

Migration `0022_phonebook_legal_entity_master.sql` is production-applied.

Current structural baseline:

- migrations: `23`;
- master legal entities after rollout dedupe: `68`;
- duplicate INN groups: `0`;
- master v1.8 columns present;
- canonical legal-entity import completed;
- subsequent director/requisites/address backfills executed through separate guarded transactions.

See:

- `docs/PHONEBOOK_LEGAL_ENTITY_PRODUCTION_ACCEPTANCE_2026-09-13.md`
- `docs/PHONEBOOK_PRODUCTION_FOLLOWUP_2026-09-15.md`

## 15.09.2026 — stale phone cleanup for four old ЮЛ

Archive guard exposed 18 active legacy phone rows for:

- `ООО "А-15 Новое Колпино` — 5;
- `ООО "Век"` — 5;
- `ООО "Евразия2008"` — 4;
- `ООО "Кайхон"` — 4.

Read-only checks proved:

- each target number had no other active owner row;
- all 18 were `NOT_FOUND_OUTSIDE_AGGREGATE` among accessible phone/source-like tables;
- direct restaurant phone references = `0`;
- active restaurant links for all four ЮЛ = `0`.

Backup:

`/opt/evrasia-ai-bot/backups/stale-phone-retire/20260915-081631/evrasia_ai_bot.pre-stale-phone-retire.dump`

Write:

- physical DELETE: `NO`;
- action: `active=false`;
- rows updated: `18`;
- transaction committed;
- post-check: all four ЮЛ had zero active phone links and zero active restaurant links;
- app remained healthy.

## 15.09.2026 — `ООО "Евразия-Большевиков"` legacy cleanup

Target legal entity:

- ID `le-e6748431e3df3ddd16c8a04c`;
- INN `7811360046`.

Seven active legacy T2 rows were identified and separately investigated.

Checks:

- exact active target count = `7`;
- all 7 `NOT_FOUND_OUTSIDE_AGGREGATE`;
- restaurant personal-phone references = `0`;
- each target phone had one row / one owner in phone directory;
- no other active owner row existed;
- user confirmed Гречко О.П. had long ago moved to `Евразия Южная` and these numbers no longer belonged to Большевиков.

The user then confirmed the guarded cleanup script succeeded: **«все ок, получилось»**.

Accepted result:

- 7 legacy rows retired with `active=false`;
- physical DELETE not used;
- history retained.

Do not confuse these 7 rows with the separate active restaurant №28 `Большевиков 18` linked to `ООО "Евразия-Манхеттен"`.

## Phone/source discovery boundary

Generic production PostgreSQL schema discovery found phone-like tables:

- `public.corporate_directory_restaurants`;
- `public.corporate_phone_audit`;
- `public.corporate_phone_directory`.

No separate MegaFon/T2 source tables were discovered inside this DB.

Therefore the accepted wording is:

`NOT_FOUND_OUTSIDE_AGGREGATE` in accessible phone/source-like tables.

Do not document these checks as if external operator systems were independently queried unless an actual external query is performed.

## Immediate open production item — restaurant №28

At last inspection:

- restaurant number/id: `28`;
- address: `Большевиков 18`;
- `active=true`;
- linked master: `ООО "Евразия-Манхеттен"`;
- legal_entity_id: `le-b534bcce3b02ace0d23bfac2`;
- master INN: `7805576103`;
- user reports restaurant closed in May 2026.

Eight active phone rows were linked to `ООО "Евразия-Манхеттен"` at that diagnostic point.

These 8 rows were **not** modified by the seven-row cleanup of old `ООО "Евразия-Большевиков"`.

Next production work must first identify which of the 8 belong specifically to the closed restaurant before any deactivation.

## GHCR authentication invariant

Production mutation runs as root, but GHCR auth belongs to user `tech` in `/home/tech/.docker/config.json`.

Reuse the existing `tech` Docker config for pulls. Do not copy secret values to root and do not print auth material.

## Current Anti-Fraud continuity

Anti-Fraud remains advisory/investigative.

- risk never auto-blocks;
- Bitrix remains account-state source of truth;
- blocked/inactive hidden by default;
- current configured bonus threshold remains `40000` unless factual production shows otherwise;
- scheduler remains a protected recurring process inside the same app;
- operator-facing `Новый` uses PR #47 24h first-seen web semantics.

Older PR #43/#44/#45 deployment history remains valid historically but is no longer the current deployed application identity.

## Retained backups

Do not clean without explicit operator approval.

Known retained backups include:

- `/opt/evrasia-ai-bot/backups/stale-phone-retire/20260915-081631/evrasia_ai_bot.pre-stale-phone-retire.dump`
- `/opt/evrasia-ai-bot/backups/v18-canonical-apply/20260913-201035/evrasia_ai_bot.pre-canonical.dump`
- `/opt/evrasia-ai-bot/backups/v18-phonebook-rollout/20260913-195433`
- `/opt/evrasia-ai-bot/backups/pr47-new-24h-20260911-201030`
- `/opt/evrasia-ai-bot/backups/pr44-modal-fix-20260909-111032`
- `/opt/evrasia-ai-bot/backups/pr41-inactive-ui-20260908-110846`
- `/opt/evrasia-ai-bot/backups/anti-fraud-similarity-hotfix-20260908-090617`

The exact backup path of the successful seven-row `Евразия-Большевиков` cleanup was not pasted back into chat. Do not invent it; inspect server backup inventory if needed.

## Deployment policy / operational lessons

Use immutable image digest for production cutover.

For server scripts follow `docs/SERVER_SCRIPT_RULES.md`.

Mandatory lessons:

- one complete copy/paste block;
- long script: quoted heredoc wrapper → `bash -n` → execute if valid → remove temp file;
- current production/app/code is source of truth before writing guards;
- exact current production baseline immediately before cutover;
- existing GHCR auth under `tech`;
- no secrets in output;
- DB writes: exact SELECT/target count first, backup, transaction, post-check;
- stale phone cleanup: `active=false`, not physical DELETE;
- stage/validate Compose with correct project-directory semantics;
- never emit resolved Compose environment values in diagnostic output;
- terminal stays open after scripts.

## Current release status

Current production release is revision `1ed726927c5064198d769bb998597889c6ad07d1` on immutable image `sha256:f86c59983ef1857cf26b9763cd019d809ff821a8e2b01904c2b3b408f8f0eb5c`.

Phonebook v1.8 core is production accepted. PR #50 delete UX is production. Two stale-phone cleanup batches are complete.

Next open Phonebook work is restaurant №28 `Большевиков 18` and its relationship to 8 active `ООО "Евразия-Манхеттен"` phone rows.
