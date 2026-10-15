# Evrasia AI Bot / СамЗаберу V5 — START HERE / 15 октября 2026

**Главная точка продолжения. Новый чат — не новый проект.** Читать этот файл, текущий `SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md` и `SAMZABERU_V5_INTEGRATION_DECISIONS_2026-10-15.md`. Предыдущая полная редакция сохранена в commit `2bec823b26087446601c59160dcb567cb2bb02de`.

## 1. Подтверждённое решение о размещении

**Отдельной VM / нового сервера не будет. V5 работает ПАРАЛЛЕЛЬНО V4 на существующем Bitrix-сервере `evrasia`.** Контейнерная копия сайта, второй MySQL instance и второй набор клиентов не обязательны. Не просить новую VM и не возвращать отменённый инфраструктурный blocker.

Цель: `/api/v4/…` сохраняется; `/api/v5/…` подключается рядом. Аккаунты и бизнес-данные общие; ключи, токены, служебные таблицы, конфигурация и выключатель V5 отдельные. Бот на `eur-bot-01` не переносится. Размещение V5-таблиц в существующей БД допустимо, но миграция ещё НЕ выполнена.

Общая БД сохраняет требования к security events: смена пароля, MFA, телефона, блокировка и logout через сайт/V4 должны корректно инвалидировать V5. Решение о размещении не разрешает произвольные production DDL, сбросы аккаунтов, платные SMS или fault injection. Конкретные изменения требуют точной цели, backup, допуска, post-check и rollback. Старые CI-only/fault guards не отключать.

## 2. Актуальный код и CI

Repo `juvantusik/Evrasia_AI_bot`, branch **`feature/samzaberu-api-v5-auth-contract`**.

**Последний code/test commit с двумя успешными CI: `0caa0248c783188939398a71543ba740f064bb90`.**

- Offline Actions **38750965561**, job **116476933149**: SUCCESS. Лог прочитан: **86 PHP lint, 26 approved suites, прежние 198 parallel-entry checks и 59 новых private-pilot checks**, FINAL_STATUS=PASS, FINAL_RC=0. Deployment shim дополнительно исполнялся дочерними PHP-процессами; он вне src/tests/api lint manifest.
- Ephemeral MariaDB Actions **38750966026**, job **116476934579**: SUCCESS, все шаги завершены. Состав DB suite не менялся: **43+117+45+63+61=329 проверок**. Новые 59 относятся к offline, не к DB. В этой итерации проверен статус MariaDB job; счётчики неизменённого набора ранее подтверждены его логом, новый полный MariaDB лог повторно не выгружался.
- Локально lint четырёх новых PHP-файлов PASS; SHA двух загруженных больших blobs совпали с локально проверенными файлами. Полный локальный suite не запускался: git clone недоступен из-за DNS. Полная регрессия выполнена GitHub Actions.
- Compare `2bec823b..0caa0248`: пять новых файлов (private loader, site entry, public shim template, off JSON example, test) и одна строка подключения test в RouteMap suite. Ни V4, ни действующий router/transport, ни native providers, ни SQL schemas, ни workflow не менялись.
- Более поздние HEAD с документацией не являются новым кодовым инкрементом. API README входит в watched paths и может вызвать offline CI даже при doc-only commit; это не новые функциональные изменения.
- Main по последнему live read: `7c2e9f8e4501b92469bcdeea18f8340676ad52c9`. Не трогать: push main вызывает build/publish latest без paths-фильтра. PR этой feature при проверке отсутствовал.

## 3. Новый private pilot bootstrap — готов в feature, НЕ установлен

Файлы относительно `site-integration/samzaberu-v5/`:

- `src/V5PrivatePilotConfig.php`: bounded JSON-конфигурация из фиксированного private release вне docroot. Только mode=pilot, отдельный SHA256 случайного pilot key, время окончания не дальше 24 часов, 1–16 точных peer IP. Mode=off, отсутствие/ошибка данных, небезопасные права, ссылки или истёкший срок дают отказ. Конфигурация не запускает PHP factory.
- `api/v5/site.php`: проверяет допуск до передачи запроса existing router; не наследует ранее зарегистрированную global factory; удаляет pilot header из PHP $_SERVER до native загрузки. Отклонённый запрос остаётся503 без body read/Bitrix bootstrap.
- `deployment/public-router.php`: **шаблон**, не установленный public файл. Будущий guarded installer подставляет два фиксированных пути: private release и docroot. Он должен подключать private `api/v5/site.php`, не обходить его прямым подключением package router.
- `deployment/site-config.example.json`: только выключенный пример, не реальные credentials.
- `tests/V5PrivatePilotConfigTest.php`: 59 checks через прежний RouteMap suite, включая реальные дочерние PHP executions public shim → site.php → router.php.

Pilot header `X-Evrasia-V5-Pilot` — только технический допуск к пилотным запросам. Он **не заменяет** логин/пароль, TOTP/SMS/trust, epoch/coverage или согласование конкретных тестовых аккаунтов. В БД ничего не создаёт. Production key не генерировался. Не включать такой ключ в публичную сборку приложения, URL, GitHub или логи. Behind-proxy allowlisted peer не считается личностью: ключ остаётся обязательным; Forwarded/X-Forwarded-For не используются для допуска, HTTPS не берётся из X-Forwarded-Proto.

Конфигурация и factory заново проверяются перед запуском factory: смена mode/hash/permissions/срока запрещает её вызов. Это не откат уже начавшегося запроса и не защита от враждебного владельца PHP-файлов; нужны trusted immutable releases/атомарное переключение и правильные web aliases/права предков.

**`site-factory.php` с настоящей native composition всё ещё НЕ реализован и НЕ установлен.** Он должен возвращать существующий `V5HttpTransportBoundary` с проверенными providers. Тестовый empty transport остаётся503 и не является готовым backend. Настоящий pilot не включался. Apache/Nginx/TLS и live coexistence этими CLI-тестами не проверены. Подробный контракт: `api/v5/README.md`.

## 4. Принятая бизнес-логика — без изменений

Логин/пароль без CUser::Login и V4-cookie; текущие active/block/expiry/Anti-Fraud и лимиты попыток. Native TOTP имеет приоритет без SMS fallback при ошибке/unknown. Без TOTP допускается только сильный V5 trust, не слабый V4. Без обоих — password-bound SMS на подтверждённый номер аккаунта, без номера с клиента. После сильного входа PIN без повторной SMS.

Сохраняются десять SMS rate/стоимостных окон, cooldown/лимиты ошибок, HMAC и encrypted outbox, binding к identity/device/phone/epoch, тариф/currency/idempotency. Claimed/unknown не отправлять автоматически повторно. SENT требует независимого receipt; late receipt не оживляет challenge и не меняет бюджет. PHONE_EVENT_VERSION меняется и при повторе того же E.164; AUTH_EPOCH атомарен с native security mutations. V4/UI/API-контракты не перепроектировать.

## 5. Ближайший шаг на существующем сервере

Владелец сайта из истории — `site_evrasia`; docroot `/home/site_evrasia/web/evrasia.spb.ru/public_html`. В истории также есть private parent `/home/site_evrasia/web/evrasia.spb.ru/private/`. Использовать этот известный путь как цель проверки размещения, не придумывать новую инфраструктуру. Восстановленная история указывает nginx:80/443 → Apache:8080 и несколько PHP-FPM версий; это исторические факты, не новая аттестация текущего web runtime.

Подготовлен в текущем чате **один** read-only Bash-блок перед установкой: root/host/docroot/V4 guards, метаданные private targets, SHA только entry/rewrite файлов, версии CLI PHP без ini и безопасные поля конкретного FPM pool. Никаких CUser/PhoneAuth probes, подключения БД, загрузки Bitrix, HTTP/SMS или изменения сайта. Локально bash -n и отказ на неправильном host проверены. Результат с evrasia ещё НЕ получен; не выдавать его за live preflight PASS.

После результата — подготовить конкретную установку **выключенного** private release + V5 public shim с backup/проверкой/rollback и без изменения V4/root rewrite. Не включать pilot без реальной factory и необходимых native proof. Additive V5 DDL — отдельное согласованное действие с backup в существующей БД, не запуск CI fixtures на production. Не повторять эту подготовку и не заказывать VM.

## 6. Реальные незавершённые интеграции

Native TOTP-code/replay, полное security-event coverage, successful phone verification/reconfirmation writers, strong trust, SMS password-bound begin/resend, paid provider/receipt/tariff и RestIS PIN ещё не приняты/не полностью подключены. Production-appropriate transaction permit нужен отдельно от старого CLI staging/fault permit; его guards не снимать механически. Ограничение тестовых аккаунтов остаётся отдельным условием native factory.

`V5BitrixLockedPasswordTotpProof` готов как fresh BEGIN recheck с прежним epoch HMAC. `V5BitrixLockedTotpFactor` из pending-документа — неподтверждённый черновик, не в src/CI. Предыдущий заблокированный тест не повторялся и не включён в текущий инкремент.

## 7. История и правила продолжения

Закрытые production исследования не повторять. Исторические handoff/checkpoint/STRUCTURE_REGISTRY, phone-native-writer и MFA-recovery документы сохранены. CUser delete/add не доказаны как successful CONFIRMED writer; SHA-drift user.php не объяснён, baseline не утверждать автоматически. Native recovery/useCode/save/delete/background mutations не объявлять атомарными по одному return true.

Исторические code checkpoints: `029aaff` (198 entry checks,329 DB), `c3f92ba` (329 DB), `8c395f0` (268), `dc2caed` (205), `4a6ea09` (160). Первый receipt run38736335930 FAIL из-за fixture ordering сохранён в истории.

**Статусы:** OFFLINE_TESTED=PASS; REAL_EPHEMERAL_MARIADB_PASS=YES(329); PRIVATE_PILOT_BOOTSTRAP_OFFLINE=PASS(59); REAL_SITE_PARALLEL_ACCEPTANCE=NOT_RUN; PRODUCTION_DEPLOYED=NO. HTTP503 — исторический baseline, нового HTTP-теста не было. Production/V4/SQL/SMS этой работой не менялись.

Серверные операции: один guarded copy-paste block, syntax check, FINAL_STATUS/FINAL_RC, без secrets/PII, SSH не закрывать. Безопасные feature/CI шаги выполнять самостоятельно. Приоритет: production fact > live feature > реальные испытания > актуальная документация > старые обсуждения.
