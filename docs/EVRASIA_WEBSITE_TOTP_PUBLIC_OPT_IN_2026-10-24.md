# Евразия: открытие добровольного TOTP 2FA для всех пользователей сайта

**Решение пользователя от 24.10.2026:** после успешно завершённого пилота разрешено предложить существующую штатную Bitrix TOTP 2FA всем пользователям личного кабинета сайта. Цель — пользователи, успешно вошедшие с защищённой сессией TOTP, самостоятельно просматривают реальный RestIS PIN списания бонусов без повторного VK/SMS.

**STATUS: BUSINESS_DECISION_APPROVED, PRODUCTION_PUBLIC_ROLLOUT_NOT_YET_DEPLOYED.** Этот документ фиксирует новое решение, не удостоверяет факт изменений production.

## Исходное подтверждённое состояние

- На production Bitrix сайт уже имеет глобально включённую OTP в **необязательном** режиме с сентября; пилотная веб-2FA с SMS-before-QR, нативной Bitrix TOTP-проверкой на входе, отключением и отзывом старых сессий работала на `USER_ID=880339`.
- 17.10.2026 оператор проверил настоящий вход `LoginByOtp()` с `Context::getUserId()` пользователя и `Context::isOtpUsed() === true`; `local/php_interface/pincode.php` показал реальный бонусный PIN непосредственно в личном кабинете, **без SMS**. Cookie-restored/старые сессии без `isOtpUsed` не могли получить PIN напрямую.
- Веб-компонент на production: `local/components/eurasia/totp.enrollment/class.php`. Пилотный код: `PILOT_USER_ID=880339`, методы `requirePilotUser()` и `requirePilotUserForDisable()`; последняя известная SHA 17.10.2026: `27de8a0d74b1613672740b2899afcc7a333b2f8e40557f5095fd98ad9ee6608e` — **исторический SHA, проверять текущий перед любым изменением**.
- Веб PIN: `local/php_interface/pincode.php`; ранее подтверждённая текущая сеансовая проверка: `$context->getUserId() === $userId && $context->isOtpUsed()`. PIN берётся из `CRestis::pincode`, но не должен разглашаться при одном только `b_sec_user.ACTIVE=Y`.
- Живые source-файлы website (`totp.enrollment`, account template, signin, pincode) хранятся на Bitrix production host, не в package `site-integration/samzaberu-v5/src`; этот GitHub feature-документ не может удостоверить их актуальные хэши/контент.

## Требуемое изменение сайта

1. Убрать **только pilot USER_ID allowlist** у доступности UI, enrollment и disable; оставить проверку сессии, текущего владельца аккаунта, Bitrix security module и полностью существующую штатную верификацию TOTP. Не менять core `Otp.php` или `TotpAlgorithm.php`.
2. Сохранить SMS-подтверждение подтверждённого телефона ПЕРЕД выдачей QR/secret, CSRF, одноразовую сессию подтверждения, время жизни/попытки и безопасный отзыв pre-MFA web/mobile sessions. До открытия всем убедиться в rate limits **per-account, per-IP и глобальном бюджете платных SMS**: прежние злоупотребления SMS не должны повториться.
3. Сохранить Bitrix MFA **OPTIONAL**. Вход без TOTP остаётся доступен без обязательного enrollment; установленный TOTP при отсутствии подлинного сильного доверия действительно запрашивается Bitrix. Не давать SMS shortcut для обхода уже установленного TOTP.
4. Прямой PIN только при сохранённой активной TOTP и доказательстве текущего Bitrix web session `getUserId()===userId && isOtpUsed()`; восстановленная cookie-сессия или факт enrollment не годятся. При отсутствии доказательства сохранять существующий VK/SMS режим, не допуская silent downgrade активной TOTP.
5. Не менять мобильные контроллеры V4, JWT, trust, SMS API и сайт по домену. Но до общего открытия проверить, как **активированная Bitrix OTP влияет на `V4\Auth::signin` / `CUser->Login`** для конкретного аккаунта: изменение штатного глобального MFA для новых аккаунтов не должно незаметно ломать V4 клиентов. СамЗаберу V5 AUTH остаётся OFF / HTTP503.
6. Проверить UI desktop/mobile, подключение, неверные SMS/TOTP, отключение, отзыв прежних сеансов, PIN без SMS после свежего OTP, невозможность прямого PIN на прежней сессии, отсутствие лишних платных SMS. Приёмка на подготовленных реальных тестовых аккаунтах до публикации UI всем; не требовать новую VM и не переписывать проверенный пилот.

## Production deployment guards

До WRITE: `hostname=evrasia`, root, exact realpath, SHA всех затрагиваемых файлов, обзор текущих source guards и фактического режима OTP, **настоящий проверенный backup каждого изменяемого файла**, syntax lint, минимальная whitelist-only замена, post-SHA check, rollback. Ни бизнес-данные, ни текущие OTP secrets, ни V4, ни API v5 legal не изменять. Чужие секреты и коды никогда не печатать.

**Следующий шаг:** получить с production одним READ_ONLY скриптом текущие hashes, безопасно обезличенные фрагменты pilot-gate в web component/account template/PIN, структуру SMS anti-abuse, и зависимость mobile V4 `CUser->Login`; затем создать точно source-pinned, idempotent one-block deployment с backup/rollback и verify. Пилот TOTP/PIN на `880339` повторно не тестировать ради исследования.

## 24.10.2026 — фактическая READ_ONLY карта production (операторский вывод)

Скрипт source-only на host `evrasia` завершился **FINAL_STATUS=PASS, FINAL_RC=0**. Загрузки Bitrix и соединения с БД нет; CODE/DB/V4/V5 write нет; SMS/TOTP не вызывались. Настоящий `Otp.php` SHA `285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c` совпал. PHPT tokenizer доступен через CLI `php` с `php.ini` (не через `php -n`), `auto_prepend_file` отключён.

| Файл production | SHA256, подтверждённый read-only | Где pilot gate |
| --- | --- | --- |
| `local/components/eurasia/totp.enrollment/class.php` | `27de8a0d74b1613672740b2899afcc7a333b2f8e40557f5095fd98ad9ee6608e` | `private const PILOT_USER_ID=880339`; `requirePilotUser()`, `requirePilotUserForDisable()`, SMS session state comparisons `self::PILOT_USER_ID` |
| `local/templates/eurasia/components/eurasia/account/main/template.php` | `022991446ef70634fc6175e2e4b96cc9d318ac2dc51ff2112ecb73ff1e73a823` | around line 224 `if ((int)$arResult[..][..] === 880339)` |
| `local/php_interface/pincode.php` | `c7fa868712ab10bef5e85384158bb1c8b21548379b278de5fb808a0e3505afff` | around line 83 `if ($userId === 880339)` protects current direct-PIN branch; then `Otp::getByUser()`/`isActivated()` and Bitrix session `context->getUserId()===userId && isOtpUsed()`; alternative non-TOTP branch contains `$_SESSION` assurance |
| `local/components/eurasia/signin/class.php` | `6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9` | pilot-only trust/assurance logic; `CUser->Login` with `Otp::isOtpRequired()` + `otpAction()` also present |
| `local/php_interface/Controllers/V4/Auth.php` | `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` | no pilot ID; legacy mobile sign-in calls `CUser->Login` and returns error when no boolean success; needs **non-invasive compatibility proof** before enrollment visible to all |

**Observed from sanitized source, not yet proven:** enrollment `requestSmsAction()` has Redis per-user+hashed-phone rate limit and calls `CSms::send()`; global per-IP budget/anti-spam and limit numeric values were masked in the current output and still require a precise *nonsecret* static check. `pincode.php` has a non-TOTP `$_SESSION` assurance fallback; widening the outer pilot check without reviewing that fallback could grant unintended direct-PIN privileges. Website login has pilot-only trust behavior, but generic Bitrix OTP action appears present. Mobile V4 may be affected by enabling native OTP for an account because it calls `CUser->Login`; **do not state the impact as tested until actual source control flow is inspected**.

**Rollout gate:** DO NOT perform indiscriminate global 880339 replacement. First do one narrow read-only, sha-pinned, literal-masked source gate analysis of (1) four Bitrix SMS enrollment / session identity invariants, (2) PIN non-TOTP assurance branch, (3) V4 login and native MFA handling, (4) native website signin second step. Only then produce minimum production patch and backup+rollback. Rollout remains `APPROVED_NOT_DEPLOYED`; all existing Bitrix OTP/pilot users are preserved, no V4/V5 API or production code touched.

## 24.10.2026 — production safety gate после READ_ONLY #2

Операторский вывод второго SHA-pinned tokenizer-аудита `FINAL_STATUS=PASS FINAL_RC=0 MODE=READ_ONLY`, PHP/BITRIX_BOOTSTRAP=NO, DB/API/SMS write=NO. Исходники пяти website/mobile файлов не изменились относительно предшествующего source map.

**Подтверждено непосредственно в production PHP по токенам без запуска:**

- `totp.enrollment/class.php`: `self::PILOT_USER_ID` — **5 вхождений**. Удалять только allowlist недостаточно: две проверки сохранённого `$_SESSION[self::SESSION_KEY]` сравнивают идентификатор пользователя с пилотом; после общего rollout должны сравнивать с текущим авторизованным пользователем. Метод `requirePilotUserForDisable` также pilot-bound. Не переписывать нативные `Otp::getByUser`/save/delete.
- `account/main/template.php`: одна проверка `((int)$arResult[..][..] === 880339)` у UI, потребуется проверка положительной авторизованной личности пользователя вместо константы.
- `pincode.php`: `if ($userId === 880339)` охватывает **две** ветки прямого PIN: активный native TOTP с `context->getUserId() === $userId && context->isOtpUsed()` и отдельный non-TOTP `AuthAssuranceService::hasPersistentCurrentAssurance` + `TrustedDeviceWebService::hasValidCurrentTrust`. При rollout native-TOTP ветку разрешать всем проверенным web user, **non-TOTP legacy trust branch не расширять автоматически**.
- `signin/class.php`: native Bitrix MFA уже обслуживается `Otp::isOtpRequired()` и `CUser::LoginByOtp()`. Пилотная без-TOTP SMS/trust цепочка в signin отдельная, её **НЕ переносить на всех** в website TOTP-only rollout; не делать повторную OTP реализацию.
- Enrollment SMS: TTL **600 s**, MAX_ATTEMPTS **3**, Redis rate `Rate::custom(1,60)` по user+phone; код генерируется `CUser::GeneratePhoneCode(userId)` и сверяет серверный профиль телефона. **Per-IP и aggregate global paid-SMS budget не подтверждены**; перед массовым включением нужен лимит стоимости/объёма SMS, чтобы один запрос на множество аккаунтов не обходил per-user правило.
- **V4 mobile incompatibility release blocker:** `Controllers/V4/Auth.php` содержит native `CUser->Login`; при результате `!== true` сразу `ApiResponse::error`; иначе выдаётся V4 JWT. Штатный сайт TOTP-деферед вход обрабатывает **отдельным** `CUser->LoginByOtp`, который в V4 controller отсутствует в проверенном фрагменте. Для нового TOTP-пользователя V4 повторный мобильный login **может быть отклонён**. Это strong control-flow evidence, но не реальный E2E client test. **Нельзя считать безопасную совместимость с мобильным V4 доказанной**.

**Действие:** WEBSITE_TOTP_PUBLIC_ROLLOUT=NOT_DEPLOYED. Следующая инженерная ветка — адаптация веб-компонента с точным SMS user-binding и защита бюджета, при сохранении прежнего пилота и без V4 изменений; отдельное решение о том, как предупреждать/обслуживать пользователей действующего V4 с включённой native TOTP, **до** общего публичного переключения. Не запускать точечную замену всех `880339` или произвольно включать OTP для пользователей; глобальная OTP уже optional, массового принудительного MFA не требуется. API V5 AUTH OFF, V4 unchanged.
