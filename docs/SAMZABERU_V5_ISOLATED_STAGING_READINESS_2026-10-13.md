# SamZaberu API V5 — отдельный Bitrix staging: обязательные условия допуска

**Дата:** 2026-10-13. **Статус:** проектная инструкция для отдельного staging; физический staging Bitrix/MySQL **НЕ ПОДТВЕРЖДЁН**. Изменений production нет.

## 1. Почему здесь нужна отдельная среда

V5 использует нативное `Bitrix\Main\DB\MysqliConnection` и общую транзакцию с ORM. Это нельзя доказать исключительно синтетическими тестами или исходниками production. Следующие шаги должны идти в **физически/логически отделённом** Bitrix-окружении: read-only bootstrap preflight, затем только после отдельного разрешения две синтетические операции с rollback.

**Не путать:** историческое слово «staging» в документации Evrasia AI Bot часто обозначает временный файл Docker Compose в директории работающего production бота. Это **не** выделенный staging сайта Bitrix и **не** тестовая БД для SamZaberu V5.

## 2. Инварианты среды до первой загрузки Bitrix

1. **Отдельный хост/VM/контейнер с проверенным размещением**, не production-хост `evrasia` и не production-bot `eur-bot-01`. Контейнер на production-хосте сам по себе не считается доказательством физической изоляции. Сохранить назначение hostname и nonproduction Bitrix document root.
2. **Самостоятельная БД/инстанс MariaDB/MySQL** и отдельный DB-account с независимыми правами, сетью и credential set. Физическое отличие от production требуется подтвердить операционными данными инфраструктуры, а не названием схемы/строкой окружения. Схема для теста: `evrasia_v5_test_*`. Точное значение `@@hostname` после независимой проверки записать **без credentials**.
3. Установить Bitrix исходники ревизии, для которой закреплены SHA256: `connection.php` = `efed4b13015a6f57063d30666baa79456f49ead188e3daf0395bd2350074a21c`; `mysqlcommonconnection.php` = `2a43ad5a5ae90982b52dbea5980bfae469e23aa024c371687696f25aed280893`; `mysqliconnection.php` = `82877ae53f27a6bc429a8b9cda9709cc86f42cfc14225c4c1ba56a43c3121ec1`. Несовпадение — fail-closed, не обходить пины.
4. **Отключить фоновые агенты, cron, отправку SMS, push/email/Telegram, RestIS и любые внешние side effects** до Bitrix bootstrap, включая интеграции, которые теоретически могут вызваться без HTTP. Ограничить сетевой egress отдельной средой. Не копировать/показывать production secrets; использовать новые staging credentials. Пользовательские данные — только синтетические/санитизированные, без активной реальной доставки сообщений.
5. Наличие PHP 8.4+ (или подтверждённо совместимой runtime версии), требуемых расширений и реального `Application::getConnection()`. До реального прогона нужно подтвердить поддержку используемого SQL `@@session.in_transaction` именно на выбранном сервере. MariaDB документирует его как read-only session variable; на иных совместимых серверах поддержка не считается доказанной и неизвестное значение блокирует прогоны.
6. Изменения V5 остаются изолированы в `feature/samzaberu-api-v5-auth-contract`; `api/v5/router.php` fail-closed 503. Никаких production SQL, миграций, MFA hooks, авторизации пользователей или SMS.

## 3. Последовательность приёмки

**Этап A — инфраструктурное подтверждение, без Bitrix bootstrap и SQL writes.** Оператор сообщает только nonsecret facts: staging application hostname/root, идентичность независимого DB-сервера/схемы, способ подтверждения сетевой изоляции, запрет внешних интеграций и текущую редакцию кода. Нет этих фактов — `STAGING_ISOLATION_ATTESTED=NO`, дальнейший запуск запрещён.

**Этап B — read-only preflight на самом staging.** Использовать только
`site-integration/samzaberu-v5/tests/integration/V5BitrixNativeDepthReadOnlyPreflight.php` с `V5_STAGE_WRITE_ACK=ISOLATED_STAGING_ONLY`, `V5_STAGE_BITRIX_ROOT`, `V5_STAGE_DB_NAME` и `V5_STAGE_DB_HOSTNAME`. Скрипт не вызывает INSERT/UPDATE/DELETE/DDL или явные BEGIN/COMMIT/ROLLBACK, но **Bitrix bootstrap может иметь побочные эффекты**, поэтому этап B допустим только после этапа A. Ожидаемые результаты: `FINAL_STATUS=PASS`, `FINAL_RC=0`, `STAGING_BITRIX_BOOTSTRAP=YES`; одного PASS недостаточно для следующего этапа.

**Этап C — отдельное разрешение на запись в только синтетическую таблицу.** После положительного B и проверки DDL test-only InnoDB `ev_v5_native_tx_probe` разрешить под контролем оператора лишь `V5BitrixNativeOrmTransactionAcceptance.php`: обе проверки ORM↔native SQL, проверка глубины, `CONNECTION_ID`, rollback и отсутствие синтетических строк после выполнения. Незнакомое состояние = ручная сверка stage, а не автоматический rollback «чужого» scope.

**Этап D — последующие integration release gates.** 10 нативных путей MFA/phone/password/logout/recovery и общий `AUTH_EPOCH`; конкурентные транзакции; SMS worker/provider; независимый JWT; staging E2E. Ни A, ни B, ни C не означают разрешение production-пилота.

## 4. Текущее доказательное состояние

| Проверка | Статус |
|---|---|
| PHP source, staging preflight и acceptance кандидат | GITHUB ONLY / PREPARED |
| Offline PHP CI `.github/workflows/v5-offline-safety.yml` | Последний GitHub Actions run [38363042677](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38363042677) **SUCCESS**: PHP lint **48 файлов**, автономные тестовые наборы **22/22 PASS** (включая 18 + 62 + 80 транзакционных синтетических проверок), отрицательные pre-bootstrap guards для обоих staging entrypoints. Только офлайн, не real staging |
| Выделенный staging application host | NOT VERIFIED |
| Отдельная staging DB и credential isolation | NOT VERIFIED |
| Отсутствие побочных эффектов Bitrix bootstrap | NOT VERIFIED |
| Реальный depth reader и permit на staging | NOT RUN |
| Реальный ORM ↔ native rollback | NOT RUN |
| Production SQL / V4 / API V5 | UNCHANGED / NOT ACTIVATED |

**Первое действие, требующее инфраструктуры:** выделить и документально подтвердить staging, а не выполнять тесты на `evrasia` или `eur-bot-01`. Не подключать к новой среде production DNS/config по умолчанию.

**PRODUCTION_WRITE=NO · STAGING_E2E=NOT_RUN · SMS_SENT=NO · DB_MIGRATIONS=NO · V5_HTTP=503** (последний документированный baseline).
