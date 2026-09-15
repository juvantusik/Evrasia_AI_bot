# Evrasia AI Bot — AI Project Context

> Operational source of truth for continuing Evrasia AI Bot work across chats.
>
> **Last updated:** 2026-09-15
> **Repository:** `juvantusik/Evrasia_AI_bot`
> **Current deployed app revision:** `1ed726927c5064198d769bb998597889c6ad07d1`
> **Current production milestone:** `/phonebook` v1.8 legal-entity master is production; PR #50 delete UX is deployed; stale legacy phone cleanup is partially completed; next open item is restaurant №28 `Большевиков 18`.

---

## 1. Continuation rule

In a new chat, read in this order:

1. `docs/PROJECT_CHECKPOINT.md`
2. `docs/AI_PROJECT_CONTEXT.md`
3. `docs/PHONEBOOK_PRODUCTION_FOLLOWUP_2026-09-15.md`
4. `docs/PHONEBOOK_LEGAL_ENTITY_MASTER.md`
5. `docs/CURRENT_ARCHITECTURE.md`
6. `docs/SERVER_SCRIPT_RULES.md`
7. `SERVER_UPDATES.md`
8. `docs/NEW_CHAT_HANDOFF.md`

Source priority:

**production actual state → current GitHub → staging/test → current docs → older discussion**.

Do not replay completed deployment, migration, stale-cleanup, block/unblock, performance or visual-acceptance steps unless a new change makes them relevant.

A documentation-only commit may advance GitHub `main` without changing production. Keep GitHub head and deployed application revision conceptually separate and verify factual runtime before every production mutation.

---

## 2. Current production baseline

Host/runtime:

- hostname: `eur-bot-01`
- IP: `192.168.103.200`
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- app service/container: `evrasia-ai-bot-app`
- DB service/container: `evrasia-ai-bot-db`
- production DB / role: `evrasia_ai_bot`
- network: `evrasia-prod-internal`
- volume: `evrasia-postgres-prod-data`

Current application after PR #50:

- revision: `1ed726927c5064198d769bb998597889c6ad07d1`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:f86c59983ef1857cf26b9763cd019d809ff821a8e2b01904c2b3b408f8f0eb5c`
- app status: `running`
- app health: `healthy`
- restart count: `0`
- DB health: `healthy`
- migrations: **23**

PR #50 was deployed without rollback.

---

## 3. Product architecture

Evrasia AI Bot remains **one production application** with four directions:

1. **Phonebook** — `/phonebook`
2. **Anti-Fraud** — `/antifraud` + protected scheduler/data pipeline
3. **SamZaberu** — Telegram scenario inside `EvrasiaTelegramBotV2`
4. **Corporate communications / MegaFon** — Telegram scenario/workflow inside the same application

Canonical route contract:

- `/phonebook` = current Phonebook UI
- `/antifraud` = current Anti-Fraud UI
- `/directory` = removed / expected HTTP 404
- `/api/directory/...` = removed / expected HTTP 404

Legacy TEST `evrasia-ai-bot-v17-test` is exited/archival and must not be restarted blindly.

No topology change occurred in the 15.09.2026 Phonebook follow-up; only application code/data state changed.

---

## 4. `/phonebook` v1.8 legal-entity master — current

Migration `0022_phonebook_legal_entity_master.sql` is production-applied.

Accepted rollout result:

- `corporate_legal_entities`: `91 -> 68` after safe dedupe;
- duplicate INN groups: `23 -> 0`;
- v1.8 master columns present;
- all matchable phone rows linked at rollout;
- all 62 restaurants linked at rollout;
- canonical core import completed under guarded transaction;
- subsequent confirmed requisites/director/address backfills were applied in separate guarded steps.

Master-data rule:

- if `legal_entity_id` exists, name/INN/general director come from `corporate_legal_entities`;
- legacy copies remain fallback/backward compatibility;
- legal and actual addresses are separate fields and may differ completely;
- restaurant short/display address remains independent;
- selecting ЮЛ in restaurant form must populate general director from master.

Two distinct `Евразия-Триумф` entities must never be merged by display name.

`Евразия-Премиум` kept its existing master row and adopted canonical INN/requisites safely.

Canonical docs:

- `docs/PHONEBOOK_LEGAL_ENTITY_MASTER.md`
- `docs/PHONEBOOK_LEGAL_ENTITY_PRODUCTION_ACCEPTANCE_2026-09-13.md`
- `docs/PHONEBOOK_PRODUCTION_FOLLOWUP_2026-09-15.md`

---

## 5. Legal-entity delete/archive behavior

### Было

DELETE/archive from `Реквизиты` requested `PHONEBOOK_WEB_WRITE_TOKEN` via editor prompt and backend `requireEditor()`.

### Стало

PR #50 production behavior:

- user gets ordinary confirmation `Уверены, что хотите удалить?`;
- legal-entity DELETE no longer requires `PHONEBOOK_WEB_WRITE_TOKEN`;
- create/edit remain protected as before;
- archive guard remains mandatory.

### Причина

A separate delete key was not a requested product requirement. Integrity is preserved by active-link guard rather than repeated secret entry.

---

## 6. Archive guard and stale phone rule

Archive is blocked while active phone or restaurant rows reference `legal_entity_id`.

Do not bypass this guard simply because the UI does not visibly show the phone row.

Accepted stale cleanup rule:

A phone row may be retired with `active=false` when all of the following are confirmed:

1. row is in `corporate_phone_directory`;
2. number is not found in accessible phone/source-like tables outside the aggregate;
3. number is not referenced by restaurant personal-phone fields;
4. same number has no other active/current owner row;
5. exact target set is guarded before write;
6. DB backup is created and verified;
7. update occurs transactionally with post-check.

Default action is **deactivate, not physical DELETE**.

Production schema discovery on 15.09.2026 found phone-like tables:

- `public.corporate_directory_restaurants`
- `public.corporate_phone_audit`
- `public.corporate_phone_directory`

No separate MegaFon/T2 source tables were discovered in this PostgreSQL DB. Therefore use the precise statement `NOT_FOUND_OUTSIDE_AGGREGATE`; do not claim external operator systems were independently queried unless they actually were.

---

## 7. Completed stale cleanup — 15.09.2026

### Four old ЮЛ

18 active stale rows were retired:

- `ООО "А-15 Новое Колпино` — 5
- `ООО "Век"` — 5
- `ООО "Евразия2008"` — 4
- `ООО "Кайхон"` — 4

Verified before write:

- no other active owner row for any number;
- all 18 `NOT_FOUND_OUTSIDE_AGGREGATE`;
- restaurant direct phone matches = `0`;
- active restaurant links for all four = `0`.

Write result:

- `UPDATED_ROWS=18`;
- `active=false` only;
- no physical DELETE;
- backup: `/opt/evrasia-ai-bot/backups/stale-phone-retire/20260915-081631/evrasia_ai_bot.pre-stale-phone-retire.dump`;
- all four ended with `active_phone_links=0`, `active_restaurant_links=0`;
- app remained healthy.

### `ООО "Евразия-Большевиков"`

Old master:

- ID `le-e6748431e3df3ddd16c8a04c`
- INN `7811360046`

Seven active legacy T2 rows were checked separately. All seven were `NOT_FOUND_OUTSIDE_AGGREGATE`, had no restaurant refs and no duplicate/current owner rows. User confirmed Гречко О.П. had long ago moved to `Евразия Южная` and the numbers no longer belonged to Большевиков.

User confirmed the production cleanup script succeeded: **«все ок, получилось»**. Treat those seven rows as successfully retired via `active=false`; history preserved.

---

## 8. Immediate Phonebook continuation point

Restaurant №28:

- address: `Большевиков 18`
- active: `true` at last inspection
- linked ЮЛ: `ООО "Евразия-Манхеттен"`
- legal_entity_id: `le-b534bcce3b02ace0d23bfac2`
- INN: `7805576103`
- user reports restaurant closed in **May 2026**

At last diagnostic point `ООО "Евразия-Манхеттен"` had 8 active phone rows.

These 8 rows were **not** touched by the 7-row cleanup of legacy `ООО "Евразия-Большевиков"`.

Next step:

1. determine which of the 8 rows belong specifically to restaurant №28;
2. determine whether any remain valid for another `Евразия-Манхеттен` use;
3. retire only obsolete rows with exact guards and backup;
4. then close/deactivate the restaurant row if appropriate.

Do not bulk-disable all 8 blindly.

---

## 9. Anti-Fraud contract — still current

Anti-Fraud remains advisory/investigative. Risk never auto-blocks an account.

Bitrix account state:

- `ACTIVE=Y`, `BLOCKED=N` → **Активен**
- `ACTIVE=N`, `BLOCKED=N` → **Неактивен**
- any `BLOCKED=Y` → **Заблокирован**

Operational rules remain:

- inactive and blocked hidden by default;
- toggle: `Показать заблокированных и неактивных`;
- KPI/shared-device/duplicate-contact summaries exclude both;
- group bulk block targets active unblocked accounts only;
- risk/group evidence can still include excluded case members.

Current threshold contract remains persisted under `anti_fraud_bonus_balance_threshold`; last confirmed value `40000`; strict `bonus_balance > threshold` rule; save does not itself run refresh.

Operator-facing `Новый` uses the later PR #47 24h first-seen web semantics. `anti_fraud_web_account_state` is the source for web-visible first-seen continuity; do not fall back to old PR #43 case-delta meaning for current UI semantics.

---

## 10. Credentials / integration boundaries

- production mutation on `eur-bot-01` is performed as root;
- GHCR auth belongs to user `tech` under `/home/tech/.docker/config.json`;
- do not copy/print token values;
- bot container must not receive RestIS credentials;
- raw loyalty card numbers must not appear in bot UI/API/logs;
- Trusted Device is app installation/trust identity, not IP/hardware identity;
- logout must not manufacture a new identity.

---

## 11. Website legal / consent continuity

Current legal/consent source of truth remains `docs/PROJECT_CHECKPOINT.md` and `docs/WEBSITE_LEGAL_CONSENT_INTEGRATION.md`.

Key accepted state:

- public offer revision 11.09.2026 is production and includes §1.19 `Недобросовестное использование`;
- privacy policy is production;
- signup requires offer + PD, marketing is optional;
- native Bitrix consent subsystem is used;
- existing-user account-gate acceptance is controlled for USER_ID `880339` only unless a newer rollout changes scope.

---

## 12. Trusted Device distinction

For authentication Trusted Device / SMS trust counts, use `docs/TRUSTED_DEVICE_DIAGNOSTICS.md` and Bitrix-side `ev_trusted_devices`. Do not answer that question from Anti-Fraud PostgreSQL device-link data.

---

## 13. Mandatory server-script rules

`docs/SERVER_SCRIPT_RULES.md` is mandatory.

Core rules:

- one complete copy/paste block;
- long script → quoted-heredoc wrapper → `bash -n` → execute if valid → remove temp file;
- exact production baseline read from factual current production;
- no secrets in output;
- backup/rollback/post-check for production mutations;
- terminal stays open.

New PR #50 deployment lesson:

- a staged compose file stored outside `/opt/evrasia-ai-bot/prod` may fail validation because relative env/file paths resolve relative to the staged file location;
- validate with production project-directory semantics;
- do not print full resolved `docker compose config` output because it can expose secret env values.

---

## 14. Do not repeat completed work

Do not automatically repeat:

- v1.8 migration/canonical import;
- PR #50 delete-key UX deployment;
- 18-row stale cleanup;
- 7-row `Евразия-Большевиков` stale cleanup;
- prior Anti-Fraud block/unblock acceptance;
- prior similarity performance acceptance;
- archival TEST work;
- older PR #43/#44/#45 modal iteration.

Continue from restaurant №28 `Большевиков 18` unless user changes priority.
