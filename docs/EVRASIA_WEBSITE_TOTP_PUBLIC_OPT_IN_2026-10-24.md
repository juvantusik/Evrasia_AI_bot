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
