# СамЗаберу API V5 — результаты реальной схемы и email JWT proof (25.10.2026)

## Единственная проверка НА РЕАЛЬНОМ Bitrix (предоставленный вывод root@evrasia)

Команда `v5-real-bitrix-email-schema-readonly.sh` из immutable `b3ed0882a68253fb16083c72f0001d7445ec1b53`, Git blob `9ee4b88ab04dc9c18fbb59729fb2aaf200c29f8d`, оператором выполнена на `evrasia` **только на схеме настоящей `eurasia_new`**. `FINAL_STATUS=PASS`, `FINAL_RC=0`, `REAL_DATABASE_IDENTITY=PASS`, pinned `bitrix/modules/main/classes/general/user.php` SHA PASS, `DATABASE_WRITE=NO`, `USER_RECORDS_FETCHED=NO`, `DDL_EXECUTED=NO`.

Подтверждены **InnoDB** `b_user`, `b_sec_user`, `ev_samzaberu_auth_state`, `ev_web_email_ownership_proof`, `ev_v5_auth_challenges`, `ev_v5_sms_outbox`, `ev_v5_strong_sessions`.

На 25.10.2026 **НЕ НАЙДЕНА** `ev_v5_email_auth_challenges` в реальной `eurasia_new`. Данный namespace/table пока только PROPOSED: `site-integration/samzaberu-v5/schema/PROPOSED_ev_v5_email_auth_challenges.sql`. Не создавать без отдельного согласования и точной схемы миграции в **ТОЙ ЖЕ** production базе, backup and rollback. Запрет владельца на изолированные/тестовые БД и синтетические аккаунты сохраняется; удалён workflow ephemeral MariaDB (`a651d121`) и новый test fixture (`69f9d870`).

Проверенные поля (не данные):
- `b_user`: `ID,LOGIN,EMAIL,PASSWORD,ACTIVE,BLOCKED,PASSWORD_EXPIRED,TIMESTAMP_X` (8/8 из запрошенных).
- `b_sec_user`: `USER_ID,ACTIVE,TYPE,SECRET,DEACTIVATE_UNTIL` (5/5).
- `ev_samzaberu_auth_state`: `USER_ID,AUTH_EPOCH` (2/2).
- `ev_web_email_ownership_proof`: `user_id,current_email_mac,profile_updated_at,verified_at,source,revoked` (6/6).

**`LOGIN_ATTEMPTS` НЕ запрашивался в этой проверке**: он нужен V5 native policy и требует отдельного read-only metadata подтверждения, не предполагать его наличие. Из факта наличия таблицы email ownership не следует, что у конкретного гостя есть живое подтверждение email или что mail transport готов.

## Выполнено в GitHub (только feature-only, ВЕБ-САЙТ И V4 НЕ ИЗМЕНЯЛИСЬ)

- [`6889db9668a8b465296a8f1c8c45a354d75f4865`](https://github.com/juvantusik/Evrasia_AI_bot/commit/6889db9668a8b465296a8f1c8c45a354d75f4865): `V5BitrixLockedEmailOtpProof` — native staged adapter к **уже имеющимся** четырём таблицам; требует ту же PDO/Bitrix physical transaction, fixed b_user + epoch, однозначный login, текущий password comparator и native password admission, положительную current login policy, native security event coverage, подтверждённый MFA-not-mandatory policy, **отсутствие любой b_sec_user credential row**, и точный website `VerifiedEmailStatus::evaluate` (чтение `ev_web_email_ownership_proof` + текущих `b_user.EMAIL/TIMESTAMP_X`). Stamp привязан не только к email MAC, но и к `verified_at`, profile timestamp и `AUTH_EPOCH`; отзыв или переверификация не должны оживлять старый код. Ни одна из required внешних native policies не заменена `true`.
- [`ac05aa99832f2ea4682840e4c74d1be96f70c460`](https://github.com/juvantusik/Evrasia_AI_bot/commit/ac05aa99832f2ea4682840e4c74d1be96f70c460): `V5MySqlConsumedEmailGrantProof` — отдельный read-only source of truth для уже consumed email OTP перед issuance JWT: b_user locked, текущий epoch, expired/replayed/changed/device stamp and email proof check, код стёрт из ledger. Повторно вызывает native email proof перед выпуском.
- [`97a461165c1c48909399e3c90a7b56ece59c6852`](https://github.com/juvantusik/Evrasia_AI_bot/commit/97a461165c1c48909399e3c90a7b56ece59c6852): `V5MySqlConsumedChallengeProof` поддерживает опциональный независимый `V5LockedConsumedEmailGrantProofPort` и **по умолчанию DENY** для EMAIL без подключённого порта. `V5MySqlStrongSessionStore` и `V5SessionAssurancePolicy` распознают email assurance только при verified consumed grant. Старые TOTP/SMS/trust paths остаются.
- [`89e42dde209f78535db29d0c534399e10b2dc3d4`](https://github.com/juvantusik/Evrasia_AI_bot/commit/89e42dde209f78535db29d0c534399e10b2dc3d4): PHP lint + class load CI без Bitrix bootstrap, тестовой БД, пользователей, почты и SMS.

## НЕ подтверждено / release gates

1. Отсутствующая `ev_v5_email_auth_challenges`: никакого реального email issuance/consumption до отдельно согласованной миграции в existing `eurasia_new`. Нельзя выдавать V5 email flow за работающий.
2. `V5BitrixPdoCompatibilityBridge` ещё НЕ содержит exact allowlist для этих новых SQL шаблонов. Нельзя включать public V5 с wildcard SQL; не придумывать совместимость.
3. `V5EmailOtpRatePort` и `V5EmailOtpTransportPort` — ещё обязательные server-side зависимости без поставленных live production адаптеров. Сайт использует собственный EmailOwnershipMailTransport; user-facing login event надо проверить, не копировать сайт-контроллер или PHP session.
4. `LOGIN_ATTEMPTS` нужно подтвердить metadata в реальной БД. Отсутствие native per-user AUTH_EPOCH и hook coverage оставляет V5 AUTH OFF.
5. Existing mail/website login remains accepted, not evidence of mobile V5 email challenge deployment. No synthetic users, no isolated DBs, no automatic SMS fallback, no production writes.

## Next

Одно узкое **только читающее** подтверждение `LOGIN_ATTEMPTS` и native mail event configuration в текущей `eurasia_new`, затем завершить native email transport/rate/Bitrix PDO exact SQL candidate без production записи. Для запуска `V5_AUTH_PRODUCTION` потребуется отдельная полная фактическая Bitrix приёмка и явное решение владельца.

## CI acceptance: 25.10.2026

[GitHub Actions #40175634354](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40175634354): **completed SUCCESS**, `PHP_LINT=PASS` для `V5BitrixLockedEmailOtpProof.php`, `V5MySqlConsumedEmailGrantProof.php`, `V5MySqlConsumedChallengeProof.php`; `EMAIL_NATIVE_CLASS_GRAPH=PASS`, `FIRST_FACTOR_PUBLIC_GATES=PASS`. Эта проверка **не создаёт БД/таблиц/пользователей, не инициализирует Bitrix, не отправляет email/SMS**. Результат подтверждает только исходный PHP-контракт и существующие offline gates; не реальный нативный login E2E.

## Дополнительная схема transport

Проверенный `deployment/website-mfa/EmailOwnershipMailTransport.php` использует event `EVRASIA_WEB_EMAIL_OWNERSHIP`, который предназначен для **подтверждения владения почтой**, а не обязательно для одноразового кода *входа*. В website `EmailLoginChallenge.php` mail sender подаётся сервером через callback. Нельзя автоматически использовать ownership event для mobile login без подтверждения действующего email-login mail transport/template на живом Bitrix. Не копировать сайт-код в публичный репозиторий.

## 25.10.2026 — exact b_user.LOGIN_ATTEMPTS evidence

Оператор на **той же реальной `eurasia_new`** выполнил read-only `information_schema.COLUMNS` запрос: `LOGIN_ATTEMPTS_COLUMN_COUNT=1`, `COLUMN=LOGIN_ATTEMPTS TYPE=int(18) NULLABLE=YES`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `USER_RECORDS_READ=NO`, `DATABASE_WRITE=NO`. Это **только схематический факт**: он не доказывает наличие `NULL` у конкретного пользователя. Текущий `V5BitrixLockedEmailOtpProof::user()` проверяет `LOGIN_ATTEMPTS` строгим `nat()`; отсутствие или NULL вызывает DENY (никогда не конвертируется в «0 попыток»). Существующая `V5SiteAccountReader` также отказывает на NULL.

## 25.10.2026 — exact native EMAIL SQL allowlist

[`8cfbfc381378dbbb3aa713c645cfabf522bf7cae`](https://github.com/juvantusik/Evrasia_AI_bot/commit/8cfbfc381378dbbb3aa713c645cfabf522bf7cae): в `V5BitrixPdoCompatibilityBridge` добавлены **ровно 18** известных подготовленных SQL форм от V5 email issuer, locked-native identity/ownership proof и consumed-email grant verifier, включая чтение исключительно указанной `ev_web_email_ownership_proof` строки. Разрешены 4 жёстко названных пары `SAVEPOINT/RELEASE` (`issue/rate/ack/verify`). Оставшиеся SELECT/INSERT/UPDATE с `ev_v5_email_auth_challenges` или `ev_web_email_ownership_proof` запрещены явно. **Никакого общего SQL wildcard, DDL, email-массчтения**.

[`44943a322cd68080a8295632669ff19b65b0de44`](https://github.com/juvantusik/Evrasia_AI_bot/commit/44943a322cd68080a8295632669ff19b65b0de44) — чистый PHP тест `V5EmailNativeSqlAllowlistTest.php`, ни одной подключённой БД и ни одного пользовательского объекта; проверяет все 18 разрешённых форм, 8 fixed savepoints, 12 отвергаемых подстановок/расширений и DDL. [`3a5e055e3230dab257385e186af2226b39882842`](https://github.com/juvantusik/Evrasia_AI_bot/commit/3a5e055e3230dab257385e186af2226b39882842) — тест привязан к `V5 first-factor and public admission offline`.

[GitHub Actions #40176109710](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40176109710): **COMPLETED/SUCCESS**, `PASS: V5 native email exact SQL only (38 assertions)`, `PASS: V5 separate TOTP/email/SMS routing with optional email and fail-closed (33 assertions)`, `EMAIL_NATIVE_CLASS_GRAPH=PASS`, `PHP_LINT=PASS`, `FIRST_FACTOR_PUBLIC_GATES=PASS`. **NO DB**, NO Bitrix production writes, NO emails or SMS. Это тест SQL-шаблонов, НЕ реальная execution attestation / email delivery.

**Оставшиеся обязательные действия**: подтвердить существующий email-login mail event/template в установленном Bitrix (не подменять событием `EVRASIA_WEB_EMAIL_OWNERSHIP`); подключить server-side account/email/IP/device rate reservation с текущей транзакцией; решить миграцию недостающей `ev_v5_email_auth_challenges` **в уже существующей** `eurasia_new` отдельно от запрета владельца на isolated DB и synthetic accounts; native password+OTP+AUTH_EPOCH E2E для реального пользователя, strong session proof. До этого `V5_AUTH_PRODUCTION=OFF`.

## 25.10.2026 — Bitrix D7 mail transport без копирования website login

[`af131602c8a21537e4f782df9c1da5d8ed1c1b8b`](https://github.com/juvantusik/Evrasia_AI_bot/commit/af131602c8a21537e4f782df9c1da5d8ed1c1b8b): `V5BitrixEmailOtpTransport.php` вводит обязательный native `V5CurrentBitrixEmailLoginTemplatePort` с **положительным подтверждением точного активного email-login event + site ID + message ID** на каждый вызов. Никакого разрешающего по умолчанию шаблона. Событие `EVRASIA_WEB_EMAIL_OWNERSHIP` специально запрещено, потому что оно для первоначальной привязки email, а не для входа. Передача через штатный `Bitrix\\Main\\Mail\\Event::send`; `DUPLICATE=N`, 6 цифр, TTL 5 минут, однозначный recipient из серверного native email ownership. Положительный ответ D7 — только очередь (QUEUED), НЕ гарантированная доставка в почтовый ящик.

[`6c68eb9fb039048cd087656d64c01928ef5263d3`](https://github.com/juvantusik/Evrasia_AI_bot/commit/6c68eb9fb039048cd087656d64c01928ef5263d3): pure PHP regression `V5BitrixEmailOtpTransportTest.php` на инъецированной заглушке только почтовой очереди (НЕ аккаунте); no database, no SMTP, no Bitrix bootstrap, no mail. Проверяет отказ без attested template, блокировку ownership event, BCC, отсутствие паролей/User ID в payload, строгую валидацию адреса и кода. [CI registration `16d029415ab44c527162aa660c3a84c03f49d3cd`](https://github.com/juvantusik/Evrasia_AI_bot/commit/16d029415ab44c527162aa660c3a84c03f49d3cd).

[GitHub Actions #40176277318](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40176277318): **completed SUCCESS**, `PASS: V5 Bitrix D7 email-login transport (19 assertions)`; 38 existing native exact SQL checks PASS, PHP lint PASS, native class-graph PASS, first-factor public gates PASS. **Это не запуск доставки**.

Точный installed сайт email-login mail event и его активный message template пока НЕ получены из реальной `eurasia_new`; не устанавливать случайное `EVENT_NAME` или `MESSAGE_ID`. Adapter может быть подключён только после read-only attestation active event template и точного назначения email/login полей на живом Bitrix. Missing V5 email ledger и реальные auth/event-epoch hooks остаются release blockers. `V5_AUTH=OFF`, `PRODUCTION_WRITES=NO`.

## 25.10.2026 — получены реальные event/template IDs для входа по email

Оператор выполнил **read-only** запрос по `eurasia_new.b_event_message` на `evrasia`, `FINAL_STATUS=PASS`, `DATABASE_WRITE=NO`, `EMAIL_SENT=NO`:
- `ID=95, EVENT_NAME=EVRASIA_WEB_EMAIL_LOGIN, ACTIVE=Y` — **существующий активный шаблон входа**.
- `ID=94, EVENT_NAME=EVRASIA_WEB_EMAIL_OWNERSHIP, ACTIVE=Y` — **отдельное подтверждение владения почтой**, не использовать для login OTP.
- `ID=92, EVENT_NAME=USER_OTP_AUTH_CODE, ACTIVE=N` — выключен; не переключать V5 на него.
- `ID=93, EVENT_NAME=USER_OTP_EMAIL_CONFIRM, ACTIVE=N`; другие шаблоны не относятся к login OTP.

В `V5BitrixEmailOtpTransport` зафиксированы **единственно допустимые** live `EVENT_NAME=EVRASIA_WEB_EMAIL_LOGIN`, `MESSAGE_ID=95` (изменения [`191c52c1`](https://github.com/juvantusik/Evrasia_AI_bot/commit/191c52c19c76b952a1cd09102d372574cd53c178) и [`bcc98ceb`](https://github.com/juvantusik/Evrasia_AI_bot/commit/bcc98ceb4ce7273d7f8a87b3c8a50aa0d9e0b176)). Случайный event или ID 94 теперь отвергается **до почтовой очереди**. [GitHub Actions #40176698067](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40176698067) `SUCCESS`, 21 email transport assertions PASS; без БД, аккаунтов, реальных писем.

**Остаётся** read-only установить `SITE_ID` из `b_event_message_site`, а также формат `EMAIL_TO` и `VERIFY_CODE` placeholders в существующем шаблоне. `b_event_message.LID` может обозначать язык шаблона и НЕ является доказательством привязки к сайту. Не угадывать `s1` из unit fixture, не отправлять письма до подтверждения. `V5_AUTH_PRODUCTION=OFF`, missing `ev_v5_email_auth_challenges` blocker remains.

## 25.10.2026 — verified real email login template and site mapping (read-only)

Оператор подтвердил **на живой `eurasia_new`**, `FINAL_STATUS=PASS`, `FINAL_RC=0`, no DB/code writes, no user records, no mail:
- `b_event_message.ID=95`, `EVENT_NAME=EVRASIA_WEB_EMAIL_LOGIN`, `ACTIVE=Y`, `LID=s1`.
- `b_event_message_site.EVENT_MESSAGE_ID=95` связан с **`SITE_ID=s1`**.
- В существующем шаблоне: placeholder `#EMAIL_TO#` = MATCH, `#VERIFY_CODE#` = MATCH. `#CODE_TTL_MINUTES#` = MISSING — **не блокирующее** отсутствие презентационной переменной; серверный email OTP candidate имеет TTL <=300 секунд, и письмо не является authority для expiry.
- Тело письма, адресаты, коды не выводились и не передавались.

### Реализовано в feature-only GitHub

- [`f410ffa7`](https://github.com/juvantusik/Evrasia_AI_bot/commit/f410ffa71e595cdd3f7599383b39b62e3fb982a6) — `V5BitrixCurrentEmailLoginTemplateProof` использует **существующий** Bitrix/MySQL connection, перед каждой enqueue читает **только статус/название/язык/вычисленные SQL-флаги наличия полей**, и строго аттестует точный site-link. Тела `MESSAGE`/`EMAIL_TO` в PHP не загружаются, необязательный TTL placeholder не требуется, всё неоднозначное => DENY.
- [`c0e11e00`](https://github.com/juvantusik/Evrasia_AI_bot/commit/c0e11e00986aa9ba2b40cd7713bcf8e962c3320d) — ровно **две** новые read-only SQL формы для mail metadata в `V5BitrixPdoCompatibilityBridge`, все прочие mail table SELECT/UPDATE/DDL запрещены; нет generic allowlist.
- [`ebd61fd0`](https://github.com/juvantusik/Evrasia_AI_bot/commit/ebd61fd03ed550cf6bc90e70ca8e30d250adf750) — `V5BitrixEmailOtpTransport` закреплён на site **`s1`**, event `EVRASIA_WEB_EMAIL_LOGIN`, MESSAGE_ID `95`. Неверный site/template отклоняется ДО D7.
- [`bc38c061`](https://github.com/juvantusik/Evrasia_AI_bot/commit/bc38c06150e7c9f2b911e3184812f4fce83b309a) и [`2fbb6561`](https://github.com/juvantusik/Evrasia_AI_bot/commit/2fbb656180f3494d991512cd886c4818f85d6c29) — unit-policy checks и source tests без DB, synthetic accounts, Bitrix bootstrap, or mail send.
- [`55e79071`](https://github.com/juvantusik/Evrasia_AI_bot/commit/55e7907174f3dfa1b9c58b5a1856f8a135fa628f) — CI hook: [Actions #40177120967](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40177120967) **COMPLETED/SUCCESS**, `PASS: V5 Bitrix real email login template 95/s1 (35 assertions)`, `PASS: V5 Bitrix D7 email-login transport (22 assertions)`, `PASS: V5 native email exact SQL only (38 assertions)`, `PHP_LINT=PASS`, `EMAIL_NATIVE_CLASS_GRAPH=PASS`, `FIRST_FACTOR_PUBLIC_GATES=PASS`.

**Boundary:** CI validates source contract ONLY; it has not executed D7 mail, touched production DB, created `ev_v5_email_auth_challenges` or produced a mobile V5 session. The production V5 email lifecycle remains **OFF**. Required before actual issue: same-existing-DB approved `ev_v5_email_auth_challenges` migration; verified transactional email rate port (account/email/IP/device); native real account/epoch/password/MFA and mail queue E2E; complete Bitrix V5 route and signed grant. Website/V4 remain untouched. Owner forbids any isolated database or synthetic users; no such tests should be reintroduced.

