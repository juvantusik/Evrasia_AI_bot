# V5 — реестр интеграционных решений, 15 октября 2026

Актуальное дополнение к STRUCTURE_REGISTRY. Полная предыдущая редакцияR1–R10 сохранена в commit6e5def78ee5c5a2fd5e63ba3ce0e6291c45c5ab1. Новый фактR11: установка на сервере завершена; это не новое проектирование архитектуры.

## R11. V5 установлен OFF; следующий шаг — публичный HTTP без включения

**Было:** layoutPASS, installer/testc37ab995 прошёл CI, но его production результат ещё ожидался.

**Стало:** оператор прислал полный успешный INSTALL_DISABLED_ONLY. Runtime0caa0248c783188939398a71543ba740f064bb90 реально установлен: public `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5`; private `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90`. Режимoff. Backup `/root/ev-v5-off-backup.f89wzMkA`; rollback там же `rollback.sh`. CLI отsite_evrasia подтвердил503 и отсутствиеnativeBitrix; root/V4rewrite unchanged; FINAL_STATUS=PASS/FINAL_RC=0. DB/Bitrix/SMS/HTTP/reload не выполнялись installer.

**Почему:** факт сервера выше прежнихpendingdocs. Повторять layout/install не нужно. Файловое размещение готово, но auth/backend не включены. Корректно различать V5_FILES_INSTALLED_ON_PRODUCTION=YES и PRODUCTION_AUTH_ENABLED=NO; прежний общийPRODUCTION_DEPLOYED=NO без уточнения теперь вводил бы в заблуждение.

**Следующий инструмент:** `deployment/check-disabled-v5-http.sh`. Без измененияapp/config два анонимныхGET на evrasia.rest: directV5entry и nestedV5path. Только после проверкиoff/manifest/backup/V4pins; безcredentials/POST/SQL/SMS. TLS verified, redirects/retries/proxies/curlrc не используются. Требует503, exactOFFJSON,no-store,no-cookie/no-redirect/no-CORS/no-age; после HTTP повторяет state guards. Первая ошибка останавливает последующие запросы. Rawresponse не выводится, TLS error не обходится insecure.

**Evidence нового helper:** локальныйbash-nPASS, wrong-hostdenial сHTTP_REQUESTS=0 и9header-parserfixturesPASS; blob27e3a6466f78457b956dc0778f36abf1b1e6925c, SHA256deb2ad8e245c63e3d12d182c0a23ce01197052856e570ffe41095a1340b83e6f. Положительный siteHTTP ещё не выполнен; native effects неверного rewrite не инструментируются. Этот helper не доказывает webSAPI,PHPHTTPS/peer mapping,V4E2E,nativeauth или готовностьpilоt. У accesslogs возможны записи. Установленный runtime/src/API/SQL/workflow не меняется этим инкрементом.

## Сохраняемые решения: было → стало → почему

| ID | Решение |
|---|---|
| R1 | Не было позднего UNKNOWN→SENT → independent receipt reconciliation без send/budget/TTL → неопределённую оплату не исправлять второй SMS. |
| R2 | Неотсортированная quota fixture → canonical sort только fixture → runtime lock guard не ослаблять, историяFAIL сохранена. |
| R3 | План отдельного staging → обязательнаяVM отмененаR7 → пользователь её не выделяет. |
| R4 | Main push публикуетlatest → работа/docs толькоfeature → без побочной публикации радиdocs. |
| R5 | SMSqueries/ACK несовместимы сbridge → exactSQL иownedCAS/COMMIT → толькоcommittedacknowledgement. |
| R6 | НетlockedpasswordBEGINadapter → currentnative rows и прежнийepochHMAC → stale password snapshot недостаточен. |
| R7 | VM какblocker → V4/V5 параллельно наevrasia, общиеаккаунты/БД → инфраструктура не подменяет продукт; security/productionpermissions не отменены. |
| R8 | Постоянная503заглушка → отдельныйentry/lazytransport/defaultoff → reuseworkflow безV4fallback. |
| R9 | Нетprivateconfig/shim → boundedoff/pilotJSON иguardedentry → operationalkey неaccountauth; nativefactory пока нет. |
| R10 | Неизвестенlayout → operatorlayoutPASS + pinnedOFFinstaller → реальныефайлыV5 вместообщегоаудита. c37ab995 исправилbuilder-errexit; CI38754541007/38754541037PASS, прежние26offline/329DB сохранены. **Теперь productioninstall тожеPASS поR11.** |

## Дополнение реестра структур

- Новое фактическое состояние publicV5/private release и backup/rollback записано вSTART HERE/checkpoint. `installed.sha256` созданinstaller вprivate/samzaberu-v5.
- Новый `deployment/check-disabled-v5-http.sh` — только ограниченный read-only HTTPcheck существующегоOFFdeployment; локальныеfixtures неsiteacceptance. Installer не изменён и не перезапускается.
- Runtime`src`, `api/v5`, keys/config, nativeproviders иPROPOSEDSQL: без измененийR11; новыйhelper не обновляет установленныйcommit.
- V4/rootrewrite иmain не менялись. РабочаяБД иnativehooks не затронуты установкой. Новыеproduction-файлыV5 уже есть, нельзя продолжать писать «никакогоdeploymentнебыло» без уточненияOFF.

Путиdeployment/src/api относительноsite-integration/samzaberu-v5. ЗакрытыеCUser/PhoneAuth/OTPsource-onlyисследования не повторять. PendingTOTPdraft и заблокированныйpayload не использовались. Настоящиеfactory/TOTP/replay/phone/events/trust/SMS/RestIS иDDL остаются отдельными интеграционными задачами.
