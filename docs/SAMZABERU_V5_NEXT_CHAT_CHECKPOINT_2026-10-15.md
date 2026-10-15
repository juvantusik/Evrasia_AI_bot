# СамЗаберу V5 — checkpoint, 15 октября 2026

Читать вместе с [START HERE](SAMZABERU_V5_START_HERE_2026-10-15.md). Исторические NEXT от 13 октября не актуальны.

## АКТУАЛЬНО: SMS через Bitrix bridge и ACK только после COMMIT

Последний code/test commit: **`8c395f0320f82a1c69bd06830dd229a087b358fb`**. Offline Actions **38740380225**, job **116440231373**, SUCCESS (26 approved suites). Real ephemeral MariaDB Actions **38740380099**, job **116440233787**, SUCCESS. Из прочитанного лога: **43 sessions + 117 SMS + 45 reconciliation + 63 bridge = 268 assertions**, combined SMS 225; FINAL_STATUS=PASS, FINAL_RC=0.

Было два несовместимых стыка: существующий мост запрещал четыре SELECT по delivery_id; receipt UPDATE выполнялся без owned transaction и потому также запрещался мостом. Стало: четыре exact SMS read templates (hint/claim/receipt/consumption), generic query widening НЕ применялся; обычный и поздний provider outcome сохраняются единым private owned BEGIN→CAS UPDATE→COMMIT. Успех возвращается только после COMMIT, lookup квитанции остаётся до транзакции. Ошибочная чужая transaction не принимается и не откатывается.

`V5EphemeralMySqlSmsBridgeTest.php` включает предыдущие SMS/reconciliation suites ОДИН раз, затем 63 assertions с реальным PDO MySQL под **синтетическим** Bitrix-shaped API facade. Проверены claim→SENT→одноразовое consume, late receipt, точные SQL templates/denials, настоящий rollback, отдельное connection для проверки видимого committed state, потерянный ответ выполненного COMMIT (никакого ложного success), native-foreign-scope/permit denial. `BITRIX_FACADE=SYNTHETIC_ONLY`; это НЕ настоящий Bitrix SqlHelper, native transactionDepth/ORM hook proof или приёмка staging.

Compare `7f4de5a..8c395f0`: только bridge (+24), dispatch (+37/-13), новый test (+276), workflow (+6/-4). DDL/routers/V4/main/deployment не менялись. Локальный PHP lint и baseline blob guards PASS; 0/4→4/4 SMS template compatibility воспроизведена. Последующие HEAD с docs не означают нового code-test run.

**NEXT:** независимый Bitrix staging по готовому provisioning plan, затем существующий native preflight/отдельно разрешённые rollback/concurrency и настоящие providers. Не повторять закрытые production source-only probes. Provider SMS/RestIS/native adapters нельзя считать подключёнными.

## ПРЕДЫДУЩИЙ CHECKPOINT: receipt reconciliation (история)

- Branch: `feature/samzaberu-api-v5-auth-contract`, repo `juvantusik/Evrasia_AI_bot`.
- Runtime implementation commit: `04915fab87abf21eec1649f75b053c9bb91619dc`.
- Тогда последний code/test commit passed by BOTH workflows: **`dc2caed6af465aeb387490d20f1173005a80e3dd`**. Исправление после первого прогона — только сортировка бюджетных ключей fixture; runtime guard сохранён.
- Offline [38736808948](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38736808948), job `116428073291`: SUCCESS, 26 suites, 78 PHP lint, FINAL_STATUS=PASS, FINAL_RC=0.
- MariaDB [38736809040](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38736809040), job `116428073802`: SUCCESS. Sessions 43, прежний SMS suite 117, reconciliation 45, combined SMS 162; **всего 205**. TWO_PROCESS_SMS_RECEIPT_RACE=YES, RECEIPT_PROVIDER=SYNTHETIC_ONLY, ACTUAL_SMS_SENT=NO.
- Первый MariaDB run `38736335930` на `04915fab` FAIL после прежних 160 PASS: Noncanonical SMS window ordering в новой fixture. Исправленный run supersedes её; история FAIL сохранена.
- Compare `0ff80561db4654eeeeac159e762e21bda479e909..dc2caed`: 3 файла — store (+57/-2), новый integration test (+235), CI workflow (+8/-5). Нет V4, DDL или deployment.
- Main был и остаётся без наших изменений, baseline `7c2e9f8e4501b92469bcdeea18f8340676ad52c9`; docs только feature из-за main push→build/publish.

## Внутренний API и сохранённые ограничения

`V5MySqlSmsDispatchClaims::reconcileUnknownSent(string $deliveryId, string $providerIdempotencyKey): bool`: только UNKNOWN candidate; обязательный независимый receipt proof; финальная conditional UPDATE с точными key/state. Теперь UPDATE исполняется в собственной подтверждённой транзакции, true только после COMMIT. Повтор/конкурентный проигрыш/неподходящий status — false, НИКОГДА не разрешение повторной SMS.

Нет envelope на выходе, новых outbox rows, изменения/возврата бюджета, TTL, auth epoch или phone stamp. Late receipt не оживляет challenge: consumer отдельно перепроверяет его актуальность и native policy. В receipt adapter запрещены send/resend. Отсутствующий/отрицательный/ошибочный attestor не даёт SENT. Чужой scope сохраняется для владельца.

Старый `recordProviderOutcome(..., Sent)` по-прежнему только claimed→sent после независимого receipt. UNKNOWN→SENT возможен лишь через reconciliation. Claim не повторяет claimed/unknown; stale-claim reaper и универсального refund нет.

## Статусы допуска

| Статус | Значение |
|---|---|
| OFFLINE_TESTED | PASS |
| REAL_EPHEMERAL_MARIADB_PASS | YES, 268 assertions |
| REAL_BITRIX_STAGING_TESTED | NO |
| REAL_SMS_PROVIDER_TESTED | NO |
| REAL_RESTIS_PIN_TESTED | NO |
| PRODUCTION_DEPLOYED | NO |

HTTP SMS begin/resend по-прежнему выключены без native password-bound lifecycle. Public V5 не развёрнут; 503 — исторический baseline. Live HTTP/production SQL/SMS в этой итерации не выполнялись.
