# СамЗаберу V5 — штатный Bitrix / production preflight (2026-10-25)

## Контекст и проверенный результат

На сервере `evrasia`, в настоящем `eurasia_new`, CLI под `site_evrasia` через штатный `prolog_before.php` / `Bitrix\Main\Application::getConnection()` проведены **READ ONLY** проверки без изменения API V4, публичного V5 или кандидата.

Подтверждено:

- `APPLICATION_GET_CONNECTION=PASS`, `NATIVE_CONNECTION_IDENTITY=PASS`.
- `NATIVE_START_TRANSACTION=PASS`, `NATIVE_ROLLBACK=PASS` в SQL-сессии `READ ONLY`.
- `V5_PERMIT_DENIAL=PASS`, `FOREIGN_TRANSACTION_DENIED=PASS`, `V5_BRIDGE_BEGIN=PASS`, `V5_BRIDGE_REAL_SQL=PASS`, `V5_BRIDGE_ROLLBACK=PASS`: временный **CLI-only** permit, НЕ production.
- Native `transactionLevel`: **0 → 1 → 0** при `startTransaction`/rollback.
- Особый сценарий: прямой `START TRANSACTION READ ONLY` через `queryExecute` даёт native depth **0**, но SQL `@@session.in_transaction=1`. V5 отказал в захвате чужой транзакции.
- `V5ProductionNativeTransactionPreflight` из отдельной ветки проверен на реальном Bitrix; финальный повтор `PREFLIGHT_TEST=PASS`, `PRODUCTION_PREFLIGHT_READONLY=PASS`, `WRONG_CONNECTION_DENIED=PASS`, `NATIVE_FOREIGN_DENIED=PASS`, `RAW_FOREIGN_DENIED=PASS`, `WRONG_SITE_ROOT_DENIED=PASS`, `TRANSACTION_LEFT_OPEN=NO`.
- Pinned Bitrix D7 DB core:
  - `db/connection.php`: `efed4b13015a6f57063d30666baa79456f49ead188e3daf0395bd2350074a21c`
  - `db/mysqlcommonconnection.php`: `2a43ad5a5ae90982b52dbea5980bfae469e23aa024c371687696f25aed280893`
  - `db/mysqliconnection.php`: `82877ae53f27a6bc429a8b9cda9709cc86f42cfc14225c4c1ba56a43c3121ec1`

Кандидат на сайте: `6fa8773746e9a40e22008d67636c896eefeec3ed`. Роутер `api/v5/router.php` остаётся с SHA `f502ae4718a3193d2765d30b75dff638a684cb75078534fecfd3524dce3b94d8`, V5 AUTH OFF. V4 не изменён.

## Добавлено в отдельной GitHub-ветке

Ветка: `feature/v5-production-native-preflight-20261025`.

1. `src/V5ProductionNativeTransactionPreflight.php`: fail-closed сверка установленного Bitrix, SHA ядерных классов, физического master `mysqli` соединения, SQL `@@in_transaction=0` и native `transactionLevel=0`. НЕ реализует `V5NativeBitrixExclusiveTransactionPermitPort`.
2. `tests/diagnostics/v5_production_native_preflight_readonly.php`: реальный CLI read-only acceptance; успешно проверен на production.
3. `src/V5ProductionNativeRequestBoundaryGuard.php`: строгое выделение V5-only HTTPS POST HTTP ingress через `api/v5/router.php`, плюс native preflight. НЕ является разрешением начать транзакцию.
4. `tests/V5ProductionNativeRequestBoundaryGuardTest.php`: deny-only проверка CLI с поддельными CGI полями. Ни Bitrix bootstrap, ни БД для теста не нужны.

## Точное состояние безопасности

**НЕ доказано:** эксклюзивное владение V5 всей транзакционной цепочкой, непрерывность Bitrix native MFA/password/phone/security callbacks, атомарность `AUTH_EPOCH` для всех требуемых путей, production-provider attestation, V5 HTTP integration. Проверка свободного соединения не является таким доказательством.

**НЕ делать:** возвращать `true` из production permit на основании `isClean` или `isEligible`; подключать новые классы к публичному роутеру; снимать V5 OFF; выполнять live SMS/OTP или DDL без отдельного разрешения. Новые файлы пока существуют только в GitHub-ветке.

## Следующая работа

1. Прочитать фактический `api/v5/router.php` и V5 request boundary, проверить реальное HTTP script/rewrite mapping (без отключения OFF).
2. Закрепить настоящий dedicated-V5 request-scope ownership/lifecycle и audit native callback/transaction continuity; только затем выпускать `V5NativeBitrixExclusiveTransactionPermitPort`.
3. Протестировать fail-closed и чужие транзакции в изолированном Bitrix staging до any production auth enablement.

Последний серверный вывод: `PREFLIGHT_TEST=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`.

## Дополнение: контроль непрерывности после BEGIN (2026-10-25)

**Добавлено только в GitHub-ветке, production не изменён:**

- `src/V5ProductionNativeTransactionContinuity.php`: не выдаёт `V5NativeBitrixExclusiveTransactionPermitPort`; может быть вооружён **только после** уже разрешённого `V5BitrixPdoCompatibilityBridge::beginTransaction()`, при native depth=1, физическом MariaDB `@@in_transaction=1` и неизменном connection ID. Создаёт уникальный SAVEPOINT на том же master mysqli. Синхронные `finishCommit()` и `finishRollback()` проверяют continuity, освобождают SAVEPOINT и немедленно вызывают соответствующее завершение существующего моста. При потере savepoint, соединения или глубины — fail-closed / `abandonUncertainNativeTransaction`, без отката потенциально чужой транзакции.
- `tests/diagnostics/v5_native_continuity_readonly.php`: подготовленный реальный CLI-only read-only acceptance на установленном Bitrix. Проверяет штатный rollback, read-only COMMIT, и скрытый прямой `COMMIT; START TRANSACTION READ ONLY`, при котором SQL `@@in_transaction=1` и Bitrix depth=1, но исходный savepoint исчез. Требуется отдельный серверный результат; на момент коммита тест ещё **НЕ ЗАПУЩЕН**.

**Важно:** контроль непрерывности не является доказательством эксклюзивного владения запросом, не меняет `V5BitrixPdoCompatibilityBridge::commit` для остальных вызывающих его компонентов, не аттестует native security callback coverage и не включает V5 AUTH. HTTP router и V4 остаются прежними. Публичный V5 router содержит отдельные health/legal endpoints, но auth factory не подключена.

**Следующее:** выполнить закреплённый CLI read-only acceptance, затем исследовать действительные native account-security mutation paths и связать continuity и lifecycle напрямую с V5-only auth handler так, чтобы неконтролируемый вызов `commit` был невозможен.

## Дополнение: привязка к защищённым V5 маршрутам (2026-10-25)

Изучен фактический `site-integration/samzaberu-v5/api/v5/router.php`: есть отдельные **анонимные**, не зависящие от AUTH READY маршруты `GET /health`, `GET /legal/consents`, `GET /legal/registration-documents`, `POST /legal/consents/validate`. Они не должны требовать exclusive transaction permit и не должны активироваться/ломаться от V5 AUTH scope. `GET /cart` защищён JWT, но обслуживается отдельным GET путём и **не включён** в этот POST guard.

В `V5ProductionNativeRequestBoundaryGuard` усилено точное сопоставление с неизменённым `V5RouteMap::ROUTES`, разрешены **только восемь** защищённых POST actions: `signin`, `verifyChallenge`, `resendChallenge`, `requestSmsFallback`, `refresh`, `logout`, `validateToken`, `showBonusPin`. `isProtectedPostRoute` — чистый метод без Bitrix/MySQL, отказывает неизвестным/публичным методам, GET, query string, altered-case, encoded и trailing-slash путям. В `V5ProductionNativeRequestBoundaryGuardTest.php` добавлены положительная матрица 8 маршрутов и отрицательная матрица 11 маршрутов. Изменения существуют **только в GitHub-ветке** и ещё **не запускались** на production (потребуется отдельный CLI offline test). Отсутствие HTTP/factory и production exclusive transaction permit остаётся неизменным; не использовать один только ingress/preflight для V5 BEGIN.

## Дополнение: строгий режим уже существующего PDO-моста

В `V5BitrixPdoCompatibilityBridge` добавлен **не включённый по умолчанию** третий аргумент конструктора `requireNativeContinuity`. Он предназначен только для будущего явно аттестованного V5-only factory:

- После разрешённого `BEGIN` сразу вооружается существующий `V5ProductionNativeTransactionContinuity` со случайным savepoint.
- В строгом режиме даже обычные `$db->commit()` и `$db->rollBack()` проходят обязательный одноразовый continuity guard внутри моста; прямой вызов хранилищем не обходит проверку.
- При исчезнувшем savepoint (скрытый `COMMIT + BEGIN`) мост не завершает потенциально чужую транзакцию, а помечает своё владение недостоверным.
- Ранее существовавшие конструкторы с двумя аргументами сохранены для текущих offline/staging тестов и не превращаются в production permit.
- В `tests/diagnostics/v5_native_continuity_readonly.php` добавлены прямые strict `commit/rollBack` и отказ при скрытом перезапуске через прямой `commit`.

**Состояние:** код находится ТОЛЬКО в GitHub feature-ветке, PHP lint и реальный native SQL smoke для новой версии ожидают запуска. Ранее успешно проверенный компонент `V5ProductionNativeTransactionContinuity` не переписан. Production AUTH V5 OFF; production exclusive permit и родной security-writer coverage НЕ АТТЕСТОВАНЫ. Строгий режим сам по себе не выдаёт permission на `BEGIN`; ему по-прежнему требуется отдельно подтверждённый `V5NativeBitrixExclusiveTransactionPermitPort`.

## Дополнение: строгая проверка до КАЖДОГО SQL (2026-10-25)

После успешного `STRICT_LIFECYCLE_TEST=PASS` на реальном Bitrix подтверждены `STRICT_DIRECT_COMMIT_GUARDED=PASS`, `STRICT_DIRECT_ROLLBACK_GUARDED=PASS`, `STRICT_HIDDEN_RESTART_DETECTED=PASS`, `FOREIGN_RESTART_PRESERVED=YES`, `FINAL_RC=0`. Это работа со штатной SQL-сессией `READ ONLY`; production-код, V4 и публичный V5 не менялись.

**Обнаружена дополнительная граница:** проверка только перед терминальным `COMMIT` не предотвращает возможный `INSERT/UPDATE` в подменённую native транзакцию, если native callback сначала сделал `COMMIT + BEGIN`. Поэтому на отдельной GitHub-ветке:

- `V5ProductionNativeTransactionContinuity::confirmBeforeStatement()` проверяет ту же физическую MariaDB-сессию, native depth и исходный savepoint и переустанавливает savepoint перед следующей операцией.
- `V5BitrixPdoCompatibilityBridge::runStatement()` при opt-in `requireNativeContinuity=true` проводит контроль до и после каждого разрешённого SQL. При разрыве помечает транзакцию недостоверной, отказывается выполнять последующие SQL или завершить потенциально чужой scope. SQL allowlist и старый двухаргументный конструктор не меняются.
- `tests/diagnostics/v5_native_continuity_readonly.php` проверяет обычный `SELECT UNIX_TIMESTAMP()` и отказ SQL после прямого скрытого `COMMIT + START TRANSACTION READ ONLY`. Данные пользователей не читаются и не меняются.
- Создан единый серверный запуск `tests/diagnostics/run_v5_strict_sql_continuity_readonly.sh`, проверяющий production host, неизменность V4/V5, кандидата, pinned Git blobs, PHP lint и post-check. Пин исходников `75a00c6cc4fe374ba3e872ae93ed5337aa9f1214`.

**СТАТУС НОВОЙ ВЕРСИИ:** код доступен в GitHub; реальный Bitrix smoke с проверкой **перед SQL** ещё ожидает серверный stdout. Не выдавать за PASS до выполнения. Строгий режим не устанавливается в production и не открывает AUTH V5.

**Открытые release blockers:** отсутствует независимо подтверждённый production exclusive permit/request lifecycle, нативные OTP/password/phone/trust/security writer coverage, интеграция с real provider и аттестованный isolated staging. Успешный preflight/continuity не снимает эти ограничения.

## Октябрь 25: фактический PASS строгой SQL continuity и HTTP-only guard

Принят операторский **реальный Bitrix CLI** read-only stdout:

```
STRICT_SQL_CONTINUITY_TEST=PASS
STRICT_NORMAL_SQL_CONTINUITY=PASS
STRICT_FOREIGN_SQL_BLOCKED_BEFORE_EXECUTION=PASS
STRICT_DIRECT_COMMIT_GUARDED=PASS
STRICT_DIRECT_ROLLBACK_GUARDED=PASS
STRICT_HIDDEN_RESTART_DETECTED=PASS
FOREIGN_RESTART_PRESERVED=YES
TRANSACTION_LEFT_OPEN=NO
FINAL_STATUS=PASS
FINAL_RC=0
WRAPPER_RC=0
```

Production Bitrix, DB, API V4 и public V5 не изменены.

**Новое изменение GitHub-only** в `V5BitrixPdoCompatibilityBridge::__construct`: при любом не-CLI PHP запуске bridge теперь требует `PHP_SAPI=fpm-fcgi`, `requireNativeContinuity=true` и `V5ProductionNativeRequestBoundaryGuard::isEligible(native)=true`, который сверяет `V5RouteMap`, HTTPS, public host, физическое соединение, чистую native/SQL transaction state и pinned Bitrix sources. Двухаргументный нестрогий bridge оставлен только для CLI тестов. Это **предварительная HTTP граница**, НЕ производство или установка `V5NativeBitrixExclusiveTransactionPermitPort`. До выделенного проверенного provider/permit `beginTransaction` не получает готовность, native security hooks остаются неаттестованными.

**Статус HTTP изменения:** сохранено только в feature GitHub; реальный FPM HTTP ingress не тестировался, PHP lint новой версии на сервере пока не проходил. Не устанавливать, не включать V5 AUTH без отдельной независимой release-проверки.

## 2026-10-25 — protected GET cart ingress and public address

**User-confirmed canonical PUBLIC entry:** `https://evrasia.rest/api/v5/` with public IPv4 `217.197.238.66`. The old `192.168.103.141` is the Bitrix LAN interface used exclusively for local-origin diagnostics, **NOT** the address given to mobile users. A server-origin `--resolve evrasia.rest:443:192.168.103.141` health 200 proved internal vhost/TLS only, not external mobile-network reachability. Real external route must be checked from outside the local network; never interpret NAT hairpin errors as app downtime.

**Verified latest operator stdout:** `STRICT_SQL_CONTINUITY_TEST=PASS`, `STRICT_FOREIGN_SQL_BLOCKED_BEFORE_EXECUTION=PASS`, `STRICT_HIDDEN_RESTART_DETECTED=PASS`, `HTTP_GUARD_TEST=PASS`, `V5_CLASSES_LOAD=PASS`, protected POST matrix PASS; `V5_AUTH_ENABLED=NO`, production V4/public V5 untouched. No replay of these accepted SQL/source tests.

**Feature-only correction:** `V5ProductionNativeRequestBoundaryGuard` now also accepts exactly `GET /api/v5/cart` under the identical pinned production-router, `evrasia.rest` host, real FPM/HTTPS and clean-connection restrictions as protected POST routes. The existing V5 route map defines `GET /cart => readCart` and `V5ParallelHttpEntry` requires the `Authorization: Bearer` envelope before bootstrapping the handler; the locked V5 session reader validates the token. Public `GET /health`, public legal GET and public legal-validate POST remain **outside** this transaction guard. Non-canonical query strings, slash variants, POST cart and unknown paths deny. No routes or auth are activated; there is no production exclusive transaction permit yet. Test matrix extended offline; **runtime FPM/real JWT still NOT tested**.

## 2026-10-25 — подтверждён GET cart и закрыт HTTP-обход обычным PDO

Принят операторский offline stdout: `CART_ROUTE_TEST=PASS`, `EXACT_PROTECTED_POST_ROUTES=PASS`, `PUBLIC_AND_UNKNOWN_ROUTES_DENIED=PASS`, `PROTECTED_JWT_CART_GET_ACCEPTED=PASS`, `ANONYMOUS_AND_ALTERNATE_GET_DENIED=PASS`, `FINAL_STATUS=PASS`, `WRAPPER_RC=0`. Это **не FPM/JWT runtime acceptance**. Пользователь случайно вставил отдельный SQL Server/Restis вывод — он не относится к API V5 и не влияет на его выпуск.

**Следующее изменение (GitHub-only, без установки на сайт):** существующий `V5BitrixPdoCompatibilityBridge` теперь экспортирует `hasStrictV5HttpIngress()`, который true только для исходной проверенной FPM-границы текущего нативного Bitrix соединения, со строгим режимом без открытой/отравленной транзакции. Сам метод НЕ даёт permit, native-coverage или authentication. Обе существующие V5 auth-композиции — `V5MySqlPublicComposition::build` и `::buildEmailFirst`, а также `V5MySqlPilotComposition::build` — в реальном НЕ-CLI окружении отклоняют обычный PDO, неправильный SAPI и мост без исходной строгой границы. Существующие CLI staging/offline тесты сохраняют прежние контракты. Нет нового маршрутизатора, JWT/TOTP/SMS реализации, IP allowlist или доверия заголовкам клиента.

**НЕ проверено:** актуальные feature-изменения на реальном FPM, положительный production transaction permit и десять нативных путей изменения AUTH_EPOCH; реальный public traffic за NAT также не подтверждён из мобильной сети. `V5_AUTH=OFF`; existing V4 и установленный V5 не изменены. Следующим инженерным шагом остаётся **нативная реализация proof-портов и независимая аттестация writer coverage**, а не повторяющаяся диагностика TLS/SQL/protocol.

## 2026-10-25 — request-scoped V5 native factory (GitHub-only)

Последний операторский результат: `COMPOSITION_SOURCE_CHECK=PASS`, PHP lint 3/3, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`; V4/public V5/private candidate не изменялись, V5 AUTH OFF. Ранее успешно закрыты SQL continuity и точная GET cart / POST ingress matrix.

**Реальный узкий пробел:** `V5BitrixNativeFirstFactorComposition::build` требует `deviceId/platform`, но `V5ParallelHttpEntry` вызывал V5 transport factory без аргументов. Не должно быть повторного небезопасного чтения `php://input`, выведения парольного JSON в логи или фабрики из CGI/global body. Изменён только уже существующий путь:

- `src/V5ParallelHttpEntry.php`: после успешной проверки размера, HTTPS, маршрута, JSON-объекта формируется один `V5StagedHttpRequest`, который передаётся в lazy factory и ТОТ ЖЕ объект — в transport. Для JWT cart GET передаётся уже проверенная форма без чтения body, для анонимного GET cart factory не вызывается.
- `src/V5PrivatePilotConfig.php`: fixed `site-factory.php` получает этот объект как локально scoped `$request` через существующий строго ограниченный include; его filename не берётся от клиента. Старая прямая фабрика без запроса теперь отказывает, если конфигурация активна. Early integrity guard сохранён.
- `tests/V5ParallelHttpEntryTest.php`, `tests/V5PrivatePilotConfigTest.php` дополнены проверками scoped-request. Создан компактный `tests/V5FactoryRequestScopeTest.php` без синтетических учётных записей и без Bitrix bootstrap/DB/SMS; он проверяет отказ до I/O при V5 OFF, передачу POST и bearer-shaped GET после входной валидации, отказ на некорректный JSON, и недопуск анонимной корзины к native factory.
- `tests/diagnostics/run_v5_scoped_factory_readonly.sh` на host evrasia: PIN source SHA, immutable Git blob, PHP lint, один target test, no production writes и post-check. Скрипт чистит экран и scrollback СРАЗУ в начале; внешний copy-paste wrapper также обязан очистить экран **до и после вставки heredoc**.

**Факт:** изменения только feature GitHub; серверный stdout теста ещё ОЖИДАЕТСЯ. Строгая FPM фабрика/настоящие native security providers не установлены. `AUTH V5=OFF`, production V4 неизменён. Следующий реальный этап — в существующую factory включить проверенные native proof-порты, в частности password/TOTP recheck + epoch, trust, SMS recovery/locked PIN, без двойной реализации сайта.

## 2026-10-25 — scoped native first-factor build

Пользователь подтвердил реальный серверный вывод: `DEFAULT_OFF_WITHOUT_IO=PASS`, `VALIDATED_POST_REQUEST_SCOPED=PASS`, `INVALID_POST_NEVER_BOOTSTRAPS=PASS`, `ANONYMOUS_CART_PREBOOTSTRAP_DENIED=PASS`, `PROTECTED_GET_REQUEST_SCOPED=PASS`, `V5_FACTORY_REQUEST_SCOPE_TEST=PASS`, `FACTORY_SCOPE_TEST=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`. V4 и V5 не изменены; AUTH OFF. Повторять уже завершённую передачу запроса в фабрику не требуется.

Новая feature-only интеграция **в уже существующий** `V5BitrixNativeFirstFactorComposition::buildFromScopedSignIn`: получает `V5StagedHttpRequest` от проверенного V5 entry и серверные CGI данные, строго принимает только канонический HTTPS `POST /api/v5/auth/signin` с валидными `login/password/device_id/platform/trust_token`, сверяет серверный метод, URI и публичный `Host=evrasia.rest`, после чего вызывает ранее реализованный `build()` с теми же отдельными MySQL-соединениями для account read и 4-scope rate budget. Нет новой проверки пароля, rate-store, OTP/SMS логики или HTTP маршрута. Вспомогательный `deviceFromScopedSignIn` извлекает **только** `device_id/platform`, исключая логирование credentials и client-provided account IDs. Для GET cart, refresh, OTP verify и legal этот вход не действует.

Новый `tests/V5NativeFirstFactorRequestScopeTest.php` проверяет только формы входа (без synthetic users/Bitrix/DB): valid scoped signup shape, rejects foreign route, query, malformed JSON, oversized input, неправильные поля, устройство, платформу, trust_token. **Реальный Bitrix native password/lockout/OTP/epoch и production first-factor acceptance этим тестом НЕ подтверждаются**. Native login controls и SMS eligibility по-прежнему требуют site-owned доказанные реализации; V5 AUTH OFF. Следующий этап: реальная интеграция site-native policy/locked epoch/OTP providers, не повторять PHP/TLS/SQL continuity smoke.

Дополнение к scoped native first-factor: создан immutable launcher `site-integration/samzaberu-v5/tests/diagnostics/run_v5_native_first_factor_scope_readonly.sh`, commit `5e80aacc27939f66f33791a2f9be13dcbb615dbe`, Git blob `e0e8b11a3bce13dbe46fe6efcb212cedd83a7341`. Запуск только CLI на host `evrasia`, два скачиваемых source blobs с проверкой SHA, временный isolated source copy, no Bitrix bootstrap, no DB, no guest accounts, no production installation, V4/V5 postcheck. Скрипт очищает screen/scrollback до вывода. Тест `V5_SCOPED_FIRST_FACTOR_SHAPE=PASS` ещё **ожидает** фактического операционного stdout; не объявлять PASS до получения. Не повторять уже подтверждённый `V5_FACTORY_REQUEST_SCOPE_TEST=PASS`.
