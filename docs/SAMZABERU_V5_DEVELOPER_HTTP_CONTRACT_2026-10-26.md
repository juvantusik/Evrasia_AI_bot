# Сам Заберу — API V5: контракт разработчику (26.10.2026)

> Публичный адрес: `https://evrasia.rest/api/v5`. Все POST: `Content-Type: application/json`; только HTTPS. Версия контракта — код `V5RouteMap` / `V5AuthHttpHandler` текущей ветки. **Это не заявление, что все маршруты уже доступны на production.** У каждого маршрута приведён честный статус.

## Статусы на момент подготовки

- **HTTP подтверждено:** `GET /health` (200), `GET /legal/consents` (200), `GET /legal/registration-documents` (200).
- **Маршрут установлен, негативный HTTP-тест пройден:** `POST /auth/signup` (400 на `{}`, без создания пользователей). Успешная реальная регистрация/согласия требует отдельного положительного теста.
- **Маршрут ранее работал, положительный ответ текущего набора ещё не подтверждён:** `POST /legal/consents/validate`.
- **Контракт написан в GitHub, production включение НЕ подтверждено:** `POST /auth/signin`, `POST /auth/{totp,email,sms}/verify`, `POST /auth/challenge/{verify,resend,use-sms}`, `POST /auth/refresh`, `POST /auth/logout`, `POST /auth/token/validate`, `POST /loyalty/pin`, профиль/корзина/заказы. Последний проверенный `signin` ответил HTTP 503; не переключать клиента на рабочий сценарий входа до серверного релиза.

## 1. Юридические документы

`GET /legal/registration-documents` и `GET /legal/consents`: тело `{ "documents": [ ... ] }`. Каждый документ содержит `code`, `type` (`offer` / `personal_data` / `marketing`), `title`, `required`, `format` (`url` / `text` / `html`), `content` (строка или null), `url`. Отображать `offer` и `personal_data` **до** регистрации и собирать обязательные согласия. `marketing` необязательный. Список кодов динамический; никогда не хранить коды ревизий в приложении.

`POST /legal/consents/validate`:
```json
{"agreements":["<current offer code>","<current personal_data code>"]}
```
Ответ 200: `{"valid":true,"agreements":["..."]}`; 409: `{"error":"documents_changed"}`; 400: `{"error":"invalid_request"}`. Этот precheck **не записывает** согласия — их фиксирует регистрация.

## 2. Регистрация

`POST /auth/signup`:
```json
{
  "registration": {
    "name": "Тестовый пример",
    "phone": "+79990000000",
    "email": "example@example.org",
    "password": "<user password>",
    "confirm_password": "<same password>"
  },
  "agreements": ["<current offer code>","<current personal_data code>"]
}
```
Данные выше — **макет контракта**, не отправлять его как тест на production: синтетические аккаунты запрещены. Дополнительно допустимы `bday` и `sex` (`M`/`F`). Успех по коду: HTTP 201 `{"status":"registered","user_id":123,"next_step":"email_confirmation"}`. Этот ответ **не** содержит JWT, PIN и trust token. Согласия записываются в Bitrix с текущими версиями; marketing по желанию. 409 `legal_documents_changed`, 400 `invalid_request`, 503 `temporarily_unavailable`. Не перезапускать регистрацию вслепую при 503: запись могла сохраниться, а email — не отправиться.

## 3. Установка приложения и вход (контракт, production ещё не включён)

При установке приложение формирует `device_id = "sz_" + 64 lowercase hex`, `platform = ios | android`. `trust_token` — **отдельный секрет**, который генерирует и хранит приложение; сервер его **не возвращает**. При первом входе `trust_token` может отсутствовать. При наличии действующего зарегистрированного token сервер проверяет его: **trusted device имеет приоритет над TOTP**.

`POST /auth/signin`:
```json
{"login":"<phone or email>","password":"<password>","device_id":"sz_<64 hex>","platform":"android","trust_token":"<existing client-generated 64-hex token>"}
```
Возможны:
- HTTP 200 `{"status":"authenticated","access_token":"<v5 jwt>","refresh_token":"<opaque v5 refresh>"}`, **только** после подтверждения действительного trust.
- HTTP 202 `{"status":"challenge_required","challenge_id":"<64 hex>","challenge_type":"totp|email|sms","expires_in":300}` — требуется фактор. Значение TTL приходит от сервера, не считать 300 константой.
- HTTP 400 `invalid_request`; 401 `authentication_failed`; 503 `temporarily_unavailable` или `v5_unavailable`.

Без валидного trust: активная native Bitrix TOTP → `totp`; иначе подтверждённый email → `email`; SMS — предусмотренный **явный** fallback, не автоматический переход с TOTP.

## 4. Проверка TOTP / Email / SMS

`POST /auth/totp/verify`, `POST /auth/email/verify`, `POST /auth/sms/verify` и общий `POST /auth/challenge/verify`:
```json
{"challenge_id":"<64 hex>","code":"<six digits>","device_id":"sz_<64 hex>","platform":"android"}
```
При успехе HTTP 200 `{"status":"authenticated","access_token":"<v5 jwt>","refresh_token":"<opaque v5 refresh>"}`. Проверка только server-side. Не принимать код другого типа фактора и не выдавать JWT до погашения challenge.

`POST /auth/challenge/resend` (для SMS): `{"challenge_id":"...","device_id":"...","platform":"android"}`. HTTP 202: `{"status":"challenge_pending","challenge_id":"...","challenge_type":"sms","expires_in":...}`. Лимиты и TTL решает сервер.

`POST /auth/challenge/use-sms` — **ручной** переход из email challenge на SMS, с тем же JSON. Успех HTTP 202 с **новым** SMS challenge ID. Это не SMS для получения бонусного PIN.

## 5. Сессия / PIN

`POST /auth/refresh`: `{"refresh_token":"v5r_<64 hex>"}`. Успех 200 — новый `access_token` и ротация `refresh_token`; старый повторно не использовать.

`POST /auth/logout`: `{"access_token":"<v5 jwt>"}`. Успех 200 `{"status":"logged_out"}`.

`POST /auth/token/validate`: `{"access_token":"<v5 jwt>"}`. 200 `{"valid":true}` либо 401 `{"valid":false}`.

`POST /loyalty/pin`: `{"access_token":"<v5 jwt>"}`. Успех 200 `{"status":"revealed","pin":"<string>"}`; PIN не преобразовывать в число (ведущие нули), не хранить в логах. После подтверждённой сильной V5-сессии повторный SMS для PIN не нужен.

## 6. После авторизации: зарегистрированные маршруты, без подтверждения production

`GET /profile`, `GET /cart`, `GET /orders` — V5 JWT в `Authorization: Bearer <access_token>`; `POST /orders/detail`, `POST /cart/items/add`, `POST /cart/items/quantity`, `POST /cart/items/remove`, `POST /cart/order` — контракт бизнес-полей сверять с опубликованным кодом V5 перед мобильной интеграцией. Эти маршруты ещё **не объявлены готовыми**.

## 7. Ошибки и порядок интеграции

На 503 показать временную недоступность, не повторять платные SMS автоматически. На 401 — повторный вход/фактор, на 409 — заново запросить текущие legal документы. Пароли, OTP, JWT, refresh, trust token и PIN не логировать. У V5 свои JWT/refresh; V4 tokens не подходят.

**Рекомендованный порядок для мобильного разработчика:** legal UI → signup UI → signin/chooser → код TOTP/email/SMS → refresh/logout → PIN → остальной функционал. **Не блокировать фронтенд разработку ожиданием серверного включения, но реальные 200/202 авторизации требуется подтвердить до релиза приложения.**

## 8. Серверные условия завершения, строго по факту

V5 signup endpoint успешно установлен оператором, backup `/root/ev-v5-signup-20261026.Kzisc3k8`, `PUBLIC_ROUTER_SHA256=c49746548517b4783c4234b3ee140a529fb12ecba937215e3d3b545413ad8659`. Действующий V4 независим. Реальный V5 auth HTTP выпуск пока упирается в production private factory и подтверждённые native-провайдеры MFA/сессий; нельзя просто открыть старый `Controllers/V5/Auth.php`, где после 503 находится password-only legacy JWT. Дальнейшие production статусы добавляются **только после выполнения KiTTY installer и реальных HTTP postchecks**.
