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

This file is the continuity source for long-running Evrasia AI Bot work. In a new ChatGPT conversation, read it first, then verify the live PR/HEAD and the exact test/production server state before changing anything.

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
4. Do not expose raw loyalty card numbers to the bot UI/API/logs or persist them in this context document.
5. A full card number may be printed only as an explicit, temporary operator-only forensic exception requested by the user; do not copy such numbers into persistent project documentation.
6. Do not print tokens, passwords, full phones, service credentials, or bulk USER_ID lists in diagnostics.
7. Keep rollback assets/backups until explicit cleanup approval.
8. Full phone/email are intentionally visible in the authenticated Anti-Fraud operator UI for manual investigation; do not re-mask there without a business requirement change.
9. Multiple active loyalty cards are an operator-visible anomaly but **do not add automatic Risk points** until the business meaning is approved.
10. Never claim server state changed without pasted server output or direct verification.
11. Never replay legacy RestIS activation/write requests during forensics. Static source inspection and known read-only `INFO`/balance queries are allowed.

---

## 4. GitHub / PR / CI — current verified state

### PR #32

Verified from GitHub on 2026-09-05 before this documentation commit:

- state: **open**
- draft: **true**
- merged: **false**
- base: `main`
- base SHA: `99377cb34a7798f982d0d9e073d13a6a4ef00afc`
- working branch: `feature/v1.7-antifraud-web`
- latest **application-code** commit before this docs update: `781834632efc07d0b43af91cf1c67425777926bb`
- commit message: `fix: refresh loyalty for newly risky accounts in same cycle`
- parent: `c443ea757627d17d616542b5582aef55b357e27c`.

The branch HEAD becomes a docs commit whenever this file is updated. Do not confuse a documentation-only HEAD with the latest application-code revision.

### Current code delta at `781834...`

The scheduler cycle now does a two-pass risk calculation so a newly discovered risky account receives loyalty data in the **same** manual/scheduled cycle:

1. `trusted_device_export`
2. `bitrix_account_map`
3. `risk_scoring_pre_loyalty` with `autoHistory=false`
4. `loyalty_priority`
5. `loyalty_stale_scan`
6. final risk calculation with targeted `autoHistory=true`
7. `case_dynamics`.

This fixes the UX case where a newly risky account could otherwise first appear as `Карты: не загружено` and receive loyalty only on the next cycle.

### CI / image history

Verified immutable candidate for `c443ea757627d17d616542b5582aef55b357e27c`:

- tag: `ghcr.io/juvantusik/evrasia_ai_bot:sha-c443ea7`
- manifest digest: `sha256:1f89536030a7a8408a11fd3f2a42bcc5455184a590f7586c411e4b80193ae0d1`
- image/config ID: `sha256:23ed606d215351404213ad5a01024dd3864e8cf892872957abdf63524adef144`
- PR build run #231: success
- manual publication run #232: success
- 57/57 tests passed
- migration smoke: 17 migrations
- signed current balance smoke passed
- multiple-active-card aggregate-balance regression passed
- Anti-Fraud API/web smoke passed.

For the newer application-code commit `781834...`:

- manual workflow `Build server image` run **#234** (`workflow_dispatch`) completed successfully on 2026-09-05;
- before deployment, verify the immutable tag/digest from the run and verify what image the test server actually runs;
- do **not** infer deployment merely from successful CI/publication.

PR #32 remains Draft.

---

## 5. Server / infrastructure

### Host

- hostname: `eur-bot-01`
- IP: `192.168.103.200`
- OS: Debian 13 (trixie)
- Docker Engine: 26.1.5
- Docker Compose: 2.26.1-4
- Docker network: `evrasia-prod-internal`
- PostgreSQL container: `samzaberu-db`.

### Production — last verified state

- container: `evrasia-ai-bot-app`
- image: `ghcr.io/juvantusik/evrasia_ai_bot:sha-8fd916f`
- status: running / healthy at last verification
- port: `127.0.0.1:18080 -> 8080`
- DB: `samzaberu`
- production Anti-Fraud tables: `0`
- **production changed during v1.7 work: NO**.

Always re-check this guard before and after any test-server mutation.

### Test v1.7

- container name: `evrasia-ai-bot-v17-test`
- DB: `samzaberu_antifraud_test`
- port: `127.0.0.1:18081 -> 8080`
- nginx preview: `http://192.168.103.200:8081/antifraud`
- Telegram polling: disabled
- RestIS credentials in bot: NO.

Later server diagnostics after the old version of this file showed that the c443ea7-era candidate was deployed to TEST, migration 0016/17 was applied, fleet loyalty refresh completed, a protected manual cycle succeeded, and the protected scheduler was enabled and ran successfully. **However, the exact current test image/revision and scheduler enabled/disabled state must be re-verified before the next server change.**

Reason for this caveat: a later scheduler-pause script was proposed during card forensics, but no execution output was returned, so it is not evidence that the scheduler was actually paused. The last known state before that unverified pause attempt was enabled.

Do not state `scheduler=OFF` or `scheduler=ON` as current fact until the server is rechecked.

### GHCR pull note

Root previously did not have GHCR auth while user `tech` did. Safe pattern:

`runuser -u tech -- env HOME=/home/tech docker pull ...`

Do not copy GitHub tokens into root config.

---

## 6. Database migrations relevant to v1.7

### 0013 — `anti_fraud_loyalty_decimal`

- `bonus_balance` -> `NUMERIC(14,2)`
- loyalty sync/history timestamps
- nullable visit card link for protected history
- exact visit monetary fields
- `loyalty_verified`.

### 0014 — `anti_fraud_restis_event_identity`

Raw RestIS `restis_id` is not unique per business operation.

- raw ID stored as `source_restis_id`
- intentionally nonunique
- stable internal event ID hashes source ID + timestamp + restaurant + amount + bonus added + bonus spent
- exact duplicate event remains deduplicated.

### 0015 — `anti_fraud_signed_bonus_balance`

- drops only `anti_fraud_accounts_bonus_balance_chk`
- keeps `bonus_balance NUMERIC(14,2)`
- allows signed **current** balances
- history/amount/bonus-added/bonus-spent contracts remain non-negative
- negative current balances are real data.

### 0016 — `anti_fraud_loyalty_resolution`

Path: `lib/db/drizzle/0016_anti_fraud_loyalty_resolution.sql`.

Adds to `anti_fraud_accounts`:

- `loyalty_active_card_count INTEGER`
- `loyalty_issue TEXT`
- count check `>= 0`
- issue index.

Schema exposes:

- `loyaltyActiveCardCount`
- `loyaltyIssue`.

Journal total becomes **17 migrations**.

Semantics:

- `NULL` count — unknown/not loaded
- `0` — no active state-113 card
- `1` — exactly one active state-113 card
- `>1` — multiple active state-113 cards.

The balance refresh service atomically persists:

- `bonus_balance`
- `loyalty_active_card_count`
- `loyalty_issue`
- `loyalty_synced_at`.

---

## 7. Protected site-side Anti-Fraud API / loyalty contract

Protected internal routes on `evrasia.spb.ru` include:

- `POST /api/internal/anti-fraud/card-map`
- `POST /api/internal/anti-fraud/account-map`
- `POST /api/internal/anti-fraud/trusted-device-export`
- `POST /api/internal/anti-fraud/loyalty`.

Target graph:

`BITRIX USER_ID -> identity + Trusted Device hash + active RestIS state + account-level balance/history`.

### Loyalty gateway constraints

- HTTPS required
- current-state max: 50 USER_ID/request
- history max: 10 USER_ID/request
- history max: 60 days
- raw `card_number` must never be returned by protected API
- current `bonusBalance` parser accepts signed decimals
- `totalSpend`, `todaySum`, and history money remain non-negative contracts
- timeout default: 30s via `BITRIX_ANTI_FRAUD_TIMEOUT_MS`
- HTTP failures must not expose response bodies/secrets.

### Active-card identity vs account-level balance

`active_card_found === (active_card_count === 1)`.

- exactly one active card: card identity is unambiguous
- zero active cards: issue `no_active_card`
- multiple active cards: issue `multiple_active_cards`, `active_card_found=false`, no arbitrary card identity selected.

**Important correction:** after the site-side multicard patch, `active_card_count>1` may still return the **account/phone-level current balance** from RestIS `/api/Balance`. This is allowed because `TotalSum` is account-level; it must not be multiplied or summed once per card.

For multiple active cards:

- keep issue `multiple_active_cards`
- do not expose/select a raw card number
- show the active-card count
- show the known account-level balance when available
- UI text may mark it as aggregate/current account balance (`суммарно`)
- do not add Risk points solely for this anomaly.

Known live protected smoke after this patch:

- reference one-card account: count 1, balance 2438.89, discount 20
- 10-card anomaly account: count 10, issue `multiple_active_cards`, account balance 733.75, discount 15
- another count 2 anomaly: balance 0.00
- another count 4 anomaly: balance 385.00
- no-active-card example: count 0, balance NULL.

`0.00` is a known zero and is distinct from `NULL`.

---

## 8. Fleet loyalty classification

Read-only full-fleet classification over **1784 active Bitrix accounts** on 2026-09-05:

- exactly one active state-113 card: **1438**
- no active state-113 card: **240**
- multiple active state-113 cards: **106**
- total classified: **1784**
- unresolved: **0**
- contract mismatches: **0**.

Active-card-count distribution:

- 0: 240
- 1: 1438
- 2: 88
- 3: 12
- 4: 4
- 8: 1
- 10: 1.

Issue distribution:

- none: 1438
- `no_active_card`: 240
- `multiple_active_cards`: 106.

Before the multicard account-balance patch, the 347 stored NULL balances decomposed as:

- 240 no active card
- 106 multiple active cards
- 1 exactly-one card with balance unavailable.

That NULL decomposition is **historical**, not the target contract after the multicard patch. Multiple-card responses can now carry an account-level balance. Re-check exact DB counts after any refresh instead of reusing the old 347 number as current truth.

Important terminology:

- 1784 = active Bitrix accounts, not active cards
- 1438 = exactly one active state-113 card, not “at least one”
- 240 = no active card
- 106 = multiple active cards.

---

## 9. Current balance / refresh architecture

`account-map` is identity-only and must not overwrite loyalty balance.

Balance refresh service:

- source: protected Bitrix loyalty gateway
- target refresh: 1..200 USER_ID
- inner protected chunks: 50
- advisory lock prevents concurrent refresh
- fetches all requested state before transactional persistence
- persists balance + count + issue + sync timestamp
- no raw card numbers
- no RestIS credentials in bot.

Refresh result distinguishes:

- exactly-one active-card accounts
- multiple-active-card accounts
- no-active-card accounts.

Stale scan is capped at 200 accounts per cycle.

History remains targeted only to history-gated/suspicious accounts. Never fleet-load 60-day history.

Known implementation caveat: history enrichment must not silently reintroduce inconsistent count/issue persistence; if history-only refresh paths are changed later, re-check this contract.

---

## 10. Anti-Fraud scheduler / manual refresh

Legacy direct RestIS `VIP_TODAY` and legacy card-map stages were removed from the protected scheduler cycle. The bot container must not receive RestIS credentials.

Current code default:

- `ANTI_FRAUD_SCHEDULER_ENABLED=false`
- `ANTI_FRAUD_SCHEDULER_INTERVAL_MINUTES=15`
- `ANTI_FRAUD_SCHEDULER_RUN_ON_START=false`
- rolling loyalty scan batch default 200 / clamped to 200.

Manual routes:

- `GET /api/anti-fraud/scheduler`
- `POST /api/anti-fraud/refresh`
- manual refresh returns 409 if a cycle is already running.

UI button `Обновить сейчас` runs the real protected Anti-Fraud cycle and then reloads the views; it is not a browser-only refresh.

At application code `781834...`, cycle stages are:

1. Trusted Device full snapshot
2. Bitrix account-map
3. preliminary risk scoring without history
4. priority loyalty for risky accounts, including newly risky accounts discovered in this same cycle
5. rolling stale loyalty scan up to 200
6. final risk scoring with targeted history for history gate only
7. case dynamics.

Do not perform a full ~1784-account loyalty burst every 15 minutes without a separate load test. Risk accounts refresh every cycle; low-risk accounts use rolling scan.

**Server scheduler state is currently marked REVERIFY, not assumed ON/OFF.**

---

## 11. Anti-Fraud business rules / Risk v1.3

Risk is advisory-only, displayed 0–100, no automatic blocking.

Thresholds:

- critical: >=75
- high: >=50
- medium: >=25.

Key rules currently include:

- max accounts/device >=4: +45; 3: +35; 2: +40
- fast switch <=30s: +35; <=120s: +30; <=300s: +15
- repeated fast switches >=5: +20; >=3: +15; >=1: +5
- shared device count >=3: +15; 2: +10
- linked accounts >=3: +30; 2: +20; 1: +10
- repeated pair devices >=3: +40; 2: +30
- duplicate phone: +35
- duplicate email: +25
- both extra: +15
- linked visits <15m >=5: +50; >=3: +35; >=1: +20
- max visits/day >=5: +70; 4: +60; 3: +50
- repeated high-visit days gap2 >=5 sequence: +50; >=3: +35; >=2: +15
- current bonus balance strictly `>40000`: +50 and history gate.

Exactly `40000.00` does not trigger the high-balance rule.

Multiple active cards currently add **0 automatic risk points**.

After fleet balance backfill, verified active-account risk rows:

- active rows: 1784
- high: 13
- critical: 16
- history gate: 29
- non-v1.3 active rows: 0.

The risk SQL may still derive an old active-card count from legacy `anti_fraud_cards`; it is not used in scoring and can be cleaned up later.

---

## 12. Case dynamics / UI semantics

Case dynamics:

- Новый
- Усилился
- Без изменений
- Ослаб.

UI loyalty rendering target:

- count unknown -> `Карты: не загружено`
- count 0 -> `Активных карт: 0 · Бонусы: —`
- count 1 -> show current balance, including negative/zero
- count >1 -> warning, active-card count, and known account-level balance with aggregate wording such as `суммарно`
- exactly one + NULL -> `Баланс недоступен`.

Raw loyalty card numbers never appear in the Anti-Fraud UI/API.

Cosmetic follow-up: ensure money formatting consistently shows two decimals where desired.

---

## 13. Trusted Device foundation

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

Collector supports full link snapshots + incremental events and uses an advisory lock/snapshot guard.

---

## 14. Reference account — Nelli

- display name: Нэлля
- Bitrix USER_ID: `120445`
- active account
- total known card records: 4
- exactly one active state-113 card, three state-116 cards
- active card type: 28
- discount: 20%
- current balance at last detailed verification: `2438.89`
- total spend: `997590.83`
- today: `0.00`
- never expose full card number.

60-day protected history reference:

- visits: 83
- amount: `208319.71`
- bonus added: `48422.60`
- bonus spent: `50691.48`
- max single bonus spend: `8620.56`.

Risk v1.3 reference:

- overall 100
- critical
- history gate true
- history enriched true.

Do not claim Nelli exceeded 40k current balance; her risk is device/multiaccount driven.

---

## 15. Paused loyalty-card anomaly investigation — USER_ID 737384

**Status:** investigation deliberately paused by user on 2026-09-05. Do not continue it unless asked.

### What triggered the investigation

Anti-Fraud showed one account with **10 active state-113 loyalty cards** and another example with 0 active cards. The zero-card UI state was valid. The 10-card case required forensic review.

### Current RestIS / Bitrix state for 737384

Read-only RestIS `INFO` and Bitrix inspection confirmed exactly **10 current active cards** for the account phone:

- 1 old card: `CARDTYPE=25`, state 1 / Bitrix enum 113, `TAX_RATE=0`, UI mapping **«Евразия Клубная Красная»**, created in Bitrix 2021-05-28;
- 9 newer cards: `CARDTYPE=3`, state 1 / Bitrix enum 113, `TAX_RATE=50`, created in Bitrix on 2026-09-03.

The current RestIS card set and Bitrix owner-card set match 10/10. No raw full card numbers are stored in this document. Full card numbers were printed once to the operator on explicit request for external/manual verification only.

### Identification of the nine new cards

Static `activate_card.php` analysis proved:

- request `card_number` is sanitized and used as `$card['number']`;
- `$save_card_number = $card['number']`, so activation event `ITEM_ID` stores the short input number rather than the final full RestIS `CARD_NO`;
- `$card_type` is cast to integer;
- full number is constructed as `$card_full_number = $card_type . $card_number`;
- UI/input type `18` maps to RestIS `CARDTYPE=3` and the UI label **«Карта Евразия 50%»**;
- input type `03` also maps to RestIS `CARDTYPE=3` but, after integer cast, produces a different prefix/length corresponding to Super VIP.

For all nine new type-3 cards:

- full card length: 8 digits
- matched activation input length: 6 digits
- derived prefix: **18**
- `TAX_RATE=50`
- source mapping therefore identifies all nine as **«Евразия 50%»**, not Super VIP.

This was confirmed for all nine: `TYPE3_PREFIX_18_COUNT=9`, `TYPE3_PREFIX_3_COUNT=0`.

### Activation-event correlation

For USER_ID 737384, event log showed extreme traffic through `/local/php_interface/activate_card.php`:

- activation-related event count over 2026-09-02/03: **7567**
- distinct activation ITEM_ID values: **1843**
- first observed: 2026-09-02 20:13:50
- last observed: 2026-09-03 11:21:30.

Each of the nine new RestIS cards has an activation-event ITEM_ID that matches its unique six-digit suffix. The matching events also contain `CARDTYPE=3` in the safe request metadata.

Observed activation windows for the nine cards were approximately 11:07:20 through 11:17:41 on 2026-09-03.

Bitrix creation happened shortly afterward:

- first 2 new cards: `2026-09-03 11:11:48`
- next 7 new cards: `2026-09-03 11:21:47`.

### Legacy `account.php` evidence

File:

`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/account.php`

Observed source behavior:

- fetches current RestIS `INFO/CARDS/CARD` for the user's `PERSONAL_PHONE`
- maps RestIS `CARD_NO` to Bitrix iblock 14 property 34
- maps `CARDSTATE + 112` to Bitrix state enum
- maps `CARDTYPE`
- owner = current user
- existing card -> Update
- absent card -> Add
- no delete/ACTIVE=N reconciliation was found in this path.

Event-log correlation showed `account.php` `USER_EDIT` events at exactly the Bitrix creation timestamps above. Later mass `TIMESTAMP_X` updates also coincided with `account.php` activity.

### Anti-Fraud exclusion

The evidence does **not** implicate Anti-Fraud in creation of these cards:

- no Anti-Fraud path events in the relevant creation/update windows
- `AntiFraudLoyaltyService.php` has no card `Add`/`Update` writes
- Bitrix card creation timestamps correlate with legacy `account.php`
- RestIS itself already currently returns all 10 cards for the account phone.

Operational conclusion: Bitrix is mirroring what RestIS returns; the nine cards exist upstream in RestIS and are not invented by the Anti-Fraud protected read path.

### `activate_card.php` evidence and unresolved root cause

`activate_card.php` builds an activation request and calls `CRestis::request($request)`. The nine new cards correlate directly with activation request events from the authenticated 737384 session by input suffix, type and time.

There is also suspicious variable reuse in the multi-card response branch (`foreach ($cards as $card)` followed by a check involving `$card['number']` and `$card['_a']['CARD_NO']`), but that branch alone is not currently claimed as the cause of the nine upstream RestIS card creations.

**Strong forensic conclusion:**

`authenticated activate_card.php traffic -> RestIS has nine new type-3/prefix-18 50% cards -> legacy account.php later mirrors them into Bitrix`.

**Still unresolved:** what frontend/user/process produced thousands of activation calls and why so many card numbers were submitted/created upstream. Do not claim the exact initiating UI defect or human action until that caller is proven.

If investigation is resumed, next safe steps are:

1. inspect the frontend/JS caller(s) of `activate_card.php`;
2. inspect available web/access logs around 2026-09-03 11:07–11:22;
3. correlate request/session behavior without printing full card/phone values;
4. never replay the activation RestIS request.

### Current commercial/account values for this anomaly

Read-only balance response at investigation time:

- account current discount: **15.00%**
- current bonus balance: **733.75**
- total spend: **86134.21**.

The nine cards' nominal `TAX_RATE=50` / «Евразия 50%» identity and the account's **current 15% discount are separate concepts**. Do not infer that the account currently receives 50% merely because those cards exist.

---

## 16. Known source hashes from the paused forensic investigation

Useful only to detect source drift before resuming:

- `activate_card.php`: `739a68f48e42b53750b682b1287dfd8837ac9aeed8bd5515ee69053ca929e863`
- `account.php`: `19b86306de76e41c31e73bfce7763a2197118fd95a0a737263889b15cd8fe258`
- `CRestis.php`: `b4dc93ab2173b5d3f291249f4d1541188d995c16c4e7a488e7bb2bdc4bbb0714`
- `AntiFraudLoyaltyService.php` during later forensics: `44d14a246ba728c22354639d89ffeb1a20a6894797d34afd9c51200b2e7491c7`.

Re-hash before resuming; do not assume these files are unchanged forever.

---

## 17. RestIS event identity discovery

Nelli 60-day diagnostic proved:

- 83 history rows
- 72 unique source RestIS IDs
- 11 repeated source-ID groups
- 0 exact duplicate groups
- 83 unique full business signatures.

Conclusion: never treat raw RestIS `restis_id` as unique event identity.

---

## 18. Backup / rollback assets

Keep until explicit cleanup approval.

Known valid test DB backups:

- `/opt/evrasia-ai-bot/backups/samzaberu_antifraud_test-pre-balance-20260905_151021.dump`
  - SHA256 `459355958011f9b60133652092bf14a15f44e53b3a6be7d759d4bc0542cf1370`
- `/opt/evrasia-ai-bot/backups/samzaberu_antifraud_test-pre-0015-balance-20260905_145132.dump`
  - SHA256 `84dc3459c8adbe731ddbb9fadf4c006822673c0ee4a21cdcb5382329ca0c8589`
- `/opt/evrasia-ai-bot/backups/samzaberu_antifraud_test-pre-55ef0a5-20260905_123904.dump`
  - SHA256 `e9bfccc994b09f6a0e40daed34f5906d4ab0195971b98f56241f402a9cc797ce`
- `/opt/evrasia-ai-bot/backups/samzaberu_antifraud_test-pre-c443ea7-20260905_165200.dump`
  - SHA256 `7458ebb41d24963d5bdb4e94606bb043e4f59aa9344e7c7ceac9a92f21037330`
- `/opt/evrasia-ai-bot/backups/samzaberu_antifraud_test-pre-c443ea7-auth-20260905_165444.dump`
  - SHA256 `fb380b53778941cf8f75bc0d3b963f164fac675ff37edd434b2e7ce881d10c0a`.

Two c443ea7 deployment attempts failed safely before the later successful test deployment. One root cause was GHCR auth; another was a mount parser bug caused by TSV shifting when `.Name` was empty for bind mounts. Corrected deployment logic uses JSON-line mount capture and `--mount`.

---

## 19. Operator/server execution preference

For server work, provide **one complete bash block**, copied/run as a whole.

Required style:

- `clear`
- `set +e`
- `set +u`
- `set +o pipefail 2>/dev/null`
- variables at top
- numbered stages
- production guard before and after
- test guard before mutations
- backup before DB mutation
- rollback path
- explicit RC + PASS/FAIL
- `/tmp` temp files + cleanup
- no outer-shell `exit`; use functions + `return`
- no secret output
- finish `TERMINAL_WILL_STAY_OPEN=YES`.

For long scripts:

1. write a temporary script using a **quoted heredoc**;
2. `bash -n` it;
3. execute only when syntax check passes;
4. remove it afterward.

Use `umask 077` and restrictive temp-file permissions when environment/secrets may be present.

If Docker/PHP stderr is diagnostically useful, capture it to a restrictive temp file and redact sensitive values rather than discarding it.

A previous Node diagnostic failed with `node: -e requires an argument`; if `node -e` is used, the JS argument must be part of the complete executed script.

A previous forensic wrapper falsely reported PASS even though Bitrix emitted its generic HTML error while PHP returned RC=0. Future wrappers should explicitly detect Bitrix's generic error text in stdout in addition to process RC.

Also avoid malformed multiline shell conditions. Write, for example:

`if [ "$RC" -ne 0 ] || [ "$GENERIC_ERROR" -ne 0 ]; then`

on syntactically valid shell lines rather than splitting `[` / operands / `]` incorrectly.

Do not let a diagnostic script close the user's SSH terminal.

---

## 20. Important mistakes / stale assumptions to avoid

- Do not confuse active Bitrix accounts with active loyalty cards.
- Do not say 1438 means “at least one card”; it means exactly one active state-113 card.
- Do not say all 347 historical NULL balances mean no active card.
- Do not repeat the superseded 346-no-card assumption; the exact old classification is 240 no active + 106 multiple + 1 one-card balance unavailable.
- After the multicard site patch, do not force multi-card balance to NULL when account-level `TotalSum` is known.
- Do not sum `/api/Balance` once per card; it is an account/phone-level value.
- Do not add automatic Risk points for multiple active cards yet.
- Do not assume current bonus balance cannot be negative.
- Do not confuse `TAX_RATE=50` or a 50%-card identity with the account's current discount.
- Do not treat raw source `restis_id` as unique event identity.
- Do not bulk-load 60-day history.
- Do not copy RestIS credentials into the bot.
- Do not claim the paused card anomaly was caused by Anti-Fraud.
- Do not claim the exact reason thousands of `activate_card.php` calls occurred; that initiating cause is still unresolved.
- Do not persist full loyalty card numbers in this document.
- Do not assume scheduler current state without rechecking the server.
- Do not assume a successful GitHub workflow means the corresponding image is deployed.
- Do not change production without explicit approval.

---

## 21. NEXT STEP — current continuation point

The 737384 loyalty-card investigation is **PAUSED by user request**. Do not continue that forensic branch unless explicitly asked. External/manual verification of the temporarily obtained full card numbers may happen separately.

Return to the main v1.7 acceptance/deployment path.

### Before any next server mutation

1. Verify PR #32 remains open/Draft and determine the current branch HEAD.
2. Identify the latest immutable image for application-code commit `781834...` (or newer current application code) and its digest from GitHub Actions/GHCR.
3. Read-only verify on `eur-bot-01`:
   - production container/image/health and Anti-Fraud-table guard;
   - test container image/revision/health;
   - test DB migration count (expected target: 17 through 0016);
   - exact scheduler state;
   - manual refresh/scheduler status.
4. Do not rely on the unverified scheduler-pause attempt from the forensic investigation.

### Main v1.7 continuation

Once exact state is known:

1. Ensure TEST runs the intended latest immutable v1.7 candidate, never production.
2. Verify the `781834...` two-pass scheduler behavior so newly risky accounts receive loyalty in the same refresh cycle.
3. Run one controlled protected manual refresh if needed and verify all stages without RestIS credentials in the bot.
4. Verify UI/behavior for at least:
   - exactly one active card
   - no active card
   - multiple active cards with known account-level balance
   - zero balance
   - negative balance
   - exactly-one card with unavailable balance if a reference exists
   - newly risky account gets loyalty in the same refresh.
5. Confirm raw loyalty card numbers never appear in protected API/UI/logs.
6. Keep multiple-active-card state advisory-only with no automatic risk points.
7. Perform visual acceptance of `/antifraud` against the actual candidate image.
8. Keep PR #32 Draft until explicit visual/server approval.
9. Production remains untouched until explicit approval.

### Current important facts to retain

- active Bitrix accounts: **1784**
- exactly one active loyalty card: **1438**
- no active loyalty card: **240**
- multiple active loyalty cards: **106**
- fleet current-state unresolved USER_ID: **0**
- fleet 60-day history: **NOT loaded by design**
- Risk v1.3 after fleet balances: **DONE**
- loyalty resolution persistence migration 0016: **implemented**
- multicard account-level balance support: **implemented and live-tested**
- current application-code commit before this docs update: **781834632efc07d0b43af91cf1c67425777926bb**
- GitHub run #234 for that commit: **success**
- exact current TEST deployed revision: **REVERIFY**
- exact current TEST scheduler state: **REVERIFY**
- production changes during v1.7: **NONE at last verification**
- USER_ID 737384 card forensics: **PAUSED**.

---

## 22. Maintenance rule

Update this file after every material milestone, especially changes to:

- branch / PR / merge state
- application-code HEAD and immutable image
- test/production deployed image
- migration level
- DB/fleet state
- protected API behavior
- loyalty-card resolution semantics
- scheduler state
- backup/rollback state
- business/risk rules
- known/resolved forensic issues
- `NEXT STEP`.

`NEXT STEP` must always describe the actual continuation point, and any server fact not directly re-verified should be explicitly marked `REVERIFY` rather than guessed.
