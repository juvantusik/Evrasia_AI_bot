# СамЗаберу V5 — фактические ветви CUser::LoginInternal (24.10.2026)

## Подтверждённая read-only проверка

Источник `/home/site_evrasia/web/evrasia.spb.ru/public_html/bitrix/modules/main/classes/general/user.php`, exact SHA256 `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99`. Ввод оператора `EV_V5_LOGIN_SITE_CONTROLS`: `NATIVE_BITRIX_SOURCE=PASS`, `NATIVE_POLICY_BRANCHES_FOUND=20`, `NATIVE_POLICY_SOURCE_SCAN=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`; bootstrap, DB, SMS и любые записи не выполнялись.

Из подтверждённого source-only фрагмента:
- `LoginInternal` обнаружен начиная около строки 1944 в показанном диапазоне (это диапазон, возвращённый лексическим сканером, а не полное объявление метода);
- `BLOCKED == Y`: отдельная ветка отказа, проверяется раньше политики (1949);
- `CUser::getPolicy` вызывается после проверки `BLOCKED` (1951);
- порог `getLoginAttempts()` >0 и **`$loginAttempts > $policyLoginAttempts`** включает CAPTCHA (1955–1964);
- `Password::equals` вызывается в правильной CAPTCHA-ветке (1970);
- `getBlockLoginAttempts/getBlockTime` вызываются в обработке ошибочного пароля; при `>=` порога активный аккаунт блокируется через `blockUser` (2010–2016);
- `PASSWORD_EXPIRED` проверяется отдельно (2049), `CheckPasswordAgainstPolicy` при `$original` (2055–57);
- `ACTIVE == Y` проверяется перед успешным завершением входа (2090);
- `blockUser()` вызывает `CUser::Update(BLOCKED=Y)` (2146–49) — **НЕ вызывать** из V5 read-only проверки.

В `local/php_interface` и `bitrix/php_interface` сканировано 259 PHP-файлов, лексических упоминаний перечисленных событий не найдено (`POTENTIAL_LOGIN_HANDLER_FILES=0`). **Это не доказательство**, что нет конфигурационных, DB-registered или динамических Bitrix обработчиков; нельзя на этом основании возвращать `siteLoginRulesClear=true`.

## Кодовый статус

Существующие `V5PinnedBitrixLoginAttemptLimits`, `V5PinnedBitrixPasswordLoginAdmission`, `V5SiteAccountReader` уже реализуют source-pinned `getPolicy`, post-hash native policy, account ACTIVE/BLOCKED/PASSWORD_EXPIRED. Их не переписывать; не называть V5 AUTH готовым. `V5CurrentLoginControlsPort` остаётся обязательной site-specific положительной проверкой current timed lock/security/site restrictions. В `V5BitrixNativeFirstFactorComposition` он по-прежнему передаётся извне (нет небезопасного default true).

**NEXT:** фактический список хранимых в Bitrix обработчиков `OnBeforeUserLogin`, `OnBeforeUserAuthorize`, `OnUserLoginExternal`, `OnAfterUserLogin` и артефактов текущей блокировки. Для БД использовать только SELECT по известным таблицам/metadata и обезличенный вывод; не вызывать CUser/LoginInternal. После подтверждения реальных источников реализовать read-only native site admission port и регрессионные тесты. Отсутствие обработчиков в одном каталоге не является разрешением входа.

`V5_AUTH_PRODUCTION=OFF`; `V4_CHANGED=NO`; `WEBSITE_EMAIL_SMS_ALREADY_ACCEPTED=YES`; `MOBILE_PUBLIC_HOST=https://evrasia.rest`.
