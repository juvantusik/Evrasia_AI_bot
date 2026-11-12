# СамЗаберу V3C — защищённая мобильная авторизация: технический checkpoint

> **Дата:** 2026-10-11. **Статус:** `CANDIDATE / ISOLATED TESTED / NOT DEPLOYED`.
>
> **Точное подтверждение последнего испытания:** `V3C_REAL_VERIFIER_PDO_E2E=PASS`, `E2E_CASE_COUNT=25`, `FINAL_RC=0`; реальная MariaDB 11.4.5 использовалась **только как изолированная тестовая**, после теста остановлена и удалена.
>
> **Важно:** последний сценарий использовал **синтетическую реализацию `TotpCandidateV3C`**, а не проверку 6-значных кодов нативным `Bitrix\Security\Mfa\Otp`. Никакая часть V3C не включена в production.
>
> **Точка входа для нового чата:** [START_HERE.md](START_HERE.md); отдельные результаты — [V3C_TEST_ACCEPTANCE](V3C_TEST_ACCEPTANCE_2026-10-11.md).

## 1. Предмет и ограничения

**V3C** — внутреннее название подготовленного кандидата защиты мобильной авторизации **СамЗаберу** (Bitrix V4). Цель: связать TOTP-подтверждение с **текущим** состоянием аккаунта, исключить повторное использование подтверждения (replay) и атомарно учитывать конкурентные запросы/отзыв после чувствительных изменений.

Не путать:

- с названием версии Bitrix;
- с уже работающим 2FA веб-кабинета и его пилотным UX;
- с уже действующим мобильным **Trusted Device**;
- с приложением/БД Anti-Fraud бота;
- с юридическими согласиями регистрации;
- с подтверждением bonus PIN или выдачей JWT — эти интеграции требуют отдельной проверки.

Существующие работающие механизмы не переписывать. Изолированные PHP-классы подлежат отдельной интеграции только после доказательства совместимости с **нативным** Bitrix TOTP и V4.

## 2. Компоненты / граница транзакции

Подтверждённый основной путь `NativeTotpVerifierV3C::verifyAndConsume(Account, code)`:

```text
Account + TOTP candidate
  -> basic checks (active/not blocked; TOTP expected; code format)
  -> PDO transaction begin
  -> lockUser(userId)
  -> lockOtp(userId)
  -> lockPhone(userId)
  -> lockState(userId)
  -> check current locked account/OTP/state
  -> LockedIdentityProjectionV3C::enrich(locked rows)
        -> CanonicalNativeRefsV3C
          -> Impro\MobileId\PhoneNormalizer::normalizeForApi
          -> NativeTotpCredentialMarkerV3C
  -> NativeIdentityVersionProofV3C::matches(
       supplied account snapshot,
       current user+otp+state projection)
  -> TotpCandidateV3C::newParams(userId, otpRow, code)
  -> PdoTotpStoreV3C::saveParamsCAS(
       userId, previous PARAMS, new PARAMS, original SECRET)
  -> COMMIT only when every guard succeeds
  -> otherwise fail closed / ROLLBACK
```

- Параллельное подтверждение должно быть сериализовано через реальные InnoDB row locks; `saveParamsCAS` — дополнительная compare-and-swap защита.
- Снимок безопасности через `NativeIdentityVersionProofV3C::fingerprint` зависит от user ID, логина, хеша пароля, ACTIVE/BLOCKED, канонической ссылки на подтверждённый телефон, статуса/версии TOTP, `AUTH_EPOCH` и ключа HMAC. **HMAC key не фиксировать в репозитории/логах.**
- Нормализатор телефона обязателен только для пути с подтверждённой записью телефона; отсутствие phone-row имеет отдельную поддерживаемую ветку.
- Проекция отклоняет несогласованные строки/ID, неподтверждённый телефон и некорректный секрет/версию TOTP.
- `saveParamsCAS` сохраняет новое значение `b_sec_user.PARAMS` и сбрасывает `ATTEMPTS`. Его реальный PDO-тест подтвердил корректное поведение и с исходным `PARAMS=NULL`; ранее лишь предполагавшийся дефект `NULL` **не подтвердился**.

## 3. Состояние БД, SQL и version epoch

**Существующие** таблицы Bitrix, затронутые кандидатом:

- `b_user` — user ID, LOGIN, PASSWORD hash, ACTIVE, BLOCKED, PASSWORD_EXPIRED и другие поля;
- `b_sec_user` — TOTP ACTIVE, TYPE, SECRET, INIT_PARAMS, PARAMS, ATTEMPTS и остальные нативные MFA-поля;
- `b_user_phone_auth` — запись подтверждённого телефона, PHONE_NUMBER / CONFIRMED и связанные поля.

**Предлагаемая, но не установленная в production** таблица:

```text
ev_samzaberu_auth_state
  USER_ID            primary key
  AUTH_EPOCH         BIGINT UNSIGNED DEFAULT 1
  PASSWORD_CHECK_SEQ BIGINT UNSIGNED DEFAULT 0
```

`PASSWORD_CHECK_SEQ` присутствует в проверенной форме тестовой схемы; его конечную бизнес-семантику/использование в production **не считать доказанными** лишь по этому checkpoint.

**Кандидат SQL:** восемь триггеров `auth_epoch_triggers_candidate.sql`, отдельный `auth_state_schema.sql`. `AUTH_EPOCH` должен инкрементироваться при событиях, делающих старый auth-snapshot недействительным (изменение логина/пароля, активности/блокировки/связанных настроек, номера подтверждённого телефона или TOTP-конфигурации). Точный перечень событий и WHEN-условий читать в текущем SQL-кандидате, **не угадывать по описанию**.

Критическое исключение: обновления `b_sec_user.PARAMS` / `ATTEMPTS` при штатном потреблении TOTP **не** должны сами увеличивать `AUTH_EPOCH`, иначе подтверждение инвалидировало бы собственную версию. Волатильный SMS `OTP_SECRET` телефонной таблицы не является credential TOTP и не должен автоматически приравниваться к смене подтверждённого телефона.

Статус SQL: отдельная MariaDB + восемь триггеров прошли функциональные, конкурентные и последние E2E тесты; **в production не мигрированы**. Для реальной миграции обязательно отдельно изучить production schema, constraints, data, backup, совместимость с существующими UPDATE/INSERT/DELETE, нагрузку, backfill и rollback.

## 4. Файлы источников, версии и неявные зависимости

На 2026-10-11 файлы PHP/SQL ниже размещались **вне GitHub как offline stage на Bitrix host** (пути могут оказаться временными; до любого использования проверять существование и SHA). Ни один из них не объявляется файлом задеплоенной V3C.

**Base PHP stage:** `/tmp/evrasia-v3c-unified-stage.uYkzGO9Y/`.

| Файл в stage | SHA256 |
| --- | --- |
| `NativeTotpVerifierV3C.php` | `51c6b62f11d34a8ebf5b5fd31a9ec1ae9c2c7032a081ded6e8fd0e3b5a0685fb` |
| `PdoTotpStoreV3C.php` | `4215f0d21a72b9feee1b020debd72a72fe12526e060f76a9d98528714e95bcf8` |
| `NativeIdentityVersionProofV3C.php` | `c74d9c367201cc594d82233c4ac9e065e3983387e6066d708243bdf9eedf00df` |
| `LockedIdentityProjectionV3C.php` | `dc1dce02d2ddb2bdec8e24111c864f718f2b9e5b61becd53bf1393531a86f9cb` |
| `CanonicalNativeRefsV3C.php` | `90f01bf9942f2b0a56ddc9d329f9f9266cf25e14bd3b5c80f1523724ca0846e3` |
| `NativeTotpCredentialMarkerV3C.php` | `1553c8e4816a8b05abbc61641e2b62963bdabf3a98d6db88103607a14f2da07d` |
| `unified_tests.php` | `9412006eec0ff5aae3d5bacaabcd2035d627d0b06889e2160c1e8ce273af7f70` |

- В stage также упоминался `patch.php`; его применённость / готовность и текущий SHA здесь **не подтверждены**. **Не запускать** patch-файл без отдельного source review и разрешения.
- Отдельно выделенный **только для isolated test** интерфейс `TotpProvider.php`: `/tmp/evrasia-v3c-contract-stage.hFf8dA6d/TotpProvider.php`, SHA256 `c1b18f17dec07887c244fccf8c03b51eee6ab271feefb9aeeb42afcb1b02aa0b`. Извлечён из `unified_tests.php` с `token_get_all` **без выполнения теста**. Для production packaging нужен самостоятельно согласованный source/loader контракт; не ссылаться на временную /tmp test fixture как на production.
- Интерфейс `TotpProofStoreV3C` **уже объявлен внутри текущего** `NativeTotpVerifierV3C.php`, с методами `begin/lockUser/lockOtp/lockPhone/lockState/saveParamsCAS/commit/rollback`. Старые копии verifier из других stage **не совместимы**: в них мог отсутствовать `lockPhone`.
- Оригинальный `Impro\MobileId\PhoneNormalizer`: `/home/site_evrasia/web/evrasia.spb.ru/public_html/local/modules/impro.mobileid/lib/PhoneNormalizer.php`, SHA256 `7c3868ce8ff4a2721fd7e822c204ea82d838911358baa655d6de98e57680aef7`. Его обычную Bitrix-autoload регистрацию делает `local/modules/impro.mobileid/include.php`. В isolated test безопасно использовался **прямой require именно класса**, без вызова `include.php`/Bitrix bootstrap.

**SQL-stage:**

| Файл | SHA256 |
| --- | --- |
| `/tmp/evrasia-v3c-atomic-stage.BiTDO3Jk/auth_state_schema.sql` | `3cc3be3384e32862a77830db4599a5f1e258237070b67ae4ffc2e89557f10bac` |
| `/tmp/evrasia-v3c-triggers.R5wYgL1L/auth_epoch_triggers_candidate.sql` | `f9a65b2e7cfa8d7bcdc8f86d7435ee2eedf5f9fce9f322f1e1db6672cdd1c3b3` |

**Production baseline source, read-only guard:** `local/php_interface/Controllers/V4/Auth.php` относительно Bitrix document root; SHA256 `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` в последних проверках. Не считать этот SHA вечным.

## 5. Что тестами подтверждено

| Набор | Результат | Что в действительности тестировал |
| --- | --- | --- |
| Isolated SQL trigger scenarios | **34/34 PASS** | Инкременты/исключения `AUTH_EPOCH` и корректность SQL на синтетической схеме |
| Two-connection locking/concurrency | **19/19 PASS** | Транзакционные гонки/блокировки на собственных MariaDB соединениях |
| Real `PdoTotpStoreV3C` | **14/14 PASS** | `lock*`, CAS, replay, wrong secret, `PARAMS=NULL`, rollback, epoch invariance |
| Original normalizer + canonical refs + real projection | **14/14 PASS** | Реальный PHP-нормализатор, форматы телефонов, HMAC references, TOTP version и snapshot |
| **Real verifier + real PDO end to end** | **25/25 PASS** | Транзакция, replay, invalid code, отзыв старой версии после чувствительных изменений; **TOTP candidate синтетический** |

**Итого 106 отдельных сценариев across test groups**, но **не** 106 independent end-to-end Bitrix production checks. Отдельные fake PDO 17/17, static audits и smoke checks не входят в эту сумму. Подробно: [test acceptance](V3C_TEST_ACCEPTANCE_2026-10-11.md).

## 6. История диагностик — НЕ возвращаться к снятым блокерам

1. Первая попытка интеграции real PDO завершилась `FAILED_CASE=CONNECT` / `ERROR_CLASS=Error`, но это была **общая метка до require**. Read-only symbol scan показал отсутствующие при загрузке `TotpProvider` и `TotpProofStoreV3C`. Корректный порядок: `TotpProvider.php` → текущий `NativeTotpVerifierV3C.php` (с `TotpProofStoreV3C`) → `PdoTotpStoreV3C.php`. Позднее `V3C_LOAD_ORDER=PASS` (9/9 shell проверки); реальный PDO — 14/14 PASS.
2. Первое E2E real verifier+PDO остановилось на `BUILD_ACCOUNT_SNAPSHOT`, ещё до `verifyAndConsume`. `CanonicalNativeRefsV3C::phone()` вызывал `Impro\MobileId\PhoneNormalizer`, не загруженный standalone PHP CLI. Ни `CanonicalNativeRefsV3C`, ни реальный Bitrix source из-за этого **не меняли**.
3. Найден production `PhoneNormalizer.php`, source/static audit 6/6 shell PASS; отдельная runtime-проверка `normalizer + projection` 14/14 PASS. После прямого `require_once` original class итоговый E2E с real verifier+PDO **25/25 PASS**.
4. Вывод static-tokenizer, заменявший числовые литералы нулями, нельзя использовать как точное представление условий SQL/PHP. Исходный код с соответствующим SHA и реальные runtime-tests имеют приоритет.

## 7. Осталось сделать, в правильном порядке

**A. Следующий узкий шаг — только READ ONLY.**

- Проверить живые исходники Bitrix MFA, фактический native TOTP call-chain и `TotpCandidateV3C` контракт. Не открывать production TOTP secret/настоящие accounts.
- Подготовить offline native-TOTP candidate с поддельным hex secret и заведомыми тестовыми векторами; проверить новый `PARAMS` и верификацию/потребление через **реальный** `verifyAndConsume` / PDO в изолированной DB.
- Отдельно проверить `PARAMS=NULL`, успех/replay/ошибку, окно допустимых временных шагов, повтор при параллельных запросах и согласованность с нативным `Bitrix\Security\Mfa\Otp` без реальной авторизации.
- Вынести и согласовать production packaging интерфейсов/loader, не полагаясь на stage /tmp; проверить фактический V4 Auth/JWT/session flow и ограничения optional web MFA.

**B. После функциональной приёмки — только проект, НЕ действие.**

- Продумать read-only обследование реальной схемы, backfill `AUTH_EPOCH`, алгоритм безопасной миграции, backup verification/rollback, защиту от неожиданных версий токена и fail-closed поведение.
- Отдельный staging/pilot rollout с явными guards, отсутствие деградации для пользователей без 2FA и проверкой логина, logout, телефонных изменений, блокировок, изменения/удаления TOTP.
- Production cutover, миграция/триггеры и Auth controller patch — **только после отдельного согласования** и с полным планом восстановления.

## 8. Безопасность и эксплуатационный контракт

- Убедиться, что host = `evrasia`, пользователь/права корректны, перед потенциальной записью проверить SHA, точные таблицы/колонки, backup и target. Не читать реальные аккаунты, пока это не требуется для отдельного согласованного read-only аудита.
- Для тестовой MariaDB: отдельный `mktemp -d`, Unix socket + `--skip-networking`, синтетическая схема, identity check `@@datadir/@@socket/@@skip_networking`; подтвердить PID/uid/cmdline перед SIGTERM и удалять лабораторию **только после** остановки. Результаты последнего run: `INSTANCE_STOPPED=YES`, `SANDBOX_REMOVED=YES`.
- Один copy-paste Bash wrapper: `bash -n` → выполнение → `PASS/FAIL` → `FINAL_STATUS`, `FINAL_RC`, `RUN_RC`; терминал остаётся открытым. Изолированный FAIL не маскировать счётчиком успешных preflight.
- Не печатать пользовательские телефоны, OTP/QR, cookie/JWT/HMAC secret, DB credentials, реальные password hashes. Repo публичный: храним только статус, redacted fingerprints и контрольные суммы исходников, **не секреты**.
- Исходники stage, SQL и production с 2026-10-11 могут измениться. Старые SHA и пути — **история проверенного снимка**, а не повод обходить guard.

## 9. Связанные документы

- [Карта направлений Bitrix и работающих интеграций](INTEGRATIONS_AND_BOUNDARIES.md).
- [Последний E2E acceptance](V3C_TEST_ACCEPTANCE_2026-10-11.md).
- [Веб-2FA пилот / отключение](../TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md).
- [SamZaberu Trusted Device production](../SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md).
- [Общие правила серверных скриптов](../SERVER_SCRIPT_RULES.md).
