# СамЗаберу V5 — checkpoint: разрешено чтение метаданных БД

15 октября2026. **Следующий ожидаемый ввод — результат read-v5-db-metadata-socket.sh.** Пользователь сказал «дальше» после запроса read-only допуска. Не повторять вопрос о разрешении этого чтения, layout/install/HTTPS/web-config. Миграция этим не разрешена.

## Новое действие

Helper `site-integration/samzaberu-v5/deployment/read-v5-db-metadata-socket.sh`, SHA256 `4158763756d3c9eb2c36e633465e286f67ac43a3f3788c6e9276bbbf1b5bc310`, blob `7cb46e944458ea82a2b3277cb1e466d0d456721b`.

Только local MySQL client, root/evrasia, schema eurasia_new и socket /run/mysqld/mysqld.sock. Локальный вход без предоставленного пароля должен уже работать, но **его доступность не подтверждена**. Нет TCP/password fallback или изменения auth. Пустой HOME/--no-defaults; PHP, Bitrix и credential files не используются.

Сначала OFF manifest/root/V4 guards, затем session READ ONLY/transaction READ ONLY и ограниченные information_schema TABLES/COLUMNS/STATISTICS/TABLE_CONSTRAINTS по10фиксированнымименам. Нет SELECT из таблиц приложения/defaults/trigger bodies/cardinality/customer rows. Два снимка сравниваются, ROLLBACK закрывает read-only scope. NOT_VISIBLE не приравнивается к отсутствию без учёта прав. В конце OFF/V4 SHA повторяются. SQL относится только к диагностике метаданных; runtime native single-connection контракт не меняется.

Локальные bash-n/Pythoncompile/wronghost(безDBattempt), SQLscope и8/8 outputfixtures PASS. Это не реальная MariaDB/prod проверка. Серверный результат **PENDING**. Первый вариант .settings.php parser был заблокирован и не публиковался/не передавался оператору; он не переупаковывался. Этот альтернативный вариант вообще не читает settings/passwords и не исполняет PHP.

Если FAIL: анализировать phase/DB_CLIENT_RC/DB_ERROR_CODE. Не запрашивать пароли и не отключать серверную аутентификацию. Если PASS: оценить фактическую структуру для отдельного additiveDDL плана. FINAL_STATUS=PASS означает успешное чтение, не readiness/nativeacceptance.

## Сохраняемая точка

Runtime насервере0caa0248c783188939398a71543ba740f064bb90, off. Public `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5`; private `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90`; backup `/root/ev-v5-off-backup.f89wzMkA`; rollback тамжеrollback.sh не запускался. Установка и2 originHTTPS пути PASS; web-config snapshot PASS, PHP_RUNTIME_MAPPING_ATTESTED=NO. Nativefactory/pilotkey/V5DDL не установлены.

Последний CI-tested runtime-код6f8a35211671aaa25f8129eeac90b802944646cd; offline38771014889/ephemeral38771014920SUCCESS;406checks=43+117+45+63+61+77. Положительные внешниеproviders иBitrix facade synthetic. Новая composition/accountscope неdeployed. Installer/testc37ab995 иimmutable package0caa0248pins не менялись.

Веткадоинкрементаdcc473b432f66e01175fe101c511e534da7e88ba; полные прошлыеcheckpoint/START HERE/решения доступны в этомcommit. Новый helper/docs не изменяетsrc/API/SQL/workflow/main илиproductionфайлы. Main не трогать: pushпубликуетlatest. VMпользовательневыделяет, V4/V5параллельнонаevrasia.

Nativefactory/testIDs/TOTP/replay/epochhooks/phone/trust/SMS/RestIS/permit/DDL остаютсяотдельнымиблоками. Не включатьпилотсфиктивнымproof, не запускатьCI/faultschemas нарабочейБД. Закрытыеsource-onlyаудиты не повторять. ПодробныеwebSHA и история находятся вSTART HERE dcc473b и операторскихлогах; онинеутеряны.
