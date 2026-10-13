# Evrasia / СамЗаберу API V5 — актуальная точка продолжения 2026-10-13

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

## 4. ТЕКУЩИЙ следующий шаг — НЕ РЕАЛИЗОВАН НА PRODUCTION

Подготовлен **новый, ещё НЕ ВЫПОЛНЕННЫЙ** source-only `site-integration/samzaberu-v5/tests/diagnostics/v5_native_recovery_final_boundary_readonly.sh`, который проверяет только **3 exact source SHA** и выводит `UserTable` delete event (94–175), части `RecoveryCodesTable` (1–82, 125–175) и `BaseMergeTrait::getCachedMergeStrategy` со SHA. Не переизучать предыдущие исходники. Скрипт не загружает Bitrix, не подключается к БД, не читает пользователя и не пишет production.

GitHub Actions [38378187055](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38378187055) **SUCCESS**: `bash -n` всех трёх диагностик; PHP 8.4.26, 48 PHP lint PASS, 22 offline suite PASS, оба staging entrypoint без разрешения отказывают. **CI никогда не исполняет производственные source-audit скрипты**, только проверяет Bash-синтаксис.

Если следующее сообщение пользователя — stdout со строками `BASE_MERGE_TRAIT_SHA256`, `SOURCE=...UserTable.php`, `SOURCE=...BaseMergeTrait.php`, `FINAL_STATUS`, **сначала разбирать именно его**, не повторять 9/9 и 6/6 диагностики. Никаких production changes или полного переаудита `b_user`.

Независимый следующий барьер: отдельный физически изолированный Bitrix/MySQL staging согласно `docs/SAMZABERU_V5_ISOLATED_STAGING_READINESS_2026-10-13.md`, **сначала** real read-only depth/permit preflight, и только после согласования — synthetic staging rollback. Ни один успешный источник/staging smoke не открывает V5: требуется ещё **10 независимых native MFA/security mutation paths**, общий `b_user` lock, `AUTH_EPOCH` + `PHONE_EVENT_VERSION`, конкурентный rollback и финансовые SMS guards.

`PRODUCTION_WRITE=NO` · `DB_WRITE=NO` · `SMS_SENT=NO` · `V5_HTTP=503` (last documented baseline) · `STAGING_BITRIX_ATTESTED=NO` · `NATIVE_MFA_ATOMICITY_PROVEN=NO`.
