# Evrasia AI Bot — AI Project Context

> Operational source of truth for continuing Evrasia AI Bot work across ChatGPT chats.
>
> **Last updated:** 2026-09-07
> **Current production:** Evrasia AI Bot v1.7
> **Repository:** `juvantusik/Evrasia_AI_bot`
> **Current source branch:** `main`
> **Merged release PR:** #32 — `Evrasia AI Bot v1.7 — Anti-Fraud investigation web UI`

---

## 1. Continuation rule

In a new chat, read this file first, then `docs/CURRENT_ARCHITECTURE.md`, `docs/SERVER_SCRIPT_RULES.md` and `docs/NEW_CHAT_HANDOFF.md`. Verify live GitHub/server state only where the next task actually depends on it. Do not replay completed deployment steps.

Recommended new-chat prompt:

> Продолжаем проект Evrasia AI Bot. Репозиторий `juvantusik/Evrasia_AI_bot`. Сначала прочитай `docs/AI_PROJECT_CONTEXT.md`, `docs/CURRENT_ARCHITECTURE.md`, `docs/SERVER_SCRIPT_RULES.md` и `docs/NEW_CHAT_HANDOFF.md` из `main`. Считай production главным источником истины, затем актуальный `main`. Не повторяй уже выполненные действия. Для серверных задач давай один полный bash-wrapper с `clear`, guards, backup/rollback, PASS/FAIL и без вывода секретов.

---

## 2. Current milestone — PRODUCTION v1.7 VERIFIED

Evrasia AI Bot v1.7 is deployed to production and independently post-audited on 2026-09-07.

Final post-production audit:

- `PASS_COUNT=34`
- `WARN_COUNT=0`
- `FAIL_COUNT=0`
- `POST_PRODUCTION_AUDIT=PASS`
- `PRODUCTION_V17_VERIFIED=YES`
- `FINAL_STATUS=PASS`
- `FINAL_RC=0`.

This supersedes all older statements that production is still on v1.6.x or that v1.7 exists only on TEST.

Exact deployed application:

- application revision: `109ac7a2a05c0289336b3b332cb09ca102253396`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:381e9d34e3ecd65e814cc93b2c0b91656bc9155a5437e86ea93c1a7f34bceffc`
- image/config ID: `sha256:ee94f6b17d4dc9f727266675d05fd3dd1ec992f30850b351d96dbca29a37e628`
- app container: `evrasia-ai-bot-app`
- app status at audit: running / healthy
- direct app port: `127.0.0.1:18080 -> 8080`.

Do not confuse later Git/documentation commits with the deployed application revision above.

---

## 3. Current product architecture — IMPORTANT

Evrasia AI Bot is **one production application** with several business modules. Do not describe the current system as only “Phonebook + Anti-Fraud”, and do not describe SamZaberu/MegaFon as separate Docker bots unless the live topology is changed later.

The production container `evrasia-ai-bot-app` currently contains four working directions:

1. **Phonebook** — canonical web interface at `/phonebook`.
2. **Anti-Fraud** — web interface at `/antifraud` plus protected scheduler/data pipeline.
3. **SamZaberu** — Telegram scenario inside `EvrasiaTelegramBotV2`, with STOP/ENABLE operations through the Bitrix service layer.
4. **Corporate communications / MegaFon** — Telegram personal-chat and bound-group workflow using Phonebook data and MegaFon routing logic.

### Canonical route naming

`/phonebook` is the actual working Phonebook route.

`/directory` is **not a separate product/module**. It is legacy compatibility only:

- `/directory` -> HTTP 308 -> `/phonebook`
- `/directory/` -> HTTP 308 -> `/phonebook/`.

Do not put `/directory` on current architecture diagrams as a separate service or feature.

### Telegram topology

The server starts one `EvrasiaTelegramBotV2` polling consumer from the production application when `TELEGRAM_BOT_POLLING=true`.

Inside it:

- SamZaberu is an internal Telegram scenario/module;
- Corporate communications is another module;
- MegaFon group handling is part of Corporate communications;
- there is no separate production Docker container for “SamZaberu bot” or “MegaFon bot”.

See `docs/CURRENT_ARCHITECTURE.md` for the compact current diagram.

---

## 4. GitHub current state

PR #32 was explicitly approved by the user, moved from Draft to Ready, and merged into `main` on 2026-09-07.

Final PR state:

- state: closed
- draft: false
- merged: true
- source branch: `feature/v1.7-antifraud-web`
- merge commit: `33e3548ab014e927e1e00074e27f3a11ef252bbc`.

At the time of this context update, `main` pointed to merge commit `33e3548ab014e927e1e00074e27f3a11ef252bbc` before this documentation-fix commit.

The deployed production application is still revision `109ac7a2...`; merging documentation/release history into `main` did not redeploy production.

For future work, start from current `main` unless a new feature branch is explicitly created.

---

## 5. Production infrastructure — verified 2026-09-07

### Host

- hostname: `eur-bot-01`
- IP: `192.168.103.200`
- OS: Debian 13 (trixie)
- Docker Engine: 26.1.5
- Docker Compose: 2.26.1-4.

### Docker / Compose

- Compose project: `evrasia-prod`
- app service/container: `evrasia-ai-bot-app`
- PostgreSQL service/container: `evrasia-ai-bot-db`
- Docker network: `evrasia-prod-internal`
- PostgreSQL volume: `evrasia-postgres-prod-data`
- PostgreSQL image: `postgres:16-bookworm`.

### PostgreSQL

- production role/user: `evrasia_ai_bot`
- production DB: `evrasia_ai_bot`
- retained test DB: `evrasia_ai_bot_antifraud_test`
- both DBs owned by `evrasia_ai_bot`
- production migrations: 18
- retained test DB migrations: 18
- Anti-Fraud production tables: 11.

Active legacy infrastructure names are absent:

- DB role `samzaberu`: absent
- DB names `samzaberu`, `samzaberu_antifraud_test`: absent
- container/service `samzaberu-db`: absent
- active Compose refs to `samzaberu-db`: 0.

### SamZaberu business data is NOT legacy

`public.samzaberu_requests`, SamZaberu API/service names and SamZaberu business semantics are intentional current business functionality.

At post-production audit:

- `public.samzaberu_requests` rows: 31
- continuity: PASS.

Do not rename/delete them merely because the old infrastructure once used the name `samzaberu`.

---

## 6. Phonebook web

Canonical working UI:

- `/phonebook`.

Nginx routes the normal application web traffic to production `127.0.0.1:18080`.

The legacy `/directory` URL exists only as an application redirect to `/phonebook` for compatibility. Product, documentation and architecture wording should say **Phonebook**, not Directory, unless discussing legacy code/file names or the redirect itself.

The codebase still contains some historical `directory-*` file/service names; those are implementation/history names and do not mean a separate current `/directory` product.

---

## 7. SamZaberu Telegram module

SamZaberu is active business functionality inside `EvrasiaTelegramBotV2`.

Telegram root menu exposes `🍱 СамЗаберу` to allowed operational managers.

The internal SamZaberu flow delegates to the existing Telegram SamZaberu handler and backend service.

Backend routes include `/api/samzaberu/...` for restaurants, access, summary, requests and manual completion.

`SamzaberuService`:

- validates operator/restaurant access;
- persists request journal and rules;
- supports actions `STOP` and `ENABLE`;
- calls the Bitrix service layer (`applyStop` / `applyEnable`);
- retries up to 3 times;
- verifies persisted/factual rule state;
- records success/cancel/escalation states;
- preserves manual-completion path for escalated requests.

This module is not a TEST artifact and must be included in any description of the current production bot.

---

## 8. Corporate communications / MegaFon Telegram module

Corporate communications is another active module inside `EvrasiaTelegramBotV2`.

Private-chat flow:

- user chooses `📱 Корпоративная связь`;
- enters a phone number;
- bot resolves Phonebook data;
- user describes the problem;
- for MegaFon, the bot checks membership of the bound “Евразия Мегафон” group and posts a structured request into that group.

Bound-group flow:

- Super Admin binds the target MegaFon group with `/bind_megafon_group`;
- bot processes normal messages only in the bound group;
- it can resolve phone/ООО from Phonebook data;
- asks for missing phone/legal-entity/problem data when needed;
- refuses ambiguous/mismatched phone-to-legal-entity routing;
- prepares/addressses the structured request to the configured MegaFon manager.

The group-routing logic is implemented in `megafon-group-routing.ts`; the group chat binding is stored as bot setting.

For T2 records, Corporate communications produces a prepared contact/request flow rather than using the MegaFon bound-group path.

---

## 9. Anti-Fraud production runtime

Verified runtime:

- `ANTI_FRAUD_SCHEDULER_ENABLED=true`
- `ANTI_FRAUD_SCHEDULER_INTERVAL_MINUTES=15`
- `ANTI_FRAUD_SCHEDULER_RUN_ON_START=false`
- `TELEGRAM_BOT_POLLING=true`
- `BITRIX_ANTI_FRAUD_TOKEN_FILE=/run/secrets/anti-fraud-service-token`.

Required secret mounts are present/readable/non-empty:

- `/run/secrets/bitrix-api-token`
- `/run/secrets/anti-fraud-service-token`.

Never print their values.

Post-audit scheduler state:

- enabled: true
- running: false at check time
- interval: 15 minutes
- last status: success
- last error: none
- next run scheduled.

Latest protected DB cycle at audit:

- source: `anti_fraud_protected_cycle`
- status: `success`.

Telegram polling conflict count at audit: 0.

---

## 10. Nginx / current routes

Verified active Anti-Fraud routing:

- `/antifraud` -> production 18080
- `/antifraud/` -> production 18080
- `/api/anti-fraud/` -> production 18080
- `/assets/antifraud-` -> production 18080
- normal Phonebook/default traffic -> production 18080
- TEST port `18081` references: 0
- `nginx -t`: PASS.

HTTP checks passed for direct/routed health, Phonebook and Anti-Fraud.

Again: `/directory` is only a legacy 308 redirect to `/phonebook`; do not treat it as a current interface.

---

## 11. TEST state after production cutover

Old container:

- `evrasia-ai-bot-v17-test`
- status: exited
- archival/rollback evidence only.

Do not restart it blindly. Its environment belongs to the old TEST topology, while the retained DB has already been renamed to `evrasia_ai_bot_antifraud_test`.

If TEST is needed again, recreate it deliberately using the current production-era naming/topology.

---

## 12. Backups / rollback assets — KEEP

Do not clean without explicit user approval.

Phase 1 directory:

`/opt/evrasia-ai-bot/backups/production-v17-phase1-20260907-053318`

Production dump SHA256:

`8cf697c2faa5010d12cb9389aac1ecd38929d1672ff1bdd432bad5df5c45e14a`

Phase 2 directory:

`/opt/evrasia-ai-bot/backups/production-v17-phase2-dbrename-20260907-054218`

- production dump `evrasia-production-pre-dbrename.dump`
- SHA256 `6dfd1b8f0d3d30857ac3c7a06d29f05e38ccdccb4b86db5a0862780b14bb56f1`
- test dump `evrasia-test-pre-dbrename.dump`
- SHA256 `bd862bc8445c632face19b4d96e23edc49d1c2385c5f05481be5d40a3a14327c`
- `postgres-globals-pre-dbrename.sql`
- pre-change Compose/env/inspect assets.

Audit verified dump hashes and `pg_restore -l` readability.

---

## 13. Anti-Fraud architecture / contracts

Protected site-side routes:

- `POST /api/internal/anti-fraud/card-map`
- `POST /api/internal/anti-fraud/account-map`
- `POST /api/internal/anti-fraud/trusted-device-export`
- `POST /api/internal/anti-fraud/loyalty`.

The bot does not receive direct RestIS credentials.

Protected scheduler cycle:

1. Trusted Device export
2. Bitrix account-map
3. preliminary risk scoring without history
4. priority loyalty for risky/newly-risky accounts
5. rolling stale loyalty scan up to 200 accounts
6. final risk scoring with targeted 60-day history only for history gate
7. case dynamics.

Manual scheduler routes:

- `GET /api/anti-fraud/scheduler`
- `POST /api/anti-fraud/refresh`
- POST returns 409 if a cycle is already running.

Do not run a full ~1784-account loyalty burst every 15 minutes.

Risk is advisory-only; no automatic account blocking.

Thresholds:

- critical >=75
- high >=50
- medium >=25.

High-balance rule:

- current balance strictly `> 40000.00`: +50 and history gate
- exactly `40000.00`: no high-balance trigger.

Multiple active cards are operator-visible anomaly but add 0 automatic risk points until business rules change.

`TotalSum` is account/phone-level current balance; never multiply/sum it once per active card.

`0.00` means known zero; `NULL` means unavailable/unknown.

Raw loyalty card numbers must not appear in bot UI/API/logs.

Case dynamics:

- Новый
- Усилился
- Без изменений
- Ослаб.

---

## 14. Loyalty fleet reference

Read-only classification from 2026-09-05:

- active Bitrix accounts: 1784
- exactly one active state-113 card: 1438
- no active state-113 card: 240
- multiple active state-113 cards: 106
- unresolved: 0.

---

## 15. Migrations relevant to v1.7

Production/test total: 18.

Important late migrations:

- **0013** — exact monetary/loyalty decimal fields, `bonus_balance NUMERIC(14,2)`, `loyalty_verified`.
- **0014** — RestIS event identity hardening; raw `restis_id` retained as nonunique source metadata.
- **0015** — signed current bonus balance; current balance may be negative.
- **0016** — `loyalty_active_card_count`, `loyalty_issue`; NULL/0/1/>1 semantics.
- **0017** — `anti_fraud_sync_runs.status` supports `running`, `success`, `partial`, `failed`.

Production audit verified the `partial` constraint.

---

## 16. Trusted Device foundation

- per-install opaque `device_id`
- 32 cryptographically random bytes -> 64 lowercase hex
- regex `^[a-f0-9]{64}$`
- not UUID/IMEI/MAC/advertising ID/hardware identifier
- survives restart/update/logout
- reinstall creates a new ID
- server may store SHA-256 device hash
- trust TTL: 90 days
- IP is not identity/trust.

Protected export endpoint:

`/api/internal/anti-fraud/trusted-device-export`.

---

## 17. Paused anomaly investigation — USER_ID 737384

**Status: PAUSED by user. Do not resume unless asked.**

Safe conclusions already established:

- account had 10 active state-113 cards
- 9 newer cards map to type/prefix corresponding to «Евразия 50%»
- current RestIS and Bitrix card sets matched
- legacy `account.php` mirrored RestIS cards into Bitrix
- Anti-Fraud did not create these cards
- thousands of activation-related requests were observed around 2026-09-02/03
- exact initiating frontend/user/process cause remains unresolved
- never replay activation requests and never persist full card numbers in docs.

---

## 18. Deployment history / lessons already resolved

Do not repeat these failures:

1. YAML accidentally executed as Python; generated-file path must be passed to `python3 - ...`.
2. PostgreSQL boolean display mismatch caused false rollback; map booleans explicitly to stable values.
3. Strict 200 check treated expected `/directory` 308 redirect as regression.
4. Nginx first-request race produced transient 502 when old worker still pointed at stopped TEST; keep old upstream alive through reload/retry.
5. Renaming a Compose-managed container is not a reliable rollback artifact because Compose labels remain.
6. One rollback restored DBs but not app; dedicated app recovery completed and was verified.

Final release was safely split:

- Phase 1: application v1.7 + migrations 4->18 + nginx + scheduler + protected control cycle.
- Phase 2: PostgreSQL/Docker infrastructure rename only.

Both completed successfully.

---

## 19. Mandatory server-script rules

`docs/SERVER_SCRIPT_RULES.md` is mandatory.

Operator preference:

- one complete copy-paste block
- wrapper writes `/tmp/...sh` via quoted heredoc
- real script begins with `clear`
- then `set +e`, `set +u`, `set +o pipefail 2>/dev/null`
- syntax check with `bash -n`
- numbered stages
- guards before mutation
- backup before DB changes
- rollback
- explicit PASS/FAIL/FINAL_STATUS/FINAL_RC
- no secret values
- terminal remains open.

Do not omit `clear`; the operator explicitly requires each new server script to visually clear previous output.

---

## 20. Product roadmap

- **v1.7** — current production baseline; Phonebook + Anti-Fraud + Telegram SamZaberu + Corporate communications/MegaFon all remain part of the unified production application.
- **v1.8** — document generation (Jira KAN-66, KAN-77).
- **v1.9** — document sending through Exchange (Jira KAN-67, KAN-78).

Trusted Device remains Anti-Fraud foundation/data source, not a separate bot version.

---

## 21. Parallel legal Bonus Club work

Technical Evrasia AI Bot and legal Bonus Club work are separate tracks.

Current legal package in the project includes offer, PD policy, optional additional-processing consent and advertising consent for `evrasia.rest`. Do not mix internal Anti-Fraud algorithms/risk thresholds into public legal documents unless legally required.

---

## 22. NEXT STEP — current continuation point

No production deployment or PR action is pending for v1.7.

Current continuation rules:

1. Treat production v1.7 as the active baseline.
2. Treat `/phonebook` as canonical; `/directory` only as legacy redirect compatibility.
3. Include all four current directions when discussing architecture: Phonebook, Anti-Fraud, SamZaberu Telegram, Corporate communications/MegaFon Telegram.
4. Keep backups and archival TEST assets until explicit cleanup approval.
5. Do not restart archival TEST blindly.
6. For a new feature, verify current `main` and production, then create the next branch/PR as needed.
7. Do not repeat v1.7 migration/deployment work without a new factual reason.

---

## 23. Maintenance rule

Update this file and `docs/CURRENT_ARCHITECTURE.md` after every material milestone affecting:

- branch / PR / merge state
- deployed revision/image
- production infrastructure/naming
- current product modules or canonical routes
- Telegram bot/module topology
- DB/migration level
- scheduler/runtime
- nginx routing
- integrations/business logic
- backup/rollback status
- next continuation point.

When a decision changes, preserve the meaning as: **Было -> Стало -> Причина**.