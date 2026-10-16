## R25 (2026-10-16): MFA native Otp::save/delete require pre-call transaction and verified database postconditions

**Было:** native MFA/recovery source had shown `Otp::save()` clears recovery rows before ORM add/update, and `Otp::delete()` returns the Otp object ignoring ORM deletion result. Scoped transaction wrapper intentionally denied ALL OTP variants; relying on ORM OnAfter callbacks cannot cover already-run recovery writes.

**Стало:** feature-only `V5BitrixOtpNativeMutationPort` pins the actual observed core `Otp.php` SHA, accepts only prepared TOTP save/enrollment and existing MFA deletion, requires same bridge-owned native connection; checks unique locked `b_sec_user` row and absence of recovery records before reporting success to the existing epoch-CAS. `V5StagingNativeMutationScope` whitelists these two paths ONLY with the exact typed adapter/matching path; arbitrary callback cannot impersonate OTP. Existing savepoint continuity and full ten-source-evidence gate preserved. This wraps only *future V5-controlled* calls; it DOES NOT intercept admin CSecurityUser, background OtpEvents, global MFA, recovery useCode, phone or logout. Never modify V4 core silently or initialize V5 user state based only on this.

**Evidence:** code commit `884916fa132285cb9007c7981c33d6ec93b43699`, GitHub offline CI `38898193783` 26 suites SUCCESS, ephemeral MariaDB `38898193583` SUCCESS **543 checks =43 sessions +500 dependent, of which 13 new are NEGATIVE source pin/callback-denial tests**. Installed Bitrix OTP positive runtime and atomic rollback remain `NOT_TESTED`. Production V5 remains off, no new writes or activation.

## R24 (2026-10-16): Hidden native COMMIT+BEGIN invalidates V5 mutation scope

**Было:** staged scope verified connection ID, native depth and @@session.in_transaction, but native code could COMMIT then BEGIN on the same connection and return apparently matching snapshots. Thus even in isolated E2E the scope could wrongly assert a common transaction.

**Стало:** generate unpredictable SQL SAVEPOINT immediately before trusted native callback, RELEASE it after callback even on returned failure/throw; its loss marks permanently poisoned UNKNOWN, prevents auto-rollback of a possibly new transaction, never retries and never claims a successful common commit. Verified CI disposable InnoDB native UPDATE followed by COMMIT+BEGIN: early native write already independently visible but epoch unchanged; V5 denies and never calls this 'rolled back'. Existing V4 accounts with absent epoch must not be globally affected. Source/test `1987ddc114352470a476f3f3e5e8eda057458f8b`. Offline `38896370467` SUCCESS 26 suites; MariaDB `38896370806` SUCCESS **530 total (43+487), +7**.

**Critical limitation:** positive native facade/release coverage still SYNTHETIC. SAVEPOINT is additional boundary detection, not ten-path Bitrix proof, and cannot reverse a native write already COMMITTED. Need independent acceptance of exact Bitrix SqlHelper/driver SAVEPOINT error and installed source-pinned native writer paths before staging pilot; V5 production OFF, AUTH_EPOCH empty.

## R23 (2026-10-16): Native mutation entrypoints must start with b_user mutex and verified transaction continuity

**Было:** existing writer atomicity exercised with synthetic native SQL and actual InnoDB, but a separate higher-level scope owning native BEGIN, source gate and full mutation-to-COMMIT handling was missing.

**Стало:** GitHub feature-only `V5StagingNativeMutationScope.php` (commit `2a3751e0c560381be401e5bd3d550d03756bd50a`) requires CLI isolated nonprod, explicit 1–32 approved cohort, concrete V5 native bridge with independently vetted exclusive BEGIN permit, strict installed ten-path coverage, and reviewed mutation port. Only password/reset, confirmed/reconfirmed phone and phone revoke are admitted candidates; all OTP/recovery/logout paths remain deny. BEGIN → depth+MySQL connection continuity → b_user lock BEFORE native write → full coverage gate → reviewed operation → epoch writer CAS → continuity → one COMMIT. Negative outcome or CAS uncertainty before COMMIT rolls back same scope while it is still provably owned. Unknown COMMIT is never reported as success or blindly retried. Explicit warning: identical connection ID/depth do not rule out hidden COMMIT+BEGIN; only pinned native source and genuine staging rollback/concurrency tests could do so.

**CI evidence:** offline run 38889891833 SUCCESS 26 suites; real disposable MariaDB run 38889891967 SUCCESS 523 checks=43 sessions+480 dependent, including 33 new staged-scope assertions. Synthetic MysqliConnection/native evidence/operation only. This is **NOT** a production native hook implementation, trusted coverage attestation or approval to activate. No production DB/site/core changes, actual installed V5 remains OFF/503 by last operator check; the six tables are created already and must not be migrated again. Next gate: targeted actual native pre-write entrypoint and rollback acceptance for all ten categories before any site factory, AUTH_EPOCH per-user rows or pilot.

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
