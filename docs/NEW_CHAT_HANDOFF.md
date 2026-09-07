# Evrasia — New Chat Handoff

> Fast handoff for continuing the Evrasia AI Bot project in a new ChatGPT chat. Read together with `docs/AI_PROJECT_CONTEXT.md`, `docs/CURRENT_ARCHITECTURE.md` and `docs/SERVER_SCRIPT_RULES.md`.

## Ready-to-paste instruction for a new chat

Продолжаем проект Evrasia AI Bot. Не начинай работу заново и не проси меня повторять уже установленный контекст.

Репозиторий: `juvantusik/Evrasia_AI_bot`.
Актуальная исходная ветка: `main`.
PR #32 и PR #33 уже слиты в `main`.

Сначала прочитай из `main`:

1. `docs/AI_PROJECT_CONTEXT.md` — главный технический source of truth;
2. `docs/CURRENT_ARCHITECTURE.md` — текущая архитектура и правильные названия модулей;
3. `docs/SERVER_SCRIPT_RULES.md` — обязательные правила серверных скриптов;
4. `docs/NEW_CHAT_HANDOFF.md` — этот handoff.

При противоречиях: production > актуальный `main` > staging/test > документация > старые обсуждения.

---

## 1. Текущая точка проекта

**Evrasia AI Bot v1.7 работает в production. `/directory` полностью удалён из рабочего контура.**

Базовый v1.7 post-production audit ранее прошёл:

- 34 PASS
- 0 WARN
- 0 FAIL
- `POST_PRODUCTION_AUDIT=PASS`
- `PRODUCTION_V17_VERIFIED=YES`.

После этого выполнен отдельный app-only cleanup deployment для удаления `/directory`:

- `PASS_COUNT=37`
- `FAIL_COUNT=0`
- `ROLLBACK_FAIL_COUNT=0`
- `DIRECTORY_REMOVAL_PRODUCTION=VERIFIED`
- `FINAL_STATUS=PASS`
- `FINAL_RC=0`.

Не повторять deployment v1.7, миграцию 4->18, nginx cutover, переименование PostgreSQL-инфраструктуры или удаление `/directory` без новой фактической причины.

---

## 2. Важнейшее уточнение архитектуры

Сейчас это **один production-контейнер `evrasia-ai-bot-app`**, внутри которого работают несколько бизнес-модулей.

Текущие четыре направления:

1. **Phonebook** — web, канонический и единственный URL `/phonebook`.
2. **Anti-Fraud** — web `/antifraud` + scheduler каждые 15 минут.
3. **СамЗаберу** — Telegram-сценарий внутри `EvrasiaTelegramBotV2`; STOP/ENABLE через Bitrix service layer.
4. **Корпоративная связь / МегаФон** — Telegram-сценарий и обработка привязанной группы «Евразия Мегафон», использующие Phonebook данные.

### `/directory`

`/directory` **удалён и в коде, и в production**.

- это не интерфейс;
- это не alias;
- это не redirect на Phonebook;
- `/directory` = HTTP 404;
- `/directory/...` = HTTP 404;
- `/api/directory/phones` = HTTP 404.

В схемах, документации и текущих проверках писать только **Phonebook = `/phonebook`**.

Исторические внутренние идентификаторы с `directory` могут оставаться там, где речь идёт именно о структуре корпоративного справочника/стабильной схеме БД; они не создают web-маршрут `/directory`.

### Telegram

Не рисовать СамЗаберу и МегаФон как отдельные Docker-боты. Production запускает один `EvrasiaTelegramBotV2` polling consumer, внутри которого есть отдельные сценарии СамЗаберу и Корпоративной связи/МегаФона.

---

## 3. Exact production baseline

Host:

- `eur-bot-01`
- `192.168.103.200`
- Debian 13
- Docker 26.1.5
- Compose 2.26.1-4.

Application:

- container: `evrasia-ai-bot-app`
- deployed revision: `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:fd58cc95d3c26f541bd15d70fbd057f068630d093c6990f926152c995ca8f479`
- image ID: `sha256:78c3d07078995c04948f1fbad600421a665ce03ad35fd388f4d6893f3bc11a47`
- status after cleanup deployment: running / healthy
- port: `127.0.0.1:18080 -> 8080`.

Verified routes after deployment:

- `/phonebook` = 200 direct and routed
- `/directory` = 404 direct and routed
- `/api/directory/phones` = 404
- `/api/phonebook/phones` = 200
- `/antifraud` = 200 direct and routed.

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

The cleanup deployment did **not** restart the DB container, did not change DB schema and did not change nginx.

Legacy infrastructure is gone:

- role `samzaberu`: absent
- DB names `samzaberu` / `samzaberu_antifraud_test`: absent
- container/service `samzaberu-db`: absent
- Compose refs to `samzaberu-db`: 0.

Important: `public.samzaberu_requests` is current SamZaberu business data, not legacy infrastructure. It has 31 rows after the cleanup deployment.

---

## 4. GitHub state

PR #32 — v1.7 Anti-Fraud release:

- `closed`
- `merged=true`
- `draft=false`
- merge commit: `33e3548ab014e927e1e00074e27f3a11ef252bbc`.

PR #33 — remove obsolete `/directory` route:

- `closed`
- `merged=true`
- `draft=false`
- source head before merge: `a9f017676f69f0d1594fbfe5b06bcfc448c628c2`
- merge commit: `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`.

GitHub Actions build #252 for PR #33 merge completed successfully and published the immutable production image now deployed.

Later documentation-only commits may advance `main`; do not confuse them with the deployed application revision `0fcebb1e...`.

For any new technical work, inspect current `main` first and then create the appropriate next branch/PR.

---

## 5. Phonebook

Canonical and only UI is `/phonebook`.

There is **no current `/directory` compatibility route**. Production and CI require `/directory` and `/api/directory/phones` to return 404 while `/phonebook` and `/api/phonebook/...` remain operational.

---

## 6. СамЗаберу

SamZaberu is active inside Telegram.

Main facts:

- Telegram menu button: `🍱 СамЗаберу`;
- access is restricted to mapped operational managers;
- backend routes under `/api/samzaberu/...`;
- actions: `STOP` / `ENABLE`;
- Bitrix service calls: `applyStop` / `applyEnable`;
- request/rule persistence in PostgreSQL;
- retry up to 3 times;
- factual-state verification;
- escalation/manual-completion path on failure.

Do not omit this module from the production architecture.

---

## 7. Корпоративная связь / МегаФон

Active inside the same Telegram bot.

Private-chat flow:

- user selects `📱 Корпоративная связь`;
- sends phone number and problem;
- Phonebook resolves operator/ООО/account details;
- for MegaFon, bot checks membership of the bound group and publishes a structured request there.

Group flow:

- Super Admin binds group using `/bind_megafon_group`;
- bot listens only to the bound group;
- resolves phone and/or ООО;
- asks clarifying questions for missing data;
- refuses ambiguous/mismatched phone-to-ООО routing;
- formats the request for the MegaFon manager.

T2 remains part of Corporate communications but uses its own prepared-contact flow, not the MegaFon group path.

---

## 8. Anti-Fraud runtime

Verified after the cleanup deployment:

- scheduler enabled: true
- interval: 15 minutes
- run-on-start: false
- scheduler was idle immediately after app restart
- latest protected DB cycle: success
- Telegram polling: true
- Telegram 409 conflicts: 0
- both secret mounts remain part of the production runtime
- never print secret values.

Nginx:

- Anti-Fraud routes target production 18080
- TEST 18081 references: 0
- nginx file was unchanged by the cleanup deployment.

---

## 9. Anti-Fraud contracts not to lose

- advisory-only; no automatic account blocking
- Risk 0–100
- critical >=75
- high >=50
- medium >=25
- current bonus balance strictly >40,000 gives +50 and history gate
- exactly 40,000 does not trigger that rule
- multiple active cards are visible anomaly but add 0 automatic risk points
- `TotalSum` is account/phone-level current balance; never multiply/sum per card
- `0.00` is known zero; NULL is unavailable/unknown
- raw loyalty-card numbers must not appear in bot UI/API/logs
- RestIS credentials must not be copied into the bot
- detailed 60-day history is targeted only to history-gated accounts.

Fleet reference 2026-09-05:

- active Bitrix accounts: 1784
- exactly one active state-113 card: 1438
- no active card: 240
- multiple active cards: 106
- unresolved: 0.

---

## 10. Trusted Device foundation

- `device_id`: 32 cryptographically random bytes -> 64 lowercase hex
- regex `^[a-f0-9]{64}$`
- not UUID/IMEI/MAC/advertising ID/hardware identifier
- stable for one installation through restart/update/logout
- reinstall creates new ID
- server may store SHA-256 hash
- trust TTL: 90 days
- IP is not identity/trust.

---

## 11. TEST and backups

Old `evrasia-ai-bot-v17-test` container is exited and archival only. Do not restart blindly.

Keep until explicit cleanup approval:

Phase 1:

`/opt/evrasia-ai-bot/backups/production-v17-phase1-20260907-053318`

SHA256:

`8cf697c2faa5010d12cb9389aac1ecd38929d1672ff1bdd432bad5df5c45e14a`

Phase 2:

`/opt/evrasia-ai-bot/backups/production-v17-phase2-dbrename-20260907-054218`

- production dump SHA256: `6dfd1b8f0d3d30857ac3c7a06d29f05e38ccdccb4b86db5a0862780b14bb56f1`
- test dump SHA256: `bd862bc8445c632face19b4d96e23edc49d1c2385c5f05481be5d40a3a14327c`.

App-only `/directory` removal backup:

`/opt/evrasia-ai-bot/backups/app-only-remove-directory-20260907-090654`

Previous production rollback image retained:

`ghcr.io/juvantusik/evrasia_ai_bot@sha256:381e9d34e3ecd65e814cc93b2c0b91656bc9155a5437e86ea93c1a7f34bceffc`

Do not clean these without explicit user approval.

---

## 12. Paused forensic branch

USER_ID 737384 loyalty-card anomaly investigation is **PAUSED** by user request.

Do not resume unless explicitly asked. Never replay activation requests and never persist full card numbers in documentation.

---

## 13. Mandatory server-script format

Before server work read `docs/SERVER_SCRIPT_RULES.md`.

One complete copy-paste wrapper. Real script begins with:

```bash
clear
set +e
set +u
set +o pipefail 2>/dev/null
```

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
- terminal remains open.

Deployment lesson: if a Compose file uses relative `env_file` paths, do not validate a copied Compose file from an unrelated `/tmp` directory unless the env files are also staged consistently. For the current topology, staging the Compose file inside `/opt/evrasia-ai-bot/prod` preserves relative env-file resolution.

---

## 14. Current continuation point

There is **no pending v1.7 production mutation, PR merge, or `/directory` cleanup deployment**.

Current production state is the new baseline:

1. production revision `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`;
2. `/phonebook` is the only Phonebook web route;
3. `/directory` is absent and returns 404;
4. Anti-Fraud remains healthy with scheduler enabled every 15 minutes;
5. Telegram polling is enabled with 0 observed 409 conflicts after deployment;
6. SamZaberu and MegaFon remain active modules in the same production app;
7. DB remains at 18 migrations / 11 Anti-Fraud tables / 31 SamZaberu request rows;
8. DB container and nginx were unchanged by the cleanup deployment;
9. backups and archival TEST remain retained.

For the next task:

1. restore context from the four docs above;
2. treat production as source of truth;
3. treat current `main` as source code baseline;
4. do not reintroduce `/directory`;
5. remember the unified four-direction architecture;
6. keep backups/archival TEST until explicit cleanup approval;
7. only then start the next requested feature/fix.

Parallel Bonus Club legal work exists, but it is a separate track.
