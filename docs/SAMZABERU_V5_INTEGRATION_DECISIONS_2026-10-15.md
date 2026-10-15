# V5 — интеграционные решения, 15 октября 2026

Дополнение к STRUCTURE_REGISTRY. Полная предыдущая редакция R1–R17 сохранена в commit `dcc473b432f66e01175fe101c511e534da7e88ba`. Новый R18 не меняет архитектуру/бизнес-правила/native proofs.

## R18. Разрешено read-only чтение структуры; выбран socket-only способ без credentials

**Было:** web-config PASS, composition CI PASS406, read-only доступ к metadata productionDB ожидал отдельного решения.

**Стало:** пользователь ответил «дальше» непосредственно на запрос такого допуска. Подготовлен `deployment/read-v5-db-metadata-socket.sh`: root/evrasia, documented schemaeurasia_new, локальныйsocket, существующийmysql/mariadbclient, никакихcredentialfiles/PHP/Bitrix. Наличиеrootsocketlogin НЕ подтверждено, поэтому отказ безопасно завершает шаг, безauthreset/password/TCPfallback.

Первоначальный скрипт с разбором settings был отклонён при публикации и не попал в рабочуюветку/серверныйблок. Не повторялся винойкодировкеилидоставке. Альтернатива выполняет более узкуюоперацию: вообще не читаетпароли/.settings.php и не исполняетPHP. Local socket здесь методчтенияметаданных, не заменаnativeBitrixtransaction вавторизации.

**Почему:** фактическаяструктура нужнапередadditiveDDL, но read-only допуск не разрешаетсодержимоеаккаунтов илимиграцию. Секреты не должны передаваться вчат,argv,envилиlogs. БДeurasia_new найдена вlivePROJECT_CHECKPOINT/ANTI_FRAUD_CONSENT_DATA_MAP, а не угадана изименипроекта.

**Ограничения:** session READ ONLY, START TRANSACTION READ ONLY, lock_wait_timeout3, общийtimeout45секунд. Толькоmetadata10имён: b_user,b_sec_user,b_user_phone_auth,ev_trusted_devices,ev_samzaberu_auth_state,ev_v5_auth_attempt_windows,ev_v5_auth_challenges,ev_v5_sms_budget_windows,ev_v5_sms_outbox,ev_v5_strong_sessions. Дваснимкасравниваются; NOT_VISIBLE не автоматически ABSENT. НетdataSELECT/defaults/triggers/cardinality/DDL; штатныеauditlogs возможны. OFFmanifest/V4baseline до/после, никакоговключенияV5.

**Evidence:** bash-n, compile2Pythonblocks, фактическийwronghostdenial DATABASE_CONNECTION_ATTEMPTED=NO, SQLscopecheck и8/8syntheticoutputcasesPASS. SHA256`4158763756d3c9eb2c36e633465e286f67ac43a3f3788c6e9276bbbf1b5bc310`, blob`7cb46e944458ea82a2b3277cb1e466d0d456721b` совпал слокальнымфайлом. РеальнаяDBпроверкаPENDING. Новыеtests неприбавляютсяк406MariaDB.

## Сохраняемые решения

| ID | Было → стало → причина |
|---|---|
| R1/R2 | LateUNKNOWN→SENT толькоindependentreceipt безsend/quota/TTL; canonicalsortfixture сохраняетruntimeguard иисториюFAIL. |
| R3/R7 | ОтдельнаяVMкакblocker → V4/V5насуществующемevrasia,общиеаккаунты/БД → прямое решениепользователя; securityдопускисохранены. |
| R4 | Mainpushпубликуетlatest → код/docsтолькоfeature → нетпобочнойпубликации. |
| R5/R6 | SMSbridgeexactSQL/ownedCOMMIT; freshlockedpasswordBEGINadapter/общийepochHMAC → не приниматьнеcommit/staleproof. |
| R8/R9 | Постоянная503заглушка → parallelentry/lazytransport/privateoff-pilotconfig → operationalkeyнеaccountauth. |
| R10/R11 | Layout+installerCI → фактическаяOFFinstallPASS → файлыесть, nativeauthне включена; backupf89wzMkAсохранён. |
| R12/R13 | PublicDNSself-callRC7 → origin--resolve →2HTTPSOFFпутиPASS; externaledge/nativeauthнепроверены. |
| R14/R15 | ВременныйPHPshimнеопубликован; readonlywebconfig→PASS → nginxSSLhttps8443/ApachePHP83, безизменениянастроек; runtimeTLSmetadataнеаттестованы. |
| R16 | Раздельныеstores → общаяMySQLcomposition/accountscope1–32ID → единыепроверкивхода/challenge/refresh/PIN. Code6f8a352, обаCI38771014889/38771014920PASS406; nativepositiveproviderssynthetic. |
| R17 | InstallerpinsнесовместимысevolvingHEAD → второйcheckoutimmutable0caa0248дляpackage-regression;DBtestscurrentHEAD → productionpinsнеснимать. |

## Реестр структур текущего шага

Добавлен только `deployment/read-v5-db-metadata-socket.sh`; обновлены START HERE/checkpoint/решения. Runtime src/API, PROPOSED SQL, workflows, установленныйOFFrelease, V4/main/серверныеконфиги не менялись. Последнийпроверенныйruntime6f8a352 остаётсяпоследнимruntime, установленный0caa0248 — установленным.

Далее требуетсявыводmetadataблока, не новоевыделениеVM/повторwebаудита. ProductionDDL/nativeeventmutations/платныеSMS — отдельныедопуски. Настоящиеnativefactory/TOTP/replay/phone/trust/SMS/RestIS/permit иконтролируемаяприёмка не завершены. ЗакрытыеCUser/PhoneAuthsource-onlyаудиты не повторять; заблокированныеpayload не переупаковывать.
