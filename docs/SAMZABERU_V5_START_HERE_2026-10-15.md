# Evrasia AI Bot / СамЗаберу V5 — START HERE / handoff на 15 октября 2026

**ЭТО ГЛАВНАЯ ТОЧКА ПРОДОЛЖЕНИЯ ПРОЕКТА.** Новый чат — не новый проект. Читать этот файл первым; старые NEXT в исторических документах не выполнять повторно.

Текущий [checkpoint](SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md), [реестр интеграционных решений](SAMZABERU_V5_INTEGRATION_DECISIONS_2026-10-15.md), [готовый план отдельного Bitrix staging](SAMZABERU_V5_STAGING_PROVISIONING_PLAN_2026-10-15.md). Полная предыдущая редакция этого START HERE сохранена в Git commit `7f4de5a459148d5e12a36a376e25aeebb1ef5b0f`; исторические исследования остаются в документах раздела 5.

## 1. Текущий итог и граница подтверждения

Repo `juvantusik/Evrasia_AI_bot`, V5 source-of-truth branch **`feature/samzaberu-api-v5-auth-contract`**. Реализованы TOTP > strong trust > bound SMS, независимые V5 JWT/refresh и InnoDB strong sessions, password-bound TOTP begin, одноразовое SMS/TOTP consumption, зашифрованная SMS-очередь, 10-scope финансовые лимиты, single paid dispatch claim, обязательная независимая SENT-квитанция и поздняя сверка UNKNOWN→SENT без повторной отправки; PIN без второй SMS; строгая HTTP-граница и кандидаты Bitrix adapters.

**Последний инкремент — исправлен реальный разрыв совместимости SMS с существующим Bitrix-мостом.** `V5BitrixPdoCompatibilityBridge` ранее отклонял все четыре используемых SMS SELECT по `delivery_id`; теперь принимает четыре точных шаблона, а не произвольные запросы. `V5MySqlSmsDispatchClaims` раньше записывал результат через UPDATE вне собственной транзакции, запрещённый мостом. Теперь обычный и поздний ACK используют owned BEGIN→CAS UPDATE→COMMIT и возвращают успех только после COMMIT. Receipt lookup выполняется ДО этой транзакции. Чужая транзакция не присваивается и не откатывается.

**Это НЕ работающий публичный V5 и НЕ настоящая интеграция Bitrix.** Новые испытания используют реальную MariaDB через синтетический Bitrix API facade; настоящий Bitrix SqlHelper/ORM/native hooks не проверены. Физически отдельный Bitrix staging всё ещё UNATTESTED, SMS provider API и RestIS PIN не подключены. Production/V4 не менялись, реальные SMS не отправлялись, production SQL не выполнялся. HTTP 503 — последний документированный baseline, не свежий HTTP-тест.

## 2. Актуальный GitHub / CI

- **Последний code/test commit с обеими успешными CI:** `8c395f0320f82a1c69bd06830dd229a087b358fb`.
- **Offline:** [Actions 38740380225](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38740380225), job `116440231373`, SUCCESS. Сохранены 26 approved offline suites; новый PHP integration test также linted. Production sources/Bitrix bootstrap не используются.
- **Настоящая одноразовая MariaDB/InnoDB:** [Actions 38740380099](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38740380099), job `116440233787`, SUCCESS. Прочитанный лог: 43 session assertions + 117 SMS + 45 reconciliation + **63 bridge = 268**; combined SMS 225, FINAL_STATUS=PASS, FINAL_RC=0.
- Новый test подтверждает claim→independent receipt→committed SENT→one-use SMS consumption через bridge и фактический SQL, поздний ACK, проверку сохранённого результата вторым MySQL connection, откат при ошибке записи/COMMIT, отсутствие ложного успеха при потерянном ответе уже выполненного COMMIT, сохранение чужого scope, точный SQL allowlist. `BITRIX_FACADE=SYNTHETIC_ONLY`, `ACTUAL_SMS_SENT=NO`, `REAL_BITRIX_STAGING_TESTED=NO`.
- Compare `7f4de5a..8c395f0`: ровно 4 файла — bridge (+24), dispatch store (+37/-13), новый integration test (+276), ephemeral workflow (+6/-4). DDL, V4, HTTP routes и deployment не менялись.
- Локально baseline blob SHA обоих runtime файлов проверены; PHP lint PASS. Отдельно воспроизведено: старый bridge принимает 0/4 нужных SMS-шаблонов, новый 4/4. Полная DB-проверка проведена в GitHub CI, не в локальном контейнере и не на сайте.
- **История:** `dc2caed6af465aeb387490d20f1173005a80e3dd`, runs `38736808948` / `38736809040`, 205 assertions — предыдущий успешный receipt checkpoint. Первый run `38736335930` на `04915fab` FAIL из-за порядка бюджетных ключей новой fixture; исправление не ослабляло runtime guard. Ещё более ранний `4a6ea09154b7bc6e8c34b60d2b37c003d85f0369`, runs `38724339404` / `38724339420`, 160 assertions — исторический baseline.
- После записи документации HEAD изменяется только docs. Проверять live ref и diff от **`8c395f0`**, не выдавать doc-only commit за новое тестирование кода.
- **Main не трогать:** последний подтверждённый HEAD `7c2e9f8e4501b92469bcdeea18f8340676ad52c9`. Его push запускает build/publish `latest` без paths-фильтра, поэтому автоматическая синхронизация docs отложена. Это не доказательство auto-deploy; публикация уже нежелательна без отдельного решения.

## 3. Принятая бизнес-логика — не переутверждать

1. Логин+пароль проверяются сервером без `CUser::Login`, V4-cookie или PHP login. Учитываются текущие блокировки/активность/expiry, Anti-Fraud и лимиты попыток; клиентскому user_id, телефону и proxy-заголовкам не доверять.
2. При native TOTP после пароля обязателен authenticator code; **fallback на SMS запрещён**, включая ошибки/недоступность TOTP.
3. Без TOTP вход без SMS разрешает только **сильный V5 trusted device**, не слабая V4 запись.
4. Без TOTP и strong trust — один password-bound SMS challenge на подтверждённый телефон аккаунта. Номер с клиента не запрашивается. После сильного входа бонусный PIN доступен без повторной SMS.
5. SMS: 10 rate/стоимостных окон, cooldown, ограничения ошибок и отправок, HMAC code и encrypted envelope, привязки user/device/phone/epoch, provider tariff/currency/idempotency. Claimed/unknown не отправлять автоматически повторно. Late receipt не создаёт расход/отправку, не возвращает бюджет и не оживляет challenge.
6. `PHONE_EVENT_VERSION` меняется при успешном native phone verification, включая повторное подтверждение **того же E.164**, и соответствующих revoke/replace. `AUTH_EPOCH` меняется атомарно с native security mutations в той же физической транзакции; старые сессии и доказательства недействительны.
7. Согласованные V4/UI/API/архитектуру не переделывать.

Предложенные POST routes: `/api/v5/auth/signin`, `/api/v5/auth/challenge/verify`, `/api/v5/auth/challenge/resend`, `/api/v5/auth/refresh`, `/api/v5/loyalty/pin`. Route map не означает включённый router. Reconciliation — внутренний store API, не новая публичная ручка.

## 4. Реализация и незавершённые стыки

Все пути относительно `site-integration/samzaberu-v5/`.

| Домен | Файлы / реальная граница |
|---|---|
| Auth/policy | `V5LoginWorkflow.php`, `AuthDecision.php`, `V5ExistingLoginPolicy.php`, `V5NoMfaFallbackPolicy.php`, `V5NativeTotpStatus.php`; приоритет факторов реализован, native acceptance нет. |
| Password/attempt | `V5BitrixPasswordProofPort.php`, `V5FirstFactorAttemptGate.php`, `V5SiteAccountReader.php`, `V5MySqlAttemptWindowStore.php`; нужны реальные resolver, IP-context, Anti-Fraud. |
| TOTP begin | `V5MySqlPasswordBoundTotpIssuer.php`: b_user + epoch locks, обязательная текущая password/TOTP recheck, TTL до 300 секунд, cap 20 pending, ticket после COMMIT; native provider не установлен. Legacy begin(userId) запрещён. |
| Consume/grant | `V5MySqlChallengeConsumption.php`, `V5MySqlChallengeHttpPort.php`, `V5MySqlConsumedChallengeProof.php`; one-time, пять ошибок→cancel, SENT-only SMS proof, fresh grant recheck и UNIQUE session grant. **HTTP SMS begin/resend выключены** без проверенного lifecycle. |
| SMS budget | `V5SmsDispatchBudget.php`, `V5MySqlSmsOutbox.php`, `V5BitrixLockedSmsEligibility.php`; encrypted outbox/10 quotas/CAS/rollback. Реального SMS нет. |
| Dispatch/receipt | `V5MySqlSmsDispatchClaims.php`, `V5SmsSentReceiptAttestationPort`; claim once, independent receipt и late reconciliation. **Новое:** обе записи результата только через owned COMMIT; provider lookup вне транзакции. Реальный paid worker/provider не подключены. |
| Sessions/PIN | `V5SessionTokens.php`, `V5MySqlStrongSessionStore.php`, `V5MySqlSessionActions.php`; независимые keys/issuer/audience, JWT после COMMIT, refresh rotation/revocation. `V5LockedBonusPinReaderPort`: PIN в session transaction без SMS; RestIS reader не установлен. |
| HTTP | `V5AuthHttpHandler.php`, `V5RouteMap.php`, `V5HttpTransportBoundary.php`, `V5PhpHttpRequestFactory.php`; HTTPS/POST/JSON/limits/no-cache/proxy guards, default 503. Site router не активирован. |
| Bitrix/security | `V5BitrixPdoCompatibilityBridge.php`, `V5StagingNativeTransactionPermit.php`, `V5VerifiedBitrixNativeTransactionDepth.php`, `V5SecurityEventVersions.php`, `V5NativeMutationCoverage.php`; новое exact outbox SELECT allowlist, но настоящий ORM/SqlHelper/10-path native proof не проверены. Не считать весь legacy SQL guard универсальным строгим allowlist: точные списки выделены для sessions и SMS reads. |
| DDL | `schema/PROPOSED_*.sql` — proposed/CI fixtures, не staging/prod deployment. Текущий инкремент не меняет схему. |

CI entrypoint SMS: `tests/integration/V5EphemeralMySqlSmsBridgeTest.php` включает reconciliation→outbox suites ОДИН раз, затем 63 новых assertions. Не запускать эти synthetic-schema CI scripts в native Bitrix DB. Native site acceptance имеет отдельные entrypoints.

## 5. Закрытые production исследования — НЕ повторять

Production Bitrix host `evrasia`, docroot `/home/site_evrasia/web/evrasia.spb.ru/public_html`; бот на **другом** `eur-bot-01`. Ни один не использовать как staging.

`PinCode.php` и `PhoneAuth.php` check имеют разные lexical owners; caller/receiver/class/namespace/core phone probes закрыты. `UserPhoneAuthTable.php` SHA `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991`, `CONFIRMED` default N. `CUser::Update` line3920: delete4098 с переменной, последнее распознанное присваивание3925; conditional add4102 под if4101 с USER_ID/PHONE_NUMBER, без inline CONFIRMED. Это не native successful verification writer, не dominance/transaction proof.

`user.php` observed SHA `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99` отличается от historical `542034ca4eee2c30bdc033f45d792945c10392aeaf2e482c01958e2bd585b9d0`. Причина drift неизвестна, новый baseline НЕ утверждён, автоматически файл не исправлять. Исторические source-only parser 4/4 и wrapper 5/5 PASS не являются новыми тестами.

OTP/recovery: save очищает recovery до UserTable::add; delete вызывает after-event после SQL; OtpEvents — дополнительный writer. `useCode()===true` не доказывает single-use CAS. 10/10 mutation coverage не подтверждена.

История: `EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-14.md`, `SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md`, `STRUCTURE_REGISTRY.md`, `SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md`, `SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md`, `SAMZABERU_V5_NATIVE_MUTATION_COVERAGE_2026-10-12.md`. Это архив, не новые команды production-аудита.

## 6. Что блокирует реальную интеграцию

Отдельная VM/Bitrix/MySQL/storage/network/secrets не аттестованы. Это отсутствие подтверждения в доступных материалах, не утверждение, что VM нигде нет. План уже готов; хост/гипервизор/адрес/права создания не угадывать. Реальные native password/TOTP/replay/strong trust/Anti-Fraud, phone verification/reconfirmation и атомарные AUTH_EPOCH/PHONE_EVENT_VERSION по десяти mutation paths не установлены/не приняты. SMS password-bound begin/resend, paid worker, provider tariff/idempotency/receipt и RestIS PIN не подключены. Proposed schemas не развёрнуты; pilot не разрешён.

**Статусы:** OFFLINE_TESTED=PASS; REAL_EPHEMERAL_MARIADB_PASS=YES (268); REAL_BITRIX_STAGING_TESTED=NO; REAL_SMS_PROVIDER_TESTED=NO; REAL_RESTIS_PIN_TESTED=NO; PRODUCTION_DEPLOYED=NO.

## 7. Следующий шаг

Проверить live refs/CI и только следующий незавершённый стык, не начинать V5 заново. Выполненные bridge fixes не повторять. Для настоящего native этапа нужен отдельный хост по уже готовому provisioning plan: Stage A изоляция ДО Bitrix PHP; затем существующий `V5BitrixNativeDepthReadOnlyPreflight.php`; после отдельного допуска synthetic native probe DDL/rollback/concurrency и десять security writer proofs. Далее native providers, SMS lifecycle/provider receipt, RestIS, staging E2E; только затем обсуждение пилота. CI MariaDB и синтетический Bitrix facade этот допуск не заменяют.

Все безопасные GitHub feature/CI операции выполнять самостоятельно; останавливать production, реальные SMS/платежи и опасные миграции до отдельного допуска. Для серверного действия — один guarded Bash wrapper, `bash -n`/PHP lint, host/root/path/DB guards, структурированный FINAL_STATUS/FINAL_RC, без секретов, терминал не закрывать. Нельзя подключать production DB или запускать proposed DDL без точной согласованной цели, backup и rollback.

## 8. Документация и правила продолжения

Приоритет production fact > live feature code > staging > актуальные docs > старые обсуждения. Обновлять START HERE, текущий checkpoint и integration decisions после существенных изменений; main автоматически не синхронизировать. Не подменять недоказанные native/provider факты синтетикой. HTTP503 остаётся историческим baseline.

Текст для нового чата: «Продолжаем Evrasia AI Bot / СамЗаберу V5, repo juvantusik/Evrasia_AI_bot, feature/samzaberu-api-v5-auth-contract. Прочитай START HERE и текущие checkpoint/decisions/provisioning plan от 15 октября. Последний code/test commit 8c395f0320f82a1c69bd06830dd229a087b358fb, CI 38740380225 / 38740380099 SUCCESS: 26 offline suites, 268 real ephemeral MariaDB assertions, включая 63 SMS bridge tests. SMS reads и owned ACK COMMIT исправлены; настоящий Bitrix/SMS/RestIS/staging всё ещё не аттестованы. Проверь live refs; doc-only HEAD не новое тестирование. Main/V4/production не менять, закрытые source-only проверки не повторять. Продолжай следующий безопасный интеграционный блок, не проси пересказывать историю».
