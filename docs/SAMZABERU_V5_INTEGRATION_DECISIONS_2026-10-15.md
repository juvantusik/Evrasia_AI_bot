# V5 — реестр интеграционных решений, 15 октября 2026

Дополнение к историческому STRUCTURE_REGISTRY. Полная предыдущая редакция сохранена вcommitcd744a125db921ec79921d727ced2ba2592f8e4e. НовыйR12 исправляет проверочныйself-call, а не архитектуру или авторизацию.

## R12. HTTPS self-check использует уже известный локальный origin

**Было:** check-disabled-v5-http.sh обращался кevrasia.rest без --resolve. Оператор получил PASS проверокOFFpackage/V4/backup, затемCURL_RC=7 наdirectentry; HTTP_REQUESTS=1, HTTP_OFF_PATHS_PASS=0, FINAL_STATUS=FAIL/FINAL_RC=2. HTTPответ не получен, nestedroute не запускался.

**Стало:** тот жеhelper добавляет --ipv4 --resolve evrasia.rest:443:192.168.103.141, проверяет назначениеIP текущемуhost иlistener443 передHTTP, затемremote_ip. URL/Host/SNI остаютсяevrasia.rest, проверкасертификата не отключена. Сохраняютсяmanifest/off/V4/backup/JSON/headersguards, не более2анонимныхGET и остановкаприошибке. Нетproxy,redirect,retry,publicIP/loopbackfallback,правкиhosts/DNS/nginx/рабочихфайловилиБД.

**Почему:** docs/BITRIX_ANTI_FRAUD_PRODUCTION_MAP.md§1 уже устанавливает этот self-call и ненадёжностьpublichairpin; previoushelper пропустил известное ограничение. Это ошибка инструментальной проверки, не доказательство неисправностиV5. Новый отказRC7 сам по себе не различает конкретные причиныNAT/firewall/listener; точная текущая причина не заявляется. Прежде чем повторно исследовать сеть, нужно применить уже подтверждённыйадрес.

**Evidence:** присланныйвыводоператораRC7; livefeaturecd744a1 и productionmap7c2e9f8 прочитаны. ИсходныйhelperSHAdeb2ad8e... заменён наSHA256 **f35297310e6a50787630f1f6dc8973088098cadc8e55c7aef37db9662ed7697b**, blobc50695bccd346d835dea18dc5ec4a9959c928501. Локальноbash-n/wronghost(0HTTP),8address/listenerfixtures+1failed-inventoryfixture,9unchangedheaderfixturesPASS. Uploadedblob совпадает. Это LOCAL_ONLY, реальнаяoriginпроверкапокаPENDING. Предыдущийoffline38763004874SUCCESS не доказал self-call и не выдается заегоуспех.

**Граница:** LOCAL_ORIGIN_OFF_BOUNDARY не PUBLIC_EDGE_TEST. --resolve не проверяетмаршрутвнешнегоклиентачерезDNS/NAT/CDN и не доказываетPHPHTTPS/peer mapping/nativeauth/V4functionalE2E. Возможныwebaccesslogs. Runtime0caa0248,режимoff,backupf89wzMkA иinstallerc37ab995 неизменны; переустановка/откат не требуются поодномуconnect-failure.

## R11. V5 установлен OFF — подтверждённый серверный факт

**Было:** layoutPASS,installer/testc37ab995CIpassed, результатустановкиожидался.

**Стало:** runtime0caa0248c783188939398a71543ba740f064bb90 установленвpublic `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5` иprivate `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90`. Modeoff;backup `/root/ev-v5-off-backup.f89wzMkA`,rollbackтамжеrollback.sh. CLIотsite_evrasia дал503,root/V4unchanged,FINAL_STATUS=PASS/FINAL_RC=0. InstallerневызывалDB/Bitrix/SMS/HTTP/reload.

**Почему:** productionfact заменяетpendingdocs. КорректныестатусыV5_FILES_INSTALLED_ON_PRODUCTION=YES иPRODUCTION_AUTH_ENABLED=NO. Не просить layout/installoutput повторно. СтарыйHTTPhelper подготовленR11 и теперь исправленR12 после первогофактическогоconnectfailure.

## Сохраняемые решения: было → стало → почему

| ID | Решение |
|---|---|
| R1 | Нет lateUNKNOWN→SENT → independentreceipt reconciliation безsend/budget/TTL → неопределённуюоплату не исправлять второйSMS. |
| R2 | Quota fixtureбезsort → canonicalsortтолькоfixture → runtimeguardсохраняется,историяFAILсохранена. |
| R3 | Планотдельногоstaging → обязательнаяVMотмененаR7 → пользовательеёневыделяет. |
| R4 | Mainpushпубликуетlatest → docs/codeтолькоfeature → безпобочнойпубликации. |
| R5 | SMSqueries/autocommitACKнесовместимысbridge → exactSQL/ownedCAS/COMMIT → толькоcommittedack. |
| R6 | НетlockedpasswordBEGINadapter → freshlockedrows/прежнийepochHMAC → staleпарольнедостаточен. |
| R7 | VMкакblocker → V4/V5параллельнонаevrasia,общиеаккаунты/БД → инфраструктуранеподменяетпродукт;securitypermissionsсохраняются. |
| R8 | Постоянная503заглушка → отдельныйentry/lazytransport/off → reuseworkflowбезV4fallback. |
| R9 | Нетprivateconfig/shim → guardedoff/pilotJSON → operationalkeyнеaccountauth;nativefactoryнет. |
| R10 | Неизвестенlayout → operatorPASS/pinnedOFFinstaller → размещениеподтверждено. c37ab995сохраняетbuilder-errexit; CI38754541007/38754541037PASS,26offline/329DBсохранены. |

## Дополнение реестра структур

- В существующем deployment/check-disabled-v5-http.sh толькоorigin routing/guards/peeroutput/scopeflags. Нового аналогичногоdiagnosticфайла не создано;installerне меняетсяине запускается.
- НовыйфактHTTPCONNECT_FAILED_RC7 иhistoricalorigincontract перенесенывSTART HERE/checkpoint. ТребованиеpublicDNSself-callзаменено;externaledgeпроверка не считаетсяпройденной.
- Runtime src/API/config/keys/nativeproviders/PROPOSEDSQL ирабочийserverrelease безизмененийR12. Main/V4/рабочаяБД не изменяются.

Путиdeployment/src/api относительноsite-integration/samzaberu-v5. ЗакрытыеCUser/PhoneAuth/OTPаудиты не повторять. PendingTOTPdraft и заблокированныйpayload не использовались. Nativefactory/TOTP/replay/phone/events/trust/SMS/RestIS/DDL остаются отдельными интеграционными задачами.
