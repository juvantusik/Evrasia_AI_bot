# СамЗаберу V5 — реальная InnoDB-атомарность event writer (с синтетическим native API)

16 октября 2026. **GitHub-only integration test; production Bitrix НЕ затрагивается.** В рабочей `eurasia_new` шесть V5 InnoDB tables уже созданы пустыми 15.10 (PASS, schema evidence `/root/ev-v5-ddl-backup.20261015T174912Z.2010173`), V5 всё ещё `off`/HTTPS503 и `AUTH_EPOCH_INITIALIZED=NO`.

## Почему это отдельный шаг

Ранее `V5SecurityEventVersionWriter` имел только offline fake-PDO assertions. `V5PilotAuthEpochInitializer` уже прошёл 39 synthetic-native/real-InnoDB assertions и оставляет PHONE_EVENT_VERSION=0 при первой инициализации. Но не было совместного E2E с **физическим native Bitrix-style mysqli facade** и реальным InnoDB transaction для:
1. нативного изменения пароля + epoch CAS,
2. нативного phone reconfirm **того же E.164**, 
3. revoke/reconfirm,
4. rollback обоих записей, когда event writer не смог доказать успех.

Новый `tests/integration/V5EphemeralMySqlNativeEventVersionWriterTest.php` выполняет предыдущую цепь 445 проверок **один раз** и затем в disposable GitHub MariaDB `ev_v5_ci_synthetic` создаёт только synthetic fixture data, используя существующий `Bitrix\\Main\\DB\\MysqliConnection` **CI facade**, `V5BitrixPdoCompatibilityBridge`, `V5SecurityEventVersions` и `V5SecurityEventVersionWriter`. Не вызывает действующие Bitrix API, рабочую БД, SMS или реальные аккаунты.

Принцип **b_user lock ДО native write** демонстрируется явной строкой `SELECT ID ... FOR UPDATE` перед каждым synthetic `$native->queryExecute(...)`, а writer повторно проверяет user→MFA→phone→state и делает CAS. В success native пароль и epoch видны второму независимому подключению только после одного COMMIT. При симулированной потере affected-row verification writer отказывает, а **caller** делает один rollback — обе записи исчезают. Без state native write в этом специально изолированном candidate scope тоже откатывается; **это нельзя ставить глобально на V4** — у неинициализированных V4-аккаунтов обычные нативные операции должны продолжать работать.

Тест также выполняет настоящую InnoDB phone reconfirm той же строки: даже когда SQL UPDATE не меняет текст телефона, явно переданное доверенное событие увеличивает оба counters и HMAC-stamp меняется. Revoke очищает phone binding, а последующее confirm создаёт новую версию, не оживляя старый challenge. False revoke отказывается. Такие гарантии действуют только внутри scope **проверенного native writer callback**, которого production ещё не имеет; сам факт SQL UPDATE не удостоверяет бизнес-событие подтверждения.

## Отрицательный release gate

- `Otp::save()` source-verified clearByUser recovery deletes happen before its ORM UserTable add; простой OnBeforeAdd стартует слишком поздно.
- `OtpEvents::onRecheckDeactivate`, восстановление MFA, same-phone reconfirm и logout по-прежнему требуют независимых hook entrypoints.
- `V5NativeMutationCoverageMatrix` требует десять source-pinned paths, тот же native transaction, b_user mutex before mutation, совместный rollback и **конкурентный** native Bitrix acceptance. CI-fake proofs НЕ могут авторизовать production.
- Не добавлять OnAfter-epoch hook, автозаполняющий `AUTH_EPOCH`, или новые production triggers без отдельного согласования. Реальный установленный `site-factory.php` отсутствует.
- Одной физической связи mysqli/bridge **не хватает**, если сам native writer делает скрытый COMMIT/BEGIN; это должно быть исключено source review и испытанием настоящего релиза, а не только `inTransaction()` после вызова.

## Чего ждём от проверки

Workflow `.github/workflows/v5-ephemeral-mariadb.yml` теперь заканчивается этим E2E, чтобы не повторять старые suites. Synthetic PHP lint проходит в offline workflow. После PASS обновить общее количество checks по фактическому GitHub job log. До статуса PASS не называть новый тест успешным.

Бизнес-контракт не меняется: пароль, обязательный native TOTP/no SMS fallback, strong V5 trust, password-bound SMS только на подтверждённый номер из профиля, бонусный PIN без второго SMS после strong auth. Production данные/файлы/режимы не меняются; main/PR Draft не затрагиваются.
