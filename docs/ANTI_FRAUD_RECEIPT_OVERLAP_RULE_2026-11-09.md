# Anti-Fraud: пересекающиеся чеки одной карты — 2026-11-09

## Решение оператора

Ищем **пересечения времени открытия/закрытия РАЗНЫХ чеков, на которые применена одна и та же карта лояльности**. Приоритет:

1. `different_restaurants` — одна карта, два одновременно открытых чека в **разных** ресторанах; более подозрительный сценарий.
2. `same_restaurant` — одна карта, два одновременно открытых чека в **одном** ресторане; возможное применение сотрудником собственной карты на гостевых заказах.

Обе категории — **сигналы для ручной проверки**, а не доказательство мошенничества. Существующие правила Check-in Scout, Risk Score и ручных блокировок не изменяем.

## Факты по текущему источнику

Проверен код default branch 2026-11-09:
- `restis-antifraud-gateway.ts`: VIP_TODAY/VIP_HISTORY parsing знает `CARD_NO`, `OPEN_DATE`, `ID`, `NAME_OBJECT`. Поле подтверждённого окончания чека не используется.
- `bitrix-antifraud-loyalty-gateway.ts`: protected loyalty history передаёт `restis_id`, `occurred_at`, ресторан, сумму, начисления и списания. **Не передаёт** подтверждённое закрытие чека и отдельный стабильный идентификатор карты в истории каждого события.
- `anti_fraud_visits`: verified loyalty rows могут иметь `card_id=NULL`; события привязаны только к Bitrix USER_ID. Следовательно, нельзя доказывать **одну и ту же карту** только через USER_ID.
- `VIP_HISTORY` может иметь несколько денежных операций для одного `restis_id`; это не дополнительные физические чеки.
- В выгрузке Excel два поля: `Дата открытия счета` и `ДатаВремя операции`. **Второе НЕ доказано временем закрытия чека**; возможны сторно, повторное начисление/списание.

**Следствие:** текущего production feed недостаточно для достоверной автоматической выдачи сигнала пересечения. Запрещено выдумывать `closed_at` из `occurred_at` или считать все `restis_id` из денежных событий уникальными чеками без проверки контракта.

## Реализованный этап 1 (feature branch, без production-изменений)

Чистая функция `detectAntiFraudReceiptOverlaps` в `artifacts/api-server/src/services/anti-fraud-receipt-overlaps.ts`:
- принимает только подтверждённые закрытые чеки с `cardKey` (внутренний непрозрачный ключ конкретной карты), `receiptKey`, `restaurantKey`, `openedAt`, `closedAt`, `closedAtEvidence='confirmed_receipt_close'`;
- сравнивает строго внутри одной `cardKey`, даже если одному Bitrix USER_ID принадлежат несколько карт;
- исключает дубликаты одного `receiptKey`, противоречивые дубликаты отклоняет;
- пересечение только положительной длительности: `max(openA,openB) < min(closeA,closeB)`;
- возвращает пару чеков, ресторан(ы), временной интервал и миллисекунды пересечения, сортирует **разные рестораны выше**;
- ничего не записывает в БД, не меняет баллы, не блокирует клиентов.

Тесты в `anti-fraud-receipt-overlaps.test.ts`: пересечение из примера 03.11.2026, разные рестораны, разные карты, смежные интервалы, дубликаты, некорректное время, неподтверждённый close.

## Для завершения интеграции — только после проверки реального источника

1. На стороне RESTIS/SQL Server выяснить **фактические** таблицы, поля и связи:
   - идентификатор карты;
   - идентификатор **чека** (не строки движения бонусов);
   - ресторан/объект;
   - подтверждённые `opened_at` / `closed_at`;
   - статус отмены/сторно и возможность отличить два чека одного стола.
2. Если реальный RESTIS уже предоставляет эти поля в защищённом site API, использовать существующий protected boundary. Не добавлять SQL Server credentials в bot.
3. Адресный запрос истории для **подтверждённой карты**, окно максимум 60 дней; использовать существующие правила History Gate или явно санкционированное операторское расследование; не делать полный backfill всех карт.
4. После получения реальных данных подключить read model, case evidence и operator UI. Отдельно учитывать разные/одинаковые рестораны, считать пары и дни за 7/30/60 дней. Не включать новые risk points до просмотра репрезентативных данных и явного утверждения порогов.
5. Не показывать raw CARD_NO, телефоны или технические IDs карты в операторских доказательствах и логах.

## Ограничения предварительного Excel-анализа

Две предоставленные оператором выгрузки содержат операции, а не гарантированную таблицу закрытых уникальных чеков. Предварительные пересечения дают **кандидатов на проверку**, не фактически доказанные параллельные заказы. Нельзя использовать такие числа для автоматической блокировки или немедленного повышения риска.

## Текущий статус

`ALGORITHM_CODED`, `UNIT_TESTS_ADDED`, `SOURCE_CLOSE_CONTRACT=NOT_VERIFIED`, `PRODUCTION_DEPLOYED=NO`, `DB_CHANGED=NO`, `RISK_SCORING_CHANGED=NO`.


## Подтверждено оператором в RESTISBONUS, 2026-11-09

- Production SQL Server 2014, database `RESTISBONUS`; `dbo.RB_CARD_LOG` содержит `CardNumber`, `ID_OBJECT`, `ID_BILL`, `Pre_BillNumber`, `OPEN_DATE_TIME`, `DateWrite`, `Waiter`, `Bill_Sum`, `PayedBonus`, `AddBonus`, `REJECT`. Отдельная колонка закрытия счета не обнаружена при поиске metadata.
- Существующий учетный логин `ev_bonus_sync_ro` имеет SELECT на `CardNumber`, `DateWrite`, `PayedBonus`, `AddBonus`, `ID`, `REJECT`, но **не имеет** SELECT на `Bill_Sum`, `ID_BILL`, `ID_OBJECT`, `OPEN_DATE_TIME`, `Waiter`, `Pre_BillNumber`, `ID_TERMINAL_BONUS`. Исходный код SQL Server error 230 был вызван этими column permissions. Права логина не менялись.
- Административный read-only SSMS запрос на `EUR-CRM` подтвердил по анонимизированным данным одну карту, ресторан `ID_OBJECT=41`, два разных счета: `ID_BILL=1326752`, открыт `2026-11-03 18:09:42`, положительная операция `19:02:01.190`, сумма `1240`; `ID_BILL=1326766`, открыт `18:38:16`, положительная операция `20:21:52.330`, сумма `1825`. `Waiter` положительных операций совпал. По второму счету на следующий день была еще операция `Bill_Sum=0`, `DateWrite=05:01:22.010`, с другим `Waiter`; поэтому `DateWrite` всё ещё **не** подтвержден как close timestamp.
- Адресный 60-дневный запрос для **этой одной карты** с границами `OPEN_DATE_TIME .. MAX(DateWrite) по операциям Bill_Sum>0` обнаружил **29 пар пересечений в 23 разных дня**; 28 пар в ресторане 41, одна в ресторане 38, **0 пар в разных ресторанах**, 0 пар с ненулевым `REJECT` (только в охваченных положительных строках). Это **candidate overlap**, а не 29 доказанных одновременно открытых столов: пары могут делить счета при тройных пересечениях. Пример 03.11 — прокси-пересечение 1425 секунд (23:45).
- **Следующий этап:** проверить для этих 29 пар `Waiter` каждого отдельного положительного чека, совпадения `Pre_BillNumber`, число счетов/дней; затем выяснить authoritative close time в системе ресторанных заказов (возможно, вне RESTISBONUS). Не менять бизнес-логику и риск-скоринг без подтвержденного timestamp.
- Production Anti-Fraud код, Bitrix, RESTIS, базы и права **не менялись**.


## Проверка Waiter и Pre_BillNumber

Read-only SSMS result for one previously confirmed card, 2026-11-09: **29 candidate overlap pairs on 23 days**, all pairs within the same restaurant, no cross-restaurant pairs; **14/29 with the same single Waiter on both bills**, **15/29 with different Waiter**, **0 unknown**. All **29/29 have different `Pre_BillNumber`** (no unknown). No nonzero REJECT in filtered positive bill rows.

This strengthens the fact of different bills for the same card, including events involving multiple waiters; it does **not** prove different physical tables or that one of the waiters owns the card. The `Waiter` value is the operational field recorded for the bonus transaction and its semantics must not be overstated. End time remains a **proxy via last positive DateWrite**, not a verified POS check close. The 29 candidate pairs must not be scored as 29 confirmed parallel open tables.

Next implementation should expose candidates separately from confirmed receipt close evidence, with `same_restaurant`/`different_restaurants`, `same_waiter`/`different_waiter`, and a source/evidence-quality marker. No automatic blocking.


## Пробный сетевой срез за семь полных суток

Оператор выполнил ограниченный read-only срез `dbo.RB_CARD_LOG` за **7 полных суток** (фильтр по дате открытия, положительная `Bill_Sum`, `REJECT=0/NULL`, `DateWrite > OPEN_DATE_TIME`, последняя положительная операция не позже восьми часов с открытия):

- **1 368** candidate overlap pairs (не уникальные мошеннические события).
- **1 130** разных карт с хотя бы одной кандидатной парой.
- **15** candidate pairs в **разных ресторанах**, на **15 разных картах** (верхние 15 строк по приоритету; у каждой одна cross-restaurant пара).
- **1 353** pairs в одном ресторане.
- **1 047** candidate pairs с одним значением `Waiter`; **321** с разными; unknown = 0 в этом наборе.
- Наиболее выраженные систематические совпадения **в одном ресторане**: без раскрытия card numbers, rank #16 — 28 пар в 7 дней, #17 — 12 пар в 4 дня, #18 — 10 пар за один день, #19 — 8 пар в 6 дней, #20 — 8 пар за два дня. Количество пар может включать несколько комбинаций одних и тех же чеков при тройных пересечениях, поэтому оно не равно числу отдельных чеков.

**Интерпретация:** 15 пар в разных ресторанах приоритизировать для анализа времен **положительных бонусных операций на каждом отдельном счете**, поскольку интервал открытия счета + last positive DateWrite может преувеличивать фактическую одновременность присутствия гостя. Сравнить наименьшую разницу между реальными `DateWrite` на двух счетах; не утверждать, что это момент прикладывания карты/закрытие, пока семантика не установлена. Затем проверить выраженные recurring same-restaurant cases. Не запускать второй массовый source scan без необходимости: известны точные пары `(ID_OBJECT, ID_BILL)` для этих 15 примеров и доступны локальные точечные индексированные чтения.

**Пока НЕ сделано:** автоматическое выявление на production, protected source API для фактических чеков, подтвержденное `closed_at`, operator UI/risk integration. SQL Server остается read-only; сайт, бот, права и баллы не менялись.


## Результат адресной проверки 15 разных ресторанов

В `RESTISBONUS.dbo.RB_CARD_LOG` оператор проверил **15 уже обнаруженных cross-restaurant пар**, строго по известным `(ID_OBJECT, ID_BILL)`, с совпадением нормализованного номера карты, `Bill_Sum>0`, `REJECT=0/NULL`. У каждой пары нашлась **ровно одна** комбинация положительных операций в выбранном окне (в выводе `matching_operation_combinations=1`).

**Ближайшие `DateWrite` по 15 разным картам:**
- **1 случай до 15 минут** (11 мин 05 сек);
- **6 случаев от 15 до 30 минут** (19–29 минут);
- **8 случаев более 30 минут**;
- **0 случаев до 5 минут**;
- **15/15**: `same_waiter=NO` (разные рестораны, ожидаемо).
- Самый близкий пример: объекты **167/62**, счета **961405/742989**, `DateWrite` **2026-11-02 15:33:44.813 / 15:44:49.857**; фактическая разница около **665 секунд** при SQL Server `DATEDIFF(SECOND)`.

**Важно:** `DateWrite` — timestamp бонусной операции, **не доказательство закрытия физического стола и не доказательство, что владелец лично предъявил карту**. При разных ресторанах необходимо также учитывать их физическое расположение, возможные задержки проведения операции и доступность номера карты сотрудникам. Все 15 остаются операционными сигналами для ручной проверки, **не подтверждённым мошенничеством**.

### Дополнительный алгоритм для данных, которые действительно имеются

На той же feature-ветке добавлен `anti-fraud-card-operation-proximity.ts`: отдельно от детектора подтверждённых интервалов закрытия чеков он распознаёт **положительные бонусные операции одной конкретной карты в разных ресторанах с разницей до 30 минут**. Он принимает только заранее отфильтрованные source rows, дедуплицирует повторные операции, выбирает ближайшую по времени пару операций для каждой пары **реальных счетов**, маркирует результат `positive_bonus_operations_not_receipt_closure`. Никаких writes/blocks/scoring. Добавлено 7 unit-тестов; факт исполнения unit-тестов и CI пока не подтверждён.

**Остаток для боевого запуска:** предоставить защищённый backend source с `cardKey` (не raw `CardNumber` в UI), `ID_OBJECT`, `ID_BILL`, уникальным ID операции, и `DateWrite` из `RESTISBONUS`; существующий `ev_bonus_sync_ro` **не имеет SELECT** на нужные идентификаторы счета и объекта. Это требует отдельного решения для read-only прав и site-side API. Нельзя заменять `cardKey` на Bitrix `USER_ID` или выдавать неподтверждённый close за факт.


## SQL Server доступ и автономный reader: 2026-11-09

Оператор в **SSMS на `EUR-CRM`** выполнил успешную транзакцию `GRANT SELECT ON OBJECT::dbo.RB_CARD_LOG (ID_OBJECT, ID_BILL, Bill_Sum) TO [ev_bonus_sync_ro]` с `EXECUTE AS LOGIN` и `SELECT TOP (0)`. Подтвержденный вывод: `EXISTING_COLUMN_GRANTS=0`, `PASS: THREE_COLUMN_SELECT_GRANTED`, `PASS: EFFECTIVE_READ_ACCESS`, `FINAL_STATUS=PASS`. Исходные данные БД, схемы и bonus worker не менялись. Старые права `CardNumber`, `ID`, `DateWrite`, `REJECT` сохранились; `OPEN_DATE_TIME` и `Waiter` не выдавались.

Создан отдельный read-only Python CLI `scripts/anti-fraud/restis-card-operation-reader.py` и offline fixtures `test_restis_card_operation_reader.py`, **только в feature-ветке**. Алгоритм:
- использует уже существующий `event_worker.connect_source()` для SQL Server, НЕ вызывает `event_runner.py` и НЕ запускает worker;
- проверяет `DB_NAME()`, `ORIGINAL_LOGIN()`, host и root; запрашивает последние максимум 7 полных дней по **`DateWrite`** с `Bill_Sum>0`, `REJECT=0/NULL`, предел 200 000 строк;
- только для **точно той же карты**, двух **разных ресторанов**, выбирает минимальный разрыв до 1800 секунд между **реальными положительными бонусными операциями** разных физических счетов `(ID_OBJECT, ID_BILL)`;
- выводит агрегаты и максимум 20 примеров без номера карты, токенов, персональных данных; не делает writes/score/block;
- отмечает, что это **не** `closed_at`, и не факт личного предъявления карты владельцем.
- Скрипт и offline fixtures сохранены в GitHub; фактический запуск на production и результаты unit-тестов пока **не подтверждены**. Ни сайт, ни bot app, ни cron/timer ещё не изменены.

Сейчас корректный ближайший этап — однократный read-only smoke test этого CLI на хосте `evrasia`, затем отдельно согласованный защищенный transport → API → UI. Не выдавать наличие Python файла в репозитории за production интеграцию.


## Первый production smoke test SQL reader

Оператор выполнил **read-only** Python smoke test `event_worker.connect_source()` с уже добавленными тремя SELECT column permissions. Подтверждено `DB_NAME=RESTISBONUS`, `ORIGINAL_LOGIN=ev_bonus_sync_ro`; за **одни полные сутки 2026-11-08** прочитаны **6781** положительных, неотклоненных событий из `dbo.RB_CARD_LOG` (по `DateWrite`), **6381** различных карт. Cross-restaurant operation-proximity candidates (<=30 minutes): **0**. `FINAL_STATUS=PASS`, никакой записи/worker execution/site change. Это ожидаемо относительно предыдущих 15 cross-restaurant receipt-overlap пар: все 7 пар с `DateWrite` gap <=30 min в предыдущей выборке пришлись на **2–5 ноября**, а не 8 ноября.

**Следующий контроль**: однократный **7-day DateWrite window (2026-11-02 .. 2026-11-09 exclusive)** с тем же Python источником для подтверждения известных пар, включая объекты 167/62, счета 961405/742989, ~665 s. Результаты `DateWrite`-based proximity не следует сравнивать напрямую с 1368 `OPEN_DATE_TIME .. DateWrite`-based candidate intervals: это разные правила.


## Контрольное подтверждение семи суток: PASS

2026-11-09 оператор выполнил **дополнительный независимый production read-only SQL/Python аудит** на host `evrasia` под `ev_bonus_sync_ro` за **2026-11-02 00:00:00 – 2026-11-09 00:00:00 (exclusive)**, фильтр по `DateWrite`, `Bill_Sum>0`, `REJECT=0/NULL`, ID и CardNumber не пустые. Результат:
- `SOURCE_OPERATION_ROWS=44903`, `CARDS_IN_SOURCE=35622`;
- `CANDIDATE_RECEIPT_PAIRS=7`, `CANDIDATE_CARDS=7`;
- `PAIRS_WITHIN_5_MIN=0`, `PAIRS_WITHIN_15_MIN=1`, `PAIRS_WITHIN_30_MIN=7`;
- `KNOWN_REFERENCE_PAIR_FOUND=YES`, `KNOWN_REFERENCE_GAP_SECONDS=665`;
- все семь пар совпали с ранее извлеченными семью случаями из SSMS;
- `FINAL_STATUS=PASS`, `DATABASE_WRITE=NO`, `SITE_WRITE=NO`, `API_WRITE=NO`, `BONUS_WORKER_EXECUTION=NO`.

Точная семерка (парные `ID_OBJECT/ID_BILL`, не персональные данные):
1. `167/961405` и `62/742989` — 665 с.
2. `143/784590` и `167/961853` — 1151 с.
3. `41/1327256` и `44/649426` — 1311 с.
4. `122/789729` и `166/412322` — 1506 с.
5. `154/578775` и `70/696192` — 1509 с.
6. `6/523046` и `153/843494` — 1555 с.
7. `38/1055992` и `67/678789` — 1732 с.

**Что подтверждено:** реальный SQL Server source readable с минимальными column grants, и автономная логика proximity воспроизводит результат SSMS для семи суток. **Что НЕ подтверждено:** принадлежность карты клиенту/официанту, момент предъявления, физическое присутствие, реальный конец чека.

**Дальше:** защищённый source-to-bot feed на базе действующей website Anti-Fraud авторизации; не подключать к существующему скорингу, автоблокировкам или `VIP_HISTORY`. Production website `AntiFraudLoyaltyService.php` имеет `authorized()`, `response()` и существующий route `POST /api/internal/anti-fraud/loyalty`; **сам SQL reader пока не интегрирован в PHP endpoint**.
