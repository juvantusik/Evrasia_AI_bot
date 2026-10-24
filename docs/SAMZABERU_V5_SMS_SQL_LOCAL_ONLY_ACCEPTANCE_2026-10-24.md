# СамЗаберу API V5 — транзакционная SMS-очередь без обязательных общих лимитов (24.10.2026)

## Решение владельца и причина

После подтверждённого сбоя website login из-за исчерпанного общего лимита **500 SMS/день**, владелец решил: **не устанавливать общие блокирующие SMS ceilings, пока нет статистики реального трафика**. Рабочий сайт / API V4 не менять, источник SMS challenge и OTP всё равно проверять на сервере. Это решение НЕ означает отказ от индивидуального anti-spam.

## Исправленная цепочка feature-only V5

1. `V5SmsBudgetPolicy` и `V5SmsDispatchBudget` (код `2ea69bc2bcc1d6d66e661ade21553be9e7701c61`) поддерживают **7 обязательных** ключей: `account_burst/account_day`, `phone_burst/phone_day`, `ip_burst/ip_day`, `device_burst`. Необязательные `global_burst/global_day/global_cost_day` оставлены для последующего измерения и согласования. Не возвращать 100/hour, 500/day и 100/IP как универсальные лимиты.
2. В SQL `V5MySqlSmsOutbox::assertWindows` обнаружен разрыв: даже после (1) он продолжал требовать **ровно 10 ключей и ровно одну global-cost бронь**. Это отклоняло бы легитимный 7-window SMS challenge ещё до отправки. Коммит [`4de04f172fe4317070ed8a5c1223a3b7cf1e4cf5`](https://github.com/juvantusik/Evrasia_AI_bot/commit/4de04f172fe4317070ed8a5c1223a3b7cf1e4cf5) исправил проверку для **7–10** HMAC окон и необязательной global-cost брони, сохраняя lock-order, цену при наличии budget, encrypted outbox, atomic rollback, resend cooldown, same challenge, identity and verified phone.
3. В том же коммите добавлены local-only регрессии в `tests/V5MySqlSmsOutboxTest.php` (fake-PDO транзакции и ошибки) и `tests/integration/V5EphemeralMySqlSmsOutboxTest.php` (реальная отдельная CI MariaDB/InnoDB, 7 новых окон, одно сообщение queued, запрет повторной отправки).
4. Коммит [`a3ba7d4429dfec53fc2f05b57835957abe33c6c2`](https://github.com/juvantusik/Evrasia_AI_bot/commit/a3ba7d4429dfec53fc2f05b57835957abe33c6c2) добавил SQL outbox PHP lint и unit-test к отдельному V5 first-factor workflow.

## Фактическая проверка GitHub Actions

- [`#40115408846`](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40115408846) **completed SUCCESS**: `PHP_LINT=PASS`, `V5SmsDispatchBudgetTest.php` **125 assertions PASS**, `V5MySqlSmsOutboxTest.php` **137 assertions PASS**, `FIRST_FACTOR_PUBLIC_GATES=PASS`.
- [`#40115373386`](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40115373386) **completed SUCCESS**: independent ephemeral MariaDB InnoDB `V5EphemeralMySqlSmsOutboxTest.php` **138 assertions PASS**, `TWO_PROCESS_SMS_FIRST_SEND_RACE=YES` (also exercises encrypted outbox, previous 10-window compatibility, new 7-window local-only policy). No production DB.
- [`#40115373410`](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40115373410) separate overall `V5 offline safety checks` **failure** caused by unrelated pre-existing `WebsiteEmailAccountConfirmWidgetClickTest.cjs` regression, not by this verified SQL/SMS feature. Do not claim all CI green.

## Boundaries / next task

`API_V4_CHANGED=NO`; `WEBSITE_AUTH_CHANGED=NO`; `PRODUCTION_DB_WRITE=NO`; `PRODUCTION_SMS_SENT=NO`; `V5_AUTH_PRODUCTION=OFF`.

`NEXT_STEP=V5_REAL_NATIVE_FACTOR_LIFECYCLE`: V5 public composition still needs a genuinely attested `V5PublicVerifiedFactorLifecyclePort`, locked native account/epoch/phone and TOTP replay evidence on same physical transaction, strong trust validation, provider receipt-backed SMS send, site-specific login veto controls, and Bitrix real end-to-end. Feature-only seven-window SQL tests do **NOT** establish production delivery or public mobile login.

Public API base is `https://evrasia.rest/api/v5`. Preserve website trusted-device-first policy and V4 business-service reuse, do not copy V4 JWT as V5 MFA proof. No synthetic production accounts and no unapproved launch.
