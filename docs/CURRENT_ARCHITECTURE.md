# Evrasia AI Bot — Current Architecture

> Canonical current architecture for module naming, runtime topology and new-chat recovery.
>
> Last updated: **2026-09-23** after PR #67 browser-visible acceptance and legacy physical-snapshot backfill.

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

#### Manual investigation by phone

PR #60 is production.

Operator action: **«Добавить на проверку»**.

Flow:

`phone → protected Bitrix resolver → USER_ID → persistent operator investigation → explicit 60-day history → normal Anti-Fraud scoring`

Operator source/reason remains separate from automatic evidence. Ambiguous phone never selects a USER_ID. Manual authorization does not fake `riskGateConfirmed`. Accounts remain visible even when automatic Risk is 0. There is no automatic blocking.

PR #62 adds the operator-visible result layer: exact latest 60-day investigation window, physical visit/day/restaurant counts, first/last event, daily summary, Trusted Device prefix and linked USER_ID values when present. The five existing numeric categories remain risk scores and are explicitly labeled as such.

PR #65 changes the source of operator physical-history metrics to a dedicated per-investigation Check-in snapshot:

`targeted Check-in → anti_fraud_operator_investigation_visits → operator UI metrics`

The parent investigation persists `physical_history_from` / `physical_history_until`. Targeted responses with unresolved cards fail closed.

PR #67 completes the browser-visible layer: rendering of the physical-history panel depends on the PR #65 snapshot completion fields and no longer depends on legacy `loyaltyHistoryLoadedAt`. Five legacy latest-ready investigations created before snapshot persistence were backfilled; all seven current latest-ready investigations now have physical coverage. Browser visual acceptance is PASS.

Critical invariant:

`anti_fraud_operator_investigation_visits` is operator evidence and **must not** feed `anti_fraud_visits`. The latter remains automatic risk/history telemetry.

Current production revision after PR #67: `b0d12a112577de2a35e0a49e55367e3bc459bc07`.

Current production immutable digest: `sha256:a9545807cf8b09c0a159e6d7bf8b3a1850ee5a7356966826c4d10a25bbf98767`.

Current image config ID: `sha256:44916797485a87a94ead3e4cfc8445727b0a1752c08d9fa81123dd5172ae34a1`.

Current image config ID: `sha256:34e3c50395b0a34a3b8efe044fc1ad6e7b90771824449a38354babaefdea451c`.

Production migrations: **26**.

Authoritative acceptance record: `docs/ANTI_FRAUD_PR65_PRODUCTION_ACCEPTANCE_2026-09-23.md`.

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

#### Check-in Scout architecture

Check-in Scout is a separate upstream physical-frequency detector.

```text
Bitrix VIP_TODAY / offline-order store
           |
           v
protected /api/internal/anti-fraud/checkins
           |
           v
bot Scout snapshot (max 3 days)
           |
           +--> 1/day: discard from persistent Scout state
           |
           +--> 2+ same Moscow day: WATCH
                    |
                    +--> 3rd same day or later WATCH day with 2+
                              |
                              v
                    targeted 60-day physical history
                              |
                              v
             persistent frequency confirmation
                              |
                              v
                      Risk 100 / Critical
```

The Scout physical source is not `VIP_HISTORY`. `VIP_HISTORY` can contain multiple monetary/event rows for one physical visit.

Current confirmation rule in code:

- 3 different days with 2+ physical check-ins in the last 7 days; OR
- 3 different days with 3+ physical check-ins in the last 60 days.

No automatic block occurs.

PR #58 changed only the first operator-facing label to `дней с 3 чекинами за 7 дней`; the backend `days_2plus_7d >= 2` calculation remains unchanged.

#### Manual investigation by phone

The site-side protected resolver is already production:

`POST /api/internal/anti-fraud/phone-resolve`

Resolution architecture:

`phone → normalize → FULLTEXT SEARCH_ADMIN_CONTENT candidate lookup → exact normalized b_user.PERSONAL_PHONE verification → 0/1/many`

Response contract:

- unique → 200;
- invalid → 400;
- not found → 404;
- ambiguous → 409 and no USER_ID selection;
- resolver/candidate-limit problem → 503.

The bot-side operator workflow **«Добавить на проверку»** is the next implementation step. It requires persistent operator-investigation state and an explicit operator-authorized 60-day history path. Do not fake the existing automatic `riskGateConfirmed` requirement.

#### Trusted Device case-display semantics

Case backend separates:

- `devices`: shared device hashes used as linking/grouping evidence;
- `trustedDevices`: all Trusted Device hashes attached to case accounts, including single-account hashes.

UI labels after PR #58:

- one linked account → **Устройство**;
- multiple linked accounts → **Общее устройство**.

Both are the same hash type. A single-account hash becomes shared grouping evidence if a second USER_ID later appears on the same hash and the next sync/scoring cycle ingests that relation.

#### SamZaberu mobile -> Trusted Device

SamZaberu **mobile application** is a separate client source from the Telegram scenario described below.

Production-accepted mobile identity flow on 2026-09-24:

```text
SamZaberu mobile
  -> explicit auth (PASSWORD / SMS / MOBILE_ID)
  -> TrustedDeviceMobileService
  -> external installation ID: sz_ + 64 lowercase hex
  -> SHA-256 over the full namespaced value, including sz_
  -> 64-hex core identity
  -> existing TrustedDeviceService
  -> ev_trusted_devices
  -> protected Trusted Device export
  -> Anti-Fraud collector
  -> anti_fraud_device_links / anti_fraud_device_events
```

Current production mobile adapter:

- website host: `evrasia`;
- file: `/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/lib/Services/TrustedDeviceMobileService.php`;
- SHA256: `c59d2a9e1b70aa026b603b457673a842dbaa6b784eae25b40c5e03684b9e612d`.

The shared `TrustedDeviceService` remains unchanged and keeps its 64-hex opaque-ID contract. Browser Trusted Device behavior therefore remains intact.

Safe production USER_ID `880339` was accepted end-to-end: one new SamZaberu iOS Trusted Device row was created on the site, then one matching current link plus two auth events were ingested by the bot. Exact acceptance evidence and rollback path are recorded in `docs/SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md`.

Current bot-side `client_type` for that accepted SamZaberu link/event is `NULL`. This is a non-blocking metadata/display follow-up, not an identity-ingestion failure.

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
- production migrations: **24**
- latest implemented migration tag in the current Anti-Fraud stream: `0023_anti_fraud_checkin_scout`.

Legacy TEST container `evrasia-ai-bot-v17-test` is exited/archival. Do not restart it blindly.

## 6. Current production release identity

Current accepted deployed application after PR #58 on 2026-09-20:

- deployed revision: `700422b3c9004c2d92092a166e50ac5e8e8a6d33`
- immutable digest: `sha256:b9ef12f9ea198c31d253ff9e07821c9c2aaa3aaa98fc286c0322c6c2534f5348`
- image ID: `sha256:700a55f7cc915f4945a65955c06f65c2a739be98678fb2fd963cd50edfa5564d`
- production migrations: **24**
- app status after deployment: running / healthy
- backup: `/opt/evrasia-ai-bot/backups/pr58-ui-labels-continuation-20260920-084234`
- deployment result: 7 PASS / 0 FAIL / rollback not required.

PR #58 was application/UI-only relative to the preceding production DB state; migration count remained 24.

Two earlier PR #58 attempts failed safely before mutation because a temporary Compose file was staged outside the production Compose directory. The accepted deployment confirmed that relative-path Compose must be staged/validated in `/opt/evrasia-ai-bot/prod`.

Later documentation-only commits may advance GitHub `main`; they do not by themselves change the deployed application identity above.

Full current Anti-Fraud handoff: `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md`.

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
