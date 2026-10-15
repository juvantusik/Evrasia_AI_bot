# Evrasia AI Bot / СамЗаберу V5 — START HERE, 15 октября 2026

**Текущая точка: OFF V5 установлен; два origin HTTPS пути и чтение web-конфигурации уже PASS. В feature собрана единая MySQL HTTP composition с ограничением пилотных аккаунтов; обе CI PASS, 406 MariaDB checks.** Не повторять установку, layout,503-проверку или web-config snapshot. Новый чат — продолжение проекта.

Читать этот файл, `SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md`, `SAMZABERU_V5_INTEGRATION_DECISIONS_2026-10-15.md` и новый `SAMZABERU_V5_PILOT_COMPOSITION_2026-10-15.md`. Полная предыдущая редакция сохранена в commit `76796242020915629decd867939bc9fc19009550`; её pending web-config NEXT выполнен.

## 1. Размещение и фактический сервер

**V5 размещается параллельно V4 на существующем Bitrix `evrasia`. Отдельной VM не будет.** Второй MySQL, контейнерная копия сайта и второй набор клиентов не обязательны. Аккаунты/бизнес-данные общие; V5 code/routes/config/keys/tokens/service tables отдельные. Бот `eur-bot-01` не переносится. Issue76 — реальное подключение, не выделение VM. Служебные V5-таблицы можно разместить в существующей БД, но V5 DDL пока НЕ выполнен.

| Объект | Фактическое состояние |
|---|---|
| Установленный runtime | `0caa0248c783188939398a71543ba740f064bb90`, не новый feature HEAD |
| Public V5 | `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5` |
| Private release | `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90` |
| Backup | `/root/ev-v5-off-backup.f89wzMkA` |
| Rollback, не запускался | `/root/ev-v5-off-backup.f89wzMkA/rollback.sh` |
| Manifest | `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/installed.sha256` |
| Config | Строго `off`; настоящая site-factory.php, pilot key и V5 DDL не установлены |

Оператор уже выполнил INSTALL_DISABLED_ONLY PASS: CLI отsite_evrasia дал503, root/V4 rewrite unchanged. Затем origin HTTPS через `--resolve evrasia.rest:443:192.168.103.141`: direct `/api/v5/router.php` и nested `/api/v5/auth/signin` дали CURL_RC0, HTTP503, TLS_VERIFY_RESULT0, exactOFFJSON/no-store/no-cookie/no-redirect/no-CORS/noAge. Итог HTTP_OFF_PATHS_PASS2/LOCAL_ORIGIN_OFF_BOUNDARY=PASS. ПрежнийCURL_RC7 без--resolve — история, не текущая ошибка. Не ремонтировать public hairpin ради self-check.

Новые V5-файлы наproduction действительно есть; правильно: V5_FILES_INSTALLED_ON_PRODUCTION=YES, PRODUCTION_AUTH_ENABLED=NO. Изменениеfeature/docs не обновляет сервер автоматически. PUBLIC_EDGE_TEST=NOT_RUN, V4 functional E2E/native auth не выполнены. Не выдавать503OFF за работающую авторизацию.

## 2. Новый факт: web-config snapshot получен и PASS

Источник — новый операторский лог15октября16:33:50, раздел VHOST/PHP SETTINGS и итог. Четыре vhost, шесть выведенных конфигурационных файлов; CONFIG_CONTENT_STABILITY=PASS, FINAL_STATUS=PASS/FINAL_RC0. SITE_FILE_WRITE/SITE_PHP_EXECUTED/DATABASE_CONNECTION/SERVICE_RELOAD=NO, HTTP_REQUESTS=0.

- `nginx.conf`: proxy_pass `http://192.168.103.141:8080`, expected docroot.
- `nginx.ssl.conf`: proxy_pass **`https://192.168.103.141:8443`**, expected docroot. Не заменять это прежним предположением, что HTTPS всегда проксируется на HTTP8080.
- `apache2.conf` и `apache2.ssl.conf`: expected docroot и `SetHandler` на `/run/php/php8.3-fpm-evrasia.spb.ru.sock`.
- `/etc/php/8.3/fpm/php.ini`: auto_prepend_file/auto_append_file disabled, session.auto_start0, opcache.revalidate_freq0.
- Наличие `/etc/nginx/fastcgi_params` сHTTPS/REMOTE_ADDR переменными отдельно НЕ доказывает, что файл используется в показанной цепочке nginx→Apache→FPM.
- Wildcard includes перечислены, но не раскрывались. Снимок текстов конфигурации не доказывает effective runtime, фактические значенияHTTPS/REMOTE_ADDR или весь include precedence: **PHP_RUNTIME_MAPPING_ATTESTED=NO сохраняется**.

Оснований менять nginx/Apache/FPM или доверять клиентскому X-Forwarded-Proto по этому снимку нет. Сохранять строгую HTTP-границу. Подробные SHA конфигов и выводы — текущий checkpoint; повторный обзор этих же файлов не нужен.

## 3. Новый проверенный код

Repo `juvantusik/Evrasia_AI_bot`, branch `feature/samzaberu-api-v5-auth-contract`. **Последний code/test commit с двумя успешными CI: `6f8a35211671aaa25f8129eeac90b802944646cd`.**

- Offline Actions **38771014889**, job **116546512805**, completed/success. Прежний26-suite workflow и полныйPHP lint.
- Real ephemeral MariaDB Actions **38771014920**, job **116546512653**, completed/success. ПолныйDB job log прочитан:43sessions +117SMS +45receipt +63bridge +61password +**77composition =406**; combined SMS/bridge/password/composition363; FINAL_STATUS=PASS/FINAL_RC0.
- Новый `src/V5MySqlPilotComposition.php`: возвращает существующий V5HttpTransportBoundary с единым workflow, durable consumed proof, challenge consumption/TOTP issuer, strong sessions, refresh иPIN actions. Все соответствующие stores используют одну переданнуюPDO/Bitrix bridge connection и согласованныйbinding key. Сам build не исполняетSQL/DDL, неbootstrap Bitrix, негенерируетcredentials и не активируетroute.
- `V5PilotScopedNativePorts` в том же файле: обязательный server-only список1–32уникальных положительныхintegerID, безwildcard/пустогосписка. Ограничение после first-factor identity распространяется наtrust/challenge/nativefactor/coverage/PIN. Refresh иPIN проверяют его через existing locked coverage и отказывают в следующей пересобранной request composition даже с ранее выданными токенами исключённого аккаунта. Это не permanent revocationDB и не отмена уже начатого запроса.
- Native providers остаются обязательными. Allowlist НЕ подменяет пароль/TOTP/trust/coverage. Первый password provider сохраняет собственные attempt/rate/account правила и может выполнять своё разрешениеidentity до cohort-filter; не утверждать, что запрещённыйaccount никогда не читается этимпровайдером.
- Lifecycle пока только существующий password-bound TOTP issuer. SMS begin/resend сохраняют отказ; SMS consumption требует существующегоSENT/nativeproof. Durable consumed grant всегда перепроверяется `V5MySqlConsumedChallengeProof`, а не произвольнымtrusted-device callback.
- Новые77checks проходят через существующий HTTP transport/handler и настоящие InnoDB sessions/JWT/refresh/PIN locks. Положительные trust/password/coverage/PIN providers и Bitrix facade СИНТЕТИЧЕСКИЕ; новыеTOTPcallbacks всегдаотказывают, алгоритм/replayне реализовывались. Это провереннаяcomposition, не realBitrix/nativeauthentication.

Compare7679624..6f8a352: ровно3файла — новый185строк composition, новый184строки integrationtest, existing ephemeralworkflow +16/-5. V4, schema, API entry, crypto, native modules и installer не менялись. Локально оба новыхPHP lintPASS, Gitblob совпал с загруженным; полныйsuite выполненGitHub, локальныйclone недоступениз-заDNS.

**CI package regression теперь отдельно checkout закреплённого установленного0caa0248**, чтобы первоначальныеsrc/APIpins installer не ослаблять из-за новогоfeatureкода. DB tests по-прежнему выполняюттекущийHEAD, старые suites одинраз. Новыйpackage regressionPASS не deploymentновойcomposition.

## 4. Принятая логика и реализованные структуры

После пароля nativeTOTP имеетприоритет безSMSfallback при ошибке/unknown; иначе толькоstrongV5trust, не слабыйV4; безобоих password-boundSMS на подтверждённыйтелефон аккаунта, не телефонсклиента. После сильного входаPIN без второйSMS. Сохраняются active/block/expiry/Anti-Fraud/attemptlimits,10SMS rate/стоимостныхокон,cooldown,HMAC/encryptedoutbox,user/device/phone/epochbindings,тариф/currency/idempotency. Claimed/unknown автоматическинепересылать. SENTтолькоindependentreceipt,late receiptнеоживляетchallenge/не меняетбюджет. PHONE_EVENT_VERSION меняетсядажеприповторе того жеE.164;AUTH_EPOCH атомаренсnative securitymutations.

Переиспользованы `V5LoginWorkflow`, `V5SessionTokens`, `V5MySqlStrongSessionStore`, `V5MySqlSessionActions`, `V5MySqlPasswordBoundTotpIssuer`, `V5MySqlChallengeConsumption`, `V5MySqlConsumedChallengeProof`, `V5MySqlChallengeHttpPort`, `V5SecurityEventVersions`, `V5BitrixPdoCompatibilityBridge` иexistingHTTPclasses. Пятьroutes и принятуюархитектуру не перепроектировать. Privatepilotkey только operational admission, не accountauth. `V5BitrixLockedPasswordTotpProof` готов какBEGINreadadapter, не verifier кода.

**Незавершённое:** фактическаяsite-factoryсnativeproviders и согласованнымпилотнымсписком; nativeTOTP-code/replay,10-path epochhooks,successfulphone/reconfirmation,strongtrust,SMS begin/resendlifecycle,paidSMS/providerreceipt/tariff,RestISPIN,additiveV5DDL,production-appropriate transactionpermit иliveauthenticationacceptance. Новаяcomposition устанавливаетaccountscope, но реальныеIDневыбирались и сервернаяfactoryнеустановлена.

Pending `V5BitrixLockedTotpFactor` и ранееотклонённый временныйinstrumentedPHPshim остаютсянеприменёнными; заблокированныеpayload не повторялись. Новыйcompositiontest — другая задача, безреализацииалгоритмаTOTP/подменыserverentry.

## 5. Следующий реальный шаг и допуск

Web-layout/установка/OFFHTTPS/configsnapshot закрыты. Не выдавать ещё один общийserveraudit. Дляsitecomposition нужны фактическаясовместимость существующейБД иnative providers. ПередподготовкойadditiveDDL следующийсерверныйдопуск — **только чтение метаданных структуры необходимых таблиц/индексов/движков**, безклиентскихстрок,DDL,измененийданных,платныхSMSиливключенияV5. Этотдоступещёне выполнялся в текущей итерации и долженбытьотдельноподтверждёнпользователем; не считатьread-onlyconfigPASS разрешениемproductionSQL.

Далееbackup→проверкаbackup→отдельносогласованнаяadditiveмиграция→nativecomposition/ограниченныеаккаунты→приёмка→pilot. Параллельноsafe featureработу выполнять самостоятельно. ProductionDDL/accountmutations/платныеSMS иустановкуновогоrelease бездопускане выполнять. CI/faultschemas не переноситьнарабочуюБД,guards не снимать.

## 6. История, окружение, ограничения

Siteaccount1005:1005,CLI8.3.21,privateвpoolopen_basedir уже разрешён. Root `.htaccess` SHA256 `81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95`; V4rewrite `61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3`; V4router `3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50`; api/.htaccessотсутствует. PrivateV5root:1005 с0750/0640,public0755/0644. Parentdirs принадлежатsiteaccount; не считатьroot-ownedleaf полнойизоляцией.

ЗакрытыеCUser/PhoneAuth/OTP-recoveryисследования не повторять. Архив: `EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-14.md`, checkpoint13октября, `STRUCTURE_REGISTRY.md`, phone-native-writer/MFA-recovery/native-mutation документы. CUserdelete/add не доказаныкакsuccessfulCONFIRMEDwriter; user.phpSHA-driftнеобъяснён, новыйbaselineнеутверждён,coreавтоматическинеисправлять.

Историческиеcodecheckpoints:0caa0248(59pilot/198entry/329DB),c3f92ba(329DB),8c395f0(268),dc2caed(205),4a6ea09(160). Installer c37ab9959bb13dae49f9c1a2c4ddda0b06905c69 сSHA2563fca39d399b8d60d865fc6a886e589ccd8c3052ae702c66227650098e9577e5a не менялся. Mainlive `7c2e9f8e4501b92469bcdeea18f8340676ad52c9` неизменён; pushmainпубликуетlatest,docsтуданесинхронизировать. Более позднийdocHEAD не новоеcode-testing.

CURRENT: FILES_INSTALLED=YES; MODE=off; LOCAL_ORIGIN_OFF_BOUNDARY=PASS; WEB_CONFIG_SNAPSHOT=PASS; MYSQL_PILOT_COMPOSITION_CI=PASS; REAL_EPHEMERAL_MARIADB_PASS=YES(406); PHP_RUNTIME_MAPPING_ATTESTED=NO; PRODUCTION_AUTH_ENABLED=NO; V5_DATABASE_MIGRATED=NO. Серверныйшаг — одинguardedBashblock,syntaxcheck,FINAL_STATUS/FINAL_RC,безсекретов;SSHне закрывать. Приоритетproductionfact>livefeature>tests>docs>oldchat.
