# /phonebook — production baseline ЮЛ перед v1.8

Дата: 13.09.2026
Jira: KAN-77
Статус: READ_ONLY FORENSIC / NO PRODUCTION IMPORT

## Production baseline

Host: `eur-bot-01`

- application image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:6230a8a198cefe39a5ef0e3f6934f74660ce7e4629d0486cd46f0859a4cc2830`;
- application: `running / healthy`, restart count `0`;
- PostgreSQL DB: `evrasia_ai_bot`;
- applied migrations: `22`;
- `corporate_legal_entities`: `91` rows;
- current master schema before v1.8: `id, name, inn, active, created_at, updated_at`;
- v1.8 fields `kpp, ogrn, general_director, source, verification_status` are not present yet.

No production write was performed during canonical dry-run or duplicate forensic.

## Canonical dry-run

Canonical source contains 62 legal entities:

- 49 `OK`;
- 13 `NEEDS_REVIEW`.

Dry-run on a temporary clone of production DB with migration `0022` applied only to the clone:

- `MATCH_COUNT=0`;
- `CREATE_COUNT=1`;
- `UPDATE_COUNT=28`;
- `SKIP_COUNT=11`;
- `CONFLICT_COUNT=22`.

The temporary database and temporary files were removed after the run. Production remained at 22 migrations and 91 master rows.

## Duplicate INN forensic

Production currently contains **23 duplicate INN groups**. Every duplicate group consists of 2 active rows created at the same timestamp (`2026-08-27 09:39:34.773834+00`) and names differ only by legacy formatting/spaces/quotes/punctuation.

22 of these groups intersect the canonical dataset and caused `DUPLICATE_EXISTING_INN:2` conflicts in the importer.

The 23rd duplicate INN group is `7811472039` (`ООО "Евразия Смайл"` / `ООО "Евразия-Смайл"`). The archive canonical row for `Евразия-Смайл` currently has no confirmed INN and therefore remains `NEEDS_REVIEW`; the production INN must not be silently promoted to verified canonical data.

### Canonical-conflict duplicate INNs

`4704083603, 4705050738, 7801398121, 7801416500, 7801418988, 7801426763, 7801541212, 7801544855, 7802472191, 7806440176, 7810554352, 7810591971, 7813495602, 7814139815, 7814438727, 7817318001, 7817318019, 7838450770, 7841436920, 7842345658, 7843308352, 7843309290`.

## Legacy references

Before v1.8:

- `corporate_phone_directory` has no `legal_entity_id`; it stores `legal_entity` text and `inn`;
- `corporate_directory_restaurants` has no `legal_entity_id`; it stores `legal_entity` text;
- phone rows for all 22 canonical-conflict INNs are split across both legacy name variants;
- 17 restaurant rows reference one of the duplicate name variants.

Therefore duplicate master rows must not simply be deleted after v1.8 foreign keys are created without first preserving/repointing references.

## Important special case: Евразия-Премиум

Canonical dry-run reported `CREATE` for INN `7804421925`, but production already has an active master row named `ООО "Евразия-Премиум"` with `inn IS NULL`.

This means a future importer must not blindly create a second master row. A same-name active row with missing INN is an unresolved merge candidate and must be surfaced as a conflict/manual-resolution case before write.

## Migration implication

The current `0022_phonebook_legal_entity_master.sql` backfills:

- phone `legal_entity_id` by exact normalized `legal_entity` name + INN;
- restaurant `legal_entity_id` only for unambiguous normalized master names.

Because production contains duplicate master rows by INN and legacy aliases, canonical import cannot safely proceed until duplicate identity handling is rehearsed and validated.

Preferred next step:

1. temporary DB clone only;
2. build alias map from all current duplicate names to one survivor per INN;
3. deduplicate master rows on the clone;
4. backfill phone links by INN to the survivor;
5. backfill restaurant links through captured aliases;
6. apply canonical core fields only after conflicts are zero;
7. verify counts/references and zero orphan links;
8. only then design guarded production migration with backup/rollback.

## Rules learned

- INN is the primary identity key; never merge legal entities by name alone.
- Formatting variants of a name must be treated as aliases, not separate legal entities when INN is identical.
- `NEEDS_REVIEW` canonical values are never auto-written.
- Existing production values may help matching/deduplication but do not automatically become verified canonical legal data.
- Any apparent `CREATE` must also be checked for same-name active rows with missing INN to avoid duplicate master creation.
- Production migration/import remains blocked while conflicts exist.
