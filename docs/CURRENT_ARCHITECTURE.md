# Evrasia AI Bot — Current Architecture

> Canonical current architecture for module naming, runtime topology and new-chat recovery.
>
> Last updated: **2026-09-08**.

## 1. Main rule

Evrasia AI Bot is a **single production application** (`evrasia-ai-bot-app`) with several business modules sharing one PostgreSQL database.

Do not describe the live system as only Anti-Fraud, and do not draw SamZaberu or MegaFon as separate Docker production bots unless the live topology changes later.

## 2. Current production diagram

```text
                         eur-bot-01
                           PRODUCTION
                               |
                               v
                    evrasia-ai-bot-app
                     Evrasia AI Bot v1.7
                               |
        +----------------------+----------------------+
        |                      |                      |
        v                      v                      v
   WEB: PHONEBOOK         WEB: ANTI-FRAUD       TELEGRAM BOT v2
   /phonebook             /antifraud             one polling consumer
        |                      |                      |
        |                      |             +--------+--------+
        |                      |             |                 |
        |                      |             v                 v
        |                      |        SAMZABERU       CORPORATE COMMS
        |                      |        STOP/ENABLE      / MEGAFON
        |                      |             |                 |
        |                      |             v                 v
        |                      |          Bitrix          Phonebook data
        |                      |                               |
        |                      |                               v
        |                      |                    group «Евразия Мегафон»
        |                      |
        |                      v
        |             Anti-Fraud scheduler
        |                 every 15 min
        |                      |
        |          +-----------+------------+
        |          |           |            |
        |          v           v            v
        |      Trusted       Bitrix     Loyalty/history
        |      Device                    via protected
        |          \           |          site API
        |           +----------+-----------+
        |                      |
        |                      v
        |          Risk / links / cases / audit
        |                      |
        |                      v
        |              manual block/unblock
        |                      |
        |                      v
        |              protected Bitrix API
        |
        +----------------------+----------------------+
                               |
                               v
                    evrasia-ai-bot-db
                       evrasia_ai_bot
```

## 3. Canonical product modules

### Phonebook

Canonical and only current UI path: `/phonebook`.

`/directory` is removed. It is not a product, alias or compatibility route. Requests to `/directory`, `/directory/...` and `/api/directory/...` are expected to return HTTP 404.

Historical implementation identifiers containing `directory` may remain where they mean an internal corporate-directory data structure; they do not create a current `/directory` product.

### Anti-Fraud

Canonical UI path: `/antifraud`.

Includes:

- risk/case/account/device investigation UI;
- protected data integrations;
- protected scheduler every 15 minutes;
- targeted loyalty/history enrichment;
- advisory explainable risk scoring;
- identity-link/case grouping;
- group bonus aggregation;
- manual per-account and group blocking;
- manual per-account unblock;
- block/unblock audit;
- Bitrix factual status synchronization.

Risk is **advisory**. There is **no automatic account blocking** from risk score.

#### Bitrix account-state contract

Bitrix is the source of truth:

- `ACTIVE=Y`, `BLOCKED=N` → **Активен**
- `ACTIVE=N`, `BLOCKED=N` → **Неактивен**
- any `BLOCKED=Y` → **Заблокирован**

Only `BLOCKED=Y` is a true Bitrix block.

Operationally after PR #41:

- blocked and inactive accounts are hidden from ordinary operational lists by default;
- the shared UI toggle is `Показать заблокированных и неактивных`;
- inactive remains a distinct visual status, not a synthetic block;
- KPI/shared-device/duplicate-contact summaries exclude both blocked and inactive accounts;
- case/group evidence and group bonus may still include excluded accounts;
- group bulk block targets active unblocked accounts only;
- an inactive account exposed through the toggle may still be individually/formally blocked if an operator explicitly chooses it.

#### Manual block/unblock architecture

Bot-facing operator routes call protected Bitrix-side routes:

```text
Anti-Fraud UI
   |
   +--> POST /api/anti-fraud/block
   |         |
   |         v
   |    protected Bitrix block endpoint
   |         |
   |         v
   |    ACTIVE=N, BLOCKED=Y
   |
   +--> POST /api/anti-fraud/unblock
             |
             v
        protected Bitrix unblock endpoint
             |
             v
        ACTIVE=Y, BLOCKED=N
```

The application re-reads factual state and persists local audit. Customer-facing block reason is generic and must not expose case IDs, risk scores, devices or identity-detection internals.

#### Loyalty/history credential boundary

The bot container does **not** hold RestIS credentials. Loyalty/history access goes through the protected site-side integration. This boundary is intentional and must not be “fixed” by copying RestIS credentials into `evrasia-ai-bot-app`.

### SamZaberu Telegram

SamZaberu is an internal scenario of `EvrasiaTelegramBotV2`, not a separate Docker bot.

User-facing Telegram entry: `🍱 СамЗаберу`.

Backend behavior includes operational-manager access, STOP/ENABLE actions, Bitrix service calls, PostgreSQL request/rule journal, retry, factual verification and escalation/manual completion.

Business API remains under `/api/samzaberu/...`.

### Corporate communications / MegaFon Telegram

Corporate communications is another scenario inside `EvrasiaTelegramBotV2`.

User-facing Telegram entry: `📱 Корпоративная связь`.

MegaFon private-chat/group handling uses Phonebook data and the bound “Евразия Мегафон” group. T2 remains part of Corporate communications but uses its own prepared-contact/request flow rather than the MegaFon group path.

## 4. Process topology

Production application startup owns:

- DB migration runner;
- Phonebook cache/init logic;
- HTTP server;
- `EvrasiaTelegramBotV2` when polling is enabled;
- Anti-Fraud scheduler when enabled.

Therefore:

- one application process owns web + Telegram + Anti-Fraud scheduler;
- one Telegram polling consumer handles multiple Telegram scenarios;
- PostgreSQL is shared by product modules;
- an app-only recreation can affect all modules at process level even when the code change is Anti-Fraud-only, so regression probes must be chosen carefully without turning unrelated subsystems into false blocking gates.

## 5. Production infrastructure

Host:

- `eur-bot-01`
- `192.168.103.200`
- Debian 13

Docker:

- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- app: `evrasia-ai-bot-app`
- DB: `evrasia-ai-bot-db`
- network: `evrasia-prod-internal`
- volume: `evrasia-postgres-prod-data`

PostgreSQL:

- role: `evrasia_ai_bot`
- production DB: `evrasia_ai_bot`
- retained test DB: `evrasia_ai_bot_antifraud_test`
- production migrations: **20**
- latest migration journal timestamp: `1788769200000`
- migrations 0018/0019 provide blocked-state fields and block audit.

Legacy TEST container `evrasia-ai-bot-v17-test` is exited/archival. Do not restart it blindly.

## 6. Current production release identity

Current deployed application after PR #41 rollout on 2026-09-08:

- deployed revision: `a156db2e30dd2a31d7bd4126410f9f513382adaa`
- immutable digest: `sha256:ce3b85fe789495f3b5ee4e59fe8eb129a75343d1916f2a7c45483da18d988947`
- image/config ID: `sha256:cbe989d6375189f9f12d7a0ad6f74f9f5455536838750fd96f0e2e459d9cc145`
- platform: `linux/amd64`
- app status after deployment: running / healthy.

Production deployment verification:

- app-only recreation
- DB container unchanged / not restarted
- schema and migration state unchanged at 20
- environment, mounts and ports preserved
- scheduler enabled, idle, 15 minutes, no last error
- `/api/healthz`, `/`, `/phonebook`, `/antifraud`, Anti-Fraud APIs = 200
- `/directory` = 404
- backup: `/opt/evrasia-ai-bot/backups/pr41-inactive-ui-20260908-110846`
- `PASS_COUNT=29`, `FAIL_COUNT=0`, `ROLLBACK_ATTEMPTED=NO`, `FINAL_STATUS=PASS`.

Main CI #298 / run ID `34202375971` built the exact image and passed 70/70 tests.

Later documentation-only commits may advance GitHub `main`; they do not change the deployed application identity above.

## 7. Anti-Fraud performance architecture

PR #38 replaced the previous exhaustive all-pairs identity-similarity scan with indexed candidate generation.

Important invariant:

- the candidate index may produce extra candidates;
- the existing final identity evaluator remains source of truth;
- risk weights, corroboration rules and grouping semantics are not changed by the optimization.

Measured protected cycle improved from 206 s to 53 s while HTTP health/scheduler probes remained responsive and the app did not restart.

The persisted `anti_fraud_risk_scoring` timing does not include the subsequent similarity overlay and must not be used as the performance gate for this optimization.

## 8. Current routes

- `/phonebook` = 200
- `/antifraud` = 200
- `/api/anti-fraud/...` = current Anti-Fraud API
- `/directory` = 404
- `/api/directory/...` = 404

Nginx routes production to port 18080. TEST port 18081 has no active role in the current production path.

## 9. Change history

### 2026-09-07 — remove `/directory`

**Было:** `/directory` existed as old compatibility routing.

**Стало:** `/directory` and `/api/directory/...` are removed; `/phonebook` is the only Phonebook route.

**Причина:** eliminate product ambiguity and obsolete compatibility behavior.

### 2026-09-08 — Anti-Fraud manual block/unblock

**Было:** Anti-Fraud displayed risk but did not provide the full operator block/unblock workflow.

**Стало:** Bitrix factual blocked status/reason are synchronized; operator can block individually or by group and unblock individually; audit and group bonus are present; blocked accounts are hidden by default.

**Причина:** convert Anti-Fraud from analysis-only UI into a controlled manual operator workflow while preserving Bitrix as source of truth and keeping risk advisory.

### 2026-09-08 — similarity performance hotfix

**Было:** synchronous exhaustive all-pairs similarity scanning caused long protected cycles and event-loop starvation.

**Стало:** indexed candidate generation narrows pairs before the unchanged final evaluator; production measurement 206 s → 53 s with responsive HTTP probes.

**Причина:** remove O(n²)-style fleet scanning from the hot path without changing detection semantics.

### 2026-09-08 — inactive operational treatment

**Было:** `ACTIVE=N`, `BLOCKED=N` was visually `Неактивен` but still participated in ordinary operational lists/summaries, unlike blocked accounts.

**Стало:** inactive remains a distinct Bitrix/UI status but is operationally hidden/excluded together with blocked accounts by default. Shared toggle exposes both. Technical reason details are localized for the operator UI.

**Причина:** an externally deactivated account should not pollute active operational Anti-Fraud workload/KPI while still remaining distinguishable from an Anti-Fraud/Bitrix block.

## 10. Change rule

When architecture or production identity changes, update this file together with `docs/AI_PROJECT_CONTEXT.md`, `docs/PROJECT_CHECKPOINT.md`, `docs/NEW_CHAT_HANDOFF.md` and `SERVER_UPDATES.md`.

Record changed decisions as **Было → Стало → Причина** and never let a later docs-only GitHub revision be mistaken for the deployed application revision.
