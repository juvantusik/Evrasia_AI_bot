# Evrasia / СамЗаберу V5 — единый Bitrix Mysqli-транспорт SMS-outbox

**2026-10-12 — GitHub-only, offline-tested; NOT DEPLOYED.**

## 1. Причина

В присланной пользователем read-only диагностике production 2026-10-12:
- В `bitrix/.settings.php` найдено `CONFIG_CLASS_REFERENCES=MYSQLI`, секреты не выводились.
- Фактический класс `Bitrix\Main\DB\MysqliConnection` найден в `bitrix/modules/main/lib/db/mysqliconnection.php`, SHA256 `82877ae53f27a6bc429a8b9cda9709cc86f42cfc14225c4c1ba56a43c3121ec1`. Родительские `Connection` и `MysqlCommonConnection` содержат методы `startTransaction()/commitTransaction()/rollbackTransaction()`; ORM использует собственный Bitrix `getConnection()`.
- Штатные MFA записи: `Otp::save() -> UserTable::add()`, `Otp::delete() -> UserTable::delete()`, фоновый агент `OtpEvents::onRecheckDeactivate() -> UserTable::update()`. Доказательства общей блокировки `b_user` и обновления `AUTH_EPOCH` для этих путей ещё **нет**.
- Реализованный раньше Trusted Device уже использует `Application::getConnection()` + native transaction вокруг ORM-операций, это пример работающего подхода в проекте.
- Старый `V5MySqlSmsOutbox` использовал отдельное PDO-подключение: общий DSN/БД **не означает общую транзакцию** с native ORM.

**Решение:** сохранить существующий SMS workflow, `ChallengeRules`, десять HMAC/SMS/финансовых квот, проверку учётной записи/MFA/телефона и зашифрованный outbox. Добавить **только транспорт** к уже используемому native Bitrix MysqliConnection вместо дублирования бизнес-логики.

## 2. Реализация GitHub-only

Добавлены `site-integration/samzaberu-v5/src/V5BitrixPdoCompatibilityBridge.php` и `tests/V5BitrixPdoCompatibilityBridgeTest.php`.

- `V5BitrixPdoCompatibilityBridge extends PDO` — **совместимый PHP-фасад**, не настоящий PDO MySQL-драйвер и **не server-side prepared statements**. Он сохраняет типовые контракты существующих `V5MySqlSmsOutbox`, `V5BitrixLockedSmsEligibility` и `V5SecurityEventVersions` без переписывания проверенных бизнес-правил.
- Запросы выполняются только через переданный объект `Bitrix\Main\DB\MysqliConnection` и только при совпадении с `Bitrix\Main\Application::getConnection()`. Используются `query()`, `queryExecute()`, `getSqlHelper()->forSql()`, `getAffectedRowsCount()` и native transaction методы.
- Реализован минимальный `PDOStatement`-совместимый интерфейс: `prepare/execute/fetch/fetchColumn/rowCount`. SQL ограничен уже известными точечными `SELECT` и `INSERT/UPDATE` V5/общей auth-state схемы; другие таблицы, `DELETE`, DDL, многооператорные команды, именованные bind-параметры и недоверенные типы отвергаются. Даже прямой `runStatement()` проходит SQL allowlist.
- Текстовые аргументы экранируются `SqlHelper::forSql` и заключаются в SQL-кавычки. **Внимание:** это эмуляция bind, а не реальные server-prepared statements. Реальное экранирование установленной версии и ErrorMode должны быть подтверждены на изолированном staging до подключения к HTTP.
- Отказ/исключение на native `BEGIN/COMMIT/ROLLBACK/SQL` и явный `false` от них — fail-closed.
- `inTransaction()` означает лишь *успешно начатую ИМЕННО ЭТИМ адаптером* транзакцию. Отказ до начала собственной транзакции **НЕ вызывает rollback чужого Bitrix transaction**. Ошибка BEGIN/COMMIT/ROLLBACK делает экземпляр небезопасным для повторного входа.

**Критический security guard:** нужен внешний `V5NativeBitrixExclusiveTransactionPermitPort::safeToBeginOn()`. Он должен доказать, что native Bitrix connection не занят **чужой** транзакцией и принадлежит текущей операции V5. **Site/prod реализации такого proof пока нет.** Простой флаг конфигурации, HTTP-параметр и fake-тест недопустимы. Без verified permit нельзя безопасно открывать транзакцию через facade. Также требуется доказать, что ORM `UserTable` реально использует именно этот `Application::getConnection()`, а не другой DB handle.

Будущая композиция, **не команда для production**:

~~~php
$shared = new V5BitrixPdoCompatibilityBridge(
    \Bitrix\Main\Application::getConnection(),
    $verifiedExclusiveTransactionPermit, // NOT INSTALLED
);
$outbox = new V5MySqlSmsOutbox(
    $shared,
    $verifiedLockedSmsEligibility,
    $verifiedEncryptedMessageFactory,
    $secretInstallationBindingKey,
);
~~~

Этот пример НЕ интегрирован в HTTP-router и не заменяет 10-path native MFA hook coverage.

## 3. Локальная проверка

`tests/V5BitrixPdoCompatibilityBridgeTest.php` — **80/80 PASS** на PHP 8.4.24, полностью изолированные fake `MysqliConnection`, fake `Application`, fake `SqlHelper` и fake `V5NativeBitrixExclusiveTransactionPermitPort`.

Проверены: выборка и блокировка native account/MFA/phone, чтение InnoDB engine и DB time, транзакция с native quota CAS и mock-outbox в одном объекте, native rollback квоты/outbox, запрет DDL/DELETE/широкого чтения/прямого исполнения SQL без проверки, SqlHelper escaping, отсутствие чужого rollback при отказе BEGIN, native exception и `false` на begin/commit/rollback/queryExecute, wrong application connection и запрет повторного использования повреждённого scope.

GitHub blob SHA исходника `84aa6072d8f20a6065518b8059de23c3ad9306f0`, теста `e5607ead2d3fb86b3a59c7e8b1426796d480efe5` побайтово совпадают с исполнявшимися локально версиями. **Это НЕ real MySQL/Bitrix E2E**; ранние 123 fake-PDO проверки основного outbox не заявляются как повторно выполненные здесь.

## 4. Что ещё блокирует staging/prod

1. Подтвердить реальное поведение `MysqliConnection`, `SqlHelper::forSql`, `Result::fetch`, `queryExecute` и `getAffectedRowsCount` в целевом релизе, а главное — безопасное определение отсутствия внешней транзакции. Только затем реализовать trusted `V5NativeBitrixExclusiveTransactionPermitPort`.
2. Доказать тождество `Application::getConnection()` и того же физического DB handle для native `UserTable` при реальных ORM-операциях. Проверить состояние транзакции до начала и после rollback.
3. Закрыть **10-path native Bitrix MFA/security event coverage**: все пути, включая фоновое восстановление, должны использовать общую `b_user` lock и `AUTH_EPOCH` CAS/rollback. Наличие `OnAfter*`-хука не доказывает атомарность.
4. Только на isolated staging после согласованной миграции V5-таблиц выполнить параллельные тесты SMS resend ↔ MFA enrollment/reconfirm, last-bucket cost rollback, provider idempotency и состояние неизвестного COMMIT. Никаких SQL-миграций в этом шаге.
5. Затем challenge create/consume, verified-price provider, SMS worker, independent JWT и PIN pilot после отдельного утверждения.

`FINAL_STATUS=OFFLINE_PASS_80_OF_80` · `PRODUCTION_WRITE=NO` · `DATABASE_WRITE=NO` · `BITRIX_BOOTSTRAP=NO` · `SMS_SENT=NO` · `V4_UNCHANGED=YES` · `V5_HTTP=503`.
