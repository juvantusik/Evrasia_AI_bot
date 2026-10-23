# Публикация текущих документов регистрации V5 поверх подтверждённого двухфайлового загрузчика

**Дата:** 23 октября 2026. **Статус: подготовлено в feature branch; на production ещё НЕ запускалось.** Пользователь прислал подтверждённый READ_ONLY отчёт: `FINAL_STATUS=PASS`, `WRAPPER_RC=0`. Текущий V5 public shim SHA256 `f502ae4718a3193d2765d30b75dff638a684cb75078534fecfd3524dce3b94d8`; локальный `site.php` SHA256 `595698a40449b78e3b32335ec09a6c75bbe62fe9f0533acf0d3f274a262e7a51`; внутренний V5-router SHA256 `6e534f93bcf873eda337439443cb3670f634f0afc1bfe9938e8acc4cef5ae37d`. Все три файла синтаксически корректны, неизменны во время скана. Публичный shim совпал с источником из сохранённого кандидата `/root/ev-v5-checkpoints/v5-bootstrap-corrected-20261023.Vj1s42V9`, сайт-entry найден среди подтверждённых checkpoint файлов, внутренний маршрутизатор равен ранее закреплённому исходнику. Он отличается от другого, более старого GitHub release-based шаблона.

## Как именно ставим, не ломая V4

**Правильная производственная цепочка:** `/public_html/api/v5/router.php` → `/public_html/local/samzaberu-v5/api/v5/site.php` → `/public_html/local/samzaberu-v5/api/v5/router.php`. Внутренняя папка `local/samzaberu-v5/src` содержит классы обработки запросов. **Нельзя** переписывать внешний V5 router по шаблону `deployment/public-router.php` и не нужно переделывать `site.php`.

Новый guarded installer: `site-integration/samzaberu-v5/deployment/upgrade-twofile-public-registration-v5.sh`, источник V5 **immutable Git commit** `0a035f9603686d07643ecefadd3e4057c7fae34a`.

Поставляем:
- `api/v5/router.php` Git blob `93c02ff1483d7f49aff567ac7cda3f6a12578dc1` — только **локальный внутренний** роутер.
- `src/V5BitrixPublicLegalCatalog.php` Git blob `241d28491ac42e4e6052bf18df7758ffb1307d85` — реальный read-only `b_consent_agreement` через текущую Bitrix DB-конфигурацию.
- `src/V5LegalConsentContract.php` Git blob `0239f2350ce1f7eb49a79711cc58ff9d9c094532` — реальный V5 контракт документов.
- `src/V5PublicLegalCodeValidation.php` Git blob `1e6014a101483f8c04b03e6304fabd375e5cc6d8` — проверка отправленных кодов текущих редакций, без записи согласия.

Если любой существующий файл из этих трёх `src` **отличается** от закреплённого Git исходника, установщик остановится без его перезаписи. Если совпадает — оставит без изменений; если отсутствует — установит исключительно новый код V5. Source из PHP не исполняется при проверке SHA; PHP lint не делает Bitrix bootstrap.

Новый installer проверяет хост `evrasia`, root, UID/GID сайта, реальные SHA3 загрузчика, точные SHA V4/router/.htaccess, безопасность путей и авторизации, **42 записи** старого `installed.sha256` (41 правильно закреплённых исходных файлов + ровно одно уже объяснённое несоответствие публичного V5 shim). Существующий `installed.sha256` **НЕ меняется**. Поэтому его общий `sha256sum -c` останется FAIL, пока не будет отдельно согласована новая целостная baseline. В ходе этого выпуска нельзя автоматически делать вид, что manifest исправен.

До переключения создаётся приватный root-checkpoint `/root/ev-v5-checkpoints/v5-legal-twofile-20261023.*`, проверяются резервные копии публичного shim, site.php, внутреннего router и manifest. Подготавливаются и закрепляются SHA четырёх V5 исходников. Переключается **только внутренний** `local/.../api/v5/router.php` через атомарный `mv -Tf`, а остальные известные V5 и все V4 остаются в текущем виде. При последующей ошибке делается **условный rollback только внутреннего router**, если его текущий SHA по-прежнему равен точно установленному; при конкурирующих правках rollback не перетирает неизвестные изменения. Новые V5-only библиотеки при таком отказе могут остаться в `local/src` (без публичной активации), сохраняя проверяемость; они не регистрируют V4 route или аккаунты.

## Приёмка настоящих Bitrix данных (без пользователя, SMS и записей)

До изменения проверяется текущий `GET https://evrasia.spb.ru/api/v5/health`, подключение по точному origin `192.168.103.141`, TLS, `auth_ready:false`. После изменения обязательны настоящие `GET /api/v5/legal/consents` и `GET /api/v5/legal/registration-documents`: оба должны дать HTTP 200, **3 актуальных документа с одинаковым JSON**, обязательными offer и personal_data. `POST /api/v5/legal/consents/validate` выполняется с двумя **прочитанными от Bitrix** актуальными code и должен вернуть `valid:true`; этот POST **не записывает согласие**. Отдельно `POST /api/v5/auth/signin` с пустым JSON должен остаться **503 / v5_unavailable**. HTTP строгий HTTPS, no-store, JSON, без cookie, redirect и CORS header.

**Домен мобильного API:** `https://evrasia.rest`. Предыдущий self-curl с сервера на свой публичный адрес дал RC=7, HTTP 000; возможен hairpin/NAT и он **не является самостоятельным свидетельством отказа внешнего API**. Новый installer намеренно проводит **обязательную проверку origin** без ложного утверждения, что внешний `evrasia.rest` протестирован. Реальная внешняя приёмка с мобильного интернета/отдельного внешнего агента проводится позднее и фиксируется явно: `EXTERNAL_EVRASIA_REST_ACCEPTANCE=PENDING` до получения доказательства.

## Выпуск и ограничения

- Не запускать прежний `upgrade-public-registration-documents-v5.sh`: рассчитан на release-based shim с переменной `$release` и другой `installed.sha256` baseline. Его старый guard завершился корректным STOP.
- В целевой ветке GitHub проверить Bash lint, строгое отклонение на неверном хосте и checksum скачанного нового скрипта. Только после успешного CI передавать оператору одну команду запуска exact pinned Git blob.
- Успешный GitHub CI означает **только пригодность к операторскому запуску**, не подтверждение production. После оператора нужно посмотреть `FINAL_STATUS`, `ROLLBACK_STATUS`, `ORIGIN_HTTPS_ACCEPTED` и проверить `EXTERNAL_EVRASIA_REST_ACCEPTANCE` отдельно. Не интерпретировать success от скачивания как live success.
- Production V4 остаётся временно действующим; его прекращение произойдёт только после полного замещения через V5 и проверки всех потребителей. V5 auth и коммерческие/cart write-route этим релизом не включаются.

**NEXT_STEP:** получить CI для нового guarded two-file installer → один строго закреплённый root run на evrasia → разобрать фактический результат и только затем объявлять origin-публикацию. Полный публичный V5 и защищённые методы остаются отдельным этапом.

## CI и следующая точка исполнения

**GitHub CI completed SUCCESS:** new two-file installer source commit [`4826138f`](https://github.com/juvantusik/Evrasia_AI_bot/commit/4826138f79f9b2bdb1550516f43d55001ee46bcf), immutable script Git blob `6ec8e9099f87fdb7bcab99f578113256a67bc2c6`, [offline safety workflow #39997003587](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39997003587) `completed/success` — Bash syntax and negative wrong-host denial. **Operator has not yet run the new installer; do not claim production deployment.** Next: run the exact pinned script through a single guarded root wrapper, inspect final postcheck/rollback output, then independently test `https://evrasia.rest` from a real external network. Old v4 and protected V5 AUTH remain untouched.

## Исправление ограничения проверки манифеста по реальному выводу оператора

Первая попытка запуска двухфайлового скрипта `6ec8e909...` 23 октября **не выполнила никаких записей в production**: ошибка `FAIL: manifest entry outside known private code release` во втором этапе, `ROLLBACK_STATUS=NOT_NEEDED`, `LOCAL_V5_ROUTER_SWITCHED=NO`, `LOCAL_V5_LIBRARIES_ADDED=0`, `WRAPPER_RC=2`. Ошибка в составе разрешённых путей установщика, **не в файлах production**: исходный `install-disabled-v5.sh` добавлял в 42-записный манифест файлы приватного релиза **и оба публичных** `api/v5/router.php` + `api/v5/.htaccess`. В новом проверялись только приватные файлы и router.

Минимальное исправление: новой версии установщика `upgrade-twofile-public-registration-v5.sh` Git blob `18cfd9fc96f21e6c76f603b409ec41094eca11d8` разрешён строго один дополнительный известный путь `$WEB/api/v5/.htaccess` с закреплённым SHA256 `5d10b20b840fe3f36a348a64a4fe5aee2b9050559cb5d429d6e12196929ed08f` (исходный Git blob `a1a05fb6...`); любые иные файлы вне приватного релиза по-прежнему отвергаются. Неправильная запись публичного router из старого манифеста остаётся единственной допущенной известной исторической ошибкой. Добавлен тест сравнения с исходным установщиком. **Повторить запуск можно только после успешного CI на новом коммите и SHA** — старую команду с blob `6ec8...` больше не использовать.

## Исправленная версия прошла CI

**Post-fix CI verified:** corrected code commit [`3c9fe2f3`](https://github.com/juvantusik/Evrasia_AI_bot/commit/3c9fe2f3696c5192b44b8d8d2d4042771937db40), pinned installer blob `18cfd9fc96f21e6c76f603b409ec41094eca11d8`; [offline safety GitHub Actions run #39998018187](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39998018187) **completed SUCCESS**, including Bash lint, wrong-host denial, and new original-V5-manifest public `.htaccess` regression test. **Not yet rerun on production.** Next: operator runs only corrected pinned script with production guards; if PASS, origin HTTPS business public endpoints have actually been accepted, while independent external `evrasia.rest` mobile network E2E remains PENDING.
