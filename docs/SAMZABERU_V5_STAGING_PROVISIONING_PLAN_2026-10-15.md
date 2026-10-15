# СамЗаберу V5 — конкретный план отдельного Bitrix staging

Дата: 15 октября 2026. **PLAN_READY / INFRASTRUCTURE_UNATTESTED.** Это план, а не выполненный provisioning, не разрешение на production-операции и не доказательство наличия VM.

Основа: `SAMZABERU_V5_ISOLATED_STAGING_READINESS_2026-10-13.md` и `site-integration/samzaberu-v5/tests/integration/BITRIX_NATIVE_TRANSACTION_ACCEPTANCE.md`. Уже выполненные production source-only исследования не повторяются.

## 1. Что известно и какая внешняя зависимость осталась

Подтверждённые проектом production hosts: Bitrix `evrasia`, бот `eur-bot-01`. Ни один из них не использовать как тестовый app/DB host. Документация и доступные материалы не подтверждают отдельные staging VM identity, адрес, independent MySQL instance/storage, ACL или гипервизор. GitHub Actions MariaDB доказала транзакции на синтетике, но не является Bitrix staging. Название staging у исторического bot compose не доказывает независимость Bitrix/БД.

**Требуемое действие владельца инфраструктуры:** подтвердить существующую независимую VM по критериям ниже либо выделить новую. Поставщик, гипервизор, адреса, наличие свободных ресурсов и право создать VM неизвестны и не подставляются наугад. Новая платная инфраструктура и доступ к управлению ею требуют отдельного согласования. Старые логи production для этого не нужны.

## 2. Предлагаемый контур (имена ещё НЕ назначены)

| Объект | Предложение | Обязательное ограничение |
|---|---|---|
| VM/host | `evrasia-v5-stage-01` | Отдельная от production операционная среда; не контейнер/каталог на evrasia или eur-bot-01. Проверить VM identity и независимость ресурсов/storage у администратора. |
| Приложение | Bitrix той же утверждённой версии и необходимых native модулей; docroot `/srv/evrasia-v5-stage/site` | Путь предлагаемый, не существующий факт. Отдельный пользователь, никакого mount/symlink к production. |
| БД | Отдельный MySQL/MariaDB instance в этой staging VM, schema `evrasia_v5_test_native` | Новые datadir/socket/credentials; не отдельная schema на production MySQL. Версия и настройки должны соответствовать утверждённой native цели, не просто CI MariaDB. |
| Доступ | Только VPN/административная сеть, приватный HTTPS при необходимости | Не менять публичный DNS/LB/router evrasia.rest; не объявлять public /api/v5. |
| Данные | Установленная native Bitrix schema и только специально созданные synthetic/test accounts | Не переносить реальные customer phones, passwords, TOTP seeds, sessions, consent/заказы и персональные дампы. |
| Секреты | Новые stage-only DB/JWT/HMAC/encryption credentials | Не использовать production keys/tokens/cookies. Не публиковать секреты в GitHub/чат/logs. |

Размер VM и storage назначается владельцем инфраструктуры под тестовый набор и проверяемый backup, без изменения production allocation. Для первого цикла достаточно одной отдельной VM для app и самостоятельного локального DB instance; два production-контейнера не являются заменой этой границы.

## 3. Phase A — изоляция ДО первого выполнения Bitrix PHP

1. Администратор создаёт/выделяет VM и фиксирует её identity/hostname/private address, независимые диски, местоположение DB и права управления. Не клонировать работающий production хост с активными cron/secrets/сетями. Если используется подготовленный образ, сначала изолировать его на уровне инфраструктуры и проверить содержимое, не запускать приложение автоматически.
2. Закрыть исходящие подключения по умолчанию (IPv4 и IPv6, включая контейнерный forwarding, если он используется). Разрешения на администрирование, необходимые install sources и время/DNS должны быть узкими и задокументированными. Доступ к production DB/API/RestIS/SMS/Telegram/mail/push запрещён, включая доступные через внутреннюю сеть пути. Не проверять блокировки платными API-вызовами; использовать конфигурационное evidence и контролируемый неплатный test endpoint.
3. До запуска web/PHP остановить и отключить фоновые задания, cron, systemd timers/workers, Bitrix agents, mail/SMS/Telegram/push handlers и внешние интеграции. Одних флагов в PHP недостаточно: нужна сетево-процессная изоляция. Не копировать production `.settings.php`, env, dbconn.php или credentials.
4. Установить согласованный runtime и лицензированный native Bitrix из разрешённого artifact/источника. Зафиксировать module/PHP/DB версии и SHA нужных native файлов. Уже описанный drift `user.php` не утверждать автоматически и не исправлять на production. Версионный mismatch остановит acceptance, а не исправляется ослаблением pin.
5. Создать новую native DB/schema и синтетические записи **только после согласования stage-only setup/DDL**. Не загружать `V5EphemeralMySql*Test.php` в native DB: эти CI tests создают упрощённые synthetic native таблицы и предназначены исключительно для ephemeral CI. PROPOSED V5 SQL также не применять без отдельного точного migration plan.
6. Проверить отдельность сессий/кеша/очередей/cookies и stage-only ключей, отсутствие production credentials и активных интеграций. Зафиксировать DB identity (фактический instance, hostname/port/socket/datadir/schema) безопасным отчётом без паролей и строк конфигурации.

**Выход Phase A:** один сохранённый и проверенный паспорт изоляции, а не набор новых однотипных диагностик. Он содержит ответственного/дату, VM identity, независимость storage/DB, private ingress, egress-deny, disabled jobs/integrations, source provenance/version pins и перечень stage-only целей. Секреты не включаются; для наличия достаточно PRESENT=YES, VALUE_PRINTED=NO. Root-флаг или совпавшее hostname сами по себе не доказывают изоляцию.

## 4. Phase B — существующий native transaction preflight

Только после Phase A использовать **существующий**, а не заново написанный:

`site-integration/samzaberu-v5/tests/integration/V5BitrixNativeDepthReadOnlyPreflight.php`

Перед запуском перечитать его актуальные guards/точные параметры. `V5_STAGE_BITRIX_ROOT`, `V5_STAGE_DB_NAME`, `V5_STAGE_DB_HOSTNAME` берутся из подтверждённого паспорта, не из предложений раздела 2. Требуемый существующим entrypoint ACK не заменяет отдельного доказательства изоляции. Bitrix bootstrap может иметь побочные эффекты даже при read-only намерении SQL-проверок.

Проверить native Bitrix connection, current transaction depth, SQL connection identity, совместимость adapter/ORM, source pins и отсутствие активной чужой transaction. Любой mismatch останавливает переход к write acceptance. К production DB не подключаться.

## 5. Phase C — ограниченная rollback/concurrency приёмка

Получить отдельный допуск на точную staging schema и synthetic probe table. До DDL/теста сделать stage backup/snapshot, проверить пригодность восстановления в другом изолированном месте и зафиксировать rollback. VM snapshot не считать проверенным DB backup без проверки согласованности/восстановления.

Применять существующие `BITRIX_NATIVE_TRANSACTION_ACCEPTANCE.md` и `V5BitrixNativeOrmTransactionAcceptance.php`: одна native SQL connection, ORM write в owned scope, явный rollback, отсутствие probe row при повторном чтении, корректная глубина, отказ в чужой transaction. Код возврата 0 недостаточен — нужны фактические postconditions. Лишь после этого планировать V5 staging-schema migration с отдельным допуском, backup и верификацией.

Отдельно доказать необходимые concurrency/invalidation случаи на синтетических native accounts. **Не запускать ephemeral CI suite в Bitrix DB** и не переносить CI ACK в реальные окружения.

## 6. Phase D — ближайшие реальные native/provider интеграции

Первый вертикальный сценарий без платных SMS: native account/current password → password-bound TOTP challenge → native TOTP verification/replay protection → committed V5 session/refresh. Проверить неверный пароль, TOTP replay, блокировку/смену пароля/epoch между шагами и запрет TOTP→SMS fallback.

Следом — полный approved 10-path security coverage: security event и AUTH_EPOCH в одной физической транзакции; rollback оставляет обе стороны неизменными; commit инвалидирует старые proofs/sessions; включая native MFA/recovery/background writers. Phone verification обязана изменять PHONE_EVENT_VERSION и при повторном подтверждении того же E.164. Старые delete/add-выводы не подменяют этот факт.

Затем strong V5 trust и native password-bound SMS begin/resend. Телефон только из подтверждённого аккаунта; native eligibility и epoch перечитываются под нужными locks; квоты, стоимость, cipher outbox и one-attempt claim сохраняются. HTTP begin/resend остаются выключенными до этой проверки.

Для реального SMS adapter получить проверенный контракт выбранного provider: стабильная идемпотентность доставки/оплаты, currency/max price/current tariff, документированный смысл sent, независимая status/receipt verification, точное соответствие delivery и charge, таймауты/дубли/поздняя квитанция. `reconcileUnknownSent()` уже реализован и DB-tested, но provider-specific lookup не реализован. Receipt adapter не отправляет SMS и не инициирует повторную оплату. Неподтверждённый ответ не разрешает ни SENT, ни повтор платного запроса. Проверить стоимость самих status calls; реальные платежи/SMS требуют отдельного согласования и лимита. Не подключать production credentials для удобства теста.

RestIS PIN подключать по реальному проверенному контракту и разрешённому test account/endpoint; после strong auth показывать PIN без новой SMS. Синтетический PIN-reader не считать доказательством настоящего RestIS. Завершить E2E, негативные тесты, бюджетные/конкурентные/rollback проверки; после этого обсуждать ограниченный пилот, не автоматически разворачивать production.

## 7. Rollback и критерий завершения

Любая неожиданная внешняя отправка, связь с production, раскрытие credentials, mismatch source/DB/host или неатомарная native mutation: остановить stage-тест, заблокировать stage egress/worker, сохранить безопасное evidence, восстановить только утверждённый stage snapshot/backup по проверенному плану. Не менять production и не делать destructive cleanup неизвестной цели.

Staging считается готовым для real integration только после Phase A+B, а native transaction-tested — после Phase C с доказанными postconditions. Статус полного `REAL_BITRIX_STAGING_TESTED` для V5 требует принятой E2E/native/provider матрицы, не одного preflight. До этого остаётся `NO`; `PRODUCTION_DEPLOYED=NO` независимо от числа CI assertions.

Серверная операция после назначения точной цели выдаётся одним copy-paste wrapper с bash/php syntax check, host/root/path/DB/source/backup guards и структурированным выводом `FINAL_STATUS`/`FINAL_RC`. Сейчас такого write-блока нет намеренно: ни фактический staging host, ни его DB, ни разрешение на создание инфраструктуры пока не подтверждены.
