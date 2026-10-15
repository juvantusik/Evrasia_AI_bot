# Evrasia AI Bot / СамЗаберу V5 — START HERE / 15 октября 2026

**ГЛАВНАЯ ТОЧКА ПРОДОЛЖЕНИЯ. Новый чат — не новый проект.** Читать этот файл, [текущий checkpoint](SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md), [дополнение реестра решений](SAMZABERU_V5_INTEGRATION_DECISIONS_2026-10-15.md). Старые NEXT в исторических документах не выполнять повторно.

## 1. Последнее подтверждённое состояние

Repo `juvantusik/Evrasia_AI_bot`, рабочая ветка **`feature/samzaberu-api-v5-auth-contract`**. Последний code/test commit с обеими успешными CI: **`c3f92ba3197157e1cd19f9aa21df3d353862279d`**.

- Offline [Actions 38743961486](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38743961486), job `116452589733`: SUCCESS, прежние 26 approved suites и PHP lint текущего дерева.
- Реальная одноразовая MariaDB/InnoDB [Actions 38743961127](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38743961127), job `116452588251`: SUCCESS; прочитанный лог **43 sessions + 117 SMS + 45 reconciliation + 63 bridge + 61 locked-password = 329 assertions**, combined SMS/bridge/locked-password 286, FINAL_STATUS=PASS, FINAL_RC=0.
- Новый `src/V5BitrixLockedPasswordTotpProof.php` реализует существующий обязательный порт TOTP issuer: тот же PDO/Bitrix bridge, собственное получение b_user lock внутри caller transaction, повторная проверка переданного AUTH_EPOCH, уникальное разрешение текущего логина, чтение пароля/активности/блокировки/expiry/attempts и native MFA под блокировками. Использует существующие `V5SiteAccountReader`, `V5NativeTotpStatus`, password comparator и **тот же** `V5SecurityEventVersions::forLockedPasswordProof()`; нового формата epoch нет.
- В мост добавлены ровно четыре точных identity SELECT: EMAIL/LOGIN для существующего first-factor reader и варианты LIMIT 2 FOR UPDATE для recheck. Generic EMAIL/LOGIN predicate не разрешён, inactive/blocked duplicates не скрываются.
- Проверены текущий/старый пароль после сброса, чужая identity с тем же паролем, inactive duplicate email, блокировки, expiry, login policy, configured inactive/unsupported/missing TOTP, отсутствие native comparator, unknown hook coverage, неверный epoch/connection/scope. Вторая реальная MySQL connection получает lock timeout при попытке изменить пароль во время proof; после окончания caller scope reset коммитится и старый пароль отклоняется.
- **Граница доказательства:** Bitrix-shaped facade, comparator, normalizer, login policy и native hook coverage в положительных CI fixtures СИНТЕТИЧЕСКИЕ. Табличные чтения/блокировки и V5 adapter/epoch/issuer настоящие, но native Bitrix runtime/Password::equals/OTP replay/hooks не приняты. Адаптер НЕ установлен на сайт, public routes не включались.
- Compare `9c9ca7c..c3f92ba`: 4 файла — новый proof (+169), bridge (+16), новый test (+215), ephemeral workflow (+6/-4). Runtime PROPOSED schemas, V4, HTTP routes, production и main не менялись. ALTER/CREATE нового теста — только расширение строго проверенных упрощённых CI fixtures, НЕ миграция native DB.
- Локально выполнен PHP lint двух новых файлов; их Git blob SHA совпали с загруженными. Полные DB/regression tests выполнены в GitHub CI, не в локальном контейнере и не на сайте. После doc-only commit проверять live HEAD/diff от `c3f92ba`, не приписывать документации новое тестирование кода.

**OFFLINE_TESTED=PASS · REAL_EPHEMERAL_MARIADB_PASS=YES (329) · REAL_BITRIX_STAGING_TESTED=NO · REAL_SMS_PROVIDER_TESTED=NO · REAL_RESTIS_PIN_TESTED=NO · PRODUCTION_DEPLOYED=NO.** HTTP503 — последний документированный baseline, не свежий HTTP-тест.

## 2. Принятая бизнес-логика — не переутверждать

1. Серверная проверка логина/пароля без CUser::Login, V4-cookie или PHP login; текущие account/Anti-Fraud ограничения, expiry и лимиты попыток. Клиентский user_id/телефон/proxy headers не авторитетны.
2. Native TOTP имеет приоритет; после пароля обязателен authenticator code. При ошибке, неактивной конфигурации или недоступности MFA fallback на SMS запрещён.
3. Без TOTP вход без SMS разрешает только сильное **V5** trusted device, не старая слабая V4 запись.
4. Без TOTP и strong trust — password-bound SMS challenge на подтверждённый телефон аккаунта, без номера с клиента. После сильного входа бонусный PIN без повторной SMS.
5. SMS: десять rate/стоимостных окон, cooldown, предел ошибок/отправок, HMAC кода, encrypted outbox, user/device/phone/epoch binding, тариф/currency/idempotency. Claimed/unknown автоматически не отправлять повторно. Late receipt не создаёт отправку/расход/refund и не оживляет challenge.
6. PHONE_EVENT_VERSION меняется при успешной native верификации, включая повтор того же E.164, и соответствующих revoke/replace. AUTH_EPOCH меняется атомарно с native security mutations в той же физической транзакции; старые сессии/доказательства недействительны. Новый read adapter этих mutation hooks НЕ устанавливает.
7. V4, UI и согласованную архитектуру не переделывать.

Пять предложенных POST routes: `/api/v5/auth/signin`, `/api/v5/auth/challenge/verify`, `/api/v5/auth/challenge/resend`, `/api/v5/auth/refresh`, `/api/v5/loyalty/pin`. Route map не означает включённый router. Reconciliation и новый locked proof — внутренние компоненты, не публичные ручки.

## 3. Реализованные компоненты и остающиеся стыки

Все src/tests/schema ниже относительно `site-integration/samzaberu-v5/`.

| Домен | Реализация / граница |
|---|---|
| Auth/policy | `V5LoginWorkflow.php`, `AuthDecision.php`, `V5ExistingLoginPolicy.php`, `V5NoMfaFallbackPolicy.php`, `V5NativeTotpStatus.php`: TOTP > strong trust > SMS; настоящая native policy acceptance не выполнена. |
| Password/identity | `V5BitrixPasswordProofPort.php`, `V5SiteAccountReader.php`, `V5PdoUserRowSource.php`, `V5FirstFactorAttemptGate.php`, `V5MySqlAttemptWindowStore.php`; **новый** `V5BitrixLockedPasswordTotpProof.php`. Native comparator/PhoneConfirm normalizer переиспользуются через существующие порты, на реальном стенде ещё не приняты. IP-context и фактическая Anti-Fraud/login policy остаются обязательными. |
| TOTP begin | `V5MySqlPasswordBoundTotpIssuer.php`: user/epoch locks, fresh current password/MFA recheck, TTL до 300 секунд, cap 20 pending, ticket после COMMIT. Новый proof подключён к issuer в CI. Native code/replay consumption этим НЕ реализовано. Legacy begin(userId) запрещён. |
| Consume/grant | `V5MySqlChallengeConsumption.php`, `V5MySqlChallengeHttpPort.php`, `V5MySqlConsumedChallengeProof.php`: one-time, пять ошибок→cancel, SENT-only SMS proof, fresh grant recheck и UNIQUE session grant. **HTTP SMS begin/resend выключены** без проверенного lifecycle. |
| SMS | `V5SmsDispatchBudget.php`, `V5MySqlSmsOutbox.php`, `V5BitrixLockedSmsEligibility.php`, `V5MySqlSmsDispatchClaims.php`: 10 quotas, encrypted outbox, single claim, independent SENT receipt и UNKNOWN→SENT reconciliation; ACK только после owned COMMIT, provider lookup вне транзакции. Реальный paid worker/provider не подключён. |
| Sessions/PIN | `V5SessionTokens.php`, `V5MySqlStrongSessionStore.php`, `V5MySqlSessionActions.php`: независимые V5 keys/issuer/audience, JWT после COMMIT, refresh CAS/revocation; `V5LockedBonusPinReaderPort` читает PIN в session transaction без второй SMS. RestIS provider не установлен. |
| HTTP | `V5AuthHttpHandler.php`, `V5RouteMap.php`, `V5HttpTransportBoundary.php`, `V5PhpHttpRequestFactory.php`: HTTPS/POST/JSON/limits/no-cache/proxy guards, default503. Site router не активирован. |
| Bitrix/security | `V5BitrixPdoCompatibilityBridge.php`, `V5StagingNativeTransactionPermit.php`, `V5VerifiedBitrixNativeTransactionDepth.php`, `V5SecurityEventVersions.php`, `V5NativeMutationCoverage.php`. Exact lists для sessions, SMS reads и новых identity lookups; остальной legacy SQL guard не объявлять универсальным строгим allowlist. Native ORM/SqlHelper/10-path proof не приняты. |
| DDL | `schema/PROPOSED_*.sql`: proposed/CI fixtures, не native staging/prod deployment. Ни один V5 CI schema script не запускать в настоящей Bitrix DB. |

CI SMS wrapper теперь `V5EphemeralMySqlLockedPasswordTotpTest.php`: включает bridge→reconciliation→outbox suites ОДИН раз, затем 61 новый check. Тест сессий (43) запускается отдельно. Реальные synthetic-DB tests не заменяют native acceptance entrypoints.

## 4. Закрытые исследования — НЕ повторять

Production Bitrix host `evrasia`, docroot `/home/site_evrasia/web/evrasia.spb.ru/public_html`; бот на другом `eur-bot-01`. Ни один не staging. PinCode/PhoneAuth check имеют разные lexical owners; caller/receiver/class/namespace/core-phone probes закрыты. `UserPhoneAuthTable.php` SHA `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991`, CONFIRMED default N. CUser::Update line3920: delete4098 с переменной/последним распознанным присваиванием3925, conditional add4102 под if4101 с USER_ID/PHONE_NUMBER, без inline CONFIRMED. Это НЕ successful phone writer/dominance/transaction proof.

`user.php` observed SHA `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99` отличается от historical `542034ca4eee2c30bdc033f45d792945c10392aeaf2e482c01958e2bd585b9d0`; причина неизвестна, новый baseline НЕ утверждён, автоматически не исправлять. Исторические parser4/4 + wrapper5/5 PASS не новые запуски. Native OTP save/delete/recovery/OtpEvents не имеют 10/10 доказанных атомарных путей; useCode()===true не доказывает single-use CAS.

Архив: `EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-14.md`, `SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md`, `STRUCTURE_REGISTRY.md`, `SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md`, `SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md`, `SAMZABERU_V5_NATIVE_MUTATION_COVERAGE_2026-10-12.md`. Предыдущие полные редакции START HERE сохранены в Git history.

## 5. Ближайший реальный шаг и допуски

**Инфраструктурный blocker теперь отслеживается в [issue #76](https://github.com/juvantusik/Evrasia_AI_bot/issues/76).** Отдельные VM/Bitrix/MySQL/storage/network/secrets не подтверждены; новое восстановление истории дополнительных host/адрес/гипервизор фактов не дало. Это не доказательство отсутствия VM во всей инфраструктуре. [Готовый provisioning plan](SAMZABERU_V5_STAGING_PROVISIONING_PLAN_2026-10-15.md) не создавать заново.

Сначала подтвердить существующую независимую VM или согласовать её выделение и паспорт Stage A: изоляция ДО первого Bitrix PHP, отключённые агенты/cron/интеграции, запрет production egress, новые секреты, synthetic accounts и согласованные версии/пины. Затем существующий `V5BitrixNativeDepthReadOnlyPreflight.php`; bootstrap сам может иметь побочные эффекты. Stage C DDL/rollback/concurrency — отдельно разрешённые synthetic операции. После этого настоящий locked password/native TOTP replay/strong trust/phone verification + 10-path AUTH_EPOCH hooks, SMS lifecycle/provider receipts, RestIS PIN, staging E2E, затем только обсуждение pilot.

Новый proof является **read adapter**, не подтверждением установленных security hooks. Нельзя в native runtime подставить CI coverage=true, синтетический comparator или фиктивный SENT. Особо проверить actual identity indexes/collation/duplicate behavior и совместный порядок locks с native writers. SQL lock exclusion в CI не доказывает, что каждый реальный native writer использует этот mutex.

## 6. История, ветки и правила

Исторические успешные code checkpoints: `8c395f0320f82a1c69bd06830dd229a087b358fb` (268, runs38740380225/38740380099); `dc2caed6af465aeb387490d20f1173005a80e3dd` (205, runs38736808948/38736809040); `4a6ea09154b7bc6e8c34b60d2b37c003d85f0369` (160, runs38724339404/38724339420). Первый receipt run38736335930 на04915fab был FAIL из-за порядка fixture budget keys; исправление не ослабляло runtime guard. Это история, не текущий NEXT.

**Main оставлен `7c2e9f8e4501b92469bcdeea18f8340676ad52c9`.** Push main запускает build/publish latest без paths-фильтра; docs туда автоматически не синхронизировать. Это не доказательство auto-deploy, но нежелательный побочный эффект публикации.

Приоритет production fact > live feature code > staging > актуальные docs > старые обсуждения. Сначала live HEAD/CI и связанный код; минимальные изменения. Production/V4, реальные SMS/платежи и опасные миграции без отдельного допуска запрещены. Серверный шаг: один guarded Bash wrapper, syntax check, host/root/path/DB/backup guards, FINAL_STATUS/FINAL_RC, без secrets/PII, терминал не закрывать. Не просить историю и ранее обработанные логи повторно.

Для нового чата: «Продолжаем Evrasia AI Bot / СамЗаберу V5, feature/samzaberu-api-v5-auth-contract. Прочитай START HERE и текущие checkpoint/decisions от15октября. Последний code/test c3f92ba3197157e1cd19f9aa21df3d353862279d, runs38743961486/38743961127 SUCCESS:26 offline suites,329 ephemeral MariaDB checks. Locked password/TOTP begin adapter готов в feature с reuse общего epoch; native Bitrix/replay/hooks/SMS/RestIS не приняты. Реальный staging blocker в issue76; готовый provisioning plan не повторять. Проверь live refs, doc-only HEAD не новое тестирование. Main/V4/production не менять; закрытые source-only аудиты не повторять».
