# Evrasia AI Bot / СамЗаберу V5 — START HERE / 15 октября 2026

**Текущая точка: V5 установлен на существующем сервере в режиме OFF. Первая HTTPS-проверка остановилась на CURL_RC=7, до HTTP-ответа. Исправлен только адрес соединения проверочного скрипта.** Установку и layout preflight не повторять. Следующий ввод — результат того же HTTP helper с закреплённым origin `192.168.103.141`.

Читать этот файл, текущие `SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md` и `SAMZABERU_V5_INTEGRATION_DECISIONS_2026-10-15.md`. Предыдущая полная редакция сохранена в commit `cd744a125db921ec79921d727ced2ba2592f8e4e`. Закрытые исследования и принятая архитектура не меняются.

## 1. Подтверждённые факты с сервера

Оператор выполнил INSTALL_DISABLED_ONLY: root/evrasia, runtime `0caa0248c783188939398a71543ba740f064bb90`, baseline/recovery archive PASS, OFF entry503 через CLI от site_evrasia, V4/shared rewrite unchanged, V5_INSTALLED_DISABLED=YES, V5_MODE=off, FINAL_STATUS=PASS, FINAL_RC=0.

| Объект | Подтверждённое значение |
|---|---|
| Public V5 | `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5` |
| Private release | `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90` |
| Резервная копия | `/root/ev-v5-off-backup.f89wzMkA` |
| Guarded rollback, не запускался | `/root/ev-v5-off-backup.f89wzMkA/rollback.sh` |
| Манифест installer | `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/installed.sha256` |
| Фактический режим | `off`, без site-factory.php, pilot key, DB settings и тестовых SQL-схем |

Installer не подключал БД/Bitrix/SMS/HTTP и не перезагружал сервисы. Новые V5-файлы действительно появились на production; нельзя писать без уточнения «production не изменялся». Рабочая авторизация V5 не включена.

**Новый результат HTTP helper:** проверка OFF package, V4 baseline и saved rollback PASS; первый путь `/api/v5/router.php`, CURL_RC=7, FINAL_STATUS=FAIL, FINAL_RC=2, HTTP_REQUESTS=1, HTTP_OFF_PATHS_PASS=0. Вложенный маршрут не проверялся. Это отказ соединения, не HTTP500/503 от приложения и не доказательство поломки V4/V5 или недоступности сайта для внешних клиентов. Файлы/настройки helper не менял. Конкретная сетевая причина отказа в этом запуске не исследовалась.

Installer закреплён commit `c37ab9959bb13dae49f9c1a2c4ddda0b06905c69`, SHA256 `3fca39d399b8d60d865fc6a886e589ccd8c3052ae702c66227650098e9577e5a`. Установленный runtime остаётся `0caa0248c783188939398a71543ba740f064bb90`. Изменение helper/docs не обновляет release на сервере.

## 2. Размещение уже согласовано

**V5 размещается параллельно V4 на существующем Bitrix evrasia. Отдельной VM не будет.** Второй MySQL, контейнерная копия сайта и второй набор клиентов не обязательны. V4 сохраняется, V5 имеет отдельные routes/code/config/keys/tokens/service tables. Аккаунты и бизнес-данные общие; V5-таблицы могут быть в существующей БД, но DDL ещё не выполнялся. Бот eur-bot-01 не переносится. Issue76 — подключение, не выделение VM.

Общая БД сохраняет требования к security events/epoch/replay. Production DDL, платные SMS и account mutations требуют отдельного конкретного допуска, backup и проверки результата. Отказные/конкурентные CI fixture scripts на рабочей БД запрещены; их guards не снимать.

## 3. Исправленный HTTP self-check: только канонический локальный origin

**Этот адрес уже был изучен — не запускать новое исследование сети.** `docs/BITRIX_ANTI_FRAUD_PRODUCTION_MAP.md`, раздел1 (проверено13сентября2026), фиксирует nginx на `192.168.103.141:80/443`, ненадёжный hairpin через публичный DNS и запрет использовать loopback. Проверенный шаблон: `curl --resolve evrasia.rest:443:192.168.103.141 https://evrasia.rest/...`. Новая проверка ошибочно не применила этот существующий контракт. История3октября также содержит такой же успешный self-call; текущий обязательный источник — production map.

Исправлен СУЩЕСТВУЮЩИЙ `site-integration/samzaberu-v5/deployment/check-disabled-v5-http.sh`, без нового диагностического набора. SHA256 **`f35297310e6a50787630f1f6dc8973088098cadc8e55c7aef37db9662ed7697b`**, Git blob `c50695bccd346d835dea18dc5ec4a9959c928501`.

Helper сохраняет off/manifest/backup/root/V4 guards. Перед HTTP убеждается через `ip` и `ss`, что этот IPv4 назначен текущему host и порт443 слушается на нём либо wildcard. Затем максимум два анонимных GET: direct `/api/v5/router.php`, после егоPASS nested `/api/v5/auth/signin`. Добавлены `--ipv4 --resolve evrasia.rest:443:192.168.103.141`; URL/Host/SNI остаются evrasia.rest, сертификат проверяется штатно. `remote_ip` обязан быть192.168.103.141. Нет proxy/curlrc/redirect/retry, переключения на HTTP, `-k` или изменения DNS/hosts/nginx. Никаких credentials/POST/SQL/SMS/V4 HTTP вызовов.

Требуются503, exactOFFJSON, JSON type, no-store, отсутствие cookies/Location/CORS/положительногоAge; после запросов сверяются state/hash. Ошибка останавливает дальнейшие запросы без rollback/rewrite. Установка не повторяется. Существующий public rollback не запускать только из-за ошибки соединения.

**Локальные проверки исправления:** bash-n PASS, фактический wrong-host denial сHTTP_REQUESTS=0;8 address/listener fixtures и отдельный отказ inventory command при правдоподобномstdout;9 неизменённых header-parser fixtures PASS. Локальный файл совпадает с uploaded Git blob. Это тесты helper, НЕ реальные origin HTTPS и НЕ новые MariaDB assertions. Новый результат с evrasia пока PENDING.

При успехе: HTTP_OFF_BOUNDARY=PASS, LOCAL_ORIGIN_OFF_BOUNDARY=PASS, HTTP_OFF_PATHS_PASS=2, PUBLIC_EDGE_TEST=NOT_RUN. **Проверка локального origin не доказывает путь через внешний DNS/NAT/CDN или доступ клиента извне.** Она также не доказывает PHP HTTPS/peer mapping, live FPM version, V4 functional E2E/native auth. Web access logs могут записываться; внутренние эффекты ошибочного rewrite не инструментированы.

## 4. Окружение и защищённые SHA

Base `/home/site_evrasia/web/evrasia.spb.ru`; docroot `<base>/public_html`; private `<base>/private`. Site account site_evrasia1005:1005. До установки base/private751 owned1005:1005, docroot751 owned1005:33. Private V5 directories0750/files0640 root:1005, public0755/0644 root:1005. Родительские права не менялись. Root-owned release под site-owned ancestor не считать абсолютной защитой от компрометации Unix site account.

CLI PHP8.3.21; также8.4.7 и8.1.32. Pool `/etc/php/8.3/fpm/pool.d/evrasia.spb.ru.conf`: user/group site_evrasia, socket `/run/php/php8.3-fpm-evrasia.spb.ru.sock`, private уже в open_basedir. Это прочитанные настройки, не доказательство web SAPI/TLS.

- Root `.htaccess`: `81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95`.
- `api/v4/.htaccess`: `61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3`.
- `api/v4/router.php`: `3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50`.
- `api/.htaccess` отсутствует. V5 public dir существует; absent baseline теперь только история до установки.

## 5. Git/CI: отдельно от установки и HTTP

Repo juvantusik/Evrasia_AI_bot, feature/samzaberu-api-v5-auth-contract. Перед исправлением проверен live HEADcd744a125db921ec79921d727ced2ba2592f8e4e и его offline Actions38763004874 SUCCESS; это прежний helper, чья сетевой цель оказалась неверной для self-call. CI не доказывает HTTP на evrasia.

Последний installer/test c37ab995: offline38754541007/job116489359396 и ephemeral38754541037/job116489360214 SUCCESS, включая package verification и прежние26offline/329DB. Runtime0caa0248: offline38750965561 SUCCESS,86PHP lint,26suites включая198entry+59private-pilot; ephemeral38750966026 SUCCESS,329DB. Исправление helper не меняет runtime/src/API, installer, SQL или workflow; его локальные проверки не считать новым полным native/DB прогоном.

Main baseline7c2e9f8e4501b92469bcdeea18f8340676ad52c9 не изменять: pushmain публикуетlatest. Документация/feature commit не являются автоматическим deployment.

## 6. Бизнес-логика и незавершённые интеграции

Логин/пароль без CUser::Login/V4-cookie; active/block/expiry/Anti-Fraud и лимиты попыток. Native TOTP имеет приоритет, без SMS fallback при ошибке/unknown. Без TOTP достаточен только strongV5trust, не слабыйV4. Иначе password-bound SMS на подтверждённый телефон аккаунта, без номера с клиента. После сильного входа PIN без второйSMS.

Сохраняются10 SMS rate/стоимостных окон, cooldown/attempt limits, HMAC/encryptedoutbox и user/device/phone/epoch bindings. Claimed/unknown не отправлять автоматически повторно; SENT требует независимой квитанции. Late receipt не оживляет challenge/не меняет бюджет. PHONE_EVENT_VERSION изменяется и при reconfirm того жеE.164; AUTH_EPOCH атомарен с native mutations.

Не закрыты: настоящая site-factory.php и test-account allowlist; nativeTOTP/replay,10-path securityhooks, phone-success/reconfirmation, strongtrust,SMS begin/resend и paidprovider/receipt/tariff,RestIS,additiveDDL,production-appropriate transaction permit иliveV4/V5acceptance. Shared-host выбор не разрешает фиктивныеtrue. Pilot key только допуск запроса.

V5BitrixLockedPasswordTotpProof готов какBEGIN read adapter. PendingV5BitrixLockedTotpFactor внеsrc/CI; ранее заблокированныйpayload не повторялся. ЗакрытыеCUser/PhoneAuth/OTP-recoverysource-onlyисследования не повторять. CUserdelete/add не доказаны какsuccessfulCONFIRMEDwriter; user.phpSHA-drift не объяснён, core/baseline автоматически не исправлять. Исторические handoff/STRUCTURE_REGISTRY/native mutation документы сохранены.

## 7. Текущие статусы и следующий ввод

LAYOUT_PREFLIGHT=PASS; V5_FILES_INSTALLED_ON_PRODUCTION=YES; V5_MODE=off; CLI_OFF_SMOKE_AS_SITE_USER=PASS; FIRST_PUBLIC_DNS_SELF_CHECK=CONNECT_FAILED_RC7; LOCAL_ORIGIN_OFF_CHECK=PENDING; PRODUCTION_AUTH_ENABLED=NO; NATIVE_FACTORY_INSTALLED=NO; V5_DATABASE_MIGRATED=NO; REAL_SITE_PARALLEL_ACCEPTANCE=NOT_RUN.

Ожидается вывод исправленного origin HTTPS helper. Не переустанавливать V5, не просить уже присланные выводы и не настраивать публичный NAT ради self-check. После реального originPASS — native composition и проверенные web metadata/ограниченный пилот; не объявлять готовой авторизацию по ответу503. Один guarded Bash block, syntaxcheck, FINAL_STATUS/FINAL_RC, безсекретов; SSH не закрывать. Приоритетproduction fact > livecode > реальные тесты > docs > обсуждения.
