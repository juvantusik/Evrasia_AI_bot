# СамЗаберу / API V5 — проверка access JWT и публичные согласия (18.10.2026)

> **Статус: feature-only HTTP contract, НЕ production, не выдавать разработчикам как работающий публичный API.**
> Новые методы выполняются только при отдельном trusted V5 HTTP transport + инъекции проверенных native providers. Установленный site `/api/v5/router.php` по умолчанию остаётся выключенным (`503 v5_unavailable`); V4 не изменяется.

## Решение пользователя

Обе запрошенные разработчиком ручки реализуются **сразу в API V5**, без временной реализации в V4:
- проверка **действующего access JWT V5**;
- получение актуальных юридических документов/кодовых версий **без JWT для первой регистрации**.

Коды, тексты и требование обязательности документов всегда берём из серверного источника; мобильное приложение не содержит зашитых значений. Согласие не записываем анонимным вызовом с произвольным USER_ID.

## 1. POST /api/v5/auth/token/validate

**Content-Type:** `application/json`; HTTPS, no-store; метод POST.

Тело:
```json
{"access_token":"<V5_ACCESS_JWT>"}
```
Успех **200**:
```json
{"valid":true}
```
Недействующий, просроченный или отозванный токен **401**:
```json
{"valid":false}
```

Неверная структура JSON/лишние поля — **400 `invalid_request`**. Отсутствующий trusted backend — **503 `v5_unavailable`**, сбой backend — **503 `temporarily_unavailable`**.

*Валидация не ограничивается разбором подписи JWT.* `V5MySqlTokenValidation` повторно использует серверный `V5MySqlStrongSessionStore::currentAssuranceSnapshot()`: HS256 issuer/audience/expiry/access-only, активная сессия, действующий пользователь, `AUTH_EPOCH`, подтверждённый отзыв, требуемый уровень подтверждения. В ответе нет `user_id`, списка разрешений или причин отзыва. **V4 JWT и refresh-токен V5 отвергаются.**

Вся реальная проверка текущего Bitrix состояния требует внедрения и аттестации native V5 providers, так что сейчас это ещё не работающая снаружи ручка.

## 2. GET /api/v5/legal/consents

**Публичный read-only каталог, без JWT и без связи с пользователем.** HTTPS; GET без тела и без query string. Обязательно `Cache-Control: no-store`.

**200** — точные текущие ревизии и тексты/ссылки сайта:
```json
{
  "documents": [
    {
      "code":"<CURRENT_OFFER_CODE_FROM_SERVER>",
      "type":"offer",
      "title":"<CURRENT_TITLE>",
      "required":true,
      "format":"url",
      "content":null,
      "url":"https://evrasia.spb.ru/legal/public-offer/"
    },
    {
      "code":"<CURRENT_PD_CODE_FROM_SERVER>",
      "type":"personal_data",
      "title":"<CURRENT_TITLE>",
      "required":true,
      "format":"url",
      "content":null,
      "url":"https://evrasia.spb.ru/legal/personal-data-consent/"
    },
    {
      "code":"<CURRENT_MARKETING_CODE_FROM_SERVER>",
      "type":"marketing",
      "title":"<CURRENT_TITLE>",
      "required":false,
      "format":"url",
      "content":null,
      "url":"https://evrasia.spb.ru/legal/marketing-consent/"
    }
  ]
}
```
Текст в угловых скобках — **заполнитель контракта, не настоящее значение**. Backend берёт реальные актуальные `code/title/content/url` из доверенного сайта, без hardcode. `format` может быть `url`, `html` или `text`; при `url` поле `content=null`, иначе содержит серверный текст соответствующей редакции. Каталог не имеет `accepted`/USER_ID или других персональных флагов.

На отсутствующем backend/недействительных документах — **503**, без раскрытия внутренних данных. Никаких API-ключей, cookies, SMS, JWT и записи БД для чтения не требуется. В feature проверяются форма ответа, разрешённые типы документов и ссылки, но **реальный production Bitrix-каталог ещё не подключён**.

## 3. Согласия на регистрации — не отдельная анонимная запись

**НЕ создавать** анонимный `POST /legal/consents/accept` с клиентским `USER_ID`. Существующая согласованная бизнес-логика:

1. Приложение делает публичный `GET /api/v5/legal/consents` и отображает все актуальные обязательные/необязательные документы.
2. Пользователь принимает оферту и ПД; маркетинг отдельно и только добровольно.
3. При будущем **V5 signup** клиент отправляет актуальные выбранные `code` в поле `agreements` одновременно с данными регистрации, **без JWT**.
4. Backend непосредственно перед записью заново загружает текущие документы и проверяет `V5LegalConsentContract::acceptedForSignup(...)`: неизвестные/устаревшие/дублированные коды, пропущенные обязательные согласия — отказ; маркетинг нельзя проставить автоматически.
5. После создания настоящего Bitrix USER_ID сервер записывает событие `Bitrix\Main\UserConsent\Consent::addByContext(...)` в действующую `b_consent_user_consent` и подтверждает фактическую запись. Никакого `UF_SMS` как замены versioned legal consent.
6. Изменение документа между отображением и submit -> отдельный явный `documents_changed`, приложение запрашивает каталог повторно.

**Техническая граница:** сейчас в V5 нет разрешённого работающего `/signup` или trusted native Bitrix consent-writer. Чистая проверка кодов добавлена в feature; операция регистрации+native-consent persistence ещё не реализована/аттестована и поэтому не выставлена HTTP-ручкой. Старый V4 `/signup` сохраняется без изменений.

Для существующего авторизованного пользователя будущие актуализации согласий должны быть с V5 JWT и current-user серверной привязкой, а не anonymous accept. Это отдельный защищённый маршрут, не часть публичного GET.

## 4. Защита и тесты

Feature files:
- `src/V5RouteMap.php`
- `src/V5AuthHttpHandler.php`
- `src/V5HttpTransportBoundary.php`
- `src/V5ParallelHttpEntry.php`
- `src/V5MySqlTokenValidation.php`
- `src/V5LegalConsentContract.php`
- `tests/V5PublicContractsTest.php`

Обязательные негативные случаи: подделанный/V4/refresh/просроченный/отозванный JWT, неверная форма, неработающий provider; подмена публичных документов, попытка вывести чужой USER_ID/accepted, HTTP вместо HTTPS, запрос с query/body; устаревшая версия согласия, дублирование и пропуск обязательного, неявный маркетинг, выключенный V5.

Доказательством production может быть только отдельная приёмка с настоящим site Bitrix (включая current-doc provider, регистрацию/Consent::addByContext и V5 native AUTH_EPOCH). Синтетический CI **не** заменяет эту приёмку.

## 5. Дальше до выдачи разработчику живых URL

1. Дождаться `V5 offline safety` CI на HEAD со всеми новыми негативными тестами.
2. Подключить native server-side каталог действующих соглашений и сверить фактическую versioning-схему Bitrix/сайта без реальных пользовательских данных.
3. Реализовать **V5 signup+consent writer** в согласованном транзакционном контуре, включая подтверждение факта native consent event.
4. Аттестовать нативную V5-сессию/отзыв/epoch перед выдачей реальной ручки проверки токена.
5. Отдельный guarded V5 staging/pilot deployment и точная E2E приёмка. Только после этого сообщать разработчику, что endpoints **работают**, а не просто описаны.

`PRODUCTION_WRITE=NO`; `V4_WRITE=NO`; `V5_HTTP_ACTIVATED=NO`; `LIVE_SMS_SENT=NO`; `GITHUB_FEATURE_ONLY=YES`.
