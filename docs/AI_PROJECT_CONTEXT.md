# Evrasia AI Bot — AI Project Context

> Operational handoff / source of truth for continuing Evrasia AI Bot work across ChatGPT chats.
>
> **Last updated:** 2026-09-07
> **Current production:** v1.7 Anti-Fraud
> **Repository:** `juvantusik/Evrasia_AI_bot`
> **Working branch:** `feature/v1.7-antifraud-web`
> **PR:** #32 — `Evrasia AI Bot v1.7 — Anti-Fraud investigation web UI`
> **PR policy:** keep Draft until explicit user approval. Do not mark Ready or merge without that approval.

---

## 1. Continuation rule

In a new chat, read this file first, then verify the live PR/HEAD and only the server facts needed for the next task. Do not replay completed deployment steps.

Recommended new-chat prompt:

> Продолжаем проект Evrasia AI Bot. Репозиторий `juvantusik/Evrasia_AI_bot`. Сначала прочитай `docs/AI_PROJECT_CONTEXT.md`, `docs/SERVER_SCRIPT_RULES.md` и `docs/NEW_CHAT_HANDOFF.md` в актуальной рабочей ветке, проверь PR #32/HEAD и продолжай строго с `NEXT STEP`. Не повторяй уже выполненные действия. Для серверных задач давай один полный bash-wrapper с `clear`, guards, backup/rollback, PASS/FAIL и без вывода секретов.

---

## 2. Current milestone — PRODUCTION v1.7 VERIFIED

Production v1.7 deployment is complete and was independently post-audited on 2026-09-07.

Final post-production audit result:

- `PASS_COUNT=34`
- `WARN_COUNT=0`
- `FAIL_COUNT=0`
- `POST_PRODUCTION_AUDIT=PASS`
- `PRODUCTION_V17_VERIFIED=YES`
- `FINAL_STATUS=PASS`
- `FINAL_RC=0`.

This supersedes all older statements that production is still on v1.6.x or that v1.7 exists only on TEST.

### Exact deployed application

- application revision: `109ac7a2a05c0289336b3b332cb09ca102253396`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:381e9d34e3ecd65e814cc93b2c0b91656bc9155a5437e86ea93c1a7f34bceffc`
- image/config ID: `sha256:ee94f6b17d4dc9f727266675d05fd3dd1ec992f30850b351d96dbca29a37e628`
- app container: `evrasia-ai-bot-app`
- app status: running / healthy at audit
- direct app port: `127.0.0.1:18080 -> 8080`.

Do not confuse later documentation-only branch commits with the deployed application revision above.

---

## 3. GitHub / PR current policy

Immediately before the documentation update, PR #32 was verified as:

- state: open
- draft: true
- merged: false
- mergeable: true
- base: `main`
- base SHA: `99377cb34a7798f982d0d9e073d13a6a4ef00afc`
- head branch: `feature/v1.7-antifraud-web`
- application-code HEAD before docs update: `109ac7a2a05c0289336b3b332cb09ca102253396`.

Documentation commits after the production audit advance the branch HEAD, but production remains pinned to the immutable v1.7 application image above.

**Do not mark PR #32 Ready and do not merge it without explicit user approval.**

---

## 4. Production infrastructure — verified 2026-09-07

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

### PostgreSQL names

- production role/user: `evrasia_ai_bot`
- production DB: `evrasia_ai_bot`
- retained test DB: `evrasia_ai_bot_antifraud_test`
- both DBs owned by `evrasia_ai_bot`
- production migrations: 18
- test DB migrations: 18
- Anti-Fraud production tables: 11.

Legacy infrastructure names are absent from the active system:

- DB role `samzaberu`: absent
- DB names `samzaberu`, `samzaberu_antifraud_test`: absent
- container/service `samzaberu-db`: absent
- active Compose references to `samzaberu-db`: 0.

### Important non-legacy SamZaberu business data

`public.samzaberu_requests` is a real business-module table and was intentionally **not** renamed.

At the post-production audit:

- `samzaberu_requests` rows: 31
- data continuity: PASS.

Do not treat `samzaberu_requests` or `/api/samzaberu/...` business semantics as legacy infrastructure names.

---

## 5. Production Anti-Fraud runtime

Verified runtime environment:

- `ANTI_FRAUD_SCHEDULER_ENABLED=true`
- `ANTI_FRAUD_SCHEDULER_INTERVAL_MINUTES=15`
- `ANTI_FRAUD_SCHEDULER_RUN_ON_START=false`
- `TELEGRAM_BOT_POLLING=true`
- `BITRIX_ANTI_FRAUD_TOKEN_FILE=/run/secrets/anti-fraud-service-token`.

Both required secret mounts are present/readable/non-empty:

- `/run/secrets/bitrix-api-token`
- `/run/secrets/anti-fraud-service-token`.

Never print their values.

Post-audit scheduler state showed:

- enabled: true
- running: false at check time
- interval: 15 minutes
- last status: success
- last error: none
- next run scheduled.

Latest protected cycle in DB at audit:

- source: `anti_fraud_protected_cycle`
- status: `success`.

Telegram polling conflict check:

- 409 conflict count: 0.

---

## 6. Nginx / routes

Production nginx now routes all Anti-Fraud paths to production `127.0.0.1:18080`.

Verified:

- `/antifraud` -> 18080
- `/antifraud/` -> 18080
- `/api/anti-fraud/` -> 18080
- `/assets/antifraud-` -> 18080
- normal `/`/phonebook route -> 18080
- references to TEST port `18081`: 0
- `nginx -t`: PASS.

HTTP checks passed for:

- direct `/api/healthz`
- direct `/phonebook`
- direct `/antifraud`
- routed `/phonebook`
- routed `/antifraud`
- routed `/directory` with redirect-follow.

`/directory` behavior is intentionally legacy-compatible:

- `/directory` -> HTTP 308 -> `/phonebook`
- `/directory/` -> HTTP 308 -> `/phonebook/`
- final response after redirect: 200.

Do not treat this specific 308 redirect as a regression.

---

## 7. TEST state after production cutover

Old TEST container:

- name: `evrasia-ai-bot-v17-test`
- status: exited
- it is archival/rollback evidence only
- its environment still references the old test topology and must **not** be restarted blindly.

The retained test database has already been renamed to:

- `evrasia_ai_bot_antifraud_test`.

If a new TEST environment is needed later, build/recreate it deliberately from current production-era naming rather than restarting the archival container.

---

## 8. Deployment history / mistakes already resolved

Do not repeat these deployment failures:

1. **YAML executed as Python** — an earlier staging wrapper called `python3 "$STAGE/compose.yml"` instead of `python3 - "$STAGE/compose.yml"`. No cutover occurred; fixed and verified.
2. **Boolean guard mismatch** — PostgreSQL returned `true|true` while a guard expected `t|t`, causing a false rollback. Later checks explicitly map booleans to `YES/NO`.
3. **`/directory` false regression** — strict HTTP 200 guard rejected the expected `308 -> /phonebook -> 200` behavior.
4. **Nginx first-request race** — during an early cutover, the first `/antifraud` request after reload was handled by an old worker still pointing to stopped TEST port 18081, producing a 502. Final safe sequence kept TEST alive during nginx reload and used retry before stopping TEST.
5. **Rollback-container Compose labels** — renaming a Compose-managed app container did not make it a reliable rollback artifact; `docker compose up` could recreate it. Final safe process instead used explicit config/database backups and controlled recreation.
6. One rollback restored both DBs/data but failed to restore the production app container; a dedicated recovery script recreated the old app from the exact restored Compose and verified health, DB URL, Telegram and routes before continuing.

Final deployment was intentionally split into two safe phases:

- Phase 1: deploy v1.7, migrate 4 -> 18, switch nginx, stop TEST, enable scheduler, run protected control cycle.
- Phase 2: rename only PostgreSQL/Docker infrastructure, with no application version change and no nginx change.

Both phases completed successfully.

---

## 9. Backups / rollback assets — KEEP

Do not clean these without explicit approval.

### Phase 1 production backup

Directory:

`/opt/evrasia-ai-bot/backups/production-v17-phase1-20260907-053318`

Production dump SHA256:

`8cf697c2faa5010d12cb9389aac1ecd38929d1672ff1bdd432bad5df5c45e14a`

### Phase 2 pre-rename backups

Directory:

`/opt/evrasia-ai-bot/backups/production-v17-phase2-dbrename-20260907-054218`

Production dump:

- `evrasia-production-pre-dbrename.dump`
- SHA256 `6dfd1b8f0d3d30857ac3c7a06d29f05e38ccdccb4b86db5a0862780b14bb56f1`

Test dump:

- `evrasia-test-pre-dbrename.dump`
- SHA256 `bd862bc8445c632face19b4d96e23edc49d1c2385c5f05481be5d40a3a14327c`

Also retained:

- `postgres-globals-pre-dbrename.sql`
- pre-change Compose/env/inspect assets.

Audit verified the two dump hashes and `pg_restore -l` readability.

---

## 10. Migrations relevant to v1.7

Production/test journal total: **18**.

Important late migrations:

### 0013 — anti_fraud_loyalty_decimal

- `bonus_balance NUMERIC(14,2)`
- loyalty sync/history timestamps
- nullable visit card link
- exact monetary fields
- `loyalty_verified`.

### 0014 — anti_fraud_restis_event_identity

Raw RestIS `restis_id` is not unique per business operation. Stable internal identity must include the business signature; raw ID is retained as nonunique source metadata.

### 0015 — anti_fraud_signed_bonus_balance

Current balance may be negative. Historical transaction amounts remain non-negative contracts.

### 0016 — anti_fraud_loyalty_resolution

Adds:

- `loyalty_active_card_count`
- `loyalty_issue`.

Semantics:

- NULL = not loaded/unknown
- 0 = no active state-113 card
- 1 = exactly one active state-113 card
- >1 = multiple active state-113 cards.

### 0017 — sync-run partial status

`anti_fraud_sync_runs.status` supports:

- `running`
- `success`
- `partial`
- `failed`.

Post-production audit verified `partial` is present in the active constraint.

---

## 11. Protected Anti-Fraud architecture

Protected site-side routes:

- `POST /api/internal/anti-fraud/card-map`
- `POST /api/internal/anti-fraud/account-map`
- `POST /api/internal/anti-fraud/trusted-device-export`
- `POST /api/internal/anti-fraud/loyalty`.

The bot does **not** receive direct RestIS credentials.

Protected scheduler cycle:

1. Trusted Device export
2. Bitrix account-map
3. preliminary risk scoring without history
4. priority loyalty for risky/newly-risky accounts
5. rolling stale loyalty scan up to 200 accounts
6. final risk scoring with targeted history only for history gate
7. case dynamics.

Manual routes:

- `GET /api/anti-fraud/scheduler`
- `POST /api/anti-fraud/refresh`
- POST returns 409 when a cycle is already running.

Never run a full ~1784-account loyalty burst every 15 minutes. Low-risk accounts use rolling refresh; detailed 60-day history remains targeted.

---

## 12. Loyalty / fleet facts

Read-only fleet classification from 2026-09-05:

- active Bitrix accounts: 1784
- exactly one active state-113 card: 1438
- no active state-113 card: 240
- multiple active state-113 cards: 106
- unresolved: 0.

Important semantics:

- `TotalSum` from RestIS Balance is account/phone-level current balance
- do not multiply/sum it once per active card
- `0.00` is known zero; `NULL` means unavailable/unknown
- multiple active cards remain an operator-visible anomaly but add **0 automatic Risk points** until business rules change
- raw loyalty-card numbers never appear in bot UI/API/logs.

---

## 13. Risk v1.3

Advisory-only; no automatic account blocking.

Thresholds:

- critical >=75
- high >=50
- medium >=25.

Important signals include device/multiaccount links, fast switching, duplicate phone/email, visit patterns and high current bonus balance.

High-balance rule:

- current bonus balance strictly `> 40000.00`: +50 and history gate
- exactly `40000.00`: no high-balance trigger.

Multiple active cards alone: +0 automatic risk points.

Case dynamics:

- Новый
- Усилился
- Без изменений
- Ослаб.

---

## 14. Trusted Device foundation

- per-install opaque `device_id`
- 32 cryptographically random bytes -> 64 lowercase hex
- regex `^[a-f0-9]{64}$`
- not UUID/IMEI/MAC/advertising ID/hardware identifier
- survives restart/update/logout
- reinstall creates a new ID
- server can store SHA-256 device hash
- trust TTL: 90 days
- IP is not identity/trust.

Protected export endpoint:

`/api/internal/anti-fraud/trusted-device-export`.

---

## 15. Paused anomaly investigation — USER_ID 737384

**Status: PAUSED by user. Do not resume unless asked.**

Confirmed safe conclusions:

- account had 10 active state-113 cards
- 9 newer cards map to type/prefix corresponding to «Евразия 50%»
- current RestIS and Bitrix card sets matched
- legacy `account.php` mirrored RestIS cards into Bitrix
- Anti-Fraud did not create these cards
- thousands of activation-related requests were observed around 2026-09-02/03
- exact initiating frontend/user/process cause remains unresolved
- never replay activation requests and never persist full card numbers in docs.

---

## 16. Server script execution rules

`docs/SERVER_SCRIPT_RULES.md` is mandatory.

Operator preference:

- one copy-paste block
- wrapper writes `/tmp/...sh` via quoted heredoc
- **first command inside real script: `clear`**
- `set +e`, `set +u`, `set +o pipefail 2>/dev/null`
- syntax check with `bash -n`
- numbered stages
- guards before mutation
- backup before DB changes
- rollback
- explicit `PASS` / `FAIL` / `FINAL_STATUS` / `FINAL_RC`
- no secret values
- terminal remains open.

Do not omit `clear`; the operator explicitly requested that each new server script visually clears previous output.

---

## 17. Product roadmap

- **v1.7** — Anti-Fraud: deployed to production and verified
- **v1.8** — document generation (Jira KAN-66, KAN-77)
- **v1.9** — document sending through Exchange (Jira KAN-67, KAN-78).

Trusted Device remains an Anti-Fraud foundation/data source, not a separate bot version.

---

## 18. Parallel legal Bonus Club work

The technical v1.7 deployment and legal Bonus Club work are separate tracks.

Current legal package produced in the project includes the offer, PD policy, optional additional-processing consent and advertising consent for `evrasia.rest`. Do not mix internal Anti-Fraud algorithms/risk thresholds into public legal documents unless legally required.

If legal work resumes, use its latest dedicated documents/conversation context rather than old draft language from this file.

---

## 19. NEXT STEP — current continuation point

**No production deployment action is pending. Production v1.7 is already verified.**

Next actions, in order:

1. Keep production unchanged and observe normal operation.
2. Keep phase1/phase2 backups and archival TEST assets until explicit cleanup approval.
3. Keep PR #32 Draft unless the user explicitly approves Ready/merge.
4. Update handoff/server documentation to match the verified v1.7 production state.
5. After documentation is current, ask/act only on the user's explicit decision about PR #32:
   - leave Draft for additional visual/business acceptance, or
   - mark Ready and merge if explicitly approved.
6. Do not restart the archival `evrasia-ai-bot-v17-test` container as a live TEST environment.
7. If a new technical feature is requested, start from the verified v1.7 production baseline above rather than repeating deployment/migration work.

---

## 20. Maintenance rule

Update this file after every material milestone affecting:

- branch / PR / merge state
- deployed application revision/image
- production infrastructure/naming
- migration level
- scheduler/runtime
- nginx routing
- backup/rollback assets
- business/risk contracts
- known forensic findings
- `NEXT STEP`.

For any live server fact that has not been re-verified, mark it `REVERIFY` instead of guessing.