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
