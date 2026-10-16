> **LATEST FACTUAL PROD PILOT 2026-10-16:** Operator verified *real* Bitrix physical mysqli READ_ONLY on `evrasia`, only USER_ID `880339`: `FINAL_STATUS=PASS FINAL_RC=0`, account ACTIVE=Y BLOCKED=NO, `OTP_ROW_EXISTS=NO`, `RECOVERY_CODE_ROWS=0`, `V5_AUTH_STATE_EXISTS=NO`, native phone total=1/confirmed=1. No site, MFA, DB writes or SMS. The old native enrollment/login/disable browser flow was already accepted Sep 24; current readback confirms no OTP enrollment, so there is no native OTP row to delete now. V5 AUTH_EPOCH must not be blindly inserted while ten-path source/atomic coverage unproven. Next controlled native MFA write requires explicit operator authorization and tested backup/reconciliation. See `docs/SAMZABERU_V5_REAL_PILOT_880339_READONLY_2026-10-16.md`. Earlier checkpoints historical.

> **REAL PRODUCTION BITRIX DRIVER READ-ONLY PASS, 2026-10-16:** Operator `evrasia` ran original PHP native `Bitrix\\Main\\DB\\MysqliConnection->getResource()` read-only test against actual `eurasia_new`: **FINAL_RC=0, REAL_BITRIX_DRIVER_MYSQL_CONNECTION=PASS, BITRIX_RESOURCE_TRANSACTION=PASS, BITRIX_RESOURCE_SAVEPOINT=PASS, READ_ONLY_ROLLBACK=PASS, MFA_ADAPTER_REQUIRED_COLUMNS=COMPLETE** (8 metadata columns). Real Otp.php SHA/methods and native DB source pins verified. Not a fully initialized `Application` context and no native MFA save/delete or ten-path atomicity tested; zero application/DB/MFA writes, V5 OFF. Read `docs/SAMZABERU_V5_REAL_BITRIX_READONLY_ACCEPTANCE_2026-10-16.md`; previous synthetic CI milestones below are retained for history.

> **LATEST VERIFIED 2026-10-16:** feature-only `V5BitrixOtpNativeMutationPort.php` added for exact-source-pinned `Bitrix\\Security\\Mfa\\Otp::save/delete` inside the previously tested b_user-first staging native transaction scope. It checks locked `b_sec_user` result and NO remaining `b_sec_recovery_codes` before AUTH_EPOCH CAS/commit. The ordinary native `Otp::delete()` return value is NOT treated as sufficient success proof. Code commit `884916fa132285cb9007c7981c33d6ec93b43699`. CI offline `38898193783` SUCCESS 26 suites; disposable MariaDB `38898193583` SUCCESS **543 total =43 sessions+500 dependent, +13 new strictly NEGATIVE adapter-source/routing assertions**. Real installed Bitrix OTP save/delete has NOT run in CI, positive source pin not accepted, **no live native hooks, trusted 10-path coverage or deployment**. Production V5 last operator-attested OFF/503; six V5 tables exist empty, no AUTH_EPOCH rows. Details `docs/SAMZABERU_V5_NATIVE_OTP_PREWRITE_ADAPTER_2026-10-16.md`. Older records below historical.

> **LATEST VERIFIED 2026-10-16:** tightened staged native mutation scope with a random SQL SAVEPOINT to reject hidden native `COMMIT+BEGIN` even when connection ID/native tx depth stay the same. Code commit `1987ddc114352470a476f3f3e5e8eda057458f8b`; offline `38896370467` SUCCESS/26 suites, disposable MariaDB `38896370806` SUCCESS **530 assertions=43 sessions+487 dependent (+7 new)**. An intentionally early-committed synthetic native password change was detected as **UNCERTAIN, not rolled back**, and CI restored its disposable fixture; scope poisoned/no retries. This does NOT attest real Bitrix hooks or release/10 paths. Production last confirmed V5 OFF/503, six tables created empty, no per-user AUTH_EPOCH; no production operation in this increment. Details `docs/SAMZABERU_V5_SAVEPOINT_NATIVE_BOUNDARY_2026-10-16.md`. Previous checkpoint paragraphs are historical.

> **LATEST 2026-10-16:** feature-only staged `V5StagingNativeMutationScope` (code `2a3751e0c560381be401e5bd3d550d03756bd50a`) and disposable MariaDB regression added. Offline Action `38889891833` SUCCESS 26 suites; ephemeral Action `38889891967` SUCCESS **523 assertions = 43 separate session + 480 dependent**, of which 33 are new. Scope allows reviewed password, verified phone same-E164 reconfirm and revoke only, b_user lock first, native continuity snapshots and fail-closed ten-path coverage gate; rejects unsupported MFA/recovery/logout and uncertain transaction. **Not native Bitrix acceptance**: positive Bitrix facade/installed proofs in CI synthetic, actual production release unmodified/OFF. Real ten-path hooks/evidence and staging transaction ownership still missing. Production DDL six empty V5 tables PASS 2026-10-15; never re-run. Source and test described in `docs/SAMZABERU_V5_STAGING_NATIVE_MUTATION_SCOPE_2026-10-16.md`. Older checkpoint paragraphs below are history.

> **АКТУАЛЬНЫЙ КОНТЕКСТ 2026-10-16:** на feature добавлен и испытан **совместный native security event writer с существующим Bitrix-mysqli CI facade на реальной disposable InnoDB**. Production native writer НЕ установлен и не аттестован. Code/test `087f6c1a0b407e24f3c88a89496a0c704e3cd9fa`. GitHub Actions offline `38873622210` SUCCESS (26 suites), ephemeral MariaDB `38873622382` SUCCESS: **490 assertions = 43 sessions + 447 combined, включая 45 новых**. Проверены password mutation+AUTH_EPOCH CAS в одном commit, совместный rollback при CAS ambiguity, same-E164 reconfirm (повторно) и phone revoke/reconfirm. CI positive Bitrix facade + native10-path evidence СИНТЕТИЧЕСКИЕ. Не включать V5, не применять hooks на V4. Последнее подтверждённое production состояние (15.10): шесть V5 tables empty, V5 mode off/503, AUTH_EPOCH_INITIALIZED=NO; backup schema-only `/root/ev-v5-ddl-backup.20261015T174912Z.2010173`. Детали: `docs/SAMZABERU_V5_NATIVE_EVENT_WRITER_EPHEMERAL_E2E_2026-10-16.md`. Исторические сообщения ниже не переопределяют этот checkpoint.

> **КОД В FEATURE ПРОВЕРЕН 15.10.2026:** staged per-user `V5PilotAuthEpochInitializer` уже в commit `45782ed0653b74a4eb5b4659551bfb70ad4a0ec1` (исправление null-check). GitHub Actions: offline `38782468148` SUCCESS (26 suites), ephemeral MariaDB `38782468454` SUCCESS (**39 новых, 445 всего**). Не installed на production! Реальные Bitrix10-path native hooks/transaction ownership не аттестованы; initializer невозможно законно использовать в OFF release. Production DDL PASS, V5 remains off, initial rows=0. Первый новый MariaDB run `38782204373` был FAIL из-за PHP `??` с null; баг исправлен и повторный CI PASS. Не объявлять это Bitrix staging.

> **АКТУАЛЬНО 15.10.2026, 17:49 по серверному логу.** Production migration PASS: все 6 служебных таблиц V5 созданы пустыми в `eurasia_new` (InnoDB, constraint guard PASS); `DDL_TABLES_CREATED=6`, `MIGRATION_POSTCHECK=PASS`, `FINAL_STATUS=PASS`/`FINAL_RC=0`. V4 root/router/rewrite не изменились, origin HTTPS /api/v5/auth/signin по-прежнему точный 503, установленный V5 режим `off`, `AUTH_EPOCH_INITIALIZED=NO`, native factory отсутствует. Evidence/снимки только **структуры**, не полный backup данных: `/root/ev-v5-ddl-backup.20261015T174912Z.2010173`. **Не повторять DDL или старые проверки отсутствия таблиц.** Следующая фаза — native hooks/epoch initialization и TOTP verification, код feature без включения production. Предыдущие текстовые NEXT ниже сохранены как исторические.

# Evrasia AI Bot / СамЗаберу V5 — START HERE, 15 октября 2026

**Текущая точка: V5 установлен OFF; origin HTTPS и web-config PASS. Попытка чтения структуры БД через root/socket БЕЗ пароля получила ERROR 1045. Метаданные НЕ прочитаны.** Не повторять этот способ подключения, layout, установку, проверку503 или web-config. Следующий вариант — только готовые SELECT в уже авторизованной консоли БД, без нового кода входа.

Repo `juvantusik/Evrasia_AI_bot`, branch `feature/samzaberu-api-v5-auth-contract`. Читать текущий checkpoint, integration decisions и `SAMZABERU_V5_PILOT_COMPOSITION_2026-10-15.md`. Полная предыдущая редакция сохранена в commit `df25f34aa894aa92292ba96f2fb6698fcbc02449`. Бизнес-правила и ограничения не меняются.

## 1. Фактический отказ подключения и следующий допустимый шаг

Пользователь ранее подтвердил только чтение структуры рабочей БД; это не разрешение на миграцию. Получен файл `Вставленный текст(20261015-171249).txt`, финальная часть лога:

- HOST=evrasia / USER=root; DB_AUTH=LOCAL_SOCKET_NO_PASSWORD_SUPPLIED.
- OFF package, V4 hashes и local socket path — PASS.
- DB_CLIENT_RC=1, DB_ERROR_CODE=1045; FINAL_STATUS=FAIL, FINAL_RC=2.
- DATABASE_CONNECTION_ATTEMPTED=YES, CLIENT_ROWS_READ=NO, DATABASE_WRITE=NO, DDL_EXECUTED=NO.
- PHP/Bitrix/HTTP/SMS/service reload/credential files — не использовались.

Это отказ входа использованной учётки, не ошибка структуры и не доказательство отсутствия таблиц или неисправности V5. Успешной DB-сессии этот запуск не дал. Unix root сам по себе не подтвердил доступ MySQL root. Не сбрасывать пароль, не менять authentication plugins/GRANT и не включать skip-grant-tables ради диагностики. Установленный V5 остаётся выключенным; восстановление из backup не требуется по этому результату.

Существующий `deployment/read-v5-db-metadata-socket.sh` сохранён неизменным для истории: его SHA256 `4158763756d3c9eb2c36e633465e286f67ac43a3f3788c6e9276bbbf1b5bc310` больше не выдавать как рабочий способ входа на этом сервере. Его socket/root-без-пароля ветка уже фактически отказала.

Вариант изменения helper с интерактивным запросом реквизитов подготовлен локально, но его публикация была заблокирована инструментом. В рабочую ветку он НЕ включён, другим каналом не передавался и не применяется. Прежний отклонённый разбор .settings.php также не повторялся. Нельзя выдавать локальные проверки этих черновиков за принятую или установленную реализацию.

**Подготовлен другой, SQL-only вариант:** `site-integration/samzaberu-v5/deployment/read-v5-db-metadata.sql`. Он предназначен исключительно для УЖЕ авторизованной консоли на существующем сервере evrasia с доступом к eurasia_new. Наличие такой доступной пользователю консоли или её конкретного продукта не подтверждено. Файл сам не подключается, не получает/читает пароли, не запускает PHP и не меняет auth.

В нём пять SELECT: текущая БД/версия и четыре ограниченные выборки information_schema TABLES/COLUMNS/STATISTICS/TABLE_CONSTRAINTS. Во всех metadata SELECT явно закреплены schema eurasia_new и прежние10имён. Нет USE/SET/START TRANSACTION/COMMIT/ROLLBACK, чтобы не менять уже открытое состояние чужой сессии. Нет application-row SELECT, defaults, trigger bodies, cardinality, DDL или вывода секретов. Результаты отдельных SELECT не объявлять атомарным снимком; невидимость объекта учитывать вместе с правами. Нельзя заключать «таблица отсутствует» только по пустому ответу ограниченного пользователя.

SQL-only SHA256 `4774dd00c565b36804ebe4d004d3b9a72c7192b80e9ee2c08f44f34b451bf84a`, blob `4dd1cb344e5cf73ad6984a379cee0f89b437f81b`. Выполнена только локальная статическая проверка состава:5SELECT, только четыре information_schema источника, фиксированный schema и отсутствие пишущих/файловых/блокирующих конструкций. SQL на сервере НЕ выполнялся, новых metadata результатов/DB assertions нет. Следующий необходимый ввод — результаты этих SELECT из авторизованной консоли, не пароль и не дамп клиентов.

Имя БД eurasia_new ранее подтверждено `ANTI_FRAUD_CONSENT_DATA_MAP.md` / `PROJECT_CHECKPOINT.md`. Отдельная metadata-консоль не заменяет требование одного native Bitrix connection для атомарной авторизации V5.

## 2. Production: установлено, но выключено

V4/V5 параллельно на существующем `evrasia`. Отдельной VM не будет; второй MySQL или копия сайта не обязательны. Аккаунты и бизнес-данные общие, служебные компоненты V5 отдельные. Бот eur-bot-01 не переносится.

| Объект | Состояние |
|---|---|
| Installed runtime | `0caa0248c783188939398a71543ba740f064bb90`, mode off |
| Public | `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5` |
| Private | `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90` |
| Backup | `/root/ev-v5-off-backup.f89wzMkA` |
| Rollback | `/root/ev-v5-off-backup.f89wzMkA/rollback.sh`, не запускался |
| Manifest | `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/installed.sha256` |

Layout и INSTALL_DISABLED_ONLY PASS; CLI отsite_evrasia дал503. Direct router.php и nested auth/signin через --resolve evrasia.rest:443:192.168.103.141 дали exact503JSON/TLSverify0/no-store/no-cookie/no-redirect/no-CORS/noAge; оба PASS, после запросов OFF/V4/hash unchanged. Первый CURL_RC7 без --resolve — история. Не ремонтировать public hairpin.

Web-config snapshot PASS: nginx HTTP→http192.168.103.141:8080, SSL→https192.168.103.141:8443, оба Apache vhost→PHP8.3 site socket. Прочитанный php.ini: auto_prepend/append disabled, session.auto_start0. Wildcard includes не раскрывались; generic fastcgi_params не доказывает передачу параметров в Apache chain. PHP_RUNTIME_MAPPING_ATTESTED=NO, PUBLIC_EDGE_TEST=NOT_RUN, V4 functional E2E/native auth не выполнены.

Site UID/GID1005:1005, CLI8.3.21; private уже в pool open_basedir. Root/V4 SHA закреплены в прежних helper. Private V5 root:1005 с0750/0640, public0755/0644; site-owned ancestors не дают абсолютной изоляции от компрометации site account. Конфиги/права не менять без причины.

## 3. Последний проверенный runtime-код и CI

**Code/test `6f8a35211671aaa25f8129eeac90b802944646cd`**: offline38771014889 и ephemeral38771014920 SUCCESS; ранее прочитан полный DB лог:43+117+45+63+61+77=406. Последующие helper/docs — не новый runtime и не его deployment.

В текущей итерации до изменений livefeature подтверждён какdf25f34aa894aa92292ba96f2fb6698fcbc02449. Его offlinecheck116559386179 / run38774752671 completed/success. Эта CI не проверила production socket login; пользовательскийERROR1045 имеет приоритет над предположениями о подключении. Новый SQL-only файл не объявлять проверенным на рабочей БД.

V5MySqlPilotComposition собирает existing workflow/transport/challenge/session/refresh/PIN на одном PDO/Bitrix connection с согласованными ключами. V5PilotScopedNativePorts добавляет обязательные1–32accountID поверх nativeproof, включая проверку старыхcredentials в новых запросах после исключения аккаунта. Это не permanentDBrevocation и не отмена in-flight requests. Положительные providers/Bitrix facade вCI синтетические. Настоящая site-factory и approvedIDs не установлены.

Installer/test c37ab9959bb13dae49f9c1a2c4ddda0b06905c69 и его pins неизменны. CI package step проверяет exactinstalled0caa0248, DB tests currentfeature. Runtime src/API, proposed schemas, workflows и installedrelease этим шагом не меняются. Mainbaseline7c2e9f8e4501b92469bcdeea18f8340676ad52c9 не трогать: push публикуетlatest.

## 4. Бизнес-логика и незавершённое

Пароль → обязательный nativeTOTP без fallback при ошибке/unknown; иначе сильноеV5trust, не слабоеV4; без обоих password-boundSMS на подтверждённый телефон аккаунта, без номера с клиента. После сильного входа PIN без второйSMS. Сохраняются active/block/expiry/Anti-Fraud/attemptlimits,10quota scopes, HMAC/encryptedoutbox/bindings, tariff/currency/idempotency. Claimed/unknown автоматически не пересылать; SENT только independentreceipt; late receipt не оживляетchallenge/не меняетbudget. PHONE_EVENT_VERSION меняется и при повторном подтверждении того жеE.164, AUTH_EPOCH атомарен с native securitymutations.

Не завершены nativefactory/TOTP-code/replay/10-path epochhooks/phone-success и reconfirm/strongtrust/SMS begin-resend/provider receipt-tariff/RestIS/transactionpermit/additiveDDL/реальныйпилот. Accountscope иHTTP503 не заменяют эти proofs. PendingTOTPverifier и прежний instrumentedPHPshim не приняты. ЗакрытыеCUser/PhoneAuth/OTPsource audits не повторять; SHA-driftuser.php не утверждён как baseline.

После metadataoutput: анализ движков/колонок/индексов → точная additiveмиграция с отдельнымдопуском/backup/verify/rollback, не автоматическийDDL. До результата ничего в БД не создавать. CI/faultschemas на production запрещены. Серверные действия — один guardedблок, FINAL_STATUS/FINAL_RC, безсекретов; SQL-only действие предназначено для существующей авторизованной DB-консоли, НЕ bashтерминала.

**CURRENT:** INSTALLED_DISABLED / ORIGIN_HTTPS_PASS / WEB_CONFIG_PASS / COMPOSITION_CI_406_PASS / SOCKET_PASSWORDLESS_AUTH_FAILED_1045 / METADATA_NOT_READ / SQL_ONLY_HANDOFF_READY / NO_NATIVE_AUTH / NO_V5_DB_MIGRATION.
