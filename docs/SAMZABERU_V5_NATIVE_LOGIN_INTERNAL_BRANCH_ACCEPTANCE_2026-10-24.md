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

## Подтверждение постоянных регистраций login events — 24.10.2026

Оператор выполнил реальный **только читающий** запрос к Bitrix `eurasia_new.b_module_to_module`. Таблица существует, обязательные поля `FROM_MODULE_ID` и `MESSAGE_ID` присутствуют, `START TRANSACTION READ ONLY` / `COMMIT` выполнены без записи. `FINAL_STATUS=PASS`, `FINAL_RC=0`, `DATABASE_WRITE=NO`.

В `FROM_MODULE_ID='main'` для четырёх известных событий:
- `OnBeforeUserLogin`: 0 зарегистрированных.
- `OnBeforeUserAuthorize`: 0 зарегистрированных.
- `OnUserLoginExternal`: 0 зарегистрированных.
- `OnAfterUserLogin`: **1 зарегистрированный** (единственный).
- `STORED_LOGIN_HANDLERS=1`; `DYNAMIC_RUNTIME_HANDLERS=NOT_ATTESTED`.

**Важно:** позднее событие `OnAfterUserLogin` не доказывает наличие предвходового veto, но его единственный зарегистрированный callback ещё не классифицирован по бизнес-последствиям. Влияние обработчиков, назначенных динамически в runtime, иных событий авторизации или конфигурации модулей **не доказано**. Нельзя автоматически выдавать `siteLoginRulesClear=true` / `temporaryLockClear=true` / `securityRestrictionClear=true` только из отсутствия трёх записей. `V5CurrentLoginControlsPort` остаётся fail-closed.

**Ближайшая точечная проверка:** получить **только метаданные** единственной записи `OnAfterUserLogin` (`TO_MODULE_ID`, `TO_CLASS`, `TO_METHOD`, `TO_PATH`) при наличии колонок; определить тип callback и нужен ли он для V5 бизнес-паритета, не исполнять callback и не читать информацию клиентов. Затем закончить V5-specific readonly site admission/negative-veto implementation. Не повторять сканирование всех 259 файлов и не переизучать `CUser::getPolicy`.

Состояние: `WEBSITE_EMAIL_SMS=ACCEPTED`, `V4=UNCHANGED`, `V5_AUTH_PRODUCTION=OFF`.

## 24.10.2026 — единственный stored OnAfterUserLogin обработчик классифицирован

Следующая read-only `START TRANSACTION READ ONLY` проверка фактической строки `b_module_to_module` (полный PASS/RC=0, без пользовательских записей) определила **ID 718**, `FROM_MODULE_ID=main`, `MESSAGE_ID=OnAfterUserLogin`, `TO_MODULE_ID=sale`, `TO_CLASS=\\Bitrix\\Sale\\Update\\CrmEntityCreatorStepper`, `TO_METHOD=OnAfterUserLogin`, пустой `TO_PATH`. Всего 1 строка; три ранее проверенных pre-login event registration остаются 0.

Опубликованный исходник **Bitrix 25.700.0** `sale/lib/update/crmentitycreatorstepper.php` реализует этот метод как чтение `sale` Option `~ADMIN_PANEL_IS_ENABLED_FOR_<userId>` и сброс значения в `N`, если оно установлено (не проверка пароля/доступа). Источник: https://doc.budagov.ru/crmentitycreatorstepper_8php_source.html (method lines 886–892). **Установленная версия файла на evrasia пока не сверена с этой публикацией.** Это побочный эффект входа в интерфейсе модуля продаж; не следует копировать его как предвходовой V5 auth-veto или искусственно вызывать `CUser::Login` ради этого эффекта.

Разрешение V5 не вытекает из отсутствия зарегистрированных pre-login handlers: runtime `AddEventHandler`, другие события, timed lock/security restriction и ревокация `AUTH_EPOCH` остаются отдельными fail-closed проверками. Нативный V5 AUTH всё ещё OFF.

## 24.10.2026 — владельцем отменены *обязательные* общие SMS-потолки для V5

После реального website outage из-за общего 500/day принято: до измерений 30–60 суток **не делать global/day/hour/cost budgets обязательными для всех пользователей**. В feature-only `V5SmsDispatchBudget` допускается policy с семью обязательными per-account/phone/IP/device scopes и необязательными тремя глобальными. Это не означает отключение антиспама, challenge, проверки номера или отправки; **публичная V5 SMS issuance OFF**, production не изменён. Включать дополнительные global limits только после данных и согласования.

## V5 feature-only SMS policy test acceptance — 24.10.2026

- Code commit `2ea69bc2bcc1d6d66e661ade21553be9e7701c61`: `V5SmsBudgetPolicy::REQUIRED_SCOPES` are **seven mandatory local rate scopes**: `account_burst`, `account_day`, `phone_burst`, `phone_day`, `ip_burst`, `ip_day`, `device_burst`. Three optional shared scopes `global_burst`, `global_day`, `global_cost_day` may be configured **only after deliberate approval** and legitimate-volume measurement. Both legacy explicit 10-scope configurations and new 7-scope configurations are accepted. `V5SmsQueueReservation` still validates typed, unique ordered windows, now range 7–10; real receipt/outbox/native challenge requirements unchanged. **This is NOT production rollout**.
- CI workflow commit `39df78df0ec2375ff41a7a9afaa971c9ee1b6495` adds actual `V5SmsDispatchBudgetTest.php` to first-factor PHP lint and execution. [GitHub Actions #40115096279](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40115096279): **COMPLETED/SUCCESS**, `PASS: V5 SMS financial + send budget coordinator (125 assertions)`, `FIRST_FACTOR_PUBLIC_GATES=PASS`.
- Older general V5 offline safety workflow #40115016840 FAIL was at website email confirmation JS regression (`WebsiteEmailAccountConfirmWidgetClickTest.cjs`), **before SMS PHP tests**; it is an independent existing issue, not evidence that V5 SMS budget test failed. Do not claim full offline suite green. Actual Bitrix native V5 SMS issuance and real provider delivery still OFF and unverified.

**Remaining V5 AUTH prerequisites:** real site-current timed lock/security/dynamic login-veto attestation, native per-user event/epoch/strong device/TOTP consume under same physical locked transaction, real verified-phone SMS gateway/receipt, real Bitrix E2E. Preserve V4; do not enable V5 public AUTH on the basis of feature-only PHP test.
