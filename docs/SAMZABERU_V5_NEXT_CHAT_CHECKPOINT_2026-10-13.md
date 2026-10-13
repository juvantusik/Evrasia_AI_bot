# Evrasia / СамЗаберу API V5 — актуальная точка продолжения 2026-10-13

> **ACTUAL / SUPERSEDES 2026-10-12 V5 HANDOFF.** Этот checkpoint описывает рабочую ветку GitHub и проверенный read-only вывод сервера, а не production-релиз. Все старые ссылки на *не реализован* native transaction depth port описывают 2026-10-12 и уже устарели.

## 1. Состояние проекта

- Repo: `juvantusik/Evrasia_AI_bot`; код V5 — исключительно `feature/samzaberu-api-v5-auth-contract`. `main` — документация/история production; не мержить кандидата ради проверки.
- На production сайтах и боте изменений в рамках текущей ветки **не было**. V4 / Trusted Device / TOTP работают по последним отдельно подтверждённым production-фактам. V5 router остаётся с HTTP 503 по **последнему документированному** состоянию; новые HTTP-проверки в этом шаге не выполнялись.
- Реальной Bitrix staging VM/отдельной MySQL-БД с независимо доказанной изоляцией **не зарегистрировано**. Реальный Bitrix ORM↔native rollback, staging MFA/epoch concurrency, SMS sender и 10-path atomicity **не проводились**. В production запрещены любые изменения БД, внедрение хуков, SMS и V5-активация.
- GitHub Actions `.github/workflows/v5-offline-safety.yml`: проверенный run [38377164870](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38377164870), `success`; PHP 8.4.26; 48 PHP files lint PASS; все 22 offline suites PASS; оба shell source diagnostics `bash -n` PASS; staging preflight и rollback entrypoints в негативном сценарии отказывают без bootstrap. **Это fake/offline проверки, не production/staging E2E.**
- Source-backed staging-only `V5VerifiedBitrixNativeTransactionDepth.php` (Reflection на `protected int $transactionLevel`, SHA-pinned Bitrix classes) уже реализован в feature, прошёл 18 синтетических checks; `V5StagingNativeTransactionPermit` 62/62 fake tests; `V5BitrixPdoCompatibilityBridge` 80/80 fake tests. **Нет реального production permit** и независимого native 10-path security coverage.

## 2. Последний ФАКТИЧЕСКИЙ вывод оператора

На `evrasia` оператор выполнил `site-integration/samzaberu-v5/tests/diagnostics/v5_native_mfa_writer_order_readonly.sh`; результат `MODE=READ_ONLY`, `PASS_COUNT=9`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. Все **шесть** pinned SHA исходников Bitrix совпали; `DATABASE_CONNECTION=NO`, `BITRIX_BOOTSTRAP=NO`, `PRODUCTION_APPLICATION_WRITE=NO`.

Подтверждённый native source order:
- `Otp::save()`: `RecoveryCodesTable::clearByUser()` при regenerated (стр. 370) **до** `UserTable::add` (374), затем cache invalidation. `Otp::delete()`: `UserTable::delete` (390).
- Background `OtpEvents::onRecheckDeactivate()`: `UserTable::update` (30/34), отдельный writer; `CSecurityUser::update()` может вызвать OTP deactivate/delete/regenerate/save; `onAfterUserUpdate` — только MFA cache invalidation.
- Native `DataManager` add: before-event 906, on-event 939, `strategy->add` 956, after-event 1015. Update: before 1277/1306, actual SQL 1337, after 1362. Delete: before 1665/1674, actual SQL 1691, after 1702.
- `Merge` strategy invokes `prepareMerge` and `Helper::executeAndGetInsertedId` (89-100). Это **только статический порядок**, не доказательство общего BEGIN/COMMIT/ROLLBACK. Нельзя доверять `OnAfterUserUpdate` как атомарному MFA+AUTH_EPOCH или ставить 10-path coverage positive.
- Подробности в `docs/SAMZABERU_V5_NATIVE_MUTATION_COVERAGE_2026-10-12.md` и `docs/STRUCTURE_REGISTRY.md`, обновлены по фактическому выводу.

## 3. ТЕКУЩИЙ следующий шаг

Подготовлен **новый, НЕ ВЫПОЛНЕННЫЙ** на production source-only аудит:
`site-integration/samzaberu-v5/tests/diagnostics/v5_native_recovery_merge_readonly.sh`.
Этот скрипт проверяет root/host, исходные SHA `Otp.php` и `UserTable.php`, выводит точную декларацию `UserTable`, устанавливает фактическую реализацию `MergeByDefaultTrait` и `RecoveryCodesTable::clearByUser` с путями/SHA и короткими исходными фрагментами. **Никакого native Bitrix bootstrap, SQL, пользовательских данных или записи.** В CI только Bash lint, реального выполнения нет.

**Если пользователь присылает stdout с `MERGE_TRAIT_REL`, `RECOVERY_SOURCE_REL` и `FINAL_STATUS`, немедленно разобрать его**: подтвердить, выполняется ли реальное удаление recovery через ORM/native SQL, как `UserTable::add` выбирает merge/upsert, есть ли вложенная/собственная транзакционная граница и какие нативные события реально существуют. Не просить прежний вывод, не переисследовать `b_user`/V4/TOTP/начальную карту API.

Независимый следующий барьер: отдельный физически изолированный Bitrix/MySQL staging согласно `docs/SAMZABERU_V5_ISOLATED_STAGING_READINESS_2026-10-13.md`, **сначала** real read-only depth/permit preflight, и только после согласования — synthetic staging rollback. Ни один успешный источник/staging smoke не открывает V5: требуется ещё **10 независимых native MFA/security mutation paths**, общий `b_user` lock, `AUTH_EPOCH` + `PHONE_EVENT_VERSION`, конкурентный rollback и финансовые SMS guards.

`PRODUCTION_WRITE=NO` · `DB_WRITE=NO` · `SMS_SENT=NO` · `V5_HTTP=503` (last documented baseline) · `STAGING_BITRIX_ATTESTED=NO` · `NATIVE_MFA_ATOMICITY_PROVEN=NO`.
