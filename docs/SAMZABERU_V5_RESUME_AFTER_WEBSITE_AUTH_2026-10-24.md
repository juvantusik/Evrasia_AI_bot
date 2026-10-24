# СамЗаберу API V5 — продолжение после Website email/SMS acceptance (24.10.2026)

## Решение владельца

- Не публиковать действующие PHP/JS сайта в GitHub. Предыдущая локальная подготовка source snapshot завершилась без публичного push, и её повторение отменено владельцем. В GitHub остаётся документация результатов [Website email/SMS acceptance](EVRASIA_WEBSITE_EMAIL_FIRST_SMS_PRODUCTION_ACCEPTANCE_2026-10-24.md).
- Пользователь подтвердил реальные успешные сценарии Website email OTP и SMS fallback. Для сайта сняты блокировки по общему Redis-бюджету после инцидента 500/500 SMS в сутки; индивидуальные anti-spam и проверка SMS/TOTP сохранены.
- Приоритет возвращается к независимому mobile API V5. Сайт не переписывать и повторно не изучать работу email/SMS. Production V4 не менять, V5 авторизацию не активировать без нативных proof gates.

## Проверенный GitHub state

Рабочая ветка `feature/samzaberu-api-v5-auth-contract` после website docs commit `bc01638248ba3091de47ff40e1f04003c792b5e8` содержит V5 независимый router и реализации V5LoginWorkflow (trust-first), `V5BitrixLockedTotpFactor` (native OTP stage-only), `V5MySqlStrongSessionStore`, `V5MySqlPublicComposition`, `V5BitrixCartApiServiceReader` и `V5RouteMap` (11 объявленных методов). Объявленный маршрут **не означает production acceptance**.

- Публичные legal GET `/api/v5/legal/consents`, `/api/v5/legal/registration-documents` уже имели независимое внешнее подтверждение 23.10.2026, см. [External acceptance](SAMZABERU_V5_EVRASIA_REST_EXTERNAL_ACCEPTANCE_2026-10-23.md).
- V5 legal validation POST имел origin acceptance; mobile V5 SIGNIN OFF и native Bitrix end-to-end не приняты.
- Мобильный trust-first алгоритм в V5 реализован feature-only: проверенное сильное доверенное устройство отменяет повторный TOTP; без траста при активном TOTP требуется TOTP; без TOTP и траста — SMS challenge. Не принимать V4 password-only trust за MFA.
- V5 корзина использует `CartApiService` из V4, но native FUSER/owner same-transaction E2E остаётся pending. Никакого отдельного расчёта цен.
- `V5SmsBudgetPolicy` содержит обязательные global/hour/day/cost scopes **feature-only**. Не утверждать их для live mobile и не переносить старые блокирующие цифры 100/час, 500/сутки, 100/IP/час; сначала измерить реальные объёмы и согласовать политику. При этом нужно сохранить индивидуальный cooldown, предотвращение спама, challenge и provider idempotency. Изменение политики требует отдельной реализации и тестов, нельзя просто включить V5.

## Следующий конкретный технический этап

`NEXT_STEP=NATIVE_GETPOLICY_DECLARATION_AUDIT` → узкая адаптация `V5PinnedBitrixLoginAttemptLimits` / `V5PinnedBitrixPasswordLoginAdmission`, если текущая реализация `CUser::getPolicy` объявлена в другом классе/файле. Первый read-only операторский шаг: проверить source SHA `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99` для `bitrix/modules/main/classes/general/user.php`, показать **только** фактическое объявление `getPolicy`, его static/visibility/class/file и source references; без Bitrix bootstrap, вызова метода, пользовательских данных, DB, SMS и website code writes.

Следом: положительный источник действующих site login restrictions для V5CurrentLoginControlsPort, плюс ten-path AUTH_EPOCH source/transaction ownership и server-native TOTP consumption. Release status `V5_AUTH_PRODUCTION=OFF` до реального native Bitrix E2E; не выдавать offline tests за acceptance.

`SOURCE_SYNC_OF_WEBSITE_FILES=CANCELLED`; `WEBSITE_AUTH_ACCEPTED=YES`; `V4_MUST_REMAIN_ACTIVE=YES`; `V5_PUBLIC_AUTH_READY=NO`.
