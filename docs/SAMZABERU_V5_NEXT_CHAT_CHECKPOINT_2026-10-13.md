# Evrasia / СамЗаберу API V5 — актуальная точка продолжения 2026-10-13

## Последняя подтверждённая точка: PHONE flow 5/5 → update receiver

Оператор прислал stdout `v5_phone_confirmation_transition_flow_readonly.sh` с production `evrasia`. **5/5 PASS**, SHA pinned `Controllers/V4/Auth.php` и `lib/PhoneAuth.php` совпали; SQL/Bitrix bootstrap/SMS/production writes = NO. Новый подтверждённый source факт: `Auth::confirmation()->update` **строка 137**, `Auth::confirm_request()->update` **851**, `Auth::confirm()->update` **1026**. Последний имеет reference к `CONFIRMED` в строке **1012**, `confirm_request` — **845/873/891/896**. Другие источники ранее показали `UserPhoneAuthTable::getList` 869, `::add` 876, `::getRowById` 880 и нативную `UserPhoneAuthTable.php` SHA `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991`. Этого НЕ достаточно, чтобы утверждать, какой update меняет поле `CONFIRMED` или используется при reconfirm того же номера.

**Следующая команда:** подготовлен в **feature-only** `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_update_receiver_readonly.sh`; точный static SHA для `Auth.php=72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`; script prints only whitelisted metadata and receiver PHP variable identifiers around известных update lines 137/851/1026. Принимаем stdout только после запуска оператором на `evrasia` под root. При `FAIL` сначала разобрать причины, а не запускать повторный ORM/MFA скан. Скрипт еще **НЕ запускался на production**.

**CI подтверждён:** GitHub Actions [38396960091](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38396960091) `completed/success` по commit `a34cda2a295a5ab31fabd72f6e82e5ba9a4d0a2e`; **7 Bash lints, 48 PHP lint, 22 offline suite, guarded wrong-host denial**. CI — не real Bitrix source-flow execution/transaction proof.

Завершённые шаги **не повторять**: MFA/recovery source 9/9, 6/6, 7/7; phone inventory 5/5, phone call-order 6/6, phone flow 5/5. Документ: `docs/SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md` (feature). Истина release-гейта неизменна: `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, same E.164 reconfirm bump не доказан, native 10-path coverage и изолированный staging Bitrix/MySQL **не подтверждены**. V5 HTTP 503 по последнему документированному baseline, V4/production/SQL/SMS не изменялись.

---

## Новейшая точка: phone call order 6/6 SOURCE VERIFIED → косвенные V4 writes

**Фактически последний операторский результат:** `v5_phone_confirmation_order_readonly.sh`, запущен на `evrasia`, `FINAL_STATUS=PASS`, `PASS_COUNT=6`, `FAIL_COUNT=0`, SHA всех 3 PHP source совпали; режим строго read-only, Bitrix не загружался, MySQL/SMS/production writes не было.

Метаданные `Auth.php` (реальный production source SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`): `signup` uses phone-confirm helper (311/334/339), `UserPhoneAuthTable::add` (372). `confirm_request` has `CONFIRMED` refs (845/873/891/896), native `UserPhoneAuthTable::getList` (869), `::add` (876), `::getRowById` (880). `confirm` starts at 954 and references `CONFIRMED` at 1012, **no direct static UserPhoneAuthTable writer in the narrow classifier**. Native `UserPhoneAuthTable.php` SHA `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991` callbacks `onBeforeAdd/onBeforeUpdate` (96/101) delegate to `modifyFields` (98/103) and normalize `PHONE_NUMBER` (112/115). `V4_PHONE_TABLE_STATIC_CALLS=4`, `V4_CONFIRMED_REFERENCES=5`, no inline literal Y assignment candidate; absence of matching regex **не доказывает** отсутствие dynamic/multiline `CONFIRMED` write.

**Новая следующая команда только для read-only static inspection:** `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_confirmation_transition_flow_readonly.sh` в feature. Она ещё **не запускалась на production**. Строгие source SHA V4, вывод только line/method/control-flow tokens и безопасных `->save/->update/->set` method identifiers (никаких original source lines, аргументов, телефонов, SMS). От пользователя следующим ожидается её stdout; разбирать именно его, не повторяя MFA 9/6/7 и phone inventory 5/6. CI [run 38393921083](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38393921083) **SUCCESS**: 6 bash lint, 48 PHP lint, 22 offline suite PASS, wrong-host denial; **не positive Bitrix runtime/DB**.

**До реального staging:** `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, real repeated same-phone confirmation atomicity and `AUTH_EPOCH` shared transaction **NOT PROVEN**; `V5_HTTP=503` по последней документации, V4/production не менялись. [Подробный phone evidence doc](SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md) обновлён в feature, общий registry синхронизирован в обеих ветках. Физически изолированный Bitrix/MySQL staging остаётся отдельным обязательным барьером.


## Завершённый предыдущий этап: native PHONE 5/5 (заменён результатом 6/6 выше)

После завершения трёх MFA/recovery source-only аудитов (9/9, 6/6, 7/7) начата **параллельная** ещё не закрытая ветка V5 `PHONE_EVENT_VERSION` / подтверждение телефона. Оператор с `evrasia` предоставил stdout `v5_phone_event_writer_inventory_readonly.sh`: **5/5 PASS**, 0 FAIL, pinned V4 source SHA совпали, без Bitrix bootstrap/DB/доступа к пользовательским телефонам/секретам/SMS/production writes. Подтверждён native `bitrix/modules/main/lib/UserPhoneAuthTable.php` SHA `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991`; `onBeforeAdd` (96), `onBeforeUpdate` (101), `modifyFields` (106), `normalizePhoneNumber` (123), `CONFIRMED` field reference (64). В действующем `Auth.php` найдены `confirm_request` (779), `confirm` (954), `signup` (263) и table/CONFIRMED references.

**Текущий ближайший ввод пользователя ожидается из НОВОГО** `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_confirmation_order_readonly.sh` (GitHub-only, ещё не run на production). Он печатает строго source metadata с номерами строк для вызовов `UserPhoneAuthTable` / `CONFIRMED` без source/PII/config/DB/SMS; SHA трех файлов из verified inventory pin-guarded. Из полученного вывода установить порядок native phone writes, определить точно где обновляется поле `CONFIRMED`, включая повторное подтверждение того же E.164. Следующий узкий source-only аудит НЕ означает разрешение V5 или доказанное событие. Старый инвентаризационный скрипт 5/5 не повторять; MFA audits не повторять.

GitHub Actions [run 38392077308](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38392077308) `completed/success`: Bash -n пяти аудит-скриптов, 48 PHP lint, 22 offline suites; фейковая prod-среда отсекается до чтения исходников. Обновлён [doc phone native writers](SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md) (в feature) и одинаковый registry в обеих ветках. В production и V4 изменений нет. Изолированный Bitrix/MySQL staging всё ещё не подтверждён и остаётся независимым runtime-blocker. `PHONE_EVENT_VERSION` writer/10-path native coverage ещё НЕ установлены, `NATIVE_PHONE_EVENT_ATOMICITY_PROVEN=NO`, `V5_HTTP=503` по последнему документированному состоянию.


> **ACTUAL / SUPERSEDES 2026-10-12 V5 HANDOFF.** Этот checkpoint описывает рабочую ветку GitHub и проверенный read-only вывод сервера, а не production-релиз. Все старые ссылки на *не реализован* native transaction depth port описывают 2026-10-12 и уже устарели.

## 1. Состояние проекта

- Repo: `juvantusik/Evrasia_AI_bot`; код V5 — исключительно `feature/samzaberu-api-v5-auth-contract`. `main` — документация/история production; не мержить кандидата ради проверки.
- На production сайтах и боте изменений в рамках текущей ветки **не было**. V4 / Trusted Device / TOTP работают по последним отдельно подтверждённым production-фактам. V5 router остаётся с HTTP 503 по **последнему документированному** состоянию; новые HTTP-проверки в этом шаге не выполнялись.
- Реальной Bitrix staging VM/отдельной MySQL-БД с независимо доказанной изоляцией **не зарегистрировано**. Реальный Bitrix ORM↔native rollback, staging MFA/epoch concurrency, SMS sender и 10-path atomicity **не проводились**. В production запрещены любые изменения БД, внедрение хуков, SMS и V5-активация.
- GitHub Actions `.github/workflows/v5-offline-safety.yml`: последний проверенный run [38378187055](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38378187055), `success`; PHP 8.4.26; 48 PHP files lint PASS; все 22 offline suites PASS; три shell source diagnostics `bash -n` PASS; staging preflight и rollback entrypoints в негативном сценарии отказывают без bootstrap. **Это fake/offline проверки, не production/staging E2E.**
- Source-backed staging-only `V5VerifiedBitrixNativeTransactionDepth.php` (Reflection на `protected int $transactionLevel`, SHA-pinned Bitrix classes) уже реализован в feature, прошёл 18 синтетических checks; `V5StagingNativeTransactionPermit` 62/62 fake tests; `V5BitrixPdoCompatibilityBridge` 80/80 fake tests. **Нет реального production permit** и независимого native 10-path security coverage.

## 2. Последний ФАКТИЧЕСКИЙ вывод оператора

На `evrasia` оператор выполнил `site-integration/samzaberu-v5/tests/diagnostics/v5_native_mfa_writer_order_readonly.sh`; результат `MODE=READ_ONLY`, `PASS_COUNT=9`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. Все **шесть** pinned SHA исходников Bitrix совпали; `DATABASE_CONNECTION=NO`, `BITRIX_BOOTSTRAP=NO`, `PRODUCTION_APPLICATION_WRITE=NO`.

Подтверждённый native source order:
- `Otp::save()`: `RecoveryCodesTable::clearByUser()` при regenerated (стр. 370) **до** `UserTable::add` (374), затем cache invalidation. `Otp::delete()`: `UserTable::delete` (390).
- Background `OtpEvents::onRecheckDeactivate()`: `UserTable::update` (30/34), отдельный writer; `CSecurityUser::update()` может вызвать OTP deactivate/delete/regenerate/save; `onAfterUserUpdate` — только MFA cache invalidation.
- Native `DataManager` add: before-event 906, on-event 939, `strategy->add` 956, after-event 1015. Update: before 1277/1306, actual SQL 1337, after 1362. Delete: before 1665/1674, actual SQL 1691, after 1702.
- `Merge` strategy invokes `prepareMerge` and `Helper::executeAndGetInsertedId` (89-100). Это **только статический порядок**, не доказательство общего BEGIN/COMMIT/ROLLBACK. Нельзя доверять `OnAfterUserUpdate` как атомарному MFA+AUTH_EPOCH или ставить 10-path coverage positive.
- Подробности в `docs/SAMZABERU_V5_NATIVE_MUTATION_COVERAGE_2026-10-12.md` и `docs/STRUCTURE_REGISTRY.md`, обновлены по фактическому выводу.

## 3. Второй ФАКТИЧЕСКИЙ production source-only аудит: recovery / merge — 6/6 PASS

На `evrasia` оператор запустил уже подготовленный `v5_native_recovery_merge_readonly.sh`, результат `PASS_COUNT=6`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `MODE=READ_ONLY`, `BITRIX_BOOTSTRAP=NO`, `DATABASE_CONNECTION=NO`, `PRODUCTION_APPLICATION_WRITE=NO`. Это **не runtime/DB proof**.

Факты: native `Bitrix\Security\Mfa\UserTable` (таблица `b_sec_user`) использует `MergeByDefaultTrait` SHA `30153b42a1a652b44ca70f2bb14335d08f970d0ebef8ca85e7f349f8ad29c198`. Он делегирует выбор стратегии `BaseMergeTrait::getCachedMergeStrategy()`, тело этого trait **ещё не изучено**.

`RecoveryCodesTable.php` SHA `a5ff49a123bd7f3864acfc1fee9010965a8d9d3775d774442de66d5667d5e357`: `clearByUser()` (84–101) выбирает ID recovery-кодов и удаляет каждый вызовом `static::delete($code['ID'])` в цикле, возвращая `true` без проверки returned delete result. `regenerateCodes()` (фрагмент 111–129) очищает старые и многократно добавляет новые отдельными `static::add()`; окончание метода не изучено.

Поскольку в уже проверенном `Otp::save()` `clearByUser()` вызывается **раньше** `UserTable::add()`, `UserTable::OnBeforeAdd` срабатывает слишком поздно, чтобы начинать транзакцию, охватывающую весь перевыпуск TOTP/recovery. Нужен owner scope **до удаления recovery**, общая блокировка `b_user`, epoch CAS и rollback на том же native DB handle. Это **техническое отрицательное ограничение**, НЕ доказательство реальной порчи recovery и НЕ подтверждение наличия такого owner scope. V5 остаётся закрытым.

## 4. ТРЕТИЙ ФАКТИЧЕСКИЙ production source-only аудит — 7/7 PASS

Оператор выполнил ранее подготовленный `v5_native_recovery_final_boundary_readonly.sh` на `evrasia`. Результат: `MODE=READ_ONLY`, `PASS_COUNT=7`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, три pinned SHA совпали, без Bitrix bootstrap, SQL и изменения production.

**Новые факты:**
- `UserTable::onAfterDelete()` (101–105) вызывает `RecoveryCodesTable::clearByUser(USER_ID)` **после** нативного SQL удаления MFA. Один `OnAfterDelete` не доказывает общую транзакцию.
- `RecoveryCodesTable::regenerateCodes()` (111–133) вызывает отдельные `static::add($code)` для десяти кодов и затем возвращает true, не проверяя каждую ORM Result.
- `RecoveryCodesTable::useCode()` (143–172) выбирает `USED=N`, вызывает `static::update(ID, USED=Y)`, не проверяет Result и устанавливает `found=true` при совпадении строки. Атомарного условного consume `USED=N -> Y` в проверенном нативном методе нет. При конкуренции возможны два успешных ответа, но **это гипотеза безопасности, НЕ production incident / E2E proof**.
- Впервые pinned `BaseMergeTrait.php` SHA `18deb72c645694c6378662056834f5c3dd0e409d8d3c271e4f49fd910c9ce5b8`: `getCachedMergeStrategy()` кэширует `new Merge(static::getEntity())`. Этот trait не является владельцем транзакции.

**Все три связанные source-only диагностики завершены:** 9/9, 6/6, 7/7 PASS. Их **НЕ повторять**. В коде Bitrix production ничего не менялось.

## 5. Принятое техническое ограничение и актуальная точка продолжения

Полный доказательный разбор и **предложенный, ещё не реализованный** atomic owner-scope:
[`SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md`](SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md).
Главное: для MFA secret replacement / delete / recovery требуется один транзакционный scope **до первых recovery операций**, общий `b_user FOR UPDATE` mutex, один настоящий native Bitrix `Application::getConnection()`, проверка результатов или postconditions, `AUTH_EPOCH` CAS и доказанный rollback. `OnBeforeAdd` начинается уже после `clearByUser`, `OnAfterDelete` начинается после native DELETE. Нельзя считать `useCode() === true` доказательством безопасного одноразового recovery-подтверждения для V5.

**Минимальное изменение кода только в GitHub feature**: расширены негативные `V5NativeMutationCoverageTest.php` для `OtpDeleteAndDisable` / `OtpRecoveryAndReset`. Действующий 10-path `V5NativeMutationCoverageMatrix` не менялся, дополнительный release gate НЕ создан. Проверенный [GitHub Actions run 38381811549](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38381811549) SUCCESS: **82/82 MFA coverage synthetic assertions**, **22/22 offline suite PASS**, 48 PHP lint PASS. Реальных MFA операций и БД тестов не было.

**Следующий обязательный шаг именно для реальных транзакционных испытаний MFA** — не новые MFA source-only аудиты на production, а независимое подтверждение/выделение изолированного Bitrix+MySQL staging согласно `docs/SAMZABERU_V5_ISOLATED_STAGING_READINESS_2026-10-13.md`. На нём только после инфраструктурных guards — ранее подготовленный read-only native depth/permit preflight; затем, после отдельного разрешения, synthetic ORM/native rollback, fault-injection для OTP/recovery и конкурентный `useCode` consume, а также доказательство всех десяти native mutation paths с `AUTH_EPOCH`. Нельзя подменять staging одиночными успешными offline checks.

`PRODUCTION_WRITE=NO` · `DB_WRITE=NO` · `SMS_SENT=NO` · `V5_HTTP=503` (последний документированный baseline) · `STAGING_BITRIX_ATTESTED=NO` · `NATIVE_MFA_ATOMICITY_PROVEN=NO`.
