# V5 — реестр интеграционных решений, 15 октября 2026

Дополнение к STRUCTURE_REGISTRY. Полная прежняя редакция R1–R12 сохранена вcommit `0a5a60e0bc52f96644e8c858b5760e015a6ef20e`; новый факт R13. Принятые бизнес-правила не меняются.

## R13. OFF origin HTTPS принят по реальному результату

**Было:** runtime0caa0248 установленOFF, CLI503PASS, public-DNSself-call получилCURL_RC7; correctedoriginhelper ещёожидалоператора.

**Стало:** оператор прислалфайл с успешнымиGET `/api/v5/router.php` и `/api/v5/auth/signin` через192.168.103.141 сSNIevrasia.rest. НаобоихCURL_RC0/HTTP503/TLS_VERIFY_RESULT0/exactOFFJSON/no-store/no-cookie/no-redirect/no-CORS/noAge. ПослеHTTP manifest/OFF/V4/sharedrewrite неизменны. FINAL_STATUS=PASS/FINAL_RC0/HTTP_OFF_PATHS_PASS2/LOCAL_ORIGIN_OFF_BOUNDARY=PASS.

**Почему:** фактическийсерверныйвывод заменяетPENDING. Файловуюустановку, layout иoffHTTP не повторять. Исправлениеself-callприменено; точнуюпричинупервогоRC7 этимнеобъявляемдоказанной.

**Граница:** неexternalDNS/NAT/CDN, неPHPHTTPS/peer metadata, неV4functionalE2E и неnativeauthentication. Backendнеподключён, modeoff. Runtime/backup/installerнеизменны; новыеfeature/docs неавтоматическийdeploy.

## R14. Следующая проверка — чтение конфигурации, без временной подмены entry

**Было:** хотелось получитьфактическийPHPконтекст через временныйinstrumentedV5shim. Публикацияэтогоскрипта былаотклоненаинструментомбезопасности; завершённымкодом/установкой это неявляется.

**Стало:** блокированныйpayload не повторяется черездругойendpoint/encoding/delivery. Вветку включён другой, read-only `deployment/read-v5-web-config.sh`, не выполняющийподменуилиPHP. Ончитает ограниченныекандидаты nginx/Apachevhost/PHP8.3/.user.ini, печатаетразрешённыеTLS/FPM/forwardedнастройки; неизвестныезначенияскрывает. OFFmanifest/root/V4проверяетдо/после; HTTP/SQL/PHP/reload/write не делает. Includeпроизвольнонеобходит.

**Почему:** выяснитьпередачуTLS/peer длясуществующегоV5transport беззатрагиванияустановленноговхода. Статическийснимокнеподменяетruntimeприёмку и неразрешаетдоверятьподдельнымproxyheaders.

**Evidence:** bashsyntax/Pythoncompile/wronghost0HTTP и10localparserfixtures PASS; credentials-canaryнепечатается, out-of-scopesymlink иотсутствиеvhostотклоняются. SHA256`26cc5a2b3b04143764c58032d5a5759f88a54fbd16ca738bcffaa4dedbbbfd81`, blob`f658d5b3bae9c47320e530ac5813b67ed87e8d51`. РезультатсевrasiaещёPENDING; noextraMariaDBcoverage. Предыдущийfeature0a5a60e offline38764635635SUCCESS прочитанlive.

## Сохранённые решения

| ID | Было → стало → почему |
|---|---|
| R1 | НетlateUNKNOWN→SENT → independentreceipt reconciliation безsend/quota/TTL → неопределённостьнеисправлятьвторойSMS. |
| R2 | Несортированнаяquota fixture → canonicalsortтолькоfixture → runtimeguardнеослаблять; FAILисториясохранена. |
| R3/R7 | ОтдельнаяVMкакblocker → V4/V5насуществующемevrasia,общиеаккаунты/БД → прямое решениепользователя; securitypermissionsсохраняются. |
| R4 | Mainpushпубликуетlatest → feature-onlyкод/docs → безпобочнойпубликации. |
| R5 | SMSqueries/autocommitACKнесовместимы → exactSQL/ownedCAS/COMMIT → committedack. |
| R6 | НетlockedpasswordBEGINadapter → currentlockedrows/общийepochHMAC → staleпарольнедостаточен. |
| R8/R9 | Постоянная503заглушка → parallelentry/lazytransport/privateoff-pilotconfig → operationalkeyнеaccountauth. |
| R10/R11 | Layout/installerожидались → actuallayoutиOFFinstallPASS → новыеV5файлыесть, nativeauthещёнет. Backupf89wzMkAсохранён. |
| R12 | НеверныйpublicDNSself-call → documentedorigin--resolve → localoriginбезDNS/nginxправок; теперьPASSпоR13. |

## Структуры / неизменность

Новый только `deployment/read-v5-web-config.sh` плюсSTART HERE/checkpoint/этотреестр. Runtime`src`/`api`, installedconfig/shim/release, nativeproviders, SQLиworkflows не менялись. Ранееотклонённыйtemporaryshimнеприменялся. Root/V4насервере сохранены пооператорскомулогу. Старыйcheck-disabled-v5-http.shбольше ненужно запускатьрадитогожеPASS.

Nativefactory/test-accountallowlist,TOTP/replay/epochhooks,phoneverification,strongtrust,SMSlifecycle/provider/RestISиDDL остаютсяследующимиинтеграционнымиблоками. Не возвращатьVM и не повторятьзакрытыеCUser/PhoneAuthsource-onlyаудиты.
