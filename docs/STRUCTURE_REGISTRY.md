# Evrasia — единый реестр исследованных структур и контрактов

> **Назначение:** единая точка восстановления фактов о сайте Bitrix, API V4, Trusted Device, TOTP/V3C и разработке API V5, чтобы не повторять одни и те же исследования.  
> **Обновлено:** 2026-10-11 (последняя read-only проверка реальной схемы `b_user`).  
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

## 7. Реально незакрытые интеграционные вопросы (только их исследовать дальше)

1. Проверить **runtime ORM mapping** только если собираемся использовать ORM-источник. После подтверждения физической `b_user` не повторять `information_schema`; SQL-кандидат — отдельный вариант.
2. Подключить `V5CurrentLoginControlsPort` к реальным штатным lockout/event правилам (`LOGIN_ATTEMPTS`, `PASSWORD_EXPIRED`, `OnBeforeUserLogin`) без обхода существующей безопасности.
3. Подключить `V5PerUserSmsEligibilityPort` к фактическим глобальным optional/mandatory MFA правилам и учётом деактивации.
4. На изолированном staging проверить новый `V5BitrixPdoCompatibilityBridge`: trusted exclusive transaction permit, тот же `Application::getConnection()` / ORM physical handle, Bitrix SqlHelper/affected rows и rollback. Затем устранить **подтверждённый пробел доказательств native Bitrix event atomicity**: все 10 потоков из `V5NativeMutationCoverage.php`, включая direct Otp add/delete и background OtpEvents agent, должны получить подтверждённые same-DB-transaction, b_user mutex, AUTH_EPOCH/CAS/rollback и staging concurrency. Только потом зарегистрировать реальные hooks, доверенный release-attestation, инициализировать общий `ev_samzaberu_auth_state`, выполнить isolated staging challenge/ SMS E2E; не менять production и не пересматривать уже проверенную структуру b_user.
5. Подтвердить strong-enrollment provenance Trusted Device, сделать независимые V5 JWT/refresh/assurance и защищённый PIN.
6. Сделать staging end-to-end; затем отдельный одобренный пилот `USER_ID=880339`. **Никаких production write до отдельного разрешения оператора.**

## 8. Индекс первоисточников и статус продолжения

**Main, постоянные документы:** `docs/PROJECT_CHECKPOINT.md`, `docs/AI_PROJECT_CONTEXT.md`, `docs/NEW_CHAT_HANDOFF.md`, `docs/CURRENT_ARCHITECTURE.md`, `docs/SERVER_SCRIPT_RULES.md`, `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`, `docs/SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md`.

**Feature, будущий V5:** `docs/SAMZABERU_API_V5_AUTH_CONTRACT_2026-10-11.md`, `site-integration/samzaberu-v5/README.md` и `site-integration/samzaberu-v5/{src,tests,api}`.

**Решение:** было «исходник `UserTable.php` не содержит два имени → предположение, что схема не подтверждена» → стало «физическая `b_user` имеет оба столбца (PASS), но ORM-проекция отдельно не доказана» → причина «результат реального read-only `information_schema`». Документ является исходной точкой для нового чата; подробные уже закрытые аудиты повторять запрещено без нового основания.
