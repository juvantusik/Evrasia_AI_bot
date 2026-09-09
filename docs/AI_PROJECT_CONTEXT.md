# Evrasia AI Bot — AI Project Context

> Operational source of truth for continuing Evrasia AI Bot work across chats.
>
> **Last updated:** 2026-09-09
> **Repository:** `juvantusik/Evrasia_AI_bot`
> **Current accepted deployed app revision:** `b7402cbe19b14f4d84c77870c8be876fe6f7bf42`
> **Current production milestone:** Anti-Fraud operator settings + `Новый` badge + final settings-modal viewport fix are deployed and operator accepted.

---

## 1. Continuation rule

In a new chat, read in this order:

1. `docs/PROJECT_CHECKPOINT.md`
2. `docs/AI_PROJECT_CONTEXT.md`
3. `docs/CURRENT_ARCHITECTURE.md`
4. `docs/SERVER_SCRIPT_RULES.md`
5. `SERVER_UPDATES.md`
6. `docs/ANTI_FRAUD_OPERATOR_SETTINGS.md`
7. `docs/NEW_CHAT_HANDOFF.md`

Source priority:

**production actual state → current GitHub → staging/test → current docs → older discussion**.

Do not replay completed deployment, block/unblock, performance or visual-acceptance steps unless a new change makes them relevant.

A documentation-only commit may advance GitHub `main` without changing production. Keep GitHub head and deployed application revision conceptually separate and verify factual runtime before every production mutation.

---

## 2. Current production baseline

Host/runtime:

- hostname: `eur-bot-01`
- IP: `192.168.103.200`
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- app service/container: `evrasia-ai-bot-app`
- DB service/container: `evrasia-ai-bot-db`
- production DB / role: `evrasia_ai_bot`
- direct app port: `127.0.0.1:18080`
- network: `evrasia-prod-internal`
- volume: `evrasia-postgres-prod-data`

Accepted application baseline after PR #45:

- revision: `b7402cbe19b14f4d84c77870c8be876fe6f7bf42`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:11b7adfe1fc4c488a85a87c9417afc562707cdc1b034cda7577483a61609aefe`
- config ID: `sha256:2febff91d52d3ce0481ddaa96dcbee7b3513a8a4d45417e57c20e71204aa479e`
- platform: `linux/amd64`
- migrations: **20**
- latest migration timestamp: `1788769200000`
- current confirmed bonus threshold: `40000`

PR #45 is UI-only relative to PR #44: no DB/schema/migration changes, no Bitrix write, no scheduler/risk/grouping semantic change.

Operator final production confirmation on 2026-09-09: **«все супер, отображение как надо»**.

Evidence boundary: the final PR #45 deployment transcript/backup path was not pasted into chat. Do not invent it. Before a future deployment, re-read the current runtime revision/image and backup inventory from production.

---

## 3. Product architecture — IMPORTANT

Evrasia AI Bot remains **one production application** with four current directions:

1. **Phonebook** — `/phonebook`
2. **Anti-Fraud** — `/antifraud` + protected scheduler/data pipeline
3. **SamZaberu** — Telegram scenario inside `EvrasiaTelegramBotV2`
4. **Corporate communications / MegaFon** — Telegram scenario/workflow inside the same application

Canonical route contract:

- `/phonebook` = current Phonebook UI
- `/antifraud` = current Anti-Fraud UI
- `/directory` = removed / expected HTTP 404
- `/api/directory/...` = removed / expected HTTP 404

Legacy TEST `evrasia-ai-bot-v17-test` is exited/archival and must not be restarted blindly.

---

## 4. GitHub / release history relevant to current production

Merged application PRs leading to the current state:

- PR #32 — v1.7 Anti-Fraud release → `33e3548ab014e927e1e00074e27f3a11ef252bbc`
- PR #33 — remove obsolete `/directory` → `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`
- PR #34 — similar-identity grouping + async refresh UX
- PR #35 — CI regression fix → `be631fd31c96434ac7232f5e1641ecf9ea94c823`
- PR #36 — Bitrix blocking status/operator actions → `947c815d15cc12d0ce571edbc2a2905ef9fed009`
- PR #37 — blocking UI/unblock/group bonus → `86c98eacf5959ca2bf8d0f2c95441ff1e4192f8f`
- PR #38 — remove all-pairs identity similarity scan → `971af94e26160914efd2c229a4352d398a65214a`
- PR #41 — inactive operational exclusion + reason localization → `a156db2e30dd2a31d7bd4126410f9f513382adaa`
- PR #43 — configurable bonus threshold + new-account badge → `3ce9f8c1904351d77696e70314bba3c60afeffa5`
- PR #44 — first settings-modal viewport overflow fix → `a45554b25a820615c95b3a3148a38640a4a27507`
- PR #45 — final settings-modal viewport regression fix → `b7402cbe19b14f4d84c77870c8be876fe6f7bf42`

PR #44 was infrastructure/deployment healthy but visually unsatisfactory and is superseded by PR #45.

PR #45 final approach:

- React portal into `document.body`;
- viewport-relative fixed overlay;
- one internal vertical scroll container;
- desktop/modal controls fully reachable;
- existing mobile behavior preserved.

The exact PR #45 merge commit CI passed and published the immutable image in section 2.

---

## 5. Anti-Fraud product contract — current

Anti-Fraud remains advisory/investigative. Risk never auto-blocks an account.

Risk thresholds:

- critical `>=75`
- high `>=50`
- medium `>=25`

High-balance rule is now operator-configurable:

- persisted key: `anti_fraud_bonus_balance_threshold`
- storage: existing `bot_settings`
- current/default production value: `40000`
- strict comparison: current bonus balance **>`threshold`** → +50 and history gate
- equality does not trigger
- saving setting does not itself run refresh
- next scheduled/manual scoring cycle reads it
- no migration
- does not alter grouping and does not auto-block.

Loyalty semantics remain:

- account balance is not multiplied by active-card count
- multiple active cards alone add no automatic risk points
- known zero differs from missing
- negative current balance is valid
- detailed 60-day history is targeted after gate, never fleet-wide.

Grouping evidence remains separate from risk evidence; behavioral signals alone do not merge identities.

---

## 6. `Новый` account badge

PR #43 uses existing case-dynamics `addedAccountIds`.

- account gets `Новый` when it newly enters a previously observed case;
- first observation of an entire case does not badge all members;
- badge is structural case-dynamics evidence, not risk/account status;
- no guessed timestamp/window is introduced.

---

## 7. Bitrix account state / operational visibility

Bitrix is the source of truth:

- `ACTIVE=Y`, `BLOCKED=N` → **Активен**
- `ACTIVE=N`, `BLOCKED=N` → **Неактивен**
- any `BLOCKED=Y` → **Заблокирован**

Only `BLOCKED=Y` is a true Bitrix block.

Operational rules remain:

- inactive and blocked hidden by default;
- toggle: `Показать заблокированных и неактивных`;
- inactive remains `Неактивен`;
- KPI/shared-device/duplicate-contact summaries exclude both;
- group bulk block targets active unblocked accounts only;
- risk/group evidence can still include excluded case members.

Localization remains accepted, including Russian operator text for `max_devices_for_same_pair=...` and related generated reason keys.

---

## 8. Manual block / unblock — COMPLETE

Routes:

- Bitrix: `POST /api/internal/anti-fraud/block`, `POST /api/internal/anti-fraud/unblock`
- bot: `POST /api/anti-fraud/block`, `POST /api/anti-fraud/unblock`

Safe USER_ID `880339` controlled round-trip is already production-proven:

- initial `ACTIVE=Y/BLOCKED=N`
- block `ACTIVE=N/BLOCKED=Y`
- unblock restored `ACTIVE=Y/BLOCKED=N`
- historical reason retained
- exactly two audit transitions
- risk/history/bonus unchanged
- 27 PASS / 0 FAIL / 0 WARN
- no real customer mutation.

Do not repeat merely for reassurance. The safe account is risk-0 and legitimately has no case; never mutate a real customer or fabricate production risk data just to create a visual fixture.

---

## 9. Similarity performance — COMPLETE

PR #38 removed exhaustive all-pairs candidate scanning while retaining final evaluator semantics.

Production acceptance:

- protected cycle before: 206 s
- after: 53 s
- `/api/healthz`: 24/24 HTTP 200, max 3 ms
- `/api/anti-fraud/scheduler`: 24/24 HTTP 200, max 6 ms
- app restart count 0.

`anti_fraud_risk_scoring` ~3.1 s does not include the later similarity overlay and is not a valid PR #38 performance gate.

Do not rerun unless a future relevant change requires it.

---

## 10. Anti-Fraud scheduler / async contract

Runtime remains:

- scheduler enabled
- interval 15 minutes
- run-on-start false.

Manual refresh:

- accepted `POST /api/anti-fraud/refresh` returns 202
- UI polls `/api/anti-fraud/scheduler`
- already-running cycle remains 409
- persisted `anti_fraud_sync_runs` is authoritative for accepted background cycles.

A later HTTP timeout after 202 is not proof of job failure. Never trigger a second refresh until the prior accepted run is factually complete/failed.

---

## 11. Credentials / integration boundaries

- bot container must **not** receive RestIS credentials;
- loyalty/history access uses protected site-side integration;
- raw loyalty card numbers must not appear in bot UI/API/logs;
- required secret mounts remain protected and must not be printed;
- Trusted Device is app installation/trust identity, not IP/hardware identity;
- logout must not manufacture a new identity.

---

## 12. Backups / TEST

Retain backups until explicit cleanup approval.

Known current retained backups include:

- `/opt/evrasia-ai-bot/backups/pr44-modal-fix-20260909-111032`
- `/opt/evrasia-ai-bot/backups/pr41-inactive-ui-20260908-110846`
- `/opt/evrasia-ai-bot/backups/anti-fraud-similarity-hotfix-20260908-090617`
- earlier v1.7 backups listed in `SERVER_UPDATES.md`.

Exact final PR #45 backup path was not captured in chat; inspect server before future cleanup/rollback planning.

---

## 13. Mandatory server-script rules

`docs/SERVER_SCRIPT_RULES.md` is mandatory.

Core rules:

- one complete copy/paste block;
- long script → quoted-heredoc wrapper → `bash -n` → execute if valid → remove temp file;
- structured numbered output and explicit `PASS/FAIL/FINAL_STATUS/FINAL_RC`;
- architecture-aware guards only;
- exact production baseline must be read from **factual current production**, not remembered from an earlier step;
- no unrelated Telegram/RestIS gates for Anti-Fraud-only work;
- scheduler race → bounded wait + revalidation;
- existing GHCR auth under `tech`;
- stage Compose in production directory when relative paths exist;
- no secrets in output;
- backup/rollback/post-check for production mutations;
- terminal stays open.

PR #45 deployment lesson: an initial script correctly stopped before cutover because it expected stale `3ce9f8c...` while production had already advanced to PR #44 `a45554b...`. The guard was correct; the script-generation baseline was wrong. Never bypass such a guard—confirm actual state and regenerate against it.

---

## 14. Paused work

Full-Bitrix email investigation remains paused. Historical anomaly investigation around USER_ID 737384 remains paused. Do not resume unless explicitly asked.

---

## 15. Immediate continuation point

The current Anti-Fraud milestone is **implemented, deployed and visually accepted**.

Do not automatically reopen:

- PR #43/#44/#45 work
- modal viewport redesign
- USER_ID 880339 acceptance
- 53-second performance refresh
- archival TEST
- paused email/anomaly investigations.

Start next iteration from the next user requirement, after checking current GitHub/CI and factual production runtime.

---

## 16. Maintenance rule

After any material change to deployed revision/image, topology, DB/migrations, scheduler behavior, integrations, Anti-Fraud rules, canonical routes or backup/rollback state, update:

- `docs/PROJECT_CHECKPOINT.md`
- `docs/AI_PROJECT_CONTEXT.md`
- `docs/CURRENT_ARCHITECTURE.md` when architecture actually changes
- `docs/NEW_CHAT_HANDOFF.md`
- `SERVER_UPDATES.md`
- `docs/ANTI_FRAUD_OPERATOR_SETTINGS.md` for operator-setting/UI semantics
- `docs/SERVER_SCRIPT_RULES.md` when a new operational lesson is learned.

When a decision changes, record **Было → Стало → Причина** and keep production application identity separate from docs-only GitHub revisions.
