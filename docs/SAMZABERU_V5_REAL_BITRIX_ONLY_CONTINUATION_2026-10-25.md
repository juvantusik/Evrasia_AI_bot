# СамЗаберу V5 — только существующая production Bitrix/eurasia_new, 25.10.2026

## Непереходимые требования владельца

- **Никаких изолированных БД / ephemeral MariaDB, новых синтетических пользователей или тестовых учётных записей.** Не запускать старые отдельные MySQL CI suites.
- Использовать действующий Bitrix на `evrasia`, базу `eurasia_new`; сначала строго READ ONLY metadata/source checks. Никакой DDL, записи, реальных SMS/email или включения V5 без отдельного решения.
- Работающие сайт `evrasia.rest`, email-first/SMS fallback, Bitrix MFA и **API V4 не менять**.
- Выбранная логика V5: текущий strong trusted device → Bitrix native TOTP → подтверждённый email OTP. SMS только по явному действию пользователя, без автоматического downgrade.
- **Отменить любые попытки публикации production website source в GitHub**. Сохранить документацию без live source/secrets.

## GitHub feature-only изменения

- `src/V5MySqlEmailOtp.php`: candidate password-bound EMAIL OTP issuer/consumer (InnoDB, 300 sec, <=3 неверных попыток, HMAC-код, текущий email-proof, locked auth epoch, savepoint guards, mail acceptance, one-time CAS). Не разрешает регистрацию по одному USER_ID или TOTP→EMAIL обход. **Его native Bitrix site proof/rate/transport adapters ещё НЕ установлены.**
- `src/V5SeparatedFactorLifecycle.php`: необязательный email issuer, отсутствие которого fail-closed; TOTP не может переходить в email или SMS автоматически.
- `src/V5MySqlChallengeHttpPort.php`: необязательный отдельный email verifier, без подмены маршрутом SMS/TOTP.
- `schema/PROPOSED_ev_v5_email_auth_challenges.sql`: **проект таблицы внутри существующей БД**, НЕ применён на production и НЕ разрешает создание изолированной БД. До live schema evidence ничего не применять.
- Новая защита на оригинальный SQL savepoint через внешний V5EmailOtpRatePort: коммит [5779224356989749a990f64b3c18728e64881b69](https://github.com/juvantusik/Evrasia_AI_bot/commit/5779224356989749a990f64b3c18728e64881b69).
- По указанию владельца **удалён workflow `.github/workflows/v5-ephemeral-mariadb.yml`**: [a651d121989d7fdd3419597db8c6a8d9be9675d2](https://github.com/juvantusik/Evrasia_AI_bot/commit/a651d121989d7fdd3419597db8c6a8d9be9675d2). Удалён также новый `tests/integration/V5EphemeralMySqlEmailOtpTest.php`: [69f9d870ead74031d680909d30852b7ad5e28344](https://github.com/juvantusik/Evrasia_AI_bot/commit/69f9d870ead74031d680909d30852b7ad5e28344).
- В GitHub создан строго read-only production schema inspector `deployment/v5-real-bitrix-email-schema-readonly.sh` [b3ed0882a68253fb16083c72f0001d7445ec1b53](https://github.com/juvantusik/Evrasia_AI_bot/commit/b3ed0882a68253fb16083c72f0001d7445ec1b53); точный git blob `9ee4b88ab04dc9c18fbb59729fb2aaf200c29f8d`. Он читает ТОЛЬКО `information_schema.TABLES/COLUMNS` в **реальной** `eurasia_new`, проверяет production Bitrix `user.php` pinned SHA и не читает записи пользователей.
- [GitHub Actions #40175234383](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40175234383) **SUCCESS**: PHP lint `V5MySqlEmailOtp.php` PASS, отдельный email/TOTP/SMS router 33 offline assertions PASS, `FIRST_FACTOR_PUBLIC_GATES=PASS`. **Нет настоящего native Bitrix E2E; PHP syntax и unit test != production acceptance.** Нет изолированной DB в данном workflow.

## Реальные блокирующие зависимости (не заявлять готовым)

1. `ev_web_email_ownership_proof` и актуальная схема `b_user` в существующей `eurasia_new`, подтверждение поля и ownership stamp/прочих writer hooks.
2. Нужен строго нативный `V5LockedEmailOtpProofPort` на том же физическом Bitrix connection, сравнивающий current password через `Bitrix\Main\Security\Password::equals`, отсутствие TOTP с одобренной MFA fallback policy, confirmed-current-email ledger, время/отзыв, AUTH_EPOCH и anti-fraud under lock. Никаких permissive providers.
3. `V5EmailOtpRatePort` должен атомарно ограничить пользователя/email/IP/device через server-observed IP и действующие сервисы, не через запрос клиента. Никаких придуманных общих потолков.
4. `V5EmailOtpTransportPort` должен использовать **существующий site-owned Bitrix mail transport** и проверенный security-template без скрытых BCC. Само принятие в очередь не доказывает доставку, но код подтверждается только по фактическому вводу.
5. В `V5BitrixPdoCompatibilityBridge` **ещё нет exact-SQL allowlist** для новых email queries. Нельзя разрешать wildcard SQL или утверждать, что mobile email candidate работает на текущем Bitrix. Нужно нативно аттестовать каждую SQL-форму и транзакционную непрерывность.
6. `V5MySqlStrongSessionStore`, `V5SessionAssurancePolicy` и `V5MySqlConsumedChallengeProof` по-прежнему принимают только `totp/sms/trusted`, а не полноценный `email` grant. **Выпуск V5 JWT после EMAIL сейчас запрещён** до собственной verified email consumed grant proof и one-use transaction.
7. Установленные native auth epoch writer hooks, strong trust и TOTP replay proof и реальный V5 router OFF не подтверждены для production.

## NEXT STEP

Выполнить **единственный** точный production read-only `v5-real-bitrix-email-schema-readonly.sh` на `root@evrasia`. По его результату сопоставить реальные таблицы/колонки и реализовать только подтверждённые native адаптеры в V5 feature. Без тестовых БД/пользователей. До отдельного явно подтверждённого rollout: `V5_AUTH_PRODUCTION=OFF`, `V4_UNCHANGED=YES`, `WORKING_WEBSITE_UNCHANGED=YES`.
