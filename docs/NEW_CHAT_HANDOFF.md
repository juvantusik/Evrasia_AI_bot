# Evrasia — New Chat Handoff

> **LATEST PRODUCTION 2026-10-15 / CORE PHONE UPDATE BOUNDARY 5/5 PASS (accepted operator stdout).** Executed on root `evrasia`: `v5_native_phone_core_observed_snapshot_readonly.sh` source-only diagnostic **PASS_COUNT=5 FAIL_COUNT=0 FINAL_STATUS=PASS FINAL_RC=0 DIAGNOSTIC_RUN_RC=0 WRAPPER_PASS_COUNT=5 WRAPPER_FAIL_COUNT=0 WRAPPER_FINAL_STATUS=PASS WRAPPER_FINAL_RC=0**. Native `bitrix/modules/main/lib/UserPhoneAuthTable.php` SHA `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991` unchanged: `getMap()` at39, `CONFIRMED` field at64, **NATIVE_CONFIRMED_DEFAULT_LEXICAL_KIND=LITERAL_N**. `bitrix/modules/main/classes/general/user.php` observed unchanged exact SHA `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99`: `CUser::Update` declaration3920; **CORE_UPDATE_DIRECT_PHONE_TABLE_MUTATORS=2** within it: `UserPhoneAuthTable::delete`4098 and `::add`4102; **CORE_UPDATE_LITERAL_CONFIRMED_REFERENCES=0** in this exact scope. Token-only static lexical source evidence; raw SQL, dynamic aliases, triggers, actual persisted data, runtime branch reachability or transaction effects are **NOT** proven. Source `user.php` historical verified SHA `542034ca4eee2c30bdc033f45d792945c10392aeaf2e482c01958e2bd585b9d0` remains a prior reference; current SHA drift reason **NOT DETERMINED**, **CORE_SOURCE_PROVENANCE_VERIFIED=NO, PRODUCTION_BASELINE_REPLACED=NO**. No Bitrix bootstrap/site PHP execution, DB, SMS, production writes. Older pending instruction to run this observed-snapshot probe is now HISTORICAL and must not be repeated.

> **NEXT — NEW NARROW ANALYZER READY, CI PASSED; PRODUCTION NOT RUN:** `site-integration/samzaberu-v5/tests/diagnostics/V5CoreUserPhoneReplaceArgs.php` + `v5_core_user_phone_replace_args_readonly.sh` + synthetic `V5CoreUserPhoneReplaceArgsSelfTest.sh` in feature commit `b3bc8e88af5ee0e7bf8ab39ae9fb52b148bf4a10`, [GitHub Actions 38701698533](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38701698533) **SUCCESS** incl 9/9 new synthetic guards and earlier V5 offline suites. Checks ONLY exact current observed core `user.php` buffer (SHA pinned; historical drift NOT approved), identifies exact direct `CUser::Update` **declaration line 3920**, `UserPhoneAuthTable::delete`4098 and `::add`4102; reports first argument shapes, three ALLOWLIST inline keys (USER_ID, PHONE_NUMBER, CONFIRMED), literal YES/NO enum for CONFIRMED when present and braces relation, not raw code/values. Synthetic tests cover explicit CONFIRMED, indirect variable, divergent blocks, comment/string decoys, core SHA mismatch, wrong-host refusal. Operator should run **one** download/blob-SHA/host/path/sha-guarded read-only Bash wrapper, then return stdout; do not repeat previous phone/MFA/PinCode checks. Native confirmed phone writer, same E.164 reconfirm event, PHONE_EVENT_VERSION, AUTH_EPOCH atomicity, isolated Bitrix/MySQL staging and V5 deployment remain **UNPROVEN**; HTTP503 only last documented V5 baseline. V4 unchanged.

> **LATEST 2026-10-15 / NATIVE CORE USER SOURCE SHA DRIFT — PRODUCTION GUARD STOP, NEW OBSERVED-SNAPSHOT PROBE READY (NOT PRODUCTION-RUN).** Operator on `evrasia` tried `v5_native_phone_core_update_boundary_readonly.sh` from feature code commit `e7ab91322563438691a9cc98ab6580d91843acf7`: downloaded PHP/launcher Git blob SHA matched and lint passed; `UserPhoneAuthTable.php` native SHA `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991` matched, but `bitrix/modules/main/classes/general/user.php` failed its old pinned SHA `542034ca4eee2c30bdc033f45d792945c10392aeaf2e482c01958e2bd585b9d0`, `PHP_SCANNER_EXECUTED=NO`, `PASS_COUNT=2 FAIL_COUNT=1 FINAL_STATUS=FAIL RUN_RC=1 WRAPPER_FINAL_STATUS=FAIL`. **Fail-closed worked; DO NOT repeat the unchanged failing launcher.** A separate exact file-only root read-only check returned `PASS_COUNT=4 FAIL_COUNT=0 FINAL_STATUS=PASS WRAPPER_FINAL_RC=0`: current `user.php` SHA-256 **`bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99`** stable on double hash, bytes **164490**, lines **6135**, UID/GID **1005/1005**, mode **644**, file mtime and ctime Unix **1791803345** (2026-10-12 11:09:05 UTC), metadata stable. Change cause **NOT DETERMINED**; observed SHA is **not approved** as production/release baseline. No Bitrix bootstrap/site execution/SQL/SMS/DB/app writes.

> **NEXT / PREPARED + CI SUCCESS / OPERATOR NOT RUN:** reused the already CI-tested `V5NativePhoneCoreUpdateBoundary.php` without changing it; added *separate* `tests/diagnostics/v5_native_phone_core_observed_snapshot_readonly.sh` with old baseline retained as historical metadata and current observed SHA only for exact read/lexical parsing; flags `CORE_SOURCE_STATUS=SHA_DRIFT_OBSERVED_NOT_APPROVED`, `CORE_SOURCE_PROVENANCE_VERIFIED=NO`, `PRODUCTION_BASELINE_REPLACED=NO`. Existing historical launcher stays untouched. Synthetic suite extended with exact core-buffer SHA mismatch and wrong-host/immutable-pin checks (**10 PASS / 0 FAIL**); code commit `7156ff02ad26c7fa8c8b8c15ec5f98f608544e12`, [V5 offline CI run 38698515019](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38698515019) **SUCCESS**. Next step issue **ONE** SHA/blob/host/path-guarded copy-paste Bash launcher for root on `evrasia`, fetching precisely unchanged PHP parser and new observed-snapshot launcher from pinned code commit. It will inspect exactly the native `UserPhoneAuthTable.php` map and direct `CUser/CAllUser::Update` references using `token_get_all` only; source code/literals/identifiers not printed. `PASS` means bounded lexical source scan, NOT phone verification writer, change provenance, native `PHONE_EVENT_VERSION`, same-E.164 reconfirm, shared `AUTH_EPOCH` transaction or staging. V4 unchanged; V5 not deployed. Previously accepted PinCode/PhoneAuth `check` 5/5 class linkage and old phone/MFA audits are CLOSED; do not repeat.

> **СЛЕДУЮЩИЙ УЗКИЙ ШАГ / PREPARED + CI PASS / PRODUCTION NOT RUN — 14.10.2026.** Последняя принятая production-проверка остаётся `v5_object_check_class_linkage_readonly.sh` **5/5 PASS**: `PinCode.php` FQ-constructor44 **не совпадает** с lexical class owner `PhoneAuth.php` check46; внутри `PhoneAuth.php` вызов `$this->check()`46 и direct declaration `check()`135 относятся к одному классу. Эта ветка исследования **ЗАКРЫТА, НЕ ПОВТОРЯТЬ**. Новая, действительно другая зависимость: Bitrix **`bitrix/modules/main/lib/UserPhoneAuthTable.php`** (предыдущий production pin SHA `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991`) и **`bitrix/modules/main/classes/general/user.php`** (исторический verified SHA `542034ca4eee2c30bdc033f45d792945c10392aeaf2e482c01958e2bd585b9d0`). Новый source-only анализатор в feature `tests/diagnostics/V5NativePhoneCoreUpdateBoundary.php`, launcher `v5_native_phone_core_update_boundary_readonly.sh`, synthetic suite `V5NativePhoneCoreUpdateBoundarySelfTest.sh`, код commit `e7ab91322563438691a9cc98ab6580d91843acf7`; [GitHub Actions 38607925628](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38607925628) **SUCCESS**, дополнительные synthetic **7/7 PASS**. Следующее действие оператора: **один** полный root/`evrasia` Bash-блок, который скачивает два parser/launcher файла строго из code commit, сверяет точные Git blob SHA, синтаксис и production host/root/canonical paths/exact SHA, затем запускает только source lexer. Новый вопрос: literal/default field `CONFIRMED` внутри нативного `getMap()`; только прямые `UserPhoneAuthTable::add/update/delete/replace/merge` и literal `CONFIRMED` references **внутри `CUser/CAllUser::Update`**, без SQL-string/dynamic/aliases semantics. `PASS` доказывает лишь успешно выполненный bounded source scan; `native verified phone writer`, `PHONE_EVENT_VERSION`, same-E.164 reconfirm, атомарность `AUTH_EPOCH`, runtime/staging **не доказаны**. Native phone core source НЕ выполняется, Bitrix bootstrap/SQL/SMS/prod code writes нет. V5 undeployed; 503 last documented HTTP baseline. Все предыдущие MFA, V4 caller/phone/PIN и check probes закрыты. Более старые NEXT ниже — исторические.

> **НОВЕЙШИЙ ПРИНЯТЫЙ PRODUCTION STDOUT — 14.10.2026 / CLASS LINKAGE 5/5 PASS.** На `evrasia` оператор выполнил `v5_object_check_class_linkage_readonly.sh`: `PASS_COUNT=5`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `DIAGNOSTIC_RUN_RC=0`, `RUN_RC=0`, `WRAPPER_PASS_COUNT=5`, `WRAPPER_FAIL_COUNT=0`, `WRAPPER_FINAL_STATUS=PASS`, `WRAPPER_FINAL_RC=0`. GitHub diagnostic code commit `a88c7199ee0bdf808afee6c5aa9fca6b09e46fbb`, [CI 38605496088](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38605496088) SUCCESS; exactly two pinned production sources intact: `Controllers/V4/PinCode.php` SHA `7734ddbaba0e7ec9ddfc9f0a9008c0da0c0a1523e505e52caebac2bbaf637cd0`, `lib/PhoneAuth.php` SHA `a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27`. **PIN_CONSTRUCTOR_FQCN_MATCHES_PHONE_OWNER=NO**: absolute constructor class used at PinCode line44 is lexically **not** the declared owner of PhoneAuth line46. **PHONE_OWNER_CLASS_DECLARATION_LINE=5**, `PHONE_OWNER_KIND=CLASS`, `PHONE_OWNER_BASENAME_HINT=PHONEAUTH_BASENAME`, `PHONE_OWNER_FQCN_LEXICALLY_RESOLVED=YES`; `PhoneAuth.php` line46 is `$this->check()`, line135 is directly declared `check()` of **same lexical class**: `PHONE_CHECK_DIRECT_CLASS_MEMBER=YES`, `PHONE_THIS_OWNER_AND_CHECK_DECLARATION_SAME_CLASS=YES`. No PHP site execution/Bitrix bootstrap/SQL/SMS or production app changes. **Do not repeat** caller inventory, locator, receiver binding, origin/namespace or class-linkage audits. This resolves the *lexical relationship*, **not** runtime class/route, verified phone writer, `PHONE_EVENT_VERSION` or V5 readiness. `CLASS_NAME_RESOLVED_TO_RUNTIME=NO`, `RUNTIME_METHOD_DISPATCH_PROVEN=NO`, `PIN_ASSIGNMENT_DOMINANCE_PROVEN=NO`, `CHECK_PROVES_NATIVE_PHONE_VERIFICATION=NO`. Native phone event, same-number reconfirm, shared `AUTH_EPOCH` transaction, 10 native mutation paths and isolated Bitrix/MySQL staging still unproven; V5 not deployed, HTTP503 only last documented baseline.

> **Следующий этап — PLANNED ONLY / NOT IMPLEMENTED / NOT RUN:** исключить уже опровергнутую прямую лексическую связь PinCode→PhoneAuth из поиска phone verification writer. Не выполнять ещё один такой же probe. Выбрать только действительно новый bounded source-only вопрос о **владельце фактического native `b_user_phone_auth.CONFIRMED` verified-event write**, с учётом уже закрытых V4 Auth signup/add372, PhoneAuth code store, закомментированных Auth.confirm*/строк891/1012, предыдущих MFA/phone audits; при отсутствии подтверждённой native точки назвать release gate нерешённым и не создавать runtime/staging/production write без отдельного staging acceptance. Последующие старые указания ниже — ИСТОРИЯ.

> **НОВЕЙШАЯ ТОЧКА 14.10.2026 ПОСЛЕ origin/namespace 5/5:** production `v5_object_check_origin_namespace_readonly.sh` **5/5 PASS**, wrapper **5/5 PASS**. PinCode.php44 `new` uses absolute FQ class token (identity masked), check46 same braced block but dominance unproven. PhoneAuth.php46 receiver is `$this` inside function27; namespace/class ownership and relation to declared `check` at135 pending. Следующий код **подготовлен ТОЛЬКО В feature**, [CI 38605496088](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38605496088) SUCCESS, commit `a88c7199ee0bdf808afee6c5aa9fca6b09e46fbb`: `V5ObjectCheckClassLinkage.php` и `v5_object_check_class_linkage_readonly.sh`. **PRODUCTION NOT RUN**: выдать один SHA/host guarded copy-paste Bash launcher для `evrasia` root, затем анализировать STDOUT. V4/DB/SMS/prod неизменны; старые 6/6, 5/5, MFA audits не повторять. Подробнее см. [основной handoff](EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-14.md).

> **НОВЕЙШАЯ ТОЧКА ПРОДОЛЖЕНИЯ ВСЕГО PROJECT — 2026-10-14:** [EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-14.md](EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-14.md). Latest actual production `v5_object_check_receiver_binding_readonly.sh` **6/6 PASS** accepted. PinCode: check46, constructor-expression assignment44, class OTHER_CLASS_MASKED; PhoneAuth: check46, function27/body27–63, zero detected direct assignments (PARTIAL). Both receivers unresolved. Next narrow origin/namespace question **PLANNED ONLY, NOT IMPLEMENTED, NOT RUN**. Previous binding/locator/caller/phone/MFA steps below completed/historical; do not repeat. V4/production/DB/SMS unchanged; V5 and isolated Bitrix/MySQL staging not deployed.


> **НОВОЕ V5 ПЕРЕДАЧА / 2026-10-13 (заменяет старый 2026-10-12 текст ниже):** [SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md](SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md). Принят результат `v5_native_mfa_writer_order_readonly.sh`: production READ_ONLY 9 PASS/0 FAIL, 6 SHA pins; исследован ORM before/write/after, OTP recovery clear перед UserTable merge и фоновый MFA агент. Сейчас в feature подготовлен `v5_native_recovery_merge_readonly.sh`, следующий полученный вывод нужно анализировать **без переповтора предыдущих проверок**. Отдельный Bitrix/MySQL staging не подтверждён, native 10-path AUTH_EPOCH атомарность НЕ доказана, V5 HTTP 503 по последнему baseline. GitHub offline CI 22/22 suites, 48 PHP lint PASS. Ни prod, ни V4, ни DB не менялись.


## Точка продолжения V5 — 2026-10-12: вывод следующего скрипта

**Самый актуальный handoff для SamZaberu API V5:** [`docs/SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-12.md`](SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-12.md). Он **новее** старых Anti-Fraud/TOTP точек в этом файле. Источником структур остаётся [`docs/STRUCTURE_REGISTRY.md`](STRUCTURE_REGISTRY.md). Рабочий код V5 — `feature/samzaberu-api-v5-auth-contract`, НЕ production. На production Bitrix/V4 изменений по этой итерации нет; V5 HTTP 503.

**Если в новом чате пользователь первым сообщением присылает консольный вывод с `SOURCE=Connection`, `SOURCE=MysqlCommonConnection`, `TRANSACTION_RELATED_VARIABLES` и `FINAL_STATUS`, это выход следующей, УЖЕ СОГЛАСОВАННОЙ read-only диагностики** `site-integration/samzaberu-v5/tests/diagnostics/v5_native_depth_readonly.sh`. Не просить пользователя снова описывать проект, не повторять `b_user`/V4/OTP аудиты. Немедленно прочитать checkpoint и **анализировать именно полученный вывод**, особенно native Bitrix `startTransaction`, `commitTransaction`, `rollbackTransaction` и внутреннюю глубину. `PASS` означает только успешную статическую инспекцию, НЕ подтверждённую runtime-транзакцию.

Последняя реализация: `V5StagingNativeTransactionPermit`, **62/62 OFFLINE PASS**, но trusted `V5VerifiedNativeTransactionDepthPort` отсутствует; реальная MySQL/Bitrix staging acceptance не запускалась. Не создавать fake «всегда 0» в production, не подключать V5 к HTTP, не трогать native MFA hooks до доказательства десяти атомарных путей изменения безопасности.

**В этом потоке следующий шаг — обработать stdout последнего script из нового чата.** Остальная история ниже относится к другим/более старым задачам.


> Fast handoff for continuing Evrasia AI Bot in a new ChatGPT chat.
>
> **Updated: 2026-10-03**. PR #73 and the 2026-10-02/03 Anti-Fraud incident are production/browser accepted; nginx IP-wait guard is installed with normal-reboot validation still pending. SamZaberu Legal Consents registration implementation remains pending.

## Ready-to-paste instruction for a new chat

Продолжаем проект Evrasia AI Bot. Не начинай работу заново и не проси меня повторять уже установленный контекст.

Репозиторий: `juvantusik/Evrasia_AI_bot`.

Сначала полностью прочитай:

0. `docs/ANTI_FRAUD_PR73_PRODUCTION_ACCEPTANCE_2026-10-03.md` — **ТЕКУЩИЙ AUTHORITATIVE production acceptance**
0A. `docs/ANTIFRAUD_INCIDENT_HANDOFF_2026-10-02.md` — закрытый incident/root-cause record
0B. `docs/SAMZABERU_LEGAL_CONSENTS_REGISTRATION_2026-10-02.md` — accepted design, implementation pending
0C. `docs/ANTI_FRAUD_PR65_PRODUCTION_ACCEPTANCE_2026-09-23.md` — предыдущий Anti-Fraud acceptance
1. `docs/PROJECT_CHECKPOINT.md` — общий authoritative checkpoint;
1A. `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md` — исторический checkpoint PR #62 и предшествующей архитектуры; читать для контекста, но не использовать как текущий production baseline;
2. `docs/AI_PROJECT_CONTEXT.md` — текущий проектный/технический контекст;
3. `docs/CURRENT_ARCHITECTURE.md` — актуальная архитектура;
4. `docs/SERVER_SCRIPT_RULES.md` — обязательные правила серверных скриптов;
5. `SERVER_UPDATES.md` — фактическая история production/server updates;
6. `docs/ANTI_FRAUD_OPERATOR_SETTINGS.md` — текущая операторская настройка Anti-Fraud;
7. `docs/ANTI_FRAUD_UI_NEXT.md` — исторический UI-план с отметкой, что прежние пункты уже реализованы; текущий следующий шаг — Step 2 из checkpoint 2026-09-20;
8. `docs/TRUSTED_DEVICE_DIAGNOSTICS.md` — authoritative method для вопроса «сколько накопилось device_id/device hash именно для Trusted Device/SMS trust-механизма»; считать на Bitrix host `evrasia` из `ev_trusted_devices`, не из Anti-Fraud PostgreSQL;
8A. `docs/SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md` — authoritative acceptance внешнего `sz_ + 64hex` mobile contract и site -> bot Trusted Device E2E;
9. `docs/WEBSITE_LEGAL_CONSENT_INTEGRATION.md` — связь Anti-Fraud с обновлёнными офертой/политикой сайта и будущей регистрацией/фиксацией согласий;
10. `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md` — authoritative TOTP 2FA / protected-profile checkpoint, now including production pilot and accepted disable-flow fix;
11. `docs/NEW_CHAT_HANDOFF.md` — этот handoff.

Приоритет источников: **production actual state → current GitHub → staging/test → current docs → older discussion**. Не повторяй уже завершённые проверки и deployment-шаги.

После docs-only commit GitHub `main` может быть новее deployed application revision. Перед следующей production mutation всегда отдельно проверяй фактический runtime image/revision.

### Текущая точка продолжения — НЕ ПЕРЕПРЫГИВАТЬ

Anti-Fraud incident 2026-10-02/03 **закрыт**.

Подтверждено:

- nginx outage был startup race между systemd `network-online.target` и фактическим DHCP assignment; permanent drop-in установлен;
- USER_ID 881346 был фактически успешно разблокирован; временно отставал только bot-local `display_name`;
- PR #73 устранил это окно: после успешного `unblocked` точный финальный ` - блок ИТ` удаляется из локального cache сразу;
- production deploy PR #73: **58 PASS / 0 WARN / 0 FAIL**;
- пользователь выполнил browser acceptance и подтвердил: **«все работает»**.

Не повторять этот incident для reassurance.

Открытые отдельные направления:

- PR #69 — bonus-display UI, отдельно от PR #73;
- SamZaberu Legal Consents first-registration implementation — ещё pending;
- nginx boot guard следует принять на следующем нормальном/явно согласованном reboot, не перезагружать production только ради теста.

---

## 1. Current production baseline

Host: `eur-bot-01` (`192.168.103.200`).

Accepted deployed application baseline after PR #73:

- application revision: `949aec3fd76af2d6525f7705ad31cd798d533fbb`
- immutable CI image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:8f1cbe957e8f85afd00a5c28a03793e602c09b9e8eba3d9a96a8e5366af79ed3`
- image ID: `sha256:4ed7d900f318acce1ecc3d8aac0cfd7a4cfd882990735d1e089235dd13ee0478`
- app: `evrasia-ai-bot-app`
- DB: `evrasia-ai-bot-db`
- DB name/role: `evrasia_ai_bot`
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- canonical Compose SHA256: `4580356ddcfbe37e639895510c872a5c13831777a8ee6d032147171bf762af80`
- production migrations: **26**
- app state at acceptance: **running healthy**, restart count 0
- PR #73 deployment backup: `/opt/evrasia-ai-bot/backups/pr73-prod-deploy-20261003-103819`
- nginx IP-wait drop-in SHA256: `835832635b2593dc8786b96ed602a1957931782c482cf15fbe91d180afda8c39`
- browser acceptance: **PASS**


PR #58 is UI-only relative to the PR #57 application baseline: no DB migration, no scoring/grouping change and no Bitrix write.

Current device labels in case details:

- one USER_ID on a Trusted Device hash → **Устройство**;
- multiple USER_ID on the same hash → **Общее устройство**;
- both labels refer to the same kind of Trusted Device hash; the difference is only the linked-account count.

Current Scout display wording:

- `дней с 3 чекинами за 7 дней`;
- `дней с 3+ чекинами за 60 дней`.

**Important:** PR #58 changed the first phrase only in the UI. Backend field `days_2plus_7d` and its `>=2` calculation were not changed. Do not silently change Scout business logic based on the label.

For the current Anti-Fraud production state and continuation point, read `docs/ANTI_FRAUD_PR73_PRODUCTION_ACCEPTANCE_2026-10-03.md` first.

---

## 1A. Latest Anti-Fraud production acceptance — PR #73 / PR #67 / PR #65

PR #73 is **MERGED / DEPLOYED / PRODUCTION / VERIFIED / BROWSER ACCEPTED**. It removes the transient local `display_name` lag after a real successful unblock and closes the 2026-10-02/03 incident. Full record: `docs/ANTI_FRAUD_PR73_PRODUCTION_ACCEPTANCE_2026-10-03.md`.

PR #65 is **MERGED / DEPLOYED / PRODUCTION / VERIFIED / ACCEPTED**.

PR #67 completed the browser-visible history layer: the physical-history panel no longer depends on legacy `loyaltyHistoryLoadedAt`. The operator visually confirmed the interface after deployment and backfill.

Legacy latest-ready investigations were backfilled: 5 accounts, leaving **0** latest-ready investigations without physical snapshot and **7** with physical coverage. Backfill result: **55 PASS / 0 FAIL / 0 WARN**.

Key invariant:

- operator 60-day physical history is stored in `anti_fraud_operator_investigation_visits`, scoped by `investigation_id`;
- UI visit/day/restaurant metrics read from that snapshot;
- targeted physical snapshot event IDs do **not** enter `anti_fraud_visits`;
- `anti_fraud_visits` remains automatic risk/history telemetry.

Production acceptance:

- USER_ID `6645`: source 10 → snapshot 10; UI 10 physical visits / 9 visit days / 4 restaurants; snapshot-to-`anti_fraud_visits` intersection 0;
- USER_ID `408974`: source 8 → snapshot 8; UI 8 physical visits / 7 visit days / 7 restaurants; snapshot-to-`anti_fraud_visits` intersection 0;
- combined acceptance: **31 PASS / 0 FAIL / 0 WARN**.

The accompanying website targeted Check-in dedup is also accepted: current site service SHA `5f65703d91ee31a9d829cd64cefd011309c8a6c44d3fa96d2d8c77a1f81541e9`.

Full record: `docs/ANTI_FRAUD_PR65_PRODUCTION_ACCEPTANCE_2026-09-23.md`.

---

## 1B. Previous Anti-Fraud operator-visible acceptance — PR #62

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

## 1C. SamZaberu mobile Trusted Device — PRODUCTION / END-TO-END ACCEPTED

Do not confuse this with the SamZaberu Telegram scenario. This section describes the **mobile application installation identity** used by Trusted Device / Anti-Fraud.

Accepted production contract on 2026-09-24:

- external `device_id`: literal `sz_` + 64 lowercase hex = 67 chars;
- one random ID per app installation;
- prefix is a namespace and must not be stripped;
- mobile adapter hashes the entire namespaced value, including `sz_`, into a 64-hex core identity;
- shared `TrustedDeviceService` remains unchanged.

Production mobile adapter:

`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/lib/Services/TrustedDeviceMobileService.php`

SHA256:

`c59d2a9e1b70aa026b603b457673a842dbaa6b784eae25b40c5e03684b9e612d`

Safe USER_ID `880339` created exactly one new ACTIVE/PASSWORD `SAMZABERU_IOS` row on the site. The next Trusted Device sync then delivered exactly one matching current link and two matching auth events to the bot PostgreSQL.

Final business proof:

`BOT_INGEST_RESULT=PASS_DEVICE_LINK_SYNCED`

The registration/ingestion task is **closed**. Current `client_type=NULL` is only a separate non-blocking metadata/display follow-up.

Read the exact acceptance, backup and failure-root-cause record in:
`docs/SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md`.

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

Separately, the SamZaberu **mobile app** now supplies Trusted Device installation identity to the website; that path is not a separate bot/container and is documented in section 1C.

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

## 9. Historical continuation point — 2026-09-20

> Superseded. Do not use this section as the current continuation point; use the top of this file and `docs/ANTI_FRAUD_PR73_PRODUCTION_ACCEPTANCE_2026-10-03.md`.

Historical status on 2026-09-20:

- Check-in Scout Step 1: **DONE / MERGED / DEPLOYED / VERIFIED**;
- PR #57 Trusted Device display: **DONE / DEPLOYED**;
- PR #58 Russian device labels + Scout display wording: **DONE / DEPLOYED / OPERATOR ACCEPTED**;
- Bitrix protected phone resolver for manual Anti-Fraud investigation: **DONE / PRODUCTION / VERIFIED**;
- bot-side Step 2 manual investigation: **NOT YET IMPLEMENTED**.

The next Anti-Fraud implementation should continue with the **bot side of “Добавить на проверку” by phone**:

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

If the operator writes **ПАНДА ДВА**, switch to the paused TOTP workstream instead; otherwise do not mix TOTP into the current Anti-Fraud continuation.

---

## 10. Keyword continuation — ПАНДА ДВА

If the operator writes **ПАНДА ДВА**, resume the TOTP 2FA / protected-profile workstream from:

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
- global `OTP_ENABLED=NO` at the checkpoint;
- pilot OTP state is clean/uninitialized/unactivated;
- native tables are `b_sec_user` and `b_sec_recovery_codes`;
- current web login uses AJAX `eurasia:signin/process` -> `CUser->Login(...)`;
- current web bonus PIN endpoint is `/local/php_interface/pincode.php`, which gets the RestIS PIN then delivers through VK/SMS;
- existing logout/revocation code can revoke pre-2FA sessions.

The immediate next step is **not** a blind WRITE. First run the final narrow pre-write audit from the dedicated checkpoint: current `b_sec_user` population, exact OTP option values, optional/mandatory mode, native setup sequence and login event wiring. If safe, proceed with the guarded pilot only.

Critical invariant: direct PIN must require a session that actually passed TOTP; do not grant direct-PIN privilege merely because the account has active OTP.


## 10A. Latest TOTP pilot state — 2026-09-24

Pilot scope remains USER_ID `880339` only.

The TOTP disable flow is now **PRODUCTION / BROWSER ACCEPTED**.

Root cause of the previous failure: `disableTotpAction()` reused enrollment-only `requirePilotUser()`, which rejects globally-enabled OTP and an already-existing OTP row. A dedicated `requirePilotUserForDisable()` guard was added for the disable path only.

Production TOTP component SHA256 after the fix:

`16220efe992af6fbf0b2910adb29be0ecdb3b100b7ad0d43fa4de58b73495789`

Backup:

`/home/site_evrasia/web/evrasia.spb.ru/backups/totp-disable-guard-fix-20260924-060347`

Bitrix core `Otp.php` SHA256 remained:

`285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c`

Operator browser acceptance: fresh TOTP code successfully disabled 2FA and the page returned to the unprotected state.

Do not restore the old generic enrollment guard in the disable action.
