# V5 — дополнение реестра структур и решений, 15 октября 2026

Актуальное дополнение к `STRUCTURE_REGISTRY.md` для инкрементов после feature `0ff80561db4654eeeeac159e762e21bda479e909`. Общий исторический реестр не переписывается; контекст других частей Evrasia сохраняется. Читать через `SAMZABERU_V5_START_HERE_2026-10-15.md`. Самое новое решение — R6; инфраструктурный blocker теперь issue76.

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

**Дополнение после c3f92ba:** создана [issue76](https://github.com/juvantusik/Evrasia_AI_bot/issues/76) для паспорта Stage A и подтверждения/согласования выделения независимой VM. Имеющийся provisioning plan переиспользуется. Ни issue, ни зелёная CI не разрешают создание платной инфраструктуры, siteDDL/production/SMS; owner/host/identity/ACL требуют реального подтверждения.

## R4. Документация только feature — применено

**Было:** прежний START HERE предлагал синхронизировать общие docs main↔feature без кодовых изменений main.

**Стало:** автоматическая синхронизация main отложена. Текущие START HERE/checkpoint/decision/provisioning docs записаны только в feature. Main не меняется.

**Почему:** проверенный `.github/workflows/build-server-image.yml` на main `7c2e9f8e4501b92469bcdeea18f8340676ad52c9` имеет push main без paths-фильтра и публикацию latest для default branch. Даже doc-only push может обновить опубликованный образ. Это не утверждение, что production автоматически перезапускается; побочный эффект публикации уже достаточен, чтобы не трогать main без отдельного безопасного решения.

## R5. SMS dispatch/receipt совместимы с owned Bitrix bridge — исправлено и CI-tested

**Было:** четыре точных SELECT outbox по delivery_id, используемых claim/receipt/consumer/grant, отвергались generic bridge guard. Прямой outcome/reconciliation UPDATE не открывал свою транзакцию и нарушал bridge запрет write without scope. Direct PDO MariaDB tests этого стыка не покрывали; фактическая совместимость не была доказана.

**Стало:** `V5BitrixPdoCompatibilityBridge::SMS_OUTBOX_READ_SQL` разрешает ровно четыре существующих indexed templates. Произвольный SELECT из outbox запрещён; generic WHERE regex не расширен. `V5MySqlSmsDispatchClaims::commitProviderOutcome()` используется обоими ACK paths: повторный readiness guard после provider lookup, owned BEGIN, CAS UPDATE с delivery/key/previous status, проверка affected=0/1, COMMIT и лишь затем bool. Provider lookup остаётся вне owned DB transaction. Чужие native/bridge scopes не присваиваются/не откатываются. Paid send, budget, TTL и схема не меняются.

**Почему:** интеграция должна работать по одному существующему native connection, не обходить мост вторым PDO и не объявлять незакоммиченную запись подтверждённым результатом. Недоступность/неопределённый COMMIT — ошибка без ACK success, не причина повторно платить за SMS.

**Evidence:** code/test `8c395f0320f82a1c69bd06830dd229a087b358fb`; offline Actions `38740380225` SUCCESS, real ephemeral MariaDB `38740380099` SUCCESS: 43+117+45+63=268. Новые63assertions проверяют мост над настоящим MySQL/PDO с синтетическим Bitrix facade, независимый connection для committed-state наблюдения, rollback/потерянный COMMIT reply, write/affected-count faults, foreign native scope и exact SQL denials. Настоящий Bitrix SQL helper/ORM/native callbacks этим НЕ аттестованы.

## R6. Готовый locked password/TOTP begin proof — реализован, native acceptance впереди

**Было:** `V5MySqlPasswordBoundTotpIssuer` требовал свежий password/MFA callback под user lock, но готового адаптера этого порта к существующим account/MFA/epoch компонентам не было. Наличие обязательного fail-closed порта НЕ было разрешением использовать stale first-factor snapshot. Existing PDO identity SELECT по EMAIL/LOGIN не проходили generic bridge guard.

**Стало:** `V5BitrixLockedPasswordTotpProof` реализует тот же обязательный порт. Связывается с конкретным PDO/bridge, проверяет InnoDB/transaction, сам берёт b_user lock и проверяет переданный epoch. `V5LockedBitrixAuthRows` читает unique identity и b_sec_user под блокировками; `V5SiteAccountReader`, `V5NativeTotpStatus`, существующий comparator/normalizer/login policy и `V5SecurityEventVersions` переиспользованы. Штамп остаётся тем же user/password-hash/AUTH_EPOCH HMAC. Мост получил четыре exact identity templates; generic EMAIL/LOGIN predicates не разрешены. Нет transaction completion, cookies, JWT, SMS или user/MFA writes в самом proof.

**Почему:** правильный пароль первого HTTP шага не заменяет проверку текущего пароля после возможного reset; native state нельзя выдавать из кешированного snapshot. Reuse общего epoch предотвращает несовместимые stamp formats. Неактивные дубликаты не должны исчезать из unique lookup. Неизвестные native policy/coverage/comparator/MFA отказывают, а не включают SMS.

**Evidence:** code/test `c3f92ba3197157e1cd19f9aa21df3d353862279d`; offline38743961486/job116452589733 SUCCESS; ephemeral38743961127/job116452588251 SUCCESS, прочитанный лог43+117+45+63+61=329. Новый test проводит existing issuer→реальный locked-row adapter→общий epoch→committed TOTP ticket, отрицательные account/identity/MFA/policy/epoch проверки и SQL password-reset lock exclusion вторым соединением. Local PHP lint двух новых файлов PASS; blob SHA совпали. Все прежние suites запускаются по одному разу.

**Не доказано:** реальный Bitrix comparator/PhoneConfirm normalizer/логин policy/SqlHelper/ORM и10path native writer coverage, TOTP-code replay protection, real staging. Положительные внешние policy/comparator/coverage и Bitrix facade в CI синтетические; native comparator absence отдельно проверена как отказ без fallback. Новый adapter не реализует native mutations/phone verification и не разрешает production. В native staging нужны отдельно доказанные indexes/collation/duplicate behavior и общий lock order всех writers.

## Дополнение реестра структур

Пути src/tests относительно `site-integration/samzaberu-v5/`.

| Путь/структура | Что добавлено | Что НЕ изменено |
|---|---|---|
| `src/V5MySqlSmsDispatchClaims.php` | R1 reconciliation; R5 общий owned commitProviderOutcome | В R6 не менялся; one-attempt/no-resend/receipt/budget/TTL сохранены |
| `src/V5BitrixPdoCompatibilityBridge.php` | R5 четыре exact SMS reads; R6 четыре exact identity reads | Transaction permit, write/lock outside scope denial; generic predicates не расширены |
| `src/V5BitrixLockedPasswordTotpProof.php` | R6 proof + внутренний locked identity/MFA row source | Existing policy/MFA/comparator/epoch semantics, issuer interface и HTTP API |
| `src/V5SecurityEventVersions.php` | Переиспользуется R6 forLockedPasswordProof | HMAC domain, AUTH_EPOCH/PHONE_EVENT_VERSION authority и runtime файл не менялись |
| `tests/integration/V5EphemeralMySqlSmsReconciliationTest.php` | R1 45checks и two-process CAS | Runtime/native provider proof не заменяет |
| `tests/integration/V5EphemeralMySqlSmsBridgeTest.php` | R5 63checks, real SQL под synthetic Bitrix facade | Native Bitrix acceptance не заменяет |
| `tests/integration/V5EphemeralMySqlLockedPasswordTotpTest.php` | R6 61checks, guarded расширение CI fixtures и real SQL lock exclusion | НЕ native DB migration; native APIs/coverage положительныхfixtures synthetic |
| `.github/workflows/v5-ephemeral-mariadb.yml` | Новый wrapper включает старые SMS suites ОДИН раз и затем locked proof | Hosted disposable DB; прежние43sessionchecks; production access отсутствует |
| PROPOSED SQL / production DB | Без изменений R6 | ALTER/CREATE есть исключительно внутри guarded synthetic CI fixture |
| Current docs / issue76 | 329-check checkpoint + явный Stage A blocker | Main не синхронизирован; старые исходные handoff/registry сохранены |

Не считать doc-only commit новым code-test run, synthetic facade настоящим Bitrix, receipt stub доказательством доставки, а рост CI coverage закрытием инфраструктурного допуска.
