# СамЗаберу V5 — email-first при отсутствии TOTP (решение владельца, 25.10.2026)

## Исправленное правило

**При входе через `https://evrasia.rest/api/v5` после правильного пароля и серверной проверки допуска:**

1. Текущий **сильный доверенный токен устройства**, прошедший проверку server-side, позволяет завершить вход без нового кода (в том числе при включённом TOTP). Нельзя считать просто `device_id` или старую регистрацию V4 сильным доверием.
2. Без такого доверия, при включённом **нативном TOTP Bitrix**, используется только TOTP аутентификатора. Ошибка TOTP не запускает SMS или email и не открывает обход.
3. Без сильного доверия и **без TOTP** запрашивается **6-значный одноразовый код на ранее подтверждённый email профиля** (EMAIL-first). Приложение не выбирает номер телефона и не поставляет адрес email для доставки.
4. **SMS — только явно запрошенная резервная процедура**, если email недоступен/письмо не пришло и сайт разрешает восстановление; никакой автоматической платной отправки из-за отсутствия TOTP или SMTP outage. SMS требует отдельного password-bound и confirmed-phone/epoch/challenge/anti-abuse допуска. Для неподтверждённой почты нужен отдельный безопасный enrollment/recovery, а не обход второго фактора.

Это правило отменяет ранее записанные `TOTP → SMS` в документах и feature-тестах от 23–24 октября. Действующий **website** email OTP + ручной SMS fallback уже проверены реальным пользователем 24.10.2026. Веб-алгоритм использует сессионный контекст Bitrix и **не является готовым мобильным EMAIL factor provider**.

## Изменено в feature-only V5

- `src/AuthDecision.php` — `NextFactor::EMAIL='email_challenge'`, отсутствие нативного TOTP приводит к `EMAIL`, не `SMS`. `NextFactor::SMS` сохранён лишь для отдельно проверяемой процедуры.
- `src/V5LoginWorkflow.php` — `ChallengeKind::EMAIL='email'`, штатный `signIn` запрашивает только EMAIL challenge. Доверие/TOTP имеют прежний приоритет. Получение pending challenge не является входом и не создаёт JWT.
- `src/V5SeparatedFactorLifecycle.php` — **преднамеренно отказывает** на EMAIL до появления настоящего мобильного email issuer. Нельзя незаметно переслать EMAIL в уже существующий платный SMS provider.
- `src/V5MySqlConsumedChallengeProof.php` — существующая SMS/TOTP ledger-проверка **не принимает EMAIL как TOTP**; для email необходим отдельный серверно подтверждённый и одноразовый proof source до выпуска токена.
- Старый `V5SamzaberuSecondFactorPolicy` и тесты согласованы с trust-first/email-first, `REQUIRE_SMS` сохранён как отдельное recovery решением, но не создаётся normal signin.
- Targeted GitHub workflow проверяет матрицу, V5LoginWorkflow, HTTP JSON, policy, отсутствие SMS auto-fallback и отсутствие выдачи JWT в неподтверждённой ветке.

**Ключевое ограничение:** изменение policy + `challenge_type=email` в тестовом HTTP контракте **НЕ означает**, что приложение уже может реально отправлять и проверять email-код. Нужен независимый мобильный email challenge: проверка владения актуальной почтой, fingerprint версии email ownership, HMAC/хэш серверного кода, атомарные попытки и однократное consumption на текущих b_user/AUTH_EPOCH, device binding, TTL ≤ 300 sec, resend cooldown и подтверждение mail delivery. Не подменять email confirmation базовым полем `b_user.EMAIL`, недействительным/сторонним почтовым адресом, браузерной сессией или SMS-галочкой. Все native факты должны перепроверяться до выдачи V5 JWT.

## Фактический тест

- Код коммит [`e62825afe8109530bbe4188bccd7625cffd68ed6`](https://github.com/juvantusik/Evrasia_AI_bot/commit/e62825afe8109530bbe4188bccd7625cffd68ed6).
- CI registration коммит [`de7ae4af27363b68531805b0d080900b1639c6c1`](https://github.com/juvantusik/Evrasia_AI_bot/commit/de7ae4af27363b68531805b0d080900b1639c6c1).
- [GitHub Actions #40173118384](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40173118384): **completed SUCCESS**, `PHP_LINT=PASS`, `FIRST_FACTOR_PUBLIC_GATES=PASS`; **32** матричных случая, **99** проверок V5 login, **76** проверок HTTP JSON, **29** проверок separated factor routing и **18** проверок вторичной policy. SMS budget (125) и MySQL SQL outbox (137) продолжили проходить.
- Общее `V5 offline safety checks` может оставаться FAIL на старом website email confirmation JS click regression. Не представлять общий CI как green. Изолированная native SMS MariaDB проверка не доказывает работу email.

`V5_AUTH_PRODUCTION=OFF`; `PRODUCTION_SITE_CHANGED=NO`; `API_V4_CHANGED=NO`; `REAL_EMAIL_SENT_BY_V5=NO`; `MOBILE_EMAIL_OTP_FULLY_INTEGRATED=NO`.

## Следующая реализация

**`NEXT=V5_PASSWORD_BOUND_EMAIL_OTP_ISSUER_AND_CONSUMPTION`**: интерфейс для получения только *проверенного* email из Bitrix, его version/ownership proof и server-only mail transport. При создании challenge — повторный password proof и source-version в том же native transaction; при consumption — email delivery/epoch/device/current status под блокировкой и одноразовый CAS. Reuse website mail template/delivery business logic through an independently verified adapter, not direct PHP website-login session or copied production source. Включение V5 только после Bitrix-native реального E2E и проверки сильных сессий.
