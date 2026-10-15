# СамЗаберу V5 — checkpoint после параллельного PHP entry

Дата: 15 октября 2026. Читать с START HERE и integration decisions.

## Новое решение пользователя

Отдельной VM/нового сервера не будет. V4/V5 размещаются параллельно на существующем Bitrix `evrasia`; отдельная копия сайта/второй MySQL не обязательны. Issue76 переведена из требования VM в задачу подключения V5 рядом с V4. Это согласование архитектуры, не выполненная установка и не разрешение произвольных production SQL/SMS/security mutations. Старые инструкции Stage A отдельной машины больше не определяют NEXT.

## Проверенный код

**`029aaff9115d39d6a489457867fbbe532434303d`** — последний code/test commit с двумя успешными CI.

| Run / job | Подтверждённый лог |
|---|---|
| Offline `38748326281` / `116467747751` | SUCCESS; 83 PHP lint; 26 approved suites; внутри RouteMap suite 198 новых parallel-entry assertions; FINAL_STATUS=PASS / FINAL_RC=0 |
| Ephemeral MariaDB `38748326331` / `116467747777` | SUCCESS; 43+117+45+63+61=329 assertions; FINAL_STATUS=PASS / FINAL_RC=0 |

Логи обоих jobs прочитаны. 198 новых тестов — offline, не дополнительная MariaDB coverage. Четыре изменённых PHP-файла проверены локальным php -l до commit; полный runtime regression выполнен в GitHub. Локально clone не состоялся из-за DNS, поэтому локальный полный suite не заявлялся.

Изменены только `api/v5/router.php`, новый `src/V5ParallelHttpEntry.php`, новый `tests/V5ParallelHttpEntryTest.php`, существующий `tests/V5RouteMapTest.php` (включает новый test без нового workflow). Пять V5 route specs и .htaccess не изменены. V4, DDL и security/provider реализация не менялись.

## Подключение entry

Старый router возвращал503 даже с ready=true. Новый entry реально передаёт запрос в существующий V5HttpTransportBoundary/V5AuthHttpHandler, если private SERVER PHP config задаёт strict ready=true и Closure factory. Factory обязана возвращать существующий transport с проверенными providers, не вызывать V4 auth/bootstrap и не запускать native код при простой регистрации. HTTP JSON/headers/cookies не источник конфигурации. Site configuration и native factory в репозитории не установлены.

Default-off/no factory —503 до body read/bootstrap; неверные path/HTTPS/method/content-type/length отсекаются до body read, неверное JSON/размер — до native factory. php://input читается с лимитом8193, допустимое тело до8192. Точное совпадение пяти путей, никакого redirect/fallback в V4. Действующая HTTP/strong-session/native security validation сохраняется. No-store, подавление Set-Cookie/Location и обычного случайного вывода factory в V5 emitter. Entry не является sandbox для недоверенного PHP factory.

Tests: все пять путей достигают существующего handler; неверные credentials дают401, test providers всегда отказывают (нет fake-success auth). Проверены ложные proxy TLS headers, пути V4/v50/direct router/query/encoded, методы/JSON/body bounds/ошибки factory. Три отдельных PHP процесса выполняют настоящий router с отсутствующей factory и подтверждают503, несмотря на клиентские флаги. **Apache/Nginx routing, TLS topology и native Bitrix не проверялись этим test.**

## Что дальше

V5-only private bootstrap/site composition + public shim на текущем сервере. См. `site-integration/samzaberu-v5/api/v5/README.md`. Это следующий незавершённый стык, не создание VM и не повторение source-only CUser/PhoneAuth исследований. Перед установкой только целевая проверка соответствующего окружения и минимальный deployment plan; DB installation — отдельно разрешённая additive миграция с backup. Pilot ACL/аккаунты и реальная native policy ещё должны быть согласованы/подключены.

## Незавершённое и история

Native TOTP-code/replay, phone-success/same-E164 versioning, 10-path AUTH_EPOCH security hooks, сильный trust, SMS lifecycle/paid provider/receipt и RestIS остаются реальными интеграционными задачами. Общая БД не отменяет их и не делает фиктивные coverage providers допустимыми. Старый native-staging permit с host deny не отключён; нужен проверенный production-appropriate scope для контролируемого V5, не перенос fault scripts.

`V5BitrixLockedTotpFactor` — черновик из pending-документа, не в src и не в CI. Заблокированный тест предыдущей итерации не повторялся. `V5BitrixLockedPasswordTotpProof` изc3f92ba остаётся готовым BEGIN read adapter, не TOTP verifier.

Предыдущий329-check checkpoint, детали earlier268/205/160 и история FAIL fixture сохранены в Git на91b7f9d5ce73aa672344d81c95b6427053735df8. После current doc commit HEAD может отличаться от029aaff только документами; проверить live ref/compare. Main последний проверенный7c2e9f8e4501b92469bcdeea18f8340676ad52c9, не изменять автоматически.

OFFLINE_TESTED=PASS; REAL_EPHEMERAL_MARIADB_PASS=YES(329); PARALLEL_ENTRY_OFFLINE_TESTED=YES(198); REAL_SITE_PARALLEL_ACCEPTANCE=NOT_RUN; REAL_BITRIX_STAGING_TESTED=NO; PRODUCTION_DEPLOYED=NO. Исторический HTTP503 не выдавать за свежий live-test. Никаких production SQL/SMS/V4 изменений этой итерацией.
