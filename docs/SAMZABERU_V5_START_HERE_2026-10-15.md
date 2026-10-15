# Evrasia AI Bot / СамЗаберу V5 — START HERE, 15 октября 2026

**Текущая точка: V5 установлен в режиме OFF; оба origin HTTPS пути проверены и PASS.** Не повторять установку, layout preflight или уже пройденную проверку503. Новый чат — продолжение того же проекта. Историческая полная редакция до получения HTTP PASS сохранена в commit `0a5a60e0bc52f96644e8c858b5760e015a6ef20e`; её ORIGIN_HTTPS_PENDING больше не актуален.

Читать вместе с `SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md` и `SAMZABERU_V5_INTEGRATION_DECISIONS_2026-10-15.md`. Последние решения и фактические результаты важнее старых NEXT.

## 1. Новый подтверждённый результат с сервера

Оператор прислал файл с результатом исправленного origin helper. Проверки OFF package, V4 baseline, backup, локального192.168.103.141 и443 listener PASS. Затем:

| Путь | Результат |
|---|---|
| `/api/v5/router.php` | CURL_RC=0, CONNECTED_ORIGIN_IP=192.168.103.141, HTTP_STATUS=503, TLS_VERIFY_RESULT=0, EXPECTED_OFF_JSON=YES |
| `/api/v5/auth/signin` | CURL_RC=0, CONNECTED_ORIGIN_IP=192.168.103.141, HTTP_STATUS=503, TLS_VERIFY_RESULT=0, EXPECTED_OFF_JSON=YES |

На обоих путях JSON_CONTENT_TYPE=YES, CACHE_NO_STORE=YES, SET_COOKIE_PRESENT=NO, REDIRECT_PRESENT=NO, CORS_HEADER_PRESENT=NO, CACHED_AGE_PRESENT=NO. После запросов package/OFF/V4/shared rewrite unchanged. Итог **FINAL_STATUS=PASS, FINAL_RC=0, HTTP_REQUESTS=2, HTTP_OFF_PATHS_PASS=2, LOCAL_ORIGIN_OFF_BOUNDARY=PASS**.

Это настоящий HTTPS к origin с каноническим SNI, не CLI и не синтетический тест. **Это НЕ работающая авторизация, не внешний DNS/NAT/CDN путь и не аттестация переменных HTTPS/REMOTE_ADDR внутри PHP.** В присланном результате PUBLIC_EDGE_TEST=NOT_RUN, PHP_HTTPS_AND_PEER_MAPPING=NOT_ATTESTED, V4_HTTP_FUNCTIONAL_TEST=NOT_RUN, REAL_NATIVE_AUTHENTICATION_TEST=NOT_RUN. Нельзя расширять вывод за эту границу.

Первый helper без --resolve ранее получил CURL_RC=7, HTTP_OFF_PATHS_PASS=0. Этот FAIL сохраняется в истории; исправленный self-call теперь подтверждён. Точную причину первого сетевого отказа отдельно не доказывали. Не настраивать public hairpin ради локальной проверки.

## 2. Фактическое размещение и восстановление

**V5 параллельно V4 на существующем Bitrix `evrasia`. Отдельной VM не будет.** Второй MySQL instance, контейнерная копия сайта и второй набор клиентов не обязательны. Аккаунты/бизнес-данные общие; V5 routes/code/config/keys/tokens/service tables отдельные. Бот `eur-bot-01` не переносится. Issue76 — подключение, а не выдача VM.

| Объект | Зафиксированное значение |
|---|---|
| Установленный runtime | `0caa0248c783188939398a71543ba740f064bb90` |
| Public V5 | `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5` |
| Private release | `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90` |
| Backup | `/root/ev-v5-off-backup.f89wzMkA` |
| Rollback, не запускался | `/root/ev-v5-off-backup.f89wzMkA/rollback.sh` |
| Manifest | `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/installed.sha256` |
| Режим | Строго `off`; настоящая `site-factory.php`, pilot key и V5 DDL не установлены |

Установка файлов уже выполнена оператором: INSTALL_DISABLED_ONLY PASS, CLI отsite_evrasia дал503, V4/root rewrite сохранены. Правильные статусы: V5_FILES_INSTALLED_ON_PRODUCTION=YES и PRODUCTION_AUTH_ENABLED=NO. Нельзя снова писать «production вообще не менялся» или считать новый feature HEAD обновлением серверного release.

Site user `site_evrasia` UID/GID1005:1005; CLI PHP8.3.21, pool `/etc/php/8.3/fpm/pool.d/evrasia.spb.ru.conf`, socket `/run/php/php8.3-fpm-evrasia.spb.ru.sock`. В прочитанной pool-конфигурации private уже разрешён в open_basedir; auto_prepend_file в том выводе не задан. Это не полный аудит эффективной web-конфигурации. Private V5 directories0750/files0640 root:1005, public0755/0644 root:1005. Родительские каталоги сайта принадлежат site account; не утверждать абсолютную защиту root-owned release при компрометации его предка.

Защищённые SHA256:
- Root `.htaccess`: `81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95`.
- `api/v4/.htaccess`: `61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3`.
- `api/v4/router.php`: `3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50`.
- `api/.htaccess` отсутствует. PublicV5 теперь существует; прежнее отсутствие — история до установки.

## 3. Следующий точечный шаг — конфигурация передачи HTTPS в PHP

Подготовлен `site-integration/samzaberu-v5/deployment/read-v5-web-config.sh`, SHA256 **`26cc5a2b3b04143764c58032d5a5759f88a54fbd16ca738bcffaa4dedbbbfd81`**, blob `f658d5b3bae9c47320e530ac5813b67ed87e8d51`.

Это **чтение текстов конфигурации**, не временная замена V5-входа. Root/evrasia, OFF config, manifest и root/V4 hashes проверяются до/после. Скрипт рассматривает ограниченный список vhost-кандидатов дляevrasia.rest/evrasia.spb.ru в стандартных каталогах nginx/Apache и site conf, известную pool8.3/globalPHP конфигурацию и .user.ini по путиV5. Пути кандидатов не объявлены найденными заранее. Не обходит произвольные include, не читает .settings.php/DB credentials/сертификаты/private keys, не выводит Authorization и другие произвольные заголовки. Показывает только имена файлов/контрольные суммы/номера строк и ограниченные TLS/FPM/forwarded значения; нестандартные и потенциально секретные значения скрывает.

**SITE_FILE_WRITE=NO, SITE_PHP_EXECUTED=NO, DATABASE_CONNECTION=NO, HTTP_REQUESTS=0, SERVICE_RELOAD=NO.** Никакой установки пакетов или изменения nginx/FPM/пилота. При отсутствии python3 или ожидаемых vhost-файлов — остановка с понятным FAIL, без угадывания рабочего пути. Положительный результат означает полученный конфигурационный снимок, а не proven web runtime. Условные blocks/include precedence и эффективный PHP runtime этим полностью не моделируются.

Локально: bash-n, компиляция встроенного Python, wrong-host отказ с0HTTP;10 синтетических проверок парсера (включая скрытие credential-canary, отказ неизвестного vhost и symlink за пределами разрешённых деревьев) PASS. Blob совпал с локальным файлом. Это не server config read и не новые MariaDB assertions. **Результат этого read-only блока с evrasia ещё ожидается.**

Планировался временный инструментированный PHP shim для runtime-проверки, но GitHub tool отклонил его публикацию по безопасности. Этот payload не повторялся другим способом и не был включён в ветку/серверный шаг. Новый разрешённый вариант не выполняет такую же подмену: он только читает конфигурационные файлы и не выдаётся за runtime measurement. Не считать обещание раннего commentary выполненной модификацией V5.

После чтения конкретных TLS/FPM правил продолжить существующую native composition, сохраняя strict HTTPS и отсутствие доверия к произвольным proxy headers. Не возвращаться к общим CUser/PhoneAuth-аудитам. Не подменять отсутствующие native proofs permissive callbacks ради зелёного ответа.

## 4. Реализация и бизнес-правила остаются прежними

Уже реализованы TOTP > strongV5trust > password-boundSMS workflow; отдельныеJWT/opaque refresh; InnoDB sessions/CAS/revocation/JWT послеCOMMIT; password-bound TOTP begin с текущими locked rows; one-use consumption/grant; encryptedSMSoutbox/10scope budgets/one paid claim/independent SENT receipt/late reconciliation; HTTPboundary; PIN session guard; Bitrix bridge; parallelentry/privatepilotloader/publicshim. Основные файлы и прежние тестовые доказательства — `site-integration/samzaberu-v5/README.md`, API README и исторические START HERE/checkpoints. Текущим helper эти компоненты не меняются.

После пароля при nativeTOTP обязателен authenticator; ошибки/unknown не переключают наSMS. БезTOTP подходит только сильноеV5trust, не слабоеV4. Иначе SMS на подтверждённый номер аккаунта, без номера с клиента. После сильного входа PIN без второйSMS. Сохраняются active/block/expiry/Anti-Fraud, password-attempt limits, encrypted/HMAC/bindings, тариф/currency/idempotency. Claimed/unknown автоматически не пересылать. SENT только с independent receipt; late receipt не оживляет challenge/не меняетбюджет. PHONE_EVENT_VERSION меняется и при reconfirm того жеE.164, AUTH_EPOCH атомарен с native security mutations.

**Не завершены:** настоящая site-factory/test-account allowlist; nativeTOTP-code/replay;10-path epochhooks; successfulphone/reconfirmation writers; strongtrust; первоначальный SMS begin/resend lifecycle; paidSMS/provider receipt/tariff; RestIS PIN; additiveV5DDL; production-appropriate transaction permit; liveauthentication/pilot acceptance. HTTP503PASS не закрывает их. `V5BitrixLockedPasswordTotpProof` — готовый BEGIN read adapter, не verifier кода. Pending `V5BitrixLockedTotpFactor` остаётся внеsrc/CI; его прежний заблокированныйpayload не повторяется.

## 5. Git/CI и границы допуска

Repo `juvantusik/Evrasia_AI_bot`, branch `feature/samzaberu-api-v5-auth-contract`. Перед этой итерацией liveHEAD `0a5a60e0bc52f96644e8c858b5760e015a6ef20e`; его offline Actions **38764635635 SUCCESS** проверен через liveGitHub. Установленныйruntime остаётся0caa0248, installer/testc37ab9959bb13dae49f9c1a2c4ddda0b06905c69. InstallerCI38754541007/38754541037 и runtimeCI38750965561/38750966026 — ранее подтверждённыеSUCCESS,26offline/329DB; новые config-parser проверки не прибавляются к329.

Main baseline `7c2e9f8e4501b92469bcdeea18f8340676ad52c9`; main не изменять: push публикуетlatest. Новые docs/helper commits не установка и не новые native providers. Production DDL, account/security mutations и платныеSMS требуют отдельного конкретного допуска, backup/post-check/rollback. CI/faultschemas не переносить в рабочуюБД и не отключать guards.

ЗакрытыеCUser/PhoneAuth/OTP-recovery исследования сохранены в историческихhandoff/STRUCTURE_REGISTRY/native-mutation документах. CUserdelete/add не доказаны какsuccessfulCONFIRMEDwriter; SHA-driftuser.php не объяснён и не приняткакbaseline. Не исправлятьcoreавтоматически и не повторять прежниеsource-onlyprobes.

**CURRENT:** V5_FILES_INSTALLED_ON_PRODUCTION=YES; V5_MODE=off; LOCAL_ORIGIN_OFF_BOUNDARY=PASS(2paths); PHP_RUNTIME_MAPPING_ATTESTED=NO; PRODUCTION_AUTH_ENABLED=NO; V5_DATABASE_MIGRATED=NO; PUBLIC_EDGE_TEST=NOT_RUN. Следующий ожидаемый ввод — read-only web-config output, не повторHTTP/установки/истории. Один guarded Bash block, syntaxcheck, FINAL_STATUS/FINAL_RC, безсекретов; терминалне закрывать.
