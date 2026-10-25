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
