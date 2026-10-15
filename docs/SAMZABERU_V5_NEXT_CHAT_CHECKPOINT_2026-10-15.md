# СамЗаберу V5 — checkpoint: origin HTTPS PASS

15 октября2026. **Оба origin HTTPS пути уже PASS.** Не повторять layout, установку или503-проверку. РазмещениеV5/V4 параллельно наevrasia; новаяVM не требуется.

## Последний результат оператора

В загруженном логе после --resolve evrasia.rest:443:192.168.103.141:
- direct `/api/v5/router.php` и nested `/api/v5/auth/signin`: CURL_RC=0, HTTP_STATUS=503, TLS_VERIFY_RESULT=0, точноеOFFJSON.
- обаответа JSON/no-store, безSet-Cookie/Location/CORS/положительногоAge;
- повторнаяпроверка package/OFF/V4/sharedrewrite PASS;
- FINAL_STATUS=PASS, FINAL_RC=0, HTTP_REQUESTS=2, HTTP_OFF_PATHS_PASS=2, LOCAL_ORIGIN_OFF_BOUNDARY=PASS.

Граница: толькоlocalorigin cканоническимSNI. PUBLIC_EDGE_TEST=NOT_RUN, PHP_HTTPS_AND_PEER_MAPPING=NOT_ATTESTED, V4_HTTP_FUNCTIONAL_TEST=NOT_RUN, REAL_NATIVE_AUTHENTICATION_TEST=NOT_RUN. ПредыдущийCURL_RC7 без--resolve сохраняется какистория, некакновыйотказ.

## Неизменённое состояние сервера

Runtime `0caa0248c783188939398a71543ba740f064bb90`; public `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5`; private `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90`; backup `/root/ev-v5-off-backup.f89wzMkA`; rollback тамже `rollback.sh`, не запускался. Manifestвprivate/samzaberu-v5/installed.sha256. Modeoff, nativefactory/pilotkey/V5DDL отсутствуют. Файлынаproductionесть; авторизацияне включена.

## NEXT

Подготовлен **только read-only** `deployment/read-v5-web-config.sh`, SHA256 `26cc5a2b3b04143764c58032d5a5759f88a54fbd16ca738bcffaa4dedbbbfd81`, blobf658d5b3bae9c47320e530ac5813b67ed87e8d51.

Проверяетroot/evrasia/OFFmanifest/V4 до/после; читает ограниченныеvhost-кандидаты nginx/Apache иPHP8.3/.user.ini, выводиттолько безопасныеTLS/FPM/forwardedнастройки. Не выполняетPHP/HTTP/SQL, не меняетrouter.php/конфигурацию/пилот, не перезапускаетсервисы. Не обходитпроизвольныеinclude. Отсутствующийпуть невыдаётся засуществующий; при отсутствииvhostилиpython3 FAILбезустановкизависимостей.

Локально bash-n/Pythoncompile/wronghost(0HTTP) и10 синтетическихparser/redaction/scopechecks PASS; это неживойwebконтекст и неDBsuite. **Ожидаетсявыводсервера именноэтогоблока.** После него разбиратьфактическиеTLS/FPMправила и продолжатьnativecomposition, не новыйобщийаудит.

Временный инструментированныйPHPshim рассматривался, но егоGitHubпубликация былазаблокирована. Payloadнеповторялсяи некоммитился/неприменялсянасервере. Read-onlyальтернатива не выполняетподменувхода. Не обещать runtimeаттестацию по статическомуconfigснимку.

## Версии/CI

Livefeatureдоизменений0a5a60e0bc52f96644e8c858b5760e015a6ef20e; offline38764635635SUCCESS подтверждён. Installer/testc37ab995 CI38754541007/38754541037SUCCESS; runtime0caa0248 CI38750965561/38750966026SUCCESS, прежние26offline/329DB. Новыйhelper не меняетsrc/API/DDL/workflows/установленныйrelease. Его локальные10checks неувеличивают329DB и неявляются новымnativeproof.

Mainbaseline7c2e9f8e4501b92469bcdeea18f8340676ad52c9 не менять. Root/V4SHA иUnix/PHPmetadata сохраненывSTART HERE. Полные предыдущиедоказательства — история0a5a60e.

Nativefactory/allowlist,TOTP/replay/10-pathepochhooks,phonesuccess/reconfirm,strongtrust,SMSlifecycle/provider/receipt/tariff,RestIS,permitиadditiveDDL всёещёнеприняты. PendingTOTPdraftвнеsrc/CI. Productionмутации/DDL/SMS отдельно согласовывать; CI/faultschemas нарабочейБД запрещены.

CURRENT: INSTALLED_DISABLED / ORIGIN_HTTPS_PASS / PHP_RUNTIME_NOT_ATTESTED / NO_NATIVE_AUTH / NO_V5_DB_MIGRATION.
