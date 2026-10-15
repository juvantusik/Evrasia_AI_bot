# V5 — реестр интеграционных решений, 15 октября 2026

Дополнение к STRUCTURE_REGISTRY. Полная предыдущая редакцияR1–R14 сохранена вcommit76796242020915629decd867939bc9fc19009550. НовыеR15–R17 ниже; бизнес-логика не переутверждается.

## R15. Получен web-config snapshot — новых server changes не требуется по этому выводу

**Было:** originOFFHTTPS принят, ожидалось чтение конкретныхTLS/FPMправил.

**Стало:** операторскийвыводFINAL_STATUS=PASS; nginxSSLproxyиспользуетhttps192.168.103.141:8443, HTTPproxyhttp8080; обаApachevhostуказываютнаsitePHP8.3socket. Впрочитанномphp.ini autoprepend/appenddisabled,session.autostart0,opcache.revalidatefreq0. Hashesstable,4vhost/6reportedfiles, app/PHP/SQL/HTTP/reloadне выполнялись. SHAзаписанывcheckpoint.

**Почему:** фактическийconfigзаменяетпредположение, чтоSSLпроксируетсяна8080. Не повторятьconfigaudit, не вводитьtrustedForwardedHeadersилименятьnginxбезнеобходимости. Genericfastcgi_paramsне доказанвиспользованиидляApacheцепочки. Wildcardincludesнераскрывались; effectivePHPmetadataпо-прежнемунеаттестованы.

## R16. Existing V5 HTTP composition и account-scoped pilot — реализовано и CI-tested

**Было:** готовыеHTTP/workflow/challenge/sessionкомпоненты подключалисьвтестахвручную; privatepilotkeyограничивалзапрос, но неaccount. Готовойобщейсборкиcohortgatesдлявхода,challenge,refreshиPINнебыло.

**Стало:** `V5MySqlPilotComposition::build`конструируетexistingtransportиодиннаборstore/actions, используяоднуPDOconnection/общийbindingkey и обязательныеnativeports. `V5PilotScopedNativePorts`требует1–32явныхуникальныхположительныхintegerIDотоператора. CohortANDnativeproof,неOR. Scope проверяетсяпослеfirst-factoridentityи доtrust/factor/coverage/PINproviders; refresh/PINучитываютновыйсписокдажедлястарыхcredentials. Scope негенерируетJWT, неактивируетroute и не вызываетSQLприbuild. Durableconsumedproof всегдаexistingMySqlimplementation; arbitrarytrusted-devicecallbackнепринимаетconsumedgrant.

**Почему:** разрешениепилотногозапросане должно открыватьдоступвсемаккаунтам. Reuseоднихstoresне позволяет случайноразойтисьsession/refresh/PINпроверкам. TOTPbeginпереиспользуетexistingboundissuer; SMSbegin/resendостаётсянедоступным, нефиктивнымуспехом.

**Evidence:** code/test6f8a35211671aaa25f8129eeac90b802944646cd. Offline38771014889/job116546512805SUCCESS; ephemeral38771014920/job116546512653SUCCESS; прочитанDBлог43+117+45+63+61+77=406. Новые77checksнастоящегоSQL/JWT/refreshиindependentobserver,отказыcohort/nativecoverage/foreignscope. Positivepolicy/trust/PIN/BitrixfacadeСИНТЕТИЧЕСКИЕ. TOTPcallbacksновоготестаотказывают; draftverifierне реализовани не включён. Локальныйlint/blobmatchPASS; полныйsuiteвCI.

**Границы:** actualsite-factory/nativeproviders/approvedIDsне установлены. First-factorproviderможетразрешатьidentityи выполнятьсвоиattempt/rateправиладоcohortfilter; не обещатьнулевыхread/writesэтогопровайдера. НовыйscopeтолькосужаетV5доступ. Исключениене пишетpermanentrevocationвБД,непрерываетin-flightrequests; снова включённыйaccountподчиняетсяexistingepoch/revocation/TTL. Непровереннаяnativecoverageвсёещёотказ. Черезexistingmappingrefreshcoveragefailureвозвращает503,PINdenial401; контрактstoreнепереписан.

## R17. Immutable package regression отделён от evolving feature

**Было:** installer self-test читалHEAD, но требуетexactsrc/APIpinsустановленного0caa0248. Любоеаддитивноеsrcизменениеfeatureпопадалобыподэтотзапрет, хотяproductionpackageнеобновляется.

**Стало:** existingephemeralworkflowделаетвторойcheckoutточного0caa0248 вv5-installed-payload,толькопакетныйself-testполучаетэтотworkspace. ActualDBtestsпродолжаютисполнятьcurrentfeatureHEAD; старыеsuitesвключаютсяодинразновымcompositiontest. Installer/pinsне менялись,не ослаблялись. Обеcheckoutбезstoredcredentials. Package stepPASS втомжепрогоне38771014920.

**Почему:** нельзярасширятьproductioninstallerbaselineрадитестированияновогокода. Проверятьновуюcompositionи ужеустановленныйOFFпакетнужнораздельно. ЭтоизменениеCI,неdeploymentинструкцияинеапгрейдсервера.

## Сохранённые решения R1–R14

| ID | Смысл и состояние |
|---|---|
| R1/R2 | LateUNKNOWN→SENTтолькоindependentreceipt,безsend/quota/TTL;canonicalsortfixtureнеослабляетruntimeguard;первыйFAILсохранён. |
| R3/R7 | ОбязательнаяотдельнаяVMотмененапользователем;V5рядомсV4наevrasia,общиеаккаунты/БД. Security/productionдопускисохранены. |
| R4 | Mainpushпубликуетlatest;safeработа/docsтолькоfeature. |
| R5 | SMSbridgeexactqueriesиownedCAS/COMMIT;успехтолькопослеcommit. |
| R6 | FreshlockedpasswordBEGINadapterпереиспользуетобщийepochHMAC;этонеTOTPcodeverifier. |
| R8/R9 | Parallelentry/lazytransport/privateoff-pilotconfig;operationalkeyнеaccountauth. |
| R10/R11 | Layout/installerCIзатемproductionOFFinstallPASS;backupf89wzMkAизвестен,непереустанавливать. |
| R12/R13 | PublicDNSself-callRC7→известныйorigin--resolve→обаHTTPSOFFпутиPASS;внешнийedgeиnativeauthнепроверены. |
| R14 | ВременныйinstrumentedPHPshimнеопубликованпослеtoolblock;вместонегочтениеconfigбезподмены/PHP. ТеперьconfigPASSпоR15;runtimeаттестациюнеподменятьснимком. |

## Структуры / неизменность

- Новые `src/V5MySqlPilotComposition.php`185строк и `tests/integration/V5EphemeralMySqlPilotCompositionTest.php`184строки; existingworkflow+16/-5. Остальныеruntime/crypto/native/API/V4/DDLфайлынеменялись.
- Самinstalledruntime0caa0248/serverconfig/shim/backupне обновлялись. НовыеGitHubтестынеproductionDBдоступ.
- START HERE/checkpointфиксируютconfigPASS/compositionPASS406,сохраняютграницыnativeнепринятого.
- Следующийсерверныйдопуск: read-onlyметаданныеexistingDBпередadditiveDDL,безклиентскихстрок; выполнениепослеотдельногоодобрения. ПроизвольныхSQL/mutations/SMSне разрешено.

Детальныйcompositionконтракт: `SAMZABERU_V5_PILOT_COMPOSITION_2026-10-15.md`. PendingTOTPdraftиblockedtemporaryshimне использовались. ЗакрытыеCUser/PhoneAuthsource-onlyаудитынеповторять.
