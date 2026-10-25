# Evrasia AI Bot / СамЗаберу API V5 — handoff 25 октября 2026
## АКТУАЛЬНАЯ ТОЧКА ПЕРЕХОДА В НОВЫЙ ЧАТ

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
