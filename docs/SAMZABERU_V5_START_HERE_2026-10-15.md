# Evrasia AI Bot / СамЗаберу V5 — START HERE / handoff на 15 октября 2026

**ЭТО ГЛАВНАЯ ТОЧКА ПРОДОЛЖЕНИЯ ПРОЕКТА В НОВОМ ЧАТЕ.** Новый чат — не новый проект. Читать этот файл первым. Старые многостраничные checkpoint/registry-документы содержат историю и устаревшие NEXT-шаги: не начинать диагностику с нуля и не повторять закрытые проверки.

**Обновлено после нового кодового инкремента и обеих успешных CI 15 октября 2026.** Текущий checkpoint: [SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md](SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md). Конкретный план отдельного стенда: [SAMZABERU_V5_STAGING_PROVISIONING_PLAN_2026-10-15.md](SAMZABERU_V5_STAGING_PROVISIONING_PLAN_2026-10-15.md). Дополнение реестра структур и решений: [SAMZABERU_V5_INTEGRATION_DECISIONS_2026-10-15.md](SAMZABERU_V5_INTEGRATION_DECISIONS_2026-10-15.md). Эти документы имеют приоритет над историческими NEXT в checkpoint от 13 октября и STRUCTURE_REGISTRY.

## 1. Текущий итог и статус

В репозитории `juvantusik/Evrasia_AI_bot`, ветка **`feature/samzaberu-api-v5-auth-contract`**, реализована отдельная, fail-closed серверная модель авторизации СамЗаберу API V5: TOTP > strong trusted device > bound SMS, V5 JWT/refresh, InnoDB strong sessions, password-bound TOTP challenge, SMS/TOTP one-time consume, зашифрованная SMS-очередь, финансовые лимиты, single paid dispatch claim и независимый provider-SENT receipt gate; защищённый показ бонусного PIN без второй SMS; строгая HTTP-граница V5; кандидаты адаптеров Bitrix.

**Новое:** явная сверка поздней квитанции `reconcileUnknownSent()`: только `unknown → sent`, только после независимого receipt proof, без повторной отправки, выдачи envelope, повторного резервирования/освобождения бюджета или изменения challenge. Конкурентная CAS и запрет оживлять просроченный/отменённый challenge проверены в одноразовой MariaDB. Настоящий провайдер квитанций по-прежнему не установлен.

**Это код feature и успешно протестированные offline/одноразовые CI БД, но НЕ deployed working API:** настоящий Bitrix+MySQL staging физически не аттестован, нативные MFA/phone/password/epoch proof-провайдеры не интегрированы, SMS provider API и RestIS PIN не подключены. V4/production не менялись, реальные SMS не отправлялись, production SQL не выполнялся. Публичный V5 остаётся выключенным, **HTTP 503 — последний документированный baseline, а не новый live HTTP тест**.

## 2. Проверенные GitHub идентификаторы — на них опираться

- Repo: `juvantusik/Evrasia_AI_bot`. V5 source-of-truth branch: `feature/samzaberu-api-v5-auth-contract`. `main` не использовать как V5 code branch.
- **Последний code/test commit с успешными обеими CI:** `dc2caed6af465aeb387490d20f1173005a80e3dd`. Предшествующий runtime-инкремент: `04915fab87abf21eec1649f75b053c9bb91619dc`; следующий commit исправил только порядок окон в новой тестовой заготовке, не ослабляя production-кандидат.
- При начале этой итерации live feature HEAD `0ff80561db4654eeeeac159e762e21bda479e909`, main HEAD `7c2e9f8e4501b92469bcdeea18f8340676ad52c9` совпали с handoff. Compare от `4a6ea09154b7bc6e8c34b60d2b37c003d85f0369` до `0ff8056` показал только документационные изменения. Новая кодовая дельта `0ff8056..dc2caed` — ровно три файла: dispatch store, новый integration test и ephemeral CI workflow.
- **V5 offline safety** [Actions 38736808948](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38736808948): `SUCCESS`, **26/26 suites**, lint **78 PHP files**, `FINAL_STATUS=PASS`. Никакого native Bitrix bootstrap/production source read.
- **Реальная временная MariaDB/InnoDB** [Actions 38736809040](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38736809040): `SUCCESS`, **43 strong-session/HTTP + 117 прежних SMS/TOTP/dispatch + 45 новых receipt reconciliation = 205 assertions**, `FINAL_STATUS=PASS`, `FINAL_RC=0`. Новая гонка: два независимых PHP процесса оба прочитали UNKNOWN, CAS успешна ровно у одного. Receipt/native providers синтетические; реальных SMS/Bitrix/RestIS нет.
- Первый прогон нового инкремента [Actions 38736335930](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38736335930) был `FAIL`: прежние 160 assertions прошли, новая fixture нарушила canonical budget ordering. Исправлено в `dc2caed`; не считать первый прогон успешным.
- Исторический успешный baseline `4a6ea09154b7bc6e8c34b60d2b37c003d85f0369`: Actions `38724339404` (26 suites) и `38724339420` (160 assertions). Они сохранены как история, но superseded указанными выше новыми успешными runs.
- После записи документации feature HEAD изменится **только документами**. Не приписывать новому doc-only HEAD отдельное тестирование кода. В новом чате проверить live ref и compare с `dc2caed`.
- **Main оставлен без изменений.** `.github/workflows/build-server-image.yml` запускается на push main без paths-фильтра и предусматривает публикацию образа `latest`. Поэтому автоматическая синхронизация docs в main отложена; это не доказательство auto-deploy, но нежелательный побочный эффект публикации. Новые V5 docs читать именно в feature.

## 3. Принятая бизнес-логика (не переутверждать)

1. Вход: логин+пароль; сервер **без установки V4 PHP/Bitrix login** проверяет актуальную личность/пароль, активность, блокировки Anti-Fraud, native 2FA и лимиты первой попытки. Не доверять клиентскому `user_id`, номеру телефона, `X-Forwarded-For`.
2. При подключённой **TOTP** после пароля обязателен код аутентификатора; **fallback на SMS запрещён** при неверном/недоступном TOTP. После входа PIN/бонусы доступны без второй SMS.
3. Когда TOTP отключён, допускается только **новое сильное V5 trusted device**, не слабая старая V4 trust запись; сильный trust позволяет вход без SMS, а PIN — без повторной SMS.
4. Без TOTP и trusted device — один серверный **SMS challenge на подтверждённый номер из аккаунта**; клиент **не вводит телефон**. Успешный код даёт strong V5 session; после этого PIN без второго SMS.
5. Защита от SMS-спама: десять rate/стоимостных окон, cooldown, лимит попыток и отправок, криптографическая привязка к challenge/account/device/phone-верификации/`AUTH_EPOCH`, HMAC SMS-кодов и зашифрованный outbox, проверка стоимости и currency перед отправкой, provider idempotency; отсутствие повторов при неизвестном платном результате. Поздняя квитанция не разрешает повторную отправку.
6. **`PHONE_EVENT_VERSION` должен увеличиваться при успешном native phone verification, включая повторное подтверждение ТОГО ЖЕ E.164**, а также соответствующих revoke/replace. **`AUTH_EPOCH` должен атомарно изменяться в одной физической native Bitrix/InnoDB transaction** со всеми security/MFA/password/logout событиями; старые V5-сессии/доказательства немедленно теряют силу.
7. Существующий V4 сохранить, никаких незапрошенных архитектурных/UI изменений.

Предложенные пути в `src/V5RouteMap.php`: POST `/api/v5/auth/signin`, `/api/v5/auth/challenge/verify`, `/api/v5/auth/challenge/resend`, `/api/v5/auth/refresh`, `/api/v5/loyalty/pin`. **Route map не означает включённый router**. Новый reconciliation method — внутренний store API, не публичная HTTP-ручка.

## 4. Готовый код: реализация vs реальная интеграция

Все пути ниже относительно `site-integration/samzaberu-v5/`.

| Реализованный домен | Основные файлы и граница подтверждения |
|---|---|
| Auth workflow и приоритеты | `src/V5LoginWorkflow.php`, `AuthDecision.php`, `V5ExistingLoginPolicy.php`, `V5NoMfaFallbackPolicy.php`, `V5NativeTotpStatus.php`: логика TOTP/trust/SMS, fail closed; реальное native MFA proof ещё не проверено. |
| Пароль и rate limiting | `V5BitrixPasswordProofPort.php` (кандидат native comparator без login-cookie), `V5FirstFactorAttemptGate.php`, `V5SiteAccountReader.php`, `V5MySqlAttemptWindowStore.php`: нужны реальные account resolver, IP-context, Anti-Fraud и staging acceptance. |
| TOTP challenge begin | `src/V5MySqlPasswordBoundTotpIssuer.php`: внутри единой InnoDB transaction блокируется `b_user`, читается общий epoch, обязателен **fresh current password + native TOTP recheck под той же блокировкой**, до 300 сек, до 20 pending; возвращает ticket только после COMMIT. Старый `begin(userId)` запрещён. Site-native verifier всё ещё не подключён. |
| Verification | `V5MySqlChallengeConsumption.php`, `V5MySqlChallengeHttpPort.php`, `V5MySqlConsumedChallengeProof.php`: SMS/TOTP one-time consume, 5 неверных попыток→cancel, strong session после повторного proof и DB UNIQUE grant digest; SMS-код принимается только от положительно подтверждённого `sent` outbox. **SMS begin/resend всё ещё отключены без trusted lifecycle provider.** |
| Очередь и бюджет SMS | `V5SmsDispatchBudget.php`, `V5MySqlSmsOutbox.php`, `V5BitrixLockedSmsEligibility.php`: 10-scope quotas, encrypted message, code HMAC, rollback, resend cooldown. Реальная БД CI PASS, настоящей отправки нет. |
| Платный SMS dispatch | `src/V5MySqlSmsDispatchClaims.php`: DB-only `queued → claimed → sent/failed/unknown` с единственным claim/конкурентной CAS, повторной native phone/MFA/epoch и provider tariff/currency проверкой. `claimed/unknown` нельзя автоматически повторно отправлять. Реальный paid SMS worker/provider API **не реализован**. |
| SENT receipt | `V5SmsSentReceiptAttestationPort` обязателен перед `recordProviderOutcome(..., Sent)`: без **независимой** подтверждённой provider квитанции статус остаётся не-`sent`, код не является SMS-proof. В CI injected receipt stub **СИНТЕТИЧЕСКИЙ**. |
| **Новое: поздняя квитанция** | `V5MySqlSmsDispatchClaims::reconcileUnknownSent()`: UNKNOWN-only SELECT + независимый receipt + CAS UNKNOWN→SENT. Нет send/resend/claim, изменений бюджета, challenge/TTL/epoch/телефона. Повтор и гонка не дублируют переход; чужая транзакция, открытая receipt-provider, запрещена и не откатывается этим store. `V5EphemeralMySqlSmsReconciliationTest.php`: 45 новых real InnoDB assertions. |
| Сессии/JWT | `V5SessionTokens.php`, `V5MySqlStrongSessionStore.php`, `V5MySqlSessionActions.php`: отдельные ключи/issuer/audience от V4, JWT только после COMMIT, opaque refresh digests, CAS rotation, revocation, device/grant HMAC, epoch. Реальный native 10-path security proof не реализован. |
| Показ бонусного PIN | `V5LockedBonusPinReaderPort`, `showBonusPinForAccess()`: текущая revocation/epoch/assurance + PIN read в одной locked transaction, **без второй SMS**. Реальный RestIS PIN-port нет, только синтетический CI reader. |
| HTTP | `V5AuthHttpHandler.php`, `V5RouteMap.php`, `V5HttpTransportBoundary.php`, `V5PhpHttpRequestFactory.php`: строгие HTTPS/POST JSON, размер, точный path, anti-cache, 503 by default, заголовки от proxy нельзя подделать для TLS. `api/v5/router.php` на public site не активирован. |
| Bitrix transaction/security | `V5BitrixPdoCompatibilityBridge.php`, `V5StagingNativeTransactionPermit.php`, `V5VerifiedBitrixNativeTransactionDepth.php`, `V5SecurityEventVersions.php`, `V5NativeMutationCoverage.php`: строгий SQL allowlist, кандидаты guard/epoch/version; **нет 10/10 real native transaction proof**. |
| DDL | `schema/PROPOSED_*.sql`: proposed auth-state, attempt, challenge/SMS, session, staging probe. Только тестовые GitHub CI fixtures; **production миграций нет**. В текущем инкременте DDL не менялся. |

CI: `.github/workflows/v5-offline-safety.yml`, `.github/workflows/v5-ephemeral-mariadb.yml`. Новый `tests/integration/V5EphemeralMySqlSmsReconciliationTest.php` включает прежний `V5EphemeralMySqlSmsOutboxTest.php` ровно один раз, затем выполняет новые cases. Реальная temporary MariaDB CI **не является отдельным Bitrix staging**.

## 5. Уже исследованные production источники — закрыто, повторять НЕ НАДО

- Production Bitrix host: `evrasia`, root `/home/site_evrasia/web/evrasia.spb.ru/public_html`; Anti-Fraud bot работает на **другом** `eur-bot-01`. Не путать services/БД.
- `Controllers/V4/PinCode.php` и `lib/PhoneAuth.php`: два вызова `check()` с **разными лексическими owning classes**. Не доказана нативная телефонная верификация. Уже закрыты caller inventory, receiver binding, namespaces/classes, core flow и source-only probes.
- `bitrix/modules/main/lib/UserPhoneAuthTable.php` наблюдался с SHA256 `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991`; `CONFIRMED` имеет source default `N`. Callback существование не доказывает writer успешной верификации или `PHONE_EVENT_VERSION`.
- `bitrix/modules/main/classes/general/user.php`: observed SHA256 `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99`, старый expected SHA `542034ca4eee2c30bdc033f45d792945c10392aeaf2e482c01958e2bd585b9d0`. **Причина исходного drift НЕ установлена; новая версия не одобрена как baseline.** В `CUser::Update` line3920 `delete()` line4098 берёт переменную, последнее распознанное присваивание line3925; `add()` line4102 под дополнительным `if` line4101 с `USER_ID`, `PHONE_NUMBER`, но без inline `CONFIRMED`. Нет доказательства verify writer, доминирования или атомарности. Production read-only parser 4/4 + wrapper 5/5 PASS — исторические результаты, не новые запуски.
- Native recovery/MFA: `Otp::save` начинает очистку recovery до `UserTable::add`; `Otp::delete` вызывает `onAfterDelete` после native SQL delete; background `OtpEvents` ещё один writer. `useCode()===true` **не доказательство single-use CAS** `USED=N → Y` в одной транзакции. Полная `V5NativeMutationCoverageMatrix` из десяти путей не доказана; без неё protected V5 release запрещён.
- Полная историческая глубина: `docs/SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md`, `docs/SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md`, `docs/SAMZABERU_V5_NATIVE_MUTATION_COVERAGE_2026-10-12.md`. Это архив доказательств, **не список команд повторного production сканирования**.

## 6. Что НЕ сделано / блокирует релиз

| Release gate | Реальный статус |
|---|---|
| Физически отдельный Bitrix+MySQL staging | **UNATTESTED**; в доступных материалах нет подтверждённых host/VM identity, независимой БД и сетевой изоляции. Это отсутствие доказательства, не утверждение, что VM нигде не существует. План создания уже подготовлен; VM ещё не создана этой работой. |
| Bitrix native transaction/ORM | Same PHP/SQL connection, `transactionLevel`, `CONNECTION_ID()`, owned transaction+rollback не проверены в реальном Bitrix staging. |
| Native пароль/TOTP/phone | Настоящие native locked password/TOTP replay/trust, confirmed phone writer, same-E164 reconfirm/version stamp/epoch proofs и Anti-Fraud integration не реализованы/не аттестованы E2E. |
| Десять mutation events | Password/reset, block, logout, OTP save/delete/reactivate/recovery/policy, phone reconfirm/revoke — **нет 10/10 atomic native paths**. |
| SMS lifecycle и provider | Первичный password-bound SMS begin/resend, реальный provider API+idempotent paid send, current tariff validation и независимая provider-квитанция, включая late receipt lookup, ещё не интегрированы. DB-only reconciliation этого не заменяет. |
| PIN RestIS | Реальный PIN reader под той же доказанной native transaction не подключён. |
| Deployment | Proposed DDL не перенесён на staging/prod; публичный V5 не активирован. Нет approval на prod rollout. |

**Не путать статусы**: `OFFLINE_TESTED=PASS`, `REAL_EPHEMERAL_MARIADB_PASS=YES`, `REAL_BITRIX_STAGING_TESTED=NO`, `PRODUCTION_DEPLOYED=NO`. Никакие synthetic ACK или synthetic phone/TOTP результаты не означают реальный SMS/Bitrix proof.

## 7. С чего продолжать в НОВОМ ЧАТЕ (минимальные действия без дублей)

1. Читать этот START HERE и checkpoint от **15 октября**, проверить live feature/main и diff с `dc2caed`, последние CI и связанные PR. Не перечитывать всю историю для повторного исследования закрытого вопроса.
2. Ближайшая внешняя зависимость — подтвердить уже выделенную независимую VM либо выделить её по **готовому** `SAMZABERU_V5_STAGING_PROVISIONING_PLAN_2026-10-15.md`. Известны только production hosts; гипервизор/доступный test host не угадывать. Не запускать новые source-only CUser микроаудиты вместо этого шага.
3. До любого Bitrix PHP выполнить Stage A исходного `SAMZABERU_V5_ISOLATED_STAGING_READINESS_2026-10-13.md`: независимые app/DB/storage/secrets, заблокированные integrations/agents/egress. Затем существующий `V5BitrixNativeDepthReadOnlyPreflight.php`; bootstrap потенциально имеет побочные эффекты. Test schema convention `evrasia_v5_test_*`, production hosts `evrasia`/`eur-bot-01` запрещены.
4. На аттестованном staging — отдельное разрешение на synthetic probe DDL/rollback/concurrency, затем 10 security writer proofs и native password/TOTP/trust/phone+epoch. Далее password-bound SMS lifecycle, настоящий provider receipt/status contract, RestIS PIN, E2E и только затем pilot.
5. Все безопасные локальные GitHub feature/CI инкременты выполнять самостоятельно; реальные платежи/SMS, production и новые опасные миграции требуют отдельного допуска. Не выдавать увеличение CI coverage за закрытие native integration gate.

## 8. Обязательные правила эксплуатации

- Приоритет production fact > live code GitHub feature > staging > свежий docs snapshot > старые обсуждения. Последующие doc commits не означают новый runtime proof.
- V4 / production не менять. Большие исходники Bitrix не переписывать; `schema/PROPOSED_*.sql` не запускать на production, никаких внешних платных SMS в тестах.
- Для server tasks выдавать **один** цельный copy-paste shell wrapper, проверка `bash -n`/PHP lint, host/root/path/source SHA/database guard, `MODE=READ_ONLY` по умолчанию, `DATABASE_WRITE=NO`, `API_WRITE=NO`, `FINAL_STATUS`, `FINAL_RC`; терминал оставить открытым; не выводить secrets/PII.
- DB migration только с одобренной точной целью, backup и проверенным rollback. Опасные операции останавливать ДО изменения при guard failure. Source-only probes, тестовая MariaDB и реальный Bitrix acceptance — разные состояния.
- После изменений обновлять этот START HERE и актуальные checkpoint/decision docs. **Не синхронизировать main автоматически:** его push вызывает build/publish. Исторические документы сохраняются, текущие V5 дополнения находятся в feature.

## 9. Готовый текст для вставки в новый чат

> Продолжаем Evrasia AI Bot / СамЗаберу API V5 как единый Project. Открой `juvantusik/Evrasia_AI_bot`, feature `feature/samzaberu-api-v5-auth-contract`. Сначала `docs/SAMZABERU_V5_START_HERE_2026-10-15.md`, затем новый checkpoint, staging provisioning plan и integration decisions от 15 октября. Последний code/test commit с обеими успешными CI `dc2caed6af465aeb387490d20f1173005a80e3dd`; Actions `38736808948` (26/26 offline suites, 78 PHP lint) и `38736809040` (43+117+45=205 real ephemeral MariaDB assertions) SUCCESS. Новый `reconcileUnknownSent()` умеет независимо подтвердить позднюю квитанцию UNKNOWN→SENT без повторной SMS/оплаты; provider в CI синтетический. Проверь live HEAD и diff, не путай более поздний doc-only HEAD с новым кодовым тестированием. Main оставлен `7c2e9f8e4501b92469bcdeea18f8340676ad52c9`: обычный push туда запускает публикацию image latest. Production/V4 не менялись; Bitrix staging всё ещё UNATTESTED, настоящие native providers/SMS/RestIS не подключены. Следующий реальный шаг — выделение или подтверждение отдельной безопасной VM+DB по уже подготовленному плану, затем существующие native preflight и rollback acceptance. Не повторяй завершённые production PHP-аудиты, не запускай production SQL/DDL/реальные SMS, не включай public V5. Все безопасные GitHub feature/CI операции выполняй самостоятельно, без ожидания команды «дальше».
