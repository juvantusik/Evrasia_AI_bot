# СамЗаберу V5 — checkpoint: OFF установлен, исправлен HTTPS self-call

15 октября2026. INSTALL_DISABLED_ONLY уже PASS. Первый HTTP helper получил CURL_RC=7; **следующий ввод — origin-проверка с --resolve**, не повтор установки/layout/истории. VM не требуется, V5 рядом сV4 наevrasia.

## Что фактически выполнено оператором

Установка runtime0caa0248c783188939398a71543ba740f064bb90: V5_INSTALLED_DISABLED=YES, V5_MODE=off, FINAL_STATUS=PASS, FINAL_RC=0. CLI отsite_evrasia подтвердил503, V4/shared rewrite unchanged; DB/Bitrix/SMS/HTTP/reload не выполнялись installer.

- Public `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5`.
- Private `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90`.
- Backup `/root/ev-v5-off-backup.f89wzMkA`.
- Rollback `/root/ev-v5-off-backup.f89wzMkA/rollback.sh`, не запускался.
- Manifest `private/samzaberu-v5/installed.sha256`; configoff, нетfactory/pilotkey/DDL.

**Следующая попытка HTTP:** OFFpackage/V4baseline/rollback guardsPASS. ПервыйGET `/api/v5/router.php` не получилHTTPответ: CURL_RC=7, FINAL_RC=2, HTTP_REQUESTS=1, HTTP_OFF_PATHS_PASS=0. Вложенный путь не выполнялся. Нет основания считать это ошибкой приложения, повреждением установки или отказом сайта для всех клиентов. Реальная причина сетевого connect-failure в новом запуске не доказана.

## Исправление без новых аудитов

Production map `docs/BITRIX_ANTI_FRAUD_PRODUCTION_MAP.md`§1 уже фиксирует localorigin192.168.103.141, nginx:443 наэтомIP, не наloopback, и ненадёжныйpublichairpin. Старый helper ошибочно не использовал известный self-call.

Существующий `deployment/check-disabled-v5-http.sh` теперь использует `--ipv4 --resolve evrasia.rest:443:192.168.103.141`, сохраняяURL/Host/SNI, полноценнуюпроверкусертификата, запретproxy/redirect/retry/insecure. ДоHTTP проверяет, чтоIP локальный и443 слушается. Послеconnect проверяетremote_ip. Старыеoff/manifest/backup/root/V4guards иexact503JSON/headerchecks сохранены. ДваGET максимум, второй послепервогоPASS.

**Новый SHA256 `f35297310e6a50787630f1f6dc8973088098cadc8e55c7aef37db9662ed7697b`**, blobc50695bccd346d835dea18dc5ec4a9959c928501. Старыйdeb2ad8e изcd744a1 больше не выдавать для self-check. Установленныйruntime не обновляется, DNS/hosts/nginx/V4/SQL не меняются.

LOCALTESTS: bash-n, wrong-hostdenial HTTP_REQUESTS=0,8address/listenerfixtures +1failed-inventoryfixture,9headerfixturesPASS. Uploadedblob совпал слокальным. Это неoriginHTTPPASS и неновыйDBsuite. НовыйвыводссервераPENDING.

ПриPASS ожидаемLOCAL_ORIGIN_OFF_BOUNDARY=PASS/HTTP_OFF_PATHS_PASS=2/FINAL_STATUS=PASS. **PUBLIC_EDGE_TEST=NOT_RUN**: --resolve проверяетместныйorigin, не внешнийNAT/CDNпуть. PHPHTTPS/peer mapping иnativeauth также неаттестованы. ПослеPASS продолжитьnativecomposition; приошибке анализироватьконкретныйновыйрезультат, безпереустановки и общихPHPаудитов.

## Версии, CI и защищённое окружение

Передизменениямиlivefeaturecd744a125db921ec79921d727ced2ba2592f8e4e; егоoffline38763004874SUCCESS. Последнийinstaller/testc37ab9959bb13dae49f9c1a2c4ddda0b06905c69, CI38754541007/38754541037SUCCESS, прежние26offline/329DB+packagestep. Runtime0caa0248CI38750965561/38750966026SUCCESS. Новыйhelper неменяетsrc/API/workflows/DDL; новыйcommit неустановленныйruntime.

SiteUID/GID1005:1005,CLI8.3.21,pool8.3/privateopen_basedir ранее подтверждены. RootSHA81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95;V4rewriteSHA61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3;V4routerSHA3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50. Sharedapi/.htaccessотсутствует. Mainbaseline7c2e9f8e4501b92469bcdeea18f8340676ad52c9 не менять.

Nativefactory/allowlist,TOTP/replay/10-pathepochhooks,phonesuccess/reconfirm,strongtrust,SMSlifecycle/provider/receipt/tariff,RestIS,permitиadditiveDDL ещё не приняты. PendingTOTPdraft внеsrc/CI; заблокированныйpayload не повторять. CI/faultschemas не переноситьнаproduction.

CURRENT: V5_FILES_INSTALLED_ON_PRODUCTION=YES / V5_MODE=off / FIRST_DNS_SELF_CHECK=CONNECT_FAILED_RC7 / ORIGIN_HTTPS_PENDING / PRODUCTION_AUTH_ENABLED=NO / NO_V5_DB_MIGRATION. Полнаяпредыдущаяредакция сохранена вcd744a125db921ec79921d727ced2ba2592f8e4e; еёHTTP_PENDINGтеперь уточнён фактическимconnect-failure, а не скрыт.
