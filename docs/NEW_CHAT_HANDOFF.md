# Evrasia — New Chat Handoff

> Fast handoff for continuing Evrasia AI Bot in a new ChatGPT chat.
>
> **Updated: 2026-09-20** after PR #62 production acceptance for operator-visible manual-investigation results.

## Ready-to-paste instruction for a new chat

Продолжаем проект Evrasia AI Bot. Не начинай работу заново и не проси меня повторять уже установленный контекст.

Репозиторий: `juvantusik/Evrasia_AI_bot`.

Сначала полностью прочитай:

0. `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md` — **самый свежий authoritative handoff для текущей ветки Anti-Fraud / Check-in Scout / ручной проверки по телефону**;
1. `docs/PROJECT_CHECKPOINT.md` — общий authoritative checkpoint;
2. `docs/AI_PROJECT_CONTEXT.md` — текущий проектный/технический контекст;
3. `docs/CURRENT_ARCHITECTURE.md` — актуальная архитектура;
4. `docs/SERVER_SCRIPT_RULES.md` — обязательные правила серверных скриптов;
5. `SERVER_UPDATES.md` — фактическая история production/server updates;
6. `docs/ANTI_FRAUD_OPERATOR_SETTINGS.md` — текущая операторская настройка Anti-Fraud;
7. `docs/ANTI_FRAUD_UI_NEXT.md` — исторический UI-план с отметкой, что прежние пункты уже реализованы; текущий следующий шаг — Step 2 из checkpoint 2026-09-20;
8. `docs/TRUSTED_DEVICE_DIAGNOSTICS.md` — authoritative method для вопроса «сколько накопилось device_id/device hash именно для Trusted Device/SMS trust-механизма»; считать на Bitrix host `evrasia` из `ev_trusted_devices`, не из Anti-Fraud PostgreSQL;
9. `docs/WEBSITE_LEGAL_CONSENT_INTEGRATION.md` — связь Anti-Fraud с обновлёнными офертой/политикой сайта и будущей регистрацией/фиксацией согласий;
10. `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md` — planned TOTP 2FA / protected-profile design and production findings;
11. `docs/NEW_CHAT_HANDOFF.md` — этот handoff.

Приоритет источников: **production actual state → current GitHub → staging/test → current docs → older discussion**. Не повторяй уже завершённые проверки и deployment-шаги.

После docs-only commit GitHub `main` может быть новее deployed application revision. Перед следующей production mutation всегда отдельно проверяй фактический runtime image/revision.

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

## 9. Immediate continuation point

Current status on 2026-09-20:

- Check-in Scout Step 1: **DONE / MERGED / DEPLOYED / VERIFIED**;
- PR #57 Trusted Device display: **DONE / DEPLOYED**;
- PR #58 Russian device labels + Scout display wording: **DONE / DEPLOYED / OPERATOR ACCEPTED**;
- Bitrix protected phone resolver for manual Anti-Fraud investigation: **DONE / PRODUCTION / VERIFIED**;
- PR #60 bot-side manual investigation by phone: **DONE / PRODUCTION / VERIFIED**;
- PR #62 operator-visible 60-day history / Device ID / linked USER_ID results: **DONE / PRODUCTION / VERIFIED**.

The current Anti-Fraud continuation starts from the accepted PR #62 state; do not re-implement **«Добавить на проверку»** or repeat already-completed rollout steps merely for reassurance:

1. protected bot gateway to `/api/internal/anti-fraud/phone-resolve`;
2. persistent operator-investigation table/state;
3. explicit operator-authorized 60-day enrichment without faking `riskGateConfirmed`;
4. normal scoring;
5. UI action **«Добавить на проверку»**, phone-first;
6. separate persistent operator source/reason such as `Авито`;
7. no auto-block;
8. tests → staged rollout → production verification.

Do not redo phone-resolver research/deployment or Check-in Scout deployment.

Use `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md` as the detailed continuation source.

If the operator explicitly asks to continue the TOTP 2FA workstream, switch to that workstream; otherwise do not mix TOTP into the current Anti-Fraud continuation.

---

## 10. TOTP 2FA continuation

If the operator explicitly asks to continue the TOTP 2FA / protected-profile workstream, resume from:

`docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`

Do not repeat the already completed production read-only discovery.

The business request is:

- optional TOTP 2FA using Яндекс Ключ / Google Authenticator / compatible TOTP clients;
- enrollment requires SMS confirmation of the current account phone **before** exposing the TOTP provisioning QR/secret;
- TOTP protects login; it does not replace the bonus-spending PIN;
- after safe enrollment, the intended benefit is to show the actual bonus-spending PIN directly in the personal account without waiting for VK/SMS;
- all ordinary profiles keep current behavior;
- initial pilot is Bitrix USER_ID `880339` only.

Confirmed production facts:

- native `Bitrix\Security\Mfa\Otp` exists;
- dormant OTP-aware AJAX login code is deployed;
- signin backend SHA: `06dcf40fcc5ab5b8ff5b77843bd02424f2136628bff8e2114152bb2a2555fb48`;
- signin JS SHA: `3f704994375adc0e074907effce43ef64a443c1a0e6708a780d84bd239cd9fc2`;
- signin template SHA: `b8e65a204c69faa1e4c3ce84c6db047947c309e4742b3649eae351649a26eec4`;
- `OTP_ENABLED=NO`, `OTP_MANDATORY=NO`, `b_sec_user` empty, pilot USER_ID `880339` has no OTP row;
- current web bonus PIN endpoint remains `/local/php_interface/pincode.php` and still delivers through VK/SMS;
- existing logout/revocation code can revoke pre-2FA sessions;
- retained rollback backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/totp-login-step-v3-20260921-172331`.

Ordinary website login E2E with global OTP still OFF is **PASSED**: the operator confirmed normal login with no OTP prompt. Pilot-only profile enrollment UI + SMS ownership challenge for USER_ID `880339` are deployed, and the compact combined security/logout UI is visually accepted by the operator. Global OTP remains OFF. The real pilot SMS ownership test and browser QR/TOTP enrollment for USER_ID `880339` are **PASSED**. Read-only verification confirms exactly one pilot TOTP row, active and initialized, with no non-pilot OTP rows and no recovery codes; global OTP is still OFF. Deployment SHAs remain account template `222b3c19d46da4b127e79b37a96c3d3676f8ba0b0bf84a26ec9f3b71cd21de41`, account JS `352447d463aa0dcea91abfae1d0690682424343bf6fbdb9dc8428dee9b6cb17a`, TOTP component `4a1bfbd277980dd529f89b43bc834498a543de3f371df4870963a3a4e6b09833`. Immediate next step: guarded optional global OTP enablement and revocation of pre-2FA pilot sessions, then password→TOTP login E2E. Direct PIN remains unchanged.

Critical invariant: direct PIN must require a session that actually passed TOTP; do not grant direct-PIN privilege merely because the account has active OTP.
