# Evrasia AI Bot — Current Project Checkpoint

> **Authoritative continuation checkpoint.**
>
> Updated: **2026-09-09** after final production deployment and operator visual acceptance of PR #45, website legal-document publication, and capture of the next Anti-Fraud UI iteration.
>
> Read together with `docs/AI_PROJECT_CONTEXT.md`, `docs/CURRENT_ARCHITECTURE.md`, `docs/NEW_CHAT_HANDOFF.md`, `docs/SERVER_SCRIPT_RULES.md`, `docs/ANTI_FRAUD_OPERATOR_SETTINGS.md`, `docs/WEBSITE_LEGAL_CONSENT_INTEGRATION.md`, `docs/ANTI_FRAUD_UI_NEXT.md` and `SERVER_UPDATES.md`.
>
> Source priority: **production actual state → current GitHub → staging/test → current docs → older discussion**.

---

## 1. Production baseline — current accepted state

Host: `eur-bot-01` (`192.168.103.200`).

Accepted deployed application after PR #45:

- repo: `juvantusik/Evrasia_AI_bot`
- application revision: `b7402cbe19b14f4d84c77870c8be876fe6f7bf42`
- immutable CI image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:11b7adfe1fc4c488a85a87c9417afc562707cdc1b034cda7577483a61609aefe`
- image config ID: `sha256:2febff91d52d3ce0481ddaa96dcbee7b3513a8a4d45417e57c20e71204aa479e`
- app container: `evrasia-ai-bot-app`
- DB container: `evrasia-ai-bot-db`
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- DB / role: `evrasia_ai_bot`
- direct app port: `127.0.0.1:18080`
- migrations: **20**
- latest migration journal timestamp: `1788769200000`
- current confirmed Anti-Fraud bonus threshold: **40000**

PR #45 changes are UI-only relative to PR #44: no new migration, no DB schema change, no scheduler semantics change, no Bitrix user-state mutation and no change to threshold/risk semantics.

Final operator production visual acceptance on 2026-09-09: **«все супер, отображение как надо»**.

Important evidence boundary: the operator confirmed the final PR #45 production UI, but the final PR #45 deployment transcript/backup path was not pasted into chat. Therefore future production mutations must re-read the factual runtime revision/image and exact retained backup inventory; do not invent a PR #45 backup path from the naming template.

---

## 2. GitHub / release lineage for the latest milestone

Current application lineage:

- PR #36 — Bitrix blocking status/operator actions → `947c815d15cc12d0ce571edbc2a2905ef9fed009`
- PR #37 — blocking UI/unblock/group bonus → `86c98eacf5959ca2bf8d0f2c95441ff1e4192f8f`
- PR #38 — remove all-pairs identity-similarity scan → `971af94e26160914efd2c229a4352d398a65214a`
- PR #41 — inactive operational exclusion + reason localization → `a156db2e30dd2a31d7bd4126410f9f513382adaa`
- PR #43 — `Anti-Fraud: configurable bonus threshold and new-account badge` → `3ce9f8c1904351d77696e70314bba3c60afeffa5`
- PR #44 — `Fix Anti-Fraud settings modal viewport overflow` → `a45554b25a820615c95b3a3148a38640a4a27507`
- PR #45 — `Fix Anti-Fraud settings modal viewport regression` → `b7402cbe19b14f4d84c77870c8be876fe6f7bf42`

PR #44 was an intermediate viewport/overflow fix. It deployed successfully at the infrastructure/application level, but the operator visually found the modal worse/unsatisfactory. It is **superseded** by PR #45 and must not be treated as the accepted UI endpoint.

PR #45 fixes the root browser-layout cause: the modal is rendered via React portal into `document.body` so fixed positioning is viewport-relative instead of constrained by the sticky header/backdrop-filter containing block. One internal vertical scroll container remains.

The main push CI for exact PR #45 merge commit passed and published the immutable target image recorded in section 1.

Docs-only commits after this checkpoint may advance `main`; deployed application identity remains a separate concept and must be checked directly before a future cutover.

---

## 3. Anti-Fraud operator setting — production accepted

The Anti-Fraud page now has **`Настройка`** immediately to the left of **`Обновить сейчас`**.

Current setting:

- operator label: `Порог бонусного баланса`
- persisted key: `anti_fraud_bonus_balance_threshold`
- storage: existing `bot_settings` table
- current/default production value: `40000`
- no migration required
- existing strict rule is preserved: only `bonus_balance > threshold` adds +50 and opens targeted 60-day history gate
- equality does not trigger
- saving does not itself trigger refresh
- next scheduled/manual scoring cycle reads the persisted value
- the setting does not change grouping logic and never auto-blocks.

Changing the value, e.g. to `30000`, is an operator data-setting change and should be acceptance-tested through the normal next scoring cycle, not by altering code or schema.

See `docs/ANTI_FRAUD_OPERATOR_SETTINGS.md`.

---

## 4. `Новый` linked-account marker — production accepted semantics

Case dynamics already persist previous/current case membership.

UI behavior after PR #43:

- account gets **`Новый`** when its USER_ID is present in the latest `addedAccountIds` for a previously observed case;
- a newly observed case does **not** label every member as new;
- no guessed timestamp/window is introduced.

This is a structural case-dynamics marker, not a risk score and not a Bitrix account state.

### Next UI iteration

Operator feedback on 2026-09-09: newly appeared users are not visible enough in the current table/workflow. The next iteration should place **`Новый`** in the same case-dynamics/status area where labels such as `усилился`, `без изменений`, etc. are shown, effectively making it another clearly visible dynamics state while preserving the existing PR #43 `addedAccountIds` semantics.

Before implementation, inspect the exact current status set/priorities in code. Do not guess how multiple dynamics conditions compose.

See `docs/ANTI_FRAUD_UI_NEXT.md`.

---

## 5. Settings modal viewport — final accepted behavior

History:

**Было:** PR #43 introduced the settings modal, which could be clipped/not fit the viewport because it lived under the sticky header layout.

**Промежуточно:** PR #44 added viewport caps/scroll handling. Deployment itself passed, but the operator visually rejected the result as worse/unsatisfactory.

**Стало:** PR #45 renders the settings overlay through React portal into `document.body`, retaining one internal vertical scroll container and existing desktop/mobile styling.

**Причина:** the sticky header/backdrop-filter created a containing block for fixed descendants; simple overflow/height adjustments did not fix the root positioning context.

Production acceptance: operator confirmed **«все супер, отображение как надо»**.

Do not reintroduce the PR #44-only layout approach unless a new measured regression requires it.

---

## 6. Anti-Fraud account-state contract — current

Bitrix remains source of truth:

- `ACTIVE=Y`, `BLOCKED=N` → **Активен**
- `ACTIVE=N`, `BLOCKED=N` → **Неактивен**
- any `BLOCKED=Y` → **Заблокирован**

Only `BLOCKED=Y` is a true Bitrix block.

Operational behavior remains:

- blocked and inactive hidden from ordinary risk/case/account views by default;
- toggle: **`Показать заблокированных и неактивных`**;
- inactive stays **Неактивен**;
- KPI/shared-device/duplicate-contact summaries exclude both;
- case with only excluded accounts is hidden;
- group bulk block targets active unblocked accounts only;
- group risk/bonus evidence still includes all case members.

Reason localization remains accepted, including Russian rendering of `max_devices_for_same_pair=...`, `matching_other_accounts`, `max_gap_days`, `similar_phone_links`, `similar_email_links`.

---

## 7. Manual block / unblock — production-proven, do not repeat

Bitrix routes:

- `POST /api/internal/anti-fraud/block`
- `POST /api/internal/anti-fraud/unblock`

Bot routes:

- `POST /api/anti-fraud/block`
- `POST /api/anti-fraud/unblock`

Safe USER_ID `880339` completed the controlled round-trip:

`ACTIVE=Y/BLOCKED=N` → `ACTIVE=N/BLOCKED=Y` → `ACTIVE=Y/BLOCKED=N`.

Acceptance:

- 27 PASS / 0 FAIL / 0 WARN
- final state restored
- historical public reason preserved
- exactly two audit transitions
- risk/history/bonus unchanged
- summary restored
- no real customer mutation.

Do not repeat merely for reassurance. USER_ID 880339 is risk-0 and legitimately has no current case; never mutate a real customer or fabricate production risk data to force a visual fixture.

---

## 8. Similarity-performance hotfix — still accepted

PR #38 replaced exhaustive all-pairs identity-similarity candidate scanning with indexed candidate generation while retaining the final evaluator.

Measured production acceptance:

- protected cycle before: **206 s**
- after: **53 s**
- `/api/healthz`: 24/24 HTTP 200, max 3 ms
- `/api/anti-fraud/scheduler`: 24/24 HTTP 200, max 6 ms
- app restart count 0.

`anti_fraud_risk_scoring` around ~3.1 s does **not** include the subsequent similarity overlay and is not a valid acceptance gate for PR #38.

Do not rerun the refresh/performance acceptance unless a later relevant code change requires it.

---

## 9. Device-hash accumulation visibility — next planned Anti-Fraud UI work

Operator requirement captured on 2026-09-09: add a visible measure of how much `device_hash_id` data has already been accumulated so collection progress can be monitored from the Anti-Fraud interface.

First version should report only facts supported by the current data model, e.g. current known/unique device hashes or another clearly defined count after inspecting the factual schema/query path.

Future requirement: split the visualization by authoritative source/origin:

1. website;
2. SamZaberu application;
3. mobile waiter application.

Before designing that split, verify whether source/origin is already persisted for each `device_hash_id`. If not, the source split requires a later data-model/integration change; do not infer it from heuristics.

See `docs/ANTI_FRAUD_UI_NEXT.md`.

---

## 10. Product / infrastructure continuity

One production application contains:

1. Phonebook — `/phonebook`
2. Anti-Fraud — `/antifraud` + scheduler
3. SamZaberu — Telegram scenario inside `EvrasiaTelegramBotV2`
4. Corporate communications / MegaFon — same production application

Canonical route contract:

- `/phonebook` → current Phonebook UI
- `/antifraud` → current Anti-Fraud UI
- `/directory` → expected 404
- `/api/directory/...` → expected 404.

Legacy `evrasia-ai-bot-v17-test` remains exited/archival. Do not restart blindly.

---

## 11. Website legal / consent integration status

On 2026-09-09 the loyalty-program offer and personal-data policy on `evrasia.rest` were updated and visually accepted in production in support of the Anti-Fraud/legal-processing perimeter. See `docs/WEBSITE_LEGAL_CONSENT_INTEGRATION.md` for production paths, backups and the planned registration-consent work.

Registration checkbox redesign and per-user consent persistence/versioning/audit remain **PLANNED**, not implemented.

---

## 12. Backups / retained state

Do not clean backups without explicit operator approval.

Known retained deployment backups include:

- `/opt/evrasia-ai-bot/backups/pr44-modal-fix-20260909-111032`
- `/opt/evrasia-ai-bot/backups/pr41-inactive-ui-20260908-110846`
- `/opt/evrasia-ai-bot/backups/anti-fraud-similarity-hotfix-20260908-090617`
- earlier v1.7 migration/deployment backups documented in `SERVER_UPDATES.md`.

The exact final PR #45 backup path was not captured in the pasted transcript. Re-read the server backup directory before relying on or cleaning it.

---

## 13. Mandatory server-script / deployment lesson from PR #45 sequence

The first PR #45 deployment attempt was safely stopped **before cutover** because its script expected stale production baseline `3ce9f8c...`, while production had already advanced to healthy PR #44 revision `a45554b...`.

This is now a permanent rule:

- every deployment script must derive/confirm guards from the **factual current production baseline** immediately before the requested change;
- never reuse an older remembered baseline merely because it was true earlier in the same workstream;
- if exact-image/revision guard finds a different healthy production state before `CUTOVER_STARTED=YES`, stop without rollback;
- investigate/confirm why production advanced, then regenerate/resume against the correct baseline;
- never weaken or bypass the exact-baseline guard to “make the deployment continue”.

The guard failure in this sequence was correct safety behavior; the mistake was generating the script with stale expected values.

Other mandatory rules remain in `docs/SERVER_SCRIPT_RULES.md`.

---

## 14. Immediate continuation point

The latest Anti-Fraud operator-settings/modal milestone is **implemented, merged, deployed and operator accepted**.

The next planned Anti-Fraud UI iteration is now explicitly captured:

1. make existing `Новый` membership-delta semantics clearly visible in the case-dynamics/status area;
2. add an operator-visible `device_hash_id` accumulation/progress metric, designed for later source segmentation (website / SamZaberu / mobile waiter) after factual source persistence is verified.

Read `docs/ANTI_FRAUD_UI_NEXT.md` before starting this work.

Do not automatically reopen:

- PR #43/#44/#45 modal work
- USER_ID 880339 block/unblock acceptance
- similarity performance refresh
- archival TEST
- paused full-Bitrix email/anomaly investigation.

Before any implementation or production mutation, inspect current GitHub/CI, exact current UI/data code and factual production state first.
