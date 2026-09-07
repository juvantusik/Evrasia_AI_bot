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

Canonical UI path: `/phonebook`.

Purpose: corporate phone/operator/legal-entity/account information and related administration/search workflows.

**Important:** `/directory` is not a separate current product.

Compatibility only:

- `/directory` -> HTTP 308 -> `/phonebook`
- `/directory/` -> HTTP 308 -> `/phonebook/`.

Historical implementation names such as `DirectoryPage`, `directory-web-service` or `directory-web.css` may remain in code, but current product naming is **Phonebook**.

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

- deployed application revision: `109ac7a2a05c0289336b3b332cb09ca102253396`
- immutable image digest: `sha256:381e9d34e3ecd65e814cc93b2c0b91656bc9155a5437e86ea93c1a7f34bceffc`
- post-production audit: 34 PASS / 0 WARN / 0 FAIL.

PR #32 containing v1.7 was merged into `main` on 2026-09-07.

Merge commit:

`33e3548ab014e927e1e00074e27f3a11ef252bbc`

The merge does not change the deployed image identity by itself.

## 7. Shared-data notes

`public.samzaberu_requests` is current business data and must not be treated as legacy simply because old infrastructure once used the name `samzaberu`.

Anti-Fraud, SamZaberu, bot access/settings and Phonebook administration all live in the unified current production data/application context.

## 8. Naming mistakes to avoid

Do not say:

- “рабочий `/directory`” as if it were a current module;
- “три отдельных бота/контейнера” for Anti-Fraud, SamZaberu and MegaFon;
- “Evrasia AI Bot = только Anti-Fraud”.

Use instead:

- Phonebook = `/phonebook`;
- Anti-Fraud = `/antifraud` + scheduler;
- SamZaberu = Telegram module inside Evrasia AI Bot;
- Corporate communications/MegaFon = Telegram module/group workflow inside Evrasia AI Bot;
- all of them are part of one current production application unless a later architecture change explicitly separates them.

## 9. Change rule

When this architecture changes, update this file and `docs/AI_PROJECT_CONTEXT.md` immediately and record:

**Было -> Стало -> Причина**.