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

## 1. Continuation rule

This file is the continuity source for long-running Evrasia AI Bot work. In a new ChatGPT conversation, read it first and then verify the live GitHub PR/HEAD and server state before changing anything.

Recommended new-chat prompt:

> Продолжаем проект Evrasia AI Bot. Репозиторий `juvantusik/Evrasia_AI_bot`. Сначала прочитай `docs/AI_PROJECT_CONTEXT.md` в актуальной рабочей ветке, проверь состояние PR #32 и текущий HEAD, восстанови контекст проекта и продолжай строго с раздела `NEXT STEP`. Не повторяй уже выполненные действия и не меняй production без моего явного разрешения. Для серверных действий давай один полный диагностический bash-скрипт с guard/backup/rollback и без вывода секретов.

---

## 2. Product/version roadmap

- **v1.6.9** — current production phonebook/directory baseline.
- **v1.7** — current Anti-Fraud workstream.
- **v1.8** — document generation (Jira KAN-66, KAN-77).
- **v1.9** — document sending through Exchange (Jira KAN-67, KAN-78).
- Trusted Device is an Anti-Fraud foundation/data source, not a separate bot version.

---

## 3. Hard safety rules

1. Production remains untouched during v1.7 test work unless explicitly approved.
2. Keep PR #32 Draft until visual approval.
3. Do not copy RestIS credentials into the bot.
4. Do not expose raw loyalty card numbers to bot/UI/logs.
5. Do not print tokens, passwords, card numbers, or full diagnostic USER_ID lists.
6. Keep rollback assets/backups until explicit cleanup approval.
7. Anti-Fraud scheduler stays disabled until direct legacy `VIP_TODAY` dependency is replaced by a protected site-side current-visit source.
8. Full phone/email are intentionally visible to the Anti-Fraud operator for manual investigation; do not re-mask without a business requirement change.
9. Never claim server state changed without pasted server output or direct verification.

---

## 4. Server / infrastructure

### Host

- hostname: `eur-bot-01`
- IP: `192.168.103.200`
- OS: Debian 13 (trixie)
- Docker Engine: 26.1.5
- Docker Compose: 2.26.1-4
- Docker network: `evrasia-prod-internal`
- PostgreSQL container: `samzaberu-db`

### Production — last verified 2026-09-05 after fleet diagnostics

- container: `evrasia-ai-bot-app`
- image: `ghcr.io/juvantusik/evrasia_ai_bot:sha-8fd916f`
- status: running
- health: healthy
- port: `127.0.0.1:18080 -> 8080`
- DB: `samzaberu`
- production Anti-Fraud tables: `0`
- **production changed during v1.7 work: NO**

### Test v1.7 web container

- container: `evrasia-ai-bot-v17-test`
- deployed web image: `ghcr.io/juvantusik/evrasia_ai_bot:sha-55ef0a5`
- deployed web revision: `55ef0a522bbbcfe552480d3f222bacab46009e95`
- test DB: `samzaberu_antifraud_test`
- port: `127.0.0.1:18081 -> 8080`
- nginx preview: `http://192.168.103.200:8081/antifraud`
- Telegram polling: disabled
- `ANTI_FRAUD_SCHEDULER_ENABLED=false`
- `ANTI_FRAUD_SCHEDULER_RUN_ON_START=false`
- `ANTI_FRAUD_AUTO_HISTORY=false`
- `ANTI_FRAUD_REFRESH_ACCOUNTS=false`
- RestIS credentials in bot: NO

### Test DB — current state

After successful fleet current-balance backfill:

- migrations: **16** (through `0015_anti_fraud_signed_bonus_balance`)
- total `anti_fraud_accounts`: **1785**
- `bitrix_active=true`: **1784**
- `bitrix_active=false`: **1**
- active accounts with `loyalty_synced_at IS NOT NULL`: **1784**
- active accounts with known non-NULL `bonus_balance`: **1437**
- active synced accounts with NULL `bonus_balance`: **347**
- active unsynced accounts: **0**
- negative current balances: **76**
- exact zero balances: **43**
- balances `> 40000.00`: **6**
- balances `= 40000.00`: **0**
- max observed current balance: **158444.11**
- stale DB constraint `anti_fraud_accounts_bonus_balance_chk`: **removed**
- `bonus_balance` remains `NUMERIC(14,2)`.

Important terminology:
- **1784 means active Bitrix accounts**, not active loyalty cards.
- `active_card_found=true` means the protected loyalty response found **exactly one unambiguous active RestIS state-113 card**.
- The fleet backfill count **1438 active-card accounts** therefore means 1438 accounts with exactly one active state-113 card, not all accounts with any active card.
- The previous inference that the remaining 346 accounts had no active card was wrong and is superseded by the full read-only fleet classification in section 16.

### Secret paths — never print values

- Bitrix API token host path: `/opt/evrasia-ai-bot/secrets/bitrix-api-token`
- Anti-Fraud service token host path: `/opt/evrasia-ai-bot/secrets/anti-fraud-service-token`
- Anti-Fraud token inside container: `/run/secrets/anti-fraud-service-token`
- loyalty gateway env: `BITRIX_ANTI_FRAUD_TOKEN_FILE`

---

## 5. GitHub / CI / immutable application image

### PR

- PR #32
- branch: `feature/v1.7-antifraud-web`
- base: `main`
- state: open
- draft: true
- merged: false
- keep Draft until visual approval.

### Application-code revision used for migration/backfill/risk work

- application revision: `d76ca1e02042e7830e22cbb2584a17bbca00025d`
- GHCR tag: `ghcr.io/juvantusik/evrasia_ai_bot:sha-d76ca1e`
- digest: `sha256:9edb4be6074a50dd9a6c65b8c7a313593dd94db3d12314b44c0b3f641389187d`
- image/config ID: `sha256:d4e7a67135fb7aa403c794bc961f77b8d53ca34ccd8414a0f443f2592a7ea56c`
- manual workflow run #218: success
- 56/56 tests passed
- API/web smoke passed
- DB smoke proved 16 migrations and signed `bonus_balance=-1.25`.

The branch HEAD can be newer because documentation-only commits are added after the image build. Never confuse docs-only HEAD with the immutable application revision above.

### GHCR pull on server

Root does not have GHCR auth. User `tech` does. Use:

`runuser -u tech -- env HOME=/home/tech docker pull ...`

Do not copy GitHub tokens into root config.

---

## 6. Operator/server execution preference

For server work, provide one complete bash block copied/run as a whole.

Required style:
- `clear`
- `set +e`
- `set +u`
- `set +o pipefail 2>/dev/null`
- variables at top
- numbered stages
- production guard before and after
- test guard
- backup before DB mutation
- rollback path
- explicit RC and PASS/FAIL
- `/tmp` temporary files and cleanup
- no outer-shell `exit`; use functions + `return`
- no secret output
- finish with `TERMINAL_WILL_STAY_OPEN=YES`.

For large scripts, preferred safe delivery:
1. write script to a temporary `.sh` with a quoted heredoc;
2. run `bash -n`;
3. execute only when syntax check passes;
4. delete the temporary script afterwards.

This avoids partial pasted-function execution in an interactive shell.

---

## 7. Anti-Fraud business rules

### Risk

- displayed risk: 0–100
- calculation version: `v1.3`
- advisory-only; no automatic blocking.

### Case dynamics

- Новый
- Усилился
- Без изменений
- Ослаб

### Device/multiaccount baseline

Known two-account shared-device example:
- `shared_device_accounts` +40
- `linked_accounts` +10
- total 50 -> high -> history gate true.

### Bonus threshold

- exactly `40000.00`: no high-balance signal
- `40000.01+`: +50 and history gate
- no auto-block
- exact decimal storage
- negative current balances are valid real data.

### Loyalty card state

Use active RestIS state `113` for current loyalty state/history.

- 113 — Активна
- 114 — Недействительна
- 115 — Украдена
- 116 — Изъять

Do not use 114/115/116 for current state. Discount `20` means 20%, not 20 bonus points.

Important ambiguity rule:
- `active_card_count=0` => no active card
- `active_card_count=1` => exactly one unambiguous active card
- `active_card_count>1` => multiple active cards; do not arbitrarily choose one card or one balance.

Do **not** add Risk points for multiple active cards yet. First persist and expose the state in the UI, then decide business significance after review.

---

## 8. Protected site-side Anti-Fraud API

Protected internal routes on `evrasia.spb.ru` include:
- `POST /api/internal/anti-fraud/card-map`
- `POST /api/internal/anti-fraud/account-map`
- `POST /api/internal/anti-fraud/trusted-device-export`
- `POST /api/internal/anti-fraud/loyalty`

Target graph:

`BITRIX USER_ID -> identity + Trusted Device hash + active RESTIS_STATE=113 loyalty + current balance/history`

The bot does not need raw card numbers or RestIS credentials for protected loyalty.

### Loyalty gateway constraints

- HTTPS required
- current-state max: 50 USER_ID per protected request
- history max: 10 USER_ID per request
- history max: 60 days
- raw `card_number` rejected if exposed
- current `bonus_balance` accepts signed decimal strings
- `total_spend`, `today_sum`, and history money remain non-negative contracts
- `active_card_found=true` is valid only with exactly one active card and `card_status_id=113`
- `active_card_count=0` returns issue `no_active_card`
- `active_card_count>1` returns issue `multiple_active_cards` and no selected current balance.

---

## 9. Trusted Device foundation

- permanent opaque `device_id` per app install
- 32 cryptographically random bytes -> 64 lowercase hex chars
- regex `^[a-f0-9]{64}$`
- not UUID/IMEI/MAC/advertising ID/hardware ID
- one ID for installation regardless of account
- survives restart/update/logout
- reinstall creates a new ID
- server stores SHA-256 device hash
- trust TTL: 90 days
- IP is not identity/trust
- do not create a competing device identity mechanism in the bot.

Protected export endpoint: `/api/internal/anti-fraud/trusted-device-export`.

---

## 10. Database migrations relevant to v1.7

### 0013 — `anti_fraud_loyalty_decimal`

- `bonus_balance` -> `NUMERIC(14,2)`
- loyalty sync/history timestamps
- nullable visit card link for protected history
- exact visit monetary fields
- `loyalty_verified`.

Historical issue: 0013 recreated `bonus_balance >= 0`, which real current balances disproved.

### 0014 — `anti_fraud_restis_event_identity`

Raw RestIS `restis_id` is not unique per business operation.

- raw ID stored as `source_restis_id`
- intentionally nonunique
- stable internal event ID hashes source ID + timestamp + restaurant + amount + bonus added + bonus spent
- exact duplicate event still rejected/deduplicated.

### 0015 — `anti_fraud_signed_bonus_balance`

- drops only `anti_fraud_accounts_bonus_balance_chk`
- keeps `bonus_balance NUMERIC(14,2)`
- allows signed current balances
- does not loosen history/visit money contracts
- journal index 15 / created_at `1788601200000`.

Current test DB has 16 migrations and 0015 is persistently applied after the successful fleet run.

### Next likely migration

Current `anti_fraud_accounts` persists `bonus_balance` and loyalty sync timestamps but does not yet persist enough information to distinguish a NULL balance caused by:
- no active card
- multiple active cards
- exactly one active card with unavailable balance.

Next schema change should persist the protected loyalty resolution state, at minimum:
- `loyalty_active_card_count`
- `loyalty_issue` (or an equivalent normalized status field).

Exact column names are not yet final; implement only after source/tests are updated together.

---

## 11. RestIS event-identity discovery

Nelli 60-day live diagnostic previously proved:
- 83 history rows
- 72 unique source RestIS IDs
- 11 repeated source-ID groups
- 0 exact duplicate groups
- 83 unique full business signatures.

Conclusion: never treat source `restis_id` as unique event identity.

---

## 12. Reference account — Nelli

- display name: Нэлля
- Bitrix USER_ID: `120445`
- active account
- total known card records: 4
- one active state-113 card, three state-116 cards
- card type 28
- discount 20%
- current balance at last detailed verification: `2438.89`
- total spend: `997590.83`
- today: `0.00`
- never expose full card number.

60-day protected history at last detailed verification:
- visits 83
- amount `208319.71`
- bonus added `48422.60`
- bonus spent `50691.48`
- max single bonus spend `8620.56`.

Nelli risk reference:
- v1.3
- overall 100
- critical
- history gate true
- history enriched true.

Do not claim Nelli exceeded 40k balance; her reference risk is device/multiaccount driven.

---

## 13. Negative-balance discovery

Read-only inspection of the first 100 active Bitrix accounts showed:
- requested 100
- resolved 100
- unresolved 0
- exactly one active loyalty card: 68
- all remaining responses had no unambiguous single active card
- negative current `bonus_balance`: 6.

This proved negative current balances are legitimate. Gateway signed parsing and migration 0015 were added accordingly.

Fleet backfill later confirmed **76 active Bitrix accounts currently have negative stored balances**.

---

## 14. Current balance architecture

### `account-map`

Identity-only. It must not set or overwrite current loyalty balance.

### Balance refresh service

- source: `bitrix_loyalty_balance`
- protected inner batch size: 50
- targeted refresh accepts up to 200 IDs and chunks internally to 50
- persists `bonus_balance` + `loyalty_synced_at`
- advisory lock prevents concurrent refresh
- no raw cards
- no RestIS credentials in bot.

Current limitation:
- the service does not persist `active_card_count` / `issue`, so after the refresh the DB cannot distinguish different NULL-balance reasons.

### History policy

- current balance: fleet-wide for active Bitrix accounts
- detailed 60-day history: only history-gated/suspicious accounts
- never fleet-load 60-day history.

---

## 15. Fleet-wide current-balance backfill history

### Attempt A — old gateway parser

Stopped on negative current balance; rollback succeeded.

### Attempt B — signed gateway but stale DB constraint

Failed on `anti_fraud_accounts_bonus_balance_chk`; rollback succeeded.

### Attempt C — migration valid, transient HTTP 500

- migration 0015 applied successfully
- first 1700 active Bitrix accounts resolved successfully
- final outer batch of 84 hit protected API HTTP 500
- full automatic test DB rollback succeeded
- production unchanged.

Read-only follow-up on exactly those final 84:
- 50 users: HTTP 200, resolved 50, unresolved 0
- 34 users: HTTP 200, resolved 34, unresolved 0
- control requests: 200
- classification: `ALL_LAST84_PASS_NOW`.

Conclusion: prior 500 was transient, not a deterministic bad account.

### Attempt D — successful fleet run

Safety wrapper:
- wrote full script to `/tmp`
- `bash -n`: PASS
- execution: PASS
- wrapper RC: 0
- temporary script removed.

Backfill policy:
- 50 active Bitrix accounts per batch
- max 4 attempts
- transient retry classes: 429/500/502/503/504/timeouts
- backoff 8s / 20s / 45s
- 2s between successful batches
- current balances only
- no fleet history.

Observed result:
- batches: **36**
- requested: **1784**
- resolved: **1784**
- unresolved: **0**
- DB updated: **1783** (one reference account was already synced before run)
- exactly-one-active-card responses: **1438**
- retries: **1**
- batch 28 first attempt hit transient error and succeeded on attempt 2 after 8s
- all other batches succeeded on first attempt.

Coverage after backfill:
- active: 1784
- synced: 1784
- known balances: 1437
- synced NULL balances: 347
- unsynced: 0
- negative: 76
- zero: 43
- >40000: 6
- =40000: 0
- max balance: 158444.11.

This is the first successful fleet-wide current-balance population in v1.7 test.

---

## 16. Full fleet loyalty-card classification — read-only, 2026-09-05

A follow-up read-only diagnostic called protected `/api/internal/anti-fraud/loyalty` for all **1784 active Bitrix accounts** with:
- batch size 50
- `include_history=false`
- no DB writes
- no card-number output
- no individual USER_ID output
- production guard before/after.

Execution result:
- batches: **36**
- retries: **0**
- input: **1784**
- requested: **1784**
- resolved: **1784**
- unresolved: **0**
- contract mismatches: **0**
- unexpected issue records: **0**
- test DB fingerprint before/after: identical
- production unchanged.

### Exact current state classification

- **exactly one active state-113 card: 1438**
- **no active state-113 card: 240**
- **multiple active state-113 cards: 106**
- classified total: **1784**.

This corrects the earlier assumption that all 346 accounts outside the 1438 exactly-one set had no active card. The correct split is **240 no active card + 106 multiple active cards**.

### Distribution by active-card count

- 0 active cards: **240**
- 1 active card: **1438**
- 2 active cards: **88**
- 3 active cards: **12**
- 4 active cards: **4**
- 8 active cards: **1**
- 10 active cards: **1**
- maximum observed active-card count: **10**.

### Issue distribution from protected API

- `none`: **1438**
- `no_active_card`: **240**
- `multiple_active_cards`: **106**.

### Balance availability by card state

For the 1438 accounts with exactly one active card:
- known current balance: **1437**
- NULL current balance: **1**.

For ambiguous/non-active states:
- no-active-card accounts with unexpected balance: **0**
- multiple-active-card accounts with unexpected balance: **0**.

Therefore the **347 NULL balances** in DB break down exactly as:
- **240** — no active card
- **106** — multiple active cards, so API deliberately does not choose one current balance
- **1** — exactly one active card, but current balance is NULL/unavailable.

### Product/UI implication

A generic `Бонусы: —` is insufficient. The UI should distinguish at least:
- numeric balance, including negative and zero values
- `Нет активной карты`
- `Несколько активных карт (N)` — warning/anomaly state
- `Баланс недоступен` — exactly one active card but NULL current balance.

`multiple_active_cards` should be visible to the operator as an anomaly, but **must not automatically add Risk points yet** until business meaning is agreed.

---

## 17. Risk v1.3 after fleet balance backfill

Risk recalculation ran with:
- `ANTI_FRAUD_REFRESH_ACCOUNTS=false`
- `ANTI_FRAUD_AUTO_HISTORY=false`
- no fleet history import.

Observed CLI result:
- `scoredAccounts`: **1786**
- medium: **0**
- high: **13**
- critical: **16**
- history gate: **29**.

DB verification over active account risk rows:
- active risk rows: **1784**
- non-v1.3 active rows: **0**
- medium: 0
- high: 13
- critical: 16
- history gate: 29
- accounts with `bonus_balance > 40000` but missing history gate: **0**.

Compared with pre-fleet-balance reference (`high=7`, `critical=16`, `history_gate=23`), fleet balances added six high/history-gate signals, consistent with six balances above 40000.00.

Do not interpret `scoredAccounts=1786` as active Bitrix account count; verified active-account count remains 1784.

---

## 18. Web verification after successful fleet run

- direct `/antifraud`: HTTP 200
- nginx preview `/antifraud`: HTTP 200
- `/api/anti-fraud/summary`: HTTP 200
- test web container remained on `sha-55ef0a5`
- test container remained running/healthy
- DB migrations: 16
- production remained `sha-8fd916f`, running/healthy, Anti-Fraud tables 0.

Visual review exposed the generic `Бонусы: —` problem, which led to the fleet classification in section 16.

---

## 19. Current rollback / backup assets

Keep until explicit cleanup approval.

### Fresh pre-successful-backfill backup

- path: `/opt/evrasia-ai-bot/backups/samzaberu_antifraud_test-pre-balance-20260905_151021.dump`
- SHA256: `459355958011f9b60133652092bf14a15f44e53b3a6be7d759d4bc0542cf1370`
- size: 350613 bytes

### Previous transient-500 run backup

- `/opt/evrasia-ai-bot/backups/samzaberu_antifraud_test-pre-0015-balance-20260905_145132.dump`
- SHA256: `84dc3459c8adbe731ddbb9fadf4c006822673c0ee4a21cdcb5382329ca0c8589`

### Earlier deploy backup / rollback container

- `/opt/evrasia-ai-bot/backups/samzaberu_antifraud_test-pre-55ef0a5-20260905_123904.dump`
- SHA256: `e9bfccc994b09f6a0e40daed34f5906d4ab0195971b98f56241f402a9cc797ce`
- rollback container: `evrasia-ai-bot-v17-test-pre-55ef0a5-20260905_123904`.

---

## 20. Scheduler caveat

The hourly pipeline still contains legacy direct `RestIS VIP_TODAY` current-visit logic that would require RestIS credentials.

Do not solve this by copying RestIS credentials into the bot.

Future direction:
- protected site-side current-visit endpoint/proxy
- remove direct bot RestIS dependency.

Scheduler remains disabled until that is done.

---

## 21. Important mistakes to avoid repeating

- Do not confuse active Bitrix accounts with exactly-one active loyalty-card accounts.
- Do not infer `bonus_balance IS NULL` means `no_active_card`.
- Do not repeat the superseded `346 no active card` assumption; exact split is 240 no active + 106 multiple active.
- Do not arbitrarily select a card/balance when `active_card_count > 1`.
- Do not add automatic Risk points for multiple active cards until business meaning is approved.
- Do not confuse a reference-user success with fleet completion.
- Do not assume current bonus balance cannot be negative.
- Do not keep a DB constraint stricter than the gateway contract.
- Do not treat transient HTTP 500 as permanent bad account without retry/diagnostic.
- Do not send more than 50 users per protected current-state request.
- Do not bulk-load 60-day history for all accounts.
- Do not say Nelli has 7 cards; verified reference has 4 total, one active.
- Do not confuse discount 20 with balance.
- Do not claim Nelli exceeded 40k.
- Do not treat raw source `restis_id` as unique.
- Do not copy RestIS credentials into bot.
- Do not enable scheduler yet.
- Do not change production without explicit approval.
- For Node 22 top-level await diagnostics, use explicit ESM; do not mix `require()` with top-level await.
- For long server scripts, write to a file and run `bash -n` before execution.

---

## 22. NEXT STEP — current continuation point

Fleet current balances, full current loyalty-card classification, and Risk v1.3 recalculation are complete. Do **not** rerun fleet backfill/classification unless there is a specific reason.

### Immediate next goal: persist loyalty resolution state and make UI truthful

1. Inspect current schema/service/API/UI paths before modifying them.
2. Add a migration and schema fields sufficient to persist current loyalty resolution state. Minimum useful data:
   - active state-113 card count
   - protected loyalty issue/status (`none`, `no_active_card`, `multiple_active_cards`, and room for future states).
3. Update balance refresh service so every successful protected response persists both:
   - `bonus_balance`
   - card-resolution state/count and `loyalty_synced_at`.
4. Preserve current contract:
   - exactly one active card -> use numeric balance when available
   - no active card -> no balance selected
   - multiple active cards -> no balance selected
   - exactly one active card + NULL balance -> `Баланс недоступен`.
5. Update Anti-Fraud API/UI so `Бонусы` renders distinct states:
   - numeric value, including negative/zero
   - `Нет активной карты`
   - `Несколько активных карт (N)` with warning styling
   - `Баланс недоступен`.
6. Add tests for all four states and ensure raw card number is never exposed.
7. Do not add Risk points for `multiple_active_cards` yet.
8. After code/CI success, build an immutable candidate image and deploy it to the **test web container** so visual acceptance is performed against the final candidate code. Current test web still runs `sha-55ef0a5`; one-shot migration/backfill/risk work used `sha-d76ca1e`.
9. Keep full 60-day history targeted only to history-gated accounts.
10. Keep scheduler disabled.
11. Keep PR #32 Draft until visual approval.
12. Production remains untouched until explicit approval.

### Important current facts

- Active Bitrix accounts: **1784**
- Exactly one active loyalty card: **1438**
- No active loyalty card: **240**
- Multiple active loyalty cards: **106**
- Exactly one card + known balance: **1437**
- Exactly one card + NULL balance: **1**
- Fleet unresolved USER_ID: **0**
- Fleet current balances: **DONE**
- Fleet card-state classification: **DONE**
- Fleet 60-day history: **NOT DONE by design**
- Risk v1.3 after balances: **DONE**
- Scheduler: **OFF**
- Production changes: **NONE**
- Loyalty status persistence/UI correction: **NEXT**

---

## 23. Maintenance rule

Update this file after every material milestone, especially changes to:
- branch / PR / merge state
- application/deployed image
- production image
- migration level
- DB state
- protected API behavior
- loyalty-card resolution semantics
- backup/rollback state
- business/risk rules
- known/resolved issues
- `NEXT STEP`.

`NEXT STEP` must always describe the actual continuation point.
