# Evrasia AI Bot — AI Project Context

> Operational handoff / source of truth for continuing work with ChatGPT across chats.
>
> **Last updated:** 2026-09-05
> **Current workstream:** v1.7 Anti-Fraud
> **Repository:** `juvantusik/Evrasia_AI_bot`
> **Working branch:** `feature/v1.7-antifraud-web`
> **PR:** #32 — `Evrasia AI Bot v1.7 — Anti-Fraud investigation web UI`
> **PR policy:** keep Draft until visual approval. Do not merge or mark Ready without explicit approval.

---

## 1. Why this file exists

This document is the continuity source for long-running Evrasia AI Bot work. In a new ChatGPT conversation, read this file first, then verify the current GitHub branch/PR state before continuing.

Do not rely on old chat memory for exact deployment state, image tags, migrations, rollback containers, DB status, or the next action when this file and live GitHub/server state can be checked.

### Recommended new-chat prompt

> Продолжаем проект Evrasia AI Bot. Репозиторий `juvantusik/Evrasia_AI_bot`. Сначала прочитай `docs/AI_PROJECT_CONTEXT.md` в актуальной рабочей ветке, проверь состояние PR #32 и текущий HEAD, восстанови контекст проекта и продолжай строго с раздела `NEXT STEP`. Не повторяй уже выполненные действия и не меняй production без моего явного разрешения. Для серверных действий давай один полный диагностический bash-скрипт с guard/backup/rollback и без вывода секретов.

---

## 2. Product/version roadmap

- **v1.6.9** — current production phonebook/directory baseline.
- **v1.7** — current Anti-Fraud workstream.
- **v1.8** — document generation (Jira KAN-66, KAN-77).
- **v1.9** — document sending through Exchange (Jira KAN-67, KAN-78).
- Trusted Device is a foundation/data source for Anti-Fraud, not a separate bot version.

---

## 3. Hard safety rules

1. **Production must remain untouched during v1.7 test work unless explicitly approved.**
2. Production container/image baseline:
   - container: `evrasia-ai-bot-app`
   - image: `ghcr.io/juvantusik/evrasia_ai_bot:sha-8fd916f`
   - production DB: `samzaberu`
   - production Anti-Fraud table count expected: `0`
3. Test work uses a separate container and DB:
   - container: `evrasia-ai-bot-v17-test`
   - DB: `samzaberu_antifraud_test`
   - host port: `127.0.0.1:18081 -> 8080`
   - nginx preview: `http://192.168.103.200:8081/antifraud`
4. Keep PR #32 Draft until visual approval.
5. Do not copy RestIS credentials into the bot.
6. Do not expose raw loyalty card numbers to the bot/UI/logs.
7. Do not print service tokens, passwords, card numbers, full diagnostic USER_ID lists, or secret values.
8. Keep rollback containers and DB backups until explicit cleanup approval.
9. Scheduler stays disabled until the direct legacy `VIP_TODAY` dependency is replaced by a protected site-side source.
10. Full phone/email are intentionally visible to the Anti-Fraud operator for manual blocking workflows; do not re-mask without a business requirement change.
11. Never claim server state changed without pasted server output or direct verification.

---

## 4. Server / infrastructure

### Host

- hostname: `eur-bot-01`
- IP: `192.168.103.200`
- OS: Debian 13 (trixie)
- Docker Engine: 26.1.5
- Docker Compose: 2.26.1-4
- production directory: `/opt/evrasia-ai-bot/prod`
- Docker network: `evrasia-prod-internal`
- PostgreSQL container: `samzaberu-db`

### Production — last verified 2026-09-05

- container: `evrasia-ai-bot-app`
- image: `ghcr.io/juvantusik/evrasia_ai_bot:sha-8fd916f`
- status: running
- health: healthy
- port: `127.0.0.1:18080 -> 8080`
- DB: `samzaberu`
- production Anti-Fraud tables: `0`
- **production changed during the latest v1.7 work: NO**

### Test v1.7 — current deployed web state

- container: `evrasia-ai-bot-v17-test`
- image: `ghcr.io/juvantusik/evrasia_ai_bot:sha-55ef0a5`
- revision represented by deployed test image: `55ef0a522bbbcfe552480d3f222bacab46009e95`
- test DB: `samzaberu_antifraud_test`
- port: `127.0.0.1:18081 -> 8080`
- network: `evrasia-prod-internal`
- Telegram polling: disabled
- `ANTI_FRAUD_SCHEDULER_ENABLED=false`
- `ANTI_FRAUD_SCHEDULER_RUN_ON_START=false`
- `ANTI_FRAUD_AUTO_HISTORY=false`
- `ANTI_FRAUD_REFRESH_ACCOUNTS=false`
- RestIS credentials present in bot: **NO**

### Current test DB state after latest rollback

The latest fleet-wide backfill attempt was fully rolled back after a temporary protected API HTTP 500. Current test DB is therefore still the pre-backfill baseline:

- migrations: **15** (through `0014`)
- total rows in `anti_fraud_accounts`: **1785**
- `bitrix_active=true`: **1784**
- `bitrix_active=false`: **1**
- active accounts with `loyalty_synced_at IS NOT NULL`: **1**
- old constraint `anti_fraud_accounts_bonus_balance_chk`: **present (1)**
- `bonus_balance` type: `numeric(14,2)`
- account `last_synced_at` window observed: `2026-09-04 14:39:14+00` to `2026-09-04 14:39:14+00`

Important terminology:
- **1784 active accounts means active Bitrix accounts (`bitrix_active=true`)**.
- It does **not** mean 1784 users have an active loyalty card.
- Active loyalty card is a separate RestIS condition: `RESTIS_STATE=113`.

### Secret mounts — paths only

- Bitrix API token host path: `/opt/evrasia-ai-bot/secrets/bitrix-api-token`
- Anti-Fraud service token host path: `/opt/evrasia-ai-bot/secrets/anti-fraud-service-token`
- Anti-Fraud token inside container: `/run/secrets/anti-fraud-service-token`
- env variable used by the loyalty gateway: `BITRIX_ANTI_FRAUD_TOKEN_FILE`

Do not print token values.

### nginx preview routing

- production port 80 remains production.
- preview listener: `192.168.103.200:8081`
- `/antifraud` and relevant Anti-Fraud API routes go to test.
- only Anti-Fraud assets should go to test.
- do not proxy all `/assets` or all `/api` to test.

---

## 5. GitHub / CI state

### Pull request

- PR #32
- title: `Evrasia AI Bot v1.7 — Anti-Fraud investigation web UI`
- branch: `feature/v1.7-antifraud-web`
- base: `main`
- state: open
- merged: false
- Draft: true
- keep Draft until explicit visual approval.

### Application-code revision before this documentation-only update

- application-code HEAD: `d76ca1e02042e7830e22cbb2584a17bbca00025d`
- this documentation update creates a newer branch commit; do not confuse the docs-only branch HEAD with the immutable application image revision below.

### Current published one-shot test/migration/backfill image

Manual workflow run **#218** successfully published:

- GHCR tag: `ghcr.io/juvantusik/evrasia_ai_bot:sha-d76ca1e`
- OCI revision: `d76ca1e02042e7830e22cbb2584a17bbca00025d`
- digest: `sha256:9edb4be6074a50dd9a6c65b8c7a313593dd94db3d12314b44c0b3f641389187d`
- image/config ID: `sha256:d4e7a67135fb7aa403c794bc961f77b8d53ca34ccd8414a0f443f2592a7ea56c`
- 56/56 tests passed
- API/web smoke passed
- DB smoke proved migrations reach 16 and `bonus_balance=-1.25` can be stored after migration 0015.

### Current deployed test web image is intentionally older

The test web container is still on:

- `ghcr.io/juvantusik/evrasia_ai_bot:sha-55ef0a5`

The new `sha-d76ca1e` image is currently used only as an **ephemeral one-shot migrator / balance CLI / risk CLI**. Do not switch the test web image merely to run the backfill.

### GHCR access on server

- root Docker config does not have GHCR auth.
- user `tech` has GHCR auth.
- pull private images as root through existing tech context:
  `runuser -u tech -- env HOME=/home/tech docker pull ...`
- do not copy the GitHub token into root config.

---

## 6. Operator/server execution preference

For server changes, provide **one complete bash block** copied and run as a whole.

Required style:

- `clear`
- `set +e`
- `set +u`
- `set +o pipefail 2>/dev/null`
- variables at top
- numbered `=== N. ... ===` sections
- production guard before risky operations
- test guard
- backup before DB mutation
- rollback path
- production guard again after work/failure
- explicit RC variables
- explicit `PASS` / `FAIL`
- no secret values
- `/tmp` for temporary files and cleanup
- do not use an outer-shell `exit` because it previously closed the SSH terminal
- use shell functions + `return`
- finish with `TERMINAL_WILL_STAY_OPEN=YES`
- user pastes full output; analyze section-by-section before next change.

---

## 7. Anti-Fraud business rules

### Risk

- displayed risk: 0–100
- current calculation version: `v1.3`
- advisory-only; no automatic blocking.

### Case dynamics statuses

- Новый
- Усилился
- Без изменений
- Ослаб

### Multiaccount/device baseline

Known example for two accounts sharing a device:
- `shared_device_accounts` +40
- `linked_accounts` +10
- total 50 -> high -> history gate true.

### Bonus threshold

- exactly `40000.00` — no high-balance signal
- `40000.01+` — +50
- triggers history gate
- no auto-block
- storage: exact `numeric(14,2)`
- **negative current bonus balances are valid real data and must be stored**.

### Card state rule

Only the active loyalty card is used for current loyalty state/history resolution.

RestIS states:
- `113` — Активна
- `114` — Недействительна
- `115` — Украдена
- `116` — Изъять

Current state/history:
- use only `113`
- ignore 114/115/116 for current state
- old cards may remain for audit/history only.

`20` in loyalty data is discount percent, not bonus balance.

---

## 8. Protected site-side Anti-Fraud API

Site: `evrasia.spb.ru`

Protected internal routes include:
- `POST /api/internal/anti-fraud/card-map`
- `POST /api/internal/anti-fraud/account-map`
- `POST /api/internal/anti-fraud/trusted-device-export`
- `POST /api/internal/anti-fraud/loyalty`

Target identity graph:

`BITRIX USER_ID -> identity + Trusted Device hash + active RESTIS_STATE=113 loyalty + current balance/history`

The bot must not need raw card numbers or RestIS credentials for protected loyalty.

### Loyalty gateway constraints

- HTTPS required
- service token from `BITRIX_ANTI_FRAUD_TOKEN` or `BITRIX_ANTI_FRAUD_TOKEN_FILE`
- current-state max: **50 USER_ID per protected request**
- history max: 10 USER_ID per request
- history range max: 60 days
- raw `card_number` is rejected if present
- current balance parser accepts signed decimal strings
- `total_spend`, `today_sum`, history amounts/bonus-added/bonus-spent remain non-negative contracts.

---

## 9. Trusted Device foundation

- opaque permanent `device_id` per app install
- 32 cryptographically random bytes -> 64 lowercase hex chars
- regex `^[a-f0-9]{64}$`
- not UUID/IMEI/MAC/advertising ID/hardware ID
- one ID per installation regardless of account
- survives restart/update/logout
- uninstall/reinstall creates a new ID
- server stores SHA-256 device hash
- trust TTL 90 days
- plaintext trust token only transiently returned; hash stored server-side
- optional device metadata must fail-open and not break primary login
- IP is not identity/trust.

Protected export endpoint:
- `/api/internal/anti-fraud/trusted-device-export`

Do not build a competing device identity mechanism in the bot.

---

## 10. Database migrations relevant to v1.7

### 0013 — `anti_fraud_loyalty_decimal`

- `anti_fraud_accounts.bonus_balance` -> `numeric(14,2)`
- adds loyalty sync/history coverage timestamps
- `anti_fraud_visits.card_id` becomes nullable
- visit monetary fields become exact numeric values
- adds `loyalty_verified`.

Important historical issue: 0013 recreated a constraint requiring `bonus_balance >= 0`, which later proved invalid for real current balances.

### 0014 — `anti_fraud_restis_event_identity`

Raw RestIS `restis_id` is not unique per business operation.

Model:
- raw RestIS ID -> `source_restis_id`
- it is intentionally nonunique
- internal stable event ID hashes full business identity: source ID + timestamp + restaurant + amount + bonus added + bonus spent
- exact duplicate event is still rejected/deduplicated.

### 0015 — `anti_fraud_signed_bonus_balance`

Created after real data showed negative current balances.

Migration behavior:
- drops only `anti_fraud_accounts_bonus_balance_chk`
- keeps `bonus_balance NUMERIC(14,2)`
- allows positive, zero, and negative current loyalty balances
- does not loosen non-negative constraints/contracts for visit amounts or historical bonus added/spent.

Journal entry:
- index 15
- timestamp/created_at used by migration runner: `1788601200000`

**Current test DB is still at 15 migrations after rollback.** Migration 0015 has been validated in CI and during a temporary test run, but must be applied again as part of the next successful full backfill run.

---

## 11. RestIS event-identity discovery

Nelli 60-day live diagnostic proved:
- history rows: 83
- unique source RestIS IDs: 72
- duplicate source-ID groups: 11
- exact duplicate groups: 0
- conflicting duplicate groups: 11
- unique full business signatures: 83.

Conclusion:

**Never treat source `restis_id` as unique event identity.**

After 0014/importer fix, the test DB correctly stored 83 verified internal event IDs while preserving only 72 unique source IDs.

---

## 12. Reference account — Nelli

- display name: Нэлля
- Bitrix USER_ID: `120445`
- account active
- one active card state 113
- total known Bitrix card records: 4; three are state 116 and one is state 113
- card type 28
- discount 20%
- current bonus balance: `2438.89`
- total spend: `997590.83`
- today: `0.00`
- never expose full card number.

60-day protected loyalty history at last verification:
- visits 83
- amount `208319.71`
- bonus added `48422.60`
- bonus spent `50691.48`
- max single bonus spend `8620.56`.

Risk at last verified reference test:
- v1.3
- overall 100
- critical
- history gate true
- history enriched true.

Do not claim Nelli ever exceeded 40k bonus balance; her risk is device/multiaccount driven.

---

## 13. Negative-balance discovery and fix

The first 100 active Bitrix accounts were inspected read-only through protected loyalty.

Result:
- requested 100
- records 100
- unresolved 0
- active loyalty card found: 68
- no active loyalty card: 32
- `bonus_balance` classes:
  - null: 32
  - valid non-negative decimal string: 62
  - **negative decimal string: 6**
- `total_spend`: valid/null only
- `today_sum`: valid/null only
- raw card number exposed: NO.

Conclusion:
- negative current bonus balance is legitimate real data
- gateway was fixed to accept signed current `bonus_balance`
- other monetary fields remain strict
- migration 0015 removes the stale DB `>=0` constraint.

---

## 14. Current balance architecture

### `account-map`

Identity-only. It must not set/overwrite current loyalty balance.

### Balance refresh service

- source: `bitrix_loyalty_balance`
- protected API inner batch: 50
- targeted refresh accepts up to 200 IDs and internally chunks by 50
- persists `bonus_balance` + `loyalty_synced_at`
- one DB transaction for the records returned in a targeted refresh run
- advisory lock prevents concurrent balance refresh
- no raw card numbers
- no RestIS credentials in bot.

### History policy

- current balance: intended for all active Bitrix accounts that can be resolved
- detailed 60-day history: **only history-gated/suspicious accounts**
- never bulk-load 60-day history for all accounts.

---

## 15. Fleet-wide balance backfill attempts — exact history

### Attempt A — old parser

Initial bulk backfill stopped immediately on batch 1 because gateway rejected negative `bonus_balance` as invalid.

Automatic rollback restored the test DB.

### Negative format diagnostic

Read-only inspection showed 6 negative current balances among first 100 active accounts. This led to signed-balance parser support.

### Attempt B — gateway fixed, DB constraint still stale

Backfill reached PostgreSQL and failed on:

`anti_fraud_accounts_bonus_balance_chk`

because the DB still required `bonus_balance >= 0`.

Automatic rollback restored the test DB.

### Migration 0015 validation

Temporary test run successfully proved:
- migration rows: 16
- last migration created_at: `1788601200000`
- stale nonnegative constraint count: 0
- transaction test `bonus_balance=-1.25`: PASS
- proof transaction rolled back, not committed.

### Attempt C — migration valid, large fleet run hit transient HTTP 500

A full run applied 0015 and then processed active Bitrix accounts in outer batches of 100 (internally 50+50 protected calls).

Observed:
- batches 1..17 succeeded
- **1700 accounts resolved successfully before failure**
- all those successful batches had `unresolved=0`
- batch 18 contained the final 84 accounts
- protected loyalty returned `HTTP 500`
- script treated it as technical failure and automatically restored the entire test DB from backup.

Fresh backup used for that run:
- path: `/opt/evrasia-ai-bot/backups/samzaberu_antifraud_test-pre-0015-balance-20260905_145132.dump`
- SHA256: `84dc3459c8adbe731ddbb9fadf4c006822673c0ee4a21cdcb5382329ca0c8589`
- size: 350613 bytes
- keep this backup.

Rollback result:
- test container running/healthy
- migrations back to 15
- active Bitrix accounts 1784
- loyalty synced active accounts back to 1
- old nonnegative constraint restored
- production remained healthy and unchanged.

---

## 16. Final-84 HTTP 500 diagnostic

A dedicated read-only diagnostic was run against exactly the final 84 active Bitrix accounts that were in the failed last outer batch.

First version of the diagnostic itself failed before making any HTTP request because Node 22 saw `require()` together with top-level `await` and raised `ERR_AMBIGUOUS_MODULE_SYNTAX`. No DB writes occurred.

Corrected diagnostic used explicit ESM (`node --input-type=module`) and performed no DB writes.

Result:
- control USER_ID request: HTTP 200
- final-84 group 1, 50 users: HTTP 200 on first try
- requested 50 / resolved 50 / unresolved 0
- final-84 group 2, 34 users: HTTP 200 on first try
- requested 34 / resolved 34 / unresolved 0
- final control request: HTTP 200
- persistent singletons: 0
- size-sensitive failures: 0
- non-500 failures: 0
- control failures: 0
- classification: **`ALL_LAST84_PASS_NOW`**
- test DB state before/after identical: `15|1785|1784|1|1`
- production unchanged.

Conclusion:

The prior HTTP 500 is most consistent with a **transient protected Loyalty service failure**, not a deterministic bad USER_ID or permanently broken final group.

---

## 17. Retry/backoff decision for the next fleet run

Because the same final 84 later passed immediately, the next full run must be more tolerant of transient service failures.

Chosen operational policy for the next run:
- one outer batch = **50 active Bitrix accounts**
- therefore each CLI run maps to one protected current-state request
- up to 4 attempts per batch
- retry only transient classes such as HTTP 429/500/502/503/504 or protected endpoint timeout
- backoff: 8s, 20s, 45s
- 2s delay between successful batches
- no history requests
- no RestIS credentials in bot
- if a genuinely persistent technical error remains after retries: rollback test DB to fresh pre-run backup
- if response is successful but contains unresolved accounts: preserve valid resolved balances, report partial coverage, do not recalculate fleet-wide Risk until coverage is understood.

---

## 18. Risk engine v1.3

Implemented rules include:
- exact decimal bonus handling
- high-balance threshold `> 40000.00`
- `40000.01` triggers high-balance reason
- account-level protected loyalty coverage controls history enrichment
- protected loyalty endpoint resolves active state-113 card
- account-map does not overwrite balance
- loyalty-verified visits count as valid visits.

Historical reference recalculation before the fleet-balance work:
- scored 1786
- medium 0
- high 7
- critical 16
- history-gate 23
- history-eligible 22
- all score rows v1.3.

These figures are **not final fleet-balance numbers**. Risk must be recalculated again only after the new full current-balance backfill completes successfully.

---

## 19. Scheduler caveat

The hourly pipeline still contains a legacy direct `RestIS VIP_TODAY` current-visit stage that would require RestIS credentials.

Do not solve this by copying RestIS credentials into the bot.

Correct future direction:
- add a protected site-side current-visit source/proxy
- remove direct bot dependency on RestIS.

Until then:
- scheduler remains disabled for Anti-Fraud test work.

---

## 20. Rollback / backups to retain

Do not delete until test acceptance/cleanup approval.

Important retained assets include:

### Latest fleet-backfill backup

- `/opt/evrasia-ai-bot/backups/samzaberu_antifraud_test-pre-0015-balance-20260905_145132.dump`
- SHA256: `84dc3459c8adbe731ddbb9fadf4c006822673c0ee4a21cdcb5382329ca0c8589`

### Earlier successful-deploy backup

- `/opt/evrasia-ai-bot/backups/samzaberu_antifraud_test-pre-55ef0a5-20260905_123904.dump`
- SHA256: `e9bfccc994b09f6a0e40daed34f5906d4ab0195971b98f56241f402a9cc797ce`

### Rollback container

- `evrasia-ai-bot-v17-test-pre-55ef0a5-20260905_123904`

Older rollback containers/backups also exist; retain for now.

---

## 21. Important mistakes to avoid repeating

- Do not confuse active Bitrix accounts with active loyalty cards.
- Do not confuse a successful reference-user test with fleet-wide rollout completion.
- Do not begin final visual acceptance while most balances are still unpopulated.
- Do not assume bonus balances cannot be negative.
- Do not leave the DB constraint stricter than the gateway contract.
- Do not treat every transient HTTP 500 as a permanent bad account.
- Do not send more than 50 users per protected current-state request.
- Do not say Nelli has 7 cards; live data found 4 total, one active.
- Do not use old guessed card numbers.
- Do not confuse discount 20 with bonus balance.
- Do not claim Nelli ever exceeded 40k bonuses.
- Do not treat raw source `restis_id` as unique.
- Do not use blocked/invalid cards for current loyalty state.
- Do not copy RestIS credentials into the bot.
- Do not enable scheduler yet.
- Do not change production during v1.7 test work without explicit approval.
- When using `node -e` with top-level await under Node 22, use explicit ESM or avoid mixed `require()`/top-level-await syntax.

---

## 22. NEXT STEP — current continuation point

A new full fleet current-balance backfill script has been prepared but **has not yet returned execution output**.

### Goal

Populate current loyalty balances for all **1784 `bitrix_active=true` accounts** in the test DB, safely tolerate transient protected API failures, then recalculate Risk v1.3 only if coverage is complete.

### Required next run

1. Production pre-guard: `sha-8fd916f`, running/healthy, Anti-Fraud tables 0.
2. Confirm rollback baseline in test:
   - test web image `sha-55ef0a5`
   - migrations 15
   - total accounts 1785
   - active Bitrix accounts 1784
   - inactive 1
   - loyalty synced active accounts 1
   - old bonus constraint present.
3. Pull/verify exact one-shot image `sha-d76ca1e` with expected digest/revision/image ID.
4. Fresh test DB backup + validation + SHA256.
5. Apply migration 0015 using one-shot image; test web image remains unchanged.
6. Verify:
   - migrations 16
   - stale constraint count 0
   - `bonus_balance=-1.25` works inside a transaction that is rolled back.
7. Load exactly 1784 active Bitrix USER_ID values privately.
8. Run full current-balance backfill in batches of 50.
9. Retry transient 429/500/502/503/504/timeouts with 8/20/45s backoff, max 4 attempts.
10. Do not request 60-day history in this fleet run.
11. After successful balance pass, report:
    - requested
    - resolved
    - unresolved
    - active-card accounts
    - retries
    - known balances
    - synced-without-balance
    - unsynced
    - negative balances
    - balances > 40000
    - balances exactly 40000.
12. If unresolved/unsynced remain, stop before global risk recalculation and diagnose coverage separately.
13. If coverage is full, recalculate Risk v1.3 with `ANTI_FRAUD_REFRESH_ACCOUNTS=false` and `ANTI_FRAUD_AUTO_HISTORY=false`.
14. Verify all active risk rows are v1.3 and every `bonus_balance > 40000` account has history gate true.
15. Check direct `/antifraud`, nginx preview, and summary API return 200.
16. Confirm test web image did not switch.
17. Final production guard.
18. Keep fresh backup.

### Important distinction

- **Fleet current-balance backfill:** YES.
- **Fleet 60-day history backfill:** NO.
- **Scheduler enablement:** NO.
- **Production changes:** NO.

### Expected user workflow

The user will run the already-prepared full bash block and paste the complete output. Analyze that output section-by-section. Do not claim the fleet backfill has completed until the output proves it.

---

## 23. Maintenance rule for this file

Update this document after every material milestone, especially when any of these change:
- branch / PR / merge state
- application image or deployed test image
- production image
- migration level
- DB schema/state
- protected API behavior
- rollback/backup state
- business rule
- risk calculation version
- known/resolved issue
- `NEXT STEP`.

`NEXT STEP` must always describe the actual continuation point.