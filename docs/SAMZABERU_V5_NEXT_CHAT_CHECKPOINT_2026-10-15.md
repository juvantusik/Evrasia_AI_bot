# СамЗаберу V5 — checkpoint после web-config PASS и HTTP composition

15 октября 2026. Layout, OFF установка, два origin HTTPS пути и web-config snapshot уже получены. Не повторять. V5/V4 параллельно на существующем evrasia; отдельнаяVM отменена.

## Новый серверный факт

Последний загруженный операторский лог, раздел396–448: четыреvhost/шестьвыведенныхконфигов, stable hashes, FINAL_STATUS=PASS/FINAL_RC0; PHP/SQL/HTTP/reload/sitewriteнеисполнялись. nginxHTTP→http192.168.103.141:8080; nginxSSL→**https192.168.103.141:8443**; обаApachevhost→sitePHP8.3socket. Вphp.ini auto_prepend/appenddisabled,session.auto_start0,opcache.revalidate_freq0. EffectivePHP/HTTPS/REMOTE_ADDR не измерялись, wildcardincludesнераскрывались. Наличиеgenericnginxfastcgi_params не доказываетегоиспользованиевproxy→Apacheцепочке.

| Конфигурация | SHA256 из вывода |
|---|---|
| site nginx.conf | be14e450606a97dd8070e2c355ff03d2acdaf85009b651468e429e4a00b4a00c |
| site nginx.ssl.conf | fd2cebd5fe8c78cdcf97ff73d90728111a118df08a830018adf48e1518e99b89 |
| site apache2.conf | e624fd83566e8c674f9e877607c5a4a538eec5819009dc469e064f53e09abcad |
| site apache2.ssl.conf | 0ba92d681ff767a87f0c1f8c321adde71f2ff5030bb44717ba74cf6cbfbfad7f |
| /etc/nginx/fastcgi_params | f37852d0113de30fa6bfc3d9b180ef99383c06739530dd482a8538503afd5a58 |
| /etc/php/8.3/fpm/php.ini | 8205ea66cbcb41d5d2a57328302afb31cea97d957210f7aa2c6eef3972c30e7e |

Site конфиги расположены в `/home/site_evrasia/conf/web/evrasia.spb.ru/`. ЭтиSHA — configsnapshot, не новыеизменения. По полученномувыводуне требуетсяправитьnginx/FPM или отключатьstrictHTTPS.

## Последний code/test, обе CI PASS

**`6f8a35211671aaa25f8129eeac90b802944646cd`**.

- Offline38771014889/job116546512805 completed/success, существующий26-suiteworkflow.
- RealephemeralMariaDB38771014920/job116546512653 completed/success; полныйлогпрочитан:43+117+45+63+61+77=**406checks**, combined363. Package regressionотдельноPASS. Первыйпрогонновойcompositionуспешен.
- ЛокальноPHP lint двухновыхфайловPASS, Gitblobсовпадает:composition68247279aa6a5f89b8e7508111f47951b90765ee,testab93b4e3d586b19f5d45df64a26b3db6763f3055. Полныйsuiteлокально не выполнялсяиз-заGitHubDNS; исполнялсявCI.
- Compare7679624..6f8a352:composition185строк,test184строки,existingworkflow+16/-5. API/native/crypto/DDL/V4/installerне менялись.

`src/V5MySqlPilotComposition.php` собираетexistingtransport/workflow/challengeconsumption/TOTPissuer/durablegrantproof/strongsessions/refreshactions/PIN. Mandatorynativeports,однаPDOconnection,общийbindingkey. `V5PilotScopedNativePorts`ограничиваетaccountcohort1–32serverintegerID; ниHTTPJSON,ниclientuser_id не задаютсписок. Allowlistне заменяетnativeproof/coverage. При новомrequest послеисключенияаккаунта старыеJWT/refreshотклоняютсяlockedgates, ноDBrevocationнепишется; ранее начатыйrequestнепрерывается.

77новыхtests: composedtrustedlogin→committedsession→валидныйJWT→lockedPIN→refreshrotation, independentDBobserver; foreignaccount/JSONuser_iddenial; изменившийсяcohortне пропускаетстарыетокены; unknown/falsecoverageсохраняютотказ; TOTPpriorityбезfallback; отсутствиеSMSlifecycle/fakechallengeне создаётcredentials/SMS; wrongPDO/unownedtx/invalidcohortотказы. Positivepassword/trust/coverage/PIN иBitrixfacadeСИНТЕТИЧЕСКИЕ. TOTPverifierне реализован, егоcallbacksотказывают. RuntimeBitrix/SMS/RestISне приняты.

Existinginstaller pins нерасширены: CIpackage step читает отдельныйcheckout установленного0caa0248; новыеDBtestsexecutecurrentfeatureHEAD. Нельзячитатьэто какобновлениеproductionпакета.

## Сервер остаётся на прежнем runtime

Installed0caa0248c783188939398a71543ba740f064bb90; public `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5`; private `/home/site_evrasia/web/evrasia.spb.ru/private/samzaberu-v5/releases/0caa0248c783188939398a71543ba740f064bb90`; backup `/root/ev-v5-off-backup.f89wzMkA`; rollbackтамжеrollback.shнеисполнялся; manifestвprivate/samzaberu-v5/installed.sha256. Modeoff,site-factory/pilotkey/V5DDLнет. Новыйfeaturecodeнеустанавливался.

## NEXT и допуск

Не выдаватьповторныеweb/HTTPS/layoutchecks. Следующийсерверныйдопуск — read-onlymetadataрабочейБД (структуранеобходимыхтаблиц,индексы,движки), **без клиентскихзаписей/DDL/измененийданных** передподготовкойadditiveмиграции. ЭтотproductionDBдоступещёне подтверждён/не выполненвтекущейитерации. Не считатьconfigPASS разрешениемподключитьсякБД. Послеодобрения — одинguardedблок сзафиксированнойцельюиexistingcredentialsбезвыводаихзначений. Не применятьPROPOSED/CIсозданиятаблицавтоматически.

Nativefactory(среальнымиproviders),TOTP/replay/10-pathepochhooks,phonesuccess/reconfirm,strongtrust,SMSlifecycle/provider/receipt/tariff,RestIS,permit/additiveDDL всё ещёнужны. Новыйcohortкомпонентготов, actualpilotIDневыбраны. PendingTOTPdraft/temporaryshimнеприменялись; ранееотклонённыеpayloadнеповторялись.

CURRENT: INSTALLED_DISABLED / ORIGIN_HTTPS_PASS / WEB_CONFIG_SNAPSHOT_PASS / COMPOSITION_CI_PASS406 / PHP_RUNTIME_NOT_ATTESTED / NO_NATIVE_AUTH / NO_V5_DB_MIGRATION. Mainlive7c2e9f8e4501b92469bcdeea18f8340676ad52c9неизменён. Послеэтогоdoc-onlyHEADне новыйкод/CI/deploy. ДеталиR15–R17 и usage — integrationdecisions и PILOT_COMPOSITIONдокумент.
