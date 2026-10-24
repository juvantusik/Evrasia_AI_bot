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

## 24.10.2026 — field provenance source + UI anchors: production PASS

Оператор выполнил `website-mfa-email-field-provenance-readonly.php` из exact GitHub commit `9f0124c89ee98028223db1a01afe66dfcb9e2183`, blob `105b6cbdcd6a64f4bd9d6347bc576dc266cd7958`. `--self-test` 4/4 PASS, live `--inspect` 15/15 PASS, wrapper `WRAPPER_FINAL_STATUS=PASS WRAPPER_RC=0`. `DATABASE_CONNECTION=YES DATABASE_WRITE=NO PRODUCTION_CODE_WRITE=NO`. Existing WEBSITE MFA unmodified.

**Доказанная структура:** `UF_CHECK_EMAIL_DATE` `datetime`, `MULTIPLE=NO`, `MANDATORY=NO`; `b_uts_user` `FIELD_STORAGE_ROWS=1,460,279`, `FIELD_NONEMPTY_ROWS=287,227`. Эти даты НЕ являются надёжным green proof без анализа writers и текущего EMAIL. При source inventory 217 approved files найдено **ровно 7 упоминаний**, не truncated:
- `local/components/eurasia/account/component.php:32` SHA `bb32a461d3fb77cc099c90df24d24a28091e2e4c471764d1a348b4a1f45f163a`;
- `local/components/eurasia/signup/class.php:193` SHA `1389951029525c046a00206397c2bc45ec14a0b5acaccbe75686b63fb2b83b84`;
- `local/components/eurasia/signup/component.php:122` SHA `b448973a336150a6db2703a0c43fbf3686343320255480dc05fcc20d0c14dd50`;
- `local/php_interface/Controllers/V4/Profile.php:210` SHA `fa82a243315bb14189b91421e0abc8a7841f4c8fb373124bccb6c2358639b603`;
- `local/php_interface/Controllers/V4/Auth.php:358` SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`;
- `local/php_interface/Controllers/UserController.php:601,632` SHA `dcb25e73c75e4ae602faed609040895030fe115262a107d79d78c8c23f53d8a5`.

**CI последней минимальной проверки:** [GitHub Actions #40074186918](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40074186918) `completed/success`. Immutable source commit `79617e7f7fcf1dba4a34b894798005911d0c4daf`, Git blob SHA `796e574160da47a9f582a4aaa56415812b50c9ff`. PHP lint, selftest PHP literal/comment/HTML attr redaction, wrong-host refusal — PASS. **Live production execution NOT YET RUN**, no source/UI changes installed.

**Осторожное наблюдение:** предыдущий signup audit показывал строку 193 как `[STRING] => date(...)`, а новый field scanner однозначно связал 193 с `UF_CHECK_EMAIL_DATE`: поле может заполняться уже в регистрации, в которой `email_confirmation` option = N. Это **серьёзный сигнал, что дата не является proof владения**. Для итогового вывода создан **один финальный targeted** `deployment/website-mfa-email-writer-logic-readonly.php`: только exact lines ± 11/12 всех 7 упоминаний, PHP literal/comments masked, а также bounded HTML-tag shapes для account email. Не повторять DB root/socket и полный search.

**Фактические шаблоны ЛК:** `account/main/template.php` SHA `42e4ae2bae706f5c63b37d1029621d2a6a9c924fef2a34560be0a369f5e23b24` (installed release manifest confirms), `account/cached/template.php` SHA `6c9c92bc9ea2b4653a2882909137fa6f0007874232e0bee5414ed54a0ba35ecd`. Exact UI lines: main ~113–130, 626–652, 708–753; cached ~48–65, 264–290, 342–343. Никаких UI изменений ещё нет. `VerifiedEmailStatus` и `EmailVerificationBadge` подготовлены, green только при надёжном proof current email.

**Оператор просит `printf '\033[3J\033[2J\033[H'` сразу после `set -Eeuo pipefail` и `umask 077` во всех новых SSH wrappers.** Если новый source audit подтверждает, что дата ставится до подтверждения почты, использовать отдельный email ownership proof; 287k legacy дат **не мигрировать автоматически**. Затем безопасно подготовить patch для индикатора и email-first + SMS fallback с rollback, без synthetic accounts.

### Следующее проверяемое действие — field provenance, минимальный email-бейдж

**GitHub CI VERIFIED:** [#40073794915](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40073794915) `completed/success` для field provenance audit, `VerifiedEmailStatus`, `EmailVerificationBadge` и offline suites; pinned source commit `9f0124c89ee98028223db1a01afe66dfcb9e2183` Git blob `105b6cbdcd6a64f4bd9d6347bc576dc266cd7958`. Production inspection **ещё не выполнена**. Один следующий wrapper обязан очищать экран первой командой и не изменять сайт.

Для source-only проверки создан `deployment/website-mfa-email-field-provenance-readonly.php`, который **НЕ заменяет старый уже выполненный full DB audit**. `--inspect` использует установленный all-users source SHA, точное root-only release и штатную `.settings.php` + PDO `START TRANSACTION READ ONLY`; спрашивает `b_user_field` о типе и кратности `UF_CHECK_EMAIL_DATE`, aggregate наличия значения в существующей (предварительно проверенной через schema) таблице, выводит только ограниченный перечень `file:line` ссылок на поле в scoped account/signup/php_interface файлах, SHA исходников и email-line anchors обоих account templates. **Не читает индивидуальные значения почты/токенов и не выводит source literals, сохраняет SECRET_PRINTED=NO.** Наличие значения поля не является подтверждением текущего адреса, пока не выяснен источник writer и привязка к email.

Добавлены `deployment/website-mfa/EmailVerificationBadge.php` и `tests/WebsiteEmailVerificationBadgeTest.php`: server-side isolated маленькая SVG галочка зелёная + крестик красный, `role=img`/`aria-label`, справа от email после точного anchor; status приходит из `VerifiedEmailStatus::evaluate()`, не из `ACTIVE`, `CONFIRM_CODE`, строки email или JS. **Feature-only, не установлен в ЛК**; до выяснения доказательства подтверждения почты ни old account ни new signup не переходят на почтовый фактор.

**Предпочтение пользователя:** каждый СЛЕДУЮЩИЙ SSH copy-paste wrapper начинает с `set -Eeuo pipefail; umask 077; printf '\033[3J\033[2J\033[H'`, сохраняя сессию открытой, и показывает только текущее структурированное выполнение.

## 24.10.2026 ~07:18 — operator production Bitrix app-DB audit PASS, email verification source uncovered

Оператор выполнил SHA-pinned `website-mfa-email-proof-live-readonly.php` с `--self-test` 3 PASS и `--inspect` 21 PASS, `DATABASE_CONNECTION=YES`, `DATABASE_WRITE=NO`, `FINAL_RC=0`, outer wrapper PASS; никаких site/Redis/SMS/mail/V4/V5 writes. Обычная DB связь **через конфиг `bitrix/.settings.php`** работает; старый root-socket FAIL устранён без изменения прав или credentials. В вывод не попадали адреса/email/секреты.

**Фактическое состояние `eurasia_new`:** `TOTAL_USERS=1530650`, `WITH_EMAIL=1530644`, `ACTIVE_NO_CODE=1513536`. **`REGISTRATION_EMAIL_CONFIRMATION_OPTION=N`**, следовательно email при массовой регистрации не требовалось подтверждать! 1 активный шаблон `NEW_USER_CONFIRM` — наличие шаблона не доказывает отправку/подтверждение. В native b_user доступны ACTIVE, CONFIRM_CODE, EMAIL, ID. **Найден user custom field `UF_CHECK_EMAIL_DATE`**; его тип/семантика/кто записывает/связь с CURRENT EMAIL пока неизвестны. НЕЛЬЗЯ считать это поле подтверждением без проверки writer и provenance. `ACTIVE=Y` и пустой `CONFIRM_CODE` недостаточны.

**Локализован UI:** `local/templates/eurasia/components/eurasia/account/main/template.php` email в областях lines 113–130, 626–652, 708–753, соответствующий `main/script.js`; также `account/cached/template.php` имеет email в lines 48–65, 264–343. Не менять оба шаблона наугад; разобраться где отображается текущий email и как меняется поле. Новый UI контракт: зелёная галочка только доказанное владение текущим email, иначе красный крестик; тот же server status разрешает/запрещает email-first login. Код `VerifiedEmailStatus.php`/unit tests feature-only, не установлен.

**Команда оператора:** «не забывай про очищение предыдущего вывода». Для каждого следующего SSH wrapper добавлять в САМОМ НАЧАЛЕ `printf '\033[3J\033[2J\033[H'` после `set -Eeuo pipefail; umask 077`: clears visible screen + scrollback, не закрывает SSH. Исторический вывод не переписывать. Следующий точечный этап — исследовать `UF_CHECK_EMAIL_DATE` тип/значения/writers и email UI anchor, без повторения уже завершённого account/core/DB-аудита и без synthetic accounts.

## 24.10.2026 ~07:10 — live DB check blocked by root socket; verified email badge approved

Оператор выполнил `EV_EMAIL_PROOF_CHECK`: production hostname / source pins `SIGNUP`, `SIGNIN` — **PASS_COUNT=3**, но `root` MariaDB socket connection с `--no-defaults --user=root` **FAIL**, `DATABASE_CONNECTION=NO`, `FINAL_RC=1`. **Это ошибка выбранного способа доступа, не доказательство сбоя БД или приложения.** Производственные данные, сайт, Redis/SMS не менялись. Не повторять root socket!

**Новая явная UI-задача:** справа от адреса email в личном кабинете показывать зелёную галочку «Email подтверждён», если сервер доказал владение *текущим* адресом, и красный крестик «Email не подтверждён», если такого доказательства нет. **Тот же серверный verified flag определяет доступность email-first login.** `ACTIVE=Y` и пустой `CONFIRM_CODE` не являются достаточным доказательством; коды/секреты/почтовые адреса не логировать.

**Работа, выполненная в GitHub feature-only:** `deployment/website-mfa-email-proof-live-readonly.php` читает существующие credentials из штатного Bitrix `bitrix/.settings.php` только в памяти, строго сверяет `database=eurasia_new`, открывает PDO транзакцию READ ONLY, выводит только schema field names, агрегированные count и количество активных `NEW_USER_CONFIRM` templates, плюс пути/номера строк email в account UI без персональных значений. Имеет `--self-test`, `--inspect` только root@evrasia, native live SHA guards и проверку наличия установленного all-user release. **НЕ bootstrap Bitrix, не посылает email/SMS, не меняет БД.** Этот probe прошёл [offline CI #40073419956](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40073419956) **SUCCESS** (PHP lint, self-test, отказ неверному hostname); исходный immutable Git commit `499464ee9d2bf806b7ab81598f7daad4ddd9c91a`, Git blob `38de0cd0ab9f6eac96fd9d0930af96c56643c2f6`. **На production probe ещё не запускался**: сначала выполнить read-only через штатное DB-подключение Bitrix, не использовать неработающий root socket.

`deployment/website-mfa/VerifiedEmailStatus.php` — общая fail-closed policy для UI badge и email-first eligibility. Зелёный статус только от отдельного DB-backed proof `source=email_ownership_otp`, USER_ID/current email HMAC, времени подтверждения после изменения email, `revoked=false`. Никакого автоматического доверия старым адресам. `tests/WebsiteVerifiedEmailStatusTest.php` покрывает green/red и ошибки чужого USER_ID, изменённой почты, отсутствующего секрета, отозванного proof. **Действующий UI ещё не изменён**, source-only реализация не является production deploy.

**Следующее действие:** выполнить pinned read-only probe через credentials приложения и получить расположение email в account UI. Затем подготовить привязанный к реальному коду badge и явную процедуру подтверждения почты с обратимым web release. Existing all-user WEBSITE MFA не откатывать.

## 24.10.2026 ~07:06 — подтверждение регистрации и почтовый контракт из живого кода

Новый операторский SOURCE_ONLY audit `EV_EMAIL_SOURCE_CHECK` на `root@evrasia` завершился `PASS_COUNT=3 FINAL_STATUS=PASS FINAL_RC=0`, без DB/Redis/mail/SMS/site/V4/V5 writes. Подтверждены точные live SHA: signup `1389951029525c046a00206397c2bc45ec14a0b5acaccbe75686b63fb2b83b84`, native CUser `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99`, Bitrix event.php `a8ed4ea418cc3de855ce7e4151638253a09a7ba1e5ca751948492b311b20f763`, signin JS `bc69ba9c1571f02c2b36ca54988f6b51f41c0ff5dbbf3f00ffc32d5a60759050`.

**Конкретные исходные данные:** signup lines 76-79 вычисляют `$emailConfirmation = Option::get(...) === ...` и принудительно `true` при `useEmailValidation()`; lines 167-179 используют `$active = $emailConfirmation ? ...`, `$confirmCode = $emailConfirmation ? Random::getStringByAlphabet(...) : ...`, записывают `EMAIL`, `CONFIRM_CODE`. Lines 308-313: `if ($emailConfirmation) { $event = new CEvent; $event->Send('NEW_USER_CONFIRM', SITE_ID, $arEventFields); }`. Следовательно **у сайта есть опциональное требование подтверждать регистрацию**, но это **не доказательство владения текущим EMAIL каждого active пользователя** (старые регистрации, административные изменения, последующая смена почты). В signup нет маркеров `EMAIL_CONFIRMED`, `UF_EMAIL_VERIFIED`, `UF_EMAIL_CONFIRMED`; actual mail delivery не проверялась. Native CUser имеет `Register`, mail CEvent имеет Send/SendImmediate. Frontend уже содержит `showSmsForm/showOtpForm/submitFirstFactor`.

**NEXT_STEP:** один bounded DB schema/aggregates + mail event template existence read-only proof, чтобы решить, существует ли точный `verified_email` источник, без доступа к raw addresses/секретам/кодам. Если такого durable доказательства нет, **не выводить verified из одного `ACTIVE=Y` и пустого `CONFIRM_CODE`**; использовать подтверждение почты через новый явно привязанный proof marker, сформированный после существующего SMS/TOTP/Trusted Device подтверждения. Исторический массовый WEBSITE MFA остаётся на месте; email-first пока `FEATURE_ONLY`.

## 24.10.2026 — операторский read-only source map выполнен, новая подтверждённая точка

`root@evrasia` выполнил immutable audit `2f9606bb033e59547c0f91abcbd65a5082a7257d` (blob `01cdbe25d80246f9c3ad56df2a45ecf0c231e6c6`); `--self-test PASS_COUNT=7`, `--inspect PASS_COUNT=25 FINAL_RC=0`, wrapper `FINAL_RC=0`. Production Bitrix signin/PIN/native core, release manifest совпадают по pinned SHA, никакие production/DB/Redis/mail/SMS/V4/V5 записи не выполнялись.

**Точные frontend source SHA из живого сайта:** signin JS `local/templates/eurasia/components/eurasia/signin/main/script.js` = `bc69ba9c1571f02c2b36ca54988f6b51f41c0ff5dbbf3f00ffc32d5a60759050`; signin template `local/templates/eurasia/components/eurasia/signin/main/template.php` = `15bddc88bf0f873513cc009731b97dbf7be78686a2bcb3d521a3a8f2620d561a`; signup class `local/components/eurasia/signup/class.php` = `1389951029525c046a00206397c2bc45ec14a0b5acaccbe75686b63fb2b83b84`. Legacy Bitrix mail event PHP file `bitrix/modules/main/classes/general/event.php` = `a8ed4ea418cc3de855ce7e4151638253a09a7ba1e5ca751948492b311b20f763`, newer `bitrix/modules/main/lib/mail/event.php` missing (не предполагать наличие).

**Контракт текущего входа из токен-маскированного source:** `signin::processAction` уже проверяет пользователя, TOTP, trusted, для `!TOTP && !trust` вызывает `startPilotSmsChallenge`; метод проверяет `CUser::LoginInternal` с реальным парольным первым фактором и сохраняет AuthenticationContext в серверной сессии; `smsAction` валидирует код и делает `Authorize($context,true)`, `registerSuccessfulSmsLogin`, assurance. `otpAction` использует native `LoginByOtp`. Frontend имеет отдельные TOTP/SMS формы и `showSmsForm`, `showOtpForm`. **Никакой повторной разработки методов Bitrix не нужно**, email-first вставляется в `NoTrust + NoTOTP` между password verifier и SMS issuance и использует тот же проверенный AuthenticationContext, SMS по явному fallback.

**Критический незакрытый факт:** signup источники содержат `CONFIRM_CODE` и `EMAIL_CONFIRM` по одному маркеру, но `EMAIL_CONFIRMED` маркеров 0; это **не доказывает текущий подтверждённый email**. `SMTP_DELIVERY_VERIFIED=NO`. Следующий focused source-only gate — конкретная signup email-confirm logic + email event/transport, не повторная Bitrix/TOTP/Redis/SMS проверка. Без доказанного подтверждения email вход по письму не включать, старый SMS должен остаться рабочим.

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
