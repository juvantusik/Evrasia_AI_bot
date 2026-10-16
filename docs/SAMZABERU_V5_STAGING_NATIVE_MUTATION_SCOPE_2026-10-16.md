> **SAVEPOINT REVISION + CI PASS, 2026-10-16:** Code `1987ddc114352470a476f3f3e5e8eda057458f8b` detects native `COMMIT+BEGIN` (not visible through connection ID/depth alone) using a randomly named SQL SAVEPOINT. Lost marker → uncertain/poisoned, no automatic rollback or retry. Disposable MariaDB `38896370806` SUCCESS 530 (43+487), +7 new staged-scope checks; offline `38896370467` SUCCESS 26. CI synthetic native Bitrix, no production proof. See `docs/SAMZABERU_V5_SAVEPOINT_NATIVE_BOUNDARY_2026-10-16.md`.

> **VERIFIED 2026-10-16:** feature `2a3751e0c560381be401e5bd3d550d03756bd50a` CI passed: offline `38889891833` 26 suites, disposable MariaDB `38889891967` **523 checks (43+480), 33 new scope assertions**, all PASS. CI positive Bitrix native transaction/evidence are synthetic; source-pinned Bitrix actual writer coverage remains unproven. No production code/DB writes in this increment.

# СамЗаберу V5 — ограниченный native mutation scope перед интеграцией hooks

16 октября 2026, GitHub feature ONLY; никакого deployment. Это отдельная попытка уменьшить риск подключения native Bitrix security writers, но НЕ утверждение, что нативные десять потоков уже атомарны.

## Входные факты

- Production eurasia_new уже имеет шесть пустых V5 InnoDB таблиц (DDL 15.10 PASS), V5 OFF/HTTP503, заводских AUTH_EPOCH строк нет.
- V5SecurityEventVersionWriter и V5PilotAuthEpochInitializer прошли в CI реальные InnoDB rollback/CAS/phone reconfirm тесты через синтетический Bitrix mysqli facade, предыдущий baseline: 490 assertions.
- В реальном Bitrix Otp::save начинает recovery deletes ещё ДО ORM UserTable::OnBeforeAdd. OnAfter callbacks / отдельное PDO соединение не дают гарантию той же транзакции. Патчить production V4 core или глобально отказывать в обычных V4 мутациях без V5 epoch нельзя.

## Новый кандидат

src/V5StagingNativeMutationScope.php (не подключён к приложению):
- только CLI на НЕproduction host, а также только объявленный CI/isolated staging environment; на production evrasia/eur-bot-01 его run запрещён;
- только явный cohort 1-32 целых ID native Bitrix b_user, без wildcard/HTTP;
- использует concrete V5BitrixPdoCompatibilityBridge с V5NativeBitrixExclusiveTransactionPermitPort, V5StrictNativeSecurityHookCoverage и V5SecurityEventVersionWriter;
- только три вида reviewed entrypoints: password change/reset, phone confirmation/reconfirmation, phone revoke (replacement to confirmed value требует отдельного proof). MFA/Otp/recovery/logout/agent здесь НЕ реализованы;
- один begin через Bitrix native mysqli, затем b_user FOR UPDATE ДО вызова typed V5ReviewedNativeMutationPort. Контроль connection ID, @@session.in_transaction=1 и native depth==1 через V5VerifiedNativeTransactionDepthPort. Реальная реализация reader требует проверенных source SHA на isolated staging;
- до native-записи fail-closed проверка всей source-pinned installed 10-path native матрицы. Реального trusted evidence provider ещё нет: готовый класс не расширяет допуск;
- после native callback применяет существующий epoch-CAS writer; телефон читает только из заблокированной b_user_phone_auth, требует canonical E.164/confirmed Y, не принимает номер клиента;
- при отрицательном native результате, недостаточной coverage, CAS error откатывает принадлежащую scope транзакцию (если native связь/владение ещё подтверждены); не делает retry. COMMIT uncertain остаётся unknown; не обещает rollback после потерянного ответа COMMIT.

Нельзя принимать depth==1 и connection ID==same за полное доказательство транзакционной непрерывности: скрытые COMMIT+BEGIN могут вернуть те же показатели. Source-pinned native writer proof + настоящий staging rollback/concurrency тест всех десяти путей обязательны.

## Проверки

tests/integration/V5EphemeralMySqlStagingNativeMutationScopeTest.php использует реальную disposable GitHub MariaDB/InnoDB и синтетический native Bitrix facade. Проверяет: unapproved ID, чужую уже открытую transaction, неподдержанные OTP, неполную матрицу, успешные password+epoch commit, callback=false, native write fault, epoch CAS fault, missing state rollback, same E.164 повторно, revoke и восстановление, false revoke, скрытый native COMMIT => uncertain, не success.

Положительные fake evidence и методы CiReviewedNativeAction существуют ТОЛЬКО в изолированном CI. Они не публикуются/не вызываются на production. Тест не создаёт real native hook и не аттестует Bitrix Security MFA Otp.
Количество assert и success брать из фактического Actions job log.

## По-прежнему необходимо

1. На существующем клоне/staging или source-verified native test harness доказать фактическую транзакцию Otp::save с recovery-code cleanup, затем native agent, reset и остальные 10 событий.
2. Согласовать hook/entrypoint API с actual Bitrix release SHA, не модифицируя V4 production core без отдельного решения.
3. Реально подтвердить concurrent lock order и common rollback для каждого mutation path. Одной fake coverage matrix недостаточно.
4. Только после этого выдавать per-user AUTH_EPOCH rows approved cohort, подключать real native providers, TOTP/no fallback и PIN.
5. Не выполнять новый DDL, не отправлять SMS, не включать API pilot.

STATUS до staging: NATIVE_HOOKS_INSTALLED=NO; FULL_10_PATH_ATTESTED=NO; PRODUCTION_WRITE=NO.
