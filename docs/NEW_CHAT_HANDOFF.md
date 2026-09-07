# Evrasia — New Chat Handoff

> Ready handoff for continuing the Evrasia AI Bot project in a new ChatGPT chat. Read together with `docs/AI_PROJECT_CONTEXT.md` and `docs/SERVER_SCRIPT_RULES.md`.

## Ready-to-paste instruction for a new chat

Продолжаем проект Evrasia AI Bot. Не начинай работу заново и не проси меня повторять уже установленный контекст.

Репозиторий: `juvantusik/Evrasia_AI_bot`.
Рабочая ветка: `feature/v1.7-antifraud-web`.
PR: #32. Он должен оставаться Draft, пока я явно не разрешу Ready/merge.

Сначала прочитай в этой ветке:

1. `docs/AI_PROJECT_CONTEXT.md` — актуальный технический source of truth;
2. `docs/SERVER_SCRIPT_RULES.md` — обязательные правила серверных скриптов;
3. `docs/NEW_CHAT_HANDOFF.md` — этот handoff.

После этого проверь PR #32/HEAD, если задача связана с GitHub. Не путай documentation-only HEAD с фактически deployed application revision.

---

## 1. Текущая точка проекта

**Evrasia AI Bot v1.7 Anti-Fraud уже развернут в production и прошел post-production audit.**

Последний аудит:

- 34 PASS
- 0 WARN
- 0 FAIL
- `POST_PRODUCTION_AUDIT=PASS`
- `PRODUCTION_V17_VERIFIED=YES`.

Поэтому **не повторять deployment, миграцию 4→18, nginx cutover или переименование PostgreSQL-инфраструктуры** без новой причины.

---

## 2. Exact production baseline

Host:

- `eur-bot-01`
- `192.168.103.200`
- Debian 13
- Docker 26.1.5
- Compose 2.26.1-4.

Application:

- container: `evrasia-ai-bot-app`
- deployed revision: `109ac7a2a05c0289336b3b332cb09ca102253396`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:381e9d34e3ecd65e814cc93b2c0b91656bc9155a5437e86ea93c1a7f34bceffc`
- image ID: `sha256:ee94f6b17d4dc9f727266675d05fd3dd1ec992f30850b351d96dbca29a37e628`
- status at audit: running / healthy
- port: `127.0.0.1:18080 -> 8080`.

PostgreSQL:

- container/service: `evrasia-ai-bot-db`
- Compose project: `evrasia-prod`
- role: `evrasia_ai_bot`
- production DB: `evrasia_ai_bot`
- retained test DB: `evrasia_ai_bot_antifraud_test`
- network: `evrasia-prod-internal`
- volume: `evrasia-postgres-prod-data`
- production migrations: 18
- test DB migrations: 18
- Anti-Fraud tables: 11.

Legacy infrastructure is gone:

- role `samzaberu`: absent
- DB names `samzaberu` / `samzaberu_antifraud_test`: absent
- container/service `samzaberu-db`: absent
- Compose refs to `samzaberu-db`: 0.

Important: `public.samzaberu_requests` is **real SamZaberu business data**, not legacy infrastructure. It intentionally remains and had 31 rows at audit.

---

## 3. Anti-Fraud production runtime

Verified:

- scheduler enabled: true
- interval: 15 minutes
- run-on-start: false
- latest protected cycle: success
- scheduler last error: none
- Telegram polling: true
- Telegram 409 conflicts: 0
- both Bitrix and Anti-Fraud secret mounts present/readable/non-empty
- no secret values should ever be printed.

Nginx:

- `/antifraud`, `/api/anti-fraud/`, Anti-Fraud assets all target production 18080
- references to TEST 18081: 0
- nginx config valid
- routed phonebook/antifraud/directory checks passed.

`/directory` is intentionally a redirect:

- `/directory` -> 308 -> `/phonebook`
- `/directory/` -> 308 -> `/phonebook/`
- final HTTP after redirect: 200.

Do not flag that verified redirect as an error.

---

## 4. TEST state

Old container `evrasia-ai-bot-v17-test` is exited and archival only.

**Do not restart it blindly.** Its environment belongs to the old test topology, while the retained test DB has already been renamed to `evrasia_ai_bot_antifraud_test`.

If a new TEST is needed later, recreate it intentionally from the current naming/topology.

---

## 5. Backups to keep

Do not clean without explicit approval.

Phase 1:

`/opt/evrasia-ai-bot/backups/production-v17-phase1-20260907-053318`

Production dump SHA256:

`8cf697c2faa5010d12cb9389aac1ecd38929d1672ff1bdd432bad5df5c45e14a`

Phase 2:

`/opt/evrasia-ai-bot/backups/production-v17-phase2-dbrename-20260907-054218`

- production dump SHA256: `6dfd1b8f0d3d30857ac3c7a06d29f05e38ccdccb4b86db5a0862780b14bb56f1`
- test dump SHA256: `bd862bc8445c632face19b4d96e23edc49d1c2385c5f05481be5d40a3a14327c`

Both dump hashes/readability were verified in the post-production audit.

---

## 6. Anti-Fraud business contracts not to lose

- advisory-only; no automatic account blocking
- Risk range 0–100
- critical >=75
- high >=50
- medium >=25
- current bonus balance strictly >40,000 gives +50 and history gate
- exactly 40,000 does not trigger that rule
- multiple active cards are visible anomaly but add 0 automatic risk points
- `TotalSum` is account/phone-level current balance; never multiply/sum it per card
- `0.00` is known zero; NULL is unavailable/unknown
- raw loyalty card numbers must not appear in bot UI/API/logs
- RestIS credentials must not be copied into the bot
- detailed 60-day history is targeted only to history-gated accounts, never fleet-loaded every cycle.

Fleet reference from 2026-09-05:

- active Bitrix accounts: 1784
- exactly one active state-113 card: 1438
- no active card: 240
- multiple active cards: 106
- unresolved: 0.

---

## 7. Trusted Device foundation

- `device_id`: 32 cryptographically random bytes -> 64 lowercase hex
- regex `^[a-f0-9]{64}$`
- not UUID/IMEI/MAC/advertising ID/hardware identifier
- stable for one installation through restart/update/logout
- reinstall creates a new ID
- server may store SHA-256 hash
- trust TTL: 90 days
- IP is not identity/trust.

---

## 8. Paused forensic branch

USER_ID 737384 loyalty-card anomaly investigation remains **PAUSED** by user request.

Safe conclusion already established:

- Anti-Fraud did not create the nine newer cards
- RestIS already contained them
- legacy `account.php` mirrored them into Bitrix
- thousands of activation requests were observed
- exact initiating frontend/user/process cause remains unresolved.

Do not resume without explicit request. Never replay activation requests and never persist full card numbers in documentation.

---

## 9. Mandatory server-script format

Before server work read `docs/SERVER_SCRIPT_RULES.md`.

Every normal server wrapper must use one complete copy-paste block and the real script must begin with:

```bash
clear
set +e
set +u
set +o pipefail 2>/dev/null
```

The user explicitly requested `clear` so previous terminal output is visually removed.

Also required:

- variables at top
- numbered stages
- hostname/environment guards
- backup before risky DB mutation
- rollback
- quoted heredoc + `/tmp` wrapper for long scripts
- `bash -n` before execution
- explicit PASS/FAIL and final RC
- no secret output
- terminal must remain open.

---

## 10. GitHub / PR policy

PR #32 is still expected to remain:

- open
- Draft
- not merged

until explicit user approval.

The deployed production application revision remains `109ac7a...` even if documentation commits move the branch HEAD afterward.

Do not mark Ready or merge automatically just because production passed audit.

---

## 11. Current continuation point

**There is no pending production mutation.**

Next actions:

1. Keep production unchanged.
2. Keep rollback backups and archival TEST assets.
3. Finish documentation updates.
4. Then wait for the user's explicit decision about PR #32:
   - keep Draft for more business/visual acceptance, or
   - mark Ready/merge if explicitly approved.
5. For any new feature, start from the verified production v1.7 baseline; do not repeat migration/deployment work.

Parallel Bonus Club legal work exists in the project, but it is separate from this technical continuation. If the user switches back to legal work, restore the latest legal documents/context rather than using old handoff text.