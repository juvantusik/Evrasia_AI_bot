# Техническое задание для разработчика «СамЗаберу»: регистрация, юридические документы, доверенные устройства, TOTP и SMS challenge

**Редакция:** 23 октября 2026. **Целевой публичный API:** `https://evrasia.rest/api/v5`. Документ для разработки клиента iOS/Android, **не подтверждение готовности приватного production API**.

## Коротко: что реально можно подключать

- **PRODUCTION/EXTERNAL GET ACCEPTED:** `GET /api/v5/legal/registration-documents`, `GET /api/v5/legal/consents`, `GET /api/v5/health`. Независимый GitHub-hosted runner [#39999984588](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39999984588) получил HTTP 200, проверил TLS, JSON и совпадение 3 текущих Bitrix юридических документов на **evrasia.rest**. Пользовательские аккаунты/JWT не участвовали.
- **PRODUCTION BITRIX ORIGIN ACCEPTED, EXTERNAL POST NOT YET ATTESTED:** `POST /api/v5/legal/consents/validate`. Возвращает результат read-only проверки кодов; **это не сохранение согласий и не регистрация**.
- **FEATURE-ONLY / AUTH PRODUCTION OFF:** `POST /auth/signin`, `POST /auth/challenge/verify`, `POST /auth/challenge/resend`, `POST /auth/refresh`, `POST /auth/logout`, `POST /auth/token/validate`, `POST /loyalty/pin` и защищённый `GET /cart`. Маршруты находятся в `src/V5RouteMap.php`, но native production AUTH не включена. **Сейчас на origin `POST /api/v5/auth/signin` проверенно возвращает 503.** Не использовать их как работающие production-сервисы, клиентскую логику реализовывать как ожидающий контракт.
- **NOT IMPLEMENTED / NO PUBLIC V5 ROUTE:** полноценная регистрация `signup` с фиксацией в Bitrix `b_consent_user_consent`, выдача/отзыв доверия после TOTP/SMS в мобильной V5 реализации. Никаких выдуманных URL и фиктивных успешных ответов.

## 1. Юридические документы в форме регистрации — можно реализовывать и подключать прямо сейчас

`GET https://evrasia.rest/api/v5/legal/registration-documents` без JWT. Ответ HTTP 200 содержит `{"documents":[...]}`. Каждый документ возвращается в действующей серверной редакции с `code` (не зашивать), `type`, `title`, `required`, `format`, `url`, `content` в зависимости от формата.

Подтверждено 3 документа: `offer` — обязателен, `personal_data` — обязателен, `marketing` — необязателен. Отдельно открывать полное содержание по реальному `url` либо `content`; маркетинг отдельным чекбоксом, по умолчанию не отмечен; не объединять его с обязательным согласием и не проставлять без пользователя.

`GET /api/v5/legal/consents` — альтернативный источник того же текущего server-only каталога. При недоступности обязательных документов не разрешать регистрацию.

**Кандидат для проверки выбранных редакций:** `POST https://evrasia.rest/api/v5/legal/consents/validate`, `Content-Type: application/json`, пример запроса (реальные коды взять из GET, не использовать placeholder как значение):

```json
{"agreements":["<actual offer code from GET>","<actual personal_data code from GET>"]}
```

Если маркетинговое согласие было выбрано добровольно, добавить его текущий `code`. Ожидаемый успех HTTP 200: `{"valid":true,"agreements":[...]}`. При `409 {"error":"documents_changed"}` повторно загрузить редакции и получить подтверждения; `400 invalid_request`, `503 temporarily_unavailable` — не продолжать.

**Важно:** `validate` не принимает согласия юридически и не создаёт пользователя. Финальная регистрация через будущий V5 signup должна **атомарно/с проверенным native commit** создать Bitrix USER_ID, заново проверить актуальные редакции, записать Bitrix UserConsent `Consent::addByContext` и проверить, что согласия действительно сохранились. До выпуска этого маршрута приложение может готовить экран/форму, но **не отправлять фиктивный POST signup или consent accept**.

## 2. Последнее принятое правило авторизации: доверие → TOTP → SMS

После правильных логина/пароля и server-only проверки статуса пользователя:

| Текущее подтверждённое состояние | Следующий шаг клиента |
|---|---|
| Доверенное устройство, валидный токен и подтверждённое **сильное** предыдущее MFA-зачисление | **Сразу вход, без TOTP и SMS**, даже если включён TOTP |
| Доверие отсутствует/истекло/отозвано/не соответствует текущей политике, TOTP включён | Ввод TOTP из аутентификатора |
| Доверие отсутствует/невалидно, TOTP не подключён и политика допускает SMS | SMS challenge на **подтверждённый номер из Bitrix** |
| Пароль/аккаунт/MFA-status/версия телефона/доверие не могут быть безопасно проверены | Отказ; никакой выдачи JWT и произвольного SMS fallback |

Это **изменение** прежней feature-политики `native TOTP before trust`. Текущий `src/AuthDecision.php`, `src/V5LoginWorkflow.php`, `src/V5MySqlStrongSessionStore.php` всё ещё запрещают trusted-login при активном TOTP. **Backend должен исправить код и тесты до выпуска AUTH**. Нельзя просто объявить этот случай готовым в мобильном приложении. Сохраняем обязательность повторной серверной проверки действительного trust token / device / strong enrollment под блокировкой пользователя при выдаче JWT.

**Безопасность доверия:** `device_id` сам по себе не подтверждает доверие. Доверие должно иметь серверно подтверждённый токен, срок, принадлежность USER_ID/device_id/platform и отсутствие отзыва, плюс свидетельство предшествующего успешного TOTP/SMS. Историческое доверие V4, созданное **только по паролю**, нельзя автоматически считать эквивалентным сильному V5-доверию. После изменения TOTP, пароля, безопасности аккаунта или отзыва доверия предыдущее подтверждение должно инвалидироваться через обязательную native epoch/token-version логику. Переиспользовать рабочий `TrustedDeviceMobileService`/Bitrix core, не внедрять клиентское решение «это trusted».

## 3. Идентификатор установки и хранение

При первой установке сгенерировать криптографически случайный идентификатор **`sz_` + 64 lowercase hex** (всего 67 символов) и использовать его стабильно для этой установки; `platform` строго `ios` или `android`. Не удалять префикс `sz_`; не передавать IMEI, MAC, рекламные идентификаторы. Отдельный сервером возвращённый `trust_token` хранить в iOS Keychain / Android Keystore-backed secure storage; не логировать и не вставлять в URL. Если пользователь удалил приложение/сбросил токен, будет новый MFA challenge.

Точное создание сильного V5 trust-токена, момент его обновления и ответ `trusted_device` согласовываются с backend перед подключением; не считать любой V4 device record достаточным.

## 4. Предусмотренные маршруты приватной авторизации V5 (ЕЩЁ НЕ PRODUCTION)

Все POST запросы — HTTPS `Content-Type: application/json`, без cookie-based авторизации V4 и без реквизитов в query-string.

### 4.1 POST /api/v5/auth/signin

Запрос:

```json
{
  "login":"<user login>",
  "password":"<entered password>",
  "device_id":"sz_<64 lowercase hex>",
  "platform":"ios",
  "trust_token":"<saved valid V5 trust token, if available>"
}
```

`trust_token` необязателен. **Текущий feature JSON контракт**, ещё не принятый production:

- HTTP **200** после доверенного MFA-подтверждённого устройства: `{"status":"authenticated","access_token":"...","refresh_token":"..."}` (+ необязательный `trusted_device`).
- HTTP **202**, когда требуется второй фактор: `{"status":"challenge_required","challenge_id":"<64 hex>","challenge_type":"totp|sms","expires_in":300}`. `expires_in` фактическое серверное значение 1–300 с, пример 300 не гарантирован. При 202 **никаких access/refresh токенов**.
- HTTP **401** для ошибки аутентификации; HTTP **503** сейчас, пока AUTH V5 OFF.

Клиент выбирает экран **только по `challenge_type`**, а не по своему предположению об активном TOTP, доверии или номере телефона.

### 4.2 POST /api/v5/auth/challenge/verify

Одинаковый route для TOTP и SMS. Запрос:

```json
{"challenge_id":"<server challenge_id>","code":"123456","device_id":"sz_<64 lowercase hex>","platform":"ios"}
```

Шесть ASCII-цифр; пример `123456` не является настоящим кодом. Backend проверяет одноразовый challenge, привязку к пользователю и установке, текущее MFA/доверие/epoch, а TOTP — **на Bitrix сервере**, штатным `Bitrix\Security\Mfa\Otp` и native replay-state. Приложение **не** получает TOTP secret и не проверяет TOTP локально.

Успех HTTP **200**: `{"status":"authenticated","access_token":"...","refresh_token":"..."}` (опционально `trusted_device`); неверный/устаревший/replayed код — никакой JWT. Требования к серверу: challenge однократный, MFA/Bitrix TOTP результат обязан быть подтверждён после commit; недостаточно простого локального вычисления TOTP-кода в приложении или синтетической проверки.

### 4.3 POST /api/v5/auth/challenge/resend

Только SMS challenge:

```json
{"challenge_id":"<current challenge_id>","device_id":"sz_<64 lowercase hex>","platform":"ios"}
```

Результат согласно текущему feature-контракту — HTTP **202**, `status:"challenge_pending"`, тот же `challenge_id`, `challenge_type:"sms"`, серверный `expires_in`. **Для TOTP кнопку «Отправить код» не показывать:** приложение-аутентификатор генерирует новый код само.

Для SMS использовать получателя **только из подтверждённого телефона аккаунта Bitrix**, не показывать и не отправлять мобильный номер в запросе. Исходная SMS-отправка — исключительно после подтверждённого первого фактора и server-side rate/budget guard; повторная — по действию пользователя, с охлаждением не менее 60 секунд, общим TTL challenge не более 5 минут, не более 5 ошибок, одноразовостью и anti-spam защитой. Не считать 202 доказательством фактической доставки SMS. Реальная native SMS begin/resend/provider интеграция **пока не работает на production**.

### 4.4 TOTP и доверенное устройство: экраны

При `challenge_type:"totp"`: поле 6 цифр, подтверждение, ошибки, повторная попытка; **без SMS fallback**, без кнопки «Получить SMS». `expires_in` ограничивает выдачу challenge, а не время обновления цифр внутри приложения-аутентификатора. При `challenge_type:"sms"`: 6 цифр, маскированная подсказка получателя только если сервер безопасно её выдал, cooldown повторной отправки, обработка ошибок/лимитов, **без поля ввода номера телефона**.

При доверенном устройстве с ответом HTTP 200 — сразу личный кабинет, TOTP/SMS экран не показывать. Во время токен-обмена сохранять access/refresh и доверие только в защищённом хранилище. Любое состояние `challenge_required` — **не авторизация**.

## 5. Сессии, PIN и ошибки

Текущий feature-контракт `POST /api/v5/auth/refresh`, `POST /auth/logout`, `POST /auth/token/validate` предусматривает отдельные V5 токены; V4 JWT нельзя использовать в V5. После успешной V5 сильной сессии `POST /api/v5/loyalty/pin` получает серверный RestIS PIN **без повторного SMS**; клиент не должен сам определять, достаточно ли MFA. Protected PIN в production пока OFF.

При любом блокировании/отзыве/изменении MFA/пароля/телефона backend должен перепроверять native `AUTH_EPOCH` / текущую сессию / доверие и не возвращать действительные токены при недоказанном состоянии. Не логировать password, trust_token, SMS/TOTP code, refresh/access токены и PIN. Не делать автоматических платных повторов SMS и не имитировать успех при 503.

## 6. Верифицированный статус TOTP на 23.10.2026

**Работает в действующем личном кабинете Bitrix:** серверная native TOTP проверка по штатному `Bitrix\Security\Mfa\Otp`, пилотный enrollment + вход + браузерное отключение ранее были пройдены на реальном аккаунте и задокументированы в `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`. Отключение TOTP также было ранее исправлено. Это **не** означает активный TOTP на пилотном аккаунте сейчас: read-only проверка 16.10.2026 показала `OTP_ROW_EXISTS=NO` после удаления TOTP, а V5 epoch-состояние отсутствовало (`docs/SAMZABERU_V5_REAL_PILOT_880339_READONLY_2026-10-16.md`).

**НЕ готов в API V5:** полноценная native `Otp::verify($code,true)` внутри атомарного V5 challenge consumption, persisted replay/epoch continuity, проверенная доверенная сессия для пользователей с TOTP, настоящий SMS provider и `AUTH_EPOCH` coverage всех 10 путей. Feature GitHub/CI использует synthetic/ephemeral MariaDB проверки, а установленный production `POST /api/v5/auth/signin` остаётся 503 (подтверждено оператором при успешной установке юридических ручек). Отдельный V5 TOTP secret никогда не создавать.

## 7. Параллельный план

**Разработчик:** сейчас подключает реально работающий публичный GET юридических документов и реализует UI состояний доверия/TOTP/SMS, используя приведённые JSON DTO как согласованный **целевой контракт**, НЕ как уже активированный API. Реальное сохранение согласий/регистрация и мобильный signin ждать до backend приёмки.

**Наш backend:** сначала заменить старую TOTP-before-trust политику в `AuthDecision.php`, `V5LoginWorkflow.php`, `V5MySqlStrongSessionStore.php` и зависимых тестах **с сохранением серверной strong enrollment, revoked/epoch guards**, затем закончить нативную TOTP verify+replay и SMS challenge, провести реальную Bitrix E2E. Пока мобильный разработчик делает UI, переносить из V4 профиль, корзину/заказы, бонусы и rest-интеграции без дублирования бизнес-логики. Последний публичный API — **только evrasia.rest**; V4 до миграции клиентов остаётся рабочим.

**Статусы:** `V5_LEGAL_PUBLIC_GET=PRODUCTION_ACCEPTED`; `V5_LEGAL_VALIDATE=ORIGIN_ACCEPTED`; `V5_AUTH=OFF`; `V5_TOTP_MOBILE_REAL_E2E=NOT_ACCEPTED`; `V5_TRUST_FIRST_POLICY=DECIDED_NOT_YET_CODE_ALIGNED`; `V4=TEMPORARY_COMPATIBILITY`.
