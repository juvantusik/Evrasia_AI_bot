# /phonebook v1.8 — production acceptance ЮЛ

Дата первоначальной acceptance: 13.09.2026
Последний follow-up: 15.09.2026
Jira: KAN-77
Статус: **PRODUCTION / ACCEPTED**

## Production runtime

Host: `eur-bot-01`.

### Initial accepted v1.8 runtime

- application image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:2dff3b661c1d01cf4f6d3d7e4a79fb8416915902e6e1828e3e56da216d41afb2`;
- application status: `running / healthy`;
- restart count: `0`;
- PostgreSQL DB: `evrasia_ai_bot`;
- applied migrations: `23`.

### Current runtime after PR #50 follow-up

- revision: `1ed726927c5064198d769bb998597889c6ad07d1`;
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:f86c59983ef1857cf26b9763cd019d809ff821a8e2b01904c2b3b408f8f0eb5c`;
- application status: `running / healthy`;
- restart count: `0`;
- DB health: `healthy`;
- migrations remain `23`;
- rollback not required.

PR #50 changed delete UX for legal-entity archive: DELETE no longer requests `PHONEBOOK_WEB_WRITE_TOKEN`; UI uses ordinary confirmation only. Create/edit protection remains unchanged.

## Migration 0022 result

Migration `0022_phonebook_legal_entity_master.sql` is applied in production.

Verified result:

- `corporate_legal_entities`: `91 -> 68` rows after safe dedupe;
- duplicate INN groups: `23 -> 0`;
- v1.8 master fields present: `kpp`, `ogrn`, `general_director`, `source`, `verification_status`;
- all matchable corporate phone rows linked to master ЮЛ;
- `PHONE_MATCHABLE_UNLINKED=0`;
- restaurants: `62/62` linked;
- phone orphan links: `0`;
- restaurant orphan links: `0`.

The migration preserved legacy aliases during dedupe and selected one survivor per INN before link backfill.

## Canonical core import

Canonical source: `docs/LEGAL_ENTITY_IMPORT_REVIEW_2026-09-13.md`.

Source set:

- 62 ЮЛ total;
- 49 `OK`;
- 13 `NEEDS_REVIEW`.

Final production dry-run immediately before write:

- `MATCH_COUNT=0`;
- `CREATE_COUNT=0`;
- `UPDATE_COUNT=49`;
- `SKIP_COUNT=13`;
- `CONFLICT_COUNT=0`.

The guarded canonical transaction then completed successfully.

Post-apply idempotence check:

- `MATCH_COUNT=49`;
- `CREATE_COUNT=0`;
- `UPDATE_COUNT=0`;
- `SKIP_COUNT=13`;
- `CONFLICT_COUNT=0`.

Production contained exactly `49` master rows with `verification_status=VERIFIED` at that acceptance point.

The 13 `NEEDS_REVIEW` canonical rows were intentionally not auto-written by the canonical importer. Later confirmed field/address backfills were performed as separate guarded production steps and do not change the original import acceptance record.

## Critical identity checks

### ООО «Евразия-Премиум»

Existing master row was preserved; no second record was created.

Verified production values:

- ID: `le-88c1c23b4c86b72a9010bdba`;
- INN: `7804421925`;
- KPP: `780601001`;
- OGRN: `1097847251743`;
- general director: `Буйлов Роман Игоревич`;
- verification status: `VERIFIED`;
- active: `true`.

This is the accepted resolution of the previous active same-name/null-INN edge case.

### Two «Евразия-Триумф»

Both distinct legal entities remain separate and verified:

1. INN `7810591971`, KPP `781001001`, OGRN `1107847187524`, general director `Комов Сергей Юрьевич`;
2. INN `7817318001`, KPP `780501001`, OGRN `1097847304609`, general director `Мамочкин Дмитрий Николаевич`.

They must never be merged by display name.

## Backup / rollback

Initial rollout backups retained:

- `/opt/evrasia-ai-bot/backups/v18-canonical-apply/20260913-201035/evrasia_ai_bot.pre-canonical.dump`;
- `/opt/evrasia-ai-bot/backups/v18-phonebook-rollout/20260913-195433`.

Later stale-phone cleanup backup:

- `/opt/evrasia-ai-bot/backups/stale-phone-retire/20260915-081631/evrasia_ai_bot.pre-stale-phone-retire.dump`.

Do not remove retained production backups without explicit cleanup approval.

## 15.09.2026 stale-link acceptance

Archive guard correctly prevented archive of four old ЮЛ because active `corporate_phone_directory` rows still referenced them.

Investigated targets:

- `ООО "А-15 Новое Колпино` — 5 rows;
- `ООО "Век"` — 5 rows;
- `ООО "Евразия2008"` — 4 rows;
- `ООО "Кайхон"` — 4 rows.

Total = 18 rows.

Before write:

- no duplicate/current owner rows for the same numbers;
- all 18 returned `NOT_FOUND_OUTSIDE_AGGREGATE` in accessible phone/source-like tables;
- direct restaurant phone references = `0`;
- active restaurant links for those four ЮЛ = `0`.

Production action:

- `UPDATE 18` via `active=false`;
- no physical DELETE;
- transaction committed;
- post-check: all four have `active_phone_links=0`, `active_restaurant_links=0`;
- application remained healthy.

A second stale set for `ООО "Евразия-Большевиков"` was then checked:

- 7 active legacy T2 rows;
- all 7 `NOT_FOUND_OUTSIDE_AGGREGATE`;
- restaurant refs = `0`;
- no duplicate rows/current owners;
- user confirmed Гречко О.П. had long ago moved to `Евразия Южная` and the numbers no longer belonged to Большевиков.

The user confirmed the guarded production cleanup completed successfully: **«все ок, получилось»**. These 7 rows were retired through `active=false`; history was preserved.

## Source-discovery boundary

The production PostgreSQL schema discovery used for stale cleanup found phone-like tables:

- `public.corporate_directory_restaurants`;
- `public.corporate_phone_audit`;
- `public.corporate_phone_directory`.

No separate MegaFon/T2 source tables were found inside this DB. Therefore the verified fact is that target numbers were not found **outside the aggregate among accessible source/phone-like tables**. Do not rewrite this as if external operator systems were independently queried.

## Final acceptance result

Core legal-entity identity/master-data stage remains **PRODUCTION / ACCEPTED**.

Current production runtime after PR #50 is healthy on immutable digest:

`sha256:f86c59983ef1857cf26b9763cd019d809ff821a8e2b01904c2b3b408f8f0eb5c`.

## What remains / immediate continuation

Not completed:

1. structured canonical bank-account dataset and bank-account import;
2. operator/mobile account linkage beyond the currently established phonebook sync model;
3. remaining unresolved review positions not subsequently corrected by guarded backfill;
4. remaining document-generation/statistical fields;
5. document-template generation workflow.

Immediate operational open item:

- restaurant №28 `Большевиков 18` remains `active=true` and is linked to `ООО "Евразия-Манхеттен"` (`legal_entity_id=le-b534bcce3b02ace0d23bfac2`);
- user states this restaurant closed in May 2026;
- 8 active phone rows are linked to `ООО "Евразия-Манхеттен"` at the current diagnostic point;
- those 8 rows were **not** touched by the stale cleanup of 7 legacy rows belonging to `ООО "Евразия-Большевиков"`;
- next work must distinguish which of the 8 belong specifically to the closed restaurant before deactivation.

See `docs/PHONEBOOK_PRODUCTION_FOLLOWUP_2026-09-15.md` for the exact continuation record.
