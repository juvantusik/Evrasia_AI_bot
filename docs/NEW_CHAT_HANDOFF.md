# Evrasia — New Chat Handoff

> Fast handoff for continuing Evrasia AI Bot in a new ChatGPT chat.
>
> **Updated: 2026-09-23** after TOTP direct-PIN pilot acceptance and the manual Anti-Fraud multicard-history root-cause audit.

## Ready-to-paste instruction for a new chat

Продолжаем проект Evrasia AI Bot. Не начинай работу заново и не проси меня повторять уже установленный контекст.

Репозиторий: `juvantusik/Evrasia_AI_bot`.
Самая свежая документационная ветка/handoff: `docs/pr62-consistency-cleanup`.

Сначала прочитай:

0. `docs/NEW_CHAT_HANDOFF.md`;
1. `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md`;
2. `docs/PROJECT_CHECKPOINT.md`;
3. `docs/AI_PROJECT_CONTEXT.md`;
4. `docs/CURRENT_ARCHITECTURE.md`;
5. `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`;
6. `SERVER_UPDATES.md`;
7. `docs/SERVER_SCRIPT_RULES.md`.

Приоритет источников: **фактический production → актуальный GitHub → staging/test → актуальная документация → старые обсуждения**.

Текущая активная точка Anti-Fraud: доказан дефект ручной истории для multicard-аккаунтов. USER_ID `408974` имеет 2 физических Check-in 22.09.2026, но loyalty/VIP_HISTORY пуст из-за `multiple_active_cards`. USER_ID `6645` имеет ту же multicard-проблему, но targeted Check-in дополнительно возвращает 0 и требует отдельной трассировки. 24-часовой cache как причина исключён. Следующий шаг — спроектировать минимальный multicard-safe путь операторской физической истории через existing targeted 60-day Check-in source и отдельно найти причину отсутствия Check-in у USER_ID 6645. По этому дефекту production write ещё не делали.

Параллельный TOTP workstream: пилот USER_ID `880339` уже enrolled, native `otpUsed` proof подтверждён, direct web PIN pilot deployed. В свежей incognito password→TOTP сессии кнопка **«Показать Пин-код»** появилась и PIN был показан. Полный business E2E остаётся pending только до подтверждения реального использования этого PIN при списании. Rollout за пределы 880339 запрещён.

Не повторяй завершённые discovery/deploy проверки. Сначала назови восстановленную текущую точку и только потом продолжай.

---

## 1. Current production baseline

Host: `eur-bot-01` (`192.168.103.200`).

Accepted deployed application baseline after PR #62:

- application revision: `dfde4c39b3821f6946d3be05448d11aea1fcc441`
- immutable CI image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:369f313744a9c1d7b70b94eee2971d78c42320d9400bffb1bf3e6dd107f417d3`
- image ID: `sha256:4e8b9448c1e7c8c9aad17e502aaabf0452479dc0688a7dc92219833f3b6a408e`
- app: `evrasia-ai-bot-app`
- DB: `evrasia-ai-bot-db`
- DB name/role: `evrasia_ai_bot`
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- production migrations: **25**
- app state at acceptance: **running healthy**
- PR #58 deployment backup: `/opt/evrasia-ai-bot/backups/pr58-ui-labels-continuation-20260920-084234`

PR #58 is UI-only relative to the PR #57 application baseline: no DB migration, no scoring/grouping change and no Bitrix write.

Current device labels in case details:

- one USER_ID on a Trusted Device hash → **Устройство**;
- multiple USER_ID on the same hash → **Общее устройство**;
- both labels refer to the same kind of Trusted Device hash; the difference is only the linked-account count.

Current Scout display wording:

- `дней с 3 чекинами за 7 дней`;
- `дней с 3+ чекинами за 60 дней`.

**Important:** PR #58 changed the first phrase only in the UI. Backend field `days_2plus_7d` and its `>=2` calculation were not changed. Do not silently change Scout business logic based on the label.

For the full current Anti-Fraud state and next continuation point, read `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md`.

---

## 1A. Latest Anti-Fraud operator-visible acceptance — PR #62

PR #62 is **MERGED / PRODUCTION / VERIFIED**.

The expanded manual-investigation account card now shows the result of the latest operator-authorized 60-day history load rather than forcing the operator to infer it from risk scores:

- exact 60-day window;
- physical visit count;
- visit-day count;
- restaurant count;
- first / last event;
- expandable daily summary;
- Trusted Device prefix when available;
- linked Bitrix USER_ID values when they exist;
- explicit `Risk по категориям` caption.

Acceptance fixture USER_ID `1969724`:

- 10 physical visits;
- 9 visit days;
- 8 restaurants;
- one Trusted Device prefix `3578df691292f7bc…`;
- no linked accounts at acceptance.

Do not interpret `Посещения 0` or `История/бонусы 0` as event counts. They remain risk scores.

---

## 2. Earlier Anti-Fraud operator-settings milestone — HISTORICAL / STILL VALID

Relevant lineage:

- PR #41 — inactive operational exclusion + localization → `a156db2e30dd2a31d7bd4126410f9f513382adaa`
- PR #43 — configurable bonus threshold + `Новый` badge → `3ce9f8c1904351d77696e70314bba3c60afeffa5`
- PR #44 — first settings-modal viewport/overflow fix → `a45554b25a820615c95b3a3148a38640a4a27507`; deployed but visually unsatisfactory
- PR #45 — final modal viewport regression fix via React portal → `b7402cbe19b14f4d84c77870c8be876fe6f7bf42`; deployed and visually accepted

### Operator setting

Button **`Настройка`** is immediately to the left of **`Обновить сейчас`**.

Current setting:

- name: `Порог бонусного баланса`
- persisted key: `anti_fraud_bonus_balance_threshold`
- storage: existing `bot_settings`
- default/current confirmed value: `40000`
- no migration
- strict rule: balance **>`threshold`** adds +50 and opens targeted 60-day history gate; equality does not trigger
- saving does not itself start a refresh
- next scheduled/manual scoring cycle reads the value
- setting never auto-blocks and does not alter grouping rules.

### `Новый` badge

Current operator-facing **`Новый`** semantics come from PR #47: a USER_ID is shown as new for 24 hours from its first appearance in the web Anti-Fraud interface. Repeated refreshes do not extend that window. Forensic case-delta `addedAccountIds` remains separate.

### Final modal behavior

PR #45 fixes the root UI issue rather than only adjusting overflow:

- modal is rendered via React portal into `document.body`;
- fixed positioning is viewport-relative and no longer constrained by the sticky header/backdrop-filter containing block;
- one internal vertical scroll container is retained;
- desktop dialog fits the viewport and lower controls remain reachable;
- mobile behavior is preserved.

Do not reopen PR #44's intermediate layout approach; PR #45 supersedes it.

---

## 3. Current Anti-Fraud next iteration — Step 2 manual investigation

The old UI-plan items from `docs/ANTI_FRAUD_UI_NEXT.md` are no longer the continuation point. Device-hash visibility and device labeling were implemented through PR #57/#58, and the 24-hour `Новый` semantics were implemented earlier through PR #47.

Current continuation:

**PR #60 «Добавить на проверку» by phone is MERGED / DEPLOYED / VERIFIED.**

Already production and unchanged:

- protected Bitrix phone resolver;
- unique/invalid/not-found/ambiguous/error contracts;
- no account write and no blocking.

Read-only production inspection on 2026-09-20 reconfirmed the accepted resolver SHAs and exact request field:

`phone`

Production functionality from PR #60:

1. protected bot phone-resolver gateway;
2. migration `0024_anti_fraud_operator_investigation`;
3. persistent operator-investigation state;
4. explicit operator-authorized 60-day history without fake risk gate;
5. normal scoring;
6. persistent visibility at automatic Risk 0;
7. phone-first UI action;
8. separate operator source/reason such as `Авито`;
9. restart-safe worker and scheduler resume;
10. immediate first-seen persistence for the existing 24-hour «Новый» rule without window extension on repeats;
11. no auto-block;
12. gateway tests plus route/migration/schema CI smoke.

Production acceptance on 2026-09-20:

- revision: `f98d10c327e14b6dd5a34a9117ce25310ed6180e`;
- immutable digest: `sha256:6b21a15ad09bd82643401e6d1f3a2c18ab8dd42adcdfb1f4997b26a71f487e40`;
- image ID: `sha256:af1e6ee925cd55ad2ed63be12fe13e8f18e3f33a95678bfe8f14141756762c43`;
- migrations: 25;
- backup: `/opt/evrasia-ai-bot/backups/pr60-manual-investigation-20260920-153256`;
- deployment: 13 PASS / 0 FAIL / 0 WARN;
- rollback: not required.

Next action: perform one deliberate operator-visible end-to-end **«Добавить на проверку»** on an account the operator actually intends to investigate, then confirm the resulting UI state/history/scoring. Do not create an arbitrary customer fixture.

Use `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md` for the full design and exact production state.

---

## 4. Existing Anti-Fraud status semantics — still current

Bitrix is source of truth:

- `ACTIVE=Y`, `BLOCKED=N` → **Активен**
- `ACTIVE=N`, `BLOCKED=N` → **Неактивен**
- any `BLOCKED=Y` → **Заблокирован**

Only `BLOCKED=Y` is a true Bitrix block.

Operational UI:

- blocked and inactive hidden by default;
- toggle: **`Показать заблокированных и неактивных`**;
- inactive stays **Неактивен**;
- KPI/shared-device/duplicate-contact summaries exclude both;
- group bulk block targets active unblocked accounts only;
- risk/group evidence semantics remain unchanged.

Localization remains accepted, including Russian rendering of `max_devices_for_same_pair=2` and related generated reason keys.

---

## 5. Blocking / unblock acceptance — completed, do not repeat

Safe test USER_ID `880339` completed the controlled backend round-trip:

`ACTIVE=Y/BLOCKED=N` → block `ACTIVE=N/BLOCKED=Y` → unblock `ACTIVE=Y/BLOCKED=N`.

Final acceptance: 27 PASS / 0 FAIL / 0 WARN; state restored; reason preserved; two audit transitions only; no risk/history/bonus mutation; no real customer mutation.

Do not repeat it merely for reassurance or fabricate/mutate a risky customer to create a visual fixture.

---

## 6. Similarity performance — completed

PR #38 acceptance remains valid:

- protected cycle before: 206 s
- after: 53 s
- `/api/healthz`: 24/24 HTTP 200, max 3 ms
- scheduler probes: 24/24 HTTP 200, max 6 ms
- app restart count 0.

`anti_fraud_risk_scoring` ~3.1 s is not the similarity-hotfix gate because that timing does not include the later similarity overlay.

---

## 7. Current product architecture

One production app/container contains:

1. Phonebook — `/phonebook`
2. Anti-Fraud — `/antifraud` + 15-minute scheduler
3. SamZaberu — Telegram scenario inside `EvrasiaTelegramBotV2`
4. Corporate communications / MegaFon — Telegram scenario/workflow inside the same application

`/directory` and `/api/directory/...` are removed and expected 404.

Legacy TEST container `evrasia-ai-bot-v17-test` is exited/archival. Do not restart blindly.

### Website legal/consent perimeter

On 2026-09-09 the loyalty-program offer and personal-data policy on `evrasia.rest` were updated and visually accepted in production in support of the Anti-Fraud/legal-processing perimeter. The privacy page was converted to a responsive text layout and linked below `Договор оферты` in the site footer.

The next planned cross-system task is the registration form: separate checkboxes/choices and auditable per-user persistence/versioning of what was accepted or declined. This is not implemented yet. Before implementation inspect the factual Bitrix registration/persistence mechanisms; do not invent fields. See `docs/WEBSITE_LEGAL_CONSENT_INTEGRATION.md` for the complete handoff and production paths/backups.

---

## 8. Mandatory deployment/script lessons

Read `docs/SERVER_SCRIPT_RULES.md` before any server work.

Especially important after the PR #44 → #45 deployment sequence:

- generate one complete copy/paste block;
- for a long script use quoted-heredoc wrapper, `bash -n`, execute only on successful syntax check, then remove the temp file;
- guards must use the **factual current production baseline**, not an older remembered revision;
- if an exact-revision guard finds a newer healthy production baseline before `CUTOVER_STARTED=YES`, stop without rollback and update the planned source baseline only after confirming why production advanced;
- a guard correctly stopping on a stale baseline is a safety success, not a reason to bypass the guard;
- scheduler race → bounded idle wait + revalidation;
- use existing GHCR auth owned by user `tech`;
- no unrelated Telegram/RestIS gates for Anti-Fraud-only work;
- no secrets in output;
- backups/rollback/post-check for production mutation;
- terminal stays open.

---

## 9. Immediate continuation point — 2026-09-23

Two workstreams are currently open. Do not mix them unless the operator explicitly switches context.

### A. Anti-Fraud manual-history defect — ACTIVE continuation

Two operator investigations that should have shown recent history exposed a factual design gap.

Resolved internal accounts:

- USER_ID `6645`
- USER_ID `408974`

Production facts already proven read-only:

- both accounts currently have `active_card_count=2`;
- protected loyalty endpoint returns `issue=multiple_active_cards` for both;
- current `AntiFraudLoyaltyService.php` deliberately does **not** call legacy `VIP_HISTORY` when more than one active card exists, because `VIP_HISTORY` requires one concrete card number;
- the worker currently treats this no-history loyalty result as successful history completion, so the investigation can become `ready` while the operator UI shows zero history;
- the earlier 24-hour cache hypothesis is ruled out for these cases;
- USER_ID `408974` has **2 physical Check-in events on 2026-09-22** in the targeted Check-in source, while loyalty history is empty;
- USER_ID `6645` has the same multicard loyalty limitation but targeted Check-in returns **0** events for the expected period, so it has a separate Check-in/card-linkage/source-population issue to trace.

Latest site-side production source baselines:

- `AntiFraudLoyaltyService.php` SHA256: `44d14a246ba728c22354639d89ffeb1a20a6894797d34afd9c51200b2e7491c7`;
- `AntiFraudCheckinScoutService.php` SHA256: `fd497e84b1ddb3afc16e497395215576dd38feb9d5e73132b4f9278b48f16e9b`;
- protected route file SHA256: `39abfc79b1cb4291688f48c1cb47ce53f844fb627138267ee3aaf6b3947f792e`;
- last audit result: **9 PASS / 0 FAIL / 0 WARN**, read-only.

Important architecture fact:

- targeted Check-in history resolves **all Bitrix card IDs owned by the requested USER_ID**, then queries `COfflineOrderHl` by those card IDs;
- therefore the multicard state itself does not prevent targeted physical Check-in history;
- manual operator **physical visits** should be sourced from the existing targeted 60-day Check-in path rather than relying on single-card `VIP_HISTORY`;
- loyalty/balance semantics remain separate, and no raw card number or RestIS credential should cross into the bot.

**Exact next gate:** design the minimal multicard-safe manual-history fix and separately trace why USER_ID `6645` has no targeted Check-in record. No production write has been approved/applied for this defect yet.

### B. TOTP 2FA / protected direct PIN — PILOT deployed

Pilot remains only Bitrix USER_ID `880339`.

Current accepted production state:

- native Bitrix TOTP is enabled globally but **not mandatory**;
- exactly one active/initialized pilot TOTP row exists; no non-pilot OTP rows were present at acceptance;
- fresh canonical `https://evrasia.rest/` password → TOTP login proves native current-session `isOtpUsed()=true`;
- later cookie-restored sessions correctly downgrade to `otpUsed=false`;
- no custom OTP session flag is needed;
- pilot direct web PIN is deployed;
- direct PIN is server-authoritative and requires canonical host + POST + valid sessid + active initialized TOTP + matching current auth context + `isOtpUsed()=true`;
- in a fresh incognito TOTP session, the PIN block showed **«Показать Пин-код»** and displayed a real PIN in-browser;
- this proves direct-display transport/UI E2E;
- **actual use of that displayed PIN for a real bonus-spend/payment operation is still awaiting operator confirmation**;
- rollout beyond USER_ID `880339` remains prohibited.

Direct-PIN production backup:

`/home/site_evrasia/web/evrasia.spb.ru/backups/direct-pin-pilot-20260923-060243`

Current direct-PIN production SHAs:

- `/local/php_interface/pincode.php`: `4d637e1bff15682d3eae6a5b4e3ffa074e2543daaf408767e2894654df2a0713`;
- account.pincode template: `569aaba642d5601215b453b04d06837da04b1378a8a69fd079ef777ad5797710`;
- account.pincode JS: `9b7afe419ee979089fda4b65af3475f415ea1eec8827a3c27d7be594ee5a68b4`.

Still pending before any broader TOTP/direct-PIN rollout:

1. operator confirmation that the direct PIN actually works in a real spend/payment operation;
2. explicit negative-path acceptance that a cookie-restored / `otpUsed=false` pilot session does **not** get direct PIN and falls back to legacy VK/SMS;
3. ordinary non-2FA account compatibility with global OTP ON / mandatory OFF;
4. separate repair of `disableTotpAction()`, which still reuses an enrollment-only guard and must not be exercised until reset/recovery/cooling-off semantics are designed;
5. separate audit/remediation of the hardcoded PHP session cookie in `verify_order_by_sms.php`; never reproduce the cookie value.

---

## 10. What a new chat must do first

1. Read this file and the current authoritative checkpoints:
   - `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md`;
   - `docs/PROJECT_CHECKPOINT.md`;
   - `docs/AI_PROJECT_CONTEXT.md`;
   - `docs/CURRENT_ARCHITECTURE.md`;
   - `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`;
   - `SERVER_UPDATES.md`;
   - `docs/SERVER_SCRIPT_RULES.md`.
2. Inspect factual GitHub/production state before any write; documentation-only commits may be newer than the deployed app.
3. Do **not** repeat already completed PR #62 deployment, TOTP enrollment, session-proof, direct-PIN deployment, or the multicard root-cause audits.
4. If continuing the current Anti-Fraud issue, resume from:
   **design multicard-safe physical-history flow + trace USER_ID 6645 targeted Check-in absence**.
5. If continuing TOTP, resume from:
   **real-use direct-PIN confirmation / negative cookie-session fallback / ordinary non-2FA compatibility**, not from enrollment discovery.

---

## 11. Ready-to-paste new-chat prompt

```text
Продолжаем Evrasia AI Bot как единый проект, ничего уже сделанного не переделывай.

Репозиторий: juvantusik/Evrasia_AI_bot.
Документационная ветка с самым свежим handoff: docs/pr62-consistency-cleanup.

Сначала восстанови контекст из:
1) docs/NEW_CHAT_HANDOFF.md
2) docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md
3) docs/PROJECT_CHECKPOINT.md
4) docs/AI_PROJECT_CONTEXT.md
5) docs/CURRENT_ARCHITECTURE.md
6) docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md
7) SERVER_UPDATES.md
8) docs/SERVER_SCRIPT_RULES.md

Приоритет источников: фактический production → актуальный GitHub → staging/test → актуальная документация → старые обсуждения.

Текущая активная точка Anti-Fraud: найден production-дефект ручной 60-дневной истории для аккаунтов с несколькими активными loyalty-картами. USER_ID 408974 имеет 2 физических Check-in 22.09.2026, но loyalty VIP_HISTORY пуст, потому что AntiFraudLoyaltyService при active_card_count>1 намеренно не делает VIP_HISTORY. USER_ID 6645 имеет ту же multicard-проблему, но targeted Check-in дополнительно возвращает 0 — это надо трассировать отдельно. 24-часовой cache как причина исключён. Последний read-only source audit = 9 PASS / 0 FAIL / 0 WARN. Следующий шаг: спроектировать минимальный multicard-safe fix, где операторская физическая история использует targeted 60-day Check-in source, и отдельно найти причину отсутствия Check-in у USER_ID 6645. Ничего в production по этому дефекту пока не менять без нового guarded шага.

Параллельный TOTP workstream: пилот USER_ID 880339 уже полностью enrolled, native otpUsed proof подтверждён, direct web PIN pilot deployed. В свежей incognito password→TOTP сессии кнопка «Показать Пин-код» появилась и PIN реально показался. Полный business E2E ещё не закрыт только потому, что пока не подтверждено фактическое использование этого PIN при списании. Rollout за пределы 880339 запрещён; cookie-restored otpUsed=false должен оставаться без direct PIN.

Не повторяй завершённые discovery/deploy проверки. Сначала назови восстановленную текущую точку и только потом продолжай.
```
