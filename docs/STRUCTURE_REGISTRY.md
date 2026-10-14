# Evrasia — единый реестр исследованных структур и контрактов

> **АКТУАЛЬНОЕ ОГРАНИЧЕНИЕ (2026-10-14):** текстовые совпадения старых телефонных скриптов с V4 `Auth.php` около 869–896 и 1009–1014 **находятся в комментариях**. Нельзя повторно использовать их как действующие нативные SMS/phone verification writers. Latest source-only evidence and one next approved active-code inventory: см. секцию `Production PHP lexical truth` внизу этого реестра.

> **Назначение:** единая точка восстановления фактов о сайте Bitrix, API V4, Trusted Device, TOTP/V3C и разработке API V5, чтобы не повторять одни и те же исследования.  
> **Обновлено:** 2026-10-14 (production V4 whole-file PHP lexical source map 5/5 PASS: `confirmation`, `signup` active; `confirm_request`, `confirm` comments; single active native phone `add` 372; Bitrix staging/atomicity not proven).
> **Статус документа:** документация, **не** признак развёртывания нового API.  
> **Источник при конфликте:** фактический production → актуальный код/ветка GitHub → staging/test → документация → старые обсуждения.

## 0. Правило работы с реестром

Этот документ — основной **индекс уже проверенных структур**, а не замена детальным актам. В новых чатах сначала читать его, затем только тематический документ. **Не повторять** широкие аудиты всего Bitrix/V4/Trusted Device/TOTP ради одного следующего шага.

Степени подтверждения:

- **PRODUCTION / DB VERIFIED** — получен успешный вывод с действующего сервера/из фактических метаданных БД. Если указано «схема», реальные записи пользователей не читались.
- **PRODUCTION / SOURCE VERIFIED** — исходный файл и/или его SHA подтверждены на сервере; это не равно проверенному поведению при реальном HTTP-запросе.
- **PRODUCTION / E2E ACCEPTED** — пользователь подтвердил отдельный production-сценарий; распространяется только на этот сценарий.
- **OFFLINE TESTED** — есть изолированный PHP-тест; **не значит** Bitrix/staging/production integration.
- **UNVERIFIED / PENDING** — пока не проверено, нельзя делать вывод «работает».
- **HISTORICAL BASELINE** — прежний принятый SHA/срез; при появлении более нового фактического значения не считать исторический SHA текущим.

Новая диагностика оправдана, только если: (1) изменился соответствующий исходник/релиз/схема; (2) впервые подключаем **непроверенную** зависимость; (3) нужна конкретная pre-write проверка перед операцией; (4) есть воспроизводимый дефект. В других случаях ссылаться на этот реестр.

## 1. Границы систем и размещение

| Система | Production | Сфера |
|---|---|---|
| Сайт Evrasia / Bitrix | host `evrasia`, `/home/site_evrasia/web/evrasia.spb.ru/public_html` | `b_user`, MFA, сайт, V4 auth/JWT, SMS, PIN, Trusted Device |
| Evrasia AI Bot | host `eur-bot-01`, приложение `evrasia-ai-bot-app`, отдельная PostgreSQL `evrasia_ai_bot` | Anti-Fraud, Phonebook, Telegram, копии событий/устройств |
| GitHub | `juvantusik/Evrasia_AI_bot`, `main` + `feature/samzaberu-api-v5-auth-contract` | основная документация + **изолированный, неразвёрнутый** V5 |

Сайт Bitrix и бот — **разные production-системы и БД**. Проектные исходники V5 на GitHub не означают, что они установлены на сайте.

## 2. Подтверждённые исходники сайта (путь относительно корня Bitrix)

| Источник | SHA-256 из последнего тематического аудита | Подтверждённое назначение / статус |
|---|---|---|
| `local/php_interface/Controllers/V4/Auth.php` | `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` | V4 signin, **SOURCE VERIFIED 2026-10-11** |
| `api/v4/router.php` | `3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50` | маршрутизатор V4, ранее source-verified |
| `api/v4/.htaccess` | `61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3` | `RewriteBase` присутствует, 3 `RewriteCond`, 1 `RewriteRule` на `router.php`; **не** сохранён полный текст условий |
| `bitrix/modules/main/lib/UserTable.php` | `e9574a33afa1a592b7f4555ba1f5a5bb54e2fa70f84968360feeb56396e725a4` | проверен статический поиск объявлений, **SOURCE VERIFIED 2026-10-11** |
| `bitrix/modules/main/lib/Security/Password.php` | `a1ffbba30510caa373087127d3af5714d36be526f5df854610f80ff227a10296` | `Password::equals()`; искусственные SHA-512 и legacy salted-MD5 прошли; **SOURCE + SYNTHETIC TEST VERIFIED** |
| `bitrix/modules/security/lib/Mfa/Otp.php` | `285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c` | нативная Bitrix MFA; **SOURCE VERIFIED 2026-10-11** |
| `bitrix/modules/security/lib/Mfa/UserTable.php` | `cf3979c6bed5684bacc758656c55431a60cefe2e356b1b2a4e63fb323a283a64` | ORM `b_sec_user`; **SOURCE VERIFIED 2026-10-11** |
| `bitrix/modules/security/classes/general/user.php` | `4f177416df72ffb7f92573c57028d2607238a2df1e1654c3e594624ad7795fa3` | штатные OTP/login hooks; ранее source-verified |
| `bitrix/modules/main/classes/general/user.php` | `542034ca4eee2c30bdc033f45d792945c10392aeaf2e482c01958e2bd585b9d0` | штатная политика входа; ранее source-verified |
| `local/php_interface/lib/Services/TrustedDeviceMobileService.php` | `e6b47cac5d5ea747e082e114970f8cf3b83ea6b84a9d3e0f1341b030773c0e7e` | SHA текущего аудита **2026-10-11**; исторический принятый 2026-09-24 отличался |
| `local/php_interface/lib/Services/TrustedDeviceService.php` | `58e107742f5a40b1b28cd439bee5b9721b020ddb25873feb01b79a404c644417` | SHA текущего аудита **2026-10-11**; исторический принятый отличался |
| `local/php_interface/lib/PhoneAuth.php` | `a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27` | отдельный SMS/Mobile ID контур V4 |
| `local/php_interface/lib/JwtAuthorization.php` | `0e539789a6322b2c209b9e8e937bb04e43344aa9092bcac5e627136c30fbc7d9` | JWT V4 access/refresh; НЕ использовать как V5 assurance |
| `local/php_interface/Controllers/V4/PinCode.php` | `7734ddbaba0e7ec9ddfc9f0a9008c0da0c0a1523e505e52caebac2bbaf637cd0` | V4 выдача бонусного PIN через прежний канал, **исторический исходник** |

Это контрольные суммы **на дату соответствующей проверки**, не гарантии неизменности на будущую дату. История веб-2FA и файлов отображения PIN — в `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`.

## 3. База сайта Bitrix — реестр таблиц

### 3.1 `b_user` — РЕАЛЬНАЯ СХЕМА ПОДТВЕРЖДЕНА 2026-10-11

На `evrasia` оператор выполнил read-only запрос к `information_schema.COLUMNS`, ограниченный `TABLE_SCHEMA = DATABASE()` и `TABLE_NAME = 'b_user'`. Два присланных результата идентичны:

```text
COLUMN_ID=YES
COLUMN_LOGIN=YES
COLUMN_EMAIL=YES
COLUMN_PASSWORD=YES
COLUMN_ACTIVE=YES
COLUMN_BLOCKED=YES
COLUMN_PASSWORD_EXPIRED=YES
COLUMN_LOGIN_ATTEMPTS=YES
MISSING_COLUMN_COUNT=0
FINAL_STATUS=PASS
FINAL_RC=0
TABLE_RECORDS_READ=NO
DATABASE_WRITE=NO
```

**Закрыто:** физическое наличие всех восьми полей в production. **Не надо снова искать эти поля в таблице.**

Важное различие уровней:

| Слой | Проверенный факт | Чего этот факт не доказывает |
|---|---|---|
| **Физическая DB** | Все 8 колонок `b_user` существуют — **DB VERIFIED** | Не подтверждены их текущие значения и допустимые пороги блокировок |
| **Статический исходник ORM** | В `Main/UserTable.php` найдены токены `ID/LOGIN/EMAIL/PASSWORD/ACTIVE/BLOCKED`; токены `PASSWORD_EXPIRED/LOGIN_ATTEMPTS` не найдены | Это НЕ доказывает физическое отсутствие колонок и даже не заменяет runtime `getEntity()->getFields()` |
| **Стандартный login PHP** | `PASSWORD_EXPIRED`, `LOGIN_ATTEMPTS` и `OnBeforeUserLogin` присутствуют в штатном обработчике | Значение/порог политики и точные обработчики ещё не интегрированы в V5 |

Принятая коррекция V5: ORM-адаптер обязан отказаться от запроса, если runtime ORM map не содержит все поля; отдельный `V5PdoUserRowSource` подготовлен как **OFFLINE TESTED** альтернативный `SELECT` с плейсхолдерами, максимум 2 строки. SQL-кандидат всё ещё **НЕ проверен подключением к production БД**. Никогда не запрашивать `limit=1` так, чтобы скрыть неоднозначный логин; не исключать заблокированные записи на этапе проверки уникальности.

Иные ранее подтверждённые поля пользователей, используемые для телефонов: `PERSONAL_PHONE`, `PERSONAL_MOBILE`, `WORK_PHONE`; их применимость к SMS-челленджу зависит от **верифицированного** источника телефона. Не путать профильный телефон с подтверждённым.

### 3.2 `b_sec_user` — штатный нативный MFA/TOTP Bitrix

Предыдущими read-only аудитами на сайте подтверждена нативная таблица с полями:
`USER_ID` (primary key), `ACTIVE`, `SECRET`, `TYPE`, `PARAMS`, `ATTEMPTS`, `INITIAL_DATE`, `SKIP_MANDATORY`, `DEACTIVATE_UNTIL`, `INIT_PARAMS`, `EMAIL`, `DATE_SENT_EMAIL`. **CONFIRMED HISTORICAL STRUCTURE**, подтверждён текущий исходник ORM MFA; перечень не является новым запросом к БД.

Нативные классы: `Bitrix\Security\Mfa\Otp`, `Bitrix\Security\Mfa\UserTable`; известны `getByUser()`, `verify()`, `save()`, `activate()`, `deactivate()`, `cleanCache()`, `isMandatoryUsing()`, `isOtpRequired()`, `getPolicy()` и обработчики входа.

Правило V5: **активный TOTP → запрос TOTP**; **достоверно нет настроенной 2FA + политика разрешает → Trusted Device/SMS**; **TOTP существует, но отключён/повреждён/неизвестен → запрет, НЕ downgrade**. Текущая политика глобальной/обязательной OTP должна проверяться авторитетно и повторно перед выдачей сессии. Нативная `Otp::getByUser()` может использовать кэш; V5-кандидат читает запись отдельно. Нативный код TOTP не копировать.

### 3.3 `b_sec_recovery_codes`

Ранее подтверждённая структура: `ID`, `USER_ID`, `CODE`, `USED`, `USING_DATE`, `USING_IP`. Наличие таблицы/механизма НЕ значит, что восстановление включено: статус включённости зависит от актуальной конфигурации. Не проектировать автоматический SMS downgrade для аккаунтов с 2FA.

### 3.4 `b_user_phone_auth`

Ранее исследован источник подтверждённых телефонов Bitrix, в частности для сверки личности и V3C. Не заменять его догадкой о `PERSONAL_PHONE`. Исторические количества записей — временной срез, не применять их как текущее состояние. Для реальной SMS-отправки нужен отдельный проверенный резолвер на стороне сервера без поля `phone` во входном запросе.

### 3.5 `ev_trusted_devices` и события Trusted Device

Production Trusted Device **E2E ACCEPTED 2026-09-24**: `ev_trusted_devices` хранит браузерные и мобильные связи; управляются статус/версия токена/источник создания/время/связь с пользователем. Точные DDL/имена дополнительных столбцов брать из `docs/SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md` и текущей схемы только при первом реальном изменении. Токен и полный device hash в диагностике не печатать.

### 3.6 Сессии JWT V4 и logout

Уже изучены `JwtAuthorization.php`, V4 refresh через `JwtAccessTokenTable`, использованные logout механизмы `jwt_tokens`, `access_token` и `UserAuthActionTable`. **Точная DDL этих хранилищ здесь не утверждается**, поскольку текущая работа её не запрашивала. Верифицирована разделённость: V4 access/refresh **не** является V5 assurance; запрещено обновлять V4 refresh до V5 сильной сессии.

### 3.7 Юридические согласия — другая уже исследованная подсистема

В `docs/PROJECT_CHECKPOINT.md` §5 зафиксирована production-схема: `b_consent_agreement`, `b_consent_user_consent`, `b_consent_field`, `b_consent_user_consent_item`; источником факта согласия является `b_consent_user_consent` по `AGREEMENT_ID + USER_ID`, а не пользовательские `UF_*`. Уже принятые различия между обязательной офертой/ПД и необязательным маркетинговым согласием не исследовать заново при работе над V5 auth. Подробности см. в указанном checkpoint и документе по Legal Consents.

### 3.8 Отдельная БД Anti-Fraud Bot — НЕ таблицы Bitrix

На `eur-bot-01` PostgreSQL использует, в частности, `anti_fraud_device_links` (срез текущих связей), `anti_fraud_device_events` (события), `anti_fraud_web_account_state` и `anti_fraud_visits`. Они не являются источником проверки пароля/MFA; бизнес-правила и миграции — в `docs/CURRENT_ARCHITECTURE.md` и `docs/PROJECT_CHECKPOINT.md`. Мобильный Trusted Device → бот-синхронизация E2E уже была принята; повторный full audit не требуется.

## 4. Подтверждённые цепочки авторизации и поведения

### 4.1 Mobile API V4 — ИСХОДНИКИ ПРОВЕРЕНЫ

```text
/api/v4/.htaccess → api/v4/router.php → Auth::signin()
login/email or phone → PhoneConfirm::setPhoneMask() (если телефон)
→ Bitrix\Main\UserTable::getList() по EMAIL либо LOGIN
→ CUser->Login()
→ JwtAuthorization::createTokens()
→ TrustedDeviceMobileService::registerSuccessfulAuthentication(AUTH_PASSWORD)
→ V4 response
```

`Auth.php`: `UserTable::getList` около строки 202, `CUser->Login` около 226, выдача JWT около 238, регистрация мобильного устройства после парольного входа около 241. На production V4 остаётся работающим. **Поведение штатного 2FA при каждом конкретном мобильном V4-входе требует отдельного E2E при миграции; не утверждать, что обычный `CUser->Login()` эквивалентен безопасному V5 first-factor.**

`/api/v4/.htaccess` содержит `RewriteBase`, три условия и правило на `router.php`. Это уже проверено; не повторять, пока источник не изменился. Полный набор условий не архивирован; не копировать его без проверки при отдельной сетевой интеграции.

### 4.2 Проверка пароля без преждевременного входа

На production с **искусственными тестовыми данными** пройдена прямая проверка `Bitrix\Main\Security\Password::equals($hash,$password)` для SHA-512 и legacy salted-MD5, включая неверный пароль. `CUser->Login()`, PHP-сессия, реальные пользователи не использовались. Это подтверждённый криптографический **первый фактор**, а не вся политика допуска: состояние аккаунта, throttle/lockouts, MFA и второй фактор всё равно обязательны.

### 4.3 Native Bitrix TOTP и прототип V3C

Bitrix MFA хранит нативные секреты и состояние replay в своей модели; подготовленный ранее `NativeTotpVerifierV3C` / `PdoTotpStoreV3C` проверялся офлайн и в изолированном E2E с MariaDB. **V3C НЕ развёрнут на production**, его дополнительные state-таблицы НЕ мигрированы. До утверждения совместного поведения Bitrix-сайта и мобильного канала нельзя заявлять закрытой гонку/повторное использование одного TOTP-кода.

Веб-пилот TOTP для пользователя `880339`: исправление кнопки самостоятельного отключения 2FA было **production/browser accepted 2026-09-24**; этот факт новее старого текста §17 TOTP design checkpoint, где ветка целиком значится planned. **Принятое веб-исправление не равнозначно развёрнутому API V5.**

### 4.4 Trusted Device — уже принятое production поведение

Контракт SamZaberu `device_id = "sz_" + 64 lowercase hex` (67 символов), `platform = ios|android`; полный namespaced ID хешируется SHA-256 в 64 hex **включая префикс**, затем передаётся существующему core. Не удалять `sz_`, не менять формат общего TrustedDeviceService и не считать device_id аппаратным идентификатором. Полный контракт и факты E2E — `docs/SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md`.

**Важно для V5:** зарегистрированное через V4 `AUTH_PASSWORD` устройство НЕ считается доказательством ранее пройденного SMS/TOTP. `tokenValid` и `strongEnrollmentProven` — две независимые серверно проверяемые истины. Мобильные классы были изменены с 2026-09-24: использовать более свежие SHA из §2.

### 4.5 Bonus PIN, SMS, JWT

- Веб- и мобильный V4 PIN исторически используют RestIS `CRestis::pincode()` и отправку через VK/SMS, а не гарантированный показ на экране. Текущие значения и deploy проверять только при изменении этого потока.
- Требование V5: после полностью проверенного входа показать PIN без повторного SMS. **Нельзя** показывать PIN по V4 JWT, флагу «у пользователя включена 2FA» или неподтверждённому `amr`.
- SMS-челлендж отправляется только на **проверенный сервером телефон аккаунта**, не на номер от клиента. Код и токены не логировать. Лимиты/квоты/атомарное погашение — ещё не внедрены.

## 5. API V5 — состояние отдельной ветки, НЕ production

Ветка: `feature/samzaberu-api-v5-auth-contract`; контракты/история: `docs/SAMZABERU_API_V5_AUTH_CONTRACT_2026-10-11.md`, `site-integration/samzaberu-v5/README.md` **в этой ветке**.

| Часть | Известное состояние |
|---|---|
| `AuthDecision`, `ChallengeRules`, `V5SessionAssurancePolicy` | OFFLINE TESTED, правила, не хранилище/криптографический verifier |
| `V5RouteMap`, `api/v5/router.php`, кандидат `.htaccess` | GitHub only, все HTTP-обработчики fail-closed/503, Apache staging не проверен |
| `V5LoginWorkflow`, `V5AuthHttpHandler` | OFFLINE TESTED; пароль → TOTP / strong trust / SMS, HTTP JSON без JWT до подтверждения |
| `V5BitrixPasswordProofPort` | OFFLINE TESTED; штатный comparator подтверждён отдельно на сервере; нет live attempt gate |
| `V5SiteAccountReader` | OFFLINE TESTED, уникальный EMAIL/LOGIN, блокировки; требует источников политики/MFA |
| `V5NativeBitrixUserRowSource` | fail-closed guard для runtime ORM field map; возможная неполная ORM-проекция |
| `V5PdoUserRowSource` | OFFLINE TESTED 28/28 с fake PDO; **физическая схема `b_user` теперь подтверждена**, но live SQL-коннект самого адаптера не проверен |
| `V5NativeTotpStatus` | OFFLINE TESTED; только безопасное определение состояния нативной MFA |
| `V5StrictExistingLoginPolicy` | OFFLINE TESTED; запрет при неизвестном лимите/lockout; текущий Bitrix controls-provider ещё не подключён |
| `V5StrictNoMfaFallbackPolicy` и `V5NativeBitrixGlobalOtpConfig` | OFFLINE TESTED; дополнительное пользовательское разрешение SMS ещё не подключено |
| V3C native TOTP | offline/staging prototype, не развернут и не заменяет нативный Bitrix |
| `V5AtomicFirstFactorAttemptGate` и `V5MySqlAttemptWindowStore` | **OFFLINE TESTED 144/144**, staging-only транзакционный кандидат с 4 HMAC-бюджетами; новая таблица **не создана**, реальная MySQL-конкуренция не проверена |
| `V5CurrentLoginControlsPort`, `V5PerUserSmsEligibilityPort`, strong-trust provenance | **PENDING** достоверных site-side провайдеров и интеграции |
| `V5SmsDispatchBudget` и `V5SmsBudgetPolicy` | **OFFLINE TESTED 111/111**, десять HMAC-квот включая общий денежный резерв; verified-phone/pricing sources **PENDING** |
| `V5MySqlSmsOutbox`, `V5SodiumSmsMessageFactory` | **OFFLINE TESTED 123/123** (fake PDO); три новых InnoDB-таблицы **только DDL proposal**; живой Bitrix policy, SMS-worker и реальный MySQL E2E **PENDING** |
| `V5BitrixLockedSmsEligibility`, `V5BitrixVerifiedSmsPhone` | **OFFLINE TESTED 88/88** (fake PDO), реальные таблицы под блокировкой; trusted phone-event version, auth epoch, MFA-lock synchronization и production site policy **PENDING** |
| `V5SecurityEventVersions`, `V5SecurityEventVersionWriter` | **OFFLINE TESTED 84/84** (fake PDO); общая **не установленная** V3C `ev_samzaberu_auth_state` с `AUTH_EPOCH` + event-version телефона; Bitrix hook coverage, migrations, security-event writes и real MySQL **PENDING** |
| `V5NativeMutationCoverageMatrix`, `V5StrictNativeSecurityHookCoverage` | **OFFLINE TESTED 60/60**; выпуск V5 невозможен без доказательства транзакционного покрытия 10 путей native security / MFA / phone; site attestation provider и native hook integration **PENDING** |
| `V5BitrixPdoCompatibilityBridge` | **OFFLINE TESTED 80/80** (fake MysqliConnection); transport to native `Application::getConnection()` without changing SMS business logic; trusted exclusive transaction permit, true ORM same-handle and concurrency E2E **PENDING** |
| `V5BitrixNativeOrmTransactionAcceptance.php` | **STAGING ACCEPTANCE PREPARED, NOT RUN**: real ORM insert ↔ native SQL read with rollback in both directions on isolated synthetic InnoDB table; lint/local fake smoke PASS, real Bitrix/MySQL proof **PENDING** |
| `V5StagingNativeTransactionPermit`, `V5VerifiedNativeTransactionDepthPort` | **OFFLINE TESTED 62/62**: staging-only double MySQL session check, exact Bitrix connection and independently verified depth=0; actual trusted native-depth reader / real staging acceptance **PENDING** |
| Атомарные SMS-челленджи, V5 JWT issuer/refresh, защищённый PIN | **PENDING**, не выдаются и не отправляются |
| Реальные /api/v5 запросы | **НЕДОСТУПНЫ: 503; production не меняли** |

Локальные подтверждённые тесты последних двух адаптеров: account ORM `94/94`, staged SQL `28/28`, native MFA status `63/63`, password proof `39/39`. **Это локальные тесты**, не production auth-acceptance.

## 6. Что больше НЕ исследуем повторно («замороженная карта фактов»)

| Закрытый вопрос | Где зафиксирован | Условие переоткрытия |
|---|---|---|
| Физическое наличие 8 колонок `b_user` | §3.1, read-only `information_schema`, 2026-10-11 | миграция/замена DB или новый симптом отсутствия столбца |
| Путь `Password.php`, наличие/работа `Password::equals` | §2, §4.2 | изменение SHA/Bitrix или ошибка реального адаптера |
| `b_sec_user`, нативные MFA сущности, методы OTP | §3.2, TOTP checkpoint | новая версия security module или конкретный E2E дефект |
| V4 поиск EMAIL/LOGIN + phone mask + путь JWT | §4.1 | изменение V4 `Auth.php` |
| V4 `router.php`, `.htaccess` rewrite target | §2, §4.1 | изменение маршрутизации/deploy V5 |
| Формат SamZaberu Trusted Device и принятый E2E | §3.5, §4.4, acceptance 2026-09-24 | изменение формата/device adapter или найденная регрессия |
| V3C был протестирован, но не установлен | §4.3 | реальная интеграция, а не общий повтор аудита |
| Структуры legal consent | §3.7, PROJECT_CHECKPOINT §5 | изменение согласий или отдельная новая бизнес-задача |

**Нельзя выдавать очередной «проверить всё» диагностический скрипт**, если следующий шаг зависит только от одного ещё не проверенного факта. При реальном изменении production проводить точечную повторную верификацию затронутого пути/схемы.


### 2026-10-11 — офлайн-реализация границ допуска V5 (без production-интеграции)

В `feature/samzaberu-api-v5-auth-contract` добавлены:
- `src/V5ExistingLoginPolicy.php`: `V5StrictExistingLoginPolicy` сверяет `LOGIN_ATTEMPTS` с **текущим авторитетным лимитом**, а также с временной блокировкой, ограничениями security и прочими правилами допуска. **Никакого заданного наугад лимита попыток нет**. Источник `V5CurrentLoginControlsPort` ещё не привязан к действующим правилам Bitrix; неизвестное значение блокирует вход.
- `src/V5NoMfaFallbackPolicy.php`: `V5StrictNoMfaFallbackPolicy` требует одновременно достоверной глобальной optional/mandatory-настройки Bitrix и **отдельного индивидуального разрешения** для пользователя без MFA. Кандидат `V5NativeBitrixGlobalOtpConfig` вызывает только штатные `Otp::isOtpEnabled()` / `Otp::isMandatoryUsing()`. Источник `V5PerUserSmsEligibilityPort` **ещё не реализован**; одной лишь пустой `b_sec_user` недостаточно.
- `tests/V5AuthPoliciesTest.php`: 98/98 автономных тестов PASS с искусственными данными, в том числе блокировка при отсутствующем лимите, обязательная MFA без downgrade, и интеграция с `V5NativeTotpStatus`.

Контрольный запуск существующих локальных тестов: `V5LoginWorkflowTest` 86/86, `V5AuthHttpHandlerTest` 48/48, `V5NativeTotpStatusTest` 63/63, новые политики 98/98: **295/295 PASS**. Новые GitHub blobs совпали с локально протестированными файлами.

**Подтверждено лишь OFFLINE TESTED:** ни `V5CurrentLoginControlsPort`, ни `V5PerUserSmsEligibilityPort` пока не имеют доверенных production-адаптеров. Даже при их создании требуется независимый атомарный лимитер V5 `V5FirstFactorAttemptGatePort`. Старые политики `OnBeforeUserLogin` не вызывать напрямую без оценки побочных эффектов; `CUser->Login()` нельзя использовать как «проверку пароля без входа».

**Следующий реальный шаг:** адаптировать *существующие* настройки/ограничения Bitrix и пользовательскую optional-MFA политику к этим двум интерфейсам с тестированием на staging. Колонки `b_user`, пути V4, методы TOTP и структура MFA уже подтверждены; их не пересматривать без изменения кода или конкретного дефекта. Production/V4/V5-router не затронуты.


### 2026-10-11 — четыре атомарных бюджета первого фактора (GitHub-only)

В ветке V5 добавлены `src/V5FirstFactorAttemptGate.php` (70/70 локальных тестов) и `src/V5MySqlAttemptWindowStore.php` (74/74 тестов на fake PDO). Инвариант: запрос на проверку пароля **до поиска пользователя** атомарно резервирует бюджеты по каноническому логину, IP источника, установке приложения и IP+логину. При превышении любого лимита — отказ и откат всех инкрементов. Значения квот не придуманы, будут заданы отдельной конфигурацией. Сырые логин/телефон/IP/device ID в таблицу не передаются: ключи — HMAC-SHA256. IP должен быть получен из серверного доверенного источника, а не подменяемого заголовка.

Проект новой таблицы: `schema/PROPOSED_ev_v5_auth_attempt_windows.sql` (InnoDB; **не запущен ни на staging, ни на production**). Проверки 144/144 проходили в PHP 8.4.24 с искусственными объектами/поддельной PDO; никакого реального MySQL-concurrency E2E, миграции или server-write нет. Нужны согласование квот, секретов, доверенного proxy IP, NTP и безопасного удаления устаревших окон. **SMS-челленджи и их денежные лимиты — отдельная незакрытая работа**.

Это не отменяет необходимость действующих источников `V5CurrentLoginControlsPort` (Bitrix login-policy) и `V5PerUserSmsEligibilityPort` (user/group MFA). Физическая `b_user` 8/8 уже проверена — НЕ исследовать её повторно.


### 2026-10-11 — подготовлен staging E2E для атомарного ограничителя (ещё НЕ запущен на MySQL)

В изолированной GitHub-ветке V5 созданы `site-integration/samzaberu-v5/tests/integration/V5MySqlAttemptStoreIntegration.php` и `run_mysql_attempt_concurrency.sh`; инструкция рядом в `tests/integration/README.md`. Два независимых файла **проверены синтаксически локально** (PHP 8.4.24 и Bash) и побайтово сверены с GitHub. Пуск без явных staging-параметров остановлен защитой ДО БД. Это НЕ означает завершение настоящего MySQL E2E.

Ожидаемый доказательный тест: **24** независимых клиента MySQL одновременно резервируют четыре лимита; ровно **7** проходят, **17** получают отказ, счётчики равны 7; отдельное испытание подтверждает откат уже изменённых счётчиков, если **последний** лимит исчерпан. Есть защитные условия: запрет production-хостов, исключительно предварительно созданная тестовая БД с именем `evrasia_v5_test_*` на подтверждённом staging MySQL, запрет вывода учётных данных, удаление только восьми собственных HMAC-ключей. В тестовой БД допускается создание лишь тестовой таблицы; на production БД **никаких изменений**.

**Незакрыто:** нет реального запуска в staging, поскольку в среде проверки отсутствовали MySQL и `pdo_mysql`; нужна подтверждённая тестовая база, 24 одновременных соединения и полный вывод `FINAL_STATUS=PASS`. Логика лимитера (144/144 на fake PDO) и staging-concurrency E2E — разные уровни. Другие источники Bitrix/MFA, лимиты SMS и V5 JWT продолжают ожидать подключения, повторные структурные аудиты не проводить.


### 2026-10-11 — V5 SMS/денежные квоты: код есть, транзакционного SMS-outbox ещё НЕТ

В `feature/samzaberu-api-v5-auth-contract` добавлен `site-integration/samzaberu-v5/src/V5SmsDispatchBudget.php` и тест `tests/V5SmsDispatchBudgetTest.php` (**111/111 PASS** на локальном PHP 8.4.24). Подготовлены десять серверных HMAC-окон: account burst/day, verified phone burst/day, IP burst/day, device burst, global burst/day, и глобальный дневной резерв **верхней стоимости SMS** в младших денежных единицах. Неизвестный подтверждённый номер, тариф, валюта или лимит → отказ до очереди. Номер и цену нельзя принимать из HTTP; полноценной реализации проверенного phone-provider и SMS tariff-provider пока нет.

Все новый source/test GitHub blob hash совпадают с локальными протестированными файлами. Результат пятёрки тестовых наборов: workflow 86, JSON 48, password gate 70, fake-PDO store 74, SMS budget 111 — **389/389 PASS** (НЕ production, НЕ real SMS).

**ОБЯЗАТЕЛЬНАЯ следующая реализация:** `V5AtomicSmsOutboxPort` должен в одной транзакции проверить парольный pending-челлендж, существующие правила `ChallengeRules` (300 сек, 60 сек, 3 отправки), текущее разрешение аккаунта и отсутствие TOTP-деградации, версию проверенного телефона, зарезервировать все десять квот и записать **зашифрованную** SMS-задачу с provider-idempotency key. Отправка только после коммита отдельным worker. Пока это **не реализовано и не протестировано**; никаких SMS/JWT, БД-миграций или production-изменений нет. Статус V5: HTTP 503.

**Детальный контракт:** `docs/SAMZABERU_V5_SMS_SEND_GUARDS_2026-10-11.md` в feature-ветке. Прежде чем запускать, проверить тариф/идемпотентность реального SMS-провайдера; не исследовать заново `b_user`, V4, TOTP и Trusted Device.



### 2026-10-12 — транзакционный SMS-outbox V5: готов офлайн-кандидат, НЕ production

На GitHub-ветке `feature/samzaberu-api-v5-auth-contract` создан `site-integration/samzaberu-v5/src/V5MySqlSmsOutbox.php` — candidate-реализация `V5AtomicSmsOutboxPort`, которая при условии работающего InnoDB должна **в одной транзакции** заблокировать существующий парольный pending SMS challenge, проверить HMAC-привязку устройства, TTL/предел 5 ошибок/cooldown 60 сек/≤3 отправок, запросить обязательную перепроверку состояния аккаунта/2FA/подтверждённого телефона **под теми же DB-locks**, зарезервировать 10 HMAC/SMS/денежных квот, обновить HMAC нового шестизначного кода и создать зашифрованную XChaCha20-Poly1305 outbox-запись с фиксированным provider-idempotency ID. При отказе последней денежной квоты откатываются предыдущие 9 обновлений.

**OFFLINE TESTED 123/123 PASS** на PHP 8.4.24 с имитацией PDO. Исходник, тест и SQL побайтово сверены с GitHub. Схема `schema/PROPOSED_ev_v5_sms_challenge_outbox.sql` описывает три **новые, не созданные** таблицы: `ev_v5_auth_challenges`, `ev_v5_sms_budget_windows`, `ev_v5_sms_outbox`. Это **предложение**, не заявление о существующей DB-структуре production. Локально проверено наличие всех используемых полей в DDL, но не запускалась SQL-миграция и настоящий MySQL-конкурентный E2E.

**Ещё НЕ реализованы:** доверенный `V5SmsLockedEligibilityPort` на Bitrix/verified-phone/epoch с блокировкой строк; создание password-proven challenge; SMS code verify/consume; проверенный провайдер/тариф, dispatcher с перепроверкой `current_delivery_id` и phone stamp + безопасной идемпотентностью; V5 JWT/бонусный PIN. Сам outbox не отправляет SMS и не выдаёт JWT. ИСТОРИЧЕСКИЕ пометки «транзакционный outbox ещё не написан» в более ранних разделах заменены этим новым offline-статусом, но deployment **НЕ** подтверждён.

Первоисточник: `docs/SAMZABERU_V5_SMS_SEND_GUARDS_2026-10-11.md` (`5 от 2026-10-12) в feature-ветке. Новые команды для production не требуются; не повторять исследование уже проверенных таблиц Bitrix.


### 2026-10-12 — V5 locked Bitrix SMS eligibility / verified phone (staging-only)

В `feature/samzaberu-api-v5-auth-contract` создан `src/V5BitrixLockedSmsEligibility.php`: фактические **SELECT ... FOR UPDATE** по давно подтверждённым `b_user → b_sec_user → b_user_phone_auth` в **одной транзакции с SMS-outbox** (порядок взят из V3C), без чтения нативного OTP-секрета. Проверяются `ACTIVE`, `BLOCKED`, `PASSWORD_EXPIRED`, `LOGIN_ATTEMPTS`; наличие **любой** MFA-записи блокирует SMS downgrade. Телефон берётся только из `b_user_phone_auth` с `CONFIRMED=Y`, подтверждаются совпадение номера и user ID; отсутствующие/дублированные записи отвергаются. Есть `V5BitrixVerifiedSmsPhone` для предварительного серверного разрешения телефона. За пределами известных E.164 форматов номеров преобразование **не угадывается**.

`tests/V5BitrixLockedSmsEligibilityTest.php` — **88/88 OFFLINE PASS** с fake PDO на PHP 8.4.24. Исходник и тест в GitHub побайтово совпадают с локальными тестами (учтён **настоящий** конструктор `V5SmsQueueReservation` с десятью квотами). Реальная Bitrix/MySQL интеграция по-прежнему **не** проходила E2E.

**Незакрытые security gates:** провайдер версии именно *события* подтверждения телефона `V5SmsPhoneVerificationVersionPort` (должен менять версию при повторном подтверждении того же номера), `V5SmsLockedAuthEpochPort` (доказательство актуального auth epoch после пароля и при изменении MFA/блокировки), реальный Bitrix login/mandatory MFA policy, сериализация отсутствия OTP-записи с native enrollment flows. `ev_samzaberu_auth_state` был проектом V3C и **не установлен**; не подменять настоящий epoch HMAC только от телефона/пароля. Отсутствие MFA-строки под `FOR UPDATE` само по себе не подтверждает race-safe отсутствие новых enrollments без общей дисциплины lock/epoch.

**Следующий этап:** проектировать/подключать event-based auth/phone epochs на подтверждённых security-событиях Bitrix, затем выполнить staging MySQL concurrency для SMS-outbox. Не возвращаться к глобальному аудиту `b_user`, V4, OTP и Trusted Device. Production/V4 без изменений, V5 HTTP 503.


### 2026-10-12 — общий V3C AUTH_EPOCH + события подтверждения телефона: staging-кандидат сохранён

Ранее «провайдеры phone-event version и auth_epoch ещё не написаны» → теперь в `feature/samzaberu-api-v5-auth-contract` добавлен `src/V5SecurityEventVersions.php`, реализующий оба провайдера и `V5SecurityEventVersionWriter`. Защита основана на **уже согласованной** для V3C, но **не установленной** `ev_samzaberu_auth_state.AUTH_EPOCH` (целое положительное число); отдельную конкурирующую таблицу не создаём. `schema/PROPOSED_ev_samzaberu_auth_state_v5.sql` — только **неприменённый** проект первоначальной совместимой схемы, включая новые `PHONE_EVENT_VERSION` и `PHONE_BINDING_HMAC` (никаких сырых телефонов). Если V3C будет установлен с другим DDL, эта CREATE-команда **не применима как миграция**, нужен отдельный аудит конкретного DDL.

Версия события подтверждения телефона является отдельным счётчиком: новое подтверждение **того же** номера обязательно меняет `PHONE_EVENT_VERSION` и делает старый SMS-челлендж недействительным. Хранится HMAC-привязка к подтверждённому E.164; изменение номера без зафиксированного события отклоняется. Auth stamp строится HMAC от user ID, `AUTH_EPOCH` и действующего сохранённого хеша пароля; смена пароля или увеличение epoch инвалидируют прежние доказательства первого фактора. Все HMAC-секреты отделены от JWT и не выводятся.

`V5SecurityEventVersionWriter` предусматривает CAS-обновление epoch в **общей** PDO-транзакции после блокировки `b_user → b_sec_user → b_user_phone_auth → auth_state`, **но действующие native Bitrix handlers ещё не подключены**. Живой `V5NativeSecurityHookCoveragePort` намеренно отсутствует: при false/unknown выдача phone stamp и auth stamp запрещена. Чтобы разрешить V5 в staging, должны быть подтверждены все native password/MFA/phone/logout/block/recovery security события, общий b_user mutex и атомарность изменений native state + epoch. Из-за отсутствия MFA-строки `FOR UPDATE` само по себе не исключает параллельного enrollment — необходима общая дисциплина блокировок. Никогда не считать хеш только от номера достаточным доказательством повторной SMS-верификации.

`tests/V5SecurityEventVersionsTest.php` — **84/84 PASS** на PHP 8.4.24 с fake PDO, в связке с настоящим `V5BitrixLockedSmsEligibility`; старый `V5BitrixLockedSmsEligibilityTest.php` повторно прошёл **88/88 PASS**; итого **172/172** в двух автономных наборах. Все 3 новых Git blobs совпали с локально протестированными/проверенными файлами. **Нет production/staging миграций, Bitrix hooks, SQL-коннекта и SMS; V5 HTTP 503.**

**Следующая точка:** точечно подтвердить совместимость native Bitrix security-event entrypoints с транзакционным обновлением общего `AUTH_EPOCH` и phone event version, затем stage-only интеграция и конкурентный тест «MFA enrolment или повторное подтверждение телефона ↔ SMS enqueue»; не повторять общее изучение таблиц.


### 2026-10-12 — native Bitrix MFA event-atomicity GAP: 10-path release gate реализован

Продолжена изолированная V5-ветка без повторных аудитов структур. Из **уже сохранённого production source audit** подтверждены важные независимые нативные пути: `Otp::save()` вызывает `UserTable::add()`; `Otp::delete()` вызывает `UserTable::delete()`; `OtpEvents::onRecheckDeactivate()` отдельно выполняет `UserTable::update()` для автоматического восстановления 2FA; `CSecurityUser::onAfterUserUpdate()` в проверенном участке только очищает cache при смене ACTIVE. **Нет подтверждения, что эти мутации имеют ту же физическую PDO/MySQL транзакцию, общий `b_user FOR UPDATE` и атомарное обновление `AUTH_EPOCH` вместе с native write.** Это не означает, что Bitrix вообще не имеет транзакций: требуется исследовать именно совместимость с V5. Простая регистрация OnAfter*-хука не закрывает race/SMS downgrade.

Добавлен `site-integration/samzaberu-v5/src/V5NativeMutationCoverage.php` с перечислением **10 обязательных** native security paths и fail-closed `V5StrictNativeSecurityHookCoverage`, реализующим существующий `V5NativeSecurityHookCoveragePort`. Для каждого пути нужны SHA реального writer source и доказанные: один PDO, b_user lock до native изменения, AUTH_EPOCH в той же транзакции, общий rollback, реальный staging concurrency acceptance. Если хоть один пропущен/unknown — допуск **запрещён**. Trusted deployment/user coverage evidence должен поступать через `V5TrustedInstalledNativeCoveragePort`; **такого провайдера нет ни в staging, ни в production**, синтетические доказательства не являются основанием для выпуска V5.

`tests/V5NativeMutationCoverageTest.php`: **60/60 PASS**, PHP 8.4.24, синтетический fake PDO и искусственный release attestation; **не live Bitrix E2E**. Новый код и тест побайтово совпадают с GitHub. Ранее готовые 84/88 тестов на auth/phone epochs не заменяют недостающего доказательства штатных writers. Никакие Bitrix hooks или БД не модифицировались. V4 работает как прежде, V5 отвечает 503.

**Новый узкий следующий gate:** по данным уже известного релиза проверить *конкретные native writer transaction boundaries*, включая агент, recovery, телефон и logout; выяснить безопасную общую Bitrix ORM/ PDO транзакционную связку и инвалидацию auth epoch **до commit**. Только после этого staged integration и конкурентные тесты SMS reserve ↔ TOTP enroll/auto-reactivation/phone reconfirm. **Не возвращаться к b_user columns / V4 / TOTP-инвентаризации.** Подробности: `docs/SAMZABERU_V5_NATIVE_MUTATION_COVERAGE_2026-10-12.md`.


### 2026-10-12 — V5 native Bitrix Mysqli connection bridge: офлайн-адаптер готов

Read-only диагностика production **PASS**: `bitrix/.settings.php` дал `CONFIG_CLASS_REFERENCES=MYSQLI`; файлы соединения находятся в `bitrix/modules/main/lib/db/` (регистр путей уточнён). Класс `Bitrix\Main\DB\MysqliConnection`, SHA256 `82877ae53f27a6bc429a8b9cda9709cc86f42cfc14225c4c1ba56a43c3121ec1`; родительские `Connection`/`MysqlCommonConnection` имеют native `startTransaction, commitTransaction, rollbackTransaction`. Штатная ORM обращается к `getConnection()`; прежняя V5 попытка использовать **отдельный PDO** не разделяла эту транзакцию.

В `feature/samzaberu-api-v5-auth-contract` добавлен `src/V5BitrixPdoCompatibilityBridge.php` — **ограниченный PDO-совместимый PHP-фасад**, делегирующий SELECT, INSERT/UPDATE и native COMMIT/ROLLBACK тому же экземпляру `Bitrix\Main\Application::getConnection()`. Бизнес-классы `V5MySqlSmsOutbox`, `V5BitrixLockedSmsEligibility`, `V5SecurityEventVersions` **не переписаны**; их PHP-PDO type hints обслуживаются фасадом, но фактически используется Bitrix Mysqli. Это НЕ настоящий `pdo_mysql` и НЕ server-prepared statements. SQL текстовые параметры проходят `SqlHelper::forSql()`; real Bitrix helper/exception semantics ещё должны быть проверены на staging.

Фасад **не подключён в HTTP** и требует не реализованный на сайте `V5NativeBitrixExclusiveTransactionPermitPort` — доказательство, что у `Application::getConnection()` нет постороннего активного transaction-scope. Отказ/ошибка BEGIN **НЕ вызывает rollback чужой транзакции**. SQL allowlist, запрет широких/разрушительных SQL, native fail-closed на exception и возвращаемый `false` реализованы. Неизвестный результат COMMIT требует staging/manual reconciliation и не является успешной отправкой.

`tests/V5BitrixPdoCompatibilityBridgeTest.php`: **80/80 PASS** на PHP 8.4.24 с fake Bitrix Mysqli/SQL helper. Бюджет и mock-outbox коммитятся и откатываются в одной **искусственной native транзакции**, проверены чужой scope, CAS, false/exception, экранирование/SQL guards. Исходник `84aa6072d8f20a6065518b8059de23c3ad9306f0` и тест `e5607ead2d3fb86b3a59c7e8b1426796d480efe5` побайтово совпадают с GitHub. Реальная ORM+V5 общая транзакция, `SqlHelper` и concurrency ещё **НЕ** подтверждены.

Для точности `V5NativeMutationProof` теперь называет аттестацию `usesSameNativeConnection` (было `usesSamePdoConnection`), поскольку реальный релиз Bitrix — Mysqli. Логика обязательных 10 путей/5 флагов не менялась; native hook installation всё ещё не выполнена.

**Следующая точка:** в isolated staging подтвердить trusted exclusive scope/physical ORM handle/escaping/errors и все 10 путей atomic AUTH_EPOCH через общий b_user mutex; затем real MySQL concurrency и SMS pilot. **production/V4 unchanged, V5 503**. Подробности: `docs/SAMZABERU_V5_BITRIX_TRANSACTION_BRIDGE_2026-10-12.md`. Не исследовать повторно `b_user` или V4.


### 2026-10-12 — двухсторонний native ORM/MySQLi rollback acceptance подготовлен, staging ещё НЕ пройден

В `feature/samzaberu-api-v5-auth-contract` создан `site-integration/samzaberu-v5/tests/integration/V5BitrixNativeOrmTransactionAcceptance.php`. Это **не ещё один fake PDO тест**, а подготовленный исполняемый **staging-only** сценарий проверки настоящего установленного `Bitrix\Main\ORM\Data\DataManager` и `Application::getConnection()` на **одной физической транзакции**. Сначала проверяются идентичность объектов connection, exact имя выделенной БД `evrasia_v5_test_*`, реальный server hostname, `@@session.autocommit=1`, `@@session.in_transaction=0` и наличие заранее созданной **только тестовой** InnoDB таблицы `ev_v5_native_tx_probe`. Production host/root и отсутствие explicit staging acknowledgement блокируются **до Bitrix bootstrap**.

Два теста с одноразовыми синтетическими ключами: (1) запись через native Bitrix ORM → чтение через native MySQLi → ROLLBACK → строки нет; (2) запись через native MySQLi → чтение через Bitrix ORM → ROLLBACK → строки нет. Код не вызывает SMS, V5 HTTP, MFA, JWT, не читает/изменяет `b_user` и не делает CREATE/DROP/DELETE. DDL `schema/PROPOSED_ev_v5_native_tx_probe.sql` — **предложение только для заранее изолированного staging**, нигде не применено. Инструкция: `tests/integration/BITRIX_NATIVE_TRANSACTION_ACCEPTANCE.md`.

**Локальная проверка:** PHP 8.4.24 syntax PASS; запуск без `V5_STAGE_WRITE_ACK` fail-closed (`FINAL_STATUS=FAIL`, DB_WRITE=NO). На **локальном имитаторе** Bitrix обе ветки read-your-writes/ROLLBACK выполнились (`FINAL_STATUS=PASS`, `TESTED_DIRECTIONS=2`, `TEST_ROWS_PERSISTED=0`). При подмене ожидаемого имени DB-сервера сценарий остановился **до тестовых INSERT**. PHP source blob `4263d2131a2bf67769416da347a486b0c37c6817` и DDL blob `87c7f74eb0e085119e7b7b116afd071b6e83f197` совпадают с локально проверенными файлами.

**НЕ выполнялось:** реальный Bitrix/MySQL/MariaDB staging, настоящие ORM rollback acceptance, V5 bridge exclusive permit, 10 native MFA event hooks/`AUTH_EPOCH`, SMS gateway. Даже реальный PASS этой отдельной проверки **не** разрешит включение API V5: это только один из обязательных барьеров. **PRODUCTION_WRITE=NO, V4_UNCHANGED, V5_HTTP=503.**

**Следующий шаг:** запустить этот конкретный тест только в согласованной *физически изолированной* staging-копии Bitrix; затем доказать эксклюзивный native transaction scope и atomic MFA event coverage, не повторяя изучение `b_user`/V4.


### 2026-10-12 — V5 native transaction ownership: staging-only допуск OFFLINE TESTED 62/62

В `feature/samzaberu-api-v5-auth-contract` создан `src/V5StagingNativeTransactionPermit.php` — конкретный **только для изолированного staging/CLI** кандидат ранее отсутствовавшего `V5NativeBitrixExclusiveTransactionPermitPort`. Проверяет: буквальное staging acknowledgement, canonical НЕ-production Bitrix root, hostname приложения, отдельную БД `evrasia_v5_test_*`, известный nonproduction MySQL server, **точное совпадение объекта** `Application::getConnection()`, два read-only снимка `DATABASE()/@@hostname/CONNECTION_ID()/@@session.autocommit/@@session.in_transaction` и независимое подтверждение **нулевой внутренней глубины Bitrix transaction nesting** перед обоими снимками. Любое неизвестное/изменившееся значение → запрет до `BEGIN`. Ни один SQL write/транзакция не вызывается из самого guard.

**ПРИНЦИПИАЛЬНО:** `V5VerifiedNativeTransactionDepthPort` — обязательное независимое доказательство internal Bitrix nesting. **Реальной реализации нет**; нельзя подставлять «всегда ноль» на сайте. SQL-флаг `@@session.in_transaction=0` сам по себе не доказывает отсутствие Bitrix nested transaction bookkeeping. Этот staging guard **не подключён** к HTTP, не может использоваться как production release proof и не заменяет 10-path atomic native MFA events.

`tests/V5StagingNativeTransactionPermitTest.php`: **62/62 PASS**, PHP 8.4.24, полностью fake Bitrix/MySQL + синтетический native depth; проверены active tx, autocommit, изменившийся `CONNECTION_ID`, неверные схема/host/владелец, errors, неизвестная глубина, неверные connection identifiers. GitHub source blob `097a8dbd0e627a7cfbe7eedfcd9c207619b1a992`, test blob `0aa6af73091c68733d0a5dcf5e6c224f2bf03bf4` точно совпадают с локально исполнявшимися файлами. **Настоящий staging ORM↔native rollback E2E всё ещё НЕ запускался**, таблиц не создавали.

Следующий **узкий** шаг: на зафиксированном в production релизе Bitrix исследовать только реализацию `Connection/MysqlCommonConnection` transaction nesting (не повторяя аудит `b_user`/V4/MFA), установить достоверный read-only native depth provider и подтвердить его на isolated staging. Затем запустить уже подготовленный `V5BitrixNativeOrmTransactionAcceptance.php` и выполнить real MySQL rollback/concurrency с ранее написанным SMS-outbox. До этого **V5 HTTP 503, V4 и production не изменены**. Подробности: `docs/SAMZABERU_V5_STAGING_TRANSACTION_PERMIT_2026-10-12.md`.


### 2026-10-12 — новая точка передачи чата: следующий вывод native Bitrix transaction depth

**AUTHORITATIVE NEXT CHAT:** [`docs/SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-12.md`](SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-12.md), одинаково сохранён в `main` и `feature/samzaberu-api-v5-auth-contract`. Если пользователь новым сообщением присылает stdout `=== 3. TRANSACTION NESTING / OWNERSHIP SOURCE TOKENS ===`, начинать с анализа **этого вывода**, не с повторного поиска `b_user`/V4/TOTP.

Последний GitHub-only milestone: `V5StagingNativeTransactionPermit.php`, источник blob `097a8dbd0e627a7cfbe7eedfcd9c207619b1a992` и тест blob `0aa6af73091c68733d0a5dcf5e6c224f2bf03bf4`; **62/62 synthetic PASS**, без реального MySQL. Критическая зависимость `V5VerifiedNativeTransactionDepthPort` **не реализована**, подмена «всегда 0» запрещена. Подготовленный `V5BitrixNativeOrmTransactionAcceptance.php` не выполнялся на реальном staging. `V5NativeMutationCoverage` 10-path gate по-прежнему без native hooks/`AUTH_EPOCH` proof. Транзакционный `V5BitrixPdoCompatibilityBridge` (Bitrix Mysqli, не отдельный PDO) ранее прошёл 80/80 synthetic assertions, но не признан live.

**Следующий согласованный скрипт** сохранён в feature: `site-integration/samzaberu-v5/tests/diagnostics/v5_native_depth_readonly.sh`, Git blob `5718f64f6eb6256645bffd0d526bb19ab0c0eee4`, SHA256 файла `37bb42963c25c72f34430eafb6cc77672d9e550c22d263ce07c1fe3c568265e6`. Read-only static code tokens только у 3 ранее подтверждённых исходников `Connection/MysqlCommonConnection/MysqliConnection`, checksum guards на known production host `evrasia`; PHP/Bash lint PASS, parser synthetic PASS, negative host guard PASS. **Production-run ещё не получен**, его полный stdout будет **первым сообщением нового чата**.

В предыдущих реальных source-only diagnostics пользователя: `FINAL_STATUS=PASS` (8/8, 4/4), конфигурация MYSQLI подтверждена, секреты не читались/не печатались. Новый скрипт не делает SQL connection и не исполняет Bitrix. Даже `FINAL_STATUS=PASS` у него — только статический аудит, **не подтверждение native transaction nesting runtime**. После обработки вывода продолжать проектировать **доверенный native depth proof**, затем isolated staging real ORM ↔ native rollback и 10-path MFA event atomics. `MODE=READ_ONLY/GITHUB_ONLY; PRODUCTION_WRITE=NO; DDL=NO; SMS_SENT=NO; V4_UNCHANGED=YES; V5_HTTP=503`.

## 7. Реально незакрытые интеграционные вопросы (только их исследовать дальше)

1. Проверить **runtime ORM mapping** только если собираемся использовать ORM-источник. После подтверждения физической `b_user` не повторять `information_schema`; SQL-кандидат — отдельный вариант.
2. Подключить `V5CurrentLoginControlsPort` к реальным штатным lockout/event правилам (`LOGIN_ATTEMPTS`, `PASSWORD_EXPIRED`, `OnBeforeUserLogin`) без обхода существующей безопасности.
3. Подключить `V5PerUserSmsEligibilityPort` к фактическим глобальным optional/mandatory MFA правилам и учётом деактивации.
4. Начать с вывода уже выданного `v5_native_depth_readonly.sh` из нового чата, затем проверить native Bitrix `Connection/MysqlCommonConnection` nesting и реализовать **доверенный** `V5VerifiedNativeTransactionDepthPort` (никаких тестовых Always-Zero), затем запустить `V5StagingNativeTransactionPermit` и подготовленный `V5BitrixNativeOrmTransactionAcceptance.php` **только на выделенном staging** после тестовой DDL и сверки DB identity, чтобы доказать единое ORM ↔ native connection и rollback. Далее проверить `V5BitrixPdoCompatibilityBridge`: trusted exclusive transaction permit, реальный Bitrix SqlHelper/affected rows, ошибки и реальный MySQL rollback. Затем устранить **подтверждённый пробел доказательств native Bitrix event atomicity**: все 10 потоков из `V5NativeMutationCoverage.php`, включая direct Otp add/delete и background OtpEvents agent, должны получить подтверждённые same-DB-transaction, b_user mutex, AUTH_EPOCH/CAS/rollback и staging concurrency. Только потом зарегистрировать реальные hooks, доверенный release-attestation, инициализировать общий `ev_samzaberu_auth_state`, выполнить isolated staging challenge/ SMS E2E; не менять production и не пересматривать уже проверенную структуру b_user.
5. Подтвердить strong-enrollment provenance Trusted Device, сделать независимые V5 JWT/refresh/assurance и защищённый PIN.
6. Сделать staging end-to-end; затем отдельный одобренный пилот `USER_ID=880339`. **Никаких production write до отдельного разрешения оператора.**

## 8. Индекс первоисточников и статус продолжения

**Main, постоянные документы:** `docs/PROJECT_CHECKPOINT.md`, `docs/AI_PROJECT_CONTEXT.md`, `docs/NEW_CHAT_HANDOFF.md`, `docs/CURRENT_ARCHITECTURE.md`, `docs/SERVER_SCRIPT_RULES.md`, `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`, `docs/SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md`.

**Feature, будущий V5:** `docs/SAMZABERU_API_V5_AUTH_CONTRACT_2026-10-11.md`, `site-integration/samzaberu-v5/README.md` и `site-integration/samzaberu-v5/{src,tests,api}`.

**Решение:** было «исходник `UserTable.php` не содержит два имени → предположение, что схема не подтверждена» → стало «физическая `b_user` имеет оба столбца (PASS), но ORM-проекция отдельно не доказана» → причина «результат реального read-only `information_schema`». Документ является исходной точкой для нового чата; подробные уже закрытые аудиты повторять запрещено без нового основания.


### 2026-10-13 — native depth: guarded staging preflight и rollback test (GITHUB-ONLY)

После предоставленного оператором **production READ_ONLY / 6/6 PASS** анализа двух pinned исходников `MysqlCommonConnection` / `MysqliConnection` подтверждено: `protected int $transactionLevel = 0`; нулевой `startTransaction()` делает `START TRANSACTION`, вложенный — `SAVEPOINT`; при внутреннем commit уровень уменьшается, физический `COMMIT` только при нуле; вложенный rollback откатывает до savepoint и бросает `TransactionException`. Тест не исполнял нативный Bitrix/SQL и не доказывает live ownership.

В изолированной feature-ветке уже создан `src/V5VerifiedBitrixNativeTransactionDepth.php` (строго source-pinned Reflection-чтение; 18/18 synthetic PASS, не real Bitrix). **Новое продолжение:** `tests/integration/V5BitrixNativeDepthReadOnlyPreflight.php` (Git blob `2fe2effbbaf634059698d4f4206eb55eb923291e`) — реальный будущий staging CLI preflight: проверяет host/root/schema, bootstrap нативного staging Bitrix, pinned depth=0 и существующий `V5StagingNativeTransactionPermit` с двойными read-only снимками MySQL-сессии. Сам скрипт **не вызывает DML/BEGIN/COMMIT/ROLLBACK**, но staging Bitrix bootstrap может иметь побочные эффекты, поэтому только на физически изолированном staging с отдельными DB credentials. Локальный PHP 8.4.24 lint PASS, два отрицательных запуска остановились **до bootstrap/DB**. Реальный положительный результат отсутствует.

Подготовленный ранее `tests/integration/V5BitrixNativeOrmTransactionAcceptance.php` **защищён**: перед каждым `BEGIN` требует pinned native depth 0 + positive staging permit + стабильную идентичность MySQL-сессии; после BEGIN — глубина 1 и SQL in_transaction=1, после rollback — глубина 0 и SQL idle. При ошибке automatic rollback только после повторного независимого proof владения; иначе ручная проверка staging. Новый source Git blob `eb0e5f0ad6326eb45abb09b98964781fd0ba670c`. Вспомогательный session-state helper прошёл **14 синтетических локальных проверок**, но **полная новая acceptance-редакция и реальный staging E2E пока не запускались**; прежний fake smoke относился к старой редакции.

Документы: `docs/SAMZABERU_V5_NATIVE_DEPTH_READER_2026-10-13.md` и `site-integration/samzaberu-v5/tests/integration/BITRIX_NATIVE_TRANSACTION_ACCEPTANCE.md`. Следующий минимальный реальный шаг — независимая подготовка/подтверждение изолированного staging, там запуск read-only preflight (не на production); только после PASS и отдельного разрешения — синтетический rollback тест. Все 10 native MFA/security `AUTH_EPOCH` путей остаются отдельно не доказаны. `PRODUCTION_WRITE=NO`, `V4_UNCHANGED=YES`, `V5_HTTP=503` (по последнему зафиксированному состоянию), `REAL_STAGING_E2E=NOT_RUN`.


### 2026-10-13 — MFA native ORM source ordering: operator source-verified 9/9

На production `evrasia` оператор выполнил **строго READ_ONLY** `site-integration/samzaberu-v5/tests/diagnostics/v5_native_mfa_writer_order_readonly.sh`: `PASS_COUNT=9`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, все 6 ранее известных SHA-256 исходников совпали. Не исполнялся Bitrix, не открывалось соединение с БД, не читались пользовательские данные, не менялся production.

Фактические исходники: `Otp::save()` при `regenerated` вызывает `RecoveryCodesTable::clearByUser` (строка 370) **перед** `UserTable::add` (374); `Otp::delete()` — `UserTable::delete` (390); `OtpEvents::onRecheckDeactivate()` сам пишет `UserTable::update` (30/34). `CSecurityUser::update()` вызывает OTP deactivate/delete/regenerate/save из разных веток; `onAfterUserUpdate()` содержит только MFA cache invalidation, не AUTH_EPOCH CAS.

В `DataManager` `sysAddInternal()` before/add callbacks — строки 906/939, native strategy add — 956, after callback — 1015. `update()`: before 1277/1306, SQL `queryExecute` 1337, after 1362. `delete()`: before 1665/1674, SQL 1691, after 1702. Это **SOURCE-VERIFIED порядок** синхронных вызовов, **НЕ подтверждение** общей транзакции. Ни OnBefore без доказанного enclosing transaction, ни OnAfter после возможного autocommit не доказывают атомарность MFA + AUTH_EPOCH. Фоновый агент и очистка recovery — самостоятельные критические ветки.

По состоянию 2026-10-13 **GITHUB-ONLY / НЕ ЗАПУЩЕН** следующий узкий source-only аудит `tests/diagnostics/v5_native_recovery_merge_readonly.sh` (установить реализацию `RecoveryCodesTable::clearByUser` и `MergeByDefaultTrait`, новые SHA, без DB/bootstrap). CI GitHub Actions [38377164870](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38377164870) **SUCCESS**: обе диагностики Bash lint, 48 PHP lint, 22 автономных offline suite PASS. Следующий шаг — анализ его вывода; real isolated Bitrix staging **не подтверждён**, V5 503, V4 и production нетронуты.


### 2026-10-13 — RecoveryCodesTable cleanup / MergeByDefaultTrait source-verified 6/6

**Второй принятый read-only вывод оператора** с production `evrasia`: `v5_native_recovery_merge_readonly.sh`, `PASS_COUNT=6`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, все pinned SHA совпали, `MODE=READ_ONLY`, без Bitrix bootstrap/БД/изменений/секретов.

Подтверждённо: `Bitrix\Security\Mfa\UserTable` (`b_sec_user`) `use MergeByDefaultTrait`; trait SHA `30153b42a1a652b44ca70f2bb14335d08f970d0ebef8ca85e7f349f8ad29c198`, `getAddStrategy() -> self::getCachedMergeStrategy()`, через `BaseMergeTrait` (содержимое Base пока не изучалось). `RecoveryCodesTable.php` SHA `a5ff49a123bd7f3864acfc1fee9010965a8d9d3775d774442de66d5667d5e357`, `clearByUser` (84–101): SELECT ID кодов пользователя → многократные `static::delete(ID)` → return true **без проверки успешности каждой ORM delete**. `regenerateCodes` (фрагмент 111–129): clear старых → много отдельных `static::add` новых; остаток метода ещё не проверен. В методе `clearByUser` собственного явного transaction begin нет; внешний tx scope **не исключён и не подтверждён**.

Вместе с предыдущим подтверждённым `Otp::save: clearByUser(370) -> UserTable::add(374)` это доказывает, что `UserTable::OnBeforeAdd` **слишком поздно**, чтобы самостоятельно заключить очистку recovery и новый TOTP в одну transaction. Нужен owner transaction scope до всей операции, общая блокировка `b_user`, epoch CAS и совместный rollback, подтверждённые real isolated staging. Это отрицательное ограничение архитектуры, а не факт уже произошедшей потери recovery.

**Следующий только source-only шаг** (в feature, подготовлен, на production ещё не запускался): `tests/diagnostics/v5_native_recovery_final_boundary_readonly.sh`. Смотрит узко `UserTable` delete callbacks, конец `regenerateCodes` и `BaseMergeTrait::getCachedMergeStrategy`, не повторяя прежние OTP/ORM сканы. GitHub CI [38378187055](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38378187055) PASS: Bash syntax всех 3 аудит-скриптов, 48 PHP files lint, 22 offline suites. Реальный staging Bitrix/MySQL не подтверждён. `PRODUCTION_WRITE=NO`, `NATIVE_MFA_ATOMICITY_PROVEN=NO`, `V5_HTTP=503` по последнему baseline.


### 2026-10-13 — Native MFA recovery last source boundary VERIFIED 7/7; source audits complete

Третий фактический operator READ_ONLY аудит `v5_native_recovery_final_boundary_readonly.sh` на `evrasia` завершился `PASS_COUNT=7`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`; исходные `UserTable.php`, `RecoveryCodesTable.php`, `MergeByDefaultTrait.php` совпали с pinned SHA. Не загружал Bitrix, не открывал SQL, не изменял production.

**Новые точно подтверждённые факты нативного исходника:**

- `UserTable::onAfterDelete` (101–105) вызывает `RecoveryCodesTable::clearByUser(USER_ID)` **после** SQL удаления MFA; это лишь event order, а не proof общих COMMIT/ROLLBACK.
- `RecoveryCodesTable::regenerateCodes` делает цикл отдельных `static::add($code)` и возвращает `true` без проверки каждого `Result`.
- `RecoveryCodesTable::useCode` (143–172) читает `USED=N`, вызывает `static::update(ID, USED=Y)`, не проверяет update Result, устанавливает `found=true`. Нет видимого атомарного CAS `WHERE USED=N`, блокировки `b_user` или transaction ownership. Следовательно, **однократное успешное использование при гонке не доказано**; двойной успех потенциальен, но на production не тестировался и не утверждается.
- Новый verified SHA `BaseMergeTrait.php = 18deb72c645694c6378662056834f5c3dd0e409d8d3c271e4f49fd910c9ce5b8`. `getCachedMergeStrategy()` кэширует `new Merge(static::getEntity())`; trait не владеет транзакцией.

**Архитектурное ограничение:** transaction scope/owner должен начинаться **до первой native MFA/recovery записи**, lock `b_user` первым, native ORM и epoch CAS выполняются на одном контролируемом Bitrix Mysqli connection, все Result/postconditions проверены и rollback доказан. ORM `UserTable::OnBeforeAdd` слишком поздний для preceding `clearByUser`, `OnAfterDelete` тоже поздний для предыдущего MFA SQL. Нельзя принять `useCode() === true` за assured recovery MFA доказательство. V5 не активировать до полного 10-path MFA/security atomicity proof.

**Код:** в рабочей feature-ветке только расширен отрицательный synthetic `tests/V5NativeMutationCoverageTest.php`: 22 дополнительных проверок для delete/recovery → общий suite **82/82 PASS**. Существующий 10-path release gate не изменён. Проверенный [GitHub CI run 38381811549](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38381811549) SUCCESS, 22/22 offline suites, 48 PHP lint, три Bash syntax check. Подробный [дизайн MFA recovery атомарности](SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md) сохранён в обоих branch docs. Актуальный [checkpoint](SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md) заменяет более раннюю задачу повторного native source audit.

**ВСЕ ТРИ read-only источниковедческих шага этой цепочки ЗАКРЫТЫ: 9/9, 6/6, 7/7.** Не повторять. Следующий барьер — самостоятельно изолированный Bitrix/MySQL staging (пока `STAGING_ISOLATION_ATTESTED=NO`), затем реальный native shared-connection rollback, MFA/recovery fault-injection и конкурентный single-use consume. Никаких новых production SQL, хуков, миграций или SMS; `V4_UNCHANGED=YES`, `V5_HTTP=503` по последней документации.


### 2026-10-13 — Native phone auth source-only inventory accepted 5/5

Оператор фактически выполнил на host `evrasia` только read-only `v5_phone_event_writer_inventory_readonly.sh` с `PASS_COUNT=5`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. `BITRIX_BOOTSTRAP=NO`, `DATABASE_CONNECTION=NO`, `DATABASE_WRITE=NO`, `PRODUCTION_APPLICATION_WRITE=NO`, `USER_DATA_READ=NO`, `CONFIG_FILES_READ=NO`, `SECRETS_PRINTED=NO`, `SMS_SENT=NO`. Оба **ранее pinned** исходника V4 совпали с SHA.

**Новый точно проверенный native source:** `bitrix/modules/main/lib/UserPhoneAuthTable.php`, SHA-256 `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991`. Схема: `getTableName` 34, `getMap` 39, `CONFIRMED` reference 64. Native mutator-related methods: `validatePhoneNumber` 83, `onBeforeAdd` 96, `onBeforeUpdate` 101, `modifyFields` 106, `normalizePhoneNumber` 123. Наличие `onBefore*` само по себе **НЕ** означает общую транзакцию, и не подтверждает `PHONE_EVENT_VERSION`.

**Верифицирована только карта V4 call-site имен, не их реализация/срабатывание:** `local/php_interface/Controllers/V4/Auth.php` прежний SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` — `signup` 263, `confirm_request` 779, `confirm` 954, `forgot_password` 551, `change_password` 680; `UserPhoneAuthTable` source markers на 372/869/876/880, `CONFIRMED` на 845/873/891/896/1012. `local/php_interface/lib/PhoneAuth.php` SHA `a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27`, методы `sendCode` 65, `check` 135, `createUser` 188 (в первом мета-аудите `UserPhoneAuthTable` и `CONFIRMED` references нет).

**Не доказаны:** где реально вызывается native `UserPhoneAuthTable::add/update/delete`; создаётся ли отдельное событие **повторного** `CONFIRMED=Y` для того же номера; взаимодействие с `PHONE_EVENT_VERSION` и общий DB scope. Нельзя считать совпадение HMAC от прежнего E.164 доказательством повторной phone verification.

**Следующий узкий, только source-only шаг:** новый `tests/diagnostics/v5_phone_confirmation_order_readonly.sh` в feature (проверены SHA всех 3 источников, без печати оригинальных строк/значений, выдаёт лишь call tags и номера строк в `signup`, `confirm_request`, `confirm` плюс native `UserPhoneAuthTable` callback). GitHub Actions [38392077308](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38392077308) SUCCESS: Bash -n пяти diagnostic files, PHP lint 48 files, 22 offline suites, pre-bootstrap staging guards. На production **этот второй телефонный скрипт ещё не запускался**.

Все три MFA/recovery read-only аудита **9/9, 6/6, 7/7** завершены и не требуют повторного исследования; real staging Bitrix/MySQL **не подтверждён**. `NATIVE_PHONE_EVENT_ATOMICITY_PROVEN=NO`; `PRODUCTION_WRITE=NO`; `V5_HTTP=503` по последнему документированному состоянию.


### 2026-10-13 — V4 phone confirmation source call-order operator 6/6 PASS

Оператор предоставил фактический stdout `v5_phone_confirmation_order_readonly.sh` с `evrasia`: `PASS_COUNT=6`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. Пинованные SHA **трёх** источников совпали: V4 `PhoneAuth.php` `a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27`, V4 `Controllers/V4/Auth.php` `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`, native `UserPhoneAuthTable.php` `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991`. Работа строго **READ_ONLY**, без Bitrix bootstrap, SQL, SMS, пользовательских/секретных значений и изменений production.

**Новый установленный порядок статических call-site refs:**

- `Auth::signup` (`Auth.php` начало 263): phone-confirm helper refs в строках **311, 334, 339**; `UserPhoneAuthTable::add` **372**.
- `Auth::confirm_request` (779): `CONFIRMED` refs **845, 873, 891, 896**; `UserPhoneAuthTable::getList` **869**, `::add` **876**, `::getRowById` **880**.
- `Auth::confirm` (954): `CONFIRMED` ref **1012**, прямой `UserPhoneAuthTable::add/update/delete` в узком marker-скане этого метода **не выявлен**.
- Native `UserPhoneAuthTable::onBeforeAdd` **96** → `modifyFields` **98**; `::onBeforeUpdate` **101** → `modifyFields` **103**; `modifyFields` нормализует `PHONE_NUMBER` (112/115). Мета-аудит не обнаружил явного write `PHONE_EVENT_VERSION` или transaction-owner method в этих callback ranges.
- `V4_PHONE_TABLE_STATIC_CALLS=4`, `V4_CONFIRMED_REFERENCES=5`, `NATIVE_CALLBACK_METADATA_MARKERS=12`; `CONFIRMED_Y_ASSIGNMENT_CANDIDATE` **не показан**. Это **узкое regex-ограничение**, а не доказательство отсутствия `CONFIRMED=Y` в динамических/многострочных выражениях или других helper calls.

**Не доказаны:** реальная точка линейного подтверждения `CONFIRMED=N→Y`, что происходит при повторном подтверждении *того же E.164*, появляется ли в исходниках косвенный `->update/->save/->set`, гарантирован ли отдельный `PHONE_EVENT_VERSION` bump, общий native transaction/epoch CAS. Ни имя метода `confirm`, ни уже подтверждённая строка телефона не позволяют выдавать новый сильный фактор.

**Следующий узкий шаг (GITHUB ONLY, еще не run на production):**
`site-integration/samzaberu-v5/tests/diagnostics/v5_phone_confirmation_transition_flow_readonly.sh`. Точно пинует два подтверждённых V4 исходника; выводит **только безопасные идентификаторы методов, классификации условий/return, строковые номера** для `confirmation / signup / confirm_request / confirm` и `PhoneAuth::sendCode/check`. Никаких исходных строк/значений, SQL или Bitrix bootstrap. [GitHub CI run 38393921083](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38393921083) **SUCCESS**: 6 Bash syntax checks, 48 PHP lint, 22 offline suite, неправильный host отсекается до чтения. Этот run проверяет **синтаксис и защитный отказ**, не положительный анализ production-исходника.

**Граница проекта неизменна:** MFA source-chain 9/9 + 6/6 + 7/7 закрыта; phone inventory 5/5 и call-order 6/6 завершены; ни V4, ни production, ни БД не изменены. Пока нет физически изолированного Bitrix/MySQL staging и full 10-path hook coverage, `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, `NATIVE_PHONE_EVENT_ATOMICITY_PROVEN=NO`, `V5_HTTP=503` (последний документированный baseline).

### 2026-10-13 — V4 phone confirmation indirect update source flow 5/5

**Фактический операторский вывод:** `v5_phone_confirmation_transition_flow_readonly.sh` на production `evrasia`: `PASS_COUNT=5`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. Два pinned V4 source SHA совпали (`Auth.php=72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`, `PhoneAuth.php=a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27`). Исключительно **SOURCE ONLY**, без PHP/Bitrix bootstrap, SQL, SMS, пользователей, конфигурации и production-записей; оригинальные строки PHP не печатались.

**Точное новое наблюдение:** `Auth::confirmation` (method 60) имеет `getList` 96, `fetch` 100, **`->update` 137**. `Auth::confirm_request` (779) содержит `CONFIRMED` reference 845, **`->update` 851**, затем native `UserPhoneAuthTable::getList` 869, `::add` 876, `::getRowById` 880, ещё `CONFIRMED` references 873/891/896. `Auth::confirm` (954) содержит `CONFIRMED` reference 1012 и **`->update` 1026**. СИНТАКСИЧЕСКИЙ `->update` **не определяет владельца таблицы**: ни обновление `b_user_phone_auth.CONFIRMED=Y`, ни повторная телефонная верификация на основании этих меток ещё не доказаны.

`PhoneAuth::sendCode` (65–123) вызывает `sendSms` на 115, `PhoneAuth::check` (135–179) содержит проверку токенов (`createTokens` 162) и ветвления без явных native phone-table writes в этом ограниченном скане. Это не доказательство успешной SMS-доставки и не исключение опосредованной записи.

**Следующий только read-only сценарий** (GITHUB-ONLY, на production ещё НЕ запускался): `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_update_receiver_readonly.sh`. Жёстко проверяет SHA текущего `Auth.php` и строки 137/851/1026, выводит только source identifiers receiver-переменных, whitelisted reference tags полей `CONFIRMED/PHONE_NUMBER/ACTIVE/USER_ID`, basic provenance и control-flow line numbers; нет исходного кода или значений. CI GitHub Actions [38396960091](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38396960091) **SUCCESS**: 7 Bash -n, 48 PHP lint, 22 offline suite, wrong-host denial новой диагностики. CI не доказывает положительный запуск на Bitrix.

Все прежние MFA source-only аудиты 9/9, 6/6, 7/7 и телефонные инвентаризации 5/5, 6/6, новый source-flow 5/5 уже приняты — **не повторять**. Отдельный Bitrix+MySQL staging не подтверждён, `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, same E.164 reconfirm bump и native ORM/epoch atomicity не доказаны, V5 HTTP 503 по последнему документированному baseline; V4/production не изменялись.

### 2026-10-13 — V4 phone writer update receivers 4/4 PASS, CONFIRMED array assignment

Оператор выполнил на `evrasia` `v5_phone_update_receiver_readonly.sh`. `FINAL_STATUS=PASS`, `PASS_COUNT=4`, `FAIL_COUNT=0`, `FINAL_RC=0`, точный SHA V4 `Auth.php = 72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` совпал. Это **статический SOURCE-ONLY аудит**: Bitrix не загружен, нет SQL, SMS, секретов/PII, исходные строки PHP не выводились и production не изменялся.

**Три статически подтверждённых update receiver:** `Auth::confirmation` — `new CUser` (стр. 136) рядом с `$obuser->update` (**137**); `Auth::confirm_request` — `new CUser` (835), `$obuser->update` (**851**); `Auth::confirm` — `new CUser` (1025), `$obuser->update` (**1026**). У всех трёх рядом ссылки на `ACTIVE` — соответственно **138, 852, 1027**. Эти вызовы **не являются прямыми `UserPhoneAuthTable::update()`**. По данным мета-аудита нельзя утверждать тип всех аргументов и факт записи phone confirmation.

**Новый важный source-факт:** `Auth::confirm_request()` строка **891** — `FIELD_CONFIRMED_SUBSCRIPT_WRITE`, запись в элемент PHP массива `['CONFIRMED']`; далее `CONFIRMED` reference **896**. Native phone table `::getList` **869**, `::add` **876**, `::getRowById` **880**, но факт сохранения изменённого массива, успешного `CONFIRMED=N→Y` и отдельного события повторной верификации того же E.164 **НЕ доказан**. В `Auth::confirm()` есть `CONFIRMED` reference **1012**, но три найденных instance update вызываются на `$obuser`, не непосредственно на phone table.

**Следующий подготовленный ТОЛЬКО read-only source шаг**: `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_confirmed_array_lineage_readonly.sh` (feature GitHub-only, на production ещё **НЕ запускался**). Pinned SHA V4 `Auth.php`, строго проверяет source anchors 869/876/880/891/1012, печатает только имя receiver массива `CONFIRMED`, классы/методы из whitelist и символические метки дальнейших использований до 938 и в confirm 992–1034. Никаких source values/строк, SQL/Bitrix/SMS, пользовательских данных. На `FAIL` не продолжать изменения.

Проверенный CI [38398655750](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38398655750) SUCCESS (PHP lint 48, offline suites 22, Bash lint 8, wrong-host deny нового скрипта). Это ещё **не положительный run скрипта на production**. Ранее завершённые MFA read-only audits 9/9+6/6+7/7 и phone audits 5/5+6/6+5/5+4/4 повторять не нужно. `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, native reconfirm+epoch transaction и десять writer coverage pathways не доказаны, staging Bitrix+MySQL не аттестован, V5 HTTP 503 по последнему документированному baseline. Production/V4 не менялись.

### 2026-10-13 — Corrected phone array lineage AWK parser (operator initial FAIL; CI positive PASS)

Оператор на `evrasia` запустил последний read-only `v5_phone_confirmed_array_lineage_readonly.sh`. **ФАКТ: `FINAL_STATUS=FAIL`, `PASS_COUNT=2`, `FAIL_COUNT=1`, `FINAL_RC=1`**. Host/root и exact SHA `local/php_interface/Controllers/V4/Auth.php` PASS; AWK parser отказал до инспекции: `awk: cmd. line:79: emit(`, `unexpected newline or end of string` — предыдущий copy-paste код ChatGPT содержал перенос строки **сразу после** `emit(`, что не соответствует синтаксису установленного awk. Ошибка только в диагностике; `MODE=READ_ONLY`, Bitrix, DB, SMS, production writes, пользовательские данные и конфигурация не затрагивались. `CONFIRMED_ARRAY_VARIABLE` всё ещё **UNKNOWN**, никаких новых выводов о `CONFIRMED=Y`/reconfirm не делали.

**Минимальное исправление в feature:** `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_confirmed_array_lineage.awk` — самостоятельный AWK parser; исходный wrapper `v5_phone_confirmed_array_lineage_readonly.sh` вызывает `awk -f`. Добавлен **реальный положительный parser regression** `V5PhoneConfirmedArrayLineageAwkSelfTest.sh` (полностью синтетические 1040 строк) и два отрицательных parser examples (изменено поле 891 / method 869). На [GitHub Actions 38425322406](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38425322406) `success`: POSIX AWK parser **действительно выполнялся** на синтетических данных, обе негативные версии отсеяны, Bash lint 8 audit scripts+test, PHP lint 48, 22 offline suites, wrong-host denial. Старый CI не проверял AWK парсер по положительному fixture — исправлено. CI не считывал настоящих prod PHP.

**Следующее одно действие оператора:** скорректированный source-only copy-paste wrapper создаёт временный `.awk` через heredoc и вызывает `awk -f` на SHA-pinned V4 `Auth.php` без оригинальных строк/значений в выводе; должен сообщить `SOURCE_ANCHORS_VALID`, `CONFIRMED_ARRAY_VARIABLE`, `CONFIRMED_RECEIVER_REUSES_AFTER_891`, `FINAL_STATUS`. При `FAIL` остановиться, не начинать другие исследования. Три MFA source checks и четыре ранее успешно закрытые phone source checks повторять не нужно. Native `PHONE_EVENT_VERSION` / same E.164 reconfirm / 10-path atomicity по-прежнему `NOT_PROVEN`. Изолированный Bitrix/MySQL staging не аттестован. V5 HTTP 503 — последний документированный baseline.

### 2026-10-13 — Phone field array lineage 4/4 production PASS; semantic source-only step prepared

Оператор на production host `evrasia` выполнил исправленный `awk -f` V4 source-only `v5_phone_confirmed_array_lineage_readonly.sh`. Факт: `SOURCE_ANCHORS_VALID=YES`, `PASS_COUNT=4`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, pinned `Controllers/V4/Auth.php` SHA совпал. **Ни Bitrix bootstrap, ни DB, ни SMS, ни application writes, ни PII/конфигурации**. Этот успех заменяет предыдущий **только parser syntax** FAIL того же source-only шага; прежние аудиты не повторять.

**Новое установленное:** в `Auth::confirm_request` строка 891 **`$phoneuser['CONFIRMED']`** присваивается в PHP-массиве, после строки 891 в ограниченном проверенном интервале 892–938 **0 прямых текстовых повторов `$phoneuser`**. Статические `UserPhoneAuthTable::getList` 869, `::add` 876, `::getRowById` 880 расположены до PHP-присвоения. `Auth::confirm()` 1012 ссылается на `CONFIRMED`, но owner ссылки не распознан. Прямого native phone ORM persistence/COMMIT этого поля и `PHONE_EVENT_VERSION` во всём Bitrix ни одна текущая source-only диагностика не доказала; `0` лексических использований нельзя приравнивать к доказательству невозможности косвенного изменения.

**Следующий без изменений production:** в feature добавлены только `tests/diagnostics/v5_phone_confirmation_semantic_boundary.awk`, guarded `v5_phone_confirmation_semantic_boundary_readonly.sh` и synthetic-only `V5PhoneConfirmationSemanticBoundarySelfTest.sh`. Классифицируют RHS `CONFIRMED` присвоения на строке 891 как N/Y/OTHER и ссылку на 1012, а также отмечают native phone-table mutator calls в ограниченном методном диапазоне; это уточнит фактический request/confirm контракт. До source-only запуска пользователем на `evrasia` результат **неизвестен**. GitHub Actions [38434850663](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38434850663) SUCCESS: AWK parser N/Y positives, tampered anchor negatives, wrong-host denial, 48 PHP lint PASS, 22 offline suites PASS. CI не читал production PHP.

Общий release gate остаётся **fail closed**: `NATIVE_PHONE_EVENT_ATOMICITY_PROVEN=NO`, `SAME_PHONE_RECONFIRM_ATOMICITY=NOT_PROVEN`, `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, isolated Bitrix staging не подтверждён, V5 HTTP 503 по последней документации. Production/V4 не менялись.

### 2026-10-13 — Native phone request and confirm semantic source 4/4 PASS

Оператор запустил на `evrasia` source-only `v5_phone_confirmation_semantic_boundary_readonly.sh` из предыдущего шага: `PASS_COUNT=4`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `SOURCE_ANCHORS_VALID=YES`, pinned `Auth.php` SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` совпал. **Никаких production V4/DB/SMS writes и Bitrix bootstrap не было.**

Проверено **только статически**: `Auth::confirm_request` line 891 `$phoneuser['CONFIRMED']` содержит `OTHER_OR_MULTILINE` — значение не равно простому `'Y'/'N'` сразу после `=`, точный RHS ещё неизвестен. На 880 `ROW_READ_ASSIGNED_TO_PHONEUSER_SAME_LINE=NOT_PROVEN`. В `Auth::confirm` line 1012 `CONFIRM_1012_FIELD_KIND=OTHER_REFERENCE`, `CONFIRM_1012_HAS_Y_LITERAL=NO`, `CONFIRM_1012_HAS_N_LITERAL=NO`. После 891 до 938 прямых `$phoneuser` refs = 0 и прямых `UserPhoneAuthTable` mutator calls = 0; в confirm прямых native phone mutator calls = 0. При этом `CUser` instance `Update` 1026 существует. Это ограниченный static result, не доказательство отсутствия подтверждения телефона в других слоях Bitrix.

**Следующая готовая диагностика только в GitHub feature:** `tests/diagnostics/V5PhoneConfirmedExpressionTokens.php` и guarded `v5_phone_confirmed_expression_tokens_readonly.sh`. PHP built-in `token_get_all` с `php -n`, без PHP/Bitrix include/eval, строго pinned SHA исходника, 16 whitelisted line positions, output только fixed token types/whitelisted `CONFIRMED`, `Y/N`, `$phoneuser`, literal/variable/unknown identifier значения всегда маскируются. Полный позитивный + два негативных synthetic parser executions и `wrong-host` guards [GitHub Actions 38436298620](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38436298620) SUCCESS; 22 approved offline suites PASS и PHP lint PASS. **На `evrasia` новый токенизатор ещё не запускался**.

Для release V5 нужно доказать native phone verification event и **same E.164 reconfirm** bump в той же physical Bitrix/MySQL transaction с `PHONE_EVENT_VERSION`, `AUTH_EPOCH`, и 10 native write paths. Эти контракты ещё `NOT_PROVEN`, независимого Bitrix/MySQL staging нет; `V5_HTTP=503` только по последнему документированному baseline. MFA/recovery audits и все прежние phone inventories закрыты, повторять нельзя.

### 2026-10-14 — Production PHP tokenizer preflight failed (2/3), isolated module fallback planned

Сервер `evrasia`, фактический source-only операторский `v5_phone_confirmed_expression_tokens_readonly.sh`: **`PASS_COUNT=2`, `FAIL_COUNT=1`, `FINAL_STATUS=FAIL`, `FINAL_RC=1`**. Пройдены host/root guard, SHA `Auth.php` и синтаксис token parser PHP. `php -n -r 'function_exists("token_get_all")'` вернул отсутствие функции, поэтому parser **не исполнялся**, результаты `SOURCE_LINE_891_TOKEN_SHAPE` и `SOURCE_LINE_1012_TOKEN_SHAPE` **НЕ ПОСТУПАЛИ**. Это настройка PHP CLI, не ошибка приложения. В stdout: `BITRIX_BOOTSTRAP=NO`, `DATABASE_CONNECTION=NO`, `DATABASE_WRITE=NO`, `PRODUCTION_APPLICATION_WRITE=NO`, `SMS_SENT=NO`, `USER_DATA_READ=NO`.

В рабочей feature внесено **только** обновление `tests/diagnostics/v5_phone_confirmed_expression_tokens_readonly.sh`: если в `php -n` tokenizer не встроен, безопасно проверяется стандартный `extension_dir` PHP при `-n`, whitelist строго `/usr/lib/php/*/tokenizer.so`, regular non-symlink file, `realpath`, root ownership, и **только** этот модуль подгружается через `php -n -d extension=<absolute path>`, с последующим пробным `function_exists`. Никаких php.ini/default plugin loading или production changes. Если модуль отсутствует — Fail closed и следующий разбор по фактическому выводу. Само наличие этой библиотеки в установленной production PHP среде **не проверено**. Синтаксис и offline fake PHP parser tests на GitHub [38525901881](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38525901881) SUCCESS, но там `php:8.4` имеет иной набор встроенных модулей и не является E2E shared PHP module proof на хосте.

Сохранять предыдущие завершённые source-only результаты без повторов: MFA 9/9 + 6/6 + 7/7; phone 5/5 + 6/6 + 5/5 + 4/4 + 4/4 + 4/4. `CONFIRMED` RHS на строке 891 `OTHER_OR_MULTILINE`, expression `confirm` 1012 `OTHER_REFERENCE`; значения не классифицированы. В V4/production/БД/SMS ничего не изменено, отдельный Bitrix/MySQL staging не аттестован, `V5_HTTP=503` — последний документированный baseline, `PHONE_EVENT_VERSION` native writer / same E.164 reconfirm CAS `NOT_PROVEN`.

### 2026-10-14 — PHP tokenizer loaded on production 4/4; 16 comment masks invalidate semantic claims

Оператор прислал последовательно старую **2/3 FAIL** (`token_get_all()` absent with `php -n`) и исправленную **4/4 PASS** read-only PHP lexer диагностики на production `evrasia`: `TOKENIZER_LOAD_MODE=EXPLICIT_ROOT_OWNED_TOKENIZER_SO`, `PHP_TOKENIZER_EXECUTED=YES`, pinned SHA `Auth.php` совпал, `PHP_INI_LOADED=NO`; Bitrix/DB/SMS/production writes/PII отсутствуют. Наличие и загрузка root-owned системного `tokenizer.so` теперь **PRODUCTION_VERIFIED**.

Но **16/16** выбранных строк старого парсера получили `TOKEN_SHAPE=COMMENT_MASKED`, потому что старый анализатор токенизировал **каждую** строку заново с подставным `<?php`. Не доказано, что `$phoneuser['CONFIRMED']` на строке 891 или reference `CONFIRMED` на 1012 представляют активный исполняемый PHP-код: возможно, участки закомментированы, возможно, искажён lexical context. Даже раньше принятые static regex proofs гарантируют лишь наличие *текста* в SHA-pinned файле, а не live event. Ни физический DB write, ни same E.164 reconfirm/`PHONE_EVENT_VERSION` bump/epoch CAS ещё не доказаны.

**Новый защищённый source-only шаг в feature** (на production **ещё не запускался**): переработан тот же `tests/diagnostics/V5PhoneConfirmedExpressionTokens.php` — токенизация **полного исходного файла в реальном лексическом контексте** и проверка токена на точных byte offsets якорей 891/1012; выпускает `SOURCE_ANCHOR_*_LEXICAL_KIND=PHP_CODE|COMMENT|INLINE_HTML`, `BOTH_SOURCE_ANCHORS_ACTIVE_PHP`, 16 `LEXICAL_CLASS`, 16 маскированных `TOKEN_SHAPE`. Wrapper для system tokenizer сохранился. [GitHub Actions 38528408976](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38528408976) SUCCESS: фальшивый live PHP, изменённые anchors, `//` комментарии, spanning `/*...*/`, смешанный PHP/inline comment, 22/22 offline suites PASS. **CI не является доказательством состояния реального `Auth.php`.**

Точка продолжения: дождаться только stdin/stdout **новой whole-file static tokenizer диагностики** от оператора (одним guarded copy-paste блоком, никаких изменений сайта). MFA/recovery 9/9+6/6+7/7 и все старые телефонные source audits не повторять. Изолированного Bitrix/MySQL staging нет; V5 HTTP 503 — последний документированный baseline, V4/prod unchanged.

### 2026-10-14 — Production PHP lexical truth: phone confirmation V4 anchors 891/1012 are comments

**Фактический результат оператора на production `evrasia`:** `v5_phone_confirmed_expression_tokens_readonly.sh` whole-file variant, exact pinned `Controllers/V4/Auth.php` SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`, `PASS_COUNT=4`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, trusted system tokenizer `EXPLICIT_ROOT_OWNED_TOKENIZER_SO`. Без Bitrix bootstrap, php.ini, SQL, SMS, PII, production application writes.

**Новая истина > старые предположения:** `SOURCE_ANCHOR_891_LEXICAL_KIND=COMMENT`, `SOURCE_ANCHOR_1012_LEXICAL_KIND=COMMENT`, `BOTH_SOURCE_ANCHORS_ACTIVE_PHP=NO`. Строки **880, 889–896, 1009–1014** `LEXICAL_CLASS=COMMENT_ONLY`, `TOKEN_SHAPE=NONE`. Строка 1015 `NO_ACTIVE_TOKENS`. Следовательно, предполагаемая запись `$phoneuser['CONFIRMED']` 891 и проверка `CONFIRMED` 1012 — **точно не исполняемые в PHP комментарии**. Строка 880 — тоже комментарий. Остальные ранее найденные строки 851, 869, 876 и 1026 по отдельности **не были классифицированы токенизатором**, поэтому пока не доказаны ни активность, ни закомментированность; их нельзя использовать как подтверждённый active phone path. Ранее source-regex audits действительны лишь как инвентаризация буквального текста; первоначальная интерпретация «обнаружен активный writer» **отменена**. По другим участкам `Auth.php` и `PhoneAuth.php` вывод не делался. Это не инцидент Bitrix, не сбой работающего V4.

**Следующий только source-only шаг (GITHUB-ONLY, production НЕ запускался):** подготовлены `site-integration/samzaberu-v5/tests/diagnostics/V5ActiveV4PhoneLexicalMap.php` и guarded `v5_active_v4_phone_lexical_map_readonly.sh`. `token_get_all` полного двух pinned V4 исходников, строго отдельные `ACTIVE_METHOD`, `COMMENTED_METHOD_TEXT`, `ACTIVE_NATIVE_PHONE_STATIC_CALL`, `ACTIVE_CONFIRMED_FIELD_REFERENCE` и счётчики. Никаких PHP statements, caller data, Bitrix/bootstrap/SQL. `Auth.php` SHA выше, `PhoneAuth.php` pinned SHA `a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27`. При mismatch fail-closed.

**CI** [38544913782](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38544913782) `completed/success`: реальный PHP tokenizer на синтетическом активном `Auth::confirm`/закомментированном `confirm_request`, `UserPhoneAuthTable` и `CONFIRMED` inside multi-line comment negative, arbitrary identifiers/string canary not disclosed, guard wrong host denied. Все 22 approved V5 offline suites PASS. Это **не** доказательство активных методов в реальном production source.

**Ограничения:** текущие native phone handlers, `PHONE_EVENT_VERSION`, same E.164 reconfirm и `AUTH_EPOCH` transaction/10-path proof всё ещё `NOT_PROVEN`. Изолированный Bitrix/MySQL staging не аттестован. V5 HTTP 503 — последнее документированное состояние, не новый online check. Production/V4 не изменялись.

### 2026-10-14 — Active V4 phone lexical map production 5/5, only signup::phone add

Оператор выполнил на `evrasia` строго read-only, SHA-pinned `v5_active_v4_phone_lexical_map_readonly.sh` с полным PHP `token_get_all`. **FINAL_STATUS=PASS, PASS_COUNT=5, FAIL_COUNT=0, FINAL_RC=0**; SHA `Auth.php=72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`, `PhoneAuth.php=a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27` совпали, system tokenizer `EXPLICIT_ROOT_OWNED_TOKENIZER_SO`, никаких `php.ini`/Bitrix bootstrap/SQL/SMS/PII/production writes.

**В ACTIVE PHP `Auth.php`:** `sendSms()` line 22, `confirmation()` **60**, `signup()` **263**, `forgot_password()` **551**, `change_password()` **680** (`ACTIVE_METHOD_DECLARATIONS=5`). Прямой **единственный** активный `UserPhoneAuthTable::add` **372** (span `signup()`); `ACTIVE_NATIVE_PHONE_STATIC_CALLS=1`, `ACTIVE_CONFIRMED_FIELD_REFERENCES=0`, `ACTIVE_INSTANCE_MUTATOR_CALLS=6` (137/361/381/386/406/769). Метки «active» означают живой лексический код, не runtime/HTTP proof.

**Закомментированы:** `function confirm_request` line **779**, `function confirm` **954** (`COMMENTED_METHOD_TEXT_MATCHES=2`) и содержащие телефонные/CONFIRMED записи локальные comment regions 845/869/873/876/880/891/896/1012 (`COMMENT_PHONE_TEXT_REGIONS=8`). Прежние regex `getList/add/getRowById` строки 869/876/880, `CONFIRMED` write 891 и `confirm` 1012 **не являются active writer**; до официальной лексической проверки они ошибочно учитывались как candidates.

**В ACTIVE PHP `PhoneAuth.php`:** `sendCode` **65**, `check` **135**, `createUser` **188**, `sendSms` **220**, instance `->add` **210**. `ACTIVE_METHOD_DECLARATIONS=4`, `ACTIVE_NATIVE_PHONE_STATIC_CALLS=0`, `ACTIVE_CONFIRMED_FIELD_REFERENCES=0`, `COMMENTED_METHOD_TEXT_MATCHES=0`.

**Далее без повторов:** только read-only source-token classification синтаксических аргументов **активного** `UserPhoneAuthTable::add` line 372 и точных whitelisted method calls в ACTIVE `confirmation/signup` и `PhoneAuth::sendCode/check/createUser`. Не делать заключений о verified phone state или event bump из `add`/`check`; physical `b_user_phone_auth` persistence, successful code check, same-E.164 reconfirm, shared `AUTH_EPOCH` transaction и 10 native paths не доказаны. Нет аттестованного Bitrix/MySQL staging; V5 HTTP 503 — последний documented baseline. V4 и production не меняли.
