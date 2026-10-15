# СамЗаберу V5 — checkpoint, 15 октября 2026

Читать вместе с [START HERE](SAMZABERU_V5_START_HERE_2026-10-15.md). Исторические NEXT от13октября не актуальны. Main не синхронизировать: push→build/publish.

## АКТУАЛЬНО: current password + native MFA row recheck под общей блокировкой

**Code/test commit `c3f92ba3197157e1cd19f9aa21df3d353862279d`.** Offline [38743961486](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38743961486), job116452589733 — SUCCESS (26 approved suites и lint). Ephemeral MariaDB [38743961127](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38743961127), job116452588251 — SUCCESS. Из реально прочитанного лога: **43+117+45+63+61=329 checks**, combined SMS/bridge/locked-password286, FINAL_STATUS=PASS, FINAL_RC=0.

Новый файл `site-integration/samzaberu-v5/src/V5BitrixLockedPasswordTotpProof.php` реализует `V5LockedPasswordTotpChallengeProofPort`. Проверяет точную identity переданного PDO относительно configured connection, наличие caller transaction/MySQL/exception mode/InnoDB, берёт b_user lock, сверяет переданный AUTH_EPOCH, требует coverage===true. Внутренний `V5LockedBitrixAuthRows` выполняет unique EMAIL/LOGIN LIMIT2 FOR UPDATE и fresh b_sec_user read с блокировкой. `V5SiteAccountReader` сохраняет существующие active/blocked/expiry/loginAttempts/identity правила; `V5NativeTotpStatus` сохраняет консервативную интерпретацию MFA, fallback port в TOTP-only adapter всегда null. Текущий пароль проверяет существующий comparator port; native конфигурация должна использовать проверенный V5NativeBitrixPasswordComparator, не CI comparator.

Stamp создаётся существующим `V5SecurityEventVersions::forLockedPasswordProof()` и включает user/current password hash/shared AUTH_EPOCH. До возврата epoch перепроверяется. Adapter не завершает caller transaction, не пишет users/MFA, не создаёт PHP login/cookie, не выдаёт JWT, не отправляет SMS и не проверяет authenticator code. Существующий TOTP issuer создаёт ticket только после своего COMMIT. Это **реализованный read/proof adapter**, НЕ installation/acceptance native hooks или TOTP replay.

В `V5BitrixPdoCompatibilityBridge` добавлены16строк: четыре exact SELECT (существующие unlocked EMAIL/LOGIN reader и locked variants). Generic predicates/произвольные hash SELECT не добавлены. Новый test `V5EphemeralMySqlLockedPasswordTotpTest.php`215строк включает прежние SMS/bridge suites ОДИН раз; затем61assertion. Новая positive coverage/comparator/normalizer/login-policy и Bitrix facade синтетические. Native tables в CI сокращённые; fixture ALTER/CREATE выполняется только после env/точногоschema guards. Ничего из тестового DDL не предназначено для native Bitrix DB.

### Проверено новым тестом

Текущий password proof→существующий issuer→committed TOTP ticket с общим epoch; уникальность identity (включая неактивный дубликат); другой user с тем же паролем; active/block/expiry/attempts; configured inactive/HOTP/невалидный secret/deactivate/отсутствие TOTP; unknown/deny policy и hook coverage; неправильный epoch/connection/transaction; отсутствие настоящего native comparator без fallback. После реального SQL reset старый пароль не даёт новый ticket, новый даёт. Пока proof держит user lock, вторая независимая MySQL connection не может завершить password UPDATE (ожидаемый lock timeout1205); после окончания caller scope reset+epoch фиксируются, старый пароль снова отклоняется. SMS outbox count не увеличивается.

Compare `9c9ca7c..c3f92ba`: **ровно4файла**: proof+169, bridge+16, test+215, workflow+6/-4. Runtime schema proposals/V4/routes/deployment/main не менялись. Локальный PHP lint двух новых файлов PASS и их blob SHA совпали; полный regression/SQL выполнялся только GitHub CI. Doc-only HEAD после этого checkpoint — не новый code-test run.

## Инфраструктурный NEXT

[Issue #76 — паспорт отдельного Bitrix+MySQL staging](https://github.com/juvantusik/Evrasia_AI_bot/issues/76) создана как явный blocker. Существующий `SAMZABERU_V5_STAGING_PROVISIONING_PLAN_2026-10-15.md` готов; подтверждённого test host/DB/VM identity нет. Production evrasia и eur-bot-01 исключены. Не интерпретировать новую issue как разрешение provisioning/оплаты/DDL/production.

После Stage A допуска — существующий native depth preflight; Stage C отдельно согласованные synthetic DDL/rollback/concurrency. Затем проверить native comparator/normalizer/policy и новый adapter на настоящем Bitrix, фактические indexes/collation/duplicate handling/lock ordering, native TOTP replay и10securitywriterpaths/phone reconfirm events. SMS lifecycle/provider receipts и RestIS PIN остаются обязательными следующими блоками. Нельзя заменить native proof синтетическими callback=true.

## Предыдущие подтверждённые checkpoints — история

| Code/test commit | Доказательство |
|---|---|
| `8c395f0320f82a1c69bd06830dd229a087b358fb` | Runs38740380225/38740380099 SUCCESS,268checks: четыре exact outbox reads, обычный/поздний receipt ACK только после owned BEGIN→CAS→COMMIT;63 bridge tests с independent observer/commit faults/foreign scope. |
| `dc2caed6af465aeb387490d20f1173005a80e3dd` | Runs38736808948/38736809040 SUCCESS,205checks: UNKNOWN→SENT reconciliation без send/resend/budget/TTL изменений;45новыхchecks и two-process CAS. |
| `4a6ea09154b7bc6e8c34b60d2b37c003d85f0369` | Runs38724339404/38724339420 SUCCESS,160checks: independent SENT receipt gate. |

Первый receipt run38736335930 на04915fab был FAIL (неотсортированные fixture budget keys), dc2caed исправил только fixture, не guard. Полные прежние редакции checkpoint сохранены в Git history9c9ca7c/7f4de5a. Исторические source-only handoff/registry документы не удалены, их NEXT не повторять.

## Сохранённые ограничения / статусы

OFFLINE_TESTED=PASS; REAL_EPHEMERAL_MARIADB_PASS=YES(329); REAL_BITRIX_STAGING_TESTED=NO; NATIVE_BITRIX_COMPARATOR_TESTED=NO(в новом integration CI); NATIVE_HOOK_COVERAGE=SYNTHETIC_ONLY; REAL_SMS_PROVIDER_TESTED=NO; REAL_RESTIS_PIN_TESTED=NO; PRODUCTION_DEPLOYED=NO.

HTTP SMS begin/resend выключены без native password-bound lifecycle. Public V5 не активирован, HTTP503 — исторический baseline. Late receipt не оживляет challenge, не разрешает повторную платную SMS и не меняет quota; consumer отдельно перепроверяет native state. Main остаётся7c2e9f8e4501b92469bcdeea18f8340676ad52c9 по последнему live read. Production SQL/site bootstrap/реальные SMS/закрытые source-only audits не выполнялись.
