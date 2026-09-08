# Evrasia AI Bot — AI Project Context

> Operational source of truth for continuing Evrasia AI Bot work across chats.
>
> **Last updated:** 2026-09-08
> **Repository:** `juvantusik/Evrasia_AI_bot`
> **Current deployed app revision:** `a156db2e30dd2a31d7bd4126410f9f513382adaa`
> **Current production milestone:** Anti-Fraud manual block/unblock + inactive operational treatment + similarity performance fix are deployed and accepted.

---

## 1. Continuation rule

In a new chat, read in this order:

1. `docs/PROJECT_CHECKPOINT.md`
2. `docs/AI_PROJECT_CONTEXT.md`
3. `docs/CURRENT_ARCHITECTURE.md`
4. `docs/SERVER_SCRIPT_RULES.md`
5. `SERVER_UPDATES.md`
6. `docs/NEW_CHAT_HANDOFF.md`

Source priority:

**production actual state → current GitHub → staging/test → current docs → older discussion**.

Do not replay completed deployment, block/unblock or performance-acceptance steps unless a new change makes them relevant.

A documentation-only commit may advance GitHub `main` without changing production. Always keep `main` revision and deployed application revision conceptually separate.

---

## 2. Current production baseline

Host:

- hostname: `eur-bot-01`
- IP: `192.168.103.200`
- OS: Debian 13

Runtime:

- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- app service/container: `evrasia-ai-bot-app`
- DB service/container: `evrasia-ai-bot-db`
- production DB: `evrasia_ai_bot`
- DB role: `evrasia_ai_bot`
- direct app port: `127.0.0.1:18080`
- network: `evrasia-prod-internal`
- volume: `evrasia-postgres-prod-data`

Current deployed application:

- revision: `a156db2e30dd2a31d7bd4126410f9f513382adaa`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:ce3b85fe789495f3b5ee4e59fe8eb129a75343d1916f2a7c45483da18d988947`
- config ID: `sha256:cbe989d6375189f9f12d7a0ad6f74f9f5455536838750fd96f0e2e459d9cc145`
- platform: `linux/amd64`
- migrations: **20**
- latest migration journal timestamp: `1788769200000`

PR #41 app-only deployment result:

- `PASS_COUNT=29`
- `FAIL_COUNT=0`
- `ROLLBACK_ATTEMPTED=NO`
- `FINAL_STATUS=PASS`
- DB container unchanged/not restarted
- schema/migrations unchanged
- environment/mounts/ports preserved
- scheduler enabled/idle/15m/no last error
- main HTTP routes passed
- no Anti-Fraud refresh triggered by deployment
- no Bitrix user-state mutation during deployment

Latest deployment backup:

`/opt/evrasia-ai-bot/backups/pr41-inactive-ui-20260908-110846`

---

## 3. Product architecture — IMPORTANT

Evrasia AI Bot is **one production application** with four current directions:

1. **Phonebook** — `/phonebook`
2. **Anti-Fraud** — `/antifraud` + protected scheduler/data pipeline
3. **SamZaberu** — Telegram scenario inside `EvrasiaTelegramBotV2`
4. **Corporate communications / MegaFon** — Telegram scenario/workflow inside the same application

There are not separate production Docker bots for SamZaberu and MegaFon.

Canonical route contract:

- `/phonebook` = current Phonebook UI
- `/antifraud` = current Anti-Fraud UI
- `/directory` = removed / HTTP 404
- `/directory/...` = removed / HTTP 404
- `/api/directory/...` = removed / HTTP 404

Historical internal identifiers containing `directory` may remain as implementation names and do not recreate the product route.

See `docs/CURRENT_ARCHITECTURE.md` for the canonical topology and current Anti-Fraud state flow.

---

## 4. GitHub / release history relevant to current production

Merged application PRs leading to the current Anti-Fraud state:

- PR #32 — v1.7 Anti-Fraud release → `33e3548ab014e927e1e00074e27f3a11ef252bbc`
- PR #33 — remove obsolete `/directory` → `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`
- PR #34 — similar-identity grouping + async refresh UX → merged and superseded as active work
- PR #35 — CI regression fix → `be631fd31c96434ac7232f5e1641ecf9ea94c823`
- PR #36 — Bitrix blocking status/operator actions → `947c815d15cc12d0ce571edbc2a2905ef9fed009`
- PR #37 — blocking UI/unblock/group bonus → `86c98eacf5959ca2bf8d0f2c95441ff1e4192f8f`
- PR #38 — remove all-pairs identity similarity scan → `971af94e26160914efd2c229a4352d398a65214a`
- PR #41 — inactive operational exclusion + reason localization → `a156db2e30dd2a31d7bd4126410f9f513382adaa`

Docs-only hardening:

- PR #39 — server-script rules hardening → merge `13bf28fe96a857498bc108705ccd6ed3f05915f4`
- PR #40 — acceptance-fixture eligibility lesson; its rule content must be retained in `docs/SERVER_SCRIPT_RULES.md` and is superseded once consolidated into the current docs update.

Main push CI #298 / run ID `34202375971` for `a156db2e...`:

- success
- tests `70/70`
- typecheck/API/main web/Phonebook/Anti-Fraud builds passed
- smoke tests passed
- published exact immutable production image above.

---

## 5. Anti-Fraud product contract — current

Anti-Fraud is advisory/investigative. Risk score never automatically blocks an account.

### Risk thresholds

- critical `>=75`
- high `>=50`
- medium `>=25`

High-balance rule:

- current bonus balance strictly `>40000.00` → +50 and history gate
- exactly `40000.00` → no high-balance trigger

Loyalty semantics:

- account balance is not multiplied by active-card count
- multiple active cards alone add 0 automatic risk points
- known zero and missing are different
- negative current bonus balance is valid
- detailed 60-day history is targeted after gate; never bulk-load fleet history every scheduler cycle

Grouping semantics:

- grouping evidence is separate from risk evidence
- behavioral signals alone do not merge separate identities
- shared device, exact identity and corroborated similarity can create links/groups under the current rules

---

## 6. Bitrix account state / operational visibility

Bitrix is the account-state source of truth.

Status mapping:

- `ACTIVE=Y`, `BLOCKED=N` → **Активен**
- `ACTIVE=N`, `BLOCKED=N` → **Неактивен**
- any `BLOCKED=Y` → **Заблокирован**

Only `BLOCKED=Y` is a true Bitrix block.

PR #41 operational rule:

- inactive and blocked accounts are hidden from ordinary Anti-Fraud operational lists by default;
- shared toggle: **`Показать заблокированных и неактивных`**;
- inactive remains visually **Неактивен**;
- KPI, shared-device summary and duplicate-contact summary exclude both blocked and inactive accounts;
- cases with only excluded accounts disappear from ordinary case list;
- group bonus and risk evidence continue to include all case accounts;
- group bulk block targets active unblocked accounts only;
- an inactive account exposed by the toggle may still be individually/formally blocked if the operator explicitly chooses it.

The operator visually confirmed this current production behavior on 2026-09-08.

---

## 7. Manual block / unblock

Bitrix protected routes:

- `POST /api/internal/anti-fraud/block`
- `POST /api/internal/anti-fraud/unblock`

Bot-facing routes:

- `POST /api/anti-fraud/block`
- `POST /api/anti-fraud/unblock`

Block:

- manual operator action only
- sets `ACTIVE=N`, `BLOCKED=Y`
- fixed approved public reason is stored
- factual state is re-read after mutation
- already-blocked is idempotent and does not overwrite an existing reason
- group action returns per-account outcomes and may partially succeed

Unblock:

- blocked account becomes `ACTIVE=Y`, `BLOCKED=N`
- already-unblocked is idempotent
- historical reason is retained
- no fake Bitrix block/unblock date is invented

Audit:

- application uses `anti_fraud_block_audit`
- `blockedAt` is shown only when current state has authoritative successful app audit; an externally blocked account does not receive a fabricated date.

Approved public wording:

> По результатам проведенной проверки подтверждено нарушение Правил программы лояльности «Бонусный Клуб Евразия», квалифицированное как недобросовестное использование Программы. В соответствии с п. 3.9 Правил применена блокировка учетной записи и связанных с ней возможностей участия в Программе.

Do not expose customer-facing:

- Anti-Fraud case ID
- risk score
- device IDs
- similar phone/email mechanics
- multi-account grouping internals
- technical reason/evidence codes.

---

## 8. Controlled backend acceptance — COMPLETE

Safe test account: `USER_ID=880339`.

The account had `overallRisk=0`, so no current case was expected.

Completed production round-trip:

- before: `ACTIVE=Y`, `BLOCKED=N`
- block: `ACTIVE=N`, `BLOCKED=Y`
- unblock: `ACTIVE=Y`, `BLOCKED=N`
- final state exactly restored
- historical reason unchanged
- exactly two audit transitions
- risk/history/bonus unchanged
- summary restored exactly
- **27 PASS / 0 FAIL / 0 WARN**
- `CONTROLLED_BACKEND_E2E_ACCEPTANCE=PASS`
- no real customer mutation

Do not repeat this test merely for reassurance.

### Acceptance-fixture lesson

A known-safe account is not automatically eligible for every UI path.

USER_ID 880339 is risk-0 and legitimately has no case. Never choose/mutate a real customer or fabricate production risk data just to force a visual fixture. Split acceptance honestly when a safe fixture cannot exercise one visual state.

Backend blocked-state semantics are production-verified. A live blocked-card visual on a safe eligible risky case was not manufactured and must not be falsely claimed.

---

## 9. Similarity performance — COMPLETE

PR #38 removed the exhaustive all-pairs candidate scan while preserving final evaluator semantics.

Reference before hotfix:

- 6119 active similarity accounts
- 18,718,021 theoretical account pairs per exhaustive pass
- protected cycle 206 s
- event-loop responsiveness degraded during synchronous scan

Measured after hotfix:

- protected cycle 53 s
- `/api/healthz`: 24/24 HTTP 200, max 3 ms
- `/api/anti-fraud/scheduler`: 24/24 HTTP 200, max 6 ms
- zero probe errors/non-200
- app restart count 0

Important instrumentation rule:

`anti_fraud_risk_scoring` duration around ~3.1 s measures the base scorer and does **not** include the subsequent identity-similarity overlay. It is not a valid performance gate for PR #38.

Do not rerun the performance refresh unless a future relevant code change requires a new measurement.

Identity evaluator semantics retained:

- phone: same normalized length >=7, exactly one differing digit
- same-provider email local >=6, edit distance <=1; same-local provider-TLD candidate supported
- cross-provider normalization removes only `.`, `_`, `-`; normalized local >=6 equal, provider differs
- same-provider email requires corroboration by same name, exact/similar phone or shared device
- cross-provider email cannot use same name alone; requires phone/shared-device corroboration
- final evaluator remains source of truth; candidate index only narrows candidate pairs.

---

## 10. UI / localization acceptance

Operator production UI is accepted after PR #41.

Confirmed:

- inactive card shows `Неактивен`
- inactive/blocked operational hiding works
- shared toggle wording is correct
- raw `max_devices_for_same_pair=...` is localized to human-readable Russian, e.g. `макс. общих устройств для одной пары аккаунтов: 2`
- generated keys `matching_other_accounts`, `max_gap_days`, `similar_phone_links`, `similar_email_links` are localized.

Operator confirmation: **«все отрабатывает»**.

---

## 11. Anti-Fraud scheduler / async refresh contract

Runtime:

- `ANTI_FRAUD_SCHEDULER_ENABLED=true`
- interval 15 minutes
- run-on-start false

Manual refresh:

- `POST /api/anti-fraud/refresh` accepts the existing protected single-flight cycle and returns HTTP 202 when accepted
- UI polls `GET /api/anti-fraud/scheduler`
- already-running cycle remains 409
- completed partial/failed states are shown factually

HTTP timeout after a 202 is not proof of job failure. Persisted `anti_fraud_sync_runs` state is authoritative for accepted background cycles.

Never trigger a second refresh while the previous accepted cycle may still be running.

---

## 12. Credential / integration boundaries

Required app secret mounts remain protected and must never be printed.

The bot container must **not** receive RestIS credentials.

Anti-Fraud loyalty/history data is reached through the protected site-side integration. Do not add RestIS credentials to `evrasia-ai-bot-app` as a deployment guard or “fix”.

Raw loyalty card numbers must not appear in bot UI/API/logs.

Trusted Device represents app install/trust identity, not IP or hardware identity. Logout must not create a new identity.

---

## 13. Phonebook / Telegram continuity

Phonebook:

- `/phonebook` is the only canonical Phonebook route
- `/directory` is absent / 404

SamZaberu:

- remains current production functionality inside `EvrasiaTelegramBotV2`
- STOP/ENABLE through Bitrix service layer
- PostgreSQL request/rule journal remains intentional business data

Corporate communications / MegaFon:

- remains current production functionality inside the same bot process
- uses Phonebook data and bound group workflow

Do not rename/delete current SamZaberu business data merely because older infrastructure once used `samzaberu` in DB/container naming.

---

## 14. TEST / backups

Legacy `evrasia-ai-bot-v17-test` is exited/archival. Do not restart blindly.

Retain backups until explicit approval, including:

- `/opt/evrasia-ai-bot/backups/pr41-inactive-ui-20260908-110846`
- `/opt/evrasia-ai-bot/backups/anti-fraud-similarity-hotfix-20260908-090617`
- earlier v1.7 production/DB rename backups listed in `SERVER_UPDATES.md`
- retained Bitrix Anti-Fraud backups documented from blocking endpoint/admin work.

Do not clean backup assets automatically.

---

## 15. Mandatory server-script rules

`docs/SERVER_SCRIPT_RULES.md` is mandatory.

Current high-value lessons:

- one complete copy-paste block; real script starts with `clear`, then safe shell mode
- architecture-aware, operation-specific guards only
- no unrelated Telegram/RestIS gates for Anti-Fraud-only work
- use GHCR auth under user `tech`; do not invent a new token first
- stage Compose under `/opt/evrasia-ai-bot/prod` when relative paths exist
- pre-cutover scheduler race → bounded wait, then revalidate; not immediate false failure
- async 202 + transient timeout ≠ job failure
- persisted DB run state is authoritative when HTTP responsiveness is under test
- validate performance metric boundaries before using them as pass/fail gates
- validate UI/API fixture eligibility before production mutation
- never mutate a real customer merely to make acceptance visually convenient
- no secrets in output
- backups/rollback/post-check for production mutations
- terminal remains open.

---

## 16. Paused work

Full-Bitrix email investigation remains paused by user decision. Do not resume unless explicitly asked.

Historical anomaly investigation around USER_ID 737384 remains paused unless the user explicitly asks to reopen it. Do not persist or repeat raw card-number data.

---

## 17. Product roadmap / parallel legal track

Current technical baseline remains v1.7 unified production app.

Previously noted roadmap:

- v1.8 — document generation
- v1.9 — document sending through Exchange

Trusted Device is an Anti-Fraud foundation/data source, not a separate bot version.

Legal Bonus Club work is a separate track. Do not expose internal Anti-Fraud algorithms/risk thresholds in public legal documents unless specifically required.

---

## 18. Immediate continuation point

The current Anti-Fraud milestone is **implemented, deployed, performance-accepted and visually accepted**.

Do not automatically reopen:

- PR #34/#36/#37/#38/#41 implementation work
- USER_ID 880339 block/unblock acceptance
- 53-second performance refresh measurement
- archival TEST
- paused email/anomaly investigations.

The next engineering iteration starts from the next user requirement. Before any change, inspect factual production, current `main`, affected files, PR/CI state and only then make the smallest necessary change.

---

## 19. Maintenance rule

After any material change to deployed revision/image, topology, DB/migrations, scheduler behavior, integrations, Anti-Fraud business rules, canonical routes or backup/rollback state, update:

- `docs/PROJECT_CHECKPOINT.md`
- `docs/AI_PROJECT_CONTEXT.md`
- `docs/CURRENT_ARCHITECTURE.md`
- `docs/NEW_CHAT_HANDOFF.md`
- `SERVER_UPDATES.md`
- `docs/SERVER_SCRIPT_RULES.md` when a new operational lesson is learned.

When a decision changes, record **Было → Стало → Причина** and keep production application identity separate from docs-only GitHub revisions.
