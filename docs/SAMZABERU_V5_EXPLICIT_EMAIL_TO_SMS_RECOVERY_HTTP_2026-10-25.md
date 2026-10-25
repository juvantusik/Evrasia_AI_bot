# СамЗаберу API V5: явный переход email OTP → SMS fallback (25.10.2026)

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
