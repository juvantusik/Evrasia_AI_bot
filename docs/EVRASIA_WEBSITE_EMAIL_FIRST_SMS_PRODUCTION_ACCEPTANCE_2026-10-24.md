# Evrasia website — email-first login и SMS fallback: production acceptance 24.10.2026

**Статус: подтверждено владельцем сайта 24.10.2026: email OTP login работает; SMS fallback после устранения блокировки также работает.** Основной публичный домен — https://evrasia.rest. Это новая актуальная запись относительно более ранних документов, где email-first помечен OFF / browser E2E pending. Старые записи оставить как историю, но не применять их будущие инструкции установки без повторной проверки актуального состояния.

## 1. Что реально проверено

| Область | Подтверждение |
| --- | --- |
| Email-first вход через почтовый OTP | После guarded deployment R7K пользователь сообщил: «код из письма подошел и я вошел». |
| Интерфейс email входа | Установлено R7L JS-only оформление и шрифты; operator `FINAL_STATUS=PASS`, визуально принято. |
| Ручное переключение на SMS | До исправления `BX.ajax.runComponentAction('eurasia:signin', 'emailUseSms', {mode:'class',...})` выдавал ошибку; диагностическое UI изменение R7O вывело `SMS_SEND_ERROR`. |
| AJAX endpoint | 31 запрос `emailUseSms` видны в access log; HTTP 200, но Bitrix AJAX возвращает `errors` и отклоняет promise. Регистрация действия `emailUseSms` уже существовала, менять её не надо. |
| Причина сбоя | В PHP журнале `[Signin MFA pilot] initial SMS send failed ... SMS temporarily unavailable`; `GlobalSmsBudget::reserve()` возвращал false до `CSms::send()`. |
| Redis и Lua | Redis connect/ping PASS, read-only Lua EVAL PASS. |
| Лимиты в момент инцидента | `GLOBAL_PER_HOUR=100`, `GLOBAL_PER_DAY=500`, `IP_PER_HOUR=100`; часовой счётчик 0/100, дневной **500/500**; TTL дневного счётчика 138186 секунд. |
| Исправление | Убраны **только вызовы глобального бюджетного блокировщика** в `signin/class.php` и `totp.enrollment/class.php`. Лимиты по пользователю, проверки сессии, SMS challenge и ограничения повторных отправок оставлены. |
| Production install | `EV_SMS_REMOVE_GLOBAL_CAPS`: `FINAL_STATUS=PASS`, `FINAL_RC=0`, `INSTALL_COMPLETE=YES`, `PATCHES_PREPARED=2`, `LIVE_PHP_SYNTAX=PASS`, `PRODUCTION_PATCH=PASS`, `ROLLBACK_STATUS=NOT_REQUIRED`. |
| Browser acceptance | Владелец после патча сообщил: **«даааа все работает!»** — реальная проверка SMS-входа положительная. |
| Другие подсистемы | Скрипт сообщает `EMAIL_LOGIN_LOGIC_CHANGED=NO`, `PIN_LOGIC_CHANGED=NO`, `API_V4_CHANGED=NO`, `API_V5_CHANGED=NO`. Это границы изменений именно патча, не новая независимая проверка всех API. |

## 2. Политика владельца по SMS

**Не ограничивать авторизацию всех гостей произвольными общими потолками 100/час, 500/сутки и 100/IP/час.** В массовом сценарии лимит 500/сутки исчерпан, что блокировало вход реальных пользователей. Владелец прямо подтвердил: «позже посмотрим сколько смс в среднем в день и уже определим лимиты».

На текущий момент глобальные ограничения для двух проверенных мест отправки **не применяются**. `GlobalSmsBudget.php` не удалён; Redis-ключи/счётчики не сбрасывались. *Это не означает отключения всех защит от SMS-спама*. Сохраняются индивидуальные rate-limit проверки (`enforcePilotSmsRateLimit()`), собственный `EmailFirstSmsRatePolicy`, `EmailFirstSmsResendGuard`, Bitrix CSRF, доказательство сессии, server-side SMS OTP validation. Нельзя обходить проверку кода, привязку к пользователю и срок действия.

Прежде чем предлагать новые ограничения, собирать обезличенную статистику **успешных фактических отправок** и отказов за 30–60 дней, дневной средний и p95/пик, различать вход и enrollment, оценивать стоимость и нагрузку. Никаких новых глобальных блокирующих лимитов без решения владельца. Предпочесть предупреждения и мониторинг расхода; учитывать, что многие отказавшие отправки не являются доставленными SMS.

## 3. Фактические production-файлы и контроль изменений

Сервер hostname: `evrasia`; document root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`. Публичные инструкции и ссылки указывать как `https://evrasia.rest`.

- `local/components/eurasia/signin/class.php` — production R7K email-first, SMS fallback и финальный R7S removal of GlobalSmsBudget::reserve().
- `local/components/eurasia/totp.enrollment/class.php` — R7S removal of global SMS budget reserve; сохраняет локальные rate limits.
- `local/templates/eurasia/components/eurasia/signin/main/script.js` — работающие email-first формы и R7L style; R7O diagnostic callback `EV_SMS_ERROR_DIAGNOSTIC_R7O` оставлен на текущем UI после диагностики, не путать с причиной ошибки SMS.
- `local/php_interface/website-mfa/GlobalSmsBudget.php` — helper физически остаётся, но из двух вышеназванных путей его reserve больше не вызывается.
- Email ownership proof / OTP, trusted device, PIN и website MFA helper файлы — рабочие существующие пути; не переустанавливать «на всякий случай».
- Сообщение `SMS_SEND_ERROR` ранее формировалось в `signin/class.php` (наблюдались строки 1109, 1803, 3382), ошибка бюджета происходила около строки 1991; после релиза нумерация строк может измениться.
- Рабочая приватная сборка R7H/R7K: `/root/ev-email-first-integration.Q6e5qTqC/release-v15-r7h-clean.uoOgjuB5` (локальный checkpoint, **не считать объектом GitHub**).

**Главный rollback последнего исправления:**
`/root/ev-email-first-integration.Q6e5qTqC/sms-global-caps-remove.0y6WFlnN/rollback.sh`.
Он восстанавливает два PHP-файла; **не запускать без причины** — возвращает глобальный запрет входа. R7O UI-only rollback: `/root/ev-email-first-integration.Q6e5qTqC/sms-error-r7o.cNajm409/rollback.sh`. R7L UI styling backup: `/root/ev-email-first-integration.Q6e5qTqC/email-ui-r7l.OPdFcuKG/revert.sh`. Все эти пути относятся к приватному серверу; откатывать нужно точечно и только с повторной проверкой SHA. В прошлой диагностике R7N регистрирующее действие `emailUseSms` уже присутствовало: `ACTION_REGISTRATION_PATCH=NOT_APPLICABLE`, `PATCH_APPLIED=NO`.

## 4. GitHub: пределы текущей фиксации

Рабочая ветка: `feature/samzaberu-api-v5-auth-contract` в `juvantusik/Evrasia_AI_bot`; репозиторий **public**. Этот документ фиксирует **подтверждённое production-состояние и решение**. **Не утверждать, что вся production R7K/R7L/R7O/R7S кодовая база уже закоммичена в репозиторий**: фактические live PHP/JS размещены на сервере, их полные текущие байты и SHA-256 не представлены в переписке. Приватные root-релизы и HMAC/SMTP/Redis/PHP конфиги в GitHub не выкладывать.

Перед синхронизацией кода: read-only снять актуальные SHA-256 именно с живых файлов и root release manifest, сравнить с репозиторием, создать отдельную feature branch/PR; проверить каждый экспортируемый исходник на пароли, ключи, токены, номера телефонов, внутренние адреса и другие секреты; провести `php -l`, JS syntax и review diff. Не `git add -A` из production web-root. Не применять старые pinned SHA как текущие: установка R7S уже изменила login и TOTP. Не возвращать общий бюджет при будущих деплоях на основании старого репозитория.

## 5. Минимальный приёмочный набор на следующих изменениях

1. Проверить email + 6-значный код, реальную авторизацию и кнопку выбора SMS, без браузерной консоли.
2. Проверить SMS fallback через текущий профиль без ввода телефона, доставку кода и успешный вход.
3. Проверить доверенное устройство (без нового TOTP/SMS), TOTP для недоверенного при включённом факторе, PIN без дополнительного SMS после успешного MFA, а также лимиты повторной отправки.
4. Проверить rate-limits отрицательными тестами без искусственных пользователей или массовой SMS-рассылки.
5. Не менять V4 API и не включать mobile V5 ради website-login. Домен `evrasia.rest` остаётся основным.
6. В дальнейшем убрать временное техническое сообщение R7O из интерфейса после принятия диагностического фикса, оставив полезную пользователю обработку ошибок. Отдельного rollback двух PHP из-за UI-ошибки не делать.

**Последнее достоверное состояние:** пользователь проверил реальный вход по SMS и подтвердил успех после `EV_SMS_REMOVE_GLOBAL_CAPS`; production не изменялся GitHub documentation-only действиями.
