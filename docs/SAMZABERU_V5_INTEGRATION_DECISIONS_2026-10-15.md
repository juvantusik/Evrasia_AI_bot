## R22 (2026-10-16): Validate *existing* event writer in physical InnoDB, not post-update hooks

**Было:** `V5SecurityEventVersionWriter` checked via fake PDO; `V5PilotAuthEpochInitializer` had real InnoDB test but no joint native-style password/phone mutation-to-epoch acceptance.

**Стало:** New GitHub-only `V5EphemeralMySqlNativeEventVersionWriterTest.php` imports prior suites once, exercises existing `V5SecurityEventVersionWriter` and the SAME underlying PDO through synthetic Bitrix mysqli/bridge against a disposable MariaDB. Explicit b_user FOR UPDATE precedes synthetic native password/phone write. Password hash + AUTH_EPOCH CAS become visible together after commit; simulated CAS affected-count failure requires caller rollback and undoes BOTH; absent per-user state also causes rollback in this synthetic V5-only scope. Two successful native phone reconfirms of the **same E.164** increment PHONE_EVENT_VERSION and invalidate prior stamp; revoke clears HMAC; later re-confirm creates new stamp. Negative false revocation fails. A same-number business event cannot be proven merely by matching SQL UPDATE; real trusted event callback is still missing.

**Evidence:** source+test `087f6c1a0b407e24f3c88a89496a0c704e3cd9fa`. Offline Action `38873622210` SUCCESS 26 suites; disposable MariaDB `38873622382` SUCCESS 490 assertions (**43 session checks separately +447 dependent checks**, +45 new). All native Bitrix calls/ten-path evidence are CI FAKES, so `NATIVE_HOOKS_PROVEN_ATOMIC=NO`, `REAL_BITRIX_STAGE_E2E=NO`; V5 remains disabled on site. MAIN unchanged; no production write/DDL/reload/SMS. **No assumption** that OnAfterUserUpdate is atomic; OTP::save recovery-code cleanup precedes ORM add event, so a transaction begun in OnBeforeAdd is too late for full multi-step mutation. No core changes authorized.

## R21 (2026-10-15): scoped epoch initialization without native event bypass

**Было:** six new tables created but AUTH_EPOCH empty; generic/automatic backfill forbidden. Native10-path hooks absent, no trusted user evidence.

**Стало:** GitHub-only `V5PilotAuthEpochInitializer` requires the concrete Bitrix PDO compatibility bridge and concrete strict10-path coverage gate, same owned native transaction, explicit 1–32 pilot IDs and current nonblocked native user. Lock order b_user→b_sec_user→b_user_phone_auth→ev_samzaberu_auth_state. Inserts (AUTH_EPOCH=1, PHONE_EVENT_VERSION=0, PHONE_BINDING_HMAC=NULL), no phone text migration, no auto-commit or auto-repair; idempotent existing row returns false. Production has no native evidence implementation/site factory, so cannot call this candidate.

**Evidence:** initial ephemeral CI FAIL (2026-10-15 `38782204373`) caught null-coalescing bug in post-insert verifier. Small fix commit `45782ed0653b74a4eb5b4659551bfb70ad4a0ec1`, re-runs: offline `38782468148` SUCCESS (26 suites), ephemeral `38782468454` SUCCESS (**406 old +39 new =445**). Real MariaDB transactions and b_user locks tested with synthetic Bitrix facade/native evidence. These tests do NOT prove full native hooks, TOTP replay, real transaction permit or production readiness. No site/production files or accounts changed by this code increment.

## R20 (ACTUAL 2026-10-15): Production additive DDL completed

**Было:** schema `eurasia_new` проверена read-only, шестёрка V5 tables отсутствовала; DDL-кандидат принят, production CREATE ещё не выполнялся.

**Стало:** оператор выполнил точный Git-blob кандидата (commit `2445b462ade3cfae588a77d291a28726c129b33d`, blob `f98d2fdf2e9f013b69f6cf373afad136cb7c5cde`) с root/V4/OFF guards, схемным backup и проверками после CREATE. Первый пробный запуск от `tech` отказал до DDL; следующий root-скрипт остановился на ложном совпадении `GRANT` внутри имени индекса (также до DDL); итоговый исправленный блок успешно создал ровно шесть таблиц.

**Evidence:** `DDL_TABLES_CREATED=6`, `ALL_NEW_TABLES_INNODB=YES`, `ALL_NEW_TABLES_EMPTY=YES`, `CONSTRAINT_GUARD=PASS`, `MIGRATION_POSTCHECK=PASS`, `FINAL_STATUS=PASS`/`FINAL_RC=0`; V4/root hashes unchanged, HTTPS OFF=503; `AUTH_EPOCH_INITIALIZED=NO`. Evidence directory `/root/ev-v5-ddl-backup.20261015T174912Z.2010173` — **schema-only**, не дамп всех строк. V5 installed runtime остаётся `0caa0248...`, `off`, без site factory. Никаких INSERT/backfill/активаций/SMS.

**Почему/следующий gate:** состояние `ev_samzaberu_auth_state` пока пустое и авторизация правильно отказывает: существующий V5SecurityEventVersions требует per-user строку. Инициализация AUTH_EPOCH обязана идти ПОСЛЕ доказанного atomic native writer/10-path coverage; наличие таблиц не заменяет hook attestation. Не повторять CREATE или автоматически DROP; частичные фазы миграции уже не актуальны.

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
