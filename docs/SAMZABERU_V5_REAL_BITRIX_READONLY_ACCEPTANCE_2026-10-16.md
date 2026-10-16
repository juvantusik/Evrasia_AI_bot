# СамЗаберу V5 — фактическая приёмка на production Bitrix, READ_ONLY, 2026-10-16

**Источники:** четыре последовательных stdout запуска оператора `tech`/`root` на
`evrasia` в чате Evrasia AI Bot. Этот документ фиксирует **факты на реально
установленном Bitrix/MySQL**, не одобряет production writes.

## Итог последнего запуска

`FINAL_STATUS=PASS FINAL_RC=0`, `MODE=READ_ONLY`,
`DATABASE_WRITE=NO APPLICATION_WRITE=NO MFA_WRITE=NO SMS_SENT=NO`.

Проверка выполнена *реальным* PHP-классом
`Bitrix\Main\DB\MysqliConnection` и его методом `getResource()`,
без полной инициализации сайта. Драйвер открыл настоящий
`mysqli`-ресурс для `eurasia_new`:

- `REAL_BITRIX_DRIVER_MYSQL_CONNECTION=PASS`, текущая база
  `eurasia_new`, MySQL session read-only подтверждён до операций.
- `BITRIX_RESOURCE_TRANSACTION=PASS`, одна физическая connection,
  `START TRANSACTION READ ONLY`.
- `BITRIX_RESOURCE_SAVEPOINT=PASS`, тестировались `SAVEPOINT`,
  `ROLLBACK TO SAVEPOINT`, `RELEASE SAVEPOINT`, затем полный
  `ROLLBACK` и `READ_ONLY_ROLLBACK=PASS`.
- `MFA_ADAPTER_REQUIRED_COLUMNS=COMPLETE`, все **8 проверенных**
  столбцов присутствуют по metadata в `information_schema`:
  `b_sec_user`: `USER_ID`, `TYPE`, `ACTIVE`, `SECRET`;
  `b_sec_recovery_codes`: `ID`, `USER_ID`;
  `ev_samzaberu_auth_state`: `USER_ID`, `AUTH_EPOCH`.
- Клиентские строки и секреты TOTP/recovery **не читались**.
  Отдельный предшествующий `mysqli` read-only тест подтвердил InnoDB
  для всех трёх таблиц и тот же SAVEPOINT/ROLLBACK.

## История диагностических отказов — исправлены, НЕ сайт-инциденты

1. Нативный `Bitrix\Security\Mfa\Otp` успешно загружен:
   исходный SHA256
   `285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c`,
   восемь необходимых методов `getUserId/getType/getHexSecret/isActivated/
   isInitialized/save/delete/verify` существуют.
2. При первом изолированном classloader отсутствовал
   `Bitrix\Main\Data\Connection`: реальный file
   `bitrix/modules/main/lib/Data/Connection.php` (с заглавными буквами),
   а диагностический код ошибочно искал `data/connection.php`.
   Файл ядра **не отсутствовал** и восстановления/замены не требуется.
3. После исправления classloader стандартный `MysqliConnection->query()`
   зашёл в `Application::getInstance()`, затем `Application.php:108`
   `new static()`; изолированный скрипт не создавал штатный экземпляр
   Application. Подтверждённый SHA `Application.php`:
   `1df355080ee7c1523846a80e89a80868d4403d4dbe3c6b49aa9f61408ff0b304`.
   Нельзя называть этот отказ проблемой MySQL; проверка через native
   `getResource()` обошла *только этот* Application-dependent entrypoint.
4. Три штатных native DB исходника совпали с pinned SHA:
   `db/connection.php` `efed4b13015a6f57063d30666baa79456f49ead188e3daf0395bd2350074a21c`,
   `db/mysqlcommonconnection.php`
   `2a43ad5a5ae90982b52dbea5980bfae469e23aa024c371687696f25aed280893`,
   `db/mysqliconnection.php`
   `82877ae53f27a6bc429a8b9cda9709cc86f42cfc14225c4c1ba56a43c3121ec1`.

## Важная граница доказательства

**ПОДТВЕРЖДЕНО:** real Bitrix driver physical MySQL connection,
read-only transaction and savepoint, InnoDB metadata, exact necessary columns.

**НЕ ПОДТВЕРЖДЕНО:** штатная инициализация
`Bitrix\Main\Application::getConnection()`, native
`MysqliConnection->query()/startTransaction()/rollbackTransaction()` в
приложении, реальный `Otp::save()/delete()`, native cleanup recovery,
атомарность epoch CAS/rollback, concurrency и десять обязательных native
security path proofs. Исторический CI на synthetic Bitrix не заменяет эти
этапы. Не писать `REAL_10_PATH_ATTESTED=YES`.

## Следующее действие, без повторения этих source/DB preflight

Для реального MFA mutation test потребуется отдельное **явное разрешение**
пользователя на контролируемые записи в существующем Bitrix, точный
одобренный тестовый `b_user.ID`, backup критичных `b_sec_user` /
`b_sec_recovery_codes` / `ev_samzaberu_auth_state` и проверенный
rollback/reconciliation. Обычный ROLLBACK нельзя обещать без доказательства,
что native `Otp::save()` и recovery cleanup не делают скрытый COMMIT.
Отдельная VM как обязательное условие ранее отклонена пользователем;
защита production пользователей и V4 остаётся обязательной.

`V5_ENABLED=NO`, `AUTH_EPOCH_INITIALIZED=NO` по последним принятым
production сведениям; новый DDL шести таблиц **не выполнять**.
