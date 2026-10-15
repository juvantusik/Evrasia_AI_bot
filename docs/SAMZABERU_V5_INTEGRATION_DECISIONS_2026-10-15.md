# V5 — актуальный реестр интеграционных решений, 15 октября 2026

Дополнение к историческому STRUCTURE_REGISTRY. Полная предыдущая редакция R1–R8 сохранена в Git commit `2bec823b26087446601c59160dcb567cb2bb02de`. Новый инкремент — R9. Текущие решения читать через START HERE, а не устаревшие NEXT.

## R9. Private pilot configuration и public shim — реализовано, не развёрнуто

**Было:** самостоятельный PHP entry уже подключал existing transport через private server-side Closure, но не было готового loader/config формата, private entry и шаблона public shim с техническим допуском пилота.

**Стало:** V5PrivatePilotConfig читает bounded site-config.json из фиксированного private release вне docroot; mode off/pilot, SHA256 отдельного случайного operational key, срок до24часов и1–16 точных peer IP. Проверяет типы/права/владельца/отсутствие symlink/hardlink, не запускает native PHP при чтении. Site entry проверяет ключ+peer+server HTTPS до передачи запроса router, удаляет ранее зарегистрированную factory и pilot header из PHP server array. Factory — фиксированный site-factory.php, вызывается лениво после existing request checks; её файл/config/expiry проверяются повторно. Public shim имеет только fixed release/docroot literals и default503. Выключенный JSON example не содержит реальных credentials.

**Почему:** V5 должен включаться отдельно от V4 и ограниченно, без eager Bitrix load и без доверия клиентским флагам/номерам/IP forwarding. Приватные настройки не должны попадать в document root или принимать include path из HTTP.

**Evidence:** code/test `0caa0248c783188939398a71543ba740f064bb90`; offline38750965561/job116476933149 SUCCESS, прочитан лог86 PHP lint/26 suites/198 прежних entry/59 новых checks. Ephemeral38750966026/job116476934579 SUCCESS; состав329 DB checks не менялся. Четыре новых PHP файла linted локально; полный runtime suite в GitHub, не на сайте. Сравнение с2bec823b подтвердило6точечных файлов и отсутствие изменений native providers/V4/schema/transport/workflow.

**Границы:** это operational admission, НЕ account authentication. Key не даёт пароль/MFA/native coverage и не ограничивает сам по себе аккаунты: список разрешённых тестовых аккаунтов ещё нужен в реальной composition. Нет реального site-factory.php, production key, включённого pilot или доказанного live Apache/Nginx/TLS. Behind-proxy peer не равен клиенту; key обязателен, Forwarded/X-Forwarded-For игнорируются. Hash/permission проверки не sandbox враждебного PHP и не откат начатых запросов; deploy должен гарантировать trusted code, alias/ancestor security и атомарное переключение.

**NEXT:** один уже подготовленный read-only layout preflight на существующем evrasia; далее guarded выключенная установка в известной структуре сайта. Не новая VM. Ничего не устанавливать по неотрендеренному public-router template и не подставлять synthetic native callbacks ради включения.

## R7. Отдельная VM отменена как обязательное условие — принято пользователем

**Было:** физически отдельный Bitrix+MySQL staging требовался до любого движения; issue76 блокировалась отсутствием VM.

**Стало:** пользователь выбрал V4/V5 параллельно на `evrasia`, с общими аккаунтами/бизнес-данными и отдельными V5 code/config/keys/tokens/service tables/routes. Второй MySQL, контейнерная копия сайта и новая машина не обязательны. Issue76 переименована под подключение, старый provisioning plan заменён.

**Почему:** выбранный способ тестирования ошибочно превратился в продуктовый blocker и был отвергнут. Это supersedes R3 в части размещения. Guards CI-only/fault scripts и security event requirements не отменены. Конкретные production DDL/SMS/mutations требуют отдельного допуска и rollback; production-appropriate native permit ещё не принят.

## R8. V5 entry подключён к существующему transport — предыдущий успешный инкремент

**Было:** Bramus-заглушка возвращала503 даже при ready=true.

**Стало:** V5ParallelHttpEntry использует существующий transport через lazy server-only factory; exact paths/TLS/POST/JSON/size/length до native factory, bounded input, generic responses/no-store, подавление случайного вывода/cookies/redirects. Пять routes/V4/.htaccess не изменены.

**Почему:** отдельный entry рядом с V4 без нового login workflow или изменения legacy API. R9 добавляет deployment-конфигурацию, не меняя этот транспорт.

**Evidence:** code029aaff9115d39d6a489457867fbbe532434303d, offline38748326281/job116467747751 (83lint/26suites/198checks), ephemeral38748326331/job116467747777 (329). Проверки native Apache/TLS/providers не заменяются CLI.

## Сохраняемые решения R1–R6

| Решение | Было → стало → причина / evidence |
|---|---|
| R1 late receipt | Нет пути позднего UNKNOWN→SENT → reconcileUnknownSent без send/quota/TTL → неопределённую оплату нельзя лечить второй SMS. Runtime04915fab, fixture fixdc2caed;45 receipt checks. |
| R2 fixture ordering | Budget keys без sort → canonical sort только fixture → runtime guard не ослаблять. Первый run38736335930 FAIL сохранён. |
| R3 infra plan | Подготовлен отдельный staging plan → обязательность VM отменена R7 → не возвращать blocker. |
| R4 feature docs | Main push вызывает publish latest → docs только feature → не публиковать образ ради docs. |
| R5 SMS bridge | Delivery SELECT/autocommit ACK не проходили bridge → exact reads + owned CAS/COMMIT → успех после commit. Code8c395f0;63 новых tests/268 DB. |
| R6 locked password BEGIN | Нет готового fresh password/MFA adapter → V5BitrixLockedPasswordTotpProof с locked rows и прежним epoch HMAC → reset не обходится stale snapshot. Codec3f92ba;61 новых tests/329 DB; native positive providers synthetic. |

## Дополнение реестра структур

Пути относительно site-integration/samzaberu-v5.

| Структура | Изменение R9 | Граница |
|---|---|---|
| src/V5PrivatePilotConfig.php | Fixed private JSON, peer/key/expiry gate, lazy factory/file guards | Не native proof и не пользовательская авторизация |
| api/v5/site.php | V5-only private registration, удаление старой factory/header | Нет actual site composition в поставке |
| deployment/public-router.php | Fixed-path public shim template, off fallback | Пути подставляет проверенный installer, не HTTP |
| deployment/site-config.example.json | Off-only example | Реальные keys/IP не записаны |
| tests/V5PrivatePilotConfigTest.php |59 offline checks, real PHP child executions | Только собственный временный каталог; не web server/Bitrix |
| tests/V5RouteMapTest.php | +1require existing suite | Нет нового workflow;198 старых entry checks сохранены |
| api/v5/router.php, src/V5ParallelHttpEntry.php, HTTP providers/PROPOSED SQL/V4 | Без изменений R9 | Нельзя заменять proof pilot key или снимать fault guards |
| Current docs / issue76 | Новый checkpoint и следующий layout preflight | Нет server deployment; main не изменён |

Pending TOTP verification остаётся черновиком вне src/CI. Ранее заблокированный payload не повторялся и не переносился в эту работу.
