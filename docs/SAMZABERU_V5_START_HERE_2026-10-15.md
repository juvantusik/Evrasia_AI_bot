# Evrasia AI Bot / СамЗаберу V5 — START HERE / handoff на 15 октября 2026

**ЭТО ГЛАВНАЯ ТОЧКА ПРОДОЛЖЕНИЯ ПРОЕКТА В НОВОМ ЧАТЕ.** Новый чат — не новый проект. Читать этот файл первым. Старые многостраничные checkpoint/registry-документы содержат историю и устаревшие NEXT-шаги: не начинать диагностику с нуля и не повторять закрытые проверки.

## 1. Текущий итог и статус

В репозитории `juvantusik/Evrasia_AI_bot`, ветка **`feature/samzaberu-api-v5-auth-contract`**, реализована отдельная, fail-closed серверная модель авторизации СамЗаберу API V5: TOTP > strong trusted device > bound SMS, V5 JWT/refresh, InnoDB strong sessions, password-bound TOTP challenge, SMS/TOTP one-time consume, зашифрованная SMS-очередь, финансовые лимиты, single paid dispatch claim и независимый provider-SENT receipt gate; защищённый показ бонусного PIN без второй SMS; строгая HTTP-граница V5; кандидаты адаптеров Bitrix.

**Это код feature и успешно протестированные offline/одноразовые CI БД, но НЕ deployed working API:** настоящий Bitrix+MySQL staging физически не аттестован, нативные MFA/phone/password/epoch proof-провайдеры не интегрированы, SMS provider API и RestIS PIN не подключены. V4/production не менялись, реальные SMS не отправлялись, production SQL не выполнялся. Публичный V5 остаётся выключенным, **HTTP 503 — последний документированный baseline, а не новый live HTTP тест**.

## 2. Проверенные GitHub идентификаторы — на них опираться

- Repo: `juvantusik/Evrasia_AI_bot`. V5 source-of-truth branch: `feature/samzaberu-api-v5-auth-contract`. `main` — историческая кодовая ветка и общая документация; не использовать как V5 code branch.
- **Последний code commit с успешным обеими CI:** `4a6ea09154b7bc6e8c34b60d2b37c003d85f0369`.
- До записи этого файла наблюдались feature HEAD `7df444dac349f0a4eb919663b0f683e8f0aa49d4` и main HEAD `654bb87534930b4152a92cf277cf1a559a15cbaf`. GitHub compare `4a6ea..7df444` показал **только четыре doc-only коммита**, без новой кодовой дельты. После создания handoff HEAD **изменятся**; в следующем чате обязательно перечитать live HEAD/CI, не подменять их историческими SHA.
- Последняя **V5 offline safety** [Actions 38724339404](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38724339404): `SUCCESS`, **26/26 offline suites**, `FINAL_STATUS=PASS`. Среди них synthetic Bitrix+HTTP E2E 193 assertions; HTTP transport 147 assertions.
- Последняя **реальная временная MariaDB 11.4/InnoDB** [Actions 38724339420](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38724339420): `SUCCESS`, **43 strong-session/HTTP + 117 SMS/TOTP/dispatch/receipt = 160 assertions**, `FINAL_STATUS=PASS`, `FINAL_RC=0`. Доказана реальная InnoDB atomicity, concurrent single-use refresh, SMS first-send/paid dispatch claim races с отдельными OS processes. Native Bitrix/фактической SMS доставки/реального телефона в тесте **НЕ было**.
- Старые успешные прогоны есть в GitHub и docs; последние 38724339404 и 38724339420 их supersede.

## 3. Принятая бизнес-логика (не переутверждать)

1. Вход: логин+пароль; сервер **без установки V4 PHP/Bitrix login** проверяет актуальную личность/пароль, активность, блокировки Anti-Fraud, native 2FA и лимиты первой попытки. Не доверять клиентскому `user_id`, номеру телефона, `X-Forwarded-For`.
2. При подключённой **TOTP** после пароля обязателен код аутентификатора; **fallback на SMS запрещён** при неверном/недоступном TOTP. После входа PIN/бонусы доступны без второй SMS.
3. Когда TOTP отключён, допускается только **новое сильное V5 trusted device**, не слабая старая V4 trust запись; сильный trust позволяет вход без SMS, а PIN — без повторной SMS.
4. Без TOTP и trusted device — один серверный **SMS challenge на подтверждённый номер из аккаунта**; клиент **не вводит телефон**. Успешный код даёт strong V5 session; после этого PIN без второго SMS.
5. Защита от SMS-спама: десять rate/стоимостных окон, cooldown, лимит попыток и отправок, криптографическая привязка к challenge/account/device/phone-верификации/`AUTH_EPOCH`, HMAC SMS-кодов и зашифрованный outbox, проверка стоимости и currency перед отправкой, provider idempotency; отсутствие повторов при неизвестном платном результате.
6. **`PHONE_EVENT_VERSION` должен увеличиваться при успешном native phone verification, включая повторное подтверждение ТОГО ЖЕ E.164**, а также соответствующих revoke/replace. **`AUTH_EPOCH` должен атомарно изменяться в одной физической native Bitrix/InnoDB transaction** со всеми security/MFA/password/logout событиями; старые V5-сессии/доказательства немедленно теряют силу.
7. Существующий V4 сохранить, никаких незапрошенных архитектурных/UI изменений.

Предложенные пути в `src/V5RouteMap.php`: POST `/api/v5/auth/signin`, `/api/v5/auth/challenge/verify`, `/api/v5/auth/challenge/resend`, `/api/v5/auth/refresh`, `/api/v5/loyalty/pin`. **Route map не означает включённый router**.

## 4. Готовый код: реализация vs реальная интеграция

Все пути ниже относительно `site-integration/samzaberu-v5/`.

| Реализованный домен | Основные файлы и граница подтверждения |
|---|---|
| Auth workflow и приоритеты | `src/V5LoginWorkflow.php`, `AuthDecision.php`, `V5ExistingLoginPolicy.php`, `V5NoMfaFallbackPolicy.php`, `V5NativeTotpStatus.php`: логика TOTP/trust/SMS, fail closed; реальное native MFA proof ещё не проверено. |
| Пароль и rate limiting | `V5BitrixPasswordProofPort.php` (кандидат native comparator без login-cookie), `V5FirstFactorAttemptGate.php`, `V5SiteAccountReader.php`, `V5MySqlAttemptWindowStore.php`: нужны реальные account resolver, IP-context, Anti-Fraud и staging acceptance. |
| **Самое новое: TOTP challenge begin** | `src/V5MySqlPasswordBoundTotpIssuer.php`: внутри единой InnoDB transaction блокируется `b_user`, читается общий epoch, обязателен **fresh current password + native TOTP recheck под той же блокировкой**, до 300 сек, до 20 pending; возвращает ticket только после COMMIT. Старый `begin(userId)` запрещён. Site-native verifier всё ещё не подключён. |
| Verification | `V5MySqlChallengeConsumption.php`, `V5MySqlChallengeHttpPort.php`, `V5MySqlConsumedChallengeProof.php`: SMS/TOTP one-time consume, 5 неверных попыток→cancel, strong session после повторного proof и DB UNIQUE grant digest; SMS-код принимается только от положительно подтверждённого `sent` outbox. **SMS begin/resend всё ещё отключены без trusted lifecycle provider.** |
| Очередь и бюджет SMS | `V5SmsDispatchBudget.php`, `V5MySqlSmsOutbox.php`, `V5BitrixLockedSmsEligibility.php`: 10-scope quotas, encrypted message, code HMAC, rollback, resend cooldown. Реальная БД CI PASS, настоящей отправки нет. |
| **Самое новое: платный SMS dispatch** | `src/V5MySqlSmsDispatchClaims.php`: DB-only `queued → claimed → sent/failed/unknown` с единственным claim/конкурентной CAS, повторной native phone/MFA/epoch и provider tariff/currency проверкой. `claimed/unknown` нельзя автоматически повторно отправлять (риск двойной оплаты). Реальный paid SMS worker/provider API **не реализован**. |
| **Самое новое: SENT receipt** | Интерфейс `V5SmsSentReceiptAttestationPort` обязателен, прежде чем `recordProviderOutcome(..., Sent)` изменит запись: без **независимой** подтверждённой provider квитанции статус остаётся не-`sent`, код не является SMS-proof. В CI injected receipt stub **СИНТЕТИЧЕСКИЙ** и не подтверждает реальную оплату/доставку. |
| Сессии/JWT | `V5SessionTokens.php`, `V5MySqlStrongSessionStore.php`, `V5MySqlSessionActions.php`: отдельные ключи/issuer/audience от V4, JWT только после COMMIT, opaque refresh digests, CAS rotation, revocation, device/grant HMAC, epoch. Реальный native 10-path security proof не реализован. |
| Показ бонусного PIN | `V5LockedBonusPinReaderPort`, `showBonusPinForAccess()`: текущая revocation/epoch/assurance + PIN read в одной locked transaction, **без второй SMS**. Реальный RestIS PIN-port нет, только синтетический CI reader. |
| HTTP | `V5AuthHttpHandler.php`, `V5RouteMap.php`, `V5HttpTransportBoundary.php`, `V5PhpHttpRequestFactory.php`: строгие HTTPS/POST JSON, размер, точный path, anti-cache, 503 by default, заголовки от proxy нельзя подделать для TLS. `api/v5/router.php` на public site не активирован. |
| Bitrix transaction/security | `V5BitrixPdoCompatibilityBridge.php`, `V5StagingNativeTransactionPermit.php`, `V5VerifiedBitrixNativeTransactionDepth.php`, `V5SecurityEventVersions.php`, `V5NativeMutationCoverage.php`: строгий SQL allowlist, кандидаты guard/epoch/version; **нет 10/10 real native transaction proof**. |
| DDL | `schema/PROPOSED_*.sql`: proposed auth-state, attempt, challenge/SMS, session, staging probe. Только тестовые GitHub CI fixtures; **production миграций нет**. |

CI/тесты: `.github/workflows/v5-offline-safety.yml`, `.github/workflows/v5-ephemeral-mariadb.yml`; `tests/*.php`, `tests/integration/V5EphemeralMySqlStrongSessionsTest.php`, `V5EphemeralMySqlSmsOutboxTest.php`. Реальная *temporary* MariaDB CI **не является отдельным Bitrix staging**.

## 5. Уже исследованные production источники — закрыто, повторять НЕ НАДО

- Production Bitrix host: `evrasia`, root `/home/site_evrasia/web/evrasia.spb.ru/public_html`; Anti-Fraud bot работает на **другом** `eur-bot-01`. Не путать services/БД.
- `Controllers/V4/PinCode.php` и `lib/PhoneAuth.php`: два вызова `check()` с **разными лексическими owning classes**. Не доказана нативная телефонная верификация. Уже закрыты caller inventory, receiver binding, namespaces/classes, core flow и source-only probes.
- `bitrix/modules/main/lib/UserPhoneAuthTable.php` наблюдался с SHA256 `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991`; `CONFIRMED` имеет source default `N`. Callback существование не доказывает writer успешной верификации или `PHONE_EVENT_VERSION`.
- `bitrix/modules/main/classes/general/user.php`: observed SHA256 `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99`, старый expected SHA `542034ca4eee2c30bdc033f45d792945c10392aeaf2e482c01958e2bd585b9d0`. **Причина исходного drift НЕ установлена; новая версия не одобрена как baseline.** В `CUser::Update` line3920 `delete()` line4098 берёт переменную, последнее распознанное присваивание line3925; `add()` line4102 под дополнительным `if` line4101 с `USER_ID`, `PHONE_NUMBER`, но без inline `CONFIRMED`. Нет доказательства verify writer, доминирования или атомарности. Production read-only parser 4/4 + wrapper 5/5 PASS.
- Native recovery/MFA: `Otp::save` начинает очистку recovery до `UserTable::add`; `Otp::delete` вызывает `onAfterDelete` после native SQL delete; background `OtpEvents` ещё один writer. `useCode()===true` **не доказательство single-use CAS** `USED=N → Y` в одной транзакции. Полная `V5NativeMutationCoverageMatrix` из десяти путей не доказана; без неё protected V5 release запрещён.
- Полная историческая глубина: `docs/SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md`, `docs/SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md`, `docs/SAMZABERU_V5_NATIVE_MUTATION_COVERAGE_2026-10-12.md`. Это архив доказательств, **не список команд повторного production сканирования**.

## 6. Что НЕ сделано / блокирует релиз

| Release gate | Реальный статус |
|---|---|
| Физически отдельный Bitrix+MySQL staging | **UNATTESTED**; нет доказанного test host/DB/server, независимых credentials/root и изоляции от production. |
| Bitrix native transaction/ORM | Same PHP/SQL connection, `transactionLevel`, `CONNECTION_ID()`, owned transaction+rollback проверены только на synthetic/mock, не в реальном Bitrix staging. |
| Native пароль/TOTP/phone | Настоящие native locked password/TOTP replay/trust, confirmed phone writer, same-E164 reconfirm/version stamp/epoch proofs и Anti-Fraud integration не реализованы/не аттестованы E2E. |
| Десять mutation events | Password/reset, block, logout, OTP save/delete/reactivate/recovery/policy, phone reconfirm/revoke — **нет 10/10 atomic native paths**. |
| SMS lifecycle и provider | Первичный password-bound SMS begin/resend, реальный provider API+idempotent paid send, current tariff validation и независимая provider-`Sent` квитанция ещё не интегрированы. |
| PIN RestIS | Реальный PIN reader под той же доказанной native transaction не подключён. |
| Deployment | Proposed DDL не перенесён на staging/prod; публичный V5 не активирован. Нет approval на prod rollout. |

**Не путать статусы**: `IMPLEMENTED_OFFLINE` / `REAL_EPHEMERAL_MARIADB_PASS` / `BITRIX_STAGING_NOT_TESTED` / `PRODUCTION_NOT_DEPLOYED`. Никакие synthetic ACK или synthetic phone/TOTP результаты не означают реальный SMS/Bitrix proof.

## 7. С чего продолжать в НОВОМ ЧАТЕ (минимальные действия без дублей)

1. Прочитать **этот** START HERE, запросить **live** GitHub heads, кодовые diff по сравнению с CI-tested commit и два последних workflow run. Проверить, не появились ли новые изменения/PR. Не начинать 200-строчный старый checkpoint сначала.
2. Проверить готовность **физически изолированного** Bitrix+MySQL staging строго по `docs/SAMZABERU_V5_ISOLATED_STAGING_READINESS_2026-10-13.md` и `site-integration/samzaberu-v5/tests/integration/BITRIX_NATIVE_TRANSACTION_ACCEPTANCE.md`. Применять `V5BitrixNativeDepthReadOnlyPreflight.php` **ТОЛЬКО** при уже доказанной изоляции staging: Bitrix bootstrap потенциально имеет побочные эффекты. Staging DB должен быть отдельно подтверждён, test schema convention `evrasia_v5_test_*`, production host `evrasia`/bot `eur-bot-01` запрещены.
3. Если staging отсутствует — зафиксировать конкретную инфраструктурную зависимость и дать безопасный план подготовки **отдельного** стенда; не пытаться сделать staging в production наугад. Продолжать только безопасную feature implementation/CI. **Не возвращаться к CUser::Update микроаудитам.**
4. На реальном staging: сперва read-only preflight native Bitrix/ORM/SQL session identity, затем **по отдельному разрешению** synthetic rollback/concurrency и 10 security writer proofs. После этого adapter native password/TOTP/trust/phone+epoch, фактическая серверная SMS lifecycle/paid provider receipt, RestIS PIN, E2E, и только затем pilot/rollout.
5. Соблюдать порядок: исследование точного незавершённого стыка → минимальный код feature → тесты → CI → документация → следующий реальный блок. Не задавать пользователю те же вопросы из Project. Не выдавать имитацию за production.

## 8. Обязательные правила эксплуатации

- Приоритет production fact > live code GitHub feature > staging > свежий docs snapshot > старые обсуждения. Подтверждённый CI commit указан выше; последующие doc commits не означают новый runtime proof.
- V4 / production не менять. Большие исходники Bitrix не переписывать; `schema/PROPOSED_*.sql` не запускать на production, никаких внешних платных SMS в тестах.
- Для server tasks выдавать **один** цельный copy-paste shell wrapper, проверка `bash -n`/PHP lint, host/root/path/source SHA/database guard, `MODE=READ_ONLY` по умолчанию, `DATABASE_WRITE=NO`, `API_WRITE=NO`, `FINAL_STATUS`, `FINAL_RC`; терминал оставить открытым; не выводить secrets/PII.
- DB migration только с одобренной точной целью, backup и проверенным rollback. Опасные операции останавливать ДО изменения при guard failure. Source-only probes, тестовая MariaDB и реальный Bitrix acceptance — разные состояния.
- После изменений обновлять этот START HERE и/или указанные основными ссылками docs; синхронизировать общие исторические docs main↔feature без затрагивания кода main.

## 9. Готовый текст для вставки в новый чат

> Продолжаем Evrasia AI Bot / СамЗаберу API V5 как единый Project. Открой актуальную ветку `feature/samzaberu-api-v5-auth-contract` репозитория `juvantusik/Evrasia_AI_bot`. **Сначала** прочитай `docs/SAMZABERU_V5_START_HERE_2026-10-15.md`, затем проверь live GitHub HEAD/CI и только относящиеся к следующему шагу исходники. Последний подтверждённый CI-tested code commit `4a6ea09154b7bc6e8c34b60d2b37c003d85f0369`; GitHub Actions `38724339404` (26/26 offline) и `38724339420` (43+117 real ephemeral InnoDB) SUCCESS. Уже реализованы password-bound TOTP begin, одноразовый SMS/TOTP consume, независимые V5 JWT/strong sessions/refresh, anti-spam 10-scope quota, encrypted SMS outbox, one paid SMS claim и mandatory independent Sent provider receipt; native Bitrix/MFA/phone writers и фактический paid SMS/RestIS пока НЕ подключены. **Не повторяй завершённые source-only production диагностики, не трогай V4, production/БД и не включай public V5.** Следующий шаг: проверить физическую готовность отдельного Bitrix+MySQL staging и двигаться к реальной native/provider интеграции по runbook. Все безопасные GitHub feature/CI инкременты выполняй и фиксируй самостоятельно; серверные шаги выдавай единым guard-script. Не проси заново рассказывать историю проекта.
