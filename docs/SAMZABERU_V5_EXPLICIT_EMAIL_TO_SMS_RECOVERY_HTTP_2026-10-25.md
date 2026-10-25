# СамЗаберу API V5: явный переход email OTP → SMS fallback (25.10.2026)

## 25.10.2026 — real native Bitrix FACT PROOFS CONNECTED (GitHub, NOT production)

Owner asked «подключаем». Implemented **`src/V5BitrixEmailSmsStageProof.php`** as the concrete, fail-closed `V5LockedEmailToSmsStageProofPort` for the existing `V5EmailToSmsAtomicStage`. This is **not another paid SMS service or rate limiter**. It reuses:

- `V5BitrixSmsPhoneRows::read(...,true)` + `confirmedDestination` and the **existing** `V5SecurityEventVersions` phone-event stamp, never request phone or `PERSONAL_PHONE`. Current SQL transaction and exact same PDO are required.
- `V5SecurityEventVersions::forLockedPasswordProof()` for the existing keyed 64-hex SMS auth epoch stamp; no new HMAC algorithm/key.
- The **same** `V5BitrixLockedEmailOtpProof::verifiedEmail()` source via new safe `currentVerifiedEmailStampWithUserLock()` (returns ONLY stamp; no email, no bearer token, no new site ownership algorithm).
- Existing `V5ExistingLoginPolicyPort`, `V5NoMfaFallbackPolicyPort`, and **mandatory** `V5NativeSecurityHookCoveragePort`; unknown/false denies.
- `SELECT USER_ID FROM b_sec_user WHERE USER_ID = ? LIMIT 2 FOR UPDATE`: **any** configured MFA row blocks downgrade to SMS. Native confirmed phone is also locked. No website PHP session or Bitrix login.
- `V5EmailToSmsAtomicStage` now selects `LOGIN_ATTEMPTS` with locked user so native login policy is not bypassed. Current underlying stage still does the owned transaction, new SMS row, email CAS and COMMIT, but does not send paid SMS.

**Source-only CI:** [V5 first-factor offline #40185214838](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40185214838) **SUCCESS**, including new `tests/V5BitrixEmailSmsStageProofContractTest.php` PHP syntax and structural safety checks. DOES NOT prove installed MFA hooks, real PDO lock order, current production schema, or external SMS delivery. Generic offline workflow still has the independently known `WebsiteEmailAccountConfirmWidgetClickTest.cjs` failure.

**Unfinished before enabling:** no installed implementation of `V5ExistingWebsiteSmsNativeTransitionPort` that connects the committed stage to the *real* `signin/class.php::issuePilotSmsCode` / `CSms::send`, website per-user quota and authoritative sent receipt; native security event hook coverage is not verified in production; table `eurasia_new.ev_v5_email_auth_challenges` has not been separately approved or created. Never treat existence of `V5BitrixEmailSmsStageProof` as permission to release/dispatch.

**Next operator-only read-only checkpoint:** inspect *metadata* for `ev_v5_email_auth_challenges`, `ev_v5_auth_challenges`, `ev_v5_sms_outbox`, `ev_v5_sms_budget_windows` plus structural use/return semantics of the **already-pinned** live `issuePilotSmsCode` / `CSms::send` without exposing SMS bodies, recipients or secrets. No source code dump, no testing against synthetic users, no message dispatch, no DDL. Wrapper MUST execute terminal clear `printf '\\033[3J\\033[2J\\033[H'` *after its heredoc and immediately before execution/first visible script output*.



## 25.10.2026 — source-only REAL SQL atomic stage, без вызова платного SMS

`src/V5EmailToSmsAtomicStage.php` — следующий точный **исполняемый SQL-кандидат**, но **не** production installation и **не** complete `V5ExistingWebsiteSmsNativeTransitionPort`. В отличие от прежнего pure-policy он содержит реальные PDO-инструкции с ownership, транзакцией, блокировками, подготовкой нового SMS challenge и условной отменой email:

1. Только валидные server-generated `emailChallengeId`, `sz_... deviceId`, `ios/android`; MySQL error-mode exception, отсутствие чужой транзакции и exact InnoDB engines для `b_user`, `ev_samzaberu_auth_state`, `ev_v5_email_auth_challenges`, `ev_v5_auth_challenges`. Любая отсутствующая таблица → fail closed **до** вызова native provider. В production новая email таблица ещё не создана.
2. Indexed email ID point lookup используется только как **подсказка для порядка блокировок**, никогда как доказательство принадлежности. Затем в одном owned SQL scope: `b_user FOR UPDATE` → `V5LockedEmailToSmsStageProofPort::currentFactsWithUserLock()` → locked numeric `AUTH_EPOCH` → locked email row. Приватный provider обязан независимо зафиксировать текущие site MFA/TOTP-OFF, verified phone, current email, password epoch, hook coverage и один физический Bitrix PDO. Никакого дефолтного permissive provider нет.
3. Native callback защищён точным `SAVEPOINT/RELEASE v5_email_sms_stage_guard`. Если Bitrix скрыто завершил транзакцию и потерял SAVEPOINT, bridge карантинируется, новая отправка запрещена.
4. Подтверждение `V5EmailToSmsTransitionPolicy::emailEligible()` по действующему `accepted` email и locked epoch; новый password-proven `kind='sms'` с отдельным ID, тем же пользователем/устройством и **существующим 64-hex `V5SecurityEventVersions` epoch stamp**, `sms_sent_count=0`, `sms_code_hmac=NULL`. Новый TTL не длиннее email TTL (и не более 300 сек). Затем exact CAS `accepted → cancelled, code_hmac=NULL` с `rowCount===1`, и один `COMMIT`. При ошибке/неизвестном COMMIT stage не возвращает разрешение на доставку. Повторная попытка уже cancelled email не проходит. В SQL bridge добавлены **только exact** CAS и два фиксированных savepoint templates; никаких generic email mutations.
5. Возвращает **только** `V5CommittedEmailSmsStage` — внутренний DTO для частного server dispatcher, **не** `PendingLoginChallenge` и **не** JWT. Нет SMS transport/OTP generator/Redis/budgets/website PHP session. Только после гарантированного COMMIT приватный provider может вызвать **уже действующую site-native SMS delivery + индивидуальные антифрод-проверки**, добавить подтверждённый receipt к мобильному challenge и лишь после этого вернуть клиенту pending SMS ticket.
6. Не использовать текущий отдельный candidate `V5MySqlSmsOutbox` как автоматическую замену site SMS budget: он содержит свою отдельную V5 budget-table, которую пользователь не согласовывал. Реальный sender binding должен повторно использовать сайт, не создавать второй лимитер. Нельзя автоматически retry неизвестный платный вызов.

**Проверка:** `tests/V5EmailToSmsAtomicStageTest.php` — source-only PHP reflection allowlist + SQL-shape, lock-order/CAS/no-send, negative PDO stub without account fixtures, **не** real transaction/concurrency E2E. Targeted [Actions #40184843958](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40184843958) **SUCCESS** (коммит `65e11d6e`). Общий V5 offline workflow продолжает отдельно падать на прежнем browser JS `WebsiteEmailAccountConfirmWidgetClickTest.cjs`. Не утверждать, что все CI green.

**STILL NOT DONE:** private `V5LockedEmailToSmsStageProofPort` implementation and `V5ExistingWebsiteSmsNativeTransitionPort` orchestration; independently confirmed paid-provider receipt; real Bitrix transaction/lock-order and race E2E; native security hooks/Auth Epoch coverage for current accounts; owner-approved email ledger CREATE in real `eurasia_new`; production V5 enablement. Browser MFA, V4, Restis not modified.



## 25.10.2026 — одноразовый переход без дополнительной таблицы (SOURCE-ONLY)

**Было:** прежний checkpoint ошибочно формулировал отсутствие `email_challenge_id → sms_challenge_id` как обязательную потребность в новой колонке или отдельном ledger и отмечал несовпадение `AUTH_EPOCH` типов как нерешённое.

**Стало:** различие форматов уже разрешено существующим кодом проекта: `ev_samzaberu_auth_state.AUTH_EPOCH` и `ev_v5_email_auth_challenges.auth_epoch` — общий положительный `BIGINT UNSIGNED`. `ev_v5_auth_challenges.auth_epoch` — `CHAR(64)` с существующим HMAC, который рассчитывает **`V5SecurityEventVersions::forLockedPasswordProof()`** из ТОГО ЖЕ числового epoch, текущего b_user password hash, ID пользователя и серверного authKey. `V5SecurityEventVersions::stillCurrent()` уже проверяет версию на SMS пути. **Новый hash/ключ/таблица/epoch не требуются.** Это source-contract, не production E2E.

Для требования **at most one paid SMS start per original email challenge** самостоятельная cross-reference колонка не обязательно нужна при fail-closed варианте: private native provider под одной физической SQL-транзакцией сначала блокирует `b_user`, общий `AUTH_EPOCH`, проверяет native email/TOTP/phone, затем блокирует `accepted` email challenge; **в той же транзакции** готовит отдельный password-proven SMS challenge с корректным HMAC epoch; после успешной записи одного нового SMS challenge выполняет условную отмену исходного email (`status = cancelled`, `code_hmac = NULL`) с проверкой affected rows **ровно 1**, а затем COMMIT. Если какая-либо стадия не прошла — нет нового SMS challenge, нет права на отправку. Вторая конкурирующая попытка увидит уже отменённый email challenge и не создаст ещё один платный SMS. При сомнительном COMMIT отправка запрещена.

**Важное ограничение:** эта стратегия сознательно НЕ возвращает прежний SMS challenge по повторному запросу и НЕ предполагает автоматический retry при неизвестной оплате. Пользователь может выполнить новый обычный signin; это дешевле/безопаснее, чем дать двум факторам одновременно остаться действующими. Конкретные locks/CAS и использование существующего site-native SMS outbox/CSms требуют отдельной реализации и реальной native проверки; внешняя платная отправка только **после** durable COMMIT/резервирования и с подтверждённым receipt, **никогда** внутри транзакции. Не использовать второй rate-limiter или веб-сессию для мобильного устройства.

**Что реализовано сейчас:** `src/V5EmailToSmsTransitionPolicy.php` — pure locked-row check `emailEligible()` / `stagedSmsEligible()` для обоих уже существующих форматов, а также точный `CANCEL_EMAIL_CAS` contract; сам код **не выполняет SQL/COMMIT/SMS**. `tests/V5EmailToSmsTransitionPolicyTest.php` проверяет корректный email/SMS контекст и отказы при просрочке, использованном challenge, TOTP, иной device binding, ином epoch, отсутствии password proof и уже начатой отправке. `tests/V5EmailSmsSharedEpochContractTest.php` подтверждает привязку к `V5SecurityEventVersions` без второго authority. Целевой [CI #40184301530](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40184301530) **SUCCESS** (source-only, нет пользователей, БД, SMS, Redis, писем). Общий offline workflow продолжает иметь независимый провал JS DOM click теста.

**Чего НЕ сделано:** не установлен private `V5ExistingWebsiteSmsNativeTransitionPort`; не подтверждён атомарный DB E2E/receipt; proposed `ev_v5_email_auth_challenges` не создана, DDL не одобрен; V5 AUTH production OFF, V4/сайт/Restis не затрагивались. Механизм переноса SMS в мобильный API нельзя считать работающим до этих шагов.



## 25.10.2026 — дополнительная обязательная проверка принадлежности challenge к email ledger

После security review source-only V5 HTTP bridge `V5MySqlChallengeHttpPort::requestSmsInsteadOfEmail` больше не пересылает в native SMS provider **любой** 64-hex `challenge_id`. Сначала он проверяет:
1. Установлен отдельно аттестованный `V5ExplicitSmsRecoveryPort` (иначе fail closed).
2. Присутствует именно `V5EmailChallengeVerificationPort` (иначе fail closed).
3. Формат ID; затем `ownsEmailChallenge(id) === true` по primary-key email ledger (без оплачиваемых побочных действий).
4. Лишь после этого native SMS provider получает запрос; **наличие email ID — только preflight, НЕ доказательство авторизации**. Privately installed provider обязан под row locks подтвердить `status=accepted`, текущий `AUTH_EPOCH`, владельца, устройство, отсутствие TOTP, verified phone, антифрод, атомарную смену двух challenge и фактическую доставку.

Нет ledger или произошла SQL-ошибка — ни один SMS provider не вызывается; HTTP boundary скрывает внутреннюю ошибку. Строгий offline тест `V5EmailToSmsLedgerOwnershipTest.php` проверяет отсутствие вызовов paid SMS при malformed/unowned ID, отсутствие SMS после ошибки ledger, positive delegation only after ownership и fail-closed при отсутствии private provider. Source-only [CI #40183917685](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40183917685) — **SUCCESS** для коммита `c5616a72`. Это не подтверждает реальную production SQL/Bitrix интеграцию, доставку и JWT.

**Зафиксировать технический blocker:** предложенная `ev_v5_email_auth_challenges` не хранит самостоятельную связь `email_challenge_id → sms_challenge_id`. Реальный native transition обязан обеспечить атомарное одноразовое переключение через уже существующую надёжную схему/идемпотентное состояние, либо согласовать минимальное дополнительное поле/ledger перед включением; нельзя просто вызвать `CSms::send`, а затем пометить email как `cancelled`. Нельзя держать InnoDB транзакцию во время внешнего платного SMS-вызова или повторять отправку при неизвестном результате; использовать durable outbox/receipt lifecycle, как принято в V5. **Не выполнять DDL без разрешения.** Website fallback 24.10 работает независимо, не менялся.



## 25.10.2026 — source-only V5-адаптер к существующему website SMS

**Что подтверждено live выводом оператора:** `issuePilotSmsCode(int $userId)` является `private` методом `signin/class.php` и вызывает `CSms::send` (один вызов), `enforcePilotSmsRateLimit(int $userId)`, `getPilotProfilePhone(int $userId)`. Сессионная обвязка `emailUseSmsAction` и `evEmailIssueSmsFromVerifiedContextNative` для мобильного API непригодна без изменений. `EmailFirstSmsRatePolicy::begin/check/reserve` и `EmailFirstSmsResendGuard::canIssue/markReserved` принимают `array $session` или `array &$session` и реальный `sessionId` — нельзя строить фиктивные веб-сессии или объявлять их мобильными лимитами.

**Результат в GitHub:** `src/V5ExistingWebsiteSmsRecoveryAdapter.php` реализует ранее утверждённый интерфейс `V5AttestedExplicitSmsRecoveryPort`. Безопасно подключаемый к `V5MySqlPublicComposition::buildEmailFirst()` source-only слой состоит из:
- `V5PinnedWebsiteSmsSourceProof`: по приватным files **только сравнивает SHA256** с четырьмя публично зафиксированными на 25.10.2026 fingerprint; исключает явные symlink; не выводит содержимое и не меняет production. При любом изменении сайта fail closed.
- Строгая проверка **того же физического PDO** при `verifiedExistingSiteRecovery()`.
- `V5ExistingWebsiteSmsNativeTransitionPort` как **обязательная приватная зависимость**, реализуемая владельцем production Bitrix: проверка исходного accepted email challenge, актуального `AUTH_EPOCH`, same device binding, verified phone, TOTP-off, idempotency/replay, уже действующих site anti-abuse и подтверждённого paid delivery; инвалидирует email OTP и выдаёт **новый** мобильный SMS challenge с отдельным ID.
- Повторная проверка всех трёх условий перед каждым `requestSmsInsteadOfEmail()`, не только при старте. Возврат не-SMS challenge, тот же ID, ошибка PHP/Bitrix или изменённый SHA => `AuthenticationRejected('challenge_unavailable')`, без JWT и сообщений об аккаунте.
- **НЕТ новой генерации SMS-кода, собственного транспорта, Redis quota или rate limiter.** Адаптер также НЕ вызывает private методы через Reflection или web PHP-session.

**Граница готовности:** `V5ExistingWebsiteSmsNativeTransitionPort` пока **только интерфейс**: рабочий приватный провайдер ещё НЕ установлен и НЕ E2E-проверен; поэтому реальная отправка мобильного SMS пока не происходит. Pinned SHA **не равен security attestation** и не заменяет проверок native provider. После отдельного разрешения на production change нужно извлечь общий site-native SMS sender/OTP receipt как защищённый серверный сервис, сохранив работу сайта, и реализовать native transition **без копирования приватного исходника в публичный GitHub**. Создание mobile email ledger `eurasia_new.ev_v5_email_auth_challenges` — отдельный DDL approval, которого пока нет.

**CI:** [#40183077325](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40183077325) `first-factor-offline` **SUCCESS**, проверены PHP syntax, source-only отказ/выдача билета только при injected attested native provider, строгий same PDO/файловый SHA/новый SMS ID; нет реального MySQL, веб-сессии, SMS, почты, Bitrix bootstrap или новых пользователей. Общий workflow с известной JS DOM-регрессией отдельно FAIL.



## Update 25.10.2026 — основной V5 email-first НЕ блокируется отсутствием мобильного SMS

Source-only изменения `06b267de`, `5e40a41b`, `60079f13`, `9ad7a13f`, `ca6d46e1`, `40eb6c74`:

- `V5MySqlPublicComposition::buildEmailFirst()` допускает независимый `V5PublicVerifiedTotpLifecyclePort` с обязательным `verifiedPublicTotpLifecycle(PDO $db) === true`. Старый `V5PublicVerifiedFactorLifecyclePort` и legacy `build()` сохраняют комбинированный gate TOTP+SMS. Всё ещё обязательны полные native preflight, email proof, один PDO, Redis budget и остальные необходимые зависимости.
- Без site-native `V5AttestedExplicitSmsRecoveryPort` композиция устанавливает **denial-only** `V5UnavailableSmsLifecycle`, не создаёт и не пересылает SMS. `V5MySqlChallengeConsumption` в этом режиме также отклоняет `kind=sms` после блокировки challenge, **до** native verification. Тем самым SMS-код из другого ранее выпущенного challenge не используется для получения новой мобильной сессии.
- Ручной SMS fallback можно подключить **только** если одновременно подтверждены full SMS/TOTP lifecycle и существующий website recovery provider. Нельзя подменять этот provider веб-сессией, `EmailFirstSmsRatePolicy($session,...)` или вызовом private `issuePilotSmsCode` через reflection.
- Это НЕ означает запуск V5 в production и не означает поддержку SMS в мобильном приложении. `ev_v5_email_auth_challenges` отсутствует по последней аттестации, её CREATE требует отдельного разрешения. Фабрика, native trust и runtime E2E не включены/не подтверждены.
- [Source-only CI #40181890121](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40181890121) — **SUCCESS** для новой TOTP-only композиции, негативных SMS HTTP проверок и PHP lint. Отдельный общий CI остаётся FAIL на старом JS DOM event тесте, не объявлять всё зелёным.



## Дополнение: проверка видимости и сессионной зависимости (production READ-ONLY)

По выводу оператора `ev-v5-sms-boundary`: `FINAL_STATUS=PASS`, `FINAL_RC=0`, **18 методов** исследованы статически на production, PHP lint/source SHA PASS, никаких вызовов БД/Redis, SMS/email, PHP Bitrix bootstrap и никаких production writes.

- `emailUseSmsAction` — `public`, но **не независимый API**: напрямую использует `$_SESSION`, `$USER`, `check_bitrix_sessid`; вызывает `EmailFirstSessionBridge::switchToSms` и внутреннюю отправку. **Не использовать как V5 endpoint**.
- `evEmailIssueSmsAfterReservation`, `evEmailIssueSmsFromVerifiedContext`, `evEmailIssueSmsFromVerifiedContextNative` — **private instance methods**, все содержат `$_SESSION`. Последний непосредственно вызывает `issuePilotSmsCode`. Не пытаться вызвать через reflection/обход visibility, не подставлять искусственную PHP-сессию.
- `issuePilotSmsCode` — `private`, непосредственно **не** ссылается на `$_SESSION`, вызывает `getPilotProfilePhone`, `enforcePilotSmsRateLimit` и существующий `CSms`. Это **точка для возможного извлечения общего site-native сервиса**, а не готовый публичный метод. `getPilotProfilePhone` и `enforcePilotSmsRateLimit` тоже private и не содержат прямого session access.
- `EmailFirstSmsRatePolicy`: `begin`, `check`, `reserve` — `public static` и не содержат прямого `$_SESSION`; `EmailFirstSmsResendGuard::canIssue` / `markReserved` — `public static`, проверка повторных отправок. Их **семантика и типы аргументов пока не аттестованы**, нельзя объявить их общим мобильным бюджетом без анализа входных данных.
- `EmailFirstSessionBridge::switchToSms` — `public static`, но принимает девять аргументов и является мостом **веб-состояния**, а не доказанным мобильным OTP proof.
- Вывод старого статического парсера о `EmailFirstSmsResendGuard::mark` (public_default) **не считать достоверной сигнатурой**: требуется корректная проверка структуры токенов/классов при необходимости.

**Решение интеграции:** V5 HTTP route уже существует, но без подтверждённого native provider **остаётся отказным**. Не переносить серверную веб-сессию, не обращаться к private methods через reflection, не дублировать `CSms`, генерацию OTP или rate-limiter. Следующий минимальный шаг — получить **только структурные сигнатуры/типы аргументов и форму фактического вызова CSms** из живого source (без строк, телефонов, secret values) и выбрать общий site-native transport / guard boundary. Отдельное разрешение на production PHP change требуется до любой экстракции или deployment; DDL `eurasia_new.ev_v5_email_auth_challenges` отдельно согласовывается.

## Реальная production-инвентаризация — 25.10.2026 (READ-ONLY, operator PASS)

Подтверждён действующий **сайт** (не приложение) и версия файла `local/components/eurasia/signin/class.php`: SHA256 `3df2c4d31ecf765a2d0bfcb4d5181aa9f51734d901872bf4ed93a07a6601817f`. Следующие методы обнаружены статически, **это НЕ подтверждение их публичной callable-сигнатуры или отсутствия привязки к браузерной сессии**:

| Existing production site method | Source / line |
| --- | --- |
| `emailUseSmsAction` | `local/components/eurasia/signin/class.php:2868` |
| `evEmailIssueSmsAfterReservation` | `local/components/eurasia/signin/class.php:3179` |
| `evEmailIssueSmsFromVerifiedContext` | `local/components/eurasia/signin/class.php:3268` |
| `evEmailIssueSmsFromVerifiedContextNative` | `local/components/eurasia/signin/class.php:3322` |
| `issuePilotSmsCode` | `local/components/eurasia/signin/class.php:1849` |
| `enforcePilotSmsRateLimit` | `local/components/eurasia/signin/class.php:1941` |
| `getPilotProfilePhone` | `local/components/eurasia/signin/class.php:1988` |
| `EmailFirstSessionBridge::switchToSms` | `local/php_interface/website-mfa/EmailFirstSessionBridge.php:163` |
| `EmailFirstSmsRatePolicy` | `local/php_interface/website-mfa/EmailFirstSmsRatePolicy.php` |
| `EmailFirstSmsResendGuard::rate` | `local/php_interface/website-mfa/EmailFirstSmsResendGuard.php:17` |

Additional installed source hashes (no private PHP copied): `EmailFirstSessionBridge.php` SHA256 `c7aa693699afee63ef1a2eab3eaf37d3c02c81acb08e36dd2b507f08f9e7c6e3`; `EmailFirstSmsRatePolicy.php` SHA256 `3ff5575e900dbe1e5fc9209d20d971b74d30b467d05c836f9eb646b00b0c33cb`; `EmailFirstSmsResendGuard.php` SHA256 `8bb27718b0708c3ba024beea1b4b5371ce8a0c55f8b6ab747e9d08c8c7284f99`. Operator reported `FINAL_STATUS=PASS`, `FINAL_RC=0`, no DB/Redis/network, SMS or code writes. Real website SMS acceptance was already confirmed 24.10.2026.

**Important reuse boundary:** `emailUseSmsAction` is a Bitrix AJAX/session action; do **not** call it from mobile V5 using web cookies or infer user ID from an untrusted request. `evEmailIssueSmsFromVerifiedContextNative` is a **candidate** for reuse, not yet proven callable outside that component. Next targeted read-only checkpoint must determine method visibility/signature and references to `$_SESSION`, `$USER`, verified context, confirmed phone and existing rate guards **without exporting private source**. Do not add a duplicate V5 SMS generation/delivery/limiter. In particular, the independent feature-only `V5SmsChallengeFactory`/`V5SmsReservedDispatcher` should not be deployed as a replacement for the already accepted website delivery implementation.

**Production V5 AUTH stays OFF.** `eurasia_new.ev_v5_email_auth_challenges` still requires separate approval for CREATE and is not automatically authorized by the user's «дальше». Parallel Restis bonus diagnostics are a separate workstream.



**Статус:** source-only HTTP boundary. **Не активировано на production.** Никакая SMS/OTP реализация не дублирована.

## Зачем нужен отдельный V5 HTTP route

Политика владельца подтверждена: **сильное доверенное устройство → native TOTP → подтверждённый email OTP; SMS только по явно выбранному восстановлению**. Сайт уже реализовал работающую ручную кнопку email → SMS, но её PHP-сессионный AJAX endpoint нельзя считать API мобильной JWT-сессии. В V5 до этого коммита был только `/auth/challenge/resend`, который обслуживает **уже начатый SMS challenge**, а не переход с email на SMS.

Добавлен V5-only `POST /api/v5/auth/challenge/use-sms`, принимающий только следующий JSON:

```json
{
  "challenge_id": "<64 lowercase hex символа ID ранее выданного EMAIL challenge>",
  "device_id": "<существующий валидный sz_-ID установленного приложения>",
  "platform": "ios"
}
```

`platform` может быть `ios` или `android`. Нет полей phone, email, user_id, password, login, trust_token, method, issuer, rate_limit или site cookie. Запрос принимается только через существующий V5 HTTP boundary (HTTPS/POST/application-json/строгий путь/no-store/без query string).

**Если recovery разрешён существующими server-side доказательствами**, серверный provider возвращает `202`, `status=challenge_pending`, `challenge_type=sms`, **новый** `challenge_id`, `expires_in`. Это **не JWT**. При вводе доставленного SMS-кода приложение вызывает уже существующий `POST /api/v5/auth/challenge/verify` с новым SMS challenge. Верификация обязана пройти existing SMS/TOTP consumption, verified phone, AUTH_EPOCH и strong V5 grant checks. Если recovery не разрешён, исходный challenge недействителен, фактор TOTP обязателен или native provider отсутствует — **нет отправки, нет JWT**.

## Недостающий, намеренно НЕ реализованный private provider

`V5ExplicitSmsRecoveryPort::requestSmsInsteadOfEmail(emailChallengeId, deviceId, platform)` — только интерфейс делегирования, а **не** новый алгоритм SMS, ограничения, SQL-таблица или транспорт.

Для будущего private Bitrix-adapter строго необходимо:

1. На том же физическом Bitrix/SQL transaction проверить, что исходный email challenge **существует**, относится к этому устройству/платформе и пользователю, был создан только после правильного пароля, имеет `status=accepted`, TTL не истёк и `AUTH_EPOCH`/email ownership stamp остались актуальны.
2. Проверить, что current native TOTP **не активен** и действующая политика сайта действительно допускает **явный SMS recovery** этому пользователю. Нельзя принимать client `phone` или трактовать отсутствие email как безусловное разрешение на SMS.
3. Использовать уже работающие **сайт-native SMS challenge/transport, verified-phone, антифрод и per-user rate limits**, без второго алгоритма, без нового global blocking cap и без новой rate-table. Не заявлять, что существующий веб-метод с PHP cookie уже обеспечивает мобильное device/epoch-proof.
4. Под row locks/idempotency инвалидировать или supersede email challenge и выдать **новый** мобильный SMS challenge ID (не равный email ID), с уже проверенными SMS receipt и code lifecycle. Если невозможно сделать атомарный переход, **deny**; нельзя оставить возможность одновременно использовать оба challenge или потратить SMS-ресурс повторно на гонке.
5. Ошибка SMS provider/Redis/Bitrix/базы — fail closed, без автоматического переключения и без вывода внешних ответов, OTP, телефонов, ключей.
6. До согласования отдельной миграции `eurasia_new.ev_v5_email_auth_challenges`, native-provider, реального E2E и V5 enable — **V5 AUTH OFF**.

**Дополнительный compile-time guard:** optional provider в `buildEmailFirst()` теперь имеет тип `V5AttestedExplicitSmsRecoveryPort`, который расширяет `V5ExplicitSmsRecoveryPort` и обязан положительно пройти `verifiedExistingSiteRecovery(PDO $db) === true` до сборки HTTP boundary. `null`, отрицательный ответ и исключения запрещают привязку; ни один из этих путей не отправляет SMS. Положительная оценка provider всё ещё **не является** реальной Bitrix E2E и требует независимой ревизии установленного кода. Source-only тест `V5MySqlPublicCompositionTest.php` проверяет отказ при отсутствии этой аттестации.

**Текущий GitHub-код**: `V5RouteMap.php`, `V5AuthHttpHandler.php`, `V5LoginWorkflow.php`, `V5MySqlChallengeHttpPort.php`, `V5MySqlPublicComposition.php`. `buildEmailFirst()` принимает optional `V5ExplicitSmsRecoveryPort` последним аргументом; при отсутствии provider route отклоняется. Если он передан, обязательна отдельная positive attestation. Legacy `build()`, существующий `resendSms()`, рабочий сайт, V4 и Restis не изменены.

**Source-only CI:** `tests/V5ManualSmsFallbackHttpTest.php` и `tests/V5MySqlPublicCompositionTest.php` проверяют именно boundary, методы, отсутствие destination override и fail-closed. Их mock pending ticket **не подтверждает фактическую отправку SMS**. Без БД, пользователей, Redis подключения или реальных сообщений.

**Отдельное согласование DDL остаётся необходимым:** см. `SAMZABERU_V5_EMAIL_LEDGER_PRODUCTION_MIGRATION_APPROVAL_2026-10-25.md`. Само сообщение «дальше» его не заменяет.
