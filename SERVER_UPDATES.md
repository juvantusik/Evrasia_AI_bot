# Server updates

> Current production operations note for Evrasia AI Bot.
>
> Last updated: **2026-09-08** after PR #41 production acceptance.

## Current production host

- host: `eur-bot-01`
- IP: `192.168.103.200`
- OS: Debian 13 (trixie)
- Docker Engine: 26.1.5
- Docker Compose: 2.26.1-4
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`.

The old Debian 9 / `/home/tech/samzaberu-bot` deployment is not the active production topology.

## Current deployed application

Application:

- service/container: `evrasia-ai-bot-app`
- deployed revision: `a156db2e30dd2a31d7bd4126410f9f513382adaa`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:ce3b85fe789495f3b5ee4e59fe8eb129a75343d1916f2a7c45483da18d988947`
- image/config ID: `sha256:cbe989d6375189f9f12d7a0ad6f74f9f5455536838750fd96f0e2e459d9cc145`
- platform: `linux/amd64`
- app port: `127.0.0.1:18080 -> 8080`
- status: running / healthy.

The application remains one unified production runtime containing:

1. Phonebook web UI (`/phonebook`)
2. Anti-Fraud web UI (`/antifraud`) and scheduler
3. SamZaberu Telegram scenario
4. Corporate communications / MegaFon Telegram scenario.

A later documentation-only GitHub merge may advance `main`; it does not change the deployed application identity above.

## Canonical routes

- `/phonebook` = current Phonebook UI
- `/antifraud` = current Anti-Fraud UI
- `/directory` = 404
- `/directory/...` = 404
- `/api/directory/...` = 404.

Operational documentation must use **Phonebook**, not Directory.

## PostgreSQL

- service/container: `evrasia-ai-bot-db`
- image: `postgres:16-bookworm`
- role/user: `evrasia_ai_bot`
- production DB: `evrasia_ai_bot`
- retained test DB: `evrasia_ai_bot_antifraud_test`
- volume: `evrasia-postgres-prod-data`
- network: `evrasia-prod-internal`
- production migrations: **20**
- latest migration journal timestamp: `1788769200000`.

Migrations 0018/0019 provide Anti-Fraud blocked-state fields and internal block audit.

Active legacy infrastructure names remain absent:

- no `samzaberu-db` production service/container
- no active `samzaberu` PostgreSQL role
- no active `samzaberu` / `samzaberu_antifraud_test` DB names.

`public.samzaberu_requests` and the SamZaberu API/service remain current business functionality and must not be treated as legacy merely because of their names.

## Anti-Fraud runtime

Production runtime:

- scheduler enabled
- interval 15 minutes
- run-on-start false
- scheduler idle after the latest app-only deployment
- no scheduler last error in the deployment post-check
- protected application routes healthy
- Bitrix/API secret files remain mounted
- bot container does not require or contain RestIS credentials.

Do not print secret values.

Nginx continues to route active application traffic to production port 18080. The PR #41 app-only deployment did not require nginx changes.

## Current Anti-Fraud account-state behavior

Bitrix is source of truth:

- `ACTIVE=Y`, `BLOCKED=N` → `Активен`
- `ACTIVE=N`, `BLOCKED=N` → `Неактивен`
- any `BLOCKED=Y` → `Заблокирован`.

Only `BLOCKED=Y` is a true Bitrix block.

Operationally after PR #41:

- blocked and inactive accounts are hidden from ordinary Anti-Fraud lists by default;
- shared toggle: `Показать заблокированных и неактивных`;
- KPI/shared-device/duplicate-contact operational summaries exclude both;
- group bulk block targets active unblocked accounts only;
- group bonus and risk evidence continue to include all case accounts;
- inactive remains a distinct visible status when the hidden population is shown.

Operator visually confirmed the current behavior in production on 2026-09-08.

## Manual block/unblock endpoints

Bitrix protected routes:

- `POST /api/internal/anti-fraud/block`
- `POST /api/internal/anti-fraud/unblock`.

Bot-facing routes:

- `POST /api/anti-fraud/block`
- `POST /api/anti-fraud/unblock`.

Block writes `ACTIVE=N`, `BLOCKED=Y`, uses the fixed approved public reason and re-reads factual state.

Unblock restores `ACTIVE=Y`, `BLOCKED=N` and retains the historical block reason.

Application audit is stored in `anti_fraud_block_audit`.

## Controlled block/unblock production acceptance

Safe test account: USER_ID `880339`.

Completed controlled backend round-trip:

- initial: `ACTIVE=Y`, `BLOCKED=N`
- block: `ACTIVE=N`, `BLOCKED=Y`
- unblock: `ACTIVE=Y`, `BLOCKED=N`
- final state exactly restored
- block reason preserved
- exactly two audit transitions
- risk/history/bonus unchanged
- summary restored
- `PASS=27`, `WARN=0`, `FAIL=0`
- `CONTROLLED_BACKEND_E2E_ACCEPTANCE=PASS`
- `REAL_CUSTOMER_MUTATION=NO`.

Do not repeat this acceptance merely for reassurance.

Because the safe account is risk-0 and has no current case, it was not eligible for a safe live blocked-card case fixture. No real customer was mutated and no production risk data was fabricated to create one. This is an intentional acceptance boundary, not a product failure.

## Similarity performance deployment / acceptance

PR #38 merge:

`971af94e26160914efd2c229a4352d398a65214a`

Former production target from that deployment:

- immutable image `ghcr.io/juvantusik/evrasia_ai_bot@sha256:53c62ce75e90ddc83d3ba7e7e36133ef43cff9d01e9bfbffa8fd181a9e7f7734`
- config `sha256:473032fe887d026c5771da5d8c79012720b0a87a3a5ed57134dba3d6dfd8da1f`.

That revision is now superseded by PR #41 production, but the performance acceptance remains valid.

Measured result:

- old protected cycle: 206 s
- optimized protected cycle: 53 s
- health probes: 24/24 HTTP 200, max 3 ms
- scheduler probes: 24/24 HTTP 200, max 6 ms
- zero probe errors/non-200
- app restart count 0.

Backup from similarity-hotfix deployment:

`/opt/evrasia-ai-bot/backups/anti-fraud-similarity-hotfix-20260908-090617`

Important measurement lesson: `anti_fraud_risk_scoring` persisted timing does not include the subsequent similarity overlay and is not a valid PR #38 performance gate.

## PR #41 deployment — inactive operational treatment / localization

Date: 2026-09-08.

Merge commit / production revision:

`a156db2e30dd2a31d7bd4126410f9f513382adaa`

Main CI:

- run #298
- run ID `34202375971`
- status: success
- tests: 70/70
- exact published digest: `sha256:ce3b85fe789495f3b5ee4e59fe8eb129a75343d1916f2a7c45483da18d988947`
- config ID: `sha256:cbe989d6375189f9f12d7a0ad6f74f9f5455536838750fd96f0e2e459d9cc145`.

Deployment scope:

- app-only recreation
- no new migration
- no DB schema change
- no DB container restart
- no Anti-Fraud refresh triggered
- no Bitrix user-state write
- no nginx change required.

Fresh backup:

`/opt/evrasia-ai-bot/backups/pr41-inactive-ui-20260908-110846`

Final deployment verification:

- expected host/root/Compose baseline passed
- target image pulled using existing Docker auth under user `tech`
- exact target config/revision/platform verified locally
- fresh DB dump created and validated
- staged Compose validated under `/opt/evrasia-ai-bot/prod`
- scheduler idle immediately before cutover
- running app exact digest/config/revision matched CI target
- DB container identity/start time unchanged
- app env/mount/port fingerprints unchanged
- migrations remained 20 / max timestamp `1788769200000`
- `/api/healthz`, `/`, `/phonebook`, `/antifraud`, Anti-Fraud summary/cases/accounts/scheduler all 200
- `/directory` remained 404
- scheduler enabled / idle / 15m / no last error
- `PASS_COUNT=29`
- `FAIL_COUNT=0`
- `ROLLBACK_ATTEMPTED=NO`
- `FINAL_STATUS=PASS`
- `FINAL_RC=0`.

After deployment, operator visual acceptance confirmed:

- inactive status displays as `Неактивен`
- blocked/inactive shared operational hiding works
- `max_devices_for_same_pair=...` is localized as Russian operator text, e.g. `макс. общих устройств для одной пары аккаунтов: 2`
- operator response: `все отрабатывает`.

## GitHub release state

Relevant current merged application PRs:

- PR #36 — merge `947c815d15cc12d0ce571edbc2a2905ef9fed009`
- PR #37 — merge `86c98eacf5959ca2bf8d0f2c95441ff1e4192f8f`
- PR #38 — merge `971af94e26160914efd2c229a4352d398a65214a`
- PR #41 — merge `a156db2e30dd2a31d7bd4126410f9f513382adaa`.

Docs-only PR #39 was merged as `13bf28fe96a857498bc108705ccd6ed3f05915f4` and hardened server-script rules.

Draft PR #40 carried the acceptance-fixture lesson and is superseded once its rule text is incorporated into the consolidated current documentation.

## TEST state

Legacy container `evrasia-ai-bot-v17-test` is stopped/exited and archival only.

Do not restart it as live TEST without deliberately recreating a current environment against the renamed retained test DB/topology.

## Backups to retain

Do not clean without explicit operator approval.

Current/latest:

- `/opt/evrasia-ai-bot/backups/pr41-inactive-ui-20260908-110846`
- `/opt/evrasia-ai-bot/backups/anti-fraud-similarity-hotfix-20260908-090617`.

Earlier v1.7 backups still retained:

- `/opt/evrasia-ai-bot/backups/production-v17-phase1-20260907-053318`
- `/opt/evrasia-ai-bot/backups/production-v17-phase2-dbrename-20260907-054218`
- `/opt/evrasia-ai-bot/backups/app-only-remove-directory-20260907-090654`

Associated DB dumps/globals/config backups remain cleanup-protected until explicit approval.

Bitrix-side Anti-Fraud backups created during status/block/admin endpoint work also remain retained until explicit cleanup approval.

## Deployment policy / operational lessons

Use immutable image digest, not unverified `latest`, for production cutover.

For server scripts follow `docs/SERVER_SCRIPT_RULES.md`.

Current mandatory lessons include:

- use current production/app/code as source of truth before writing guards
- unrelated Telegram/RestIS probes must not become Anti-Fraud deployment blockers
- bot container intentionally has no RestIS credentials
- GHCR auth is available under user `tech`; do not create new root credentials first
- Compose with relative files must be staged under `/opt/evrasia-ai-bot/prod`, not `/tmp`
- a normal scheduler race before cutover should boundedly wait for idle and then revalidate
- HTTP 202 acceptance plus a later timeout is not proof of background-job failure
- persisted Anti-Fraud run state is authoritative when monitoring async work
- verify that a performance metric actually contains the optimized code path before using it as a gate
- prove test-fixture eligibility for the exact UI/API path before mutation
- never mutate a real customer merely to make a visual fixture convenient
- create/verify backup before production DB mutation
- preserve env/mounts/ports/secrets during app-only recreation
- never print secret values
- keep terminal open after operator scripts.

## Current release status

Production v1.7 is stable on revision `a156db2e30dd2a31d7bd4126410f9f513382adaa` and immutable digest `sha256:ce3b85fe789495f3b5ee4e59fe8eb129a75343d1916f2a7c45483da18d988947`.

The Anti-Fraud blocking/inactive/localization/similarity-performance milestone is deployed and accepted. No repeat production refresh or block/unblock acceptance is pending.
