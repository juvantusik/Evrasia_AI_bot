> **АКТУАЛЬНЫЙ PRODUCTION CHECKPOINT, 24.10.2026 — EMAIL-FIRST + SMS FALLBACK РАБОТАЮТ.** Владелец подтвердил успешный вход по email OTP и затем по SMS. Массовый отказ SMS был вызван общим Redis бюджетом 500/сутки (500/500); на production сняты только общие блокирующие reserve-вызовы в signin и totp.enrollment, индивидуальные ограничения сохранены, operator FINAL_STATUS=PASS; SMS browser acceptance подтверждён. Новые общие caps не вводить до анализа реального 30–60-дневного объёма. **Актуальная запись:** [production acceptance и rollback](EVRASIA_WEBSITE_EMAIL_FIRST_SMS_PRODUCTION_ACCEPTANCE_2026-10-24.md); [инструкция для нового чата](EVRASIA_AI_BOT_NEW_CHAT_INSTRUCTION_2026-10-24_EMAIL_SMS_ACCEPTED.txt). Все утверждения ниже о «email-first OFF», «SMS E2E pending» и запланированном общем бюджете — исторические и в части website login устарели. GitHub код live R7K/R7L/R7O/R7S ещё требует безопасной синхронизации, документация не равна source snapshot.

# Evrasia website MFA / all users — release checklist

**НОВЕЙШИЙ ФАКТ PRODUCTION, 24.10.2026: ALL-USERS WEBSITE MFA УСТАНОВЛЕН.** Операторский `--apply` 20 PASS / FINAL_RC=0, последующий независимый `--status` 6 PASS / FINAL_RC=0, `RELEASE_STATE=INSTALLED_ALL_USERS`; outer WRAPPER_RC=0. Релиз `/root/ev-web-mfa-release-packages/all-users-website-mfa-20261024-064446-b03bafa5`, локальный `restore.sh` в нём. **BROWSER_ACCEPTANCE=PENDING; rollback не запускался.** Сведения ниже о `NOT_DEPLOYED`, отсутствие SMS helper и необходимости отдельной когорты — исторические состояния до установки. Применять повторно `--apply` не требуется. Следующий этап: реальные входы, PIN, SMS ошибки и обратная связь; откат только при проблеме.

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

**Порядок релиза изменён по явному решению пользователя:** целевой охват — сразу все пользователи, без отдельного cohort. Если проверка installer fails — сайт не меняется либо выполняется guarded rollback. Исходная source-only проверка пакета от 24.10: PASS_COUNT=17, FINAL_RC=0, что **не является web E2E**. Feature-only installer: `deployment/website-mfa-all-users-deploy.sh`, конфигурация Redis защитных потолков 100/h и 500/day, per-REMOTE_ADDR потолок 100/h временно дублирует глобальный до независимой проверки внешнего IP. Exact release SHA и механика rollback обязательно проверяются перед применением.

## E. Safe production rollout

- [x] Непосредственно перед `--apply` проверены installed SHA, metadata и оригинальный пилот, созданы четыре root-only `*.rollback.php` и `*.metadata`; candidate source SHA и оригинальные source SHA postcheck PASS. **Фактический rollback на live НЕ запускался**.
- [x] Installer с exact host/root SHA, flock, fresh backup, staged PHP lint, атомарным переименованием, отдельной `--status` и offline `--rollback` установлен для четырёх website targets + SMS helper. `--apply` 20 PASS; `--status` 6 PASS.
- [x] Точный root-only rollback package и `restore.sh` подготовлены, source/script/manifest SHA и права проверены installer и status. **Сам restore live пока не тестировался**, удаление trusted devices не предусмотрено.
- [x] **Обновлённое решение владельца 24.10.2026:** отдельный canary-сегмент отменён, разрешено контролируемое **direct all-users** включение с уже принятым пилотом как baseline. При этом source SHA, PHP lint, глобальная SMS-защита, полный root-only backup, независимый offline rollback и post-deploy browser E2E не отменены; INSTALL до этих guards запрещён.
- [ ] **ПОСЛЕ фактического массового source rollout:** подтвердить E2E на реальном браузере и агрегированно наблюдать ошибки и SMS-бюджет. Отдельная cohort/canary отменена владельцем, но проверки безопасности продолжаются.
- [x] После фактической установки обновлён [общий handoff](EVRASIA_WEBSITE_MFA_ALL_USERS_HANDOFF_2026-10-24.md) и [Project Context](AI_PROJECT_CONTEXT.md); статус `PRODUCTION_SOURCE_INSTALLED=YES`, `BROWSER_ACCEPTANCE=PENDING`.

## F. Workstream separation

- **Website:** именно этот checklist. Пользователь одобрил all-users optional 2FA; цель — быстрый переход от успешного пилота с безопасным release.
- **Mobile SamZaberu V5:** публичный домен `https://evrasia.rest/api/v5`, public legal GET работают, **V5 AUTH OFF**. Рабочая ветка в том же GitHub, отдельные native adapter/transaction gates. Не включать V5 AUTH автоматически в ходе WEBSITE rollout.
- **Legacy mobile V4:** оставить работоспособным до отдельного задокументированного cutover. Никаких произвольных переписываний.

**Следующий конкретный этап (после установленного all-users source rollout):** `POST_RELEASE_REAL_BROWSER_E2E_AND_SMS_ERROR_OBSERVATION`. **Не запускать повторную установку и не возвращаться к старой сборке пакета.**

**Порядок итерации:** READ_ONLY exact current sources → feature generator/candidate & CI → runtime/security gates → rollback-ready controlled install → real browser verification → expand all-users. Если диагностический шаг FAIL — разбирать его вывод, не ослаблять guards вслепую. Для оператора всегда один paste-ready Bash-block с `bash -n`, понятными `===` разделами и `FINAL_STATUS`/`FINAL_RC`.
