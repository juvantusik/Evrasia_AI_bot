# Evrasia AI Bot — AI Project Context

> Operational source of truth for continuing Evrasia AI Bot work across chats.
>
> **Last updated:** 2026-09-23
> **Repository:** `juvantusik/Evrasia_AI_bot`
> **Current accepted deployed app revision:** `dfde4c39b3821f6946d3be05448d11aea1fcc441`
> **Current production milestone:** PR #62 remains the accepted bot runtime; TOTP direct-PIN pilot is deployed on the website for USER_ID 880339; the active Anti-Fraud continuation is the diagnosed multicard manual-history defect.

---

## 1. Continuation rule

In a new chat, read in this order:

0. `docs/NEW_CHAT_HANDOFF.md` — fastest current handoff and exact active continuation
1. `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md` — current Anti-Fraud / Scout / manual-investigation handoff
2. `docs/PROJECT_CHECKPOINT.md`
3. `docs/AI_PROJECT_CONTEXT.md`
4. `docs/CURRENT_ARCHITECTURE.md`
5. `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`
6. `docs/SERVER_SCRIPT_RULES.md`
7. `SERVER_UPDATES.md`
8. `docs/ANTI_FRAUD_OPERATOR_SETTINGS.md`

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

Accepted application baseline after PR #62:

- revision: `dfde4c39b3821f6946d3be05448d11aea1fcc441`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:369f313744a9c1d7b70b94eee2971d78c42320d9400bffb1bf3e6dd107f417d3`
- image ID: `sha256:4e8b9448c1e7c8c9aad17e502aaabf0452479dc0688a7dc92219833f3b6a408e`
- migrations: **25**
- current confirmed bonus threshold: `40000`
- accepted runtime state: `running healthy`

PR #58 deployment backup:

`/opt/evrasia-ai-bot/backups/pr58-ui-labels-continuation-20260920-084234`

Current PR #58 UI rules:

- `Устройство` = one linked USER_ID on this Trusted Device hash;
- `Общее устройство` = two or more linked USER_ID values on this same hash type;
- if a second USER_ID later appears on the same hash, shared-device grouping can occur after the next sync/scoring cycle.

Current Scout display wording:

- `дней с 3 чекинами за 7 дней`;
- `дней с 3+ чекинами за 60 дней`.

The first phrase is display-only. Technical `days_2plus_7d` still uses `>=2`. Do not change backend semantics unless the operator separately approves a rule change.

Documentation-only commits after this checkpoint may advance GitHub `main` without changing the deployed application identity. Re-read actual runtime revision/image before every production mutation.

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

### Current Anti-Fraud release lineage after the earlier UI milestones

The earlier PR #32–#45 lineage below is retained as history. Current continuation additionally depends on:

- PR #47 — operator-facing `Новый` changed to persistent web-first-seen semantics with a 24-hour window; migration/state introduced and deployed earlier;
- PR #52 — generic operator watchlist support; useful infrastructure but **not** the final manual-investigation persistence model;
- PR #53 — Avito manual override experiment; **closed unmerged**, never production;
- PR #55 — first Check-in Scout implementation; unsafe image must **never be deployed**;
- PR #56 — corrected Check-in Scout; merged/deployed/verified; migration `0023_anti_fraud_checkin_scout`;
- PR #57 — exposes all case Trusted Device identifiers while keeping shared-device grouping semantics separate;
- PR #58 — Russian device labels + Scout display wording; merged/deployed/operator accepted at revision `700422b3c9004c2d92092a166e50ac5e8e8a6d33`.
- PR #60 — manual operator investigation by phone; merged/deployed/verified with migration `0024_anti_fraud_operator_investigation`.
- PR #62 — operator-visible manual-investigation evidence: exact 60-day window, visit metrics/day summary, Trusted Device prefix and linked USER_ID display; merged/deployed/verified at revision `dfde4c39b3821f6946d3be05448d11aea1fcc441`.

Detailed facts and the exact next step are in `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md`.

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

## 5A. Check-in Scout and manual investigation — current

Check-in Scout is a separate upstream detector for **physical check-in frequency** because the normal 60-day loyalty-history path sits behind a risk gate.

Authoritative physical source:

- Bitrix offline-order/check-in data fed by `VIP_TODAY`;
- do not count `VIP_HISTORY` rows as physical visits.

Production Scout behavior:

- 1 physical check-in/day → normal/no persistent Scout row;
- 2+ in a Moscow day → WATCH;
- 3rd same day or a later WATCH day with 2+ → targeted 60-day deep check;
- WATCH retained through current day + two later calendar days;
- confirmation: 3 different days with 2+ in 7d OR 3 different days with 3+ in 60d;
- confirmed frequency → Risk 100 / Critical independently;
- no auto-block.

Protected site route:

`/api/internal/anti-fraud/checkins`

Manual-investigation Step 2:

- operator action: **«Добавить на проверку»**;
- primary input: phone;
- operator source/reason must be persistent and separate from automatic telemetry;
- no auto-block.

Bitrix phone resolver is already production:

`/api/internal/anti-fraud/phone-resolve`

Resolver behavior:

- 200 unique;
- 400 invalid;
- 404 not found;
- 409 ambiguous without choosing a USER_ID;
- 503 internal/candidate-limit problem.

Bot-side Step 2 from PR #60 is **MERGED / DEPLOYED / VERIFIED** in production.

Implemented in PR #60:

- protected phone gateway using exact production request field `phone`;
- migration `0024_anti_fraud_operator_investigation`;
- persistent operator source/reason and state;
- explicit operator-authorized 60-day history path;
- address-specific worker + restart recovery;
- normal scoring without fake `riskGateConfirmed`;
- persistent Risk-0 visibility;
- phone-first UI action **«Добавить на проверку»**;
- no auto-block;
- gateway regression tests and CI schema-smoke.

Read-only production inspection reconfirmed the Bitrix resolver SHAs and showed `$payload['phone'] ?? null` as the exact input field. Production resolver itself was not modified.

Production acceptance on 2026-09-20: revision `f98d10c327e14b6dd5a34a9117ce25310ed6180e`, immutable digest `sha256:6b21a15ad09bd82643401e6d1f3a2c18ab8dd42adcdfb1f4997b26a71f487e40`, image ID `sha256:af1e6ee925cd55ad2ed63be12fe13e8f18e3f33a95678bfe8f14141756762c43`, migrations 25, deployment 13 PASS / 0 FAIL / 0 WARN, rollback not required. Backup: `/opt/evrasia-ai-bot/backups/pr60-manual-investigation-20260920-153256`.

Do not repeat deployment or backend smoke merely for reassurance. Operator-visible use of **«Добавить на проверку»** has now been exercised on real intended investigations; that acceptance exposed the 2026-09-23 multicard manual-history defect documented in sections 18–19. Continue from that defect, not from PR #60 rollout.

See `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md` for hashes, routes, backup paths and deployment proof.

## 6. `Новый` account badge — current semantics

PR #47 changed the operator-facing meaning of `Новый` to a persistent first-seen window:

- source table: `anti_fraud_web_account_state(bitrix_user_id, first_seen_at)`;
- a USER_ID is shown as `Новый` for **24 hours** after first appearing in the web Anti-Fraud interface;
- repeated scheduler/manual refreshes do not reset or extend the 24-hour window;
- historical accounts were bootstrapped as old during rollout;
- forensic case-delta history remains separate and is not the operator-facing 24-hour definition.

Do not revert to the older PR #43 interpretation that equated the operator badge directly with the latest case `addedAccountIds`.

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


---

## 17. TOTP 2FA / protected-profile workstream — PILOT DEPLOYED

Authoritative continuation document:

`docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`

Pilot is restricted to Bitrix USER_ID `880339`.

Current factual production state:

- native Bitrix MFA/TOTP is in use;
- global OTP is enabled;
- mandatory OTP remains OFF;
- exactly one pilot TOTP enrollment was present at acceptance and no non-pilot OTP rows were present;
- SMS ownership is required before QR/secret exposure;
- real QR/TOTP enrollment succeeded;
- pre-enrollment web/mobile sessions were revoked;
- password → TOTP login E2E succeeded;
- the account security card shows `Подключена` and `Отключить 2FA`;
- native `CUser::getContext()->isOtpUsed()` is the accepted current-session proof;
- a fresh canonical TOTP session reports `otpUsed=true`;
- a later cookie-restored session reports `otpUsed=false`, which is an intended privilege downgrade.

Direct web PIN pilot is deployed:

- source PIN remains the existing `CRestis::pincode()` mechanism;
- no new PIN generator/store was created;
- direct disclosure requires canonical `evrasia.rest`, POST, valid Bitrix sessid, active initialized TOTP, matching current auth context and `isOtpUsed()=true`;
- otherwise the existing VK/SMS path remains;
- fresh incognito password → TOTP session showed **«Показать Пин-код»** and displayed a real PIN;
- direct-display UI/transport E2E is PASSED;
- **actual spend/payment use of that displayed PIN is still awaiting operator confirmation**;
- rollout beyond USER_ID `880339` remains forbidden.

Current direct-PIN production SHAs:

- endpoint: `4d637e1bff15682d3eae6a5b4e3ffa074e2543daaf408767e2894654df2a0713`;
- template: `569aaba642d5601215b453b04d06837da04b1378a8a69fd079ef777ad5797710`;
- JS: `9b7afe419ee979089fda4b65af3475f415ea1eec8827a3c27d7be594ee5a68b4`;
- backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/direct-pin-pilot-20260923-060243`.

Pending:

1. confirm that the displayed direct PIN works in a real bonus-spend/payment operation;
2. explicitly verify cookie-restored / `otpUsed=false` fallback to legacy VK/SMS;
3. verify ordinary non-2FA account compatibility with global OTP ON / mandatory OFF;
4. repair the current disable-TOTP guard design before testing disable/reset/recovery;
5. separately remediate the hardcoded PHP session cookie in `verify_order_by_sms.php` without ever printing its value.

Do not repeat TOTP discovery, enrollment, session-proof or direct-PIN deployment.

---

## 18. Manual Anti-Fraud history multicard defect — ACTIVE

Two real operator investigations exposed a systematic mismatch between the manual-history implementation and the physical-history semantics shown in the UI.

Affected internal USER_ID values:

- `6645`;
- `408974`.

Confirmed production facts:

- both accounts have two active loyalty cards;
- protected loyalty returns `issue=multiple_active_cards`;
- site-side `AntiFraudLoyaltyService.php` deliberately skips legacy `VIP_HISTORY` when `count($cards) > 1`;
- the account-level balance still comes from RestIS `/api/Balance` by phone;
- current operator investigation can still reach `ready` with zero history;
- USER_ID `408974` has two targeted physical Check-in events on 2026-09-22;
- USER_ID `6645` targeted Check-in returns zero for the expected event and needs a separate trace;
- the 24-hour cache hypothesis is ruled out.

Production source baselines:

- protected route file: `39abfc79b1cb4291688f48c1cb47ce53f844fb627138267ee3aaf6b3947f792e`;
- loyalty service: `44d14a246ba728c22354639d89ffeb1a20a6894797d34afd9c51200b2e7491c7`;
- Check-in Scout service: `fd497e84b1ddb3afc16e497395215576dd38feb9d5e73132b4f9278b48f16e9b`;
- final read-only source audit: 9 PASS / 0 FAIL / 0 WARN.

Architecture direction already supported by production:

- targeted Check-in accepts `user_ids` and up to 60 days;
- it resolves every Bitrix card ID owned by the requested USER_ID;
- it queries physical `COfflineOrderHl` events by those card IDs;
- it maps them back to USER_ID without exposing raw card numbers.

Therefore the current next design gate is:

**manual operator physical visits → existing targeted 60-day Check-in source**

with loyalty balance/history kept as a separate concern.

Before any write, separately trace why USER_ID `6645` has no targeted Check-in event. Do not assume the event is missing, mis-owned or linked elsewhere until read-only evidence proves which.

---

## 19. Immediate continuation point — 2026-09-23

If the operator continues the current Anti-Fraud problem, do not reopen completed PR #60/#62 work. Resume from:

1. design the minimal multicard-safe manual physical-history contract using targeted Check-in;
2. trace USER_ID `6645` separately;
3. then implement/test/deploy with normal production guards.

If the operator switches back to TOTP, resume from direct-PIN business acceptance and negative compatibility gates, not from enrollment/discovery.

For a new chat, read `docs/NEW_CHAT_HANDOFF.md` first.
