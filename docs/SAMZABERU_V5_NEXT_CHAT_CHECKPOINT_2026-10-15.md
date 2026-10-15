# СамЗаберу V5 — checkpoint после receipt reconciliation, 15 октября 2026

Текущая точка продолжения. Читать вместе с [START HERE](SAMZABERU_V5_START_HERE_2026-10-15.md). Исторический `SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md` сохранён; его NEXT не переопределяет этот checkpoint.

## Подтверждённый результат

- Branch: `feature/samzaberu-api-v5-auth-contract`, repo `juvantusik/Evrasia_AI_bot`.
- Runtime implementation commit: `04915fab87abf21eec1649f75b053c9bb91619dc`.
- Latest code/test commit passed by BOTH workflows: **`dc2caed6af465aeb387490d20f1173005a80e3dd`**. Исправление после первого прогона — только сортировка бюджетных ключей fixture; runtime guard сохранён.
- Offline [38736808948](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38736808948), job `116428073291`: SUCCESS, 26 suites, 78 PHP lint, FINAL_STATUS=PASS, FINAL_RC=0.
- MariaDB [38736809040](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38736809040), job `116428073802`: SUCCESS. Из реально прочитанного лога: sessions 43, прежний SMS suite 117, reconciliation 45, combined SMS 162; **всего 205**. TWO_PROCESS_SMS_RECEIPT_RACE=YES, RECEIPT_PROVIDER=SYNTHETIC_ONLY, ACTUAL_SMS_SENT=NO.
- Первый MariaDB run `38736335930` на `04915fab` FAIL после прежних 160 PASS: Noncanonical SMS window ordering в новой fixture. Не стирать историю неуспеха; исправленный run supersedes её.
- Compare `0ff80561db4654eeeeac159e762e21bda479e909..dc2caed` подтвердил ровно 3 изменённых файла: store (+57/-2), новый integration test (+235), CI workflow (+8/-5). Нет изменений V4, DDL или deployment.
- Main остаётся `7c2e9f8e4501b92469bcdeea18f8340676ad52c9`; docs этой итерации — только feature. После этого checkpoint возможен doc-only HEAD: проверить live ref и compare, не требовать совпадения HEAD с code/test commit.

## Новый внутренний API

`site-integration/samzaberu-v5/src/V5MySqlSmsDispatchClaims.php`:

`reconcileUnknownSent(string $deliveryId, string $providerIdempotencyKey): bool`

Проверяет идентификаторы и готовность InnoDB connection; читает только UNKNOWN candidate; вызывает обязательный `V5SmsSentReceiptAttestationPort::independentlyConfirmedSent()` без своей открытой транзакции; затем выполняет conditional UPDATE с прежним статусом UNKNOWN и точными delivery/key. Возвращает true только при одном изменённом ряду. Конкурентный проигравший, повтор, несуществующая запись, queued/claimed/sent/failed/cancelled — false; это **никогда не разрешение отправить SMS снова**.

Без receipt provider, при отказе/ошибке проверки статус не меняется. Если provider неожиданно открыл чужую DB transaction, store отказывает, не откатывая чужую транзакцию. Никаких plaintext SMS/envelope на выходе, новых outbox rows, изменений бюджета, TTL, auth epoch или phone verification stamp. Late receipt — учёт подтверждённого результата, а не доказательство текущего права входа: стандартный consumer повторно проверяет challenge/native policy. Запрещён send/resend внутри receipt adapter.

Существующий `recordProviderOutcome(..., Sent)` НЕ изменён по смыслу: только claimed→sent после independent receipt. UNKNOWN нельзя превратить в SENT через старый метод. `claimQueuedOnce()` не делает retry для claimed/unknown. Нет stale-claim reaper и нет универсального автоматического refund.

Тест `V5EphemeralMySqlSmsReconciliationTest.php` запускает прежние 117 tests ОДИН раз, затем 45 новых. Гонка реализована двумя отдельными PHP OS processes с новыми PDO connections и barrier внутри receipt lookup, после обоих SELECT UNKNOWN. Это доказанная InnoDB CAS, но не настоящий provider/API/Bitrix.

## Статусы допуска

| Статус | Значение |
|---|---|
| OFFLINE_TESTED | PASS |
| REAL_EPHEMERAL_MARIADB_PASS | YES, 205 assertions |
| REAL_BITRIX_STAGING_TESTED | NO |
| REAL_SMS_PROVIDER_TESTED | NO |
| REAL_RESTIS_PIN_TESTED | NO |
| PRODUCTION_DEPLOYED | NO |

SMS begin/resend через HTTP по-прежнему выключены без проверенного native password-bound lifecycle. Public V5 не развёрнут; 503 — исторический baseline, live HTTP в этой итерации не тестировался.

## Точная точка продолжения

[Staging provisioning plan](SAMZABERU_V5_STAGING_PROVISIONING_PLAN_2026-10-15.md) готов. Не подтверждены независимые VM/DB, гипервизор или доступный test host; существование стенда остаётся UNATTESTED, а не ABSENT. Нужен инфраструктурный допуск/выделение или подтверждение существующей отдельной VM. Не просить старые production логи и не повторять аудит CUser/PhoneAuth.

После подтверждения изоляции — существующий native depth preflight; после отдельного разрешения — probe-table rollback/ORM/connection tests; затем native password/TOTP/phone/security events и 10-path atomicity. Настоящие SMS receipt/тарифы/idempotency/late lookup и RestIS подключать только по действующему проверенному контракту. Реальные SMS и платные вызовы отдельно согласовываются.

Реестр текущих структур и Было→Стало→Почему: [integration decisions](SAMZABERU_V5_INTEGRATION_DECISIONS_2026-10-15.md). Старые registry/checkpoint/README сохраняют исторический контекст; последнее состояние и NEXT брать из START HERE и этого checkpoint.
