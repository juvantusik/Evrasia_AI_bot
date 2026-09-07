# Evrasia AI Bot — AI Project Context

> Operational source of truth for continuing Evrasia AI Bot work across ChatGPT chats.
>
> **Last updated:** 2026-09-07
> **Current production:** Evrasia AI Bot v1.7, `/directory` removed
> **Repository:** `juvantusik/Evrasia_AI_bot`
> **Current source branch:** `fix/antifraud-case-refresh-ux` (PR #34 Draft; production unchanged)
> **Merged release PRs:** #32 v1.7 Anti-Fraud, #33 remove obsolete `/directory`

---

## 1. Continuation rule

In a new chat, read this file first, then `docs/CURRENT_ARCHITECTURE.md`, `docs/SERVER_SCRIPT_RULES.md` and `docs/NEW_CHAT_HANDOFF.md`. Verify live GitHub/server state only where the next task actually depends on it. Do not replay completed deployment steps.

Recommended new-chat prompt:

> Продолжаем проект Evrasia AI Bot. Репозиторий `juvantusik/Evrasia_AI_bot`. Сначала прочитай `docs/AI_PROJECT_CONTEXT.md`, `docs/CURRENT_ARCHITECTURE.md`, `docs/SERVER_SCRIPT_RULES.md` и `docs/NEW_CHAT_HANDOFF.md` из актуальной рабочей ветки/`main`. Считай production главным источником истины, затем актуальный код. Не повторяй уже выполненные действия. Для серверных задач давай один полный bash-wrapper с `clear`, guards, backup/rollback, PASS/FAIL и без вывода секретов.

---

## 2. Current milestone — PRODUCTION VERIFIED

Evrasia AI Bot v1.7 is deployed to production and verified.

Base v1.7 post-production audit on 2026-09-07:

- `PASS_COUNT=34`
- `WARN_COUNT=0`
- `FAIL_COUNT=0`
- `POST_PRODUCTION_AUDIT=PASS`
- `PRODUCTION_V17_VERIFIED=YES`
- `FINAL_STATUS=PASS`
- `FINAL_RC=0`.

After that audit, obsolete `/directory` compatibility routing was removed in PR #33 and deployed app-only to production.

Final `/directory` cleanup deployment result:

- `PASS_COUNT=37`
- `FAIL_COUNT=0`
- `ROLLBACK_FAIL_COUNT=0`
- `DIRECTORY_REMOVAL_PRODUCTION=VERIFIED`
- `CUTOVER_STARTED=YES`
- `FINAL_STATUS=PASS`
- `FINAL_RC=0`.

Exact current deployed application:

- application revision: `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:fd58cc95d3c26f541bd15d70fbd057f068630d093c6990f926152c995ca8f479`
- image/config ID: `sha256:78c3d07078995c04948f1fbad600421a665ce03ad35fd388f4d6893f3bc11a47`
- app container: `evrasia-ai-bot-app`
- status after deployment: running / healthy
- direct app port: `127.0.0.1:18080 -> 8080`.

PR #34 is a code/UI fix only at the current working stage; it has not changed production, nginx, DB schema or migrations.

Later documentation-only commits may advance `main`; do not confuse them with the deployed application revision above.

---

## 3. Current product architecture — IMPORTANT

Evrasia AI Bot is **one production application** with several business modules. Do not describe the current system as only “Phonebook + Anti-Fraud”, and do not describe SamZaberu/MegaFon as separate Docker bots unless the live topology is changed later.

The production container `evrasia-ai-bot-app` contains four working directions:

1. **Phonebook** — canonical and only web interface at `/phonebook`.
2. **Anti-Fraud** — web interface at `/antifraud` plus protected scheduler/data pipeline.
3. **SamZaberu** — Telegram scenario inside `EvrasiaTelegramBotV2`, with STOP/ENABLE operations through the Bitrix service layer.
4. **Corporate communications / MegaFon** — Telegram personal-chat and bound-group workflow using Phonebook data and MegaFon routing logic.

### Canonical route naming

`/phonebook` is the only current Phonebook web route.

`/directory` is **removed**:

- `/directory` = HTTP 404
- `/directory/...` = HTTP 404
- `/api/directory/phones` = HTTP 404.

Do not describe `/directory` as an alias, redirect, product or compatibility route.

Historical internal names containing `directory` may remain where they refer to a corporate-directory data structure or stable implementation identifier; they do not create a web route.

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

### PR #32 — v1.7 Anti-Fraud release

- state: closed
- draft: false
- merged: true
- source branch: `feature/v1.7-antifraud-web`
- merge commit: `33e3548ab014e927e1e00074e27f3a11ef252bbc`.

### PR #33 — remove obsolete `/directory` route

- state: closed
- draft: false
- merged: true
- source branch: `cleanup/remove-directory-route`
- source head before merge: `a9f017676f69f0d1594fbfe5b06bcfc448c628c2`
- merge commit: `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`.

GitHub Actions build #252 for merge commit `0fcebb1e...` completed successfully.

Published image:

- tag: `ghcr.io/juvantusik/evrasia_ai_bot:sha-0fcebb1`
- digest: `sha256:fd58cc95d3c26f541bd15d70fbd057f068630d093c6990f926152c995ca8f479`
- image/config ID: `sha256:78c3d07078995c04948f1fbad600421a665ce03ad35fd388f4d6893f3bc11a47`
- OCI revision label: `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`.

That exact immutable image is current production.

### PR #34 — Anti-Fraud case grouping / async refresh UX

Current working PR:

- state: open
- draft: true
- base: `main`
- branch: `fix/antifraud-case-refresh-ux`
- production unchanged.

Scope:

- corroborated similar phone/email identity links are allowed to join accounts into one investigation case;
- weak similarity remains insufficient unless `corroborated=true`;
- manual refresh contract changes from one long synchronous request to `202 Accepted` plus scheduler polling;
- UI shows `partial`/`failed` completion factually and preserves `409` when a cycle is already running;
- `no_active_card` is shown explicitly;
- technical similarity reason codes are translated for operator UI;
- regression coverage checks that the same-name + one-digit-phone rule produces a corroborated link and that the resulting link group is collapsed into one Anti-Fraud case group.

Do not mark PR #34 Ready or merge it until its updated CI is green and the user explicitly approves the next PR action.

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

After the `/directory` cleanup deployment:

- `public.samzaberu_requests` rows: 31
- continuity: PASS
- DB container ID/start timestamp unchanged
- DB schema unchanged.

Do not rename/delete SamZaberu business data merely because the old infrastructure once used the name `samzaberu`.

---

## 6. Phonebook web

Canonical and only working UI:

- `/phonebook`.

Current production verification:

- direct `/phonebook` = 200
- routed `/phonebook` = 200
- direct `/api/phonebook/phones` = 200
- `/directory` = 404 direct/routed
- `/api/directory/phones` = 404.

Product, documentation and architecture wording must say **Phonebook**, not Directory.

The codebase may still contain historical `directory-*` file/service/schema identifiers where they refer to corporate-directory implementation details; those identifiers do not mean a separate current `/directory` product.

---

## 7. SamZaberu Telegram module

SamZaberu is active business functionality inside `EvrasiaTelegramBotV2`.

Telegram root menu exposes `🍱 СамЗаберу` to allowed operational managers.

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

This module is production functionality, not a TEST artifact.

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

The group-routing logic is implemented in `megafon-group-routing.ts`; group chat binding is stored as bot setting.

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

After app-only cleanup deployment:

- scheduler endpoint = 200
- enabled = true
- running = false at check time
- interval = 15 minutes
- immediate post-restart scheduler status = `idle`
- latest protected DB cycle = `success`
- Telegram polling = true
- Telegram 409 conflicts observed after deployment = 0.

PR #34 does not alter the scheduler cadence or protected-cycle stage order; it changes only how manual web refresh waits for that existing single-flight cycle.

---

## 10. Nginx / current routes

Verified active routing:

- `/antifraud` -> production 18080
- `/antifraud/` -> production 18080
- `/api/anti-fraud/` -> production 18080
- `/assets/antifraud-` -> production 18080
- normal Phonebook/default traffic -> production 18080
- TEST port `18081` references: 0.

The `/directory` cleanup deployment did **not** modify nginx; nginx checksum remained unchanged.

Current route contract:

- `/phonebook` = 200
- `/antifraud` = 200
- `/directory` = 404
- `/api/directory/...` = 404.

PR #34 intentionally does not change nginx or `proxy_read_timeout`; the application returns manual refresh acceptance before that timeout and the UI polls scheduler status separately.

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

App-only `/directory` removal backup:

`/opt/evrasia-ai-bot/backups/app-only-remove-directory-20260907-090654`

Previous production rollback image retained:

`ghcr.io/juvantusik/evrasia_ai_bot@sha256:381e9d34e3ecd65e814cc93b2c0b91656bc9155a5437e86ea93c1a7f34bceffc`

Do not remove any of these until explicit cleanup approval.

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

Manual scheduler routes / web refresh contract in PR #34:

- `GET /api/anti-fraud/scheduler` returns current single-flight cycle state;
- `POST /api/anti-fraud/refresh` starts the same protected cycle and returns **HTTP 202 Accepted** immediately after the cycle is accepted;
- the web UI then polls `GET /api/anti-fraud/scheduler` until `running=false`;
- if a cycle is already running, POST remains **HTTP 409**;
- completed `partial` and `failed` states must be shown factually to the operator; do not turn a long successful cycle into a false nginx timeout error.

Anti-Fraud investigation-case grouping:

- shared device can join accounts into one case;
- exact normalized phone/email can join accounts into one case;
- similar phone/email may join accounts only when the stored identity link has `corroborated=true`;
- weak similarity by itself does not join cases;
- current similarity rule `sameName + phone differs by exactly one digit` is unchanged;
- corroborated links are included transitively, so a chain of valid links remains one investigation case;
- behavioral signals by themselves do not merge separate identities into one case.

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

The `/directory` cleanup deployment added no DB migration and left migration count at 18.

PR #34 also adds no DB schema/migration change; its case grouping consumes the existing `anti_fraud_identity_links.corroborated` data.

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
3. Old deployment logic treated the former `/directory` 308 redirect as a regression; that route is now removed and expected to return 404.
4. Nginx first-request race produced transient 502 when old worker still pointed at stopped TEST; keep old upstream alive through reload/retry.
5. Renaming a Compose-managed container is not a reliable rollback artifact because Compose labels remain.
6. One rollback restored DBs but not app; dedicated app recovery completed and was verified.
7. During `/directory` cleanup, a first app-only attempt stopped **before cutover** because a staged Compose file copied to `/tmp` resolved relative `prod-app.env` / `prod-db.env` paths under `/tmp`. Production stayed unchanged. Correct fix: stage the temporary Compose file inside `/opt/evrasia-ai-bot/prod` or stage all referenced relative files consistently.

Successful release history:

- Phase 1: application v1.7 + migrations 4->18 + nginx + scheduler + protected control cycle.
- Phase 2: PostgreSQL/Docker infrastructure rename only.
- App-only cleanup: remove `/directory`, no DB migration/restart and no nginx change.

All completed successfully.

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

For Compose staging, preserve the directory context of relative `env_file` and mount paths.

---

## 20. Product roadmap

- **v1.7** — current production baseline; Phonebook + Anti-Fraud + Telegram SamZaberu + Corporate communications/MegaFon all remain part of the unified production application; `/directory` removed.
- **v1.8** — document generation (Jira KAN-66, KAN-77).
- **v1.9** — document sending through Exchange (Jira KAN-67, KAN-78).

Trusted Device remains Anti-Fraud foundation/data source, not a separate bot version.

---

## 21. Parallel legal Bonus Club work

Technical Evrasia AI Bot and legal Bonus Club work are separate tracks.

Current legal package in the project includes offer, PD policy, optional additional-processing consent and advertising consent for `evrasia.rest`. Do not mix internal Anti-Fraud algorithms/risk thresholds into public legal documents unless legally required.

---

## 22. NEXT STEP — current continuation point

Production baseline remains unchanged at revision `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`.

Current working task is PR #34 (`fix/antifraud-case-refresh-ux`), still Draft. Before any Ready/merge/production step:

1. keep production untouched;
2. verify the updated PR CI/typecheck/tests are green;
3. confirm regression coverage for corroborated similar identity -> one case group;
4. confirm async refresh remains `202 + scheduler polling`, with 409 for already-running and factual partial/failed UX;
5. do not add DB migrations or nginx changes for this fix;
6. only after successful review ask for/receive explicit user approval before Ready or merge.

Stable production facts that remain in force:

1. Immutable production digest `sha256:fd58cc95d3c26f541bd15d70fbd057f068630d093c6990f926152c995ca8f479`.
2. `/phonebook` is the only Phonebook route.
3. `/directory` and `/api/directory/...` are absent and return 404.
4. Four current directions remain: Phonebook, Anti-Fraud, SamZaberu Telegram, Corporate communications/MegaFon Telegram.
5. Anti-Fraud scheduler remains enabled every 15 minutes; latest protected production cycle verified `success`.
6. Telegram polling remains enabled; deployment verification observed 0 Telegram 409 conflicts.
7. DB remains `evrasia_ai_bot`, migrations 18, Anti-Fraud tables 11, SamZaberu rows 31.
8. Keep backups and archival TEST assets until explicit cleanup approval.

---

## 23. Maintenance rule

Update this file, `docs/CURRENT_ARCHITECTURE.md`, `docs/NEW_CHAT_HANDOFF.md`, `SERVER_UPDATES.md` and, when relevant, `docs/SERVER_SCRIPT_RULES.md` after every material milestone affecting:

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
