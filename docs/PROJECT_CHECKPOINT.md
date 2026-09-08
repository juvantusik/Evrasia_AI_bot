# Evrasia AI Bot — Current Project Checkpoint

> **Authoritative continuation checkpoint.**
>
> Updated: **2026-09-08** after final production deployment and operator visual acceptance of PR #41.
>
> Read together with `docs/AI_PROJECT_CONTEXT.md`, `docs/CURRENT_ARCHITECTURE.md`, `docs/NEW_CHAT_HANDOFF.md`, `docs/SERVER_SCRIPT_RULES.md` and `SERVER_UPDATES.md`.
>
> Source priority remains: **production actual state → current GitHub → staging/test → current docs → older discussion**.

---

## 1. Production baseline — current and verified

Host: `eur-bot-01` (`192.168.103.200`).

Current deployed application:

- repo: `juvantusik/Evrasia_AI_bot`
- deployed application revision: `a156db2e30dd2a31d7bd4126410f9f513382adaa`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:ce3b85fe789495f3b5ee4e59fe8eb129a75343d1916f2a7c45483da18d988947`
- image config ID: `sha256:cbe989d6375189f9f12d7a0ad6f74f9f5455536838750fd96f0e2e459d9cc145`
- app container: `evrasia-ai-bot-app`
- DB container: `evrasia-ai-bot-db`
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- DB: `evrasia_ai_bot`
- direct app port: `127.0.0.1:18080`
- migrations: **20**
- latest migration journal timestamp: `1788769200000`

PR #41 production deployment on 2026-09-08:

- app-only recreation
- database schema unchanged
- DB container unchanged / not restarted
- environment, mounts and ports preserved exactly
- Anti-Fraud refresh not triggered by deployment
- no Bitrix user-state mutation during deployment
- scheduler remained enabled, idle, interval 15 minutes, no last error
- `/api/healthz`, `/`, `/phonebook`, `/antifraud`, Anti-Fraud summary/cases/accounts/scheduler all returned 200
- `/directory` remained 404
- deployment result: `PASS_COUNT=29`, `FAIL_COUNT=0`, `ROLLBACK_ATTEMPTED=NO`, `FINAL_STATUS=PASS`
- fresh backup: `/opt/evrasia-ai-bot/backups/pr41-inactive-ui-20260908-110846`

The prior production application revision `971af94e...` is superseded by `a156db2e...`.

A later **documentation-only** merge may advance GitHub `main`; do not mistake a docs-only commit for a new deployed application revision.

---

## 2. GitHub / CI release state

Relevant merged application PRs:

- PR #36 — `Anti-Fraud: Bitrix blocking status and operator actions`, merge `947c815d15cc12d0ce571edbc2a2905ef9fed009`
- PR #37 — `Anti-Fraud: blocking UI, hidden blocked accounts and unblock preparation`, merge `86c98eacf5959ca2bf8d0f2c95441ff1e4192f8f`
- PR #38 — `Anti-Fraud: remove all-pairs identity similarity scan`, merge `971af94e26160914efd2c229a4352d398a65214a`
- PR #41 — `Anti-Fraud: hide inactive accounts from operational lists`, merge `a156db2e30dd2a31d7bd4126410f9f513382adaa`

PR #41 pre-merge CI #297 passed. Main push CI #298 / run ID `34202375971` passed and published the exact production image above.

Main CI #298 facts:

- `70/70` tests passed
- TypeScript typecheck passed
- API build passed
- main web build passed
- Phonebook build passed
- Anti-Fraud build passed
- smoke `/antifraud` passed
- smoke Anti-Fraud summary/case-dynamics passed
- migration smoke confirmed 20 migrations and block/audit schema
- published platform: `linux/amd64`

Docs-only PR #39 (`SERVER_SCRIPT_RULES` hardening) was merged as `13bf28fe96a857498bc108705ccd6ed3f05915f4`.

Draft PR #40 contains the production lesson about validating acceptance-fixture eligibility. That lesson must be retained in `docs/SERVER_SCRIPT_RULES.md`; it is superseded by the consolidated documentation update once the same rules are merged there.

---

## 3. Anti-Fraud account-state contract — current

Bitrix remains the source of truth.

Status mapping:

- `ACTIVE=Y`, `BLOCKED=N` → **Активен**
- `ACTIVE=N`, `BLOCKED=N` → **Неактивен**
- any `BLOCKED=Y` → **Заблокирован**

Important distinction:

- **true blocked** means `BLOCKED=Y`;
- `ACTIVE=N`, `BLOCKED=N` is not rewritten as a Bitrix block and remains the separate UI status **Неактивен**;
- operationally, however, inactive accounts are treated like excluded blocked accounts in Anti-Fraud lists and KPI calculations.

Current operational behavior after PR #41:

- blocked **and inactive** accounts are hidden from ordinary risk/case/account operational views by default;
- one toggle exposes the hidden population: **`Показать заблокированных и неактивных`**;
- inactive accounts still display as **Неактивен**, not `Заблокирован`;
- KPI, shared-device summaries and duplicate-contact summaries exclude both blocked and inactive accounts;
- a case containing only excluded accounts is hidden from the ordinary case list;
- partial cases report the hidden-account count;
- group bulk block targets only active, unblocked accounts;
- group bonus/risk evidence continues to use all accounts in the case, including excluded ones;
- an inactive account revealed by the toggle may still be individually/formally blocked by an operator if required; it is simply not swept into a group bulk block.

The operator visually confirmed this production behavior on 2026-09-08.

---

## 4. Blocking / unblock — implemented and production-proven

Anti-Fraud is advisory/investigative. Risk never auto-blocks an account.

Manual blocking is implemented.

Bitrix protected routes:

- `POST /api/internal/anti-fraud/block`
- `POST /api/internal/anti-fraud/unblock`

Bot-facing routes:

- `POST /api/anti-fraud/block`
- `POST /api/anti-fraud/unblock`

Block contract:

- writes `ACTIVE=N`, `BLOCKED=Y`
- uses fixed approved public reason
- re-reads factual Bitrix state after mutation
- already blocked is idempotent and does not overwrite an existing reason
- group block reports per-account results and supports partial success

Unblock contract:

- blocked account becomes `ACTIVE=Y`, `BLOCKED=N`
- already unblocked is idempotent
- historical block reason is retained rather than silently cleared
- no fake Bitrix block/unblock date is invented

App audit uses `anti_fraud_block_audit`. `blockedAt` is shown only when the application has an authoritative successful current block audit for that state; externally blocked accounts do not receive a fabricated timestamp.

Approved public block wording remains:

> По результатам проведенной проверки подтверждено нарушение Правил программы лояльности «Бонусный Клуб Евразия», квалифицированное как недобросовестное использование Программы. В соответствии с п. 3.9 Правил применена блокировка учетной записи и связанных с ней возможностей участия в Программе.

Customer-facing reason must never expose case ID, risk score, device identifiers, identity-similarity mechanics or other detection internals.

---

## 5. Controlled production acceptance — completed, do not repeat

Safe test account: `USER_ID=880339`.

Backend/API acceptance was completed without requiring case membership:

- initial state: `ACTIVE=Y`, `BLOCKED=N`
- account had `overallRisk=0`, therefore zero current Anti-Fraud cases was expected
- block succeeded and was independently re-read as `ACTIVE=N`, `BLOCKED=Y`
- unblock succeeded and restored `ACTIVE=Y`, `BLOCKED=N`
- historical public reason remained unchanged
- audit rows advanced exactly by two transitions
- summary returned exactly to the original state
- risk/history/bonus values were not altered by block/unblock
- final result: **27 PASS / 0 FAIL / 0 WARN**
- `CONTROLLED_BACKEND_E2E_ACCEPTANCE=PASS`
- `REAL_CUSTOMER_MUTATION=NO`

Do **not** repeat this block/unblock round-trip merely for reassurance.

The safe account is risk-0 and not eligible for a live case-card blocked visual fixture. Therefore:

- backend/API blocked-state semantics are production-verified;
- UI blocked-state logic is implemented and code-reviewed;
- live blocked-card rendering on a safe eligible risky case was **not manufactured** and is not falsely claimed as observed;
- no real customer was mutated to create a convenient visual fixture.

This acceptance-fixture lesson is mandatory in `docs/SERVER_SCRIPT_RULES.md`.

---

## 6. Performance hotfix — production accepted

PR #38 replaced exhaustive all-pairs identity-similarity scanning with indexed candidate generation while retaining the existing final evaluator and risk/grouping semantics.

Reference production cycle before hotfix:

- protected cycle: **206 s**
- 6119 active similarity accounts
- theoretical exhaustive pairs per pass: 18,718,021
- HTTP scheduler probe could stall during the synchronous scan

Measured production cycle after hotfix:

- protected cycle: **53 s**
- `/api/healthz`: 24/24 HTTP 200, max observed 3 ms
- `/api/anti-fraud/scheduler`: 24/24 HTTP 200, max observed 6 ms
- zero probe errors/non-200 responses
- app restart count: 0

The persisted `anti_fraud_risk_scoring` duration around ~3.1 s is **not** an acceptance metric for this optimization because it does not include the subsequent similarity overlay. Do not reintroduce that false gate.

Do not rerun the performance refresh unless a future change requires a new measurement.

---

## 7. Operator UI / localization — accepted

Current production UI behavior is visually accepted by the operator.

Confirmed after PR #41:

- inactive account card shows **Неактивен**;
- inactive and blocked accounts use the shared hidden operational population;
- operator toggle wording is **Показать заблокированных и неактивных**;
- raw technical reason `max_devices_for_same_pair=...` is rendered in Russian as human-readable text, e.g. **`макс. общих устройств для одной пары аккаунтов: 2`**;
- generated raw keys `matching_other_accounts`, `max_gap_days`, `similar_phone_links`, `similar_email_links` are also localized for operator UI.

Operator confirmation on 2026-09-08: **«все отрабатывает»**.

---

## 8. Anti-Fraud invariants that remain in force

- risk thresholds: critical `>=75`, high `>=50`, medium `>=25`
- balance strictly `>40000.00` gives +50 and opens history gate; exactly `40000.00` does not
- multiple active loyalty cards alone do not add risk points
- account balance is not multiplied by card count
- known zero is different from missing; negative current balance is valid
- detailed 60-day history remains targeted after gate; no fleet-wide history burst
- protected scheduler interval remains 15 minutes
- grouping evidence is distinct from risk evidence; behavior alone must not group separate identities
- Trusted Device represents an installation/trust identity, not IP/hardware identity
- logout must not manufacture a new device identity
- bot container must not receive RestIS credentials; loyalty/history access uses protected site-side integration
- raw card numbers must not appear in bot UI/API/logs

Identity-similarity semantics preserved by PR #38:

- phone: same normalized length >=7, exactly one differing digit
- same-provider email locals >=6, edit distance <=1; same local across provider TLD candidate remains supported
- cross-provider normalization removes only `.`, `_`, `-`; normalized local >=6 must match and provider differ
- same-provider email needs corroboration by same name, exact/similar phone or shared device
- cross-provider email cannot use same-name alone; requires phone/shared-device corroboration
- candidate index only narrows pairs; final evaluator remains source of truth

---

## 9. Product / infrastructure continuity

One production application contains four current directions:

1. Phonebook — `/phonebook`
2. Anti-Fraud — `/antifraud` + scheduler
3. SamZaberu — Telegram scenario inside `EvrasiaTelegramBotV2`
4. Corporate communications / MegaFon — Telegram scenario/workflow inside the same app

Canonical routes:

- `/phonebook` = current Phonebook UI
- `/antifraud` = current Anti-Fraud UI
- `/directory` = removed / expected 404
- `/api/directory/...` = removed / expected 404

TEST container `evrasia-ai-bot-v17-test` remains exited/archival. Do not restart it blindly.

Preserve both production secret mounts. Never print secret values.

---

## 10. Backups to retain

Do not clean without explicit operator approval.

Current latest app deployment backup:

- `/opt/evrasia-ai-bot/backups/pr41-inactive-ui-20260908-110846`

Similarity-hotfix backup:

- `/opt/evrasia-ai-bot/backups/anti-fraud-similarity-hotfix-20260908-090617`

Older production/DB/Bitrix backups documented in `SERVER_UPDATES.md` remain retained until explicit cleanup approval.

---

## 11. Immediate continuation point

The Anti-Fraud blocking / inactive-state / localization / similarity-performance milestone is **implemented, deployed and accepted in production**.

Do not reopen completed work automatically:

- do not repeat USER_ID 880339 block/unblock acceptance;
- do not rerun the 53-second performance refresh;
- do not redesign the accepted Anti-Fraud UI without a new requirement;
- do not restart archival TEST;
- do not resume the paused full-Bitrix email investigation unless explicitly requested.

The next engineering workstream should start only from a new user requirement and must first inspect current `main` plus current production before mutation.
