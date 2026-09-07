# Evrasia — New Chat Handoff

> Fast handoff for continuing the Evrasia AI Bot project in a new ChatGPT chat.
>
> **Updated: 2026-09-07**

## Ready-to-paste instruction for a new chat

Продолжаем проект Evrasia AI Bot. Не начинай работу заново и не проси меня повторять уже установленный контекст.

Репозиторий: `juvantusik/Evrasia_AI_bot`.
Production source baseline: `main`.

Сначала прочитай:

1. `docs/PROJECT_CHECKPOINT.md` — самый свежий checkpoint и текущая точка продолжения;
2. `docs/AI_PROJECT_CONTEXT.md` — основной исторический/технический контекст;
3. `docs/CURRENT_ARCHITECTURE.md` — архитектура;
4. `docs/SERVER_SCRIPT_RULES.md` — обязательные правила серверных скриптов;
5. `docs/NEW_CHAT_HANDOFF.md` — этот handoff.

Если старые документы противоречат `PROJECT_CHECKPOINT.md`, считать checkpoint более новым. Приоритет источников: production > актуальный код/ветка > staging/test > актуальная документация > старые обсуждения.

---

## 1. Текущий production baseline

Evrasia AI Bot работает в production на `eur-bot-01` (`192.168.103.200`).

Текущий развернутый application baseline:

- revision: `be631fd31c96434ac7232f5e1641ecf9ea94c823`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:74a6d19805eb0d2f51da86cf8e8c2660e89d28c429250ebd64eb46549c106ff1`
- image config: `sha256:f1e11e8dc8bcca6ef80bbbe4142badf7d50401203e1e468ff93e970b6c2b3d4e`
- app: `evrasia-ai-bot-app`
- DB: `evrasia-ai-bot-db`, database `evrasia_ai_bot`
- migrations: 18
- Anti-Fraud tables: 11
- final backup: `/opt/evrasia-ai-bot/backups/antifraud-final-20260907-135220`

Final production verification after PR #34/#35 rollout:

- app-only recreation
- DB container unchanged/not restarted
- nginx unchanged
- `/phonebook` OK
- `/antifraud` OK
- `/directory` and `/api/directory/...` remain 404
- Telegram polling true, conflicts 0
- Danil grouped case manually verified
- async Anti-Fraud refresh manually verified
- `PASS=49`, `WARN=0`, `FAIL=0`, `FINAL_STATUS=PASS`

Do not treat old `0fcebb1...` baseline or PR #34 Draft state as current.

---

## 2. GitHub state

Merged:

- PR #32 — v1.7, merge `33e3548...`
- PR #33 — remove `/directory`, merge `0fcebb1...`
- PR #34 — similar-identity grouping + async refresh UX, merge `bf17177a...`
- PR #35 — CI regression fix, merge `be631fd31c96434ac7232f5e1641ecf9ea94c823`

Release CI run #268 / ID `34105440103`: success, tests `60/60`.

No PR merge without explicit user approval.

---

## 3. Current active workstream

**Anti-Fraud blocking + Bitrix status sync + group bonus total.**

Bitrix is the source of truth for account status.

Required status mapping:

- `ACTIVE=Y`, `BLOCKED=N` => `Активен`
- `ACTIVE=N`, `BLOCKED=N` => `Неактивен`
- `BLOCKED=Y` => `Заблокирован`

Anti-Fraud block must set both:

- `ACTIVE=N`
- `BLOCKED=Y`

Approved public block reason:

> По результатам проведенной проверки подтверждено нарушение Правил программы лояльности «Бонусный Клуб Евразия», квалифицированное как недобросовестное использование Программы. В соответствии с п. 3.9 Правил применена блокировка учетной записи и связанных с ней возможностей участия в Программе.

Never expose customer-facing:

- `AF-...` case ID
- risk score
- device IDs
- similar phone/email/multiaccount technical evidence

---

## 4. Bitrix side — already implemented and proven in production

Server:

- hostname `evrasia`
- site root `/home/site_evrasia/web/evrasia.spb.ru/public_html`
- nginx listens on `192.168.103.141:443`; local vhost checks use `--resolve evrasia.rest:443:192.168.103.141`

Implemented:

- user field `UF_AF_BLOCK_REASON`, ID `166`
- account-map now returns `bitrix_active`, `bitrix_blocked`, `block_reason`
- protected `POST /api/internal/anti-fraud/block`
- existing Anti-Fraud service-token auth reused
- real block uses `CUser->Update()`
- writes `ACTIVE=N`, `BLOCKED=Y`, approved public reason
- re-reads and verifies factual Bitrix state after mutation
- repeat block is idempotent (`already_blocked`, `changed=false`)
- custom Bitrix admin display via `main:OnAdminTabControlBegin`
- `local/php_interface/anti_fraud_admin.php` is included from `local/php_interface/init.php`
- any admin with access to the user card can see `Основание блокировки`
- core Bitrix `user_edit.php` was not changed

Controlled real test explicitly authorized by user:

- USER_ID `880339`
- before: `ACTIVE=Y`, `BLOCKED=N`
- after: `ACTIVE=N`, `BLOCKED=Y`
- approved reason stored and independently confirmed by account-map
- idempotency confirmed
- admin card visual display confirmed by user
- account intentionally remains blocked at this checkpoint

Important: Bitrix repo already had unrelated local changes before this work. Never `git add .`, `git reset --hard` or `git clean`. Stage only exact files after reviewing diffs.

---

## 5. What is NOT done yet in Evrasia AI Bot app

The app-side integration still needs implementation:

1. inspect current `main` code and migration conventions;
2. persist `bitrix_blocked` and `bitrix_block_reason` in `anti_fraud_accounts`;
3. extend Bitrix gateway/collector so every refresh updates real status/reason;
4. extend case/account DTOs;
5. add app-side block gateway/service to call protected Bitrix block endpoint;
6. add internal block audit;
7. UI statuses: `Активен` / `Неактивен` / `Заблокирован`;
8. per-account block button;
9. group block button with per-user partial result;
10. disable button / show `Уже заблокирован` for blocked accounts;
11. group bonus sum across all accounts, preserving unknown values, e.g. `Бонусы группы: 64 350 · данные 3 из 4`;
12. tests for mapping/idempotency/partial result/public reason/no public case ID/group bonus NULL handling;
13. branch/PR/CI; explicit approval before merge;
14. guarded production deployment.

Do not resume the paused full-Bitrix email investigation unless explicitly asked.

---

## 6. Product architecture not to lose

One production app/container contains:

1. Phonebook — `/phonebook`
2. Anti-Fraud — `/antifraud` + 15-minute scheduler
3. SamZaberu — Telegram STOP/ENABLE scenario
4. Corporate communications/MegaFon — Telegram scenario

`/directory` is removed and must remain 404.

Telegram runs one `EvrasiaTelegramBotV2` polling consumer; SamZaberu and MegaFon are scenarios inside it, not separate Docker bots.

---

## 7. Mandatory server-script behavior

Read `docs/SERVER_SCRIPT_RULES.md` before server work.

Key rules:

- one pasteable block
- `clear` first
- guards before mutation
- backups + verification
- numbered output stages
- PASS/FAIL and truthful RC propagation
- no secrets
- outer interactive wrapper must never call `exit`
- temporary child script may `exit "$RC"`
- KiTTY can truncate very large/nested heredocs and leave `>` continuation prompt; use shorter paste-robust blocks and do not retry the same fragile paste unchanged
- terminal must remain open

---

## 8. Immediate continuation point

Do **not** repeat completed Bitrix discovery/block tests or old PR #34 work.

Next engineering iteration:

**inspect current main/migrations -> implement app-side Bitrix blocked/reason sync + block client/API + group bonus/buttons -> tests -> PR/CI -> explicit approval -> production.**

Backups on both production hosts are retained. Do not clean them without explicit approval.
