# V5 — актуальный реестр интеграционных решений, 15 октября 2026

Дополнение к историческому STRUCTURE_REGISTRY. Полная редакция R1–R6 сохранена в Git commit `91b7f9d5ce73aa672344d81c95b6427053735df8`; ниже их смысл сохранён и добавлены R7/R8. Текущие решения читать через START HERE, не через устаревший NEXT исторических документов.

## R7. Отдельная VM отменена как обязательное условие — принято пользователем

**Было:** физически отдельный Bitrix+MySQL staging требовался до любого движения к site integration; существующие hosts исключались из всех вариантов размещения, issue76 блокировалась отсутствием VM.

**Стало:** пользователь прямо заявил, что отдельной VM нет и не будет, и выбрал параллельную работу V4/V5 на существующем сервере. Целевой host Bitrix `evrasia`; `/api/v4/…` сохраняется, `/api/v5/…` добавляется рядом. Аккаунты/бизнес-данные общие; V5 code/config/keys/tokens/service tables/versioned routes отдельные. Таблицы могут находиться в существующей Bitrix DB. Второй MySQL instance, контейнерная копия сайта и новый сервер не являются обязательными. Issue76 переименована и переписана под параллельное подключение; старый provisioning plan помечен как заменённый.

**Почему:** независимость версий API не требует дополнительной машины. Выбранный ранее способ испытаний ошибочно превратился в продуктовый инфраструктурный blocker. Пользователь его отклонил. Это новое подтверждённое решение supersedes R3 в части размещения.

**Что НЕ отменено:** не нарушать V4, не проводить отказные эксперименты на клиентах, не выдавать synthetic CI за native proof. У общей БД есть общие locks/security events, поэтому AUTH_EPOCH/phone versions/replay нужны по-прежнему. Конкретная production установка/DDL/SMS/mutations требует отдельного допуска, backup и rollback. Legacy CI-only/fault guards не удалять ради запуска на рабочем host. Production-appropriate native transaction permit ещё не подготовлен/не принят.

## R8. V5 PHP entry подключён к существующему HTTP transport — реализовано и CI-tested

**Было:** api/v5/router.php содержал самостоятельную Bramus-заглушку и возвращал503 даже при ready=true. Готовые transport/handler/workflow не были подключены к entry.

**Стало:** новый V5ParallelHttpEntry вызывает существующий transport через ленивую server-only Closure factory; router читает строго boolean ready и серверный factory global. Нет HTTP-driven config, динамических путей include или V4 fallback. По умолчанию503 до body read/bootstrap. Проверка exact V5 paths/TLS/POST/JSON/type/size/length до native factory, bounded php://input, generic errors/no-store. Emitter не выдаёт V4 cookies/redirects и подавляет обычный неожиданный вывод factory. Пять contract routes, auth policies, V4 и .htaccess не изменены. Private site config/factory/pilot scope ещё не установлены — один флаг не делает backend готовым.

**Почему:** V5 должен получить собственный entry рядом с V4, переиспользуя уже написанный транспорт, а не заменять legacy router или дублировать бизнес-логику. Выключение V5 не должно зависеть от загрузки Bitrix и не должно переключать V4.

**Evidence:** code/test `029aaff9115d39d6a489457867fbbe532434303d`. Offline run38748326281/job116467747751 SUCCESS:83 PHP lint,26 suites,198 новых entry assertions в existing RouteMap suite. Ephemeral run38748326331/job116467747777 SUCCESS:329 existing assertions. Оба лога прочитаны. Все четыре изменённых PHP файла linted локально до commit; локальные полные regression tests не заявлялись.

198checks проверяют default-off/no I/O/no bootstrap, exact path и metadata/body guards, existing handler401 для отвергнутых credentials и три дочерних PHP процесса с настоящим router. Положительной native authentication в новом test нет. Реальные Apache/Nginx rules, native Bitrix, pilot endpoints и provider API этим НЕ приняты.

## Сохраняемые решения R1–R6

| Решение | Было → стало → причина / evidence |
|---|---|
| R1 late receipt | Не было пути для позднего доказанного UNKNOWN→SENT → explicit reconcileUnknownSent без нового paid send/quota/TTL → неопределённую оплату нельзя лечить повторной SMS. Runtime04915fab, исправленный testdc2caed;45 receipt checks. |
| R2 fixture ordering | Новые quota keys без canonical sort → сортировка fixture без ослабления runtime guard → безопасные входы обязаны соблюдать контракт. Первый run38736335930 FAIL сохранён в истории. |
| R3 infra plan | Подготовлен отдельный staging plan, но VM не подтверждалась → **обязательность отдельной VM отменена R7**, план исторический → не возвращать этот blocker. |
| R4 feature docs | Main push вызывает build/publish latest → docs только feature → не обновлять опубликованный образ ради документации. Main7c2e9f8 неизменён. |
| R5 SMS bridge | Четыре delivery SELECT и autocommit ACK не проходили bridge → exact reads + owned BEGIN/CAS/COMMIT → успех только после commit. Code8c395f0;63 bridge tests;268 DB тогда. |
| R6 locked password BEGIN | Обязательный fresh password/MFA port без готового adapter → V5BitrixLockedPasswordTotpProof с текущими locked rows и прежним epoch HMAC → reset между first factor и challenge не обходится старым snapshot. Codec3f92ba;61 new tests;329 DB. Native hooks/comparator в positive CI synthetic, не acceptance. |

## Дополнение реестра структур

Пути относительно `site-integration/samzaberu-v5/`, если не указан docs.

| Структура | Текущее изменение | Граница |
|---|---|---|
| `src/V5ParallelHttpEntry.php` | Отдельная входная проверка V5 и lazy transport composition | Нет DB/Bitrix bootstrap по умолчанию; нет native factory в поставке |
| `api/v5/router.php` | Wire существующего transport, bounded input и response emitter | V4 не вызывается, default-off; site deploy не выполнен |
| `tests/V5ParallelHttpEntryTest.php` | 198 offline checks, реальные child PHP executions router | Не Apache/TLS/native test, credentials всегда reject |
| `tests/V5RouteMapTest.php` | Включает новый test в прежний26-suite runner | Контракт5routes не менялся; новых workflow не создано |
| `api/v5/.htaccess` | Без изменения directives | Real web-server precedence ещё требует целевой проверки |
| `api/v5/README.md` | Контракт V5-only private config/public shim | Proposed placement, не установленный config |
| Auth/SMS/session/epoch providers и PROPOSED SQL | Без кодовых изменений R8 | Нельзя снимать security checks из-за shared host |
| Issue76 / current docs | Параллельное подключение вместо VM blocker | Архитектура согласована, deployment/DDL approvals отдельные |

В pending TOTP verification документе сохранён только черновик, не проверенный verifier. Предыдущая заблокированная загрузка теста не повторялась и не обходилась. Новые entry tests не включают тот draft.
