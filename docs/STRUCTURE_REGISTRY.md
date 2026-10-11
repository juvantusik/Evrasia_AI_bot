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
| `api/v4/router.php` | `3b263c09387a3d588f972960f2e8eb44e4d88b21065539be50` | маршрутизатор V4, ранее source-verified |
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
| `local/php_interface/Controllers/V4/PinCode.php` | `7734ddbaba0e7ec9ddfc9f0a9008c0da0c1523e505e52caebac2bbaf637cd0` | V4 выдача бонусного PIN через прежний канал, **исторический исходник** |

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
| `V5NativeTotpStatus` | OFFLINE TESTED; только безопасное определение состояния нативной MFA; policy fallback пока не реализован |
| V3C native TOTP | offline/staging prototype, не развернут и не заменяет нативный Bitrix |
| `V5FirstFactorAttemptGatePort`, `V5ExistingLoginPolicyPort`, `V5NoMfaFallbackPolicyPort`, strong-trust provenance | **PENDING реальной реализации / интеграции** |
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

## 7. Реально незакрытые интеграционные вопросы (только их исследовать дальше)

1. Проверить **runtime ORM mapping** только если собираемся использовать ORM-источник. После подтверждения физической `b_user` не повторять `information_schema`; SQL-кандидат — отдельный вариант.
2. Реализовать `V5ExistingLoginPolicyPort` по штатным lockout/event правилам (`LOGIN_ATTEMPTS`, `PASSWORD_EXPIRED`, `OnBeforeUserLogin`) без обхода существующей безопасности.
3. Реализовать `V5NoMfaFallbackPolicyPort` с текущими глобальными optional/mandatory MFA правилами и учётом деактивации.
4. Завершить атомарное хранилище challenge, повторное использование кода TOTP между вебом и мобильным каналом, бюджет SMS и серверное определение проверенного телефона.
5. Подтвердить strong-enrollment provenance Trusted Device, сделать независимые V5 JWT/refresh/assurance и защищённый PIN.
6. Сделать staging end-to-end; затем отдельный одобренный пилот `USER_ID=880339`. **Никаких production write до отдельного разрешения оператора.**

## 8. Индекс первоисточников и статус продолжения

**Main, постоянные документы:** `docs/PROJECT_CHECKPOINT.md`, `docs/AI_PROJECT_CONTEXT.md`, `docs/NEW_CHAT_HANDOFF.md`, `docs/CURRENT_ARCHITECTURE.md`, `docs/SERVER_SCRIPT_RULES.md`, `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`, `docs/SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md`.

**Feature, будущий V5:** `docs/SAMZABERU_API_V5_AUTH_CONTRACT_2026-10-11.md`, `site-integration/samzaberu-v5/README.md` и `site-integration/samzaberu-v5/{src,tests,api}`.

**Решение:** было «исходник `UserTable.php` не содержит два имени → предположение, что схема не подтверждена» → стало «физическая `b_user` имеет оба столбца (PASS), но ORM-проекция отдельно не доказана» → причина «результат реального read-only `information_schema`». Документ является исходной точкой для нового чата; подробные уже закрытые аудиты повторять запрещено без нового основания.
