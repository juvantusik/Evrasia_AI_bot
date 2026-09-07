# Evrasia AI Bot — Current Architecture

> Canonical current architecture. Use this file for diagrams, module naming and new-chat recovery.
>
> Last updated: 2026-09-07.

## 1. Main rule

Evrasia AI Bot is currently a **single production application** (`evrasia-ai-bot-app`) with several business modules.

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
        |          +-----------+-----------+
        |          |           |           |
        |          v           v           v
        |      Trusted       Bitrix      Loyalty/history
        |      Device                     (targeted)
        |          \           |           /
        |           +----------+----------+
        |                      |
        |                      v
        |                Risk / Cases
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

Purpose: corporate phone/operator/legal-entity/account information and related administration/search workflows.

`/directory` is **removed**. It is not a product, alias or compatibility route. Requests to `/directory` and `/directory/...` return HTTP 404 in production.

Historical implementation identifiers containing the word `directory` may still exist internally where they mean a corporate directory/data structure; they do not create a `/directory` web product or route. Renaming stable DB schema identifiers is not required merely for UI naming cleanup.

### Anti-Fraud

Canonical UI path: `/antifraud`.

Includes:

- risk/case/account/device investigation UI;
- protected integration endpoints;
- scheduler every 15 minutes;
- targeted loyalty/history refresh;
- advisory risk scoring and case dynamics.

No automatic account blocking.

### SamZaberu Telegram

SamZaberu is an internal scenario of `EvrasiaTelegramBotV2`, not a separate Docker bot.

User-facing Telegram entry: `🍱 СамЗаберу`.

Backend behavior:

- allowed operational-manager access;
- STOP / ENABLE actions;
- Bitrix `applyStop` / `applyEnable` service-layer calls;
- PostgreSQL request/rule journal;
- retries and factual-state verification;
- escalation/manual completion on failure.

Business API remains under `/api/samzaberu/...`.

### Corporate communications / MegaFon Telegram

Corporate communications is another scenario inside `EvrasiaTelegramBotV2`.

User-facing Telegram entry: `📱 Корпоративная связь`.

MegaFon private-chat flow:

1. user enters a corporate number;
2. Phonebook resolves operator/ООО/account data;
3. user describes the problem;
4. bot verifies membership of the bound MegaFon group;
5. bot publishes the structured request into the group.

MegaFon group flow:

1. Super Admin binds the group using `/bind_megafon_group`;
2. bot listens only to that bound group;
3. it parses phone/legal-entity/problem data;
4. asks clarification when data is missing;
5. rejects ambiguous or mismatched phone-to-ООО combinations;
6. produces the structured request addressed to the MegaFon manager.

T2 is handled inside Corporate communications but uses its own prepared-contact/request flow rather than the MegaFon group path.

## 4. Process topology

Production application startup:

- runs DB migrations;
- initializes/refreshes Phonebook cache;
- starts HTTP server;
- starts `EvrasiaTelegramBotV2` when Telegram polling is enabled;
- starts Anti-Fraud scheduler when scheduler is enabled.

Therefore:

- one application process owns web + Telegram + Anti-Fraud scheduler;
- one Telegram polling consumer handles multiple Telegram scenarios;
- PostgreSQL is shared by the product modules.

## 5. Production infrastructure

Host:

- `eur-bot-01`
- `192.168.103.200`
- Debian 13.

Docker:

- Compose project: `evrasia-prod`
- app: `evrasia-ai-bot-app`
- DB: `evrasia-ai-bot-db`
- network: `evrasia-prod-internal`
- volume: `evrasia-postgres-prod-data`.

PostgreSQL:

- role: `evrasia_ai_bot`
- production DB: `evrasia_ai_bot`
- retained test DB: `evrasia_ai_bot_antifraud_test`
- production migrations: 18.

## 6. Current production release identity

Current deployed application after the `/directory` cleanup deployment on 2026-09-07:

- deployed application revision: `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`
- immutable image digest: `sha256:fd58cc95d3c26f541bd15d70fbd057f068630d093c6990f926152c995ca8f479`
- image/config ID: `sha256:78c3d07078995c04948f1fbad600421a665ce03ad35fd388f4d6893f3bc11a47`
- app container: `evrasia-ai-bot-app`
- app status after deployment: running / healthy.

App-only deployment verification:

- `PASS_COUNT=37`
- `FAIL_COUNT=0`
- `ROLLBACK_FAIL_COUNT=0`
- `DIRECTORY_REMOVAL_PRODUCTION=VERIFIED`
- `FINAL_STATUS=PASS`
- `FINAL_RC=0`.

Route checks:

- `/phonebook` = 200 direct and routed
- `/directory` = 404 direct and routed
- `/api/directory/phones` = 404
- `/api/phonebook/phones` = 200
- `/antifraud` = 200 direct and routed.

Runtime continuity:

- Anti-Fraud scheduler enabled, 15-minute interval
- latest protected cycle = `success`
- Telegram polling = true
- Telegram 409 conflicts = 0
- SamZaberu and MegaFon modules remain in the same production application.

Database/nginx continuity:

- production migrations = 18
- Anti-Fraud tables = 11
- `public.samzaberu_requests` rows = 31
- DB container was not restarted
- DB schema was not changed
- nginx was not changed.

PR #32 containing v1.7 was merged into `main` on 2026-09-07, merge commit `33e3548ab014e927e1e00074e27f3a11ef252bbc`.

PR #33 removed obsolete `/directory` compatibility routing and was merged into `main` on 2026-09-07, merge commit `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`.

Later documentation-only commits may advance `main`; they do not change the deployed application identity above.

## 7. Shared-data notes

`public.samzaberu_requests` is current business data and must not be treated as legacy simply because old infrastructure once used the name `samzaberu`.

Anti-Fraud, SamZaberu, bot access/settings and Phonebook administration all live in the unified current production data/application context.

## 8. Naming mistakes to avoid

Do not say:

- “рабочий `/directory`”;
- “`/directory` — alias/redirect Phonebook”;
- “три отдельных бота/контейнера” for Anti-Fraud, SamZaberu and MegaFon;
- “Evrasia AI Bot = только Anti-Fraud”.

Use instead:

- Phonebook = `/phonebook` only;
- `/directory` = absent / HTTP 404;
- Anti-Fraud = `/antifraud` + scheduler;
- SamZaberu = Telegram module inside Evrasia AI Bot;
- Corporate communications/MegaFon = Telegram module/group workflow inside Evrasia AI Bot;
- all of them are part of one current production application unless a later architecture change explicitly separates them.

## 9. Change history

### 2026-09-07 — remove `/directory`

**Было:** `/directory` and `/directory/` returned HTTP 308 redirects to `/phonebook`.

**Стало:** `/directory` and `/directory/...` are removed from the product contract and return HTTP 404 in production; `/phonebook` is the only Phonebook UI route.

**Причина:** no business or operational need exists for the old alias, and keeping it creates recurring ambiguity about whether Directory is a separate product/module.

Deployment implementation:

- PR #33 merged into `main`;
- CI build #252 passed;
- immutable image `sha256:fd58cc95...` deployed app-only;
- no DB migration, DB restart or nginx change;
- verification 37 PASS / 0 FAIL.

A first deployment attempt stopped **before cutover** because a staged Compose file placed under `/tmp` resolved relative `env_file` paths against `/tmp`. No production change occurred. The corrected deployment staged the Compose file inside `/opt/evrasia-ai-bot/prod`, preserving relative env-file resolution.

## 10. Change rule

When this architecture changes, update this file and `docs/AI_PROJECT_CONTEXT.md` immediately and record:

**Было -> Стало -> Причина**.
