# Evrasia AI Bot / СамЗаберу API V5 — handoff 25 октября 2026
## АКТУАЛЬНАЯ ТОЧКА ПЕРЕХОДА В НОВЫЙ ЧАТ

> **EMAIL LEDGER OWNERSHIP BEFORE MOBILE SMS — 25.10.2026:** `V5MySqlChallengeHttpPort::requestSmsInsteadOfEmail` now fails closed for absent `V5EmailChallengeVerificationPort`, absent native SMS recovery or non-email `challenge_id`. Before any native paid-SMS callback it performs `ownsEmailChallenge` exact indexed email ledger lookup (not authorization). Native adapter must still re-lock state, current AUTH_EPOCH/device, TOTP-off/verified phone, idempotency and paid provider receipt. `tests/V5EmailToSmsLedgerOwnershipTest.php` added; source-only targeted [Actions #40183917685](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40183917685) **SUCCESS**, no DB or SMS. Existing `V5MySqlPublicCompositionTest.php` adjusted to deny before native callback if email ledger not available. Doc [SAMZABERU_V5_EXPLICIT_EMAIL_TO_SMS_RECOVERY_HTTP_2026-10-25.md](SAMZABERU_V5_EXPLICIT_EMAIL_TO_SMS_RECOVERY_HTTP_2026-10-25.md) updated. IMPORTANT: proposed `ev_v5_email_auth_challenges` schema has no durable cross-reference to new SMS challenge; real provider must prove atomic, idempotent supersession via verified existing schema or request separate approved DDL; sending paid SMS before durable state is forbidden. No private installed native SMS bridge, no production DDL, V5 AUTH OFF. Site fallback independently works. Terminal output rule remains CLEAR AFTER HEREDOC before executing commands.



> **V5 WEBSITE SMS BRIDGE SOURCE-ONLY PREPARATION — 25.10.2026:** live read-only signature check returned `FINAL_STATUS=PASS`, no DB/SMS: `issuePilotSmsCode(int $userId)` private, uses `CSms::send`, `getPilotProfilePhone(int)`, `enforcePilotSmsRateLimit(int)`; website SMS policy/resend guard accepts real `array $session` and `sessionId`, cannot re-use with fake mobile browser session. Created `src/V5ExistingWebsiteSmsRecoveryAdapter.php` implementing `V5AttestedExplicitSmsRecoveryPort`, binding exact physical PDO, comparing SHA256 of four known live site files and demanding a **separately implemented** `V5ExistingWebsiteSmsNativeTransitionPort` native proof; checks again per request and forbids same/new-email ID or wrong factor. No SMS sending, code generation, duplicate quotas or session emulation. Added `tests/V5ExistingWebsiteSmsRecoveryAdapterTest.php`, wired offline CI. Targeted [Actions #40183077325](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40183077325) **SUCCESS** for commit `9faa71f8`. Documents updated `docs/SAMZABERU_V5_EXPLICIT_EMAIL_TO_SMS_RECOVERY_HTTP_2026-10-25.md`. This is an interface-to-live-code **preparation**, NOT implemented private provider or real mobile SMS E2E. Browser SMS already works, do not rewrite it; private Bitrix sender and OTP receipt integration still require explicit approved and backed-up production change; email challenge DDL still requires separate approval. API V5 AUTH OFF. Do not treat this as production ready. Parallel Restis unchanged.



> **MANDATORY TERMINAL OUTPUT CLEAR RULE — owner repeatedly confirmed:** every next server copy-paste wrapper MUST clear the *previous terminal output AND the pasted heredoc command echo* by executing `printf '\\033[3J\\033[2J\\033[H'` **AFTER the heredoc script file is fully created and IMMEDIATELY BEFORE `=== WRAPPER SYNTAX CHECK ===` / `bash -n` and running the script**. A clear only at the very start of the pasted wrapper is NOT enough; do not repeat this mistake. One paste block; no secret logging; leave SSH open. Rule applies also to read-only/short server commands. This text is a durable project handoff rule for new chats.

> **EMAIL-FIRST NO-SMS-GATE CONTINUATION — 25.10.2026:** existing live website SMS is working. Latest inspected production source: `issuePilotSmsCode(int $userId)` is PRIVATE and uses `CSms::send`, `getPilotProfilePhone`, `enforcePilotSmsRateLimit`; its enclosing website flow is PHP-session-bound. `EmailFirstSmsRatePolicy::begin/check/reserve` use `array &$session` / `sessionId`; cannot use fake PHP sessions to integrate mobile SMS. Existing V5 `buildEmailFirst()` previously blocked readiness on SMS+TOTP together. Feature-only change allows independently attested native TOTP via `V5PublicVerifiedTotpLifecyclePort` when manual SMS is absent. `V5UnavailableSmsLifecycle` denies SMS issue/resend; SMS consumption is blocked under challenge row lock in `V5MySqlChallengeConsumption`. Optional SMS needs independently attested full native SMS/TOTP and website recovery; no new generator, delivery or limiter created. Target source-only [Actions #40181890121](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40181890121) **SUCCESS**. Do NOT claim real login E2E, installed private native SMS adapter or production V5 enablement. V4 and website unchanged. DDL `ev_v5_email_auth_challenges` still not approved; no synthetic accounts or isolated DB. Full explanation in `docs/SAMZABERU_V5_EXPLICIT_EMAIL_TO_SMS_RECOVERY_HTTP_2026-10-25.md`.


> **LIVE WEBSITE SMS REUSE BOUNDARY — 25.10.2026, operator PASS:** read-only `ev-v5-sms-boundary` verified unchanged exact live source hashes and statically inspected 18 methods, no Bitrix bootstrap, DB, Redis, mail/SMS, or writes. `emailUseSmsAction` is public BUT relies on `$_SESSION`, `$USER`, CSRF; private `evEmailIssueSmsAfterReservation`, `evEmailIssueSmsFromVerifiedContext`, `evEmailIssueSmsFromVerifiedContextNative` rely on `$_SESSION`; **do not invoke them as API V5**. `issuePilotSmsCode` is private but directly calls `CSms`, `getPilotProfilePhone` and `enforcePilotSmsRateLimit`, with no direct PHP-session access — candidate for extraction to a shared installed site-native service *only after separately approved live change*. `EmailFirstSmsRatePolicy` public static begin/check/reserve and `EmailFirstSmsResendGuard` public static canIssue/markReserved lack direct session accesses; signatures/semantics not yet attested. Do not use reflection to invoke private Bitrix methods or copy production PHP into public GitHub. Documentation checkpoint: [SAMZABERU_V5_EXPLICIT_EMAIL_TO_SMS_RECOVERY_HTTP_2026-10-25.md](SAMZABERU_V5_EXPLICIT_EMAIL_TO_SMS_RECOVERY_HTTP_2026-10-25.md). No V5 provider has been installed; V5 AUTH OFF, DDL not authorized. NEXT: get sanitized parameter signatures and existing CSms call shape, then implement minimal V5-side native wrapper reusing site SMS provider/guards, with exact live guards and no second limiter. Restis separate.


> **NEW V5 EMAIL→MANUAL SMS HTTP BOUNDARY — 25.10.2026:** added V5-only `POST /api/v5/auth/challenge/use-sms` (strict JSON `challenge_id` of existing email challenge, `device_id`, `platform` only). Normal signin remains trust→TOTP→email; resend continues to mean SMS resend only. New `V5ExplicitSmsRecoveryPort` delegates to *already existing site SMS recovery* with native binding/epoch/verified phone/rate proof; does NOT implement SMS algorithm or duplicate limiter. No installed provider supplied, so response is fail-closed 401 and **NO SMS sent**. `buildEmailFirst()` additionally accepts only optional `V5AttestedExplicitSmsRecoveryPort` with positive `verifiedExistingSiteRecovery(PDO)`, unknown/false/exception denies assembly. Contract and status recorded in [SAMZABERU_V5_EXPLICIT_EMAIL_TO_SMS_RECOVERY_HTTP_2026-10-25.md](SAMZABERU_V5_EXPLICIT_EMAIL_TO_SMS_RECOVERY_HTTP_2026-10-25.md). Source-only `V5ManualSmsFallbackHttpTest.php` plus public composition negative tests: [targeted CI #40180569595](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40180569595) job completed SUCCESS (PHP lint/tests, no DB, accounts, Redis, messages); broad CI remains separately failing older JS DOM click regression, so NOT all-green. This does **not** prove real mobile fallback. Production V5 AUTH OFF. No DDL in `eurasia_new` without separate explicit approval; proposal/rollback document already exists. V4, installed website and Restis untouched. Do not repeat earlier email budget or SQL audits.


> **V5 EMAIL LEDGER APPROVAL PREP — 25.10.2026:** GitHub source-only test `tests/V5EmailLedgerSchemaContractTest.php` at `683402f6` checks exact 11-column proposed `ev_v5_email_auth_challenges` schema against issuer, consumed-email grant proof, one-time HMAC clearing and native SQL bridge. CI registered `8ff72319`. SQL header explicitly identifies same existing `eurasia_new`, NOT separate DB/staging and NOT an approval (`44d954df`). [Target Actions #40180131993](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40180131993) **COMPLETED SUCCESS** (source-only, no DB/users/mail). Broad offline workflow **still FAIL** on its separate older JS click regression; do not claim all CI green. New detailed [approval/backup/rollback plan](SAMZABERU_V5_EMAIL_LEDGER_PRODUCTION_MIGRATION_APPROVAL_2026-10-25.md) recorded at `03d47e3c`. **No production DDL executed, existing `ev_v5_email_auth_challenges` still absent per latest read-only checkpoint; no authorisation for CREATE inferred from "дальше".** NEXT: owner separately approves table CREATE in real `eurasia_new` while keeping V5 AUTH OFF, then guarded backup/migration/post-check. Until then continue GitHub-only integration work and preserve parallel Restis commits.


> **V5 AUTH development continuation (25.10.2026):** three bounded source-only GitHub commits strengthened new `buildEmailFirst` wiring: `3d9bc8d1` adds `V5BitrixLockedEmailOtpProof::boundTo(PDO)` (object identity, no SQL), `9fe3b3c8` refuses mismatched Bitrix PDO before constructing HTTP provider or invoking release attestation, and `d03f8977` adds pure-PHP tests of foreign-connection denial, release gates and same-PDO composition (no accounts/DB/Redis/email). Target workflow [#40179813413](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40179813413) **COMPLETED SUCCESS** including PHP lint and new composition tests. Follow-on `5a692ca9` adds a source-only HTTP invalid-login 401 regression, also proving no OTP/Redis calls; its own CI result must be inspected independently. Broad `V5 offline safety checks` [#40179813410](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40179813410) **FAIL** at older `WebsiteEmailAccountConfirmWidgetClickTest.cjs` browser DOM event regression, prior to PHP steps; do not label all CI green and do not modify deployed website for that separate defect. Missing production `ev_v5_email_auth_challenges` still requires separate owner approval + backup/rollback; no DDL done. Private factory and native AUTH_EPOCH/live trust attestation are still uninstalled/unverified. V4/site/Restis untouched, V5 AUTH OFF.


> **OPCACHE READ-ONLY FOLLOW-UP (operator output, 25.10.2026):** installed live `EmailSendBudget.php` Git blob `cc6c1b775f584eb84b7811c7fb5ba204ba3be2aa` reconfirmed, `php -l` PASS. 11 PHP-FPM processes spanning 8.1 / 8.3 / 8.4, `opcache.enable=1` found in each `/etc/php/<version>/fpm/php.ini`; version 8.3 explicitly has `opcache.revalidate_freq=0`. Effective `opcache.validate_timestamps` and which PHP-FPM pool serves the site are NOT attested; FPM live cached bytecode not inspected. `FINAL_STATUS=PASS`, `FINAL_RC=0`, no HTTP/DB/Redis interactions, no restart or cache clear. Do NOT misrepresent this as positive live OPcache attestation or require a restart just because OPcache is enabled. Source migration already complete, no need to reinstall.


> **PRODUCTION ATTESTATION — 25.10.2026, operator-provided guarded deploy output:** production `evrasia` `/local/php_interface/website-mfa/EmailSendBudget.php` switched from verified Git blob `b20486bf4b99318cfd0ad22a6fe2db2e0cc09f01` to `cc6c1b775f584eb84b7811c7fb5ba204ba3be2aa`, `php -l` PASS and metadata preserved. Verified private root backup exists. `FINAL_STATUS=PASS`, `FINAL_RC=0`, `GLOBAL_EMAIL_BLOCKING_CAP=REMOVED_FROM_SOURCE`; user cooldown 60 sec + 5/hour remain; global Redis hourly counter is nonblocking reservation telemetry. `PHP_FPM_OPCACHE_RUNTIME_VERIFIED=NO`: do not yet claim FPM has reloaded source. No DB, Redis, SMS, mail sends, V4 or V5 AUTH enablement. This supersedes earlier checkpoint text claiming production source has not been updated. Do not repeat the same deploy; next check must be read-only OPcache/FPM evidence.


> **ДОПОЛНЕНИЕ ПОЗДНЕЕ 25.10.2026 — РЕШЕНИЕ ВЛАДЕЛЬЦА О ПОЧТОВОМ ЛИМИТЕ.** Общий блокирующий потолок **1000 email/час УБИРАЕМ**, до сбора фактической статистики по email и SMS общие блокирующие потолки не вводить. В feature GitHub изменён только существующий `deployment/website-mfa/EmailSendBudget.php`: пер-пользовательские cooldown 60 сек и 5 писем/час сохранены; прежний общий Redis hourly counter остаётся только НЕБЛОКИРУЮЩИМ наблюдением за budget reservations, не доставленными письмами. `V5ExistingWebsiteEmailBudget` по-прежнему использует этот существующий сайт-Redis, второй limiter не допускается. Старые описания `GLOBAL_PER_HOUR=1000` ниже — исторические. **Production website НЕ менялся этим source-коммитом; live-хеш и соответствие ещё не установлены.** Не раскатывать обновление без аттестации живого файла, backup и проверки. V5 AUTH production OFF.


**Прочитать перед любыми действиями.** Это актуальный checkpoint после исправления website email-first + SMS fallback, подтверждения реальной схемы БД и mail template, реализации feature-only мобильной email-auth цепочки, а главное — решения владельца **НЕ создавать второй email/SMS OTP или rate-limiter**. Старые инструкции, в которых website MFA всё ещё OFF, TOTP без trust приоритетнее, а SMS предлагается по умолчанию, исторические и в этих частях НЕ действуют.

**GitHub**: [juvantusik/Evrasia_AI_bot](https://github.com/juvantusik/Evrasia_AI_bot), ветка `feature/samzaberu-api-v5-auth-contract`. HEAD непосредственно перед подготовкой этого handoff: `9a242d3ae1b5b9aaecde479e4506e741d37f0658`. HEAD ветки нужно перечитать перед каждым изменением: **в ветке параллельно идут коммиты по Restis/бонусам**, их не терять и не откатывать. Канонический текущий HEAD после этого handoff — тот, который выдаст GitHub, а не указанная выше историческая контрольная точка.

**Инструкция для первого сообщения нового чата**: [EVRASIA_AI_BOT_NEW_CHAT_INSTRUCTION_2026-10-25_V5_EMAIL_REUSE.txt](EVRASIA_AI_BOT_NEW_CHAT_INSTRUCTION_2026-10-25_V5_EMAIL_REUSE.txt).

## 1. Непереходимые требования владельца

- **Уже работающий сайт не менять**: основной домен для гостей `https://evrasia.rest`, production host `evrasia`, физический Bitrix docroot `/home/site_evrasia/web/evrasia.spb.ru/public_html`, реальная база `eurasia_new`.
- API V4 должен остаться полностью независимым и работоспособным; бизнес-логику V4 для V5 переиспользовать, не переписывая V4 без необходимости. API V5 AUTH на production **OFF** до отдельной полной приёмки и согласованного включения.
- **Никаких изолированных / disposable / ephemeral БД, отдельного Redis, тестовых/синтетических пользовательских учётных записей и повторных MariaDB CI integration workflows**. Workflow `.github/workflows/v5-ephemeral-mariadb.yml` удалён по решению владельца. Разрешены GitHub CI pure-PHP синтаксис/безопасные source-only проверки без подключения к БД и без создания пользователей. Для фактов о Bitrix — узкие read-only действия на реальном сервере, не читать секреты/данные клиентов без надобности.
- **Никаких несанкционированных DDL, DB WRITE, production PHP/JS patch, отправки реальных OTP или включения V5**. Нужна отдельная согласованная миграция недостающей таблицы внутри **существующей** `eurasia_new` с backup/rollback, если она действительно необходима.
- **Не переносить/не публиковать** рабочие production PHP/JS, private root snapshots, HMAC/SMTP/Redis credentials или пользовательские данные в GitHub. Репозиторий public; owner остановил previous production-source push.
- **Не писать заново** email challenge сайта, SMS challenge, почтовый transport или алгоритм лимитов: владелец настаивает на переиспользовании уже внедрённых и проверенных функций. Допустима только узкая V5 integration boundary и отдельное mobile challenge/session state, необходимое из-за отсутствия PHP-browser session в приложении.
- Сперва прочитать актуальный source/status, **не начинать новый чат с десятков старых диагностик**; выпускать конкретные изменения кодовой базы, CI, GitHub commit и честно отделять CI от production E2E.

## 2. Принятая политика авторизации (НЕ менять)

После серверного подтверждения пароля и допуска:
1. **Сильное доверенное устройство** — если токен/регистрация доверия подтверждены сервером, не требовать TOTP/email/SMS повторно, включая пользователя с TOTP.
2. Иначе если включён нативный **TOTP Bitrix** — проверять серверный TOTP, без email/SMS downgrade при ошибке.
3. Иначе (нет TOTP) — **6-значный OTP на ранее подтверждённый текущий email**, берётся с сервера из текущего профиля, не из client payload.
4. **SMS — только отдельная явно запрошенная резервная процедура** для допустимого пользователя после server-side proof; не автоматически из-за SMTP outage/TOTP ошибки. У пользователя сайт уже реализовал SMS challenge, resend protection, rate controls, verified phone.
5. Только после реально погашенного и повторно проверенного factor grant выдавать сильную mobile V5 access/refresh session; V4 token, PHP session, cookie, простой deviceId и наличие email-адреса НЕ являются таким proof. PIN и бонусные операции — только под достаточной assurance.

Параллельные требования разработчика СамЗаберу: отображение актуальных legal документов/согласий при регистрации, TOTP/SMS API contracts, PIN в ЛК после нужной проверки; **проверять готовность отдельно**, не объявлять все public endpoints действующими из одного CI.

## 3. Что действительно работает на сайте, подтверждено 24.10.2026

Оператор/владелец проверил реальный website email OTP login и затем SMS fallback: **оба работают**. Вход сайта: `local/components/eurasia/signin/class.php`, `local/components/eurasia/totp.enrollment/class.php`, UI в шаблонах. В октябре инцидент: общий Redis SMS бюджет 500/сутки достиг 500/500 и блокировал вход. На сайте точечно убраны **два глобальных блокирующих вызова** `GlobalSmsBudget::reserve` в signin/totp.enrollment, индивидуальные ограничения и OTP/SMS challenge оставлены; production проверен владельцем. **Никогда не возвращать общий SMS потолок 100/час, 500/сутки, 100/IP/час автоматически.** Если нужны лимиты, после 30–60 дней реальных агрегированных данных и решения владельца. Документ: [EVRASIA_WEBSITE_EMAIL_FIRST_SMS_PRODUCTION_ACCEPTANCE_2026-10-24.md](EVRASIA_WEBSITE_EMAIL_FIRST_SMS_PRODUCTION_ACCEPTANCE_2026-10-24.md).

**Это не значит, что V5 mobile auth внедрена**. Веб-код использует PHP session и site-specific AJAX; V5 нужен независимый по устройству одноразовый state и mobile JWT/refresh.

## 4. Проверенные реальные факты: `eurasia_new` (read-only, 25.10)

Пользователь прислал успешные выводы трёх read-only команд на `root@evrasia`, `FINAL_STATUS=PASS`, `FINAL_RC=0`; отсутствовали user record reads, DDL, database write, email/SMS.

### Схема
Все обнаруженные **InnoDB**:
`b_user`, `b_sec_user`, `ev_samzaberu_auth_state`, `ev_v5_auth_challenges`, `ev_v5_sms_outbox`, `ev_v5_strong_sessions`, `ev_web_email_ownership_proof`.
`b_user`: `ID,LOGIN,EMAIL,PASSWORD,ACTIVE,BLOCKED,PASSWORD_EXPIRED,TIMESTAMP_X` и в отдельной проверке **`LOGIN_ATTEMPTS int(18) NULLABLE=YES`**. V5 должен deny на NULL, не превращать в 0.
`b_sec_user`: `ACTIVE,DEACTIVATE_UNTIL,SECRET,TYPE,USER_ID`.
`ev_samzaberu_auth_state`: `USER_ID,AUTH_EPOCH`.
`ev_web_email_ownership_proof`: `user_id,current_email_mac,profile_updated_at,revoked,source,verified_at`.
**`ev_v5_email_auth_challenges` ОТСУТСТВУЕТ**; SQL кандидат `site-integration/samzaberu-v5/schema/PROPOSED_ev_v5_email_auth_challenges.sql` не применён и не разрешён автоматически. Не создавать вторую БД и не считать действующий `ev_v5_auth_challenges` автоматически эквивалентным; сначала проверить архитектуру и согласовать минимальное изменение существующей БД.

### Почта Bitrix: текущий EMAIL-login event (реально прочитан)
`b_event_message.ID=95`, `EVENT_NAME=EVRASIA_WEB_EMAIL_LOGIN`, `ACTIVE=Y`, `LID=s1`, `b_event_message_site` связывает 95 с **`SITE_ID=s1`**. В существующем шаблоне: `#EMAIL_TO#=MATCH`, `#VERIFY_CODE#=MATCH`; `#CODE_TTL_MINUTES#=MISSING`, это не blocker: **TTL 300 сек проверяется сервером**, не телом письма. `ID=94 / EVRASIA_WEB_EMAIL_OWNERSHIP` — отдельная процедура подтверждения владения почтой, не путать. `USER_OTP_AUTH_CODE` (ID 92) ACTIVE=N. Контактные данные и тела писем не выводили.

## 5. Реальная точка разработки feature-only V5

Исходники `site-integration/samzaberu-v5/src/` уже содержат:
- `AuthDecision.php`, `V5LoginWorkflow.php`, `V5SeparatedFactorLifecycle.php`: `trusted → TOTP → EMAIL`, SMS по явному запросу, без silent downgrade.
- `V5MySqlEmailOtp.php`: candidate **mobile-bound email OTP**, отдельный одноразовый статус challenge, HMAC 6-значного кода, TTL <=300с, счётчик ошибок, device/auth-epoch/email-stamp binding, rate port и проверка transport acceptance. Не deployed.
- `V5BitrixLockedEmailOtpProof.php`: native locked proof текущего пароля, login eligibility, b_user, MFA, `AUTH_EPOCH`, verified `ev_web_email_ownership_proof`; default fail-closed. Ещё требует реальной native hook/source attestation.
- `V5BitrixEmailOtpTransport.php`: штатный Bitrix D7 `Mail\Event::send`, строго event **`EVRASIA_WEB_EMAIL_LOGIN`**, ID **95**, site **`s1`**, `DUPLICATE=N`; QUEUED не равно inbox delivered.
- `V5BitrixCurrentEmailLoginTemplateProof.php`: узкая runtime проверка активного event 95/site s1 и нужных placeholder-флагов на прежнем connection, без выгрузки тела письма.
- `V5BitrixPdoCompatibilityBridge.php`: exact SQL allowlists (включая email и mail metadata) и ограниченные savepoint guards, без generic user SQL.
- `V5MySqlConsumedEmailGrantProof.php` + `V5MySqlConsumedChallengeProof.php`, `V5MySqlStrongSessionStore.php`: повторная проверка погашенного OTP и grant перед JWT; **не доказано живым full E2E**.
- SMS: `V5BitrixLockedSmsEligibility.php`, `V5SmsDispatchBudget.php`, `V5MySqlSmsOutbox.php`, `V5MySqlSmsDispatchClaims.php`, `V5MySqlChallengeConsumption.php`, `V5MySqlChallengeHttpPort.php`: код существующих SMS candidate; нет доказательства complete live mobile provider lifecycle/receipt; не переписывать, сохранять V4/site.
- **САМОЕ ВАЖНОЕ ДОПОЛНЕНИЕ ПЕРЕД HANDOFF:** `V5ExistingWebsiteEmailBudget.php` — `V5EmailOtpRatePort` вызывает готовый `deployment/website-mfa/EmailSendBudget.php::reserve()` через **тот же website Redis**. НЕ новая система лимитов. Параллельный SQL email rate limiter и его таблица удалены из текущей feature ветки. `V5MySqlEmailOtp` уже передаёт текущий verified email в `reserveWithLocks(...,$identity->address)`.
- **ОСТОРОЖНО:** `EmailSendBudget.php` в GitHub содержит cooldown **60 сек/user**, **5 email/час/user**, **GLOBAL_PER_HOUR=1000** и namespace `evrasia:{web_mfa_email_login}:v1:`. Это НЕ доказательство действующей production версии. При совместной нагрузке сайт+приложение существующий общий потолок может блокировать обоих. НЕЛЬЗЯ незаметно вводить новый потолок или делить этот budget без проверки текущей конфигурации/нагрузки и решения владельца. Lua quota атомарна в Redis, но **не является одной транзакцией с SQL**: SQL rollback может оставить потраченный cooldown, это fail-closed, но риск UX.
- Новый thin adapter есть в GitHub, но **НЕ установлен** как native composition и не проверен с настоящим production Redis/Bitrix.

Подробная фиксация и коммиты: [SAMZABERU_V5_REUSE_WEBSITE_EMAIL_BUDGET_2026-10-25.md](SAMZABERU_V5_REUSE_WEBSITE_EMAIL_BUDGET_2026-10-25.md), [SAMZABERU_V5_REAL_SCHEMA_EMAIL_PROOF_CHECKPOINT_2026-10-25.md](SAMZABERU_V5_REAL_SCHEMA_EMAIL_PROOF_CHECKPOINT_2026-10-25.md), [SAMZABERU_V5_EMAIL_FIRST_AUTH_DECISION_2026-10-25.md](SAMZABERU_V5_EMAIL_FIRST_AUTH_DECISION_2026-10-25.md).

## 6. Тесты и достоверность

- Целевой workflow `.github/workflows/v5-native-first-factor-offline.yml`: [GitHub Actions **#40177880562**](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40177880562) **COMPLETED SUCCESS** на коммите `96dcfb65309a...`. В том числе `V5ExistingWebsiteEmailBudgetTest.php`: **17 assertions PASS**. PHP lint и остальные предусмотренные provider/source-only gates в target workflow PASS; никаких DB/Redis connections, реальных клиентов или сообщений.
- Ранее [#40177120967](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40177120967) **SUCCESS**: 35 проверок шаблона 95/s1, 22 D7 transport, 38 SQL guard и PHP lint.
- Общий `V5 offline safety checks` неоднократно показывал FAIL, ранее причиной была регрессия JavaScript `WebsiteEmailAccountConfirmWidgetClickTest.cjs`; не заявлять, что весь CI зелёный или что конкретная новая ошибка не повлияла без проверки точных логов.
- Наличие unit tests не означает: что весь website source GitHub совпадает с production, что нативные hooks `AUTH_EPOCH` готовы, что Redis бюджет production соответствует source, что V5 auth endpoint/real mail/real tokens уже работают.
- Удалённые ephemeral MariaDB тесты **не запускать и не восстанавливать**. Handoff формируется только из GitHub и уже полученных операторских read-only результатов.

## 7. Что осталось реально сделать — следующий чат НЕ ДОЛЖЕН стартовать новый rate limiter

**Приоритет — доставить рабочий API V5, не продолжать бесконечное исследование.**

1. В первую очередь проверить по актуальному GitHub source состояние композиции V5 (почта/OTP/Redis/сессии, public admission) и **точно определить, что НЕ подключено**, не повторяя уже изученные schema/event/SITE_ID факты. В новый чат не нужно переприсылать последние три read-only команды: их результаты сохранены здесь.
2. Узко и **только READ-ONLY на действующем `evrasia`** подтвердить, что установленный website `EmailSendBudget` и Redis deployment действительно соответствуют source, снимать только версии/hash/настройки без секретов и Redis-пользовательских ключей. Проверить risk `GLOBAL_PER_HOUR=1000` и возможный cross-channel denial. **Не менять сайт и лимиты без решения владельца.**
3. Подключить **существующий** `V5ExistingWebsiteEmailBudget` (не писать вторую rate-систему), `V5BitrixLockedEmailOtpProof`, native event 95/s1/fields, email challenge issuer/consumption, current device and MFA epoch, `V5MySqlConsumedEmailGrantProof`, strong session issuance в **V5-only native composition/HTTP routes**, сохраняя explicit fail-closed behavior. Использовать site-owned transport, не копировать и не раскрывать production website PHP/JS. Проверить и исправить source-level несовпадения контрактов до установки.
4. Отдельно обсудить с владельцем **необходимость и минимальность** `ev_v5_email_auth_challenges` в существующей `eurasia_new`, со схемой/backup/rollback/approval. Ничего не создавать, пока не согласовано. Не предполагать, что уже существующий `ev_v5_auth_challenges` можно использовать под email без read-only контрактной проверки.
5. Сохранять TOTP/trusted/SMS flows, MFA, legal/consents/signup и V4 независимыми; затем **реальный E2E только с настоящими разрешёнными аккаунтами и явным согласием**, под guard, без синтетических пользователей, без массовых SMS/email, с контролем rollback. Включение V5 AUTH только по отдельному явному разрешению после полной native Bitrix proof/lifecycle verification.
6. GitHub commit/CI по каждому небольшому feature шагу, всегда проверять HEAD перед CAS (`Restis` делает параллельные коммиты). Не присваивать production readiness только на основе PASS PHP.

**Следующий конкретный шаг для ассистента:** проверить исходники `V5ExistingWebsiteEmailBudget.php`, `V5MySqlEmailOtp.php`, `V5MySqlPublicComposition.php` и текущий source/site Redis interface и **собрать реальный V5-only composition plan, переиспользуя сайт**, без ещё одного SQL/Redis budget. Если действительно нужен операторский вывод, давать только узкую проверку installed source/Redis и ждать её реального результата перед выводами о prod. Не запускать новые ДБ/ботов/аккаунтов и не менять production.

## 8. Контроль статусов

`REPO=juvantusik/Evrasia_AI_bot`
`BRANCH=feature/samzaberu-api-v5-auth-contract`
`PRE_HANDOFF_HEAD=9a242d3ae1b5b9aaecde479e4506e741d37f0658`
`WEBSITE_EMAIL_FIRST=OWNER_ACCEPTED_WORKING`
`WEBSITE_SMS_FALLBACK=OWNER_ACCEPTED_WORKING`
`CURRENT_PUBLIC_DOMAIN=https://evrasia.rest`
`LIVE_MAIL_EVENT=EVRASIA_WEB_EMAIL_LOGIN`
`LIVE_MAIL_TEMPLATE_ID=95`
`LIVE_MAIL_SITE_ID=s1`
`REAL_DB=eurasia_new`
`REAL_EMAIL_CHALLENGE_LEDGER=NOT_PRESENT`
`V5_WEBSITE_EMAIL_RATE_REUSE_CANDIDATE=IN_FEATURE_BRANCH`
`V5_WEBSITE_EMAIL_RATE_PROD_ATTESTED=NO`
`V5_EMAIL_REDIS_GLOBAL_CAP_IN_GITHUB_SOURCE=1000_PER_HOUR`
`V5_EMAIL_REDIS_GLOBAL_CAP_LIVE_CONFIRMED=NO`
`V5_AUTH_PRODUCTION=OFF`
`V4_CHANGED_BY_THIS_HANDOFF=NO`
`PRODUCTION_DB_WRITE_BY_THIS_HANDOFF=NO`
`WEBSITE_SOURCE_PUBLISHED=NO`
`ISOLATED_DB_CREATED=NO`
`SYNTHETIC_USERS_CREATED=NO`
`NEW_CHAT_NEXT=REUSE_LIVE_WEBSITE_SERVICES_IN_V5_ONLY_COMPOSITION`

Не нужно спрашивать пользователя заново подтверждённые факты. Текст нового сообщения хранится в соседнем `.txt`, и в ответе ассистента должен быть выдан полностью.
