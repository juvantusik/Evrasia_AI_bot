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

## 3. Авторизация, TOTP, trusted device, SMS

POST /auth/signin:

    {"login":"guest@example.com","password":"<password>","device_id":"<installation id>","platform":"android","trust_token":null}

При необходимости challenge: HTTP 202, например:

    {"status":"challenge_required","challenge_id":"<opaque>","challenge_type":"totp","expires_in":300}

При сильном доверии текущего устройства либо успешно пройденном challenge: HTTP 200:

    {"status":"authenticated","access_token":"<V5 JWT>","refresh_token":"<V5 refresh>"}

В ответе может также присутствовать trusted_device. Значения challenge_type, expires_in и token opaque — только с сервера; демонстрационные числа не являются установленной конфигурацией.

POST /auth/challenge/verify:

    {"challenge_id":"<opaque>","code":"123456","device_id":"<same installation>","platform":"android"}

POST /auth/challenge/use-sms или /auth/challenge/resend:

    {"challenge_id":"<opaque>","device_id":"<same installation>","platform":"android"}

POST /auth/refresh:

    {"refresh_token":"<V5 refresh>"}

Ответ refresh — новая пара access_token и refresh_token, предыдущую нельзя использовать повторно. POST /auth/logout, POST /auth/token/validate и POST /loyalty/pin принимают JSON {"access_token":"<V5 JWT>"}.

PIN возвращается как {"status":"revealed","pin":"<PIN>"} после подтверждённой сильной V5-сессии без повторного SMS. Бизнес-правило: действительное доверенное устройство может проходить без регулярного повторного TOTP; без доверия нужен предусмотренный сервером challenge. Не спрашивать телефон пользователя в форме SMS challenge: сервер уже знает его из профиля.

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
