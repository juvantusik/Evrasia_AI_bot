# Bitrix / СамЗаберу V5 — актуальная параллельная ветка авторизации (checkpoint)

> **Зафиксировано:** 2026-10-12. **Статус:** GITHUB-ONLY / OFFLINE-TESTED / NOT DEPLOYED.
>
> **Важно:** этот документ — навигационный снимок **отдельной V5-ветки**, а не сообщение о развертывании V5 и не отмена результатов V3C. Источник истины для деталей V5 — фактическая HEAD рабочей feature-ветки и её собственные docs, а не пересказ ниже.
>
> **Feature branch:** `feature/samzaberu-api-v5-auth-contract`. Проверенная в GitHub при составлении этого документа HEAD: `5da1e7b514d47b7ace6d4114eec77d31538856b0`. **Branch и файлы перепроверить перед следующей операцией.**

## 1. Почему V5 выделен отдельно от V3C

V3C (см. [V3C checkpoint](V3C_SECURE_AUTH_CHECKPOINT_2026-10-11.md) и [приёмку тестов](V3C_TEST_ACCEPTANCE_2026-10-11.md)) — подготовленный на Bitrix-хосте набор PHP/SQL-кандидатов, проверенный **в изолированной MariaDB**: 25/25 последних E2E тестов с реальными verifier, PDO-хранилищем, projection, нормализатором и **синтетическим** TOTP candidate. **V3C не развернут**. Нативный алгоритм Bitrix TOTP в этом E2E не исполнялся.

V5 — более новая, отдельная **GitHub-only** реализация защищённого auth/SMS flow СамЗаберу с собственной веткой исходников, DDL proposals, fail-closed шлюзом и тестами. Она **не равна выполненному V3C E2E** и **не означает**, что мобильная авторизация production уже перешла на V5. Не применять V3C-схему/триггеры автоматически к V5 и не переносить результаты V3C PDO-тестов на V5 нативный MySQLi без независимых доказательств.

## 2. Принятая бизнес-логика V5 (не переизобретать)

- Вход: логин/пароль — только первый фактор; проверка защищена лимитами по login / IP / device / IP+login.
- Если для учётной записи действительно активна **нативная Bitrix TOTP 2FA**, требуется TOTP из аутентификатора **всегда**, даже при наличии Trusted Device.
- Если TOTP не включён, но есть доверенное устройство с **доказанной сильной MFA-привязкой** и текущими версиями security state, SMS может не требоваться. Существующий V4 `password-only` trust сам по себе **не** является достаточным доказательством.
- Иначе показывать **challenge с полем ввода SMS-кода**, не поле ввода телефона. SMS направлять только на **сервером подтверждённый** номер аккаунта, без допуска клиентской подмены. Защита от спама и расходов: интервал 60 секунд, максимум 3 SMS на challenge, 10 атомарных квот (включая финансовые); реальные денежные/ценовые лимиты не назначать предположением.
- Только после доказанного второго фактора — самостоятельная V5 assured session / JWT; затем показ существующего бонусного PIN **без дополнительной SMS**. Не вводить новый генератор PIN: существующий механизм бонусного PIN и пилот веб-LK — отдельный принятый поток.
- Trusted Device уже имеет самостоятельный **production-accepted** контракт `sz_` + 64 hex → SHA-256 полного namespaced значения → existing service. Не ломать его ради V5; V5 должна надстраивать корректный уровень assurance.

**Все пункты выше — принятый контракт/цель. Не объявлять их работающими на production V5.**

## 3. Последнее подтверждённое состояние V5 в GitHub на 2026-10-12

- `site-integration/samzaberu-v5/api/v5/router.php` сохраняет **HTTP 503 fail-closed**. Production V4/Bitrix DB/службы не менялись в рамках V5.
- Предложены `ev_samzaberu_auth_state` и **три V5 SMS-таблицы**; это **DDL PROPOSALS**, а не подтверждённые production/staging таблицы. V3C восемь триггеров не установлены в production.
- `V5SmsDispatchBudget`: 111 отдельных offline tests; `V5MySqlSmsOutbox`: 123 fake-PDO tests; `V5BitrixLockedSmsEligibility`: 88 offline tests; `V5SecurityEventVersions`: 84 offline tests. Эти результаты не доказывают ни отправку SMS, ни настоящую DB concurrency.
- `V5NativeMutationCoverage`: 10 независимых групп нативных изменений security/MFA/phone/password/logout, 60/60 offline tests. Нужны реальные native hook/attestation для **каждого** пути, включая фонового MFA агента. `OnAfterUserUpdate` после commit не подтверждает атомарность.
- `V5BitrixPdoCompatibilityBridge`: 80/80 **fake-Mysqli** tests. Это PDO-совместимый фасад над **Bitrix\Main\DB\MysqliConnection** и `Application::getConnection()`, **не настоящий PDO MySQL** и **не server-side prepared statements**. Один объект соединения необходим, но не достаточен для контроля транзакции/ORM.
- `V5StagingNativeTransactionPermit`: **62/62 offline PASS** на fake runtime (проверка hostname/root/schema, autocommit, `@@session.in_transaction`, постоянства `CONNECTION_ID()` и независимой внутренней глубины native-транзакции). Это **не** разрешение на реальный staging/prod BEGIN.
- **`V5VerifiedNativeTransactionDepthPort` не имеет реально проверенной реализации**. Нельзя подставлять «depth=0» заглушкой или опираться только на SQL `@@in_transaction`; нельзя выполнять rollback чужой транзакции Bitrix.
- Staging acceptance `tests/integration/V5BitrixNativeOrmTransactionAcceptance.php` подготовлен, но не испытан в настоящем isolated staging/MySQL. Реальный 24-worker MySQL gate также **не запускался**. Native 10-path MFA writer coverage не замкнуто. Никаких production hooks, миграций, SMS, JWT или rollout V5 не было.

**Разные счётчики тестов — разные скоупы; не суммировать в «готовый production E2E».**

## 4. Факты реального Bitrix и критический транзакционный барьер

Подтверждено read-only: штатный Bitrix подключён через `Bitrix\Main\DB\MysqliConnection`, а не standalone PDO. Установленные исходники:
- `bitrix/modules/main/lib/db/connection.php` — SHA256 `efed4b13015a6f57063d30666baa79456f49ead188e3daf0395bd2350074a21c`;
- `bitrix/modules/main/lib/db/mysqlcommonconnection.php` — SHA256 `2a43ad5a5ae90982b52dbea5980bfae469e23aa024c371687696f25aed280893`;
- `bitrix/modules/main/lib/db/mysqliconnection.php` — SHA256 `82877ae53f27a6bc429a8b9cda9709cc86f42cfc14225c4c1ba56a43c3121ec1`.

Исходники native ORM `UserTable`, `Otp::save/delete` и фонового `OtpEvents::onRecheckDeactivate` имеют отдельные операции записи. **Ещё НЕ доказано**, что каждая чувствительная операция синхронно удерживает общий `b_user FOR UPDATE` и обновляет `AUTH_EPOCH` **в той же физической транзакции** с единым rollback. Результаты fake тестов не закрывают этот release gate.

У `V5StagingNativeTransactionPermit` отдельные строгие барьеры: CLI-only, буквальное `V5_STAGE_WRITE_ACK=ISOLATED_STAGING_ONLY`, заведомо отдельный staging-host/root и БД `evrasia_v5_test_*`; на production-хостах отказывает. Не обходить guards.

## 5. Точная следующая операция в V5-ветке (на момент snapshot)

В GitHub feature уже зафиксирован `site-integration/samzaberu-v5/tests/diagnostics/v5_native_depth_readonly.sh` — **целевая статическая READ_ONLY диагностика** `Connection`/`MysqlCommonConnection`/`MysqliConnection`. Git **blob SHA** `5718f64f6eb6256645bffd0d526bb19ab0c0eee4` (это Git blob, **не** SHA256).

По последнему сохранённому V5 checkpoint на 2026-10-12 **скрипт был подготовлен и синтаксически проверен, но stdout его запуска на `evrasia` ещё не получен**. Если пользователь в новом чате присылает строки `TRANSACTION NESTING / OWNERSHIP SOURCE TOKENS` или `SOURCE=Connection`, **сначала анализировать именно этот вывод**, не начинать заново аудит `b_user`/V4/OTP и не предлагать повторный запуск без причины.

После исходникового анализа — минимальная реализация независимого `V5VerifiedNativeTransactionDepthPort` **только если** на её корректность есть основания; иначе оставаться fail-closed. Лишь затем isolated staging ORM/MySQL transaction/RW/rollback, real concurrent MySQL и отдельная полнота всех 10 native security writers. Никаких production changes до отдельного согласования.

## 6. Авторитетные документы и вход в новый чат

**V5 — читать из настоящей feature-ветки, а не из `main` или docs/Bitrix PR**:

1. [V5 NEXT CHAT checkpoint](https://github.com/juvantusik/Evrasia_AI_bot/blob/5da1e7b514d47b7ace6d4114eec77d31538856b0/docs/SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-12.md)
2. [Staging native transaction permit](https://github.com/juvantusik/Evrasia_AI_bot/blob/5da1e7b514d47b7ace6d4114eec77d31538856b0/docs/SAMZABERU_V5_STAGING_TRANSACTION_PERMIT_2026-10-12.md)
3. [Bitrix Mysqli bridge](https://github.com/juvantusik/Evrasia_AI_bot/blob/5da1e7b514d47b7ace6d4114eec77d31538856b0/docs/SAMZABERU_V5_BITRIX_TRANSACTION_BRIDGE_2026-10-12.md)
4. [Native MFA mutation coverage](https://github.com/juvantusik/Evrasia_AI_bot/blob/5da1e7b514d47b7ace6d4114eec77d31538856b0/docs/SAMZABERU_V5_NATIVE_MUTATION_COVERAGE_2026-10-12.md)
5. [SMS protections](https://github.com/juvantusik/Evrasia_AI_bot/blob/5da1e7b514d47b7ace6d4114eec77d31538856b0/docs/SAMZABERU_V5_SMS_SEND_GUARDS_2026-10-11.md), [original V5 auth contract](https://github.com/juvantusik/Evrasia_AI_bot/blob/5da1e7b514d47b7ace6d4114eec77d31538856b0/docs/SAMZABERU_API_V5_AUTH_CONTRACT_2026-10-11.md), [V5 integration README](https://github.com/juvantusik/Evrasia_AI_bot/blob/5da1e7b514d47b7ace6d4114eec77d31538856b0/site-integration/samzaberu-v5/README.md).

Реестр `docs/STRUCTURE_REGISTRY.md` поддерживается по принятому правилу между `main` и V5 feature; **данный docs-only Bitrix PR его не изменяет**.

**Готовый запрос, если продолжаем именно V5:**

> Продолжаем Evrasia AI Bot / Bitrix, направление СамЗаберу API V5. Открой GitHub `juvantusik/Evrasia_AI_bot`, актуальную feature-ветку `feature/samzaberu-api-v5-auth-contract`. Сначала прочитай `docs/SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-12.md`, `docs/SAMZABERU_V5_STAGING_TRANSACTION_PERMIT_2026-10-12.md` и `site-integration/samzaberu-v5/README.md`. Сверь текущие ветку/HEAD и production только read-only. V5 — GitHub-only, HTTP 503, no deploy. Прими мой вывод `v5_native_depth_readonly.sh` как следующую точку, либо, если вывода нет, продолжай безопасно с этой диагностики. Не переделывай V3C/V5 пройденные тесты и не меняй production.

## 7. Статусы других Bitrix-направлений (не смешивать)

- **Web-LK native 2FA:** был контролируемый production-пилот, browser-accepted disable 2026-09-24. Не означает всеобщую 2FA или готовность мобильного V5.
- **СамЗаберу Trusted Device:** production/E2E accepted 2026-09-24, не переписывать `sz_`-контракт.
- **Legal consents:** сайт — production; pre-JWT при новой регистрации СамЗаберу — design accepted, implementation pending.
- **Anti-Fraud bot:** отдельная система/сервер, PR #73 production accepted 2026-10-03; не путать GitHub doc HEAD с развёрнутым ботом.
- **V3C:** isolated E2E 25/25 с synthetic candidate, NOT DEPLOYED.
- **V5:** GITHUB-ONLY, fake/offline tests, NOT DEPLOYED, HTTP 503.

**Безопасность и процесс:** публичный репозиторий; без реальных номеров, ID аккаунтов, QR/TOTP-secret, HMAC/JWT/API key, env/credentials и конфигурационных дампов. Для сервера — один guard-protected copy-paste wrapper, `bash -n`, отдельные секции и `FINAL_STATUS/FINAL_RC`; ничего не запускать в production без подтверждения пользователя.
