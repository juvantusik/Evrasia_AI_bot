# Evrasia AI Bot — передача проекта в новый чат (14 октября 2026)

> **НАЧАТЬ ЗДЕСЬ.** Это краткая точка продолжения Project (все чаты Evrasia AI Bot относятся к одной общей истории). Следующим сообщением пользователь должен получить **один готовый безопасный скрипт** для следующего source-only этапа, а не предложение повторить сделанное. Подробная архитектура/история в документах ниже. При противоречиях: факт production > текущий код GitHub > staging > документация > старые обсуждения.

## 1. Последняя точка, не терять

**Последний фактический вывод оператора на production Bitrix-хосте evrasia** — source-only `v5_phone_check_masked_control_flow_readonly.sh`. Зафиксированы `PASS_COUNT=5`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. Два SHA исходников совпали; PHP полный tokenizer только через доверенный root-owned `tokenizer.so` под `php -n`; без Bitrix bootstrap, PHP site execution, SQL, SMS, PII/секретов, prod writes.

**Новые исходные факты:**
- Активный метод `PhoneAuth::check()` (135–187): if line146 — `NOT|VARIABLE_MASKED`; if152 — сравнение `GREATER` в masked выражении из 18 токенов.
- return 147 и 155: masked 8-token `UNKNOWN_CLASS::UNKNOWN_METHOD(STRING_MASKED,NUMBER_MASKED)`. return149 — **точный литерал false** (единственный token). return179 — masked `UNKNOWN_CLASS::UNKNOWN_METHOD(VARIABLE_MASKED)`, 6 tokens. return185 — сложное 22-token выражение, UNKNOWN semantics. Все `TRUNCATED=NO`. Не называть unknown классы или успех SMS на основании формы.
- В активном `Controllers/V4/Auth.php`: `CONTROLLER_DIRECT_PHONEAUTH_CHECK_CALLS=0`, `CONTROLLER_DIRECT_PHONEAUTH_STATIC_CALLS=0`, `CONTROLLER_OBJECT_CHECK_CALLS_RECEIVER_UNRESOLVED=0`. **Только один V4-контроллер и прямые формы вызова**; это не отрицание остальных маршрутов/контроллеров.
- `REAL_SMS_SUCCESS_AND_HTTP_ROUTE_PROVEN=NO`; `NATIVE_PHONE_CONFIRMED_WRITE_PROVEN=NO`; `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`.

**РОВНО ОДИН СЛЕДУЮЩИЙ ШАГ, на production ещё НЕ выполнялся:** уже разработанный scanner поиска АКТИВНЫХ вызывающих мест `PhoneAuth` в ограниченных V4 PHP файлах; см. `4. Не создавать новый scanner и не повторять предыдущий вывод 5/5.

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

**Индекс уже установленных структур всех подсистем**, Bitrix, Trusted Device, Bot Anti-Fraud, юрсогласий, V4, MFA, источников БД: [STRUCTURE_REGISTRY.md](STRUCTURE_REGISTRY.md). **Весь текущий прогресс V5**: [SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md](SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md), читать первый раздел 2026-10-14; нижние разделы исторические. **Телефонная source форензика**: [SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md](SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md) в feature. Общая бот/Anti-Fraud/Consent история: [NEW_CHAT_HANDOFF.md](NEW_CHAT_HANDOFF.md), [PROJECT_CHECKPOINT.md](PROJECT_CHECKPOINT.md), [AI_PROJECT_CONTEXT.md](AI_PROJECT_CONTEXT.md); правила выдачи команд: [SERVER_SCRIPT_RULES.md](SERVER_SCRIPT_RULES.md).

**Не путать readiness:** GitHub Actions 22 V5 offline suites PASS — не означает, что V5 route, миграции, JWT, outbox, SMS worker или native hooks существуют на production. Build image main success — не свидетельство deploy на eur-bot-01 или связки V5 с Bitrix.

## 3. Принятое поведение V5 (не переделывать)

Отдельный V5 для будущей версии приложения СамЗаберу; действующий V4 не менять. После подтверждённого пароля:
- включённая TOTP — запрашивается код Authenticator и после успешного входа PIN/бонусы без нового SMS;
- без TOTP, но с **проверенным strong trust**, который был получен при реальном SMS/TOTP enrollment — можно пропустить SMS; старый V4 trust после `AUTH_PASSWORD` не является proof сильного устройства;
- без strong trust/TOTP — SMS-челлендж только на **серверно подтверждённый номер аккаунта из ЛК**, не на номер из клиентского ввода; после успешного входа нет второго SMS для PIN/бонусов;
- anti-spam и денежные квоты SMS, общее proof and replay state, revocation, TOTP double-use prevention и SMS challenge — fail-closed; V4 JWT или статус «2FA включена» недостаточны для защищённого PIN.
- SamZaberu Trusted Device: `device_id="sz_"+64 lowercase hex`, SHA-256 хеш всего ID **с префиксом**, не менять core service.
- Консенсы/оферты, антифрод, статусы «Новый», блокировки и UI — отдельные ранее реализованные подсистемы. Не переделывать или переносить их в V5 без новой задачи.

Контракт: [SAMZABERU_API_V5_AUTH_CONTRACT_2026-10-11.md](SAMZABERU_API_V5_AUTH_CONTRACT_2026-10-11.md), source directory `site-integration/samzaberu-v5/README.md` — feature.

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

**Следующий готовый GitHub-only read-only этап, ещё не запущенный оператором:**
- `site-integration/samzaberu-v5/tests/diagnostics/V5PhoneCheckActiveCallerDiscovery.php`;
- `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_check_active_caller_discovery_readonly.sh`;
- `site-integration/samzaberu-v5/tests/diagnostics/V5PhoneCheckActiveCallerDiscoverySelfTest.sh`.

Wrapper строго проверяет host `evrasia`, root, SHA двух базовых файлов; собирает не более 80 source PHP только в `local/php_interface/Controllers/V4` (max depth 2) плюс `local/php_interface/lib` (max depth 1, filenames phone/auth/sms). Файлы с basename containing config/secret/credential/password/token/settings/env **исключаются до чтения**; symlink/unexpected path/size → FAIL. Full-file `token_get_all`, root-owned system tokenizer.so, php.ini disabled; никаких Bitrix/bootstrap/DB/SMS/реальных пользователей. Источники и найденные calls выводятся только в виде безопасных относительных имён и line/allowed method, без исходных PHP statements, аргументов и secret values.

Он находит активные `PhoneAuth::check/sendCode/createUser/sendSms`, alias imports и `new PhoneAuth`, отдельно считает unknown object `->check`. Покрытие dynamic dispatch **неполное**, нуль вызовов НЕ доказывает отсутствие всего маршрута. **CI этой версии** — [38571136784](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38571136784) **completed/success**: positive direct+alias/new и negative commented/sensitive filename/wrong host; 22 offline suites PASS; никакого production PHP code execution.

**Первое действие нового чата:** на основе **уже существующих** двух GitHub files (PHP scanner + guarded sh) составить **ОДИН цельный copy-paste Bash heredoc wrapper** с SHA/host guards, `bash -n` и `php -n -l`, который безопасно запускается под root на evrasia; не предлагать `curl | bash` и не трогать production app. Пользователь пришлёт stdout. До него **не придумывать** active route и не менять native code. Особые строки следующего stdout: `APPROVED_SOURCE_FILES`, `SKIPPED_SENSITIVE_FILENAMES`, `CANDIDATE_SOURCE`, `ACTIVE_PHONEAUTH_STATIC_CALL`, `ACTIVE_PHONEAUTH_INSTANTIATIONS`, `PHONEAUTH_IMPORT_HINTS`, `OBJECT_CHECK_CALLS_UNRESOLVED`, `FINAL_STATUS`, `FINAL_RC`. Прежний последний вывод уже внесён в документы; **не просить его ещё раз**.

## 5. Незакрытые release gates — не заявлять готовность V5

- `NATIVE_PHONE_VERIFICATION_PROVEN=NO`: actual verified phone state/Bitrix event writer пока не найден.
- `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`; **повторное подтверждение ТОГО ЖЕ E.164** обязано увеличивать событие/версию, не только изменение phone string.
- `AUTH_EPOCH` one-connection transaction/CAS/rollback, ordered `b_user → b_sec_user → b_user_phone_auth → auth_state` lock, все **10 native MFA/security/phone mutation paths** — real runtime аттестация отсутствует. Proposed `ev_samzaberu_auth_state` schema только в GitHub, миграции не было.
- MFA/recovery probes **9/9, 6/6, 7/7** source-only завершены. Знаем ordering: `Otp::save` clears recovery **до** merge, `onAfterDelete` cleanup **после** удаления, `RecoveryCodesTable::useCode` не доказывает atomic single-use CAS и игнорирует результат update; это risk gap, не доказанный exploit. [MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md](SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md). Не повторять source audits.
- Нет проверенного изолированного Bitrix/MySQL staging и proof actual ORM↔native SQL rollback/concurrent use; [ISOLATED_STAGING_READINESS_2026-10-13.md](SAMZABERU_V5_ISOLATED_STAGING_READINESS_2026-10-13.md). Docker staging бота не подходит. Real test writes ONLY after explicit staging guards and separate authorization.
- Component inventory `AuthDecision / V5LoginWorkflow / V5SmsBudget / V5MySqlSmsOutbox / V5SecurityEventVersions / native 10-path coverage` в GitHub feature уже существует и проверен только offline; не разрабатывать параллельные реализации без изменения требований. `V5_HTTP=503` — last documented baseline, not new curl check.

## 6. Процесс работы в новом чате

1. Новый чат начинает **с чтения данного короткого handoff**, latest heading shared checkpoint и live **branch/CI head**; не перечитывает 100k registry целиком ради повторения старых фактов. До GitHub changes всегда сверять actual feature code.
2. По задачам site production сначала `MODE=READ_ONLY`, guards перед source reads; no Bitrix bootstrap/SQL/SMS/PII/config/secrets; no production code modification unless separately approved.
3. Команды для оператора — **ОДИН полностью копируемый wrapper** с heredoc → `bash -n`/`php -n -l` → run → нумерованные `=== 1..5 ===` → `PASS/FAIL`, `FINAL_STATUS`/`FINAL_RC`; SSH не закрывать. Если длинный paste имеет странное echo (`WRAPPER...`), оценивать фактический output and exit, а не повреждённое echo.
4. Не повторять ранее закрытые phone and MFA diagnostics и не откатывать старые согласованные V4/бот/TOTP/TrustedDevice/Consent/AntiFraud изменения.
5. Документировать новый фактический результат в **двух одинаковых** `docs/SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md` и `docs/STRUCTURE_REGISTRY.md` (main + feature), подробный forensic report — feature. Отмечать `Было → Стало → Причина`, CURRENT / PROPOSED / TESTED / PROD separately.
6. GitHub actions `Build server image` success **не является доказательством deploy**. Проверка на момент создания handoff: CI V5 [38571136784](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38571136784) SUCCESS и main image build [38571610909](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38571610909) SUCCESS. Production V4 не изменён текущими исследованиями.

**Checkpoint marker для следующего чата:**
`LAST_OPERATOR_STEP=v5_phone_check_masked_control_flow_readonly.sh | LAST_OPERATOR_STATUS=PASS_5_5 | AUTH_V4_DIRECT_PHONEAUTH_CALLS=0_IN_PINNED_AUTH_ONLY | NEXT_STEP=v5_phone_check_active_caller_discovery_readonly.sh | NEXT_STEP_PRODUCTION_RUN=NO | V5_HTTP_LAST_DOCUMENTED=503 | STAGING_BITRIX_MYSQL_ATTESTED=NO | PHONE_VERSION_AND_AUTH_EPOCH_NATIVE_ATOMICITY=NOT_PROVEN`.

**Снимок GitHub до записи данного handoff**: main head `293be5352ed8b3d148ea30b113132fb65eb8671c`; feature head `2bbfafa35e05fae0c3bd203f66487466e8e6d53d`. **Эти SHA становятся историческими после новых commits**; новый чат обязан проверить branches live.
