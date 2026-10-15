# СамЗаберу V5 — checkpoint: установлен и выключен

15 октября2026. **Получен INSTALL_DISABLED_ONLY PASS. Не повторять установку/layout preflight.** Отдельная VM отменена; V5 рядом с V4 на существующем evrasia. Полный предыдущий checkpoint — commit6e5def78ee5c5a2fd5e63ba3ce0e6291c45c5ab1, его pending-install NEXT выполнен.

## Фактический результат оператора

HOST=evrasia, USER=root; CODE_COMMIT=0caa0248c783188939398a71543ba740f064bb90; baseline/recovery archive проверены; OFF entry503 через CLI от site_evrasia; V4/shared rewrite unchanged; V5_INSTALLED_DISABLED=YES, V5_MODE=off; FINAL_STATUS=PASS, FINAL_RC=0. DATABASE_CONNECTION/WRITE=NO; BITRIX_BOOTSTRAP=NO; SMS_SENT=NO; HTTP_TESTED=NO; SERVICE_RELOAD=NO.

- PUBLIC_PATH `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5`.
- PRIVATE_RELEASE `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90`.
- BACKUP_DIR `/root/ev-v5-off-backup.f89wzMkA`.
- ROLLBACK_SCRIPT `/root/ev-v5-off-backup.f89wzMkA/rollback.sh` (не запускался).
- Installer создал `private/samzaberu-v5/installed.sha256`. Config строгоoff; site-factory.php/pilot key/DDL отсутствуют.

Новые файлы на production уже есть: не писать «production не изменялся» или PRODUCTION_DEPLOYED=NO без уточнения смысла. Правильные статусы: **V5_FILES_INSTALLED_ON_PRODUCTION=YES / PRODUCTION_AUTH_ENABLED=NO**. CLI503 ещё не HTTP503.

## NEXT — конкретный HTTP блок уже подготовлен

`site-integration/samzaberu-v5/deployment/check-disabled-v5-http.sh`; SHA256 `deb2ad8e245c63e3d12d182c0a23ce01197052856e570ffe41095a1340b83e6f`.

Guarded root/evrasia, закреплённый release/manifest, точныйOFF config и отсутствиеfactory, backup и неизменные root/V4 SHA. Два анонимных HTTPS GET на evrasia.rest: direct `/api/v5/router.php`, затем nested `/api/v5/auth/signin`. Второй только после первогоPASS. Проверка TLS без insecure,503, точного JSON,no-store и отсутствия cookies/redirect/CORS/Age; raw headers/body не печатаются, redirects/retry/proxy/curlrc исключены. После запросов SHA/off проверяются снова. Веб-конфиги не меняет, SQL и POST/login/SMS не выполняет. Сам rollback только syntax-checked, не исполняется.

**LOCAL_ONLY:** bash -n PASS, wrong-host denial HTTP_REQUESTS=0,9/9 synthetic header-parser cases PASS. На evrasia HTTP ещё не запускался. Этот helper не меняет installed payload и не является новым native/MariaDB suite. При unexpected response остановка, без автоматического исправления или отката. Веб-запрос может писать access logs; internal effects неизвестного маршрута не инструментированы. PHP HTTPS/peer mapping, live FPM version и V4 functional E2E этим не доказаны.

Ожидается вывод именно этого HTTP блока. ПриPASS зафиксировать фактические HTTP результаты, затем продолжить native site composition. ПриFAIL разбирать конкретный status/headers, не возвращаться к общим source-only аудитам и не переустанавливатьV5 вслепую.

## Закреплённые версии / CI

Runtime0caa0248c783188939398a71543ba740f064bb90; installer/testc37ab9959bb13dae49f9c1a2c4ddda0b06905c69, installerSHA2563fca39d399b8d60d865fc6a886e589ccd8c3052ae702c66227650098e9577e5a. Installer CI38754541007/38754541037 SUCCESS;26offline/329DB + package step. RuntimeCI38750965561/38750966026 SUCCESS. Новые helper/docs commits — не новый runtime/deployment.

SiteUID/GID1005:1005; CLI8.3.21, pool8.3/private open_basedir ранее подтверждены. RootSHA81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95; V4rewriteSHA61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3; V4routerSHA3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50. Shared api/.htaccess отсутствует. Main7c2e9f8e4501b92469bcdeea18f8340676ad52c9 не меняется.

## Незавершённое не скрывать

Native factory/test-account allowlist, native TOTP/replay/10-pathAUTH_EPOCH hooks, phone-success/same-number event, strongtrust,SMS lifecycle/provider/receipt/tariff,RestIS,production-appropriate permit и additiveDDL ещё не приняты. Pilot key не auth proof. PendingTOTP draft не вsrc/CI; заблокированный payload не повторялся. Fault/CI fixture scripts не переносить на production.

CURRENT: INSTALLED_DISABLED / HTTP_PENDING / NO_NATIVE_AUTH / NO_V5_DB_MIGRATION. Предыдущие детальные доказательства и история сохранены через START HERE/Git, не начинать проект заново.
