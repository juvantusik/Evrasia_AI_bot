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
