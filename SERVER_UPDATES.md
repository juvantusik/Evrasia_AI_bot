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
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:fd58cc95d3c26f541bd15d70fbd057f068630d093c6990f926152c995ca8f479`
- deployed application revision: `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`
- image/config ID: `sha256:78c3d07078995c04948f1fbad600421a665ce03ad35fd388f4d6893f3bc11a47`
- app port: `127.0.0.1:18080 -> 8080`
- status after app-only cleanup deployment: running / healthy.

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

`/directory` is fully removed from the current product contract and production runtime:

- `/directory` -> 404
- `/directory/...` -> 404
- `/api/directory/phones` -> 404.

Operational documentation and diagrams must use **Phonebook** as the product name and must not show `/directory` as an alias or redirect.

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

After the `/directory` cleanup deployment:

- `public.samzaberu_requests` rows: 31
- DB container ID unchanged
- DB start timestamp unchanged
- DB schema unchanged
- production migrations remain 18
- Anti-Fraud tables remain 11.

## Anti-Fraud runtime

Production runtime:

- scheduler enabled
- interval 15 minutes
- run-on-start false
- scheduler idle immediately after the app-only restart
- latest verified protected DB cycle status: success
- Telegram polling enabled
- Telegram 409 conflict count after deployment: 0
- Bitrix and Anti-Fraud service tokens mounted as secret files.

Never print secret values.

Nginx routes active Anti-Fraud traffic to production `127.0.0.1:18080`. TEST port `18081` has zero active nginx references.

The `/directory` cleanup deployment did not modify nginx.

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

App-only `/directory` removal backup:

`/opt/evrasia-ai-bot/backups/app-only-remove-directory-20260907-090654`

Previous production rollback image retained:

`ghcr.io/juvantusik/evrasia_ai_bot@sha256:381e9d34e3ecd65e814cc93b2c0b91656bc9155a5437e86ea93c1a7f34bceffc`

Keep associated globals/config backups until explicit cleanup approval.

## GitHub release state

PR #32 was explicitly approved, marked Ready and merged into `main` on 2026-09-07.

- merged: true
- draft: false
- merge commit: `33e3548ab014e927e1e00074e27f3a11ef252bbc`.

PR #33 removed obsolete `/directory` compatibility routing and was merged into `main` on 2026-09-07.

- merged: true
- draft: false
- merge commit: `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`.

GitHub Actions build #252 for merge commit `0fcebb1e...` completed successfully and published:

- tag: `ghcr.io/juvantusik/evrasia_ai_bot:sha-0fcebb1`
- digest: `sha256:fd58cc95d3c26f541bd15d70fbd057f068630d093c6990f926152c995ca8f479`
- image/config ID: `sha256:78c3d07078995c04948f1fbad600421a665ce03ad35fd388f4d6893f3bc11a47`.

That exact immutable image is now production.

## `/directory` cleanup deployment

Date: 2026-09-07.

Scope: app-only.

Explicit non-changes:

- database schema: no change
- database container: no restart
- nginx: no change
- migrations: no new migration
- TEST: remained stopped.

Final verification:

- `PASS_COUNT=37`
- `FAIL_COUNT=0`
- `ROLLBACK_FAIL_COUNT=0`
- `/phonebook` = 200 direct/routed
- `/directory` = 404 direct/routed
- `/api/directory/phones` = 404
- `/api/phonebook/phones` = 200
- `/antifraud` = 200 direct/routed
- scheduler enabled / idle / 15 minutes
- latest protected cycle = success
- Telegram polling = true
- Telegram 409 conflicts = 0
- SamZaberu module present
- MegaFon module present
- migrations = 18
- Anti-Fraud tables = 11
- SamZaberu request rows = 31
- `DIRECTORY_REMOVAL_PRODUCTION=VERIFIED`
- `FINAL_STATUS=PASS`
- `FINAL_RC=0`.

A first attempt stopped before cutover with `CUTOVER_STARTED=NO` because the staged Compose file was copied to `/tmp`, causing relative `prod-app.env` / `prod-db.env` references to resolve under `/tmp`. Production remained untouched. The corrected attempt staged the temporary Compose file inside `/opt/evrasia-ai-bot/prod` and succeeded.

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

The successful v1.7 release was split into two phases after earlier safe failures:

1. application/migrations/nginx/scheduler cutover;
2. PostgreSQL/Docker infrastructure rename only.

Avoid combining unrelated high-risk changes into one production operation when an intermediate result can be verified independently.

Known resolved pitfalls:

- do not execute a YAML file as Python; pass generated-file args to `python3 - ...`;
- map PostgreSQL booleans explicitly rather than depending on `t/true` display form;
- `/directory` is now removed and expected to return 404; do not retain old redirect assumptions;
- keep old nginx upstream alive through reload/retry to avoid transient 502 race;
- renaming a Compose-managed container does not make it an independent rollback artifact;
- when staging a Compose file with relative `env_file` paths, keep the staged file in the same directory context or stage all referenced files consistently.

## Current release status

Production v1.7 is verified and current cleanup is complete.

Current deployed identity:

- revision `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`
- digest `sha256:fd58cc95d3c26f541bd15d70fbd057f068630d093c6990f926152c995ca8f479`.

There is no pending `/directory` cleanup deployment. `/phonebook` is the only Phonebook route.