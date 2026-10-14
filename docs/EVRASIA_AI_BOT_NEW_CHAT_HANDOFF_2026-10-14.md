# Evrasia AI Bot — передача проекта в новый чат (14 октября 2026)

> **НАЧАТЬ ЗДЕСЬ.** Это краткая точка продолжения Project (все чаты Evrasia AI Bot относятся к одной общей истории). Следующим сообщением пользователь должен получить **один готовый безопасный скрипт** для следующего source-only этапа, а не предложение повторить сделанное. Подробная архитектура/история в документах ниже. При противоречиях: факт production > текущий код GitHub > staging > документация > старые обсуждения.

## 1. Последняя точка, не терять

**Последний фактический stdout оператора:** `v5_phone_check_active_caller_discovery_readonly.sh` на production `evrasia`, `PASS_COUNT=5`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`; `RUN_RC=0`, `LAUNCHER_RC=0`, `WRAPPER_FINAL_RC=0`. Diagnostic commit `07a93202dd892f2db58ddd41a0baf611a5ff95e0`; SHA-256 shell `37a7df71494c2d848b9c36cdf7c0909b78f80fcaf74c26a24f782f4a5b7ee342`, PHP analyzer `101be2f3a8b2f6f8ae30ac42e5c7fb5bca0e19b6c03a5a3b2790a8b6d3f0bc56`; Bash/PHP syntax RC=0. Pinned source SHA `Auth.php=72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` and `PhoneAuth.php=a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27` matched.

**New source evidence:** `APPROVED_SOURCE_FILES=18`, `SCANNED_APPROVED_PHP_FILES=18`, `SKIPPED_SENSITIVE_FILENAMES=0`, `SCANNED_TOKEN_COUNT=32465`, `OUTPUT_CAPPED=NO`. `ACTIVE_PHONEAUTH_STATIC_CALLS=0`, `ACTIVE_PHONEAUTH_INSTANTIATIONS=0`, `PHONEAUTH_IMPORT_HINTS=0`, `CANDIDATE_SOURCE_FILES=0`, **`OBJECT_CHECK_CALLS_UNRESOLVED=2`**. Source scope is still only V4 controllers depth 2 and named phone/auth/sms libs depth 1. The two object calls are **not proven PhoneAuth calls**. Their file/line/receiver is not reported by the completed scanner's default mode, because candidate files are emitted only when static calls, new instances or imports are found.

**Было → Стало → Причина:** caller discovery was prepared but not production-run → completed source-only 5/5 PASS, 18 approved files, zero matched PhoneAuth static/new/import and two unresolved object-check sites → accepted operator stdout. `CANDIDATE_SOURCE_FILES=0` is an output-selection/count fact, **not** proof of no object calls, no route or no phone verification elsewhere. Dynamic dispatch and alias coverage remain partial; no runtime/HTTP/DB conclusions.

**One next step, not yet production-run:** `v5_object_check_locations_readonly.sh`, using an opt-in `--locate-object-checks` mode of the **same** `V5PhoneCheckActiveCallerDiscovery.php`. It keeps the identical approved source scope, host/root/two SHA guards and trusted tokenizer under `php -n`; prints only object-check source relative paths, SHA-256 of the exact read source, numeric lines, object/nullsafe operator and masked receiver-shape enums. It does not infer receiver class or print source statements/identifiers/arguments/literals. Previous 18 files / 2 calls / 32465 tokens are informational inventory counts, not a complete checksum baseline of all sources. CI: [38591998122](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38591998122) `completed/success`: scanner PHP lint, new synthetic object-location/shape/masking/cap cases, prior default caller selftest, wrong-host refusal, 22 approved V5 offline suites PASS; no production runtime/DB tests..

**Operational/release state:** `MODE=READ_ONLY`, `TOKENIZER_LOAD_MODE=EXPLICIT_ROOT_OWNED_TOKENIZER_SO`, `PHP_INI_LOADED=NO`, `BITRIX_BOOTSTRAP=NO`, `SITE_PHP_EXECUTED=NO`, `DATABASE_CONNECTION=NO`, `DATABASE_WRITE=NO`, `SMS_SENT=NO`, `USER_DATA_READ=NO`, `CONFIG_FILES_READ=NO`, source values/statements/secrets not printed, production/V4 unchanged. Native verified-phone writer, same E.164 reconfirm event bump, `PHONE_EVENT_VERSION`, atomic native `AUTH_EPOCH` and ten mutation paths, independent Bitrix/MySQL staging remain unproven. V5 HTTP 503 is last documented baseline only. Do not repeat the completed caller discovery, masked flow or old MFA/phone audits.


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

**Завершённый bounded caller inventory:** `v5_phone_check_active_caller_discovery_readonly.sh` production 5/5 PASS, 18 files / 32465 tokens; static/new/import PhoneAuth=0, object check=2, candidate files=0. Default candidate output фильтруется static/new/import; не путать нулевой candidate count с отсутствием object calls. Предыдущие masked `PhoneAuth::check` shapes, точный false literal и отсутствие прямых calls в одном Auth.php сохранены в checkpoint; старые проверки завершены.

**Следующий готовый read-only этап, ещё НЕ выполненный оператором:**
- `site-integration/samzaberu-v5/tests/diagnostics/v5_object_check_locations_readonly.sh`;
- `site-integration/samzaberu-v5/tests/diagnostics/V5PhoneCheckActiveCallerDiscovery.php`, opt-in `--locate-object-checks` (обычный режим сохранён);
- `site-integration/samzaberu-v5/tests/diagnostics/V5ObjectCheckLocationsSelfTest.sh`.

Wrapper сохраняет host `evrasia`, root и два pinned SHA guards. Область чтения прежняя: не более 80 PHP в V4 controllers (depth 2) и filename phone/auth/sms в lib (depth 1); sensitive config/secret/credential/password/token/settings/env имена исключаются ДО чтения. Реальный full-file tokenizer, php.ini disabled, no include/eval/target execution/Bitrix/SQL/SMS. До публикации locations валидируются все sources; более 80 locations → FAIL без частичного location stdout. Paths только whitelist relative, SHA exact analyzed source, numeric line / `METHOD_TOKEN_LINE` (это строка token `check`, не enclosing method declaration), operator OBJECT/NULLSAFE, receiver enum; никакие names/values/arguments/statements не печатаются. Receiver class и real route не доказаны. Предыдущие 18/2/32465 — информационные counts, не checksum baseline всего корпуса.

CI этой версии: [38591998122](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38591998122) `completed/success`: scanner PHP lint, new synthetic object-location/shape/masking/cap cases, prior default caller selftest, wrong-host refusal, 22 approved V5 offline suites PASS; no production runtime/DB tests.

**Первое действие после этого handoff:** один полный copy-paste Bash heredoc launcher для `v5_object_check_locations_readonly.sh`, закреплённый на code commit `5bfc1cb6bef8a43999cdffeb5fad20eedea6dab7`, с SHA-256 downloaded diagnostics, `bash -n` и `php -n -l`; no `curl | bash`, временные файлы только /tmp с cleanup, SSH открыт. PHP analyzer SHA-256 `8f128d2a67acd2246882a8f74508ca10cbe75f2a1d81df1f11bac8c2874299f9`; shell SHA-256 `c0436aa98a0520d50c9dbdef618aae1f99d63f420139c5c8a182d7ee2b172e38`. Не повторять завершённый caller inventory. Следующий stdout: `OBJECT_CHECK_SOURCE`, `OBJECT_CHECK_SOURCE_SHA256`, `OBJECT_CHECK_LOCATION`, `OBJECT_CHECK_LOCATIONS_EMITTED`, `OBJECT_CHECK_SOURCE_FILES`, `RECEIVER_CLASS_PROVEN`, `FINAL_STATUS`, `FINAL_RC`. До него не придумывать receiver class/active route/native phone writer.

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
6. GitHub actions `Build server image` success **не является доказательством deploy**. Проверка на момент создания handoff: CI V5 [38591998122](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38591998122) SUCCESS и main image build [38571610909](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38571610909) SUCCESS. Production V4 не изменён текущими исследованиями.

**Checkpoint marker для следующего чата:**
`LAST_OPERATOR_STEP=v5_phone_check_active_caller_discovery_readonly.sh | LAST_OPERATOR_STATUS=PASS_5_5_18_FILES | PHONEAUTH_STATIC_NEW_IMPORT=0_IN_BOUNDED_SCOPE | OBJECT_CHECK_RECEIVER_UNRESOLVED=2 | NEXT_STEP=v5_object_check_locations_readonly.sh | NEXT_STEP_PRODUCTION_RUN=NO | V5_HTTP_LAST_DOCUMENTED=503 | STAGING_BITRIX_MYSQL_ATTESTED=NO | PHONE_VERSION_AND_AUTH_EPOCH_NATIVE_ATOMICITY=NOT_PROVEN`.

**Снимок GitHub до записи данного handoff**: main head `293be5352ed8b3d148ea30b113132fb65eb8671c`; feature head `2bbfafa35e05fae0c3bd203f66487466e8e6d53d`. **Эти SHA становятся историческими после новых commits**; новый чат обязан проверить branches live.
