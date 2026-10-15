# V5 — дополнение реестра структур и решений, 15 октября 2026

Актуальное дополнение к `STRUCTURE_REGISTRY.md` для инкремента после feature `0ff80561db4654eeeeac159e762e21bda479e909`. Общий исторический реестр не переписывается; контекст других частей Evrasia сохраняется. Читать через `SAMZABERU_V5_START_HERE_2026-10-15.md`.

## R1. Поздняя SMS-квитанция без второй оплаты — реализовано и CI-tested

**Было:** единственный claim, independent SENT receipt для claimed→sent, UNKNOWN запрещён к повторной отправке; явного пути учесть позднее доказанный SENT для UNKNOWN не было.

**Стало:** внутренний метод `V5MySqlSmsDispatchClaims::reconcileUnknownSent()` переводит только UNKNOWN→SENT через независимый receipt proof и conditional UPDATE. Нет вызова send/resend, возврата envelope, изменения challenge/TTL/epoch/phone, освобождения/повторного расходования quota. Старые методы и публичный route map не меняются.

**Почему:** неопределённость платной отправки должна разрешаться проверкой уже произошедшей операции, а не вторым платным запросом. Поздний SENT не оживляет expired/cancelled challenge и не заменяет свежую проверку native состояния.

**Evidence:** code `04915fab87abf21eec1649f75b053c9bb91619dc`; исправленная fixture и обе успешные CI на `dc2caed6af465aeb387490d20f1173005a80e3dd`, runs `38736808948` / `38736809040`. 45 новых MariaDB assertions; независимый provider в тесте СИНТЕТИЧЕСКИЙ. Реальная provider integration НЕ закрыта.

## R2. Обнаруженная ошибка новой fixture — исправлено без ослабления guard

**Было:** новая fixture строила 10 SHA256 budget keys в порядке генерации. Первая CI корректно остановилась на Noncanonical SMS window ordering.

**Стало:** fixture сортирует окна по bucketKey до создания reservation. `V5SmsDispatchBudget` и canonical lock-order check не менялись. Прежние 160 tests сохранены; итог нового успешного прогона 205.

**Почему:** test inputs обязаны соблюдать существующий контракт; production-кандидат нельзя ослаблять ради зелёного теста.

## R3. Переход к native integration — план подготовлен, инфраструктура UNATTESTED

**Было:** документы фиксировали blocker отдельного Bitrix staging; вместо него нельзя использовать production или ephemeral synthetic CI.

**Стало:** составлен `SAMZABERU_V5_STAGING_PROVISIONING_PLAN_2026-10-15.md` с конкретными фазами, проектными именами, evidence для изоляции, остановкой перед bootstrap/DDL, rollback и точными существующими acceptance entrypoints. Факт отдельной VM и независимой БД всё ещё не подтверждён; VM не создана этой работой.

**Почему:** ближайший реальный integration block — инфраструктурный допуск, а не ещё один лексический PHP-аудит. Нет оснований утверждать ни наличие готового staging, ни его полное отсутствие во внешней инфраструктуре.

## R4. Документация только feature — применено

**Было:** прежний START HERE предлагал синхронизировать общие docs main↔feature без кодовых изменений main.

**Стало:** автоматическая синхронизация main отложена. Текущие START HERE/checkpoint/decision/provisioning docs записаны только в feature. Main не меняется.

**Почему:** проверенный `.github/workflows/build-server-image.yml` на main `7c2e9f8e4501b92469bcdeea18f8340676ad52c9` имеет push main без paths-фильтра и публикацию latest для default branch. Даже doc-only push может обновить опубликованный образ. Это не утверждение, что production автоматически перезапускается; побочный эффект публикации уже достаточен, чтобы не трогать main без отдельного безопасного решения.

## Дополнение реестра структур

| Путь/структура | Что добавлено | Что НЕ изменено |
|---|---|---|
| `site-integration/samzaberu-v5/src/V5MySqlSmsDispatchClaims.php` | `reconcileUnknownSent()`, уточнение no-send обязанности receipt port | Старые paid-claim/outcome semantics и native policy |
| `site-integration/samzaberu-v5/tests/integration/V5EphemeralMySqlSmsReconciliationTest.php` | 45 новых assertions после включения прежних 117, два независимых worker процесса | Native/receipt остаются synthetic, реальных отправок нет |
| `.github/workflows/v5-ephemeral-mariadb.yml` | Path нового теста, проверка proc_open, запуск расширенного suite | Hosted disposable MariaDB, запрет production/Bitrix, старые session tests |
| `ev_v5_sms_outbox.status` | Использование уже существующих значений unknown/sent в CAS | Колонки, индексы и DDL не менялись |
| `ev_v5_auth_challenges` / `ev_v5_sms_budget_windows` | Новые tests проверяют неизменность при receipt reconciliation | Runtime schema и lifecycle не изменены |
| `docs/SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md` | Новый текущий checkpoint с 205-test evidence | Исторический checkpoint от 13 октября сохранён |

Не считать изменение docs новым code-test run. Не считать синтетический receipt доказательством тарифов, доставки, оплаты или настоящей нативной проверки Bitrix.
