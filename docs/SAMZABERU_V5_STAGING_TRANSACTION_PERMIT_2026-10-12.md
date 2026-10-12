# Evrasia / СамЗаберу V5 — staging-only проверка допуска к нативной транзакции Bitrix

Дата: **2026-10-12**. Статус: **GitHub-only / 62 автономные проверки PASS / staging E2E не запускался / production не изменён**.

## 1. Почему это отдельный барьер

В production ранее подтверждено: нативная ORM Bitrix использует `Bitrix\Main\DB\MysqliConnection`, а V5 SMS-outbox первоначально разрабатывался через PDO. `V5BitrixPdoCompatibilityBridge.php` уже подготовлен и позволяет V5 выполнять SQL через тот же объект `Application::getConnection()`, сохраняя существующую бизнес-логику challenge, 10 SMS/денежных квот и зашифрованного outbox.

Но проверка **типа соединения** недостаточна, чтобы вызвать `startTransaction()`: Bitrix может уже владеть транзакцией, в том числе с внутренним счётчиком вложенности. Вызвать потом `rollbackTransaction()` вместо чужого владельца нельзя.

## 2. Что создано

`site-integration/samzaberu-v5/src/V5StagingNativeTransactionPermit.php` — staging-only реализация ранее объявленного `V5NativeBitrixExclusiveTransactionPermitPort`.

- **CLI-only**; требует буквального `V5_STAGE_WRITE_ACK=ISOLATED_STAGING_ONLY`, конкретного hostname приложения, canonical staging root **не** равного production root, и имя отдельной БД по шаблону `evrasia_v5_test_*`. Известные production-хосты отвергаются.
- Проверяет **тот же объект** `Application::getConnection()` / `MysqliConnection`. Подменённый объект отвергается без запросов.
- Требует отдельный `V5VerifiedNativeTransactionDepthPort::depthFor()`, который должен **достоверно определить внутреннюю глубину транзакций Bitrix**. Строго `0` до и между снимками; unknown/false/ненулевой уровень — отказ. **Live-реализации depth port пока нет**, нельзя подменять её всегда возвращаемым нулём.
- Дважды читает сервером **только** `SELECT DATABASE(), @@hostname, CONNECTION_ID(), @@session.autocommit, @@session.in_transaction` и требует полного совпадения обоих снимков, известного nonproduction DB-host/схемы, `autocommit=1`, `in_transaction=0` и корректного положительного ID соединения. При ошибке/изменении значений — `null` (отказ).
- Сам класс **не начинает и не завершает транзакцию, не делает INSERT/UPDATE/DELETE**, не читает учётные записи и не подключается к БД без вызова. Все сообщения об ошибках/секреты скрываются.

## 3. Проверки

`tests/V5StagingNativeTransactionPermitTest.php` — **62/62 PASS**, PHP 8.4.24, **целиком fake Bitrix Application/MysqliConnection и fake независимого счётчика глубины**.

Проверены успешный синтетический scope, двойные снимки, изменение `CONNECTION_ID` между ними, активный `in_transaction`, отключённый autocommit, неизвестный/ненулевой/изменившийся native nesting depth, неправильные БД/host/документ root, отсутствие staging acknowledgement, неверный объект приложения, пустые/дублированные ответы и аварии SQL, некорректные ID соединения (float, leading zero, пробелы, overflow). В тесте создаётся и удаляется **только свой временный каталог**; нет конфигурации/секретов реального сайта.

GitHub source blob: `097a8dbd0e627a7cfbe7eedfcd9c207619b1a992`.
GitHub test blob: `0aa6af73091c68733d0a5dcf5e6c224f2bf03bf4`.
Оба совпадают с файлами, на которых получен PASS 62/62.

**Эти 62 проверки НЕ доказывают**, что на реальном Bitrix `@@session.in_transaction` доступен и корректно отражает native transaction, что Bitrix правильно управляет nested counters, или что реально работает общий rollback ORM/V5. Успешный искусственный `V5VerifiedNativeTransactionDepthPort` — не production-разрешение.

## 4. Следующая обязательная точка

1. Точечно проверить **фактический установленный исходник** `bitrix/modules/main/lib/db/{connection.php,mysqlcommonconnection.php,mysqliconnection.php}` и определить: хранится ли внутренний счётчик вложенности, доступен ли авторитетный read-only API, действительно ли `startTransaction` безусловно начинает транзакцию; при несовпадении с предположением — обновить кандидат. Повторно исследовать `b_user`/V4/OTP не требуется.
2. Лишь после реальной независимой реализации `V5VerifiedNativeTransactionDepthPort` запускать подготовленный `tests/integration/V5BitrixNativeOrmTransactionAcceptance.php` на **изолированном staging**, сверяя состояние сессии до/после BEGIN/ROLLBACK.
3. Затем проверить настоящий V5 SQL-helper, affected rows и ORM/транзакцию, а также **все 10** нативных security/MFA writers совместно с `AUTH_EPOCH` (их хуки ещё не установлены).
4. До выполнения этих условий не включать `api/v5/router.php`, не выдавать JWT, не отправлять SMS. Production/V4 не менялись.

**MODE=GITHUB_ONLY · NATIVE_DEPTH_PROVIDER=NOT_IMPLEMENTED · REAL_MYSQL_E2E=NOT_RUN · DB_MIGRATION=NO · V5_HTTP=503 · PRODUCTION_WRITE=NO.**
