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
