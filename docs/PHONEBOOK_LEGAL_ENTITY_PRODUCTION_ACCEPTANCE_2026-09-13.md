# /phonebook v1.8 — production acceptance ЮЛ

Дата: 13.09.2026
Jira: KAN-77
Статус: **PRODUCTION / ACCEPTED**

## Production runtime

Host: `eur-bot-01`.

- application image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:2dff3b661c1d01cf4f6d3d7e4a79fb8416915902e6e1828e3e56da216d41afb2`;
- application status: `running / healthy`;
- restart count: `0`;
- PostgreSQL DB: `evrasia_ai_bot`;
- applied migrations: `23`.

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

Production now contains exactly `49` master rows with `verification_status=VERIFIED`.

The 13 `NEEDS_REVIEW` canonical rows were intentionally not auto-written.

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

A post-migration, pre-canonical production DB backup was created and verified before apply:

`/opt/evrasia-ai-bot/backups/v18-canonical-apply/20260913-201035/evrasia_ai_bot.pre-canonical.dump`

Backup structure and SHA256 checksum were verified before the canonical transaction.

Earlier pre-v1.8 rollout backup remains separately retained under:

`/opt/evrasia-ai-bot/backups/v18-phonebook-rollout/20260913-195433`

Do not remove either backup without explicit cleanup approval.

## Final acceptance result

- `APPLY_STARTED=YES`;
- `APPLY_COMMITTED=YES`;
- `CANONICAL_IMPORT_STATUS=PASS`;
- `FINAL_STATUS=PASS`;
- `FINAL_RC=0`;
- app remains `healthy`;
- restart count remains `0`.

Core legal-entity identity/master-data stage of v1.8 is therefore **PRODUCTION / ACCEPTED**.

## What remains

This acceptance covers the master/core legal identity fields only:

- name;
- INN;
- KPP;
- OGRN;
- general director;
- source;
- verification status;
- links from phonebook/restaurants to the master ЮЛ.

Not completed by this step:

1. structured canonical bank-account dataset and bank-account import;
2. operator/mobile account linkage beyond the currently established phonebook sync model;
3. resolution of the 13 `NEEDS_REVIEW` canonical positions;
4. remaining full requisites/address/statistics fields needed for document generation;
5. document-template generation workflow itself.

Next work on `/phonebook` must continue from this production state, not from the old pre-v1.8 baseline.
