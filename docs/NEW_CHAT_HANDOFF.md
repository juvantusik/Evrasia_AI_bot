# Evrasia — New Chat Handoff

> Fast handoff for continuing Evrasia AI Bot in a new ChatGPT chat.
>
> **Updated: 2026-09-15** after `/phonebook` v1.8 production rollout, delete-UX fix and stale phone-link cleanup.

## Ready-to-paste instruction for a new chat

Продолжаем проект Evrasia AI Bot. Не начинай работу заново и не проси меня повторять уже установленный контекст.

Репозиторий: `juvantusik/Evrasia_AI_bot`.

Сначала полностью прочитай:

1. `docs/PROJECT_CHECKPOINT.md` — authoritative checkpoint;
2. `docs/AI_PROJECT_CONTEXT.md` — текущий проектный/технический контекст;
3. `docs/CURRENT_ARCHITECTURE.md` — актуальная архитектура;
4. `docs/PHONEBOOK_LEGAL_ENTITY_MASTER.md` — master-data contract `/phonebook` v1.8;
5. `docs/PHONEBOOK_LEGAL_ENTITY_PRODUCTION_ACCEPTANCE_2026-09-13.md` — initial production acceptance;
6. `docs/PHONEBOOK_PRODUCTION_FOLLOWUP_2026-09-15.md` — текущая точка Phonebook cleanup и открытая задача;
7. `docs/SERVER_SCRIPT_RULES.md` — обязательные правила серверных скриптов;
8. `SERVER_UPDATES.md` — production/server update history;
9. `docs/TRUSTED_DEVICE_DIAGNOSTICS.md` — authoritative Trusted Device counting method;
10. `docs/WEBSITE_LEGAL_CONSENT_INTEGRATION.md` — website/legal/consent perimeter;
11. `docs/NEW_CHAT_HANDOFF.md` — этот handoff.

Приоритет источников: **production actual state → current GitHub → staging/test → current docs → older discussion**. Не повторяй уже завершённые проверки и deployment-шаги.

После docs-only commit GitHub `main` может быть новее deployed application revision. Перед следующей production mutation всегда отдельно проверяй фактический runtime image/revision.

---

## 1. Current production baseline

Host: `eur-bot-01` (`192.168.103.200`).

Current deployed application after PR #50:

- application revision: `1ed726927c5064198d769bb998597889c6ad07d1`;
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:f86c59983ef1857cf26b9763cd019d809ff821a8e2b01904c2b3b408f8f0eb5c`;
- app: `evrasia-ai-bot-app`;
- DB: `evrasia-ai-bot-db`;
- DB name/role: `evrasia_ai_bot`;
- Compose project: `evrasia-prod`;
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`;
- app status: `running`;
- app health: `healthy`;
- app restart count: `0`;
- DB health: `healthy`;
- migrations: **23**;
- current confirmed Anti-Fraud bonus threshold remains **40000** unless factual production says otherwise.

PR #50 changed only the legal-entity delete/archive UX in `/phonebook`: ordinary confirmation instead of editor-key prompt for DELETE. Create/edit protection remains unchanged.

---

## 2. `/phonebook` v1.8 — PRODUCTION / ACCEPTED

Migration `0022_phonebook_legal_entity_master.sql` is production-applied.

Accepted core state:

- `corporate_legal_entities`: `91 -> 68` after safe dedupe;
- duplicate INN groups: `23 -> 0`;
- v1.8 columns present;
- all matchable phone links and all 62 restaurants were linked during rollout;
- canonical legal-entity import was applied under guarded transaction;
- subsequent requisites/director/address backfills were performed in controlled production steps;
- two same-name `Евразия-Триумф` legal entities remain distinct by INN;
- `Евразия-Премиум` preserved one existing master row and safely adopted canonical INN/requisites.

Important master rule: consumers with `legal_entity_id` read current name/INN/general director from `corporate_legal_entities`; legacy text fields are fallback/backward compatibility.

Restaurant form rule: selecting a ЮЛ must also populate the general-director field from master data.

Address rule: `legal_address` and `actual_address` are independent and may differ completely; restaurant display address remains a separate short address.

---

## 3. Delete/archive UX and guard

Current UX:

- delete/archive of legal-entity requisites shows only ordinary confirmation `Уверены, что хотите удалить?`;
- DELETE legal-entity does **not** require `PHONEBOOK_WEB_WRITE_TOKEN`;
- create/edit remain protected as before.

Archive guard remains mandatory:

- active `corporate_phone_directory.legal_entity_id` links block archive;
- active `corporate_directory_restaurants.legal_entity_id` links block archive.

Do not remove the guard merely because a phone is not visible in the UI. First inspect factual DB links.

---

## 4. Stale phone cleanup rule — accepted

If a phone row exists only in `corporate_phone_directory`, is not found in accessible phone/source-like tables outside the aggregate, has no restaurant personal-phone reference, and has no other active owner row for the same phone, it can be treated as stale legacy and retired with `active=false`.

Do not physically delete by default. Preserve history.

Production schema discovery on 15.09.2026 found phone-like tables:

- `corporate_directory_restaurants`;
- `corporate_phone_audit`;
- `corporate_phone_directory`.

No separate MegaFon/T2 source tables were found inside this PostgreSQL DB, so the verified statement is `NOT_FOUND_OUTSIDE_AGGREGATE` in accessible tables — not that external operator systems were independently queried.

---

## 5. Completed stale cleanup on 15.09.2026

### Four old legal entities

18 stale active phone rows were found and retired:

- `ООО "А-15 Новое Колпино` — 5;
- `ООО "Век"` — 5;
- `ООО "Евразия2008"` — 4;
- `ООО "Кайхон"` — 4.

Checks before write:

- no duplicate/current owner rows;
- all 18 `NOT_FOUND_OUTSIDE_AGGREGATE`;
- no restaurant personal-phone references;
- active restaurant links = 0 for all four.

Action:

- `UPDATE 18`, `active=false`;
- no physical DELETE;
- backup: `/opt/evrasia-ai-bot/backups/stale-phone-retire/20260915-081631/evrasia_ai_bot.pre-stale-phone-retire.dump`;
- post-check: active phone links = 0 and active restaurant links = 0 for all four;
- app remained healthy.

### `ООО "Евразия-Большевиков"`

Old master:

- ID `le-e6748431e3df3ddd16c8a04c`;
- INN `7811360046`.

Seven active legacy T2 rows were separately checked. All seven were `NOT_FOUND_OUTSIDE_AGGREGATE`, had no restaurant refs and no other owner rows. User also confirmed Гречко О.П. had long ago moved to `Евразия Южная` and these numbers no longer belonged to Большевиков.

User confirmed production cleanup succeeded: **«все ок, получилось»**. Treat the seven rows as successfully retired with `active=false`; history preserved.

---

## 6. Immediate Phonebook continuation point — IMPORTANT

Restaurant №28:

- address: `Большевиков 18`;
- active: `true` at last inspection;
- linked ЮЛ: `ООО "Евразия-Манхеттен"`;
- legal_entity_id: `le-b534bcce3b02ace0d23bfac2`;
- INN: `7805576103`;
- user reports the restaurant closed in **May 2026**.

At the last diagnostic point `ООО "Евразия-Манхеттен"` had 8 active phone rows. They were **not** touched by the cleanup of the seven legacy `ООО "Евразия-Большевиков"` rows.

Next step is not to bulk-disable all 8 blindly. First determine which of those 8 belong specifically to the closed restaurant №28 and whether any are still valid for another `Евразия-Манхеттен` use. Then retire only obsolete links and close/deactivate the restaurant row with exact guards and backup.

See `docs/PHONEBOOK_PRODUCTION_FOLLOWUP_2026-09-15.md`.

---

## 7. Anti-Fraud state still current unless superseded by factual production

Anti-Fraud remains advisory/investigative; risk does not auto-block.

Bitrix source-of-truth account state:

- `ACTIVE=Y`, `BLOCKED=N` → **Активен**;
- `ACTIVE=N`, `BLOCKED=N` → **Неактивен**;
- any `BLOCKED=Y` → **Заблокирован**.

Blocked/inactive are hidden by default from ordinary operational lists; toggle remains `Показать заблокированных и неактивных`.

Current threshold key: `anti_fraud_bonus_balance_threshold`; last confirmed value `40000`; strict `balance > threshold` rule; save does not itself trigger refresh.

The operator-facing `Новый` semantics were later changed from old case-delta semantics to 24h first-seen web semantics under PR #47. For current details read `docs/PROJECT_CHECKPOINT.md` and factual code/DB rather than the older PR #43 notes.

---

## 8. Website legal / consent state

Canonical website legal work remains production. Current source-of-truth details are in `docs/PROJECT_CHECKPOINT.md` and `docs/WEBSITE_LEGAL_CONSENT_INTEGRATION.md`.

Important current facts from the last accepted checkpoint:

- offer revision 11.09.2026 production;
- privacy policy production;
- signup uses required offer + required PD + optional marketing;
- native Bitrix consent tables are used;
- existing-user account consent gate is accepted for controlled USER_ID `880339` only unless rollout scope changes later.

Do not infer a global rollout from the controlled acceptance.

---

## 9. Trusted Device distinction

For authentication Trusted Device / SMS bypass counts, use `docs/TRUSTED_DEVICE_DIAGNOSTICS.md` and factual Bitrix-side storage (`ev_trusted_devices`). Do not answer that question from Anti-Fraud PostgreSQL device-link tables.

---

## 10. Mandatory deployment/script lessons

Read `docs/SERVER_SCRIPT_RULES.md` before server work.

Especially important:

- one complete copy/paste block;
- quoted-heredoc wrapper for large scripts;
- `bash -n` before execution;
- current factual production baseline before guards;
- production mutation as root, GHCR auth reused from user `tech`;
- never print secret values;
- exact target set before DB write;
- backup and backup verification before mutation;
- transaction + post-check;
- use `active=false` for stale phone cleanup instead of physical DELETE;
- keep terminal open.

Compose validation lesson from PR #50 deployment: when validating a staged compose file outside the production directory, use the production project directory so relative env/file paths resolve correctly; do not print resolved `docker compose config` output because it can expose secret values.

---

## 11. Do not redo completed work

Do not automatically repeat:

- v1.8 migration/canonical import;
- 18-row stale cleanup;
- 7-row `Евразия-Большевиков` stale cleanup;
- PR #50 delete-key UX deployment;
- old PR #43/#44/#45 modal work;
- controlled USER_ID 880339 block/unblock acceptance;
- prior similarity performance acceptance;
- archival TEST recovery.

Continue from the open restaurant №28 `Большевиков 18` investigation unless the user changes priority.
