# V5 — реестр интеграционных решений, 15 октября 2026

Актуальное дополнение к STRUCTURE_REGISTRY. Полная предыдущая редакция R1–R9 сохранена в commit `984cbc207a35309a6e70c24a0ce38ffa91d589d8`. Новая запись — R10. Детали предыдущих компонентов доступны в Git history и API README.

## R10. Layout подтверждён; подготовлена выключенная установка

**Было:** private pilot bootstrap готов, но точное размещение, Unix owner и PHP на существующем evrasia ожидали одного read-only блока.

**Стало:** оператор прислал FINAL_STATUS=PASS и FINAL_RC=0: root/evrasia, site_evrasia 1005:1005, известный private sibling уже включён в pool8.3 open_basedir, CLI8.3.21, public/private V5 targets отсутствуют. Root/V4 SHA записаны в START HERE и закреплены в installer. Подготовлен один installer для новых V5 файлов: private runtime release0caa0248 и public shim/rewrite, mode=off, без native factory, ключей, DB settings и тестовых схем.

**Почему:** размещение больше не blocker. Следующее действие — реальные файлы V5 рядом с V4, а не ещё один общий аудит или новая VM. Выключенная установка отделяет файловое размещение от недоказанного backend/pilot/native-security допуска.

**Реализация:** `deployment/install-disabled-v5.sh`. Проверки root/host/UID/PHP/source/path/baseline, tar backup с проверкой, bare Git fixed commit/fsck, закреплённые src/API blobs, подготовка shim, lint, private copy/byte compare, OFF CLI smoke от site_evrasia, атомарный rename publicdir, post-check root/V4/manifest и guarded rollback/quarantine. Нет перезаписи root/V4, reload сервисов, DB/Bitrix/HTTP/SMS. Повторный неизменённый запуск проверяет manifest и возвращает ALREADY_APPLIED; partial/drift отказывается. Разные filesystem для /root и /home допускаются: предварительное копирование в private, а final rename только между private и public на одном filesystem.

**Evidence:** installer/test commit `c37ab9959bb13dae49f9c1a2c4ddda0b06905c69`. Offline38754541007/job116489359396 SUCCESS. Ephemeral38754541037/job116489360214 SUCCESS: отдельный package verification step и прежние DB tests завершены успешно. Полные новые logs не запрашивались; статусы шагов прочитаны. Состав прежних26offline/329DB не менялся. Package verification не новый native/DB test. Локально проверены bash -n и wrong-host denial, local/uploaded blob совпадает. Первый installer71d4a2d уточнён c37ab995: вызов builder без `||` сохраняет Bash errexit.

**Граница:** CI использует временные пути и не выполняет production install/owner/root-backup ветку. На evrasia получен только layout PASS; вывод нового installer ещё не получен. OFF CLI503 не является live HTTP или готовой авторизацией. Root-owned releases под site-owned ancestor не объявлять абсолютной защитой от компрометации site account. Aliases, ancestors, global PHP и rewrites требуют дальнейшей целевой оценки до включения native factory. При post-publish drift auto rollback не трогает неожиданные данные.

**NEXT:** получить результат именно INSTALL_DISABLED_ONLY блока. После PASS зафиксировать installedOFF и фактический backup, затем web boundary/native providers. Не повторять layout preflight и не возвращать отдельный staging-host.

## Сохраняемые R1–R9: было → стало → почему

| ID | Решение |
|---|---|
| R1 | Не было позднего UNKNOWN→SENT → явная independent receipt reconciliation без send/budget/TTL → неопределённую оплату не исправлять второй платной SMS. |
| R2 | Неотсортированная quota fixture → canonical sort только fixture → не ослаблять lock-order guard; прежний FAIL сохранён. |
| R3 | План отдельного staging → обязательная VM отменена R7 → пользователь её не выделяет. |
| R4 | Main push публикует latest → документация и работа только feature → не запускать побочную публикацию ради документов. |
| R5 | SMS queries/ACK несовместимы с bridge → точные SQL shapes и owned CAS/COMMIT → только committed acknowledgement. |
| R6 | Нет готового locked-password BEGIN proof → адаптер текущих native rows с прежним epoch HMAC → stale password snapshot недостаточен. |
| R7 | VM как обязательный blocker → V4/V5 параллельно на evrasia, общие аккаунты/БД → инфраструктура не подменяет продукт. Security/production permissions не отменены. |
| R8 | Постоянная503 заглушка → отдельный V5 entry/lazy transport/default off → reuse готового workflow без V4 fallback. |
| R9 | Нет private config/public shim → bounded off/pilot JSON и guarded entry → отдельный operational допуск не является account auth. Реальной factory пока нет. |

## Дополнение реестра структур

- `deployment/install-disabled-v5.sh`: новый guarded OFF installer,257 строк; runtime payload0caa0248. Installer закреплён commit c37ab995 и SHA256 `3fca39d399b8d60d865fc6a886e589ccd8c3052ae702c66227650098e9577e5a`.
- `.github/workflows/v5-ephemeral-mariadb.yml`: +15 строк, trigger и host-denial/package self-test; прежний DB regression сохранён. Это не deployment workflow, site credentials отсутствуют.
- `src`, `api/v5/*.php`, `.htaccess`, native providers и PROPOSED SQL: без изменений R10.
- Root/V4 baseline и folder/PHP metadata: получены из фактического read-only результата оператора, не из предположений.
- START HERE/checkpoint: layout больше не pending, ожидается installer output; deployment не заявлен.

Пути deployment/src/api относительно `site-integration/samzaberu-v5`. Main/V4/рабочая БД не менялись этой итерацией. Pending TOTP draft и ранее заблокированный test payload не использовались и не повторялись.
