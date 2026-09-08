# Evrasia — New Chat Handoff

> Fast handoff for continuing Evrasia AI Bot in a new ChatGPT chat.
>
> **Updated: 2026-09-08** after production acceptance of PR #41.

## Ready-to-paste instruction for a new chat

Продолжаем проект Evrasia AI Bot. Не начинай работу заново и не проси меня повторять уже установленный контекст.

Репозиторий: `juvantusik/Evrasia_AI_bot`.
Production source baseline: current `main`, но deployed application identity проверяй отдельно: docs-only commits могут быть новее production app revision.

Сначала полностью прочитай:

1. `docs/PROJECT_CHECKPOINT.md` — самый свежий authoritative checkpoint;
2. `docs/AI_PROJECT_CONTEXT.md` — текущий проектный/технический контекст;
3. `docs/CURRENT_ARCHITECTURE.md` — актуальная архитектура;
4. `docs/SERVER_SCRIPT_RULES.md` — обязательные правила серверных скриптов;
5. `SERVER_UPDATES.md` — фактическая история production/server updates;
6. `docs/NEW_CHAT_HANDOFF.md` — этот handoff.

Приоритет источников: **production actual state → current GitHub → staging/test → current docs → older discussion**. Не повторяй уже завершённые проверки и deployment-шаги.

---

## 1. Current production baseline

Host: `eur-bot-01` (`192.168.103.200`).

Current deployed app:

- revision: `a156db2e30dd2a31d7bd4126410f9f513382adaa`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:ce3b85fe789495f3b5ee4e59fe8eb129a75343d1916f2a7c45483da18d988947`
- image config: `sha256:cbe989d6375189f9f12d7a0ad6f74f9f5455536838750fd96f0e2e459d9cc145`
- app: `evrasia-ai-bot-app`
- DB: `evrasia-ai-bot-db`
- DB name: `evrasia_ai_bot`
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- app port: `127.0.0.1:18080`
- migrations: **20**
- latest migration timestamp: `1788769200000`

Latest deployment backup:

`/opt/evrasia-ai-bot/backups/pr41-inactive-ui-20260908-110846`

Deployment result:

- app-only recreate
- DB container unchanged / not restarted
- schema unchanged
- env/mounts/ports preserved
- scheduler enabled/idle/15m
- main routes healthy
- `/directory` remains 404
- `PASS_COUNT=29`, `FAIL_COUNT=0`, `ROLLBACK_ATTEMPTED=NO`, `FINAL_STATUS=PASS`

Main CI #298 / run ID `34202375971` passed 70/70 tests and published the exact immutable image above.

---

## 2. Anti-Fraud milestone — DONE / PRODUCTION ACCEPTED

Relevant merged PRs:

- #36 blocking status/operator actions → `947c815d...`
- #37 blocking UI/unblock/group bonus → `86c98eac...`
- #38 identity-similarity performance → `971af94e...`
- #41 inactive operational exclusion + localization → `a156db2e...`

Do not treat these as pending work.

### Status semantics

Bitrix is source of truth:

- `ACTIVE=Y`, `BLOCKED=N` → **Активен**
- `ACTIVE=N`, `BLOCKED=N` → **Неактивен**
- any `BLOCKED=Y` → **Заблокирован**

Only `BLOCKED=Y` is a true Bitrix block.

Operational UI after PR #41:

- both blocked and inactive are hidden from ordinary lists by default;
- toggle: **`Показать заблокированных и неактивных`**;
- inactive stays visually **Неактивен**, not `Заблокирован`;
- KPI/shared-device/duplicate-contact operational summaries exclude both;
- group bulk block targets active unblocked accounts only;
- group bonus/risk evidence still includes all accounts in the case;
- an inactive account can still be individually/formally blocked when explicitly chosen.

Operator visually confirmed this in production on 2026-09-08: **«все отрабатывает»**.

### Localization

Raw Anti-Fraud reason detail keys must not appear in operator UI.

Confirmed production translation includes:

- `max_devices_for_same_pair=2` → `макс. общих устройств для одной пары аккаунтов: 2`
- `matching_other_accounts`
- `max_gap_days`
- `similar_phone_links`
- `similar_email_links`

The operator screenshot/confirmation showed the Russian wording working in production.

---

## 3. Blocking / unblock acceptance — completed

Manual block/unblock is production-proven.

Bitrix protected routes:

- `POST /api/internal/anti-fraud/block`
- `POST /api/internal/anti-fraud/unblock`

Bot routes:

- `POST /api/anti-fraud/block`
- `POST /api/anti-fraud/unblock`

Approved public reason remains fixed and must not expose technical detection details.

Safe test USER_ID `880339` completed a controlled backend round-trip:

`ACTIVE=Y/BLOCKED=N` → block `ACTIVE=N/BLOCKED=Y` → unblock `ACTIVE=Y/BLOCKED=N`.

Final acceptance:

- 27 PASS / 0 FAIL / 0 WARN
- state restored exactly
- reason preserved
- two audit transitions only
- no risk/history/bonus mutation
- no real customer mutation

**Do not repeat this round-trip.**

Important fixture lesson: USER_ID 880339 is risk-0 and legitimately has no case. Do not mutate a real customer or fabricate production risk data just to force a blocked-card visual fixture. Backend semantics are verified; live blocked-card rendering on a safe eligible risky fixture was not manufactured and is not falsely claimed.

---

## 4. Similarity performance — completed

PR #38 removed exhaustive all-pairs similarity scanning while retaining final evaluator semantics.

Production acceptance:

- before: protected cycle 206 s
- after: protected cycle 53 s
- health: 24/24 HTTP 200, max 3 ms
- scheduler: 24/24 HTTP 200, max 6 ms
- app restart count 0

`anti_fraud_risk_scoring` ~3.1 s is not the similarity-hotfix performance gate because that timer does not include the similarity overlay.

**Do not rerun the performance refresh unless a future code change requires it.**

---

## 5. Current product architecture

One production app/container contains:

1. Phonebook — `/phonebook`
2. Anti-Fraud — `/antifraud` + 15-minute scheduler
3. SamZaberu — Telegram scenario inside `EvrasiaTelegramBotV2`
4. Corporate communications / MegaFon — Telegram scenario/workflow inside the same application

`/directory` and `/api/directory/...` are removed and expected to return 404.

Legacy TEST container `evrasia-ai-bot-v17-test` is exited/archival. Do not restart blindly.

---

## 6. Anti-Fraud invariants not to lose

- advisory risk; no automatic blocking
- critical >=75, high >=50, medium >=25
- current bonus strictly >40000.00 → +50 and history gate
- multiple active cards alone add no automatic risk
- account balance is not multiplied by card count
- zero ≠ missing; negative current balance is valid
- 60-day history is targeted after gate, never fleet-wide
- grouping evidence is separate from risk evidence; behavior alone does not group
- candidate index must preserve final identity evaluator semantics
- Trusted Device is app install/trust identity, not IP/hardware identity
- bot must not receive RestIS credentials
- raw loyalty card numbers must not appear in bot UI/API/logs

---

## 7. Mandatory server-script behavior

Read `docs/SERVER_SCRIPT_RULES.md` before any server work.

Key rules:

- one complete copy/paste block
- starts with `clear`, `set +e`, `set +u`, `set +o pipefail 2>/dev/null`
- guards are architecture-aware and operation-specific
- no unrelated Telegram/RestIS gates for Anti-Fraud-only work
- use existing GHCR auth under user `tech`
- stage current Compose under `/opt/evrasia-ai-bot/prod`, not `/tmp`, when relative paths exist
- scheduler race → bounded wait, not immediate false failure
- async 202 + later timeout is not job failure; persisted run state is authoritative
- validate fixture eligibility before mutation-driven acceptance
- never choose a real customer merely to make a UI fixture convenient
- backup/rollback/verification required for production mutations
- no secrets in output
- terminal stays open

---

## 8. Immediate continuation point

Anti-Fraud blocking/inactive/localization/performance work is **complete and accepted**.

Do not automatically resume:

- USER_ID 880339 block/unblock testing
- performance refresh measurement
- old PR #34/#36/#37/#38/#41 implementation work
- archival TEST
- paused full-Bitrix email investigation

Start the next engineering iteration only from a new user requirement. Before changing code or production, inspect current `main`, relevant current files/PRs/CI and factual production state.
