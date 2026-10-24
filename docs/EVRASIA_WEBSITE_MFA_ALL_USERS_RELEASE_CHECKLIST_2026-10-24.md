# Evrasia website MFA / all users — release checklist

**Состояние на 24.10.2026:** пилот успешно подтверждён пользователем; запуск для всех **одобрен как задача**, но **не развёрнут**. Главный контекст: [WEBSITE MFA all-users handoff](EVRASIA_WEBSITE_MFA_ALL_USERS_HANDOFF_2026-10-24.md).

**Новейшая запись 24.10.2026:** production SHA и helper presence уже проверены, PRIVATE all-user release package уже создан и PHP-linted. Не тратить время на повторный первичный build; следующий gate — внешний IP/proxy, численные SMS caps, безопасность кандидатов, rollback и cohort acceptance. [Конкретный checkpoint](EVRASIA_WEBSITE_MFA_ALL_USERS_HANDOFF_2026-10-24.md).

## A. До написания нового кода — live proof (READ_ONLY)

- [x] Пилотный логин с действующим Trusted Device без TOTP, когда TOTP включён — user browser accepted после исправления `PASSWORD_ORIGINAL`.
- [x] Пилотный прямой PIN по действительному трасту без повторного SMS — пользователь: «да все ок, можем делать для всех».
- [x] Точный установленный SHA логина: `b63094e5d45ad74f28f9594b605944526adbfad9fd0dd507ccabf11f843ed174`.
- [x] Точный установленный SHA PIN: `dee875d300403f11ad13fdfafc760fabd52daf39e03f31ee89f847d4dd427510`.
- [x] Оба шаблона предупреждения PIN обновлены, PHP lint и текст `NEW_EXACT`.
- [x] Старый приватный V3 (четыре файла+бюджет SMS) собран, PHP lint и SHA/manifest PASS, не установлен.
- [x] Redis Lua atomic quota на временных probe keys протестирован.
- [x] Текущие production SHA enrollment, signin, account, PIN, native User/Otp, Trusted Device, assurance и V4 Auth повторно подтверждены оператором: READ_ONLY PASS_COUNT=13, FINAL_RC=0. Приватный V3 SMS budget SHA и план SHA проверены, четыре живых PHP lint PASS.
- [ ] Проверить фактический текущий namespace/proxy/REMOTE_ADDR после независимого внешнего клиента; origin-local поддельные заголовки уже были отклонены, но внешний IP не доказан.
- [x] Прямое отсутствие установленного `local/php_interface/website-mfa/GlobalSmsBudget.php` подтверждено на production: `GLOBAL_SMS_HELPER=ABSENT`.

## B. Rebase к актуальному production, не устанавливать старый V3

- [x] Приватный пакет текущего live пилота уже создан: `/root/ev-web-mfa-release-packages/all-user-mfa-prepared-20261023-235710-4b06db78` — five-file candidate PHP lint PASS, PASS_COUNT=118, WRAPPER_RC=0, **INSTALL_ALLOWED=NO**. Это не production installation.
- [x] В приватном all-user package операторский builder подтвердил `PASSWORD_ORIGINAL` и parity с работающим pilot trusted login. Новый signin candidate SHA `e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a`; **ещё нет массового browser E2E**.
- [x] Приватный PIN-кандидат сравнен с заранее верифицированным global parity SHA `0b12ed47cd74bfa03c91bdcab2c4bfd07c7ccac7b67852e9540ed9a71390c94c`, builder PASS; **нужна независимая candidate-specific security/E2E проверка**.
- [ ] Расширить pilot-only enrollment/UI/signin/PIN только на действительного текущего пользователя, а не `USER_ID>0` без проверки принадлежности сессии, телефона и challenge.
- [ ] Не снимать защиту native Bitrix для SMS/TOTP enroll/disable, logout, revocation, session epoch, replay.
- [ ] Исходники и candidate version хешировать; php TOKEN_PARSE, `php -l`, точный source-diff/proof, выверенный rollback manifest, CI + negative fixtures.
- [ ] Никакой записи в Bitrix core, V4, V5 mobile, таблицы пользователей и Trusted Device.

## C. Security and paid SMS gates (до всех пользователей)

- [ ] Подтвердить необходимые лимиты глобального SMS бюджета совместно с владельцем: пока **предложены** `100/hour`, `500/day`, `20/hour/IP`, не утверждать их как production.
- [ ] Единый атомарный Redis pre-send reserve на **обеих точках платного SMS** (login без TOTP и enrollment TOTP). Fail closed при Redis ошибке. Обойти лимит дроблением пользователей/IP нельзя.
- [ ] Проверить настоящий внешний IP после web proxy и нормализацию IPv4/IPv6; `X-Forwarded-For` без доверенного маршрута нельзя принимать от клиента.
- [ ] Проверить SMS cooldown, ограничения кода и попыток, TTL, generic errors / no phone enumeration, phone из Bitrix account, challenge с серверной привязкой к USER_ID/номеру/сессии.
- [ ] Не делать мобильным V4 пользователям новый обязательный TOTP без теста совместимости native `LoginByOtp`; проверить сценарий «включил TOTP на сайте → вошёл в СамЗаберу V4».
- [ ] Отдельная однозначная политика доверенного сайта (существующий verified trust) и сильного V5 device-trust (MFA/epoch), не путать их.

## D. Website E2E acceptance (не имитировать PHP lint)

- [ ] Trusted + enabled TOTP → пароль, **нет TOTP**, PIN прямо в ЛК, SMS=0.
- [ ] Untrusted + enabled TOTP → пароль + server-verified native TOTP, PIN прямо в ЛК, SMS=0.
- [ ] Trusted + disabled TOTP → пароль, без SMS, PIN прямо в ЛК, SMS=0.
- [ ] Untrusted + disabled TOTP → пароль + SMS challenge (без ввода телефона), после него PIN в ЛК без второго SMS.
- [ ] Добровольное подключение TOTP для обычного пользователя (SMS phone proof + QR/TOTP) и отключение; проверка отзыва старых сессий/трастов согласно политике.
- [ ] Неверный пароль/OTP/SMS, expiry/replay, revoked trust, смена пароля/телефона, CSRF, logout и роли; нет выдачи PIN/JWT до полной авторизации.
- [ ] Desktop/mobile browser, повторный вход, другие пользователи без установленного TOTP.
- [ ] Отсутствие необычного роста расходов SMS и ошибок; логи без PIN, SMS-кодов, OTP secrets, cookie/токенов.

## E. Safe production rollout

- [ ] Текущие installed SHA/metadata/file names и backup **по каждому live файлу** захвачены непосредственно перед релизом; rollback проверен. Текущие пилотные файлы не заменяются старыми до-пилотными версиями.
- [ ] Комплект с безопасным install/verify/rollback (только одобренные website targets), с lock, idempotency, fail-closed guard по exact host/source/UID, atomic rename + owner/gid/mode, sha/lint, no partial install.
- [ ] Подготовлен rollback для всего согласованного сайта и отдельно для платного SMS helper; test rollback на безопасных источниках, без удаления доверенных устройств.
- [ ] Сначала допустимый ограниченный сегмент / canary, не все пользователи мгновенно. Завершённый pilot не считать доказательством новых массовых сценариев.
- [ ] Production canary E2E и наблюдаемость SMS/ошибок/логина подтверждены, только затем полный WEBSITE opt-in и тесты на реальных пользователях.
- [ ] После действия обновить [общий handoff](EVRASIA_WEBSITE_MFA_ALL_USERS_HANDOFF_2026-10-24.md) и [Project Context](AI_PROJECT_CONTEXT.md) — конкретные SHA/CI/операторские результаты, статус `STAGED/INSTALLED/VERIFIED`.

## F. Workstream separation

- **Website:** именно этот checklist. Пользователь одобрил all-users optional 2FA; цель — быстрый переход от успешного пилота с безопасным release.
- **Mobile SamZaberu V5:** публичный домен `https://evrasia.rest/api/v5`, public legal GET работают, **V5 AUTH OFF**. Рабочая ветка в том же GitHub, отдельные native adapter/transaction gates. Не включать V5 AUTH автоматически в ходе WEBSITE rollout.
- **Legacy mobile V4:** оставить работоспособным до отдельного задокументированного cutover. Никаких произвольных переписываний.

**Следующий конкретный этап:** `REBASE_WEBSITE_V3_ONTOP_OF_LIVE_PILOT_AND_CLOSE_SMS_RELEASE_GATES`.

**Порядок итерации:** READ_ONLY exact current sources → feature generator/candidate & CI → runtime/security gates → rollback-ready controlled install → real browser verification → expand all-users. Если диагностический шаг FAIL — разбирать его вывод, не ослаблять guards вслепую. Для оператора всегда один paste-ready Bash-block с `bash -n`, понятными `===` разделами и `FINAL_STATUS`/`FINAL_RC`.
