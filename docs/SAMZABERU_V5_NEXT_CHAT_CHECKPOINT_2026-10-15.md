# СамЗаберу V5 — checkpoint перед выключенной установкой

15 октября2026. **Следующий ожидаемый ввод — результат INSTALL_DISABLED_ONLY блока.** Read-only layout preflight уже получен и PASS. Не повторять его и не просить VM: V5 параллельно V4 на существующем evrasia.

## Подтверждённый серверный факт

Оператор выполнил previous layout block: root/evrasia, UID/GID site_evrasia1005:1005, FINAL_STATUS=PASS/FINAL_RC=0. Docroot `/home/site_evrasia/web/evrasia.spb.ru/public_html`; private sibling существует. Private/samzaberu-v5 и public_html/api/v5 на момент проверки отсутствуют. api/.htaccess отсутствует. CLI PHP8.3.21; pool8.3 конфигурационно использует site_evrasia и уже допускает private в open_basedir. Никакой DB/Bitrix/HTTP проверки не было.

Контрольные SHA256, использованные installer:
- root.htaccess `81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95`;
- api/v4/.htaccess `61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3`;
- api/v4/router.php `3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50`.

## Подготовленный installer / CI

**Installer/test commit c37ab9959bb13dae49f9c1a2c4ddda0b06905c69**. Script path `site-integration/samzaberu-v5/deployment/install-disabled-v5.sh`, SHA256 `3fca39d399b8d60d865fc6a886e589ccd8c3052ae702c66227650098e9577e5a`. Его содержимое совпало с локально linted blob2a0174eb6497d1468ff1f411fe4e6727bce63a8c.

Offline run38754541007/job116489359396 completed/success; ephemeral run38754541037/job116489360214 completed/success. Новый отдельный package step и прежний DB regression success. Полные новые job logs не выгружались; шаги проверены. Состав прежних26offline/329DB не менялся; package tests не являются дополнительными DB assertions.

CI строит пакет из закреплённых src/API blobs, выполняет будущий public shim→private site→router в OFF режиме, проверяет атомарную публикацию на временном filesystem, отказ перезаписи существующей цели и перенос назад при откате. Default installer также проверен на отказ на неверном host. Это НЕ исполнение production install path, ownership/FPM/backup/HTTP/native acceptance на evrasia. Полный положительный пакет локально не запускался (GitHub DNS недоступен); локально bash syntax и host denial.

Первый installer71d4a2d исправлен c37ab995 двумя строками: build_payload вызывается без `|| fail`, сохраняя Bash errexit внутри helper. Приложение, V4 и SQL не менялись. Diff984cbc2..c37ab995:257строк installer и15строк существующего workflow.

## Точный режим серверного блока

Runtime payload **0caa0248c783188939398a71543ba740f064bb90**, а НЕ installer commit. Private release `<private>/samzaberu-v5/releases/<payloadSHA>`, public `/api/v5` содержит лишь shim и versioned.htaccess. Установка root:1005; private0750/0640, public0755/0644. Конфигурация строгоoff, нет site-factory.php/pilot key/DB settings. Не устанавливаются тесты/DDL, не вызывается SMS/RestIS, не меняются root/V4 rewrites или сервисы.

Guards: точные root/host/UID/GID/PHP/path/source pins и current root/V4 SHA; backup с tar compare; Git fixed commit с fsck без checkout/credentials/hooks; lint всех устанавливаемыхPHP; copy compare до публикации; OFF CLI smoke от site_evrasia до/после rename; manifest. Повторный неизменённый запуск ALREADY_APPLIED. При partial/drift остановка, не перезапись. Отдельный rollback.sh в root backup и ограниченный автоматический quarantine нового publicdir при post-check failure. Private package сохраняется для диагностики.

**HTTP_TESTED=NO, BITRIX_BOOTSTRAP=NO, DATABASE_CONNECTION=NO.** CLI503 не выдавать за фактический HTTP503 на сайте. Родительские directories/open_basedir/globalPHP/web rewrite ещё не являются полностью аттестованным web boundary.

## NEXT после вывода

Разобрать результат; приPASS сохранить фактический BACKUP_DIR/ROLLBACK_SCRIPT и state INSTALLED_DISABLED. Затем только целевая web routing/TLS/SAPI проверка и реальная native composition. ПриFAIL учитывать фазу и quarantine, не запускать блок повторно вслепую при partial install. Не включать пилот с пустым transport/фиктивным native proof.

Native TOTP replay,10-path epoch hooks, phone success/reconfirm, strong trust, SMS lifecycle/provider и RestIS остаются незавершёнными. Размещение на shared host согласовано, отказные CI scripts на production запрещены. Pending TOTP draft не в src/CI. История подробных компонентов — START HERE и предыдущая редакция984cbc2.

**Текущий фактический статус до ответа оператора:** LAYOUT_PREFLIGHT=PASS; OFF_INSTALLER_CI=PASS; V5_OFF_INSTALLATION=PREPARED_NOT_CONFIRMED; REAL_SITE_PARALLEL_ACCEPTANCE=NOT_RUN; PRODUCTION_DEPLOYED=NO. Main7c2e9f8e4501b92469bcdeea18f8340676ad52c9 неизменён. Следующий doc-only HEAD не новое тестирование приложения.
