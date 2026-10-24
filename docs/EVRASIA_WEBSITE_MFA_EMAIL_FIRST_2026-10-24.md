# Evrasia AI Bot — email-first website login challenge с SMS fallback

**Дата:** 24 октября 2026. **Статус:** одобрено пользователем («делаем»), базовый security code и unit tests подготовлены **в GitHub feature**, но **НЕ установлены** на production. WEBSITE MFA ALL-USERS уже физически установлен ранее и остаётся без изменений (оператор `--apply` и `--status` PASS, release `/root/ev-web-mfa-release-packages/all-users-website-mfa-20261024-064446-b03bafa5`). Не повторять rollout и не заменять действующую авторизацию до готовности email интеграции.

## Согласованная бизнес-логика

При каждом входе корректный пароль проверяется штатным Bitrix первым фактором.

| Уже проверено | Логика |
| --- | --- |
| Valid Trusted Device (с TOTP или без) | Вход по паролю + server-verified текущему трасту, **без повторных кодов** |
| No Trust + native Bitrix TOTP активен | Обязательный native Bitrix TOTP; email/SMS не должны обходить этот метод |
| No Trust + TOTP выключен + сервером подтверждённый email | Email OTP первым способом; код из письма. Ссылка «Не приходит код?» -> «Получить SMS» запускает существующий SMS challenge **только по явному действию гостя** |
| No Trust + TOTP выключен + адрес отсутствует/некорректен/не подтверждён | Существующий SMS challenge. Телефон берётся только из серверного Bitrix профиля |
| После полного успешного входа | PIN доступен через действующий server-verified assurance в ЛК без ещё одной платной SMS |

**Ключевая защита:** значение `EMAIL` в профиле само по себе не доказывает владение почтой. Прежде чем разрешать письмо как второй фактор, необходимо доказать действующий источник `email_confirmed` / завершённое подтверждение владения, привязанное к current USER_ID. Отсутствует подтверждение — не слать письмо без проверки, использовать SMS. Нельзя изменять email через одну лишь старую password-only сессию после включения email MFA.

**Offline CI принят:** GitHub Actions [#40072132028](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40072132028) SUCCESS для двух новых email suites: `WebsiteEmailLoginChallengeTest.php` **46/46 assertions PASS** и `WebsiteEmailSendBudgetTest.php` **21/21 PASS**; PHP lint + `website-mfa-email-first-source-audit.php --self-test` PASS и неправомерный `--inspect` на CI-host отклонён. Это **только offline**, не mail-delivery и не live web E2E. Для read-only production audit использовать pinned source commit `2f9606bb033e59547c0f91abcbd65a5082a7257d`, Git blob `01cdbe25d80246f9c3ad56df2a45ecf0c231e6c6`; источник создан ранее и не изменялся после successful CI.

## Изолированная реализованная часть (feature-only)

- `site-integration/samzaberu-v5/deployment/website-mfa/EmailLoginChallenge.php`: независимая серверная state machine. 6-значный криптографически случайный код, `password_hash` на сервере, expiry 300s, не более 3 попыток, одноразовое погашение, binding `USER_ID + current confirmed email fingerprint + PHP session ID + random challenge ID`. Raw code передаётся только в mail transport callback; в JSON ответе и session storage отсутствует. Для нового password attempt старый email challenge аннулируется. ТOTP и валидный Trust исключают email flow. `switchToSms` аннулирует email proof и даёт возможность вызвать существующий paid SMS challenge после проверки первого фактора, TOTP, телефонов и действующих budget limits. Service **не вызывает CUser::Authorize** самостоятельно.
- `site-integration/samzaberu-v5/deployment/website-mfa/EmailSendBudget.php`: отдельный Redis Lua check+increment, без обращения к IP (его внешняя атрибуция ещё не доказана). Предварительные лимиты до approval: **1 отправка/60s per user (continuous cooldown), 5/час/user, 1000/час global**, Redis exception -> fail closed. Идентификатор пользователя хеширован, email/телефон/куки в Redis-ключах отсутствуют. ЭТО НЕ SMS бюджет. Платный `GlobalSmsBudget` сайта не затрагивается.
- `site-integration/samzaberu-v5/tests/WebsiteEmailLoginChallengeTest.php`, `WebsiteEmailSendBudgetTest.php`: source-only тесты позитивных/негативных сценариев, cross-user, cross-session, email change, expiry, replay, attempt limit, SMS transition, SMTP failure, Redis error, quota namespace isolation.
- `site-integration/samzaberu-v5/deployment/website-mfa-email-first-source-audit.php`: source-only read-only mapper **уже установленного** signin/PIN SHA, frontend signin JS/template, signup confirmation/email transport markers. Режим `--inspect` только root@evrasia; без Bitrix bootstrap/DB/SMTP/Redis/web writes, выводит только PHP-token-masked фрагменты и SHA. `--self-test` offline.

## С чем ещё нужно соединить (не считать готовым только из-за CI)

1. Получить read-only source topology с **реального установленного** `local/components/eurasia/signin/class.php` SHA `e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a` и frontend `local/templates/eurasia/components/eurasia/signin/main/{script.js,template.php}` — они не хранятся как исходники в GitHub. Сверить фактическое формирование challenge, CUser::LoginInternal, сохранённый первый фактор и присутствие OTP/SMS/TrustedDevice.
2. Выяснить где и **каким образом** сайт подтверждает владение email; если механизма нет, понадобится дополнительная привязка почты после SMS/подтверждённого входа, а до неё использовать существующий SMS fallback. Нельзя трактовать `b_user.EMAIL` как автоматически подтверждённый.
3. Проверить текущий Bitrix почтовый транспорт и event/template, HTML-письмо, domain/SPF/DKIM/DMARC, никакие секреты не печатать; подготовить delivery adapter без выдачи кода через HTTP.
4. Подключить email-first только в проверенном `NoTrust + NoTOTP + passwordVerified`; создать actions `verifyEmailAction` и `useSmsInsteadAction` c CSRF, nonce/session/user/attempt binding; отдельный статус email pending в JS/UI. `useSmsInstead` переводит на уже работающий SMS challenge, не посылает SMS до явного подтверждения гостя. Не создавать `Trusted Device` и не показывать PIN без подтверждённого server-side login.
5. Private SHA-pinned candidates + PHP/JS checks + root-only backup, rollback и acceptance на реальном текущем сайте; V4/V5 AUTH, core, DB schema и historical TrustedDevice не трогать без отдельного решения.

## Рекомендуемые тексты интерфейса

- Заголовок: «Подтвердите вход». Подзаголовок: «Мы отправили код подтверждения на [маскированный email]».
- Поле: «Код из письма»; кнопка: «Продолжить».
- Ссылка: «Не приходит код?» -> варианты «Отправить письмо повторно» (cooldown) и «Получить код по SMS» (показываем маскированный телефон из аккаунта). Не предлагать ручной ввод или замену телефона в форме входа.
- Не сообщать, существует ли email/номер, до проверки пароля. Error messages generic, без номера телефона, raw email, OTP/SMS-кодов.

## Релизные ограничения

- `PRODUCTION_EMAIL_FIRST_ENABLED=NO` до отдельного проверенного web deploy.
- `WEBSITE_MFA_ALL_USERS=INSTALLED` и работающий PIN/Trust не откатывать без фактической проблемы.
- `API_V4_WRITE=NO`, `API_V5_WRITE=NO`; mobile V5 AUTH OFF.
- Не слать тестовые письма на вымышленные или синтетические аккаунты; только реальные согласованные браузерные сценарии.
- **NEXT_STEP=VERIFY_LIVE_SIGNIN_JS_AND_EMAIL_CONFIRMATION_PROVENANCE_READ_ONLY**, затем готовить patch без повторного исследования Bitrix core/Redis Lua, уже выполненного в проекте.
