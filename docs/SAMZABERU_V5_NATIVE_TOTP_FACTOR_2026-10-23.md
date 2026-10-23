# СамЗаберу V5 — нативная проверка TOTP: реализация в feature, выпуск запрещён до Bitrix staging

Дата: 23.10.2026. Ветка `feature/samzaberu-api-v5-auth-contract`. **Эта заметка не означает, что AUTH production включена.**

## 1. Последнее утверждённое поведение

После проверенного логина/пароля сервер проверяет **действующее сильное доверие установки** (валидный `trust_token`, привязка к аккаунту, текущая версия доверия, предшествующее MFA, отсутствие отзыва). Когда оно подтверждено, **новый TOTP/SMS код не требуется даже при активном TOTP**. Без подтверждённого сильного доверия и при активном `b_sec_user` TOTP нужен новый шестизначный код из аутентификатора; на SMS не переключаемся автоматически. Без активного TOTP и при доказанном разрешении Bitrix — отдельный SMS challenge, иначе отказ. Старые V4 trust-записи, полученные только по паролю, не повышаются до сильного V5 trust автоматически.

В feature изменены:
- `src/AuthDecision.php`: `strong_current_trust → COMPLETE`, затем `TOTP`, затем `SMS`.
- `src/V5LoginWorkflow.php`: оценивает сильный trust и у владельцев включённого TOTP, возвращает либо новый TOTP challenge, либо немедленно доверенную сильную сессию.
- `src/V5MySqlStrongSessionStore.php::afterTrustedDevice`: убрано старое безусловное отклонение при `nativeTotpActive`. **Не убраны** server-side `verifiedTrustWithUserLock()`, текущая native `AUTH_EPOCH`, Revocation/MFA coverage, транзакция или подписывание JWT после COMMIT.
- Обновлены `AuthDecisionTest.php`, `V5LoginWorkflowTest.php`, `V5MySqlStrongSessionStoreTest.php` и синтетический реальный MariaDB pilot composition test.

Прошедшие CI проверки первого изменения: [offline safety #40003906478](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40003906478), [first factor #40003906556](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40003906556). В первом запуске MariaDB #40003906380 была **FAIL** из-за старого теста, который требовал приоритет TOTP; он обновлён в отдельном коммите, [MariaDB #40004366187](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40004366187) **COMPLETED SUCCESS**. Эти проверки используют синтетические first-factor, trust и native MFA, не production аккаунты.

## 2. Нативный TOTP adapter / реальное API Bitrix

Новый `src/V5BitrixLockedTotpFactor.php` реализует существующий `V5LockedChallengeNativeFactorPort` и:
1. Входит **только** при `PHP_SAPI=cli`, `V5_STAGE_WRITE_ACK=ISOLATED_STAGING_ONLY`, строго НЕ на `evrasia`, `eur-bot-01`, prod/production. Поэтому **не может** случайно активировать production HTTP.
2. Требует именно существующий `V5BitrixPdoCompatibilityBridge` и `ownsCurrentNativeTransaction()` для точного `Bitrix\Main\Application::getConnection()` на физическом `Bitrix\Main\DB\MysqliConnection`, `V5VerifiedNativeTransactionDepthPort::depthFor() === 1`, действующую полную 10-path native mutation coverage и отдельный attested `V5LockedChallengeNativeFactorPort` текущего пользовательского права (не выдумывает его из флага).
3. Проверяет SHA256 **установленного** `Bitrix\Security\Mfa\Otp` `285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c`, а также SHA `Bitrix\Main\Security\Mfa\TotpAlgorithm` из **дополнительно проверенного администратором staging** значения. Последний SHA в этом репозитории ещё НЕ аттестован: произвольное значение без исходного просмотра на staging запрещено считать пройденным release gate.
4. Читает только MFA конкретного `USER_ID` через `FOR UPDATE`: `ACTIVE=Y`, `TYPE=totp`, 40 hex secret (не печатается), отсутствие `DEACTIVATE_UNTIL`, точные `PARAMS` (native `offset:last_timecode`) и действующий счётчик попыток. `Otp::getByUser($uid)` дополнительно должен отразить ту же текущую MFA/secret/PARAMS; кэшированный/чужой объект запрещён.
5. Вызывает **штатный** `$otp->verify($code, true)` внутри уже открытой native транзакции. После `true` принудительно повторяет locked-чтение native `b_sec_user.PARAMS` и требует сохранённое монотонное увеличение `last_timecode`. Простого `true` от Bitrix недостаточно: отказ, если показатель не обновился, MFA изменилась, другой секрет/владелец, транзакция разорвана, source hash не совпал или сохранение не подтверждено.
6. Внешний `V5MySqlChallengeConsumption` сохраняет SAVEPOINT до вызова native кода; ошибка/COMMIT внутри Bitrix/native POST-условий вызывает отказ и контролируемый откат **только собственной доказанной транзакции**. Успех вызова ещё должен быть зарегистрирован как consumed challenge с блокировкой одноразовости, а затем отдельный `V5MySqlConsumedChallengeProof` и session store снова проверяют грант при выдаче V5 JWT.

**Ключевой инвариант:** нет второго TOTP-секрета или отдельного клиентского TOTP-верификатора. Для Bitrix TOTP native `Otp::verify($input, true)` вызывает сохранение `PARAMS`; алгоритм хранит последний использованный timestep. Это подтверждено доступной документацией и исходными контрактами, но **конкретный установленный native-код и его действительную атомарность ещё требуется доказать на изолированном Bitrix**.

## 3. Что уже покрыто тестами, и чего тесты НЕ доказывают

Добавлен `tests/V5BitrixLockedTotpFactorTest.php` (offline, без настоящего Bitrix) — проверка персистентного replay-матрикса: правильное увеличение timecode, отказ на том же/старом code, отказ при неизвестном/неполном/подменённом MFA, смене секрета, повторной попытке, неверном user_id/строке params. Исполняемые TOTP secrets не читаются, синтетический код не является реальным native Bitrix кодом. Расширен `tests/V5BitrixPdoCompatibilityBridgeTest.php`: только точно принадлежащий текущему bridge mysqli может получить флаг активного native scope до COMMIT, чужая сессия — нет. Добавлено в offline workflow.

**Критические незавершённые условия:** физически изолированный real Bitrix staging, подтверждённая SOURCE SHA installed `TotpAlgorithm.php`, реальный позитивный `Otp::verify` с persist/readback на нём, два параллельных запроса на один и тот же TOTP с разными challenges (один должен отказать), native transaction/savepoint/rollback без скрытого COMMIT, все 10 security mutation/epoch hooks и текущий trust issuer/strong enrollment. Это не заменяется synthetic/ephemeral MariaDB CI, и до этой приемки ни source-файлы, ни приватные providers не публикуются в production API.

## 4. Следующий шаг

После успешного CI: реализовать/аттестовать реальные locked eligibility/trust proof providers и изолированную native Bitrix TOTP проверку; закрыть 10-path `AUTH_EPOCH`, native MFA replay и SMS/phone use case. Проверять публичный адрес только `evrasia.rest`, V4 не изменять.

**Статус:** `V5_TRUST_FIRST_CODE=FEATURE_IMPLEMENTED`; `V5_NATIVE_TOTP_ADAPTER=STAGING_CANDIDATE`; `NATIVE_TOTP_REAL_BITRIX_E2E=NOT_TESTED`; `V5_AUTH_PRODUCTION=OFF`; `PRODUCTION_CODE_WRITE=NO`.

## 5. Завершённая CI-приёмка feature (последняя проверка перед handoff)

Для нативного TOTP source commit [`c1eb8767`](https://github.com/juvantusik/Evrasia_AI_bot/commit/c1eb87674fb4008d56c4f5106dc2a4b0d064fe35) все три джоба GitHub Actions к 23.10.2026 завершились **COMPLETED SUCCESS**: [offline safety #40005309621](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40005309621), [first-factor/public #40005309623](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40005309623), [real ephemeral MariaDB #40005309662](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40005309662). Предыдущая заметка checkpoint §44 о `IN PROGRESS` для MariaDB устарела. Ephemeral MariaDB подтверждает code/SQL tests, **не нативную проверку Bitrix OTP на реально изолированном сайте** и не разрешает production release. Полная инструкция продолжения: [`EVRASIA_AI_BOT_NEW_CHAT_INSTRUCTION_2026-10-23_TOTP.txt`](EVRASIA_AI_BOT_NEW_CHAT_INSTRUCTION_2026-10-23_TOTP.txt).


## 6. Продолжение 24.10.2026 — native TOTP staging identity и независимые challenge replay

**Продвижение в feature после handoff, без production write:**

- Commit [65cded0](https://github.com/juvantusik/Evrasia_AI_bot/commit/65cded028e5396582c34e8cf7b957da5f068f283): staging native factor дополнительно требует canonical staging Bitrix root, dedicated `evrasia_v5_test_*` schema, точный SQL `@@hostname`, `@@session.in_transaction=1`, autocommit и неизменный `CONNECTION_ID()` перед и после native `Otp::verify()`. Введён `V5NativeTotpStagingIdentity.php` и negative matrix; CI [offline #40016381278](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40016381278), [first-factor #40016381218](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40016381218), [ephemeral MariaDB #40016381405](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40016381405) — COMPLETED SUCCESS.
- Commit [454b44d](https://github.com/juvantusik/Evrasia_AI_bot/commit/454b44d4484dc1832635bf0640799764182187ce): added disposable InnoDB race of TWO DIFFERENT password-proven TOTP challenges for same synthetic user, same synthetic code, independent PDO processes and ONE user-wide locked timecode marker; also exception rollback after marker update. First MariaDB CI #40016941200 FAIL due solely to duplicate fixture challenge_id already used in the original test. No Bitrix production or native verifier executed.
- Commit [a1ddc0f](https://github.com/juvantusik/Evrasia_AI_bot/commit/a1ddc0f1d97761732c14d3360674a5d0c6776b49): replaced fixture IDs with distinct deterministic SHA-256 and prior-collision check. [MariaDB CI #40017154673](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40017154673) and [offline CI #40017154702](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40017154702) — COMPLETED SUCCESS. CI confirms one consumed grant, one denied challenge with charged failed attempt, continued replay denial, and rollback of synthetic marker after unexpected factor callback exception. Full dependent chain: 513 assertions plus 43 separate session assertions.

**What this does NOT prove:** the replay ledger is an independent synthetic InnoDB fixture, NOT installed Bitrix b_sec_user; no real `Otp::verify($code,true)` or native TOTP secret was used. Exact installed `Bitrix\Main\Security\Mfa\TotpAlgorithm` SHA remains UNVERIFIED; no isolated real Bitrix two-challenge concurrency proof, ten-path AUTH_EPOCH acceptance or production auth enablement. Public Bitrix source is reference only and must not be substituted for installed source SHA. Next required operator action: READ_ONLY source-only SHA + bounded method review on technical Bitrix origin, not a production auth/write experiment. User's earlier refusal to require an additional VM remains valid; this does not authorize production staging experiments.

**Status:** `STAGING_DB_SESSION_GUARD=FEATURE_TESTED`; `TWO_DISTINCT_CHALLENGE_SYNTHETIC_REPLAY=TESTED`; `INSTALLED_TOTP_ALGORITHM_SHA=UNVERIFIED`; `REAL_BITRIX_TOTP_REPLAY=NOT_TESTED`; `TEN_PATH_AUTH_EPOCH=NOT_ATTESTED`; `V5_AUTH_PRODUCTION=OFF`; `V4_PRODUCTION=UNCHANGED`.
