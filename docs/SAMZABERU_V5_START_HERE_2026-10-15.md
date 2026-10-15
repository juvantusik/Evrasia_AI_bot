# Evrasia AI Bot / СамЗаберу V5 — START HERE / 15 октября 2026

**Текущая точка: V5 УСТАНОВЛЕН НА СУЩЕСТВУЮЩЕМ СЕРВЕРЕ В РЕЖИМЕ OFF.** Это не работающая авторизация и не включённый пилот. Вывод installer уже получен: не повторять установку или layout preflight. Следующий ожидаемый результат — два анонимных HTTPS GET выключенного V5.

Читать этот файл, текущие `SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md` и `SAMZABERU_V5_INTEGRATION_DECISIONS_2026-10-15.md`. Полная предшествующая редакция с устройством installer и всеми доказательствами сохранена в commit `6e5def78ee5c5a2fd5e63ba3ce0e6291c45c5ab1`; её pending-install NEXT теперь выполнен.

## 1. Подтверждённый результат с сервера

Источник — присланный оператором полный вывод INSTALL_DISABLED_ONLY: root/evrasia, CODE_COMMIT=0caa0248c783188939398a71543ba740f064bb90, проверены baseline и recovery archive; OFF entry дал503 через CLI от site_evrasia; V4 и общие rewrite не изменились; V5_INSTALLED_DISABLED=YES, V5_MODE=off, FINAL_STATUS=PASS, FINAL_RC=0.

| Объект | Подтверждённое значение |
|---|---|
| Public V5 | `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5` |
| Private release | `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90` |
| Резервная копия | `/root/ev-v5-off-backup.f89wzMkA` |
| Guarded rollback, НЕ запускался | `/root/ev-v5-off-backup.f89wzMkA/rollback.sh` |
| Манифест, созданный проверенным installer | `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/installed.sha256` |
| Фактический режим | `off`, без site-factory.php, pilot key, DB settings и тестовых SQL-схем |

В установочном выводе: DATABASE_CONNECTION=NO, DATABASE_WRITE=NO, BITRIX_BOOTSTRAP=NO, SMS_SENT=NO, HTTP_TESTED=NO, SERVICE_RELOAD=NO. **CLI503 не означает, что публичный HTTP уже проверен.** После этого результата нельзя писать без уточнения «production не изменялся»: новые V5-файлы на production действительно появились, но V4/база/сервисы не изменялись.

Installer закреплён commit `c37ab9959bb13dae49f9c1a2c4ddda0b06905c69`, SHA256 `3fca39d399b8d60d865fc6a886e589ccd8c3052ae702c66227650098e9577e5a`. Установленный runtime — ДРУГОЙ commit `0caa0248c783188939398a71543ba740f064bb90`; документационные HEAD не обновляют release на сервере.

## 2. Размещение уже согласовано

**V4 и V5 работают параллельно на существующем Bitrix `evrasia`. Отдельной VM не будет.** Второй MySQL, контейнерная копия сайта и второй набор клиентов не обязательны. V4 сохраняется, V5 имеет отдельные routes/code/config/keys/tokens/service tables. Аккаунты и бизнес-данные общие; служебные V5-таблицы могут быть в существующей БД, но DDL ещё НЕ выполнялся. Бот `eur-bot-01` не переносится. Issue76 — подключение, не выделение VM.

Общая БД сохраняет требования к security events/epoch/replay. Production DDL, платные SMS и account mutations требуют отдельного конкретного допуска, backup и проверки результата. Отказные/конкурентные CI fixture scripts на рабочей БД запрещены; их guards не снимать.

## 3. Ближайший шаг: выключенный HTTP, без новой установки

Готов `site-integration/samzaberu-v5/deployment/check-disabled-v5-http.sh`. Он проверяет root/evrasia, фактический backup, installed manifest, строго off config, отсутствие native factory и закреплённые root/V4 hashes. Затем делает максимум **два анонимных GET** на `https://evrasia.rest`: сначала `/api/v5/router.php`, затем `/api/v5/auth/signin`. Вложенный путь проверяется только после успешного прямого entry. Никаких login/password/token/pilot credentials, POST/SMS, V4 HTTP-вызовов или SQL-команд. TLS verification включена; redirects, proxy environment, curlrc и retry не используются. Ответы ограничены и не выводятся сырыми; проверяются503, точное OFF JSON, JSON content type, no-store, отсутствие cookie/redirect/CORS и ненулевого Age. В конце повторно проверяются off state и SHA.

**Статус нового блока: подготовлен, на evrasia ещё НЕ выполнен.** Локально bash -n PASS, отказ на другом host с HTTP_REQUESTS=0 и девять synthetic header-parser cases PASS; это не site HTTP и не новый MariaDB test. SHA256 блока `deb2ad8e245c63e3d12d182c0a23ce01197052856e570ffe41095a1340b83e6f`.

HTTP-проверка не инструментирует внутренние native/DB effects при ошибочной веб-маршрутизации: нельзя приписывать ей доказанное BITRIX_BOOTSTRAP=NO для неизвестного HTTP пути. Web access logs могут измениться. Она не доказывает PHP HTTPS/peer mapping, версию живого FPM, V4 E2E, native auth или готовность пилота. При неожиданном ответе — остановка, без автоматического rewrite/rollback/включения. ПриPASS следующий стык — фактическая native composition и проверенные web metadata для пилота, а не повтор установки.

## 4. Зафиксированное окружение и защищённые SHA

Base `/home/site_evrasia/web/evrasia.spb.ru`; docroot `<base>/public_html`; private `<base>/private`. Site account `site_evrasia`1005:1005. До установки base/private mode751 owned1005:1005, docroot mode751 owned1005:33. Installer создаёт private V5 directories0750/files0640 root:1005, public0755/0644 root:1005; существующие родительские права не менялись. Root-owned release под site-owned ancestor не считать абсолютной защитой от компрометации Unix site account.

CLI PHP8.3.21; также8.4.7 и8.1.32. Pool `/etc/php/8.3/fpm/pool.d/evrasia.spb.ru.conf`: user/group site_evrasia, socket `/run/php/php8.3-fpm-evrasia.spb.ru.sock`, private уже в open_basedir. Это прочитанные настройки, не доказательство текущего FPM routing/TLS. Новый отдельный сервер или изменение open_basedir не требовались.

- root `.htaccess`: `81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95`.
- `api/v4/.htaccess`: `61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3`.
- `api/v4/router.php`: `3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50`.
- `api/.htaccess` отсутствует. V5 public dir теперь существует; старый absent baseline — только история до установки.

## 5. Код/CI: не путать с HTTP и серверным состоянием

Repo `juvantusik/Evrasia_AI_bot`, feature `feature/samzaberu-api-v5-auth-contract`. Main при текущем live read остаётся `7c2e9f8e4501b92469bcdeea18f8340676ad52c9`; push main публикует latest, не синхронизировать docs туда.

Installer/test c37ab995: offline38754541007/job116489359396 SUCCESS; ephemeral38754541037/job116489360214 SUCCESS, включая package verification и прежнюю DB регрессию. Состав26offline/329DB не менялся. Текущий read job ещё раз подтвердил completed/success; полные новые логи не выгружались. Runtime0caa0248: offline38750965561 SUCCESS,86PHP lint,26suites включая198entry+59private-pilot; ephemeral38750966026 SUCCESS, неизменённые329DB checks. Эти результаты не новый native proof.

Новый HTTP-check script не меняет runtime src/API, installer, SQL или workflow. Его локальные тесты не следует называть329новыми тестами или deployed upgrade. Автоматическая CI на helper/docs commit также не является обновлением установленного payload.

## 6. Бизнес-логика и незавершённая интеграция

Логин/пароль без CUser::Login/V4-cookie; active/block/expiry/Anti-Fraud и ограничения попыток. Native TOTP имеет приоритет и не переключается на SMS при ошибке/unknown. Без TOTP достаточен только strong V5 trust, не слабый V4. Иначе password-bound SMS на подтверждённый номер аккаунта, без номера с клиента. После сильного входа бонусный PIN без второй SMS.

Сохраняются десять SMS rate/стоимостных окон, cooldown/attempt limits, HMAC/encrypted outbox и user/device/phone/epoch bindings. Claimed/unknown автоматически не отправлять повторно; SENT только с независимым подтверждением. Late receipt не оживляет challenge и не меняет бюджет. PHONE_EVENT_VERSION меняется и при повторном подтверждении того же E.164; AUTH_EPOCH атомарен с native mutations.

Не закрыты: настоящая site-factory.php и допуск конкретных тестовых аккаунтов; native TOTP-code/replay,10-path security hooks, phone-success/reconfirmation writers, strong trust, SMS begin/resend и paid provider/receipt/tariff, RestIS PIN, additive V5 DDL, production-appropriate transaction permit и live V4/V5 acceptance. Shared-host выбор не разрешает заменять эти proof фиктивными true. Pilot key — допуск к запросу, не auth или account allowlist.

`V5BitrixLockedPasswordTotpProof` готов как BEGIN read adapter. Pending `V5BitrixLockedTotpFactor` не в src/CI; ранее заблокированный payload не повторялся. Закрытые CUser/PhoneAuth/OTP-recovery source-only исследования не повторять. CUser delete/add не доказаны как successful CONFIRMED writer; user.php SHA-drift не объяснён, core/baseline автоматически не исправлять. Подробности сохранены в исторических handoff/STRUCTURE_REGISTRY/native mutation документах.

## 7. Текущие статусы

LAYOUT_PREFLIGHT=PASS; V5_FILES_INSTALLED_ON_PRODUCTION=YES; V5_MODE=off; CLI_OFF_SMOKE_AS_SITE_USER=PASS; V5_HTTP_OFF_CHECK=PENDING; PRODUCTION_AUTH_ENABLED=NO; NATIVE_FACTORY_INSTALLED=NO; V5_DATABASE_MIGRATED=NO; REAL_SITE_PARALLEL_ACCEPTANCE=NOT_RUN.

Серверный шаг — один guarded copy-paste block, проверка синтаксиса, FINAL_STATUS/FINAL_RC, без секретов; SSH не закрывать. Приоритет: production fact > live feature > реальные тесты > актуальные docs > старые обсуждения. Не просить installer output заново: он уже PASS и backup известен.
