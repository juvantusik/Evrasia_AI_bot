# Server updates

> Current production operations note for Evrasia AI Bot.
>
> Last updated: 2026-09-07.

## Current production host

- host: `eur-bot-01`
- IP: `192.168.103.200`
- OS: Debian 13 (trixie)
- Docker Engine: 26.1.5
- Docker Compose: 2.26.1-4
- Compose project: `evrasia-prod`.

The old Debian 9 / `/home/tech/samzaberu-bot` deployment is not the active production topology.

## Current production v1.7 application

Application:

- service/container: `evrasia-ai-bot-app`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:381e9d34e3ecd65e814cc93b2c0b91656bc9155a5437e86ea93c1a7f34bceffc`
- deployed application revision: `109ac7a2a05c0289336b3b332cb09ca102253396`
- app port: `127.0.0.1:18080 -> 8080`
- status at post-production audit: running / healthy.

The application is a unified production runtime containing:

1. Phonebook web UI (`/phonebook`)
2. Anti-Fraud web UI (`/antifraud`) and scheduler
3. SamZaberu Telegram scenario
4. Corporate communications / MegaFon Telegram scenario.

There are not separate Docker production containers for the SamZaberu and MegaFon Telegram scenarios; they run inside the same `evrasia-ai-bot-app` through `EvrasiaTelegramBotV2`.

## Canonical web routes

Current canonical product routes:

- Phonebook: `/phonebook`
- Anti-Fraud: `/antifraud`.

`/directory` is not a separate current module. It exists only for compatibility and redirects:

- `/directory` -> 308 -> `/phonebook`
- `/directory/` -> 308 -> `/phonebook/`.

Operational documentation and diagrams should use **Phonebook** as the product name.

## Telegram runtime

Production has `TELEGRAM_BOT_POLLING=true` and starts one `EvrasiaTelegramBotV2` polling consumer from the application process.

### SamZaberu

The Telegram module exposes `🍱 СамЗаберу` to allowed operators.

Backend/service behavior includes:

- STOP / ENABLE actions
- Bitrix service-layer calls `applyStop` / `applyEnable`
- PostgreSQL request/rule persistence
- up to 3 attempts
- factual/persisted-state verification
- escalation and manual completion on failure.

Business API remains under `/api/samzaberu/...`.

### Corporate communications / MegaFon

The Telegram module exposes `📱 Корпоративная связь`.

For MegaFon:

- user can initiate an issue from private chat;
- Phonebook resolves number/operator/legal entity/account data;
- bot checks membership of the bound “Евразия Мегафон” group;
- bot posts a structured issue into that group;
- bot also processes ordinary messages inside the bound group, asks clarifying questions and validates phone-to-legal-entity consistency;
- Super Admin binds the group using `/bind_megafon_group`.

T2 remains part of Corporate communications but uses its own prepared-contact flow rather than the MegaFon group path.

## PostgreSQL

- service/container: `evrasia-ai-bot-db`
- image: `postgres:16-bookworm`
- role/user: `evrasia_ai_bot`
- production DB: `evrasia_ai_bot`
- retained test DB: `evrasia_ai_bot_antifraud_test`
- volume: `evrasia-postgres-prod-data`
- network: `evrasia-prod-internal`
- production migrations: 18
- test DB migrations: 18
- Anti-Fraud tables: 11.

Active legacy PostgreSQL infrastructure names are removed:

- no `samzaberu-db` service/container
- no `samzaberu` PostgreSQL role
- no `samzaberu` or `samzaberu_antifraud_test` DB names.

Important: `public.samzaberu_requests` and the SamZaberu service/API are intentional current business functionality, not legacy infrastructure.

At audit, `public.samzaberu_requests` had 31 rows and continuity passed.

## Anti-Fraud runtime

Production runtime:

- scheduler enabled
- interval 15 minutes
- run-on-start false
- latest verified protected cycle status: success
- Telegram polling enabled
- no Telegram 409 conflict at audit
- Bitrix and Anti-Fraud service tokens mounted as secret files.

Never print secret values.

Nginx routes active Anti-Fraud traffic to production `127.0.0.1:18080`. TEST port `18081` has zero active nginx references.

## TEST state

Legacy container `evrasia-ai-bot-v17-test` is stopped/exited and archival only.

Do not restart it as a live TEST without rebuilding its environment for the renamed test DB/topology.

## Backups to retain

Do not clean without explicit approval.

Phase 1:

`/opt/evrasia-ai-bot/backups/production-v17-phase1-20260907-053318`

Production dump SHA256:

`8cf697c2faa5010d12cb9389aac1ecd38929d1672ff1bdd432bad5df5c45e14a`

Phase 2:

`/opt/evrasia-ai-bot/backups/production-v17-phase2-dbrename-20260907-054218`

- production dump SHA256: `6dfd1b8f0d3d30857ac3c7a06d29f05e38ccdccb4b86db5a0862780b14bb56f1`
- test dump SHA256: `bd862bc8445c632face19b4d96e23edc49d1c2385c5f05481be5d40a3a14327c`.

Keep associated globals/config backups until explicit cleanup approval.

## GitHub release state

PR #32 was explicitly approved, marked Ready and merged into `main` on 2026-09-07.

- merged: true
- draft: false
- merge commit: `33e3548ab014e927e1e00074e27f3a11ef252bbc`.

Production remains pinned to application revision `109ac7a2...`; the merge itself did not redeploy production.

## Deployment policy

Do not deploy using `latest` as an unverified moving target. Prefer an immutable GHCR digest after CI/publication and TEST/acceptance verification.

Before a future production deployment:

1. verify current production container/image/health;
2. verify current `main`/branch/PR and application code;
3. verify immutable target digest/revision;
4. create fresh backups before DB mutation;
5. use explicit rollback;
6. preserve secrets through file mounts;
7. verify migrations/data/HTTP/Telegram/scheduler after change.

For server scripts follow `docs/SERVER_SCRIPT_RULES.md`; the real script begins with `clear`.

## Lessons from v1.7 deployment

The successful release was split into two phases after earlier safe failures:

1. application/migrations/nginx/scheduler cutover;
2. PostgreSQL/Docker infrastructure rename only.

Avoid combining unrelated high-risk changes into one production operation when an intermediate result can be verified independently.

Known resolved pitfalls:

- do not execute a YAML file as Python; pass generated-file args to `python3 - ...`;
- map PostgreSQL booleans explicitly rather than depending on `t/true` display form;
- `/directory` 308 is expected compatibility redirect, not a current product endpoint;
- keep old nginx upstream alive through reload/retry to avoid transient 502 race;
- renaming a Compose-managed container does not make it an independent rollback artifact.

## Current release status

Post-production audit on 2026-09-07:

- 34 PASS
- 0 WARN
- 0 FAIL
- production v1.7 verified.

No further production mutation is required for this release.