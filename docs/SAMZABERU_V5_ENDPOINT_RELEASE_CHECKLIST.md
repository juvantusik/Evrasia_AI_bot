# API V5 / СамЗаберу — обязательный чеклист добавления HTTP-ручки

**Принято 03.11.2026. Применять к каждой новой или изменённой ручке V5 перед сообщением разработчику «готово».**

Цель: выдавать **работающие HTTP-ручки** приложения «СамЗаберу», а не только PHP-код или запись в таблице маршрутов. Не усложнять процессы. **V4 production не менять**, готовые бизнес-операции повторно не перепроверять без причины. Источник истины: **фактический production → актуальный GitHub → документация**. Из-за операторских изменений production-файлы могут отличаться от GitHub: перед исправлением смотреть действующий код, не копировать устаревшую feature-версию.

## 1. Прежде чем писать код

- [ ] Записать **точный** `METHOD /api/v5/path`, необходимость завершающего `/`, формат тела, ответ, JWT/публичный доступ и используемую V5 identity.
- [ ] Проверить, нет ли уже работающего контроллера/сервиса для этой бизнес-логики. Предпочитать минимальное локальное подключение; не переписывать V4 и не придумывать лишние эндпоинты.
- [ ] Узнать действующий маршрут и реальные исходники на **evrasia**: `/home/site_evrasia/web/evrasia.spb.ru/public_html`. Сохранить SHA изменяемых файлов.

## 2. СКВОЗНАЯ ЦЕПОЧКА МАРШРУТА — проверить ВСЕ точки

**Недостаточно прописать путь в `V5RouteMap.php`!** Обязательная цепочка (для конкретной ручки отмечать неиспользуемые звенья как N/A):

1. **Веб-сервер и вход:** `api/v5/.htaccess` (fallback на `router.php`), `api/v5/router.php`; выяснить, нет ли более ранней специальной ветки, перехватывающей URI. Для public-маршрутов учитывать `local/samzaberu-v5/api/v5/router.php` при его использовании.
2. **Карта действий:** `local/samzaberu-v5/src/V5RouteMap.php` — точное соответствие метода/пути → action.
3. **Входной transport:** `V5ParallelHttpEntry.php`, `V5PhpHttpRequestFactory.php`, `V5HttpTransportBoundary.php` — метод, JSON/лимит тела, POST/GET, Bearer, передача JWT в handler.
4. **ОБЯЗАТЕЛЬНО: allowlist native ingress:** `local/samzaberu-v5/src/V5ProductionNativeRequestBoundaryGuard.php`, в частности **`isProtectedRequestRoute()` и `isProtectedPostRoute()`**. Это **отдельный список допустимых HTTP-маршрутов**, независимый от `V5RouteMap`. Проверить точный формат существующего условия: бывает `in_array(...)`, бывает `$uri === '...' || ...`, бывает отдельный `if`. Добавить новый URI строго в нужную ветку с проверкой **метода**; не добавлять безусловный `return true`.
5. **Нативная проверка/транзакция:** `V5ProductionNativeBridgeFactory.php`, `V5ProductionNativeExclusiveTransactionPermit.php`, `V5BitrixPdoCompatibilityBridge.php`; убедиться, что уже существующие проверки допускают маршрут, **не отключать/ослаблять проверки transaction ownership и JWT**.
6. **Приватная фабрика:** `local/samzaberu-v5/private/http-factory.php` — загрузка классов и `V5ProductionAuthHttpComposition.php`, создание зависимостей и transport; следить за двойными копиями runtime классов (`local/php_interface/Controllers/V5/Runtime` vs `local/samzaberu-v5/src`).
7. **Обработчик:** `V5AuthHttpHandler.php` (action в `handleJson` и ответ) + реальный сервис/провайдер (`V5TrustHttpOperations` и т. п.). Если конструктор расширялся — проверить подключение в composition и фабрике. Для специальных маршрутов — соответствующий фактический контроллер, не выдумывать обязательность общего handler.

**Контрольная фраза перед выпуском:** «Router → RouteMap → Parallel Entry → Transport/Bearer → Native Guard → Bridge → Factory/Composition → Handler → Business Service — проверено или N/A».

## 3. Безопасность и минимальное production-изменение

- [ ] Для защищённой ручки только подлинный **V5 JWT**, действующая strong session и серверно проверенный USER_ID; для изменяющих данных операций — необходимые native session/user/epoch/transaction locks.
- [ ] Для `POST /auth/trust/enroll` **нужна именно assurance=`mobileid`**, совпадение подписанной сессии и device binding; одного JWT или клиентского `user_id` недостаточно. `device_id` генерируется приложением (`sz_` + 64 hex), `trust_token` генерируется и хранится **на устройстве**, на сервере хранится только hash. Сервер **не возвращает** trust token.
- [ ] Точный guard окружения (`hostname=evrasia`, root, путь, ожидаемый SHA), резервная копия и проверенный rollback **до записи**. Готовить patch во временном файле; `php -l` до замены; после записи проверить и при необходимости `systemctl reload php8.3-fpm`.
- [ ] Терминальный скрипт — **один copy-paste блок**, в начале `printf '\033[3J\033[2J\033[H'`; статусы `PASS/FAIL`, `FINAL_STATUS`, `FINAL_RC`, `WRAPPER_RC`, SSH оставлять открытым. Ни токены, ни пароли, ни номера телефонов не печатать.
- [ ] Не создавать синтетических пользователей; реальные операции проводить только в разрешённом сценарии.

## 4. HTTP-проверка после установки — обязательна

- [ ] Тест на **действующем** HTTPS vhost через origin при необходимости: `curl --resolve evrasia.rest:443:192.168.103.141 https://evrasia.rest/api/v5/...`; отдельно отличать origin-тест от внешней доступности.
- [ ] Для POST с JWT: **корректный формат JSON, но БЕЗ Bearer** → ожидаемый `401 authentication_failed` при доступном маршруте. `400` на `{}` **НЕ доказывает**, что фабрика/handler работает: JSON отвергается раньше.
- [ ] Некорректные поля/тело → `400`; неподдерживаемый метод → `405`; неизвестный маршрут → `404`; JSON + корректный Content-Type. Не считать эти отрицательные проверки положительным E2E.
- [ ] Успешная операция → **реальный пользователь и действительная V5-сессия**, проверить ответ и последующий эффект (например, validate trust), без публикации JWT и токенов; для чувствительных записей проверить idempotency/rollback по фактической логике.
- [ ] После установки/тестов занести **точный статус** в документацию и сообщить разработчику. «Код установлен» ≠ «HTTP доступен» ≠ «операция реально протестирована».

## 5. Если 503 — диагностика ПО ФАКТИЧЕСКОМУ запросу

- `503 {"error":"v5_unavailable"}` при валидном POST — фабрика/entry или native ingress, а не доказательство неверного JSON.
- **Сначала** `.../private/v5-runtime/http-factory-errors.log`. Сравнить размер **до и после ровно одного** корректно сформированного безопасного запроса; смотреть только **свежую** `STAGE/TYPE/CODE` запись. Старые ошибки относятся к другим маршрутам.
- `STAGE=V5_DEPENDENCIES CODE=v5_route_denied` → проверить `V5ProductionNativeRequestBoundaryGuard::isProtectedRequestRoute()`. В инциденте 03.11.2026 `POST /auth/trust/enroll` был в `V5RouteMap`, transport и handler, но **отсутствовал в native guard**, поэтому возвращал 503.
- `STAGE=BITRIX_NATIVE_BRIDGE CODE=v5_native_http_factory_denied` → фактический native admission/bridge; **не считать доказательством сбоя именно текущей ручки**, если запись не привязана к текущему запросу.
- `http-factory-stage.log` диагностирует **только `POST /api/v5/auth/signin`** (проверено по коду 03.11.2026); для trust enroll не использовать его как свидетельство успешной/ошибочной инициализации.
- Если `php8.3-fpm` reload не изменил 503, не повторять reload: выяснить **новую** ошибку фабрики/guard.

## 6. Готовность к передаче СамЗаберу

Отмечать стадии раздельно: `IDEA → CODE_INSTALLED → NEGATIVE_HTTP_PASS → POSITIVE_REAL_E2E_PASS → DEVELOPER_READY`. Внешнюю доступность подтверждать отдельно, если проверялся только origin.

**Инцидент 03.11.2026 — разрешён на уровне HTTP/JWT gate:** новая ручка `POST /api/v5/auth/trust/enroll` была установлена в шести V5-файлах, но корректный запрос без JWT сначала дал 503 из-за отсутствия разрешения в `V5ProductionNativeRequestBoundaryGuard` (`V5_DEPENDENCIES / v5_route_denied`). Предыдущий установщик не сработал, потому что искал массив, а в production guard используется `$uri === ... || $uri === ...`. После точечного добавления маршрута обнаружился второй блокер: `BITRIX_NATIVE_BRIDGE / v5_native_http_factory_denied` из-за устаревшего SHA-pin для Bitrix `mysqlcommonconnection.php` в `V5ProductionNativeTransactionPreflight.php`. SHA-pin обновлён по фактическому проверенному файлу, **Bitrix core не изменяли**. После reload PHP-FPM `POST /auth/trust/enroll` и `/auth/trust/validate` с валидным JSON без JWT вернули `401 authentication_failed`, новых ошибок фабрики нет (`FINAL_STATUS=PASS`). **Положительный Mobile ID JWT E2E и запись trust ещё не проверены.**

**Итог 03.11.2026 — положительный E2E зарегистрирован:** после устранения HTTP/bridge blockers первая реальная попытка с Mobile ID JWT дала `503 temporarily_unavailable`. Временная диагностика локализовала `v5_bitrix_strong_session_sql_forbidden` в точном SQL-allowlist `V5BitrixPdoCompatibilityBridge::STRONG_SESSION_SQL`; в production добавлены только две используемые `enroll` команды: `SELECT device_binding, platform ... WHERE sid = ? FOR UPDATE` и `UPDATE ev_v5_strong_sessions ... WHERE user_id=? AND status='active' AND sid<>?`. Бэкап `/home/site_evrasia/web/evrasia.spb.ru/backups/v5-trust-sql.PCfzDr29`; V4 и ядро Bitrix не менялись. **Положительный production origin E2E:** Mobile ID `AUTH` с реальным V5 JWT → `enroll` **200 enrolled=true** → `validate` **200 trusted=true** → `revoke` **200 revoked=true**; временная диагностика восстановлена, `FINAL_STATUS=PASS`. Статус `POSITIVE_REAL_E2E_PASS`, готово к передаче разработчику. Приложение на реальном телефоне в этом скриптовом тесте не использовалось — его интеграцию тестировать отдельно.

**Смежные актуальные документы:** [Production checkpoint 02.11.2026](SAMZABERU_V5_PRODUCTION_STATUS_2026-11-02.md) · [Полная спецификация API V5](SAMZABERU_API_V5_FULL_SPECIFICATION_2026-11-02.txt).
