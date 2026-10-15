# Evrasia AI Bot / СамЗаберу V5 — START HERE / 15 октября 2026

Главная точка продолжения. Читать этот файл, текущие `SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md` и `SAMZABERU_V5_INTEGRATION_DECISIONS_2026-10-15.md`. Полная предыдущая редакция — Git commit `984cbc207a35309a6e70c24a0ce38ffa91d589d8`. Не повторять закрытые исследования.

## 1. Размещение уже согласовано

**V5 подключается параллельно V4 на существующем Bitrix-сервере `evrasia`. Отдельной VM не будет.** Второй MySQL instance, контейнерная копия сайта и второй набор клиентов не обязательны. V4 сохраняется; V5 получает отдельные routes/code/config/keys/tokens/service tables. Аккаунты Bitrix и бизнес-данные общие, V5-таблицы могут находиться в существующей БД. Бот `eur-bot-01` не переносится. Issue76 — подключение V5 рядом с V4, не выдача VM.

Общая БД не отменяет актуальность security events/epoch/replay. Конкретные production DDL, SMS/платежи и account mutations требуют отдельного допуска. CI-only/fault scripts нельзя запускать на рабочей БД и нельзя снимать их guards ради такого запуска.

## 2. НОВЫЙ факт с сервера: layout preflight PASS

Оператор прислал результат уже выданного read-only блока: HOST=evrasia, USER=root, FINAL_STATUS=PASS, FINAL_RC=0, LAYOUT_PREFLIGHT_ONLY=YES. Повторно этот блок не запрашивать. Это проверка размещения, не native Bitrix или HTTP acceptance.

- Unix site account `site_evrasia`: UID=1005, GID=1005.
- Base `/home/site_evrasia/web/evrasia.spb.ru`: UID/GID1005:1005, mode751.
- Docroot `<base>/public_html`: UID/GID1005:33, mode751.
- Private `<base>/private`: UID/GID1005:1005, mode751.
- `<private>/samzaberu-v5` и `<docroot>/api/v5` **отсутствуют на момент preflight**; `api/.htaccess` также отсутствует.
- CLI `/usr/bin/php8.3`=8.3.21; также8.4.7 и8.1.32. Для установки выбран8.3.21.
- `/etc/php/8.3/fpm/pool.d/evrasia.spb.ru.conf`: user/group site_evrasia, socket `/run/php/php8.3-fpm-evrasia.spb.ru.sock`; open_basedir уже содержит private. FPM/global/web-server настройки не изменялись. Это конфигурационное evidence, не attestation живого web SAPI/TLS.
- Root `.htaccess`: SHA256 `81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95`,2307bytes,1005:1005,644.
- `api/v4/.htaccess`: SHA256 `61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3`,398bytes,1005:1005,644.
- `api/v4/router.php`: SHA256 `3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50`,8909bytes,1005:1005,644.

Проверка не выполняла site PHP/Bitrix/SQL/HTTP/SMS. Вывод терминала в начале вставки визуально повреждён, но структурированный результат целевого блока завершён PASS; нет оснований заново повторять весь preflight.

## 3. Текущий installer и доказательства

Repo `juvantusik/Evrasia_AI_bot`, feature `feature/samzaberu-api-v5-auth-contract`.

**Последний installer/test commit: `c37ab9959bb13dae49f9c1a2c4ddda0b06905c69`.**
- Offline Actions **38754541007**, job116489359396: completed/success.
- Ephemeral MariaDB + OFF-package Actions **38754541037**, job116489360214: completed/success. Новый шаг `Verify disabled deployment package without site access` и прежний DB regression завершились success. Полные новые job logs не выгружались; статусы всех шагов проверены. Состав прежних26offline/329DB тестов не менялся, новые проверки пакета не добавляются к329.
- Новый `site-integration/samzaberu-v5/deployment/install-disabled-v5.sh` и15строк existing ephemeral workflow. Приложение `src`, PHP entry, V4, proposed SQL и native providers не менялись.
- Installer SHA256 **`3fca39d399b8d60d865fc6a886e589ccd8c3052ae702c66227650098e9577e5a`**, blob `2a0174eb6497d1468ff1f411fe4e6727bce63a8c`. Сверен с локально linted файлом. Локальные bash syntax/wrong-host denial PASS; локальный полный пакет не выполнялся, GitHub DNS в контейнере недоступен.
- Первый installer commit71d4a2d исправлен c37ab995: build_payload вызывается без `|| fail`, чтобы Bash errexit не отключался внутри сборки. Не ослаблялись host/source guards. Оператору даётся только окончательный pinned c37ab995.

**Устанавливаемый runtime payload остаётся `0caa0248c783188939398a71543ba740f064bb90`.** Не путать его с installer commit. В новых CI проверяются эти же закреплённые src/API blobs. Более поздний doc-only HEAD не новый runtime или deployment.

## 4. Что делает подготовленный серверный шаг

Оператору выдан один Bash-блок: скачивает installer по immutable c37ab995, проверяет SHA256 и bash -n, затем запускает на root/evrasia. **Это уже file WRITE, а не очередной read-only аудит; фактический результат установки пока НЕ получен.**

Installer проверяет host/UID/GID/PHP, canonical paths, отсутствие V5 targets, приведённые выше root/V4 SHA, отсутствие shared api rewrite и filesystem для атомарной публикации. Создаёт root-only backup трёх защищённых файлов, сравнивает tar с оригиналами. Получает immutable runtime commit в bare Git без credentials/helpers/hooks и без checkout; проверяет объекты и src/API pins. На сайт переносит только runtime PHP и V5 rewrite, off config и CODE_COMMIT. Тесты, SQL schemas, настоящая factory, pilot key и credentials не устанавливаются.

Private release: `<private>/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90/`, root:1005, directories0750/files0640. Public `/api/v5` содержит только router.php и .htaccess, root:1005,0755/0644. Существующие родительские права/open_basedir/FPM/nginx/Apache/root/V4 rewrite не меняются. Private/files вначале готовятся и сверяются, public dir публикуется одним rename на том же filesystem. Разные filesystem для /root backup и /home допускаются: private payload предварительно копируется и побайтово сравнивается.

До/после публикации новый OFF entry выполняется **через CLI PHP -n от site_evrasia**, проверяя точный503 JSON, отсутствие Bitrix/CUser и посторонних includes. **Не делает HTTP-запроса**, не запускает native code, не подключает БД и не перезагружает сервисы. Эти проверки НЕ доказывают актуальные web rewrite/TLS/global PHP directives.

Сохраняет hash manifest и guarded rollback.sh в root backup. Откат перемещает только созданный public V5 каталог обратно в private, не удаляет БД и не восстанавливает чужие изменения V4. При собственной post-publish ошибке пытается такой же откат только при совпадении inode/hashes и двух ожидаемых файлов; при drift останавливается. Повторный запуск проверяет manifest и возвращает ALREADY_APPLIED без перезаписи; частичную/изменённую установку автоматически не перетирает.

## 5. Ближайшая точка продолжения

**Ожидается вывод INSTALL_DISABLED_ONLY блока**, а не повтор layout preflight, новая VM или повторное объяснение требований. До результата: `V5_OFF_INSTALLATION=PREPARED_NOT_CONFIRMED`, `PRODUCTION_DEPLOYED=NO`.

После реального PASS зафиксировать фактический backup/release/manifest, затем точечно проверить актуальную маршрутизацию/web runtime и выключенный HTTP path. Root/V4 rewrite не заменять наугад. Native `site-factory.php` и backend integrations остаются отдельной задачей. Нельзя считать файлыOFF работающей авторизацией и нельзя включать flag вместо законченных native providers.

## 6. Принятая бизнес-логика и незавершённое

Логин/пароль проверяются без CUser::Login/V4-cookie. Сохраняются active/block/expiry/Anti-Fraud и ограничения попыток. При native TOTP после пароля обязательный authenticator code, без SMS fallback при ошибке/unknown. Без TOTP — только сильный V5 trust; слабый V4 trust не подходит. Без обоих — password-bound SMS challenge на подтверждённый телефон аккаунта, без номера с клиента. После сильного входа PIN без второй SMS.

Сохраняются десять SMS rate/стоимостных окон, cooldown/attempt limits, HMAC/encrypted outbox, user/device/phone/epoch bindings. Claimed/unknown не отправлять автоматически повторно; SENT требует независимой квитанции. Late receipt не оживляет challenge и не меняет бюджет. PHONE_EVENT_VERSION меняется и при reconfirm того же E.164; AUTH_EPOCH атомарен с native mutations.

Не закрыты: native TOTP-code/replay, полное10-path security-event coverage, successful phone confirmation/reconfirmation writer, strong trust, SMS begin/resend lifecycle и реальный paid provider/receipt/tariff, RestIS PIN, additive V5 DDL, production-appropriate transaction permit, реальные ограничения пилотных аккаунтов. Shared-host размещение эти гарантии не отменяет. `V5BitrixLockedPasswordTotpProof` — готовый BEGIN read adapter; `V5BitrixLockedTotpFactor` в pending-документе остаётся черновиком вне src/CI. Заблокированный ранее payload не повторялся.

Private pilot loader/header — только допуск к запросам, не account auth/allowlist/native coverage. В текущей установке mode=off, factory отсутствует, pilot не активируется. Подробности текущих компонентов см. API README и предыдущий START HERE984cbc2.

## 7. История и ограничения

Закрытые CUser/PhoneAuth/OTP-recovery source-only исследования не повторять. CUser delete/add не доказаны как successful CONFIRMED writer; SHA-drift user.php не объяснён, не утверждать новый baseline и не исправлять core автоматически. Все подробные handoff/STRUCTURE_REGISTRY/native mutation документы сохранены.

Runtime история:0caa0248 (59private-pilot/198entry/329DB),029aaff (198entry/329DB),c3f92ba (329DB),8c395f0 (268),dc2caed (205),4a6ea09 (160). Main при live read остаётся `7c2e9f8e4501b92469bcdeea18f8340676ad52c9`; его push вызывает build/publish latest, docs туда не переносить. Feature code/doc commits не deploy автоматически.

Серверные операции: один guarded Bash block, syntax check, FINAL_STATUS/FINAL_RC, без секретов и лишнего PHP/SQL/HTTP. Безопасные feature/CI шаги выполнять самостоятельно; production facts > live code > реальные тесты > актуальные docs > старые обсуждения.
