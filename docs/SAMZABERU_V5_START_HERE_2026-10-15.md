# Evrasia AI Bot / СамЗаберу V5 — START HERE, 15 октября 2026

**Текущая точка: V5 установлен OFF; origin HTTPS и web-config уже PASS. Пользователь подтвердил следующий read-only шаг рабочей БД. Подготовлен только socket-only сбор метаданных; его серверный результат ещё НЕ получен.** Не повторять layout, установку, проверку503 или web-config.

Repo `juvantusik/Evrasia_AI_bot`, branch `feature/samzaberu-api-v5-auth-contract`. Читать текущий checkpoint, integration decisions и `SAMZABERU_V5_PILOT_COMPOSITION_2026-10-15.md`. Полная предыдущая редакция всех доказательств сохранена в commit `dcc473b432f66e01175fe101c511e534da7e88ba`; изменения ниже не отменяют её бизнес-правила и ограничения.

## 1. Следующий подготовленный блок

Пользователь ответил «дальше» на запрос разрешить **только чтение структуры рабочей БД**, без клиентских записей/DDL/мутаций/включения V5. Это допуск к указанному чтению, не к миграции.

`site-integration/samzaberu-v5/deployment/read-v5-db-metadata-socket.sh`:
- SHA256 `4158763756d3c9eb2c36e633465e286f67ac43a3f3788c6e9276bbbf1b5bc310`;
- Git blob `7cb46e944458ea82a2b3277cb1e466d0d456721b`;
- root/evrasia, фиксированная БД **eurasia_new**, локальный socket `/run/mysqld/mysqld.sock`;
- используются существующий mysql/mariadb client, пустой HOME и --no-defaults; пароль не передаётся, credential files не читаются, PHP/Bitrix не исполняются;
- существующий локальный вход root через socket **не подтверждён**: если он недоступен, скрипт останавливается без чтения .settings.php, смены auth, TCP или password fallback;
- session READ ONLY и START TRANSACTION READ ONLY, ограниченные information_schema SELECT, затем ROLLBACK; таймаут ожидания metadata lock3секунды, общий timeout45секунд;
- ровно10 имён: b_user, b_sec_user, b_user_phone_auth, ev_trusted_devices, ev_samzaberu_auth_state, ev_v5_auth_attempt_windows, ev_v5_auth_challenges, ev_v5_sms_budget_windows, ev_v5_sms_outbox, ev_v5_strong_sessions;
- только таблицы/движки/collation, столбцы/типы/nullable/length/unsigned, индексы и типы constraints. Нет данных клиентов, COUNT строк приложения, SHOW CREATE, defaults, cardinality, trigger bodies или текстов выражений;
- два чтения метаданных сравниваются; это стабильность снимков, не гарантия отсутствия будущего DDL. NOT_VISIBLE не автоматически значит отсутствует: учитывать права;
- OFF manifest и root/V4 SHA сверяются до/после. Файлы сайта, конфигурация V5, БД и сервисы не изменяются; возможны обычные DB audit logs.

Локально bash-n, compile двух встроенных Python частей, wrong-host denial с DATABASE_CONNECTION_ATTEMPTED=NO, проверка SQL-scope и8/8 synthetic output cases PASS. **Это не реальное подключение к MySQL, не подтверждение root socket login и не новые MariaDB assertions.** Результат оператора ожидается. Не утверждать наличие/отсутствие V5 таблиц до результата.

Первый вариант с разбором .settings.php был отклонён инструментом при публикации и НЕ включён в ветку/блок оператора. Он не повторялся через другую кодировку или канал. Принятый отдельный вариант исключает чтение credential files и весь PHP, не делает такого разбора. Если socket login не работает, не возвращаться автоматически к заблокированному варианту.

Имя БД eurasia_new восстановлено из `docs/ANTI_FRAUD_CONSENT_DATA_MAP.md` и `PROJECT_CHECKPOINT.md` через live GitHub. Не путать с БД бота. Метаданные читаются через отдельный диагностический клиент; **это не меняет требование одного native Bitrix connection для атомарной авторизации**.

## 2. Production: установлено, но выключено

V4/V5 параллельно на существующем `evrasia`. Отдельной VM не будет; второй MySQL или копия сайта не обязательны. Аккаунты и бизнес-данные общие, служебные компоненты V5 отдельные. Бот eur-bot-01 не переносится.

| Объект | Состояние |
|---|---|
| Installed runtime | `0caa0248c783188939398a71543ba740f064bb90`, mode off |
| Public | `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5` |
| Private | `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90` |
| Backup | `/root/ev-v5-off-backup.f89wzMkA` |
| Rollback, не запускался | `/root/ev-v5-off-backup.f89wzMkA/rollback.sh` |
| Manifest | `<private parent>/samzaberu-v5/installed.sha256` |

Layout и INSTALL_DISABLED_ONLY PASS; CLI отsite_evrasia дал503. Direct router.php и nested auth/signin через --resolve evrasia.rest:443:192.168.103.141 дали exact503JSON/TLSverify0/no-store/no-cookie/no-redirect/no-CORS/noAge; оба PASS, после запросов OFF/V4/hash unchanged. Первый CURL_RC7 без --resolve — история. Не ремонтировать public hairpin.

Web-config snapshot PASS: nginx HTTP→http192.168.103.141:8080, SSL→https192.168.103.141:8443, оба Apache vhost→PHP8.3 site socket. Прочитанный php.ini: auto_prepend/append disabled, session.auto_start0. Wildcard includes не раскрывались; generic fastcgi_params не доказывает передачу параметров в Apache chain. PHP_RUNTIME_MAPPING_ATTESTED=NO, PUBLIC_EDGE_TEST=NOT_RUN, V4 functional E2E/native auth не выполнены.

Site UID/GID1005:1005, CLI8.3.21; private уже в pool open_basedir. Root/V4 SHA закреплены в helper. Private V5 root:1005 с0750/0640, public0755/0644; site-owned ancestors не дают абсолютной изоляции от компрометации site account. Конфиги/права не менять без причины.

## 3. Последний проверенный runtime-код и CI

**Code/test `6f8a35211671aaa25f8129eeac90b802944646cd`**: offline38771014889 и ephemeral38771014920 SUCCESS; повторно прочитаны live run statuses перед текущей работой. Прежний полный DB лог:43+117+45+63+61+77=406. Последующие helper/docs не являются новым runtime или его deployment.

V5MySqlPilotComposition собирает существующие workflow/transport/challenge/session/refresh/PIN компоненты на одном PDO/Bitrix connection с согласованными ключами. V5PilotScopedNativePorts добавляет обязательный список1–32 accountID поверх nativeproof, включая проверку старыхcredentials при новых запросах после исключения аккаунта. Это не permanent DB revocation и не отмена in-flight requests. Положительные providers/Bitrix facade вCI синтетические. Настоящая site-factory и approvedIDs не установлены.

Installer/test c37ab9959bb13dae49f9c1a2c4ddda0b06905c69 и его source pins неизменны. CI package step проверяет точный installed0caa0248, DB tests currentfeature. Новый metadata helper не меняет workflows, src/API, SQL schemas или установленный release. Последний main baseline7c2e9f8e4501b92469bcdeea18f8340676ad52c9; main не трогать: push публикуетlatest.

## 4. Бизнес-логика и незавершённое

Пароль → обязательный nativeTOTP без fallback при ошибке/unknown; иначе сильноеV5trust (не слабоеV4); без обоих password-boundSMS на подтверждённый телефон аккаунта, без номера с клиента. После сильного входа PIN без второйSMS. Сохраняются active/block/expiry/Anti-Fraud/attemptlimits,10quota scopes, HMAC/encryptedoutbox/bindings, tariff/currency/idempotency. Claimed/unknown автоматически не пересылать; SENT только independentreceipt; late receipt не оживляетchallenge/не меняетbudget. PHONE_EVENT_VERSION меняется и при повторном подтверждении того жеE.164, AUTH_EPOCH атомарен с native securitymutations.

Не завершены nativefactory/TOTP-code/replay/10-path epochhooks/phone-success и reconfirm/strongtrust/SMS begin-resend/provider receipt-tariff/RestIS/transactionpermit/additiveDDL/реальныйпилот. Accountscope иHTTP503 не заменяют эти proofs. PendingTOTPverifier и прежний временныйinstrumentedPHPshim вне принятогоsrc/CI; заблокированные payload не повторять. Закрытые CUser/PhoneAuth/OTPsource audits сохранены, не запускать заново; SHA-driftuser.php не утверждён как baseline.

После metadata output: анализ движков/колонок/индексов → точная additive миграция с отдельным допуском/backup/verify/rollback, не автоматический DDL. До этого ничего в БД не создавать. CI/faultschemas не запускать на production. Серверные действия — один guarded блок, FINAL_STATUS/FINAL_RC, безсекретов, SSH не закрывать.

**CURRENT:** INSTALLED_DISABLED / ORIGIN_HTTPS_PASS / WEB_CONFIG_SNAPSHOT_PASS / COMPOSITION_CI_406_PASS / METADATA_READ_APPROVED / METADATA_RESULT_PENDING / NO_NATIVE_AUTH / NO_V5_DB_MIGRATION.
