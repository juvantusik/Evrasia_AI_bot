# СамЗаберу — API V5: ТЗ для разработчика приложения

Дата: 25.10.2026. Основной публичный домен: https://evrasia.rest. Базовый путь: /api/v5.
Исходники: juvantusik/Evrasia_AI_bot, ветка feature/v5-production-native-preflight-20261025.

**СТАТУС: КОНТРАКТ ДЛЯ РАЗРАБОТКИ / MOCK. V5 AUTH OFF. НИ ОДИН ИЗ ПЕРЕЧИСЛЕННЫХ V5 МЕТОДОВ ЕЩЁ НЕ ОБЪЯВЛЕН LIVE НА PRODUCTION.**

Это согласованный по текущему коду интерфейс для создания экранов, сетевого клиента и mock-тестов. Документ НЕ является разрешением использовать методы на реальных пользователях.

## 1. Список маршрутов

| Method | Path | Назначение | Состояние |
|---|---|---|---|
| POST | /auth/signin | Вход логин/пароль, доверие, запуск challenge | реализован контракт, live OFF |
| POST | /auth/challenge/verify | Проверка TOTP или challenge | реализован контракт, live OFF |
| POST | /auth/challenge/resend | Повтор разрешённого challenge | реализован контракт, live OFF |
| POST | /auth/challenge/use-sms | Явный SMS fallback | реализован контракт, live OFF |
| POST | /auth/refresh | Ротация access/refresh | реализован контракт, live OFF |
| POST | /auth/logout | Завершение сильной сессии | реализован контракт, live OFF |
| POST | /auth/token/validate | Проверка JWT V5 | реализован контракт, live OFF |
| POST | /loyalty/pin | Бонусный PIN в сильной сессии | реализован контракт, live OFF |
| GET | /profile | Профиль текущего гостя | реализован контракт, live OFF |
| GET | /cart | Корзина текущего гостя | контракт, site reader OFF |
| POST | /cart/items/add | Добавление товара | HTTP + coordinator, site writer OFF |
| POST | /cart/items/quantity | Количество позиции | HTTP + D7 candidate, единый backend не доказан |
| POST | /cart/items/remove | Удаление позиции | HTTP + D7 candidate, единый backend не доказан |
| POST | /cart/order | Создание заказа | idempotent native writer, live OFF |
| GET | /orders | История заказов | реализован контракт, live OFF |
| POST | /orders/detail | Детали своего заказа | реализован контракт, live OFF |
| GET | /legal/consents | Актуальные тексты и коды соглашений | реализован контракт, live OFF |
| GET | /legal/registration-documents | Те же соглашения для регистрации | реализован контракт, live OFF |
| POST | /legal/consents/validate | Проверка кодов без сохранения | реализован контракт, live OFF |

В текущей карте V5 **нет POST /auth/register**. Можно делать экран регистрации и показывать legal-документы, но настоящий вызов регистрации и атомарной записи согласий ещё требует серверной реализации.

## 2. Общие правила

HTTPS, Content-Type: application/json для POST, максимум 8192 байт, без лишних полей. Для защищённого GET передавать Authorization: Bearer <V5 access JWT>, без тела. Для защищённого POST код сейчас принимает access_token в JSON. Не смешивать V4 JWT и V5 JWT. Не принимать от клиента USER_ID, FUSER_ID, цену, валюту, статус оплаты или идентификатор собственника корзины. Не логировать PIN, пароль, access/refresh и коды TOTP/SMS.

Ответы no-store, JSON. Не подставлять примеры из ТЗ как реальные guest/product/restaurant ID.

## 3. Авторизация V5: доверенное устройство, TOTP, email и SMS

**Контракт для реализации мобильного клиента и mock-тестов, НЕ live API.** На сайте работают свои сценарии 2FA; мобильный V5 создаёт отдельную сильную сессию, а его публичная авторизация на production пока OFF.

### 3.1. POST /auth/signin

    {"login":"guest@example.com","password":"<password>","device_id":"sz_<64 lowercase hex>","platform":"android","trust_token":null}

- device_id: ровно sz_ + 64 строчных hex символа (серверная проверка формата); platform — ios или android. Устройство привязано к установке приложения; код нельзя проверять с другой установки.
- trust_token: только ранее выданное сервером действительное сильное доверие; одного device_id недостаточно.
- Первый фактор проверяет пароль по Bitrix без браузерной авторизации. Сервер проверяет блокировки, лимиты попыток и AUTH_EPOCH.

| Состояние после подтверждения пароля | Ответ и UI |
|---|---|
| Действительное сильное доверенное устройство | HTTP 200, сразу токены V5, без повторного TOTP/email/SMS |
| Доверия нет, нативный Bitrix TOTP включён | HTTP 202, challenge_type=totp, экран кода аутентификатора |
| Доверия нет, TOTP не включён | HTTP 202, challenge_type=email, экран кода из письма |
| Нет подтверждённого email или email-провайдер недоступен | Отказ, НЕ автоматическая отправка SMS |

HTTP 202:

    {"status":"challenge_required","challenge_id":"<64 lowercase hex>","challenge_type":"email","expires_in":300}

challenge_type выбирает сервер (totp / email / только отдельно разрешённый sms). expires_in — значение сервера, максимум 300 секунд, не фиксировать 300 в UI.

HTTP 200:

    {"status":"authenticated","access_token":"<V5 JWT>","refresh_token":"<V5 refresh>"}

Опционально trusted_device, если сервер выдал подтверждённые данные доверия.

### 3.2. POST /auth/challenge/verify

Один маршрут для TOTP, email OTP и отдельно разрешённого SMS OTP:

    {"challenge_id":"<64 lowercase hex>","code":"123456","device_id":"sz_<те же 64 hex>","platform":"android"}

Код: ровно 6 цифр. Device_id/platform строго те же, что при первом шаге. При успехе HTTP 200 и access_token + refresh_token. Челлендж одноразовый, с TTL и ограничением попыток.

**TOTP:** проверяется на сервере через штатную Bitrix MFA и защиту от повторного использования, не в приложении. Используется существующий фактор сайта, но мобильный V5 всё равно должен создать собственную сильную сессию. Код нативной серверной проверки подготовлен, production E2E для V5 ещё не подтверждён.

**Email:** пароль и владение текущим email перепроверяются сервером под блокировками; email нельзя задать в теле запроса. Есть отдельный ledger email challenge, HMAC кода, контроль устройства, лимиты и AUTH_EPOCH. В составе email-first предусмотрены существующие Bitrix D7 mail event EVRASIA_WEB_EMAIL_LOGIN, шаблон 95, сайт s1 и существующий Redis budget. Принятие письма в очередь Bitrix не гарантирует фактическую доставку во входящие.

### 3.3. SMS fallback

SMS не запускается автоматически и не подменяет включённый TOTP. Только при наличии текущего email challenge приложение может запросить осознанный fallback:

POST /auth/challenge/use-sms:

    {"challenge_id":"<ID исходного email challenge>","device_id":"sz_<те же 64 hex>","platform":"android"}

При положительном подтверждении сервера выдаётся НОВЫЙ challenge_type=sms с другим challenge_id; затем вызов обычного POST /auth/challenge/verify с кодом из SMS. Телефон берётся из проверенного аккаунта, не передаётся приложением. Сервер обязан проверить email challenge, пароль, отсутствие нативного TOTP, устройство, AUTH_EPOCH, подтверждённый телефон, SMS-квоты и фактически принятую отправку.

**Реальное состояние:** V5ExistingWebsiteSmsRecoveryAdapter и защитные интерфейсы есть, но обязательная site-native реализация положительного SMS-перехода/подтверждения отправки ещё не подключена. В email-first составе используется V5UnavailableSmsLifecycle вместо обычного автоматического SMS. POST /auth/challenge/resend присутствует в маршрутах, но сейчас не является работающим универсальным повтором email/SMS. Не делать автоматических повторных отправок и не обещать пользователю SMS до серверного разрешения.

### 3.4. Сессия и бонусный PIN

POST /auth/refresh:

    {"refresh_token":"<V5 refresh>"}

Сервер выдаёт новую пару; после успешной ротации хранить только актуальную. POST /auth/logout, POST /auth/token/validate и POST /loyalty/pin получают:

    {"access_token":"<V5 JWT>"}

Показ PIN: {"status":"revealed","pin":"<PIN>"} — при уже подтверждённой сильной V5-сессии, без повторного SMS. Отзыв доверия, истечение сессии или изменение AUTH_EPOCH требуют нового подтверждения. V4 JWT не разрешён.

### 3.5. UI и безопасность

- Экран фактора выбирать только по challenge_type, а не по собственному решению приложения.
- Сохранять устройство/trust только из серверного подтверждения, не принимать device_id за доказательство доверия.
- Не передавать email, телефон, цену, USER_ID/FUSER_ID вместе с challenge.
- Не логировать пароль, TOTP, SMS, email OTP, PIN и токены.
- Обрабатывать недоступность email/SMS, истечение challenge, отказ сервера, повтор и отзыв сессии.
- Не считать эти ручки production-ready до отдельного подтверждения native TOTP + email/Redis transport + site SMS recovery и включения V5 AUTH.

## 4. Юридические документы при регистрации

GET /legal/registration-documents (равно GET /legal/consents) возвращает объект documents. Каждый документ содержит поля code, type, required, title, format, content, url. Типы offer и personal_data обязательны, marketing необязателен. Format: url, text или html. Для url значение content равно null; реальную редакцию и текст всегда задаёт сервер.

Иллюстративный формат (не реальный код согласия):

    {"documents":[{"code":"<current offer code>","type":"offer","required":true,"title":"<title>","format":"url","content":null,"url":"https://evrasia.rest/legal/<path>"}]}

POST /legal/consents/validate:

    {"agreements":["<current offer code>","<current personal data code>"]}

Ответ 200: {"valid":true,"agreements":[...]}; при устаревших документах HTTP 409 documents_changed — повторно получить и показать актуальную редакцию. **Валидация только проверяет коды и НЕ создаёт пользователя, НЕ записывает согласие**. Для реальной регистрации требуется ещё серверная операция с сохранением выбранных кодов.

## 5. Корзина

GET /cart требует Authorization Bearer V5. Возвращает {"items":[...],"total":{...}}. Поля line ID/total должны быть финально закреплены после объединения backend: пример нормализованного вида ниже НЕ подтверждён как текущий live ответ.

    {"items":[{"id":401,"product_id":81,"quantity":2,"name":"<server title>"}],"total":{"price":380,"currency":"RUB"}}

POST /cart/items/add:

    {"access_token":"<V5 JWT>","product_id":81,"quantity":2}

Контракт ответа после подтверждённой записи: HTTP 201, {"item":{"id":401,"product_id":81,"quantity":2}}. Новый product_id проверяется по текущему серверному каталогу, цены и доступность не принимаются от клиента. **Site-native writer записи в реальную B_API_BASKET ещё НЕ подключён**.

POST /cart/items/quantity:

    {"access_token":"<V5 JWT>","item_id":401,"quantity":3}

Ответ HTTP 200: {"item":{"item_id":401,"quantity":3}}.

POST /cart/items/remove:

    {"access_token":"<V5 JWT>","item_id":401}

Ответ HTTP 200: {"item":{"item_id":401,"removed":true}}.

Количество целое от 1 до 1000. item_id — существующий серверный ID строки корзины (не product_id), который должен быть идентичен во всех методах.

**Важный release-блокер:** checkout использует CartApiService / B_API_BASKET, но прежний кандидат D7 reader/mutator работает с b_sale_basket. Это потенциально разные корзины. Автоматический D7 fallback для GET /cart удалён. Нельзя включать корзину V5 до подтверждения, что add/quantity/remove/GET/checkout читают и пишут ОДНУ корзину с одинаковым line ID; иначе товар окажется в UI, но не попадёт в заказ. Пока ответы site-disabled: 503, не ложный успех.

## 6. Оформление заказа

POST /cart/order:

    {"access_token":"<V5 JWT>","request_id":"0123456789abcdef0123456789abcdef","checkout":{"rid":12,"name":"<guest name>","phone":"<guest phone>","time":"19:30","dispatch":"<site supported mode>","cutlery":2,"comment":"<optional comment>"}}

Здесь rid — реальный ID действующего ресторана, dispatch — один из двух проверенных сервером вариантов; конкретные значения и образцовые 12/19:30 НЕ утверждены как production правила. Сервер назначает способ оплаты **при получении**, не принимайте payment и pay_system_id от приложения; не маркируйте заказ оплаченным.

Успешный контракт: HTTP 201, {"order":{"id":401,"number":"<Bitrix order number>","status":"N"}}. Статус определяется сервером, N здесь пример неотменённого нового заказа.

request_id — UUID или ровно 32 символа lowercase hex. Клиент создаёт его один раз на одну попытку и при сетевом сбое повторяет с **тем же ключом и тем же JSON**, чтобы не создать дубликат. Не генерировать новый ключ при обычном retry.

Маршрут: СамЗаберу → API V5 → Bitrix → **существующий канал передачи в колл-центр** → колл-центр → ресторан. Никаких прямых вызовов колл-центра/ресторана из приложения. Серверы колл-центра не исследуются. Native Sale checkout создан в коде, но ещё требует реального подтверждения общей транзакции, локального признака принятия в существующий канал и настройки ledger.

## 7. Профиль, заказы, бонусный PIN

GET /profile — V5 Bearer; данные текущего гостя из Bitrix. GET /orders — V5 Bearer; JSON {"orders":[...]} (не более 50 записей). POST /orders/detail:

    {"access_token":"<V5 JWT>","order_id":401}

HTTP 200 — {"order":{"id":401,"items":[...]}} с реальными полями Bitrix; HTTP 404 order_not_found для отсутствующего/чужого заказа. Не передавать client USER_ID. PIN: POST /loyalty/pin, JSON {"access_token":"<V5 JWT>"}.

## 8. Обработка ошибок

- HTTP 400 invalid_request — неверный JSON, поле, тип или количество.
- HTTP 401 authentication_failed — нет подтверждённой V5-сессии или истёк/отозван токен.
- HTTP 403 secure_transport_required; 404 not_found; 405 method_not_allowed; 415 unsupported_media_type.
- HTTP 409 documents_changed — старая редакция legal.
- HTTP 503 v5_unavailable — маршрут не подключён или V5 OFF.
- HTTP 503 temporarily_unavailable — операцию нельзя безопасно подтвердить.

Не показывать успешный заказ/добавление товара после 503. Повтор checkout выполнять только с исходным request_id.

## 9. Что можно передать разработчику и что НЕ считается завершённым

**Уже можно делать сегодня:** мобильные формы, сетевые DTO, клиентскую логику сильной сессии, TOTP/SMS/trusted, legal UI, корзину и заказ по mock-ответам, idempotent retry, историю заказов, профиль и PIN.

**На сервере нужно завершить перед live:** (1) проверенную общую транзакцию Bitrix Sale D7/V5 и побочные эффекты save; (2) ОДНУ корзину B_API_BASKET для чтения/добавления/изменения/удаления/оформления и проверенный item ID; (3) site-native реализацию записи в B_API_BASKET; (4) реальные значения NOTES, source, happytime, dispatch и pay-on-pickup; (5) схему checkout request ledger; (6) локальное подтверждение передачи в существующий канал колл-центра; (7) настоящий POST регистрации и атомарную фиксацию legal-согласий; (8) отдельное разрешение на pilot с реальным пользователем. **До этого V5 AUTH OFF, production не изменён.**

## 10. Код и тесты

Исходники: site-integration/samzaberu-v5/src/V5RouteMap.php, V5AuthHttpHandler.php, V5HttpTransportBoundary.php, V5MySqlStrongSessionStore.php, V5CartMutationRequest.php, V5BitrixExistingCartAdder.php, V5BitrixLockedPickupOrderWriter.php, V5NativePickupCheckoutComposition.php, V5LegalConsentContract.php.

CI: .github/workflows/v5-checkout-source-only.yml. Источниковые и mock-тесты подтверждают проверку контрактов, **но не работу на live Bitrix**.
