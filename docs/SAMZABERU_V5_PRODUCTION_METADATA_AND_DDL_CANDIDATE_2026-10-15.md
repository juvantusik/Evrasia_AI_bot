# СамЗаберу V5 — production metadata result и additive DDL candidate

15 октября 2026. Основание — read-only metadata output с `evrasia` / schema `eurasia_new`.

## Подтверждено production

- MariaDB 11.4.5.
- `b_user`, `b_sec_user`, `b_user_phone_auth`, `ev_trusted_devices` существуют как InnoDB.
- `b_user.LOGIN` уникален только в composite `(LOGIN, EXTERNAL_AUTH_ID)`; `EMAIL` имеет non-unique index. Поэтому V5 сохраняет unique-resolution через LIMIT 2/fail-closed и не полагается на DB-уникальность EMAIL.
- `b_user_phone_auth`: PRIMARY(USER_ID), UNIQUE(PHONE_NUMBER), `CONFIRMED` nullable CHAR(1).
- `ev_trusted_devices` остаётся V4 trust и не считается strong V5 trust автоматически.
- Шесть V5 таблиц в metadata output отсутствуют: `ev_samzaberu_auth_state`, `ev_v5_auth_attempt_windows`, `ev_v5_auth_challenges`, `ev_v5_sms_budget_windows`, `ev_v5_sms_outbox`, `ev_v5_strong_sessions`.
- Read-only scope завершён; client rows/DDL/write не выполнялись.

## DDL candidate

Добавлен `schema/PRODUCTION_CANDIDATE_v5_additive_2026-10-15.sql`.

Он создаёт ровно шесть новых InnoDB таблиц из уже проверенных V5 schema contracts и:
- не ALTER-ит Bitrix/V4 таблицы;
- не создаёт `ev_v5_native_tx_probe`;
- не использует `IF NOT EXISTS`, чтобы не скрыть неожиданное/частичное состояние;
- не вставляет строки, не делает backfill, не меняет GRANT/auth и не включает V5;
- сохраняет FK только между новыми `ev_v5_sms_outbox` → `ev_v5_auth_challenges`;
- намеренно не добавляет FK к `b_user`: production `b_user.ID` — signed INT, а существующие V5 contracts используют BIGINT UNSIGNED user_id; менять type contract без отдельного review не нужно.

Это **кандидат**, а не разрешённая production migration.

## Критический blocker после CREATE

Текущий `V5SecurityEventVersions` и writer fail-closed при отсутствии per-user строки в `ev_samzaberu_auth_state`. Runtime сам её не создаёт. Поэтому после одного только CREATE:
- V5 остаётся OFF;
- AUTH_EPOCH не backfill-ится;
- ни один пользователь не считается covered;
- strong session/SMS/TOTP begin не должны становиться доступны.

Per-user initialization AUTH_EPOCH=1 допустима только вместе с установленными и принятыми native password/MFA/phone/logout/block/recovery hooks, которые сериализуются на b_user lock и обновляют epoch в той же физической MySQL transaction. PHONE_EVENT_VERSION нельзя выводить из текущего текста телефона; trusted confirmation event нужен отдельно.

## Следующий порядок

1. Отдельное явное разрешение на additive DDL production.
2. Перед DDL: повторный metadata guard, backup/evidence, V5 mode=off, native factory absent, V4 hashes unchanged.
3. CREATE шести новых пустых таблиц.
4. Post-check: exact columns/indexes/constraints/engines, row count zero, V4 unchanged, V5 по-прежнему 503/off.
5. Не выполнять DROP автоматически. Rollback при failed migration проектируется по фактической фазе и только пока таблицы гарантированно пусты.
6. После этого — native security hooks + controlled initialization только approved pilot accounts; затем native TOTP/replay/strong trust/phone/SMS/RestIS и pilot.

Статусы после metadata result: `PRODUCTION_METADATA_READ=PASS`, `V5_TABLES_PRESENT=NO`, `DDL_CANDIDATE_READY=YES`, `DDL_EXECUTED=NO`, `PRODUCTION_AUTH_ENABLED=NO`.
