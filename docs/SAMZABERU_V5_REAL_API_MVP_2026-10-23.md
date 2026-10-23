# СамЗаберу API V5 — реальные HTTP-методы, без тестовых аккаунтов (23.10.2026)

## Выбор реализации

Разрабатывается **один настоящий API V5** в уже принятом Bitrix/HTTP контуре:
\`https://evrasia.rest/api/v5/\` — адрес для мобильного клиента; \`evrasia.spb.ru\` — сайт Bitrix.
Никакого отдельного mock API, синтетических пользователей, клиентского IP allowlist или выдачи поддельных JWT. Существующий V4 не меняется.

Рабочий сервер \`evrasia\` уже имеет приватно установленный V5 OFF-only bootstrap; **публичная V5 авторизация там пока выключена**. Подготовленный в GitHub код сам по себе не устанавливает обновления в production. При разработке SDK отличать **закреплённый контракт** от **реально включённой ручки**.

## Уже установлено и проверено на реальном origin 2026-10-23

- \`GET /api/v5/health\` — 200 \`{"status":"online","service":"samzaberu-v5","auth_ready":false}\`. Реальный статус, а не разрешение входа.
- \`GET /api/v5/legal/consents\` — 200, \`{"documents":[...]}\`; возвращает текущие три документа Bitrix: offer, personal_data, marketing. Коды и текст **получать с сервера**, не закреплять в приложении. Без JWT, без cookie, no-store.
- Внешняя проверка именно \`evrasia.rest\` с мобильной сети ещё не принята; локальный hairpin внутри \`evrasia\` не использовать в качестве индикатора доступности.

## Новые настоящие V5 методы, код подготовлен в текущей feature-ветке

### POST /api/v5/legal/consents/validate — независим от AUTH

\`Content-Type: application/json\`, HTTPS, без JWT, без query string, тело до 8192 байт:

\`\`\`json
{"agreements":["<current_offer_code_from_GET>","<current_pd_code_from_GET>"]}
\`\`\`

Маркетинговый код отправляется **только по добровольному выбору пользователя**. Сервер заново читает текущий Bitrix \`b_consent_agreement\` через уже работающий \`V5BitrixPublicLegalCatalog\` и обрабатывает \`V5LegalConsentContract::acceptedForSignup\`; не доверяет соглашениям из HTTP как серверному списку.

- **200** \`{"valid":true,"agreements":[...серверный_порядок...]}\` — коды действительны на момент проверки.
- **409** \`{"error":"documents_changed"}\` — отсутствует обязательная действующая редакция либо код устарел/не относится к текущему каталогу; приложение повторно вызывает GET.
- **400** \`{"error":"invalid_request"}\` — JSON/типы/лишние поля/дубликаты/неправильные значения.
- **415** \`{"error":"unsupported_media_type"}\` — недопустимый Content-Type.
- **503** \`{"error":"temporarily_unavailable"}\` — неизвестный или недоступный актуальный каталог.

Это **только read-only проверка**. Метод НЕ регистрирует пользователя, НЕ записывает согласие, НЕ подтверждает принятые оферты задним числом. На окончательном POST /signup сервер должен **повторить проверку** и записать собственные native Bitrix события согласия атомарно с созданием пользователя. Публичный валидатор можно подключить отдельно от незавершённой V5 авторизации, без ослабления MFA или токенов. **Фактический внешний deployment нового POST пока не выполнен**, поэтому не объявлять его работающим live до HTTPS smoke.

### POST /api/v5/auth/token/validate — защищённая проверка V5 JWT

\`\`\`json
{"access_token":"<real_v5_access_jwt>"}
\`\`\`

\`200 {"valid":true}\`, \`401 {"valid":false}\`; неверная структура — 400, отсутствующий backend — 503. Проверяется действительная серверная сессия, текущий AUTH_EPOCH, отзыв и assurance, а не только подпись. Реальный \`V5MySqlTokenValidation\` теперь подключён в pilot composition к настоящему \`V5MySqlStrongSessionStore\`. **На production AUTH OFF**, токенов пользователям ещё не выдаём.

### POST /api/v5/auth/logout — отзыв конкретной V5-сессии

\`\`\`json
{"access_token":"<real_v5_access_jwt>"}
\`\`\`

\`200 {"status":"logged_out"}\` — только после успешного server-side отзыва V5-сессии; \`401 {"error":"authentication_failed"}\` — неверный/отозванный/неподтверждённый JWT; \`400 {"error":"invalid_request"}\` — неверная форма, лишние поля (особенно \`user_id\`); \`503\` — отсутствующий или недоступный нативный session provider.

Реальная реализация \`V5MySqlSessionLogout\` использует **существующий** \`V5MySqlStrongSessionStore::revokeAccess()\` с локами пользователя/сессии, epoch и проверкой покрытия native hooks. Никакого V4 logout, массового выхода других аккаунтов или отдельного тестового пользователя. Провайдер теперь включён в public и pilot feature-композиции, **но не активирован в production**.

### Остальные согласованные V5-маршруты

- \`POST /auth/signin\` — настоящий Bitrix login/password + нативные TOTP/доверенное устройство/SMS; сейчас gated, пока отсутствуют positive native site-policy, security-epoch и SMS acceptance.
- \`POST /auth/challenge/verify\`, \`POST /auth/challenge/resend\` — native challenge lifecycle, SMS на подтверждённый телефон аккаунта; публичный production пока gated.
- \`POST /auth/refresh\` — проверенная V5 server-side сессия, одноразовая ротация.
- \`POST /loyalty/pin\` — только сильный V5 JWT, без повторной SMS, native PIN reader; production пока gated.

Клиент мобильного приложения может реализовывать DTO, запросы, статусы и UI **уже сейчас по реальному контракту**, но не считать ещё закрытые AUTH-ручки live. В production не устанавливать \`site-factory.php\` и не переключать AUTH без native security acceptance.

## Доказательства и выпуск

1. Код, тесты и contract-checks: GitHub \`feature/samzaberu-api-v5-auth-contract\`, проверять HEAD и CI.
2. Новые public POST и logout пока **feature code**, deployment и real Bitrix runtime smoke — отдельная guarded итерация. Ни один production data/user/SMS writer здесь не включён.
3. Сначала проверить реальное наличие текущего OFF-only release/immutable checksums, существующий snapshot и backup; потом только точечный V5-only upgrade с rollback. Не затрагивать \`api/v4\`, native PHP/Bitrix, SQL schema, consent history и платные SMS.
4. Native MFA/PIN/AUTH_EPOCH gates для выдачи реальных JWT должны оставаться обязательными; частичная безопасность не является основанием включить вход в реальные аккаунты.
