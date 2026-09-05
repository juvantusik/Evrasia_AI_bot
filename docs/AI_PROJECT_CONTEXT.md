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

Do not rely on old chat memory for exact deployment state, image tags, migrations, rollback containers, DB status, or next action when this file and live GitHub/server state can be checked.

### Recommended new-chat prompt

Use this in a new chat:

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
7. Do not print service tokens, passwords, or secret values in diagnostics.
8. Keep rollback containers and DB backups until explicit cleanup approval.
9. Scheduler stays disabled until direct legacy `VIP_TODAY` dependency is replaced by a protected site-side source.
10. Full phone/email are intentionally visible to the Anti-Fraud operator for manual blocking workflows; do not re-mask without a business requirement change.

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

### Production

- container: `evrasia-ai-bot-app`
- image: `ghcr.io/juvantusik/evrasia_ai_bot:sha-8fd916f`
- health: healthy at last verification
- port: `127.0.0.1:18080 -> 8080`
- `/api/healthz`: 200 at last verification
- DB: `samzaberu`
- Anti-Fraud tables: `0`

### Test v1.7 — current deployed state

- container: `evrasia-ai-bot-v17-test`
- image: `ghcr.io/juvantusik/evrasia_ai_bot:sha-55ef0a5`
- revision represented by that image: `55ef0a522bbbcfe552480d3f222bacab46009e95`
- test DB: `samzaberu_antifraud_test`
- port: `127.0.0.1:18081 -> 8080`
- network: `evrasia-prod-internal`
- Telegram polling: disabled
- Anti-Fraud scheduler: disabled
- scheduler run-on-start: false
- `ANTI_FRAUD_AUTO_HISTORY=false`
- `ANTI_FRAUD_REFRESH_ACCOUNTS=false`
- RestIS credentials present in bot: **NO**

### Secret mounts (paths only; never print values)

- host: `/opt/evrasia-ai-bot/secrets/bitrix-api-token`
  - container: `/run/secrets/bitrix-api-token`
- host: `/opt/evrasia-ai-bot/secrets/anti-fraud-service-token`
  - container: `/run/secrets/anti-fraud-service-token`
- env points Anti-Fraud token file to `/run/secrets/anti-fraud-service-token`

### nginx preview routing

- production port 80 remains production.
- preview listener: `192.168.103.200:8081`
- `/antifraud` and relevant Anti-Fraud API routes go to test.
- only `/assets/antifraud-...` should go to test.
- do not proxy all `/assets` or all `/api` to test.

---

## 5. GitHub / CI state

### Pull request

- PR #32
- title: `Evrasia AI Bot v1.7 — Anti-Fraud investigation web UI`
- branch: `feature/v1.7-antifraud-web`
- base: `main`
- keep Draft.

### Last verified application-code image before this documentation commit

- source revision: `55ef0a522bbbcfe552480d3f222bacab46009e95`
- GHCR tag: `ghcr.io/juvantusik/evrasia_ai_bot:sha-55ef0a5`
- digest: `sha256:31cf50b2346fbc2fe2e877412118dd1fd7d528b5cf6722290a1060e13e7fc420`
- image ID: `sha256:ecdeef6377c3b36fde93835800a9937836f44f3cc55a7367a1910e65f0115017`
- manual workflow build #205: successful
- CI test total on that build: 54/54 passed

> Note: creating/updating this documentation file creates a newer branch commit. Always distinguish the **current branch HEAD** from the **application image revision currently deployed in test**.

### GHCR access on server

- root Docker config does not have GHCR auth.
- user `tech` has GHCR auth.
- when a private GHCR pull is needed while operating as root, use the existing `tech` Docker auth context, e.g. `runuser -u tech -- env HOME=/home/tech docker pull ...`.
- never copy the GitHub token into root config just to work around this.

---

## 6. Operator/server execution preference

For server changes, provide **one complete bash block** that is copied and run as a whole.

Required style:

- `clear`
- `set +e`
- `set +u`
- `set +o pipefail 2>/dev/null`
- variables at the top
- numbered `=== N. ... ===` sections
- pre-change production guard
- test guard
- backup before DB mutation
- rollback path
- post-change production guard
- explicit RC variables
- explicit `PASS` / `FAIL`
- do not print secrets
- use `/tmp` for temporary files and clean them
- do not use outer-shell `exit` because it previously closed the SSH terminal
- use shell functions + `return`
- finish with `TERMINAL_WILL_STAY_OPEN=YES`
- user pastes full output; analyze section-by-section before next change

Never claim a server/GitHub state changed without actual tool output or pasted server output.

---

## 7. Anti-Fraud business rules

### Risk scale

- displayed risk: 0–100
- current calculation version: `v1.3`

### Case dynamics statuses

- Новый
- Усилился
- Без изменений
- Ослаб

### Multiaccount/device behavior

Known baseline example:
- two accounts sharing a device may produce:
  - `shared_device_accounts` +40
  - `linked_accounts` +10
- total baseline 50, high, history gate true.

### Bonus threshold

Correct rule:
- exactly `40000.00` — no high-balance signal
- `40000.01+` — +50
- triggers history gate
- no automatic blocking
- DB storage must preserve decimal precision (`numeric(14,2)`).

### Card state rule

Only the **active loyalty card** is used for current loyalty state and history resolution.

RestIS states:
- `113` — Активна
- `114` — Недействительна
- `115` — Украдена
- `116` — Изъять

Current balance/history:
- use only state `113`
- ignore 114/115/116 for current loyalty state
- old cards may remain only for historical/audit purposes

`20` in loyalty data is discount percent, not bonus balance.

---

## 8. Protected site-side Anti-Fraud API

Site: `evrasia.spb.ru`

Protected internal routes currently include:

- `POST /api/internal/anti-fraud/card-map`
- `POST /api/internal/anti-fraud/account-map`
- `POST /api/internal/anti-fraud/trusted-device-export`
- `POST /api/internal/anti-fraud/loyalty`

They use the Anti-Fraud service token family. Never print the token.

### Correct architecture

Target identity graph:

`BITRIX USER_ID -> identity + Trusted Device hash + active RESTIS_STATE=113 loyalty card + current balance/history`

The bot must not need raw card numbers or RestIS credentials for the protected loyalty flow.

### Loyalty endpoint architecture

The protected loyalty endpoint resolves server-side:

`USER_ID -> active card state 113 -> RestIS balance/history`

Bot receives safe loyalty fields only.

Limits implemented in the bot gateway:
- current-state users per request: bounded
- history users per request: bounded
- history days: max 60
- HTTPS required
- raw `card_number` field is rejected if accidentally exposed

---

## 9. Trusted Device foundation

Existing site mechanism:

- permanent opaque `device_id` per app installation
- 32 cryptographically random bytes -> 64 lowercase hex chars
- regex: `^[a-f0-9]{64}$`
- not UUID, IMEI, MAC, advertising ID, or hardware ID
- one ID per app install regardless of account
- survives restart/update/logout
- uninstall/reinstall creates a new device ID
- server stores SHA-256 device ID hash
- trust TTL: 90 days
- plaintext trust token only transiently returned; hash stored server-side
- old clients remain compatible
- optional Trusted Device metadata must fail-open and must not break primary login
- IP is not identity/trust
- current Trusted Device version does not send SMS or decide SMS bypass

Protected export endpoint:
- `/api/internal/anti-fraud/trusted-device-export`

Do not create a second, competing device-identity system in the bot.

---

## 10. Database migrations relevant to v1.7

Current test DB after successful deployment has migrations through **0014**.

### 0013 — `anti_fraud_loyalty_decimal`

Key changes:

- `anti_fraud_accounts.bonus_balance`
  - integer -> `numeric(14,2)`
- new account fields:
  - `loyalty_synced_at`
  - `loyalty_history_loaded_from`
  - `loyalty_history_loaded_until`
  - `loyalty_history_loaded_at`
- `anti_fraud_visits.card_id` becomes nullable
- new visit fields:
  - `amount numeric(14,2)`
  - `bonus_added numeric(14,2)`
  - `bonus_spent numeric(14,2)`
  - `loyalty_verified boolean NOT NULL DEFAULT false`

### 0014 — `anti_fraud_restis_event_identity`

Reason: real RestIS history proved that source `restis_id` is **not unique per business operation**.

Model:
- raw RestIS ID stored separately as `source_restis_id`
- `source_restis_id` is intentionally **not unique**
- bot creates a stable unique internal event ID from the full event identity, including:
  - source RestIS ID
  - timestamp
  - restaurant
  - amount
  - bonus added
  - bonus spent
- different monetary operations may share the same source RestIS ID
- an exact duplicate event is still rejected/deduplicated

---

## 11. Important RestIS event-identity discovery

Live 60-day diagnostic for Nelli proved:

- history rows: `83`
- unique source RestIS IDs: `72`
- duplicate source-ID groups: `11`
- duplicate extra rows: `11`
- exact duplicate groups: `0`
- conflicting duplicate groups: `11`
- timestamps differed in duplicate groups: `0`
- restaurant differed: `0`
- amount differed: `11`
- bonus added differed: `11`
- bonus spent differed: `3`
- unique full business signatures: `83`

Conclusion:

**`restis_id` must never be treated as the unique identity of a loyalty-history operation.**

After migration 0014 and importer fix, test DB correctly stored:

- verified rows: `83`
- unique internal event IDs: `83`
- unique source RestIS IDs: `72`
- repeated source-ID groups: `11`

---

## 12. Known reference account: Nelli

Reference test subject:

- display name: Нэлля
- Bitrix user ID: `120445`
- account active

Known current loyalty state at last verification:

- active card found: yes
- active card count: 1
- active card state: 113
- card type: 28
- discount percent: 20
- current bonus balance: `2438.89`
- total spend: `997590.83`
- today sum: `0.00`

Do not expose the full card number.

### 60-day loyalty history at last verification

- visits: `83`
- amount: `208319.71`
- bonus added: `48422.60`
- bonus spent: `50691.48`
- max single bonus spend: `8620.56`

### Nelli risk at last successful test deploy

- calculation version: `v1.3`
- overall risk: `100`
- level: `critical`
- history gate: true
- history enriched: true

Important: there is **no evidence that Nelli currently has or previously had > 40,000 bonus balance**. Her history gate is justified by device/multiaccount behavior, not the high-balance threshold.

---

## 13. Risk engine v1.3

Key changes already implemented:

- decimal bonus parsing preserved
- `bonus_balance > 40000.00` is the exact threshold
- `40000.01` triggers the high-balance reason
- account-level protected-loyalty coverage determines history enrichment
- auto-history eligibility no longer requires a card already present in bot DB
- protected loyalty endpoint determines the active state-113 card
- loyalty-verified visits count as current valid visits
- account-map is identity-only and no longer overwrites `bonus_balance`

Last test risk recalculation:

- scored accounts: `1786`
- medium: `0`
- high: `7`
- critical: `16`
- history-gate accounts: `23`
- history-eligible accounts: `22`
- all score rows were `v1.3`

---

## 14. Current loyalty-balance architecture

### Account-map

`account-map` is deliberately **identity-only**.

It must not set or overwrite current loyalty bonus balance.

### Current balance service

A separate protected loyalty balance service exists.

Design:
- resolve balance by Bitrix USER_ID through protected site-side loyalty API
- update exact decimal `bonus_balance`
- update `loyalty_synced_at`
- bounded batches
- avoid hammering RestIS
- no raw cards
- no RestIS credentials in bot

### History service

60-day history is **not for every user**.

Correct policy:
- current bonus balance: should be available for all active Anti-Fraud accounts where an active loyalty card can be resolved
- 60-day detailed history: only for accounts that cross the Anti-Fraud history gate

---

## 15. Current visible problem discovered during UI review

On the Anti-Fraud case UI, Nelli showed a current balance, but many other accounts showed:

`Бонусы: —`

This is not intended final behavior.

Cause:

- the architecture for protected current balance exists;
- Nelli was used as the full end-to-end reference test;
- scheduler is intentionally disabled;
- a one-time initial balance backfill for all active Anti-Fraud accounts was **not run before visual review**.

Therefore the UI review was started too early.

This is a missed rollout step, not a new product requirement.

---

## 16. NEXT STEP — current continuation point

### Goal

Populate current loyalty bonus balances for the full active Anti-Fraud account set in **test only**, without loading 60-day history for everyone and without enabling the scheduler.

### Required approach

1. Verify production remains unchanged.
2. Verify current test image/DB/migrations.
3. Take a fresh test DB backup before mass updates.
4. Run a **safe bounded initial balance backfill** over all active Anti-Fraud accounts using the protected loyalty endpoint.
5. Use batches/chunks; do not send thousands of users in one request.
6. Do not copy RestIS credentials into the bot.
7. Do not expose raw card numbers.
8. Do not load detailed 60-day history for all 1786 accounts.
9. After backfill, report:
   - total active Anti-Fraud accounts considered
   - successfully resolved loyalty users
   - users without an active state-113 card / unresolved
   - users with a stored balance
   - users still with NULL balance
   - users with balance exactly 0.00
   - users with balance > 40,000.00
   - max observed balance (safe aggregate; no card data)
10. Recalculate Risk v1.3 with account refresh/history automation still disabled as appropriate.
11. Confirm high-balance rule now operates across all accounts with known balances.
12. Re-open UI visual review only after this backfill is validated.
13. Final production guard.
14. Keep backup and rollback state.

### Important distinction

- **Balance backfill for all active accounts:** YES.
- **60-day history backfill for all accounts:** NO.
- **Scheduler enablement:** NO.

---

## 17. Scheduler caveat / unresolved architecture item

The hourly pipeline still contains a legacy direct `RestIS VIP_TODAY` current-visit stage that requires RestIS credentials if enabled.

The test container deliberately has no RestIS credentials and scheduler is disabled.

Do not solve this by copying RestIS credentials into the bot.

Correct future direction:
- add a protected site-side current-visit source/proxy (similar to the protected loyalty endpoint), then remove the bot's direct RestIS dependency.

Until that is done:
- scheduler remains disabled in test and production for the Anti-Fraud flow.

---

## 18. Rollback / backups to retain

Do not delete yet.

### Current most relevant rollback container

- `evrasia-ai-bot-v17-test-pre-55ef0a5-20260905_123904`

### Current most relevant DB backup

- `/opt/evrasia-ai-bot/backups/samzaberu_antifraud_test-pre-55ef0a5-20260905_123904.dump`
- SHA256: `e9bfccc994b09f6a0e40daed34f5906d4ab0195971b98f56241f402a9cc797ce`

### Older rollback state also retained

Examples retained from earlier stages include:
- `evrasia-ai-bot-v17-test-pre-8720c17-20260905_121341`
- `evrasia-ai-bot-v17-test-pre-secret-20260905_104219`
- `evrasia-ai-bot-v17-test-pre-20260905_091931`

Older DB backups also exist. Do not clean them up until test acceptance is complete.

---

## 19. Last successful end-to-end v1.7 test deployment result

At the end of the successful `sha-55ef0a5` test deployment:

- test container healthy
- migrations 0013 + 0014 PASS
- bonus balance type: `numeric(14,2)`
- Nelli current loyalty PASS
- Nelli 60-day history PASS
- 83 loyalty events imported
- 83 unique internal event IDs
- 72 unique source RestIS IDs
- 11 repeated source ID groups
- current Nelli balance DB: `2438.89`
- Risk v1.3 recalculation PASS
- Nelli risk: `100 critical`
- all score rows v1.3
- direct Anti-Fraud page HTTP 200
- nginx preview HTTP 200
- Anti-Fraud summary HTTP 200
- production remained on `sha-8fd916f`, healthy
- production Anti-Fraud tables remained `0`
- secrets not printed

---

## 20. Important mistakes to avoid repeating

- Do not confuse a successful reference-account test with completion of fleet-wide rollout.
- Do not begin final UI acceptance before full current-balance backfill has run.
- Do not say Nelli has 7 cards. The live site check found 4 total records, only one active state-113 card.
- Do not use an old guessed loyalty card number.
- Do not confuse discount `20` with bonus balance.
- Do not claim the 0.01 loyalty reconciliation difference cause; it was not proven.
- Do not claim Nelli ever exceeded 40k bonuses.
- Do not treat source `restis_id` as unique.
- Do not use blocked/invalid cards for current loyalty state.
- Do not depend on VIP_TODAY to discover the active card for protected history.
- Do not copy RestIS credentials to the bot.
- Do not enable scheduler yet.
- Do not change production during v1.7 test work without explicit approval.

---

## 21. Maintenance rule for this file

Update this document after every material milestone, especially when any of these change:

- current branch / PR / merge state
- deployed test image
- production image
- migration level
- database schema
- protected API architecture
- rollback container
- backup path/hash
- business rule
- risk calculation version
- known issue
- resolved issue
- `NEXT STEP`

The `NEXT STEP` section must always describe the actual continuation point, not merely the long-term roadmap.
