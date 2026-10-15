# V5 — дополнение реестра структур и решений, 15 октября 2026

Актуальное дополнение к `STRUCTURE_REGISTRY.md` для инкрементов после feature `0ff80561db4654eeeeac159e762e21bda479e909`. Общий исторический реестр не переписывается; контекст других частей Evrasia сохраняется. Читать через `SAMZABERU_V5_START_HERE_2026-10-15.md`. Самое новое решение — R5.

## R1. Поздняя SMS-квитанция без второй оплаты — реализовано и CI-tested

**Было:** единственный claim, independent SENT receipt для claimed→sent, UNKNOWN запрещён к повторной отправке; явного пути учесть позднее доказанный SENT для UNKNOWN не было.

**Стало:** внутренний метод `V5MySqlSmsDispatchClaims::reconcileUnknownSent()` переводит только UNKNOWN→SENT через независимый receipt proof и conditional UPDATE. Нет вызова send/resend, возврата envelope, изменения challenge/TTL/epoch/phone, освобождения/повторного расходования quota. Старые методы и публичный route map не меняются.

**Почему:** неопределённость платной отправки должна разрешаться проверкой уже произошедшей операции, а не вторым платным запросом. Поздний SENT не оживляет expired/cancelled challenge и не заменяет свежую проверку native состояния.

**Evidence:** code `04915fab87abf21eec1649f75b053c9bb91619dc`; исправленная fixture и обе успешные CI на `dc2caed6af465aeb387490d20f1173005a80e3dd`, runs `38736808948` / `38736809040`. 45 новых MariaDB assertions; независимый provider в тесте СИНТЕТИЧЕСКИЙ. Реальная provider integration НЕ закрыта.

## R2. Обнаруженная ошибка новой fixture — исправлено без ослабления guard

**Было:** новая fixture строила 10 SHA256 budget keys в порядке генерации. Первая CI корректно остановилась на Noncanonical SMS window ordering.

**Стало:** fixture сортирует окна по bucketKey до создания reservation. `V5SmsDispatchBudget` и canonical lock-order check не менялись. Прежние 160 tests сохранены; итог того успешного прогона 205.

**Почему:** test inputs обязаны соблюдать существующий контракт; production-кандидат нельзя ослаблять ради зелёного теста.

## R3. Переход к native integration — план подготовлен, инфраструктура UNATTESTED

**Было:** документы фиксировали blocker отдельного Bitrix staging; вместо него нельзя использовать production или ephemeral synthetic CI.

**Стало:** составлен `SAMZABERU_V5_STAGING_PROVISIONING_PLAN_2026-10-15.md` с конкретными фазами, проектными именами, evidence для изоляции, остановкой перед bootstrap/DDL, rollback и точными существующими acceptance entrypoints. Факт отдельной VM и независимой БД всё ещё не подтверждён; VM не создана этой работой.

**Почему:** ближайший реальный integration block — инфраструктурный допуск, а не ещё один лексический PHP-аудит. Нет оснований утверждать ни наличие готового staging, ни его полное отсутствие во внешней инфраструктуре.

## R4. Документация только feature — применено

**Было:** прежний START HERE предлагал синхронизировать общие docs main↔feature без кодовых изменений main.

**Стало:** автоматическая синхронизация main отложена. Текущие START HERE/checkpoint/decision/provisioning docs записаны только в feature. Main не меняется.

**Почему:** проверенный `.github/workflows/build-server-image.yml` на main `7c2e9f8e4501b92469bcdeea18f8340676ad52c9` имеет push main без paths-фильтра и публикацию latest для default branch. Даже doc-only push может обновить опубликованный образ. Это не утверждение, что production автоматически перезапускается; побочный эффект публикации уже достаточен, чтобы не трогать main без отдельного безопасного решения.

## R5. SMS dispatch/receipt совместимы с owned Bitrix bridge — исправлено и CI-tested

**Было:** четыре точных SELECT outbox по delivery_id, используемых claim/receipt/consumer/grant, отвергались generic bridge guard. Прямой outcome/reconciliation UPDATE не открывал свою транзакцию и нарушал bridge запрет write without scope. Direct PDO MariaDB tests этого стыка не покрывали; фактическая совместимость не была доказана.

**Стало:** `V5BitrixPdoCompatibilityBridge::SMS_OUTBOX_READ_SQL` разрешает ровно четыре существующих indexed templates. Произвольный SELECT из outbox запрещён; generic WHERE regex не расширен. `V5MySqlSmsDispatchClaims::commitProviderOutcome()` используется обоими ACK paths: повторный readiness guard после provider lookup, owned BEGIN, CAS UPDATE с delivery/key/previous status, проверка affected=0/1, COMMIT и лишь затем bool. Provider lookup остаётся вне owned DB transaction. Чужие native/bridge scopes не присваиваются/не откатываются. Paid send, budget, TTL и схема не меняются.

**Почему:** интеграция должна работать по одному существующему native connection, не обходить мост вторым PDO и не объявлять незакоммиченную запись подтверждённым результатом. Недоступность/неопределённый COMMIT — ошибка без ACK success, не причина повторно платить за SMS.

**Evidence:** code/test `8c395f0320f82a1c69bd06830dd229a087b358fb`; offline Actions `38740380225` SUCCESS, real ephemeral MariaDB `38740380099` SUCCESS: 43+117+45+63=268. Новые 63 assertions проверяют мост над настоящим MySQL/PDO с синтетическим Bitrix facade, независимый connection для committed-state наблюдения, rollback/потерянный COMMIT reply, write/affected-count faults, foreign native scope и exact SQL denials. Локальный baseline-guard/lint и 0/4→4/4 template reproduction PASS. Настоящий Bitrix SQL helper/ORM/native callbacks этим НЕ аттестованы.

## Дополнение реестра структур

| Путь/структура | Что добавлено | Что НЕ изменено |
|---|---|---|
| `src/V5MySqlSmsDispatchClaims.php` | R1 reconcileUnknownSent; R5 общий private commitProviderOutcome для обоих ACK paths | One-attempt claim, independent receipt, no-resend, budget/TTL/native policy |
| `src/V5BitrixPdoCompatibilityBridge.php` | R5 четыре точных SMS outbox SELECT templates | Native exclusive permit и запрет write/lock outside own scope; legacy guards других таблиц не перепроектированы |
| `tests/integration/V5EphemeralMySqlSmsReconciliationTest.php` | R1 45 assertions, два независимых worker процесса | Native/receipt остаются synthetic, реальных отправок нет |
| `tests/integration/V5EphemeralMySqlSmsBridgeTest.php` | R5 63 assertions после старых suites, real InnoDB под synthetic Bitrix facade | Не настоящий native Bitrix runtime; новых production diagnostics нет |
| `.github/workflows/v5-ephemeral-mariadb.yml` | R5 новый wrapper вызывает прежние suites ОДИН раз и затем bridge cases | Hosted disposable MariaDB, старые 43 session tests, никакого production access |
| `ev_v5_sms_outbox.status` | R1 UNKNOWN→SENT; R5 owned transaction для ACK | Колонки, индексы и DDL не менялись |
| `ev_v5_auth_challenges` / `ev_v5_sms_budget_windows` | Regression на неизменность при receipt | Runtime schema и lifecycle не изменены |
| Current docs | Актуальные START HERE/checkpoint с 268-test evidence, история 205/160 сохранена | Main не синхронизирован, исторические source-only документы сохранены |

Пути src/tests в таблице относительно `site-integration/samzaberu-v5/`. Не считать doc-only commit новым code-test run, synthetic facade настоящим Bitrix, receipt stub доказательством тарифов/доставки или увеличение coverage закрытием инфраструктурного допуска.
