# V5 — интеграционные решения, 15 октября 2026

Дополнение к STRUCTURE_REGISTRY. Полная предыдущая редакцияR1–R18 сохранена вcommit `df25f34aa894aa92292ba96f2fb6698fcbc02449`. НовыйR19 уточняетфактдоступа, не меняетбизнес-логику илиархитектуру.

## R19. Root/socket без пароля отвергнут; metadata передаются как SQL-only

**Было:** read-onlymetadataразрешены; helperпроверялroot/socket безпароля, но такой вход был только предположением и не считался подтверждённым.

**Стало:** операторскийлог17:12:49 далOFF/V4/socketguardsPASS, DB_CLIENT_RC1/DB_ERROR_CODE1045/FINAL_STATUSFAIL. Метаданныене получены. Системныйrootне означает автоматическиMySQLroot. Повторять этот способбезновогооснования, сбрасыватьauthилидобавлятьправа не требуется и не разрешено.

Вариантhelperсинтерактивнымзапросомреквизитов был подготовленлокально, но публикацияотклоненаинструментом. Онне включёнвветку/не переданальтернативнымканалом. Прежнийотклонённыйsettings-parserне повторялся. ТеперьподготовленДРУГОЙартефакт `deployment/read-v5-db-metadata.sql`, состоящийтолькоизSELECT длясуществующейавторизованнойSQLсессии. Внёмнеткодавхода,запросапароля,credentialfilesилиPHP.

**Почему:** требуютсяметаданные, а не изменениебезопасностисервераилиочередноеугадываниеучётки. УжеавторизованнаяконсольпозволяетотделитьнеобходимыеSELECT отнеподтверждённогоавтоматическогоподключения. Наличие/продукттакойконсолине утверждается; безлегитимногодоступавыполнениеостанетсянеподтверждённым.

**Scope:** одинSELECT текущейDB/версии, затем4SELECTinformation_schema сфиксированнойschemaeurasia_new и10целевымиименами. НетUSE/SET/BEGIN/COMMIT/ROLLBACK, чтобы не менять состояниесуществующейсессии. Нетapplicationrows/defaults/triggerbodies/cardinality/DDL. Пустойmetadataрезультат не доказанноеABSENT; учитыватьправа. РаздельныеSELECT неатомарныйsnapshot.

**Evidence:** фактическийлогоператора1045; liveHEADdf25f34 иoffline38774752671SUCCESSпроверены. НовыйSQL-onlySHA256 `4774dd00c565b36804ebe4d004d3b9a72c7192b80e9ee2c08f44f34b451bf84a`, blob `4dd1cb344e5cf73ad6984a379cee0f89b437f81b`. Статическипроверены5SELECT/4information_schemaисточника/фиксированныйschema/отсутствиепишущихконструкций. SQLнеисполнялся, новых406DBchecksилиnativeproofне заявляется. Следующийнеобходимыйввод—результатSELECT, неcredentials.

## R18. Предыдущий допуск к metadata — сохраняется в его границах

Пользовательсказал«дальше»наконкретныйзапросread-onlyструктурырабочейБД. Это неDDL/клиентскиестроки/включениеV5. ПодготовленныйsockethelperсохранялсясsessionREADONLY,10именами,doublesnapshot,timeoutиOFF/V4guards; serverrun теперьнеPENDING, аAUTH_FAILEDпоR19. Егоcode/historyнеудалены. Доступчерезsettingsparserне выполнялся.

## Сохраняемые решения

| ID | Было → стало → причина |
|---|---|
| R1/R2 | LateUNKNOWN→SENT толькоindependentreceipt безsend/quota/TTL; canonicalsortfixture сохраняетruntimeguard иисториюFAIL. |
| R3/R7 | VMкакblocker → V4/V5насуществующемevrasia,общиеаккаунты/БД → прямое решениепользователя; securityдопускисохранены. |
| R4 | Mainpushпубликуетlatest → код/docsтолькоfeature → нетпобочнойпубликации. |
| R5/R6 | SMSbridgeexactSQL/ownedCOMMIT; freshlockedpasswordBEGINadapter/общийepochHMAC → не приниматьнеcommit/staleproof. |
| R8/R9 | Постоянная503заглушка → parallelentry/lazytransport/privateoff-pilotconfig → operationalkeyнеaccountauth. |
| R10/R11 | Layout+installerCI → фактическаяOFFinstallPASS → файлыесть, nativeauthне включена; backupf89wzMkAсохранён. |
| R12/R13 | PublicDNSself-callRC7 → origin--resolve →2HTTPSOFFпутиPASS; externaledge/nativeauthнепроверены. |
| R14/R15 | ВременныйPHPshimнеопубликован; readonlywebconfig→PASS → nginxSSLhttps8443/ApachePHP83 безизменениянастроек; runtimeTLSmetadataнеаттестованы. |
| R16 | Раздельныеstores → общаяMySQLcomposition/accountscope1–32ID → единыепроверкивхода/challenge/refresh/PIN. Code6f8a352, CI38771014889/38771014920PASS406; nativepositiveproviderssynthetic. |
| R17 | InstallerpinsнесовместимысevolvingHEAD → immutable0caa0248дляpackage-regression;DBtestscurrentHEAD → productionpinsнеснимать. |

## Изменения текущего шага

Добавлен толькоSQL-onlymetadataфайл; START HERE/checkpoint/этотреестр уточняютфактический1045. Исходныйsockethelper, runtimesrc/API,PROPOSEDschemas,workflows,productionrelease,root/V4/mainне меняются. Послеmetadataрезультатовготовитсяточнаяadditiveмиграциясотдельнымдопуском, а не автоматическийDDL.

Nativefactory/TOTP/replay/phone/trust/SMS/RestIS/permitиконтролируемаяприёмкане завершены. Не повторятьзакрытыеCUser/PhoneAuthsource-onlyаудиты и не возвращатьтребованиеVM. Не запрашиватьпароли вчате.
