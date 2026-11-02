# СамЗаберу / API V5 — production checkpoint на 02.11.2026

**Статус:** фактические операторские результаты production до 02.11.2026.  
**API:** https://evrasia.rest/api/v5  
**Сервер:** evrasia; сайт: /home/site_evrasia/web/evrasia.spb.ru/public_html.  
**Исходники GitHub:** feature/samzaberu-api-v5-auth-contract; HEAD проверен на 02.11.2026: 3153ce9218fa653a139c2308e66b53a98a9bc67c.  
**Полная спецификация для разработчика:** [SAMZABERU_API_V5_FULL_SPECIFICATION_2026-11-02.txt](SAMZABERU_API_V5_FULL_SPECIFICATION_2026-11-02.txt).

> **Это новый приоритетный checkpoint для СамЗаберу.** Старые GitHub-документы от 12–27 октября описывают предшествующие стадии (в том числе «V5 выключен»/HTTP 503). Они не описывают состояние production на 02.11.2026. Production-код меняли операторскими скриптами; наличие файла в feature GitHub само по себе НЕ доказывает идентичности актуальному production-файлу.

## 1. Задача и правила проекта

**Сейчас делаем и предоставляем рабочие HTTP-ручки API V5 разработчику приложения «СамЗаберу», а не бесконечно проверяем проверенное.** Переносим существующую бизнес-логику V4 с минимальными изменениями и отдельным сильным контуром авторизации V5. **API V4 в production не изменять без отдельного согласования.** Не создавать новые механизмы, если нужное уже работает; не создавать синтетических пользователей. Для защищённых сценариев — реальная V5-сессия; V4 JWT не подходит.

Клиент самостоятельно генерирует и хранит device_id и trust_token; сервер НЕ выдаёт trust_token. Формат device_id: sz_ + 64 lowercase hex; platform: ios/android. Регистрация возвращает next_step=email_confirmation; письмо подтверждается **ссылкой в браузере**, а не придуманной новой ручкой мобильного API. Согласия регистрации передаются вместе с POST /auth/signup и сохраняются за новым Bitrix user.

**Не входят в СамЗаберу:** QR заказа (/qr_order/*), специальный deeplink / возврат в приложение после оплаты, отдельная клиентская /auth/token/validate. Эти механизмы не добавлять в рамках V5.

## 2. Легенда статусов

- **✓** Подтверждённый положительный ответ соответствующего сценария на production; это не гарантирует проверку всех веток.
- **◐** Реализация или маршрут есть, но подтверждён только отказ, перенос кода, отдельный DTO или неполный сценарий.
- **✗** Реальная заглушка/отсутствующая бизнес-операция.
- **—** Не относится к интеграции СамЗаберу.

HTTP 400/401/405, PHP lint, наличие маршрута и копии V4 **не равны** доказательству успешной бизнес-операции. Не делать фиктивные SUCCESS-отметки.

## 3. Подтверждённая работа API V5

### 3.1 Сервис, публичные данные, legal

- ✓ GET /health — 200 JSON без JWT; после исправления api/v5/.htaccess и fallback на router.php исчез blanket 403, неизвестный путь даёт 404 JSON.
- ✓ GET /menu — 200 JSON без JWT; в одном из live-ответов 232 товара / 32 категории.
- ✓ GET /restaurants — 200 JSON без JWT; в live-ответе 59 ресторанов.
- ✓ GET /actions — 200 JSON без JWT; в live-ответе 11 акций.
- ✓ GET /restaurants-stop-order — 200 JSON без JWT; сравнение с V4 давало parity PASS.
- ✓ GET /system_notifications — 200 JSON без JWT; совпадение с V4 подтверждено.
- ✓ GET /legal/registration-documents и GET /legal/consents — 200; документы до регистрации, включая обязательные, доступны публично, персональные accepted/acceptance_required — с V5 JWT.
- ✓ POST /legal/consents/validate — подтверждена валидация актуальных обязательных кодов (успешный запрос) и отказ для постороннего кода; НЕ помечать «✗ маршрут».
- ◐ POST /legal/consents/accept — HTTP 200 с JWT и повторным принятием; запись **нового** согласия отдельным тестом не подтверждалась.

### 3.2 Регистрация, вход, MFA, доверие

- ✓ POST /auth/signup — реальный Bitrix user, 201 registered, next_step=email_confirmation, ACTIVE=N до подтверждения; NEW_USER_CONFIRM; после перехода из письма пользователь входит отдельно.
- ✓ POST /auth/signin — 202 challenge_required и 200 authenticated для подтверждённого доверенного устройства.
- ✓ POST /auth/totp/verify и POST /auth/email/verify — реальные успешные сценарии 200 с выдачей V5 access/refresh. Email OTP входа не путать с подтверждением email после регистрации.
- ✓ POST /signin/mobileid, POST /signin/mobileid/status, POST /signin/mobileid/code — успешные сценарии Mobile ID, AUTH и JWT/refresh; возможна NEED_SMS_CODE.
- ✓ POST /auth/logout — 200, отзыв текущей V5-сессии.
- ◐ POST /auth/sms/verify, POST /auth/challenge/verify, POST /auth/challenge/use-sms, POST /auth/challenge/resend, POST /auth/refresh — 02.11.2026 **все пять** зарегистрированы в V5RouteMap/handler и возвращали 400 application/json {"error":"invalid_request"} на пустом JSON. Положительная ротация refresh или отправка SMS/email **не проверялась**. Результат скрипта PASS_COUNT=5, FAIL_COUNT=0, FINAL_RC=0.
- ◐ POST /auth/trust/validate и /auth/trust/revoke — зарегистрированы, V5 session gate; пустой запрос без требуемых данных даёт 400 JSON. По существующему контракту validate использует device_id, platform, trust_token, revoke — device_id и platform; положительные DTO trusted/revoked установлены по коду, но успешный live вызов не подтверждён.
- — POST /auth/token/validate не требуется клиенту.

### 3.3 Профиль и бонусная программа

- ✓ GET /profile и GET /notifications — 200 с действительным V5 JWT.
- ◐ POST /notifications — 200 с JWT и существующими настройками SMS/email; изменение состояния не доказано отдельной проверкой.
- ◐ POST /edit-profile/, POST /profile/change_email_request, POST /profile/change_email_confirm, DELETE /profile — валидация или отказ подтверждены; не проводить изменение чужих/реальных пользовательских данных ради статуса.
- ✓ GET /loyalty/cards — 200 с JWT, поле cards. Из существующего getCards() известны cardIdInBX, type, title, name, cardNumberInRestis, created, status, picture; отдельный QR/штрихкод не подтверждён.
- ✓ GET /loyalty/balance — 200 с JWT, поле bonus. Значение читается из синхронизируемого Bitrix UF_BONUS, это не онлайн-SELECT из RestIS при каждом GET.
- ✓ POST /loyalty/pin — 200 с JWT, четырёхзначный pin_code для списания бонусов.
- ✓ POST /restaurant-discount — 200 с JWT, четырёхзначный checkin для предъявления карты, с обязательным REST_ID выбранного ресторана; проверено на REST_ID=300 как примере, не константе.
- ◐ POST /loyalty/cards/add — 400 с JWT на дубликат карты/типа; успешная активация новой карты не подтверждена.
- ◐ POST /pincode — совместимый прежний путь; использовать для новой интеграции /loyalty/pin.

### 3.4 Корзина и checkout

- ✓/◐ GET /cart и гостевые GET /cart/, GET /cart/id — production возвращал 200; есть отдельные ветки авторизованной и гостевой корзины. Полный DTO каждой ветки не объявлять одинаковым.
- **Исторический положительный guest E2E (27.10):** POST /cart/add, /cart/plus, /cart/minus, /cart/remove — HTTP 200 на отдельной реальной гостевой FUSER-корзине, товар добавлялся, количество менялось 1→2→1, затем удалялось, V4/V5 parity PASS; это было до последующих V5-правок. Не подменять этим подтверждение текущей версии после последующих развёртываний.
- ◐ POST /cart, POST /cart/, POST /cart/add|plus|minus|remove — V4 guest handlers перенесены в V5; positive E2E **после последних правок** не проводился.
- ◐ POST /cart/items/add|quantity|remove — отдельный V5 JSON-контракт; quantity/remove пустым запросом дают 400 JSON. Семантика абсолютного quantity не заменяет относительные plus/minus.
- ◐ POST /cart/gifts/add|remove, POST /cart/apply_sale, POST /cart/clear — маршруты и штатные отказы 400; успешная мутация не подтверждена.
- ◐ POST /cart/order и POST /order — checkout/business перенесён; в V5 без JWT корректный отказ 401; **реальный заказ не создавали**. Гостевой cart_id сохраняется до авторизации и checkout.
- Для checkout СамЗаберу **не требуется redirect_url/возврат в приложение**; не добавлять. Не проводить real checkout ради smoke-теста.

### 3.5 Заказы и история бонусных карт

- ✓ GET /orders — 200 с JWT. Активный V5 router включает private/samzaberu-v5/business/orders/index.php; передаёт verified V5 user id, а не V4 JwtAuthorization. Реальный UserOrder::getOrders() возвращает элементы в data и meta.total/meta.page. Поля: no, human_no, amount, status, restaurant_address {lat,long,title}, order_date_time, pickup_time, prior_notification_enabled, rating, pay {url,expire}|null.
- ◐ GET /orders/{id} — активный private V5 detail handler проверяет V5 identity; UserOrder::getOrder() формирует data с теми же полями + items[{product_id,title,amount,qty,images:{original,icon,desktop}}]. Положительный HTTP E2E конкретного заказа не проводился; 404 в коде предусмотрен.
- ◐ POST /orders/detail — альтернативная JSON-операция; успешный production E2E не подтверждён.
- ◐ POST /orders/{id}/evaluate — существующий обработчик принимает form-urlencoded score (1–5), message (необязательно); только выполненные заказы.
- ◐ POST /orders/{id}/cancel — без POST-полей; отмена разрешена только в статусе L; успешную отмену не проводили.
- ◐ POST /orders/{id}/pay — type=sbp по умолчанию; существующий код СБП, ответ ожидает data.url и data.expire. Успешная реальная оплата не проводилась. Старый deeplink в исходном V4-коде **не является требованием СамЗаберу**.
- ◐ POST /orders/{id}/repeat — бизнес getOrder/repeatOrder перенесён; без JWT корректный 401 JSON, не создавали повторный заказ.
- **✗ GET /orders/{id}/push — единственная подтверждённая заглушка.** В V3/V4/V5 push.php три строки: выводит текст "on/off push", не обновляя PROPERTY PUSH_ID=16; не использовать клиентом.
- ◐ POST /history/update — присутствует V5 router, отдельный V5HistoryBridge, класс EvrasiaV5HistoryBusiness **наследует** UserController::updateHistoryNew(). Он обновляет историю бонусных карт, **не историю заказов и не «историю профиля»**. POST-полей нет; код возвращает data.new_items, updated_at, html, cards. Успешный реальный update не проводился; возможные неинициализированные html/cards в общем методе — отдельный технический риск.

## 4. Известные проблемы без новых правок кода

1. Реальная заглушка push (описана выше). Поле prior_notification_enabled уже присутствует в ответах заказов, но отдельный рабочий toggle отсутствует.
2. Общий UserOrder::getOrder(): статус использует $arOrder['CANCELED'] до инициализации массива вместо $order['CANCELED']; при нулевом id подозрительная опечатка OrderNotFouundException. **Общий V4/V5 класс не менять без отдельного решения.**
3. DTO бонусной карты не гарантирует QR/штрихкод; не выдумывать поля.
4. POST /history/update: успешный E2E и заполнение html/cards не подтверждены.
5. Внешняя JSON-обёртка ApiResponse при разных операциях может отличаться; не выдавать внутренние PHP array keys за подтверждённый фактический HTTP DTO без успешного запроса.
6. На 02.11 успешная ротация refresh и многие POST-mutations остаются непроверенными в реальном пользовательском сценарии. **Это не означает, что они отсутствуют.**

## 5. Продолжение и ограничения

- Приоритет: разработчик СамЗаберу интегрирует нужные V5 endpoints; при ошибке присылает метод, путь, HTTP status, без секретов — входные поля и обезличенный ответ. Исправлять именно подтверждённый сбой, без повторной тотальной ревизии.
- Не менять production V4. Не переписывать существующие V4 бизнес-алгоритмы в V5. Не разрабатывать QR заказов, app deeplink, /auth/token/validate или новые SMS/OTP механизмы без явного запроса.
- Последний блок диагностик **read-only**: успешная проверка 5 auth routes; read-only просмотр UserOrder, четырёх private order handlers, заглушки push и V5HistoryBridge. Все дали FINAL_STATUS=PASS, FINAL_RC=0; production-код/БД этими проверками не менялись.
- Перед любым терминальным скриптом **очищать экран и scrollback** первой командой: printf '\033[3J\033[2J\033[H'. Один цельный copy-paste блок; guards root/hostname/environment, backup до write, rollback на ошибке, явные MODE/WRITE и FINAL_STATUS/FINAL_RC, не выводить credentials.
- **Разграничение источников:** факт production > актуальный GitHub feature > staging > документы > старые обсуждения. В GitHub feature могут отсутствовать фактические production hotfixes; этот checkpoint НЕ является утверждением о точном SHA production файлов.
- Сопутствующие ветки проекта (веб-сайт с email-first MFA/Mobile ID, bonus outbox для RESTISBONUS) вести отдельно и не объединять их изменения с этой публикацией документации V5.

## 6. Исторический контекст

Решение пользователя: **БЫЛО** — строить альтернативные V5 бизнес-сервисы/изолированные контракты; **СТАЛО (27.10)** — сначала переносить проверенную V4 бизнес-логику, подключая независимую авторизацию V5. **Причина** — своевременно предоставить работающий API разработчику СамЗаберу. Последующий production-роллаут включает исправление V5 rewrite (30.10), public catalog, legal, real signup, Mobile ID, loyalty/checkin, auth and order handlers. Новых production правок в рамках текущей публикации docs нет.

**Источники:** фактические operator stdout и чтение production PHP, предоставленные в чатах проекта до 02.11.2026; полная спецификация (текстовая версия) по ссылке в начале этого документа.
