# Evrasia AI Bot — передача проекта в новый чат (14 октября 2026)

> **НАЧАТЬ ЗДЕСЬ.** Это краткая точка продолжения Project (все чаты Evrasia AI Bot относятся к одной общей истории). Следующим сообщением пользователь должен получить **один готовый безопасный скрипт** для следующего source-only этапа, а не предложение повторить сделанное. Подробная архитектура/история в документах ниже. При противоречиях: факт production > текущий код GitHub > staging > документация > старые обсуждения.

## 1. Последняя точка, не терять

**Latest accepted operator stdout:** `v5_object_check_locations_readonly.sh` on production `evrasia`: `PASS_COUNT=5`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `RUN_RC=0`, `LAUNCHER_RC=0`, `WRAPPER_FINAL_RC=0`. Diagnostic commit `5bfc1cb6bef8a43999cdffeb5fad20eedea6dab7`; shell SHA-256 `c0436aa98a0520d50c9dbdef618aae1f99d63f420139c5c8a182d7ee2b172e38`, analyzer SHA-256 `8f128d2a67acd2246882a8f74508ca10cbe75f2a1d81df1f11bac8c2874299f9`; Bash syntax and PHP lint RC=0. Previously pinned Auth.php/PhoneAuth.php SHA matched; trusted root-owned system tokenizer.so under `php -n`.

**New source-only facts:** `OBJECT_CHECK_LOCATIONS_EMITTED=2`, `OBJECT_CHECK_SOURCE_FILES=2`:
- `local/php_interface/Controllers/V4/PinCode.php`, SHA-256 `7734ddbaba0e7ec9ddfc9f0a9008c0da0c0a1523e505e52caebac2bbaf637cd0`: object operator line **46**, `METHOD_TOKEN_LINE=46`, `RECEIVER_SHAPE=VARIABLE_MASKED`.
- `local/php_interface/lib/PhoneAuth.php`, SHA-256 `a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27`: object operator line **46**, `METHOD_TOKEN_LINE=46`, `RECEIVER_SHAPE=VARIABLE_MASKED`.

`METHOD_TOKEN_LINE` is the called `check` token, not an enclosing method declaration. Receiver class/variable identity/assignment origin was not disclosed or established. In particular, the `check` call in PhoneAuth.php at line46 is not evidence that its receiver is `PhoneAuth::check()` (the previously mapped method declaration is line135). Same basename or same line in two files does not establish equal receivers.

**Inventory unchanged as counts:** 18 approved PHP files / 32465 tokens; `SKIPPED_SENSITIVE_FILENAMES=0`, PhoneAuth static/new/import=0, candidate files=0, unresolved object checks=2, `OUTPUT_CAPPED=NO`. This comparison is counts only, not checksum proof of all 18 files or runtime/route evidence. `RECEIVER_CLASS_PROVEN=NO`, `REAL_SMS_SUCCESS_AND_HTTP_ROUTE_PROVEN=NO`, `NATIVE_PHONE_VERIFIED_EVENT_PROVEN=NO`, `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`.

**Было → Стало → Причина:** two unresolved object-check sites counted without locations → located at exact source/line/SHA in PinCode.php and PhoneAuth.php, both simple variable receivers → accepted production locator stdout. Completed bounded caller inventory and locator must not be repeated.

**One next step, not production-run:** `v5_object_check_receiver_binding_readonly.sh` with narrow `V5ObjectCheckReceiverBinding.php`. Three source SHA guards (Auth, PhoneAuth, newly current PinCode) before analysis; PHP reads only PinCode.php and PhoneAuth.php and requires the exact active line46 simple-variable call anchors; expected SHA is also checked against the exact PHP-read buffer before tokenization. Reports enclosing lexical scope/assignment lines, masked RHS kinds and allowlisted class-basename enums as **source hints only**, without source statements/receiver names/arbitrary class or namespace names/arguments/literals. Direct assignment coverage is partial; parameter/reference bindings and string interpolations are excluded or unresolved, so zero assignment records do not prove no receiver origin. Class namespace, control flow, aliasing, runtime receiver and HTTP/SMS outcome remain unresolved. No rescan of the 18-file discovery scope. CI: [38595306842](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38595306842) `completed/success`: PHP lint, synthetic exact-buffer SHA mismatch refusal, lexical function/nested scope/class exclusions, string/interpolation masking and bounded assignment records, wrong-host denial, prior diagnostics regression and 22 approved V5 offline suites PASS. No production runtime/SQL/SMS tests..

**Mode/release boundaries:** READ_ONLY; no php.ini, Bitrix bootstrap, site PHP execution, SQL/database connections/writes, SMS, real user data, config files or production app changes; no source values/secrets printed. Native phone verification writer, PHONE_EVENT_VERSION bump for reconfirming the same E.164, atomic AUTH_EPOCH/native MFA and all ten mutation paths, independent Bitrix/MySQL staging remain unproven. V5 HTTP 503 remains last documented baseline only. Do not repeat masked flow/phone/MFA audits or redesign V5.


## 2. Архитектура, ветки, источники

| Система | Проверенное состояние |
|---|---|
| GitHub | **juvantusik/Evrasia_AI_bot** |
| Рабочая V5 feature | **feature/samzaberu-api-v5-auth-contract**, каталог **site-integration/samzaberu-v5/**. НЕ развёрнуто |
| Общие документы | **main** и feature; checkpoint и registry между ветками должны быть идентичными |
| Сайт / Bitrix production | host **evrasia**, корень **/home/site_evrasia/web/evrasia.spb.ru/public_html** |
| Бот / Anti-Fraud production | **eur-bot-01**, приложение **evrasia-ai-bot-app**, **отдельная PostgreSQL evrasia_ai_bot**; не путать с Bitrix/MySQL |
| Staging Bitrix/MySQL | **НЕ АТТЕСТОВАН/НЕ ПОДТВЕРЖДЁН**. Staging Docker Compose бота не считается независимым staging сайта |
| API V5 | HTTP **503 по последнему документированному baseline**, не новая online verification; V4 production не менялся |

**Индекс уже установленных структур всех подсистем**, Bitrix, Trusted Device, Bot Anti-Fraud, юрсогласий, V4, MFA, источников БД: [STRUCTURE_REGISTRY.md](STRUCTURE_REGISTRY.md). **Весь текущий прогресс V5**: [SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md](SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md), читать первый раздел 2026-10-14; нижние разделы исторические. **Телефонная source форензика**: [SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md](https://github.com/juvantusik/Evrasia_AI_bot/blob/feature/samzaberu-api-v5-auth-contract/docs/SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md) в feature. Общая бот/Anti-Fraud/Consent история: [NEW_CHAT_HANDOFF.md](NEW_CHAT_HANDOFF.md), [PROJECT_CHECKPOINT.md](PROJECT_CHECKPOINT.md), [AI_PROJECT_CONTEXT.md](AI_PROJECT_CONTEXT.md); правила выдачи команд: [SERVER_SCRIPT_RULES.md](SERVER_SCRIPT_RULES.md).

**Не путать readiness:** GitHub Actions 22 V5 offline suites PASS — не означает, что V5 route, миграции, JWT, outbox, SMS worker или native hooks существуют на production. Build image main success — не свидетельство deploy на eur-bot-01 или связки V5 с Bitrix.

## 3. Принятое поведение V5 (не переделывать)

Отдельный V5 для будущей версии приложения СамЗаберу; действующий V4 не менять. После подтверждённого пароля:
- включённая TOTP — запрашивается код Authenticator и после успешного входа PIN/бонусы без нового SMS;
- без TOTP, но с **проверенным strong trust**, который был получен при реальном SMS/TOTP enrollment — можно пропустить SMS; старый V4 trust после `AUTH_PASSWORD` не является proof сильного устройства;
- без strong trust/TOTP — SMS-челлендж только на **серверно подтверждённый номер аккаунта из ЛК**, не на номер из клиентского ввода; после успешного входа нет второго SMS для PIN/бонусов;
- anti-spam и денежные квоты SMS, общее proof and replay state, revocation, TOTP double-use prevention и SMS challenge — fail-closed; V4 JWT или статус «2FA включена» недостаточны для защищённого PIN.
- SamZaberu Trusted Device: `device_id="sz_"+64 lowercase hex`, SHA-256 хеш всего ID **с префиксом**, не менять core service.
- Консенсы/оферты, антифрод, статусы «Новый», блокировки и UI — отдельные ранее реализованные подсистемы. Не переделывать или переносить их в V5 без новой задачи.

Контракт: [SAMZABERU_API_V5_AUTH_CONTRACT_2026-10-11.md](https://github.com/juvantusik/Evrasia_AI_bot/blob/feature/samzaberu-api-v5-auth-contract/docs/SAMZABERU_API_V5_AUTH_CONTRACT_2026-10-11.md), source directory `site-integration/samzaberu-v5/README.md` — feature.

## 4. Что уже доказано про телефон; какое следующее действие

**Pinned production V4 source SHA:**
- `local/php_interface/Controllers/V4/Auth.php` → `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`.
- `local/php_interface/lib/PhoneAuth.php` → `a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27`.

**Ключевая коррекция (БЫЛО → СТАЛО → ПРИЧИНА):** ранние grep/AWK нашли текст `Auth::confirm_request()`/`Auth::confirm()` и предполагаемые записи `CONFIRMED` на 891, 1012. Теперь точный whole-file PHP lexer **доказал оба якоря — COMMENT, не активный PHP**. `confirm_request` и `confirm` в исследованной версии закомментированы. Старые текстовые PASS были поиском слов в комментариях, **не** реальными native writers. Запрещено снова проектировать hook на 891 или называть эти методы действующими.

**Активный код, production source-only подтверждено:**
- В Auth.php активные методы: `sendSms` 22, `confirmation` 60, `signup` 263, `forgot_password` 551, `change_password` 680.
- `signup` line372: единственный найденный **прямой активный** `UserPhoneAuthTable::add`, короткий array со строковыми полями `USER_ID` и `PHONE_NUMBER`; явный `CONFIRMED` в этом массиве **не найден**. Native default/persistent state и реально исполненный SQL НЕ доказаны.
- В PhoneAuth.php активные методы `sendCode` 65, `check` 135, `createUser` 188, `sendSms` 220.
- `sendCode` static add106 и `check` static deletes153/160 принадлежат **\Evrasia\Orm\PhoneAuthCodeTable** (custom SMS code store), **НЕ native Bitrix UserPhoneAuthTable**. Удаление SMS-code не равно `b_user_phone_auth.CONFIRMED` event.
- Последняя полная проверка структуры if/returns и прямых V4 Auth callers — **5/5 PASS**, её результат в `1. Всё получено без запуска сайта/SQL/SMS.

**Последний object locator завершён:** `v5_object_check_locations_readonly.sh`, production 5/5 PASS. Точные две точки — object `check` line46 в `Controllers/V4/PinCode.php` и `lib/PhoneAuth.php`, оба VARIABLE_MASKED. Текущий PinCode SHA `7734ddbaba0e7ec9ddfc9f0a9008c0da0c0a1523e505e52caebac2bbaf637cd0`; PhoneAuth SHA выше. Эти вызовы нельзя заранее считать SMS verification или PhoneAuth receivers. Все completed 18-file caller and locator scans / masked phone / MFA audits не повторяются.

**Следующий узкий read-only этап, ещё НЕ выполненный оператором:**
- `site-integration/samzaberu-v5/tests/diagnostics/v5_object_check_receiver_binding_readonly.sh`;
- `site-integration/samzaberu-v5/tests/diagnostics/V5ObjectCheckReceiverBinding.php`;
- `site-integration/samzaberu-v5/tests/diagnostics/V5ObjectCheckReceiverBindingSelfTest.sh`.

Это targeted receiver-binding probe, не новый broad caller inventory. Wrapper проверяет evrasia/root и pinned Auth/PhoneAuth/PinCode SHA, затем PHP читает только две найденные source files. Expected SHA повторно сверяется с exact read buffer ДО tokenization/output; обе части отчёта публикуются только после успешных guards/анализа. Exact line46 active `->check`/`?->check` должен иметь простой variable receiver; changed/missing/ambiguous anchor или source drift → FAIL. Full PHP lexer под php -n и доверенный system tokenizer; no target include/eval/site/Bitrix/SQL/SMS. Source label allowlist ровно PinCode/PhoneAuth, canonical path/symlink/size/token-count/20 assignment record caps.

Вывод: safe relative source+SHA, anchor/token lines, nearest lexical function declaration/body lines или TOP_LEVEL, prior same-variable direct assignment line/RHS enum/known class-basename enum/nested brace depth. Имена receiver, arbitrary class/namespace, original statements, literals, argument values не печатаются. `JWTAUTHORIZATION_BASENAME` и другие известные basename hints **не** доказывают canonical FQCN или runtime receiver. `CLASS_NAMESPACE_RESOLVED=NO`, `RECEIVER_CLASS_PROVEN=NO`, `CONTROL_FLOW_AND_ALIASING_RESOLVED=NO`, binding coverage PARTIAL, string interpolation EXCLUDED, parameters/reference origins unresolved. Zero assignment records не означают отсутствия происхождения receiver. Native phone writer, actual HTTP/SMS route/outcome remain unproven.

CI: [38595306842](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38595306842) `completed/success`: PHP lint, synthetic exact-buffer SHA mismatch refusal, lexical function/nested scope/class exclusions, string/interpolation masking and bounded assignment records, wrong-host denial, prior diagnostics regression and 22 approved V5 offline suites PASS. No production runtime/SQL/SMS tests.

**Первое действие после этого handoff:** один copy-paste Bash heredoc launcher for `v5_object_check_receiver_binding_readonly.sh`, pinned code commit `747f0985d20cf72566f092233f6e6544bed5a5df`, checksum downloaded diagnostics then bash -n / php -n -l / run / structured result / /tmp cleanup / SSH open. Analyzer SHA-256 `9b60dbebff516dc2f674e99b7658379d24cf505039e1c3fad40cfe17ea933e13`; shell SHA-256 `e7c4b1875d16c28ffde1e54cfe96eaf9ca15c3fdb86f0ee1497aa3e7eb342aac`. Не повторять locator или поиск по 18 файлам. Ожидаемые stdout markers: `SOURCE`, `SOURCE_SHA256`, `ANALYZED_SOURCE_SHA_MATCHED`, `RECEIVER_OPERATOR_LINE`, `ENCLOSING_FUNCTION_DECLARATION_LINE`, `PRIOR_ASSIGNMENT_RECORDS`, `PRIOR_ASSIGNMENT_*_RHS_KIND`, `PRIOR_ASSIGNMENT_*_CLASS_BASENAME_HINT`, `RECEIVER_CLASS_PROVEN`, `FINAL_STATUS`, `FINAL_RC`.

## 5. Незакрытые release gates — не заявлять готовность V5

- `NATIVE_PHONE_VERIFICATION_PROVEN=NO`: actual verified phone state/Bitrix event writer пока не найден.
- `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`; **повторное подтверждение ТОГО ЖЕ E.164** обязано увеличивать событие/версию, не только изменение phone string.
- `AUTH_EPOCH` one-connection transaction/CAS/rollback, ordered `b_user → b_sec_user → b_user_phone_auth → auth_state` lock, все **10 native MFA/security/phone mutation paths** — real runtime аттестация отсутствует. Proposed `ev_samzaberu_auth_state` schema только в GitHub, миграции не было.
- MFA/recovery probes **9/9, 6/6, 7/7** source-only завершены. Знаем ordering: `Otp::save` clears recovery **до** merge, `onAfterDelete` cleanup **после** удаления, `RecoveryCodesTable::useCode` не доказывает atomic single-use CAS и игнорирует результат update; это risk gap, не доказанный exploit. [MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md](SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md). Не повторять source audits.
- Нет проверенного изолированного Bitrix/MySQL staging и proof actual ORM↔native SQL rollback/concurrent use; [ISOLATED_STAGING_READINESS_2026-10-13.md](https://github.com/juvantusik/Evrasia_AI_bot/blob/feature/samzaberu-api-v5-auth-contract/docs/SAMZABERU_V5_ISOLATED_STAGING_READINESS_2026-10-13.md). Docker staging бота не подходит. Real test writes ONLY after explicit staging guards and separate authorization.
- Component inventory `AuthDecision / V5LoginWorkflow / V5SmsBudget / V5MySqlSmsOutbox / V5SecurityEventVersions / native 10-path coverage` в GitHub feature уже существует и проверен только offline; не разрабатывать параллельные реализации без изменения требований. `V5_HTTP=503` — last documented baseline, not new curl check.

## 6. Процесс работы в новом чате

1. Новый чат начинает **с чтения данного короткого handoff**, latest heading shared checkpoint и live **branch/CI head**; не перечитывает 100k registry целиком ради повторения старых фактов. До GitHub changes всегда сверять actual feature code.
2. По задачам site production сначала `MODE=READ_ONLY`, guards перед source reads; no Bitrix bootstrap/SQL/SMS/PII/config/secrets; no production code modification unless separately approved.
3. Команды для оператора — **ОДИН полностью копируемый wrapper** с heredoc → `bash -n`/`php -n -l` → run → нумерованные `=== 1..5 ===` → `PASS/FAIL`, `FINAL_STATUS`/`FINAL_RC`; SSH не закрывать. Если длинный paste имеет странное echo (`WRAPPER...`), оценивать фактический output and exit, а не повреждённое echo.
4. Не повторять ранее закрытые phone and MFA diagnostics и не откатывать старые согласованные V4/бот/TOTP/TrustedDevice/Consent/AntiFraud изменения.
5. Документировать новый фактический результат в **двух одинаковых** `docs/SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md` и `docs/STRUCTURE_REGISTRY.md` (main + feature), подробный forensic report — feature. Отмечать `Было → Стало → Причина`, CURRENT / PROPOSED / TESTED / PROD separately.
6. GitHub actions `Build server image` success **не является доказательством deploy**. Проверка на момент создания handoff: CI V5 [38595306842](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38595306842) SUCCESS и main image build [38571610909](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38571610909) SUCCESS. Production V4 не изменён текущими исследованиями.

**Checkpoint marker для следующего чата:**
`LAST_OPERATOR_STEP=v5_object_check_locations_readonly.sh | LAST_OPERATOR_STATUS=PASS_5_5 | TARGETS=PINCODE_LINE46_AND_PHONEAUTH_LINE46_VARIABLES | RECEIVER_CLASS=NOT_PROVEN | NEXT_STEP=v5_object_check_receiver_binding_readonly.sh | NEXT_STEP_PRODUCTION_RUN=NO | V5_HTTP_LAST_DOCUMENTED=503 | STAGING_BITRIX_MYSQL_ATTESTED=NO | PHONE_VERSION_AND_AUTH_EPOCH_NATIVE_ATOMICITY=NOT_PROVEN`.

**Снимок GitHub до записи данного handoff**: main head `293be5352ed8b3d148ea30b113132fb65eb8671c`; feature head `2bbfafa35e05fae0c3bd203f66487466e8e6d53d`. **Эти SHA становятся историческими после новых commits**; новый чат обязан проверить branches live.
