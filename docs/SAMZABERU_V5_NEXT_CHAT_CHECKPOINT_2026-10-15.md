> **CURRENT NEXT — 2026-10-16:** historical DB metadata/DDL are DONE. Production six V5 tables installed EMPTY (15.10 operator PASS), V5 OFF/503, no site factory, no per-user AUTH_EPOCH; do not re-run DDL. Feature HEAD after E2E `087f6c1a0b407e24f3c88a89496a0c704e3cd9fa`. Offline CI 38873622210 SUCCESS /26 suites. Ephemeral MariaDB CI 38873622382 SUCCESS /490 assertions =43 sessions +447 dependent, +45 new native-event checks (with SYNTHETIC Bitrix facade). New `V5EphemeralMySqlNativeEventVersionWriterTest.php` shows shared InnoDB password/epoch commit+rollback, same-E164 reconfirm and revocation. Still no native production hooks/real transaction permit/actual ten-path source acceptance. Follow-up: implement/verify real native writer entrypoint coverage without modifying live V4 until separate acceptance. Do NOT re-run old broad audits or create VM.

> **ACTUAL NEXT 2026-10-15 after CI:** 6 production V5 tables INSTALLED EMPTY, V5 OFF. Feature code `V5PilotAuthEpochInitializer.php` added/fixed (code commit `45782ed0653b74a4eb5b4659551bfb70ad4a0ec1`); strict ten-path gate required; cohort 1-32; b_user→MFA→phone→state row locks; AUTH_EPOCH=1 and PHONE_EVENT_VERSION=0 only inside caller-owned native bridge transaction; no commit/bulk backfill. MariaDB synthetic tests +39, total445 PASS, run `38782468454`; offline26 PASS run `38782468148`. NO real installed hooks/provider, no use in production. Next engineering target: independently verified native writer hooks and transaction permit, not another DDL. Preceding pre-DDL headings below are historical.

**ACTUAL CHECKPOINT — 15 октября 2026, 17:49.** Шесть V5 service tables УЖЕ СОЗДАНЫ production `eurasia_new` через подтверждённый additive DDL; InnoDB/EMPTY/constraints PASS; HTTP 503, V4 unchanged, V5 off, AUTH_EPOCH_INITIALIZED=NO. `FINAL_STATUS=PASS`, `FINAL_RC=0`. Schema-only evidence `/root/ev-v5-ddl-backup.20261015T174912Z.2010173`. Не выполнять migration снова, не инициировать per-user epoch rows до принятой atomic native coverage. Дальше: feature code + tests native event prerequisites, без production write/pilot. Текст предыдущего checkpoint ниже — история периода до миграции.

# СамЗаберу V5 — checkpoint: чтение БД остановлено на 1045

15 октября2026. Последний ввод оператора: `Вставленный текст(20261015-171249).txt`, строки490–521. **Не повторять root/socket без пароля: этот способ уже отказал.** Разрешение только metadata read сохраняется; миграция не разрешена.

## Результат

OFF package/V4 hashes/local socket path PASS. Затем DB_CLIENT_RC=1, DB_ERROR_CODE=1045, FINAL_STATUS=FAIL, FINAL_RC=2. DATABASE_CONNECTION_ATTEMPTED=YES, DATABASE_WRITE/CLIENT_ROWS_READ/DDL/PHP/Bitrix/HTTP/SMS/reload=NO. Структура таблиц НЕ получена; наличие V5таблиц и движки не установлены. Это отказ входа, не ошибка V5 и не повод к rollback/переустановке/authreset.

Первоначальный helper `deployment/read-v5-db-metadata-socket.sh` остаётся вGit неизменным с историческим SHA4158763756d3c9eb2c36e633465e286f67ac43a3f3788c6e9276bbbf1b5bc310. Его нельзя выдавать как подтверждённоработающий способ подключения. Черновик варианта --prompt-auth был заблокирован при публикации и не включён вветку/серверныйблок/другуюдоставку. Settings-parser тоже не повторялся.

## NEXT: только SELECT в уже авторизованной консоли

Новый `deployment/read-v5-db-metadata.sql`: пятьSELECT, включаяDB/version иTABLES/COLUMNS/STATISTICS/TABLE_CONSTRAINTS появнойschemaeurasia_new и прежним10именам. Нет подключения/паролей/PHP/USE/SET/transactioncontrol/DDL/applicationrows/defaults/triggers/cardinality. Не нарушает существующую transaction-конфигурацию SQL-консоли. Нужна существующая разрешённая сессия с видимостьюметаданных; продуктконсоли и наличиеоткрытойсессии неизвестны. Не создавать её через обход аутентификации.

SHA256 `4774dd00c565b36804ebe4d004d3b9a72c7192b80e9ee2c08f44f34b451bf84a`, Gitblob `4dd1cb344e5cf73ad6984a379cee0f89b437f81b`. Только статическипроверен составSELECT иscope; SQL не выполнялся, server-resultPENDING. Не называтьпустойответ доказательствомотсутствиятаблицы безучётаправ; отдельныерезультаты не единыйатомарныйснимок.

ОжидаетсявыводSELECT, не пароль и не дамп. SQLблоквыполняетсявSQL-консоли, неshell. Отдельного разрешения наDDL этимнет. Не менятьпарольroot/плагины/GRANT/skip-grant-tables; отсутствиеизвестногодоступа не лечится переборомcredentials.

## Неизменённая рабочая точка

Наproductionruntime0caa0248c783188939398a71543ba740f064bb90, off; public `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5`; private `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90`; backup `/root/ev-v5-off-backup.f89wzMkA`; rollbackтамжеrollback.shне запускался. OFFinstall/2originHTTPS/webconfigPASS; PHP_RUNTIME_MAPPING_ATTESTED=NO. Factory/pilotkey/V5DDLне установлены. VM не требуется, V4/V5рядомнаevrasia.

Последнийruntimecode/test6f8a35211671aaa25f8129eeac90b802944646cd; runs38771014889/38771014920SUCCESS,406checks. Nativepositiveproviders/Bitrixfacade synthetic. Installer/testc37ab995 иpackage0caa0248pinsне менялись. Передэтимшагомlivefeaturedf25f34,егоoffline38774752671/job116559386179SUCCESS. НиCIниновыйSQLфайл не доказывают servermetadataPASS.

START HERE иреестррешенийсодержатновыйR19; полныепредыдущиередакциивdf25f34/dcc473b. Кодsrc/API/workflows/PROPOSEDSQL/main/установленныйreleaseне меняются. Nativefactory/TOTP/replay/epochhooks/phone/trust/SMS/RestIS/permit/DDL иприёмкапо-прежнемуотдельныеблоки. Закрытыесerverпроверки иsource-onlyаудитынеповторять.
