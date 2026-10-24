# Evrasia AI Bot — WEBSITE MFA: переход от успешного пилота к запуску для всех

## 24.10.2026 — НАИВЫСШИЙ ПРИОРИТЕТ: WEBSITE MFA ALL-USERS УСТАНОВЛЕН В PRODUCTION

**Подтверждено фактическим выводом оператора `root@evrasia`, а не только GitHub/CI.** В 06:44 UTC согласно имени пакета от 24.10.2026 оператор выполнил immutable all-users installer `f9f194eea550bcecc1dcebd0914ec6533c3dd4d9`, blob `c1460244efe598bb23e2addeca8fb430655c7008` (GitHub offline CI [#40071228006](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40071228006) SUCCESS). Полный wrapper source verification и Bash lint PASS, `--self-test` PASS, `--preflight` 17/17 PASS, `--apply` 20/20 PASS с `INSTALL_VERIFIED=YES`, отдельная проверка `--status` 6/6 PASS, `RELEASE_STATE=INSTALLED_ALL_USERS`; итог оболочки `WRAPPER_FINAL_STATUS=PASS WRAPPER_RC=0`.

**Путь к фактически установленному release и локальному rollback:**

`/root/ev-web-mfa-release-packages/all-users-website-mfa-20261024-064446-b03bafa5`

`bash /root/ev-web-mfa-release-packages/all-users-website-mfa-20261024-064446-b03bafa5/restore.sh --rollback /root/ev-web-mfa-release-packages/all-users-website-mfa-20261024-064446-b03bafa5`

**Установлено (не путать с browser E2E):** exact PHP source SHA проверены после атомарной замены для `totp.enrollment/class.php`, `signin/class.php`, `account/main/template.php`, `local/php_interface/pincode.php` и нового `local/php_interface/website-mfa/GlobalSmsBudget.php`; в `--status` все четыре `*_installed pinned SHA` PASS и общий SMS helper SHA PASS. Из зафиксированных source SHA: signin `e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a`, PIN `0b12ed47cd74bfa03c91bdcab2c4bfd07c7ccac7b67852e9540ed9a71390c94c`; account warning и budget dynamic SHA хранятся в root-private `manifest.txt`, **не угадывать их**. Native Bitrix core, API V4, API V5 AUTH и существующие Trusted Devices этим инсталлятором не изменялись; DB/Redis не записывались в ходе команды; SMS команда не отправляла. Начальные лимиты helper `100/час`, `500/сутки`, per-REMOTE_ADDR `100/час` (временно не строже общего глобального лимита).

**Важно: `BROWSER_ACCEPTANCE=PENDING`, `BROWSER_E2E=REQUIRED`.** Пользователь ещё не подтвердил четыре реальные ветки входа, enrollment и прямой PIN после массового развёртывания. `ROLLBACK_NOT_TESTED_ON_LIVE=YES`: rollback файл и резервные копии присутствуют, но сам restore на живом сайте не выполнялся. **Не откатывать без фактически обнаруженной проблемы; не переустанавливать!** Следующий этап — user-facing smoke check / агрегированное мониторирование ошибок и платных SMS, без повторной установки и без ручных JSON/IP тестов с телефона. Подтверждение оператора относится к exact source install, а не к гарантированной реальной работоспособности всех пользователей.

Оставшийся текст ниже — **история подготовки до массовой установки**. Его утверждения `NOT_INSTALLED` / `GLOBAL_SMS_HELPER=ABSENT` устарели после этого operator PASS.

**Дата фиксации:** 24 октября 2026. **Проект продолжается**, это не новое проектирование.  
**Решение пользователя (последнее):** «да все ок, можем делать для всех». Это подтверждение успешного **пилотного браузерного сценария** и поручение подготовить общедоступный веб-MFA. **Не означает, что массовый rollout уже включён.**

## 24.10.2026 — новый чат: source-only PASS и приватный массовый пакет уже подготовлен

**Операторский факт, не предположение:** в новом чате получены ДВА отчёта `root@evrasia`. (1) Source-only production: `FINAL_STATUS=PASS FINAL_RC=0 PASS_COUNT=13`; живые enrollment `27de8a0d74b1613672740b2899afcc7a333b2f8e40557f5095fd98ad9ee6608e`, signin `b63094e5d45ad74f28f9594b605944526adbfad9fd0dd507ccabf11f843ed174`, account `022991446ef70634fc6175e2e4b96cc9d318ac2dc51ff2112ecb73ff1e73a823`, PIN `dee875d300403f11ad13fdfafc760fabd52daf39e03f31ee89f847d4dd427510`, native User/Otp, Trusted/Assurance services и V4 Auth полностью совпали с закреплёнными pins; все четыре живых website PHP lints PASS. GlobalSmsBudget.php на сайте **ABSENT**. Существующий приватный V3 SMS budget файл SHA `ef0ed60aafceea3d82dd65f239c01dfe9f708bddfdb68fb5a64f8078b3d38b2f`, план SHA `725c61b432c4dea5121ab9336bd0e46ea5f53495e28fcd6216a38ec49caefe09`.

(2) Уже проведена отдельная безопасная **PRIVATE_ALL_USER_MFA_PREPARATION** (не повторять): `PASS_COUNT=118`, `FINAL_STATUS=PASS FINAL_RC=0`, outer `WRAPPER_FINAL_STATUS=PASS WRAPPER_RC=0`; PHP lint всех пяти кандидатов PASS; source/rollback SHA, исправленный `PASSWORD_ORIGINAL` и parity с принятым pilot trusted helper и PIN положительно подтверждены операторским инструментом. **Актуальный root-private комплект**: `/root/ev-web-mfa-release-packages/all-user-mfa-prepared-20261023-235710-4b06db78`. Исправленный all-users signin SHA256 `e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a`; all-users PIN SHA256 `0b12ed47cd74bfa03c91bdcab2c4bfd07c7ccac7b67852e9540ed9a71390c94c`. SMS budget кандидат включён **только приватно**. Пакет **не содержит разрешённого общего installer**, `GLOBAL_RELEASE_INSTALL_ALLOWED=NO`, `SITE_CODE_WRITE=NO`, `PUBLIC_MFA_ROLLOUT=NOT_PERFORMED`.

**Действия по GitHub:** в новом чате был создан дублирующий неподтверждённый feature-only rebaser `website-mfa-rebased-private-prepare.php`, затем **удалён** из feature branch как ненужный: приоритет у фактически успешно собранного пакета `all-user-mfa-prepared`, а не у конкурирующей непроверенной реализации. Её коммиты в истории GitHub не означают развертывание.

**NEXT:** не пересоздавать приватный комплект и не повторять базовые SHA или Redis Lua/Bitrix исследования. Закрыть **независимый внешний client-IP / trusted-proxy proof**, согласовать числовую политику SMS и предупредить о возможном повторном login V4 после добровольной TOTP, выполнить новые candidate-specific security/rollback checks и ограниченный реальный website E2E, только затем guarded rollout. Действующие trusted устройства сохранить; V4 без записей, V5 AUTH OFF.

### 24.10.2026 — read-only security-marker review of current all-user package

Оператор прислал независимый read-only отчет `root@evrasia` для `/root/ev-web-mfa-release-packages/all-user-mfa-prepared-20261023-235710-4b06db78`: `PASS_COUNT=17`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`. Все четыре live PHP SHA, четыре rollback SHA и пять private candidate SHA подтверждены; глобальный SMS helper **по-прежнему ABSENT на сайте**. Source-only scanner: 15/15 строк `*_SECURITY_*=PASS`, включая `PASSWORD_ORIGINAL`, двойную серверную проверку trust, отсутствие пилотных user-ID guards, нативный Bitrix `LoginByOtp`, обе `GlobalSmsBudget::reserve`, account session bindings, PIN CSRF/USER/TrustedDevice и Redis fail closed. **Это только наличие статических маркеров, НЕ доказательство полного control-flow/cross-user E2E.** Отчет `PRIVATE_JSON_MANIFEST_COUNT=1` и запрет на установку сохраняются.

Обнаружен конкретный UI gap: `V4_TOTP_WARNING_VISIBLE_IN_ACCOUNT_CANDIDATE=NOT_FOUND_IN_THIS_TEMPLATE` (эвристический поиск, требуется точный просмотр текста для уверенного утверждения). Внешний PHP client IP за прокси `NOT_YET_VERIFIED`; численные SMS caps `STILL_PENDING`; ограниченная cohort policy `NOT_YET_ATTESTED`; реальные website E2E `NOT_YET_ACCEPTED`. Нет production writes. **Дальше нужны внешняя client-IP аттестация и целевой opt-in warning/closed cohort, не повторное исследование старых SHA или Redis Lua.**

### 24.10.2026 — external client IP proof, feature-only diagnostic

После успешного package marker review создан **новый ограниченный диагностический инструмент, НЕ production release installer**: `site-integration/samzaberu-v5/deployment/website-mfa-external-client-ip-browser-probe.sh`, feature code `043274e11a1985783038aab4e826a0bf91125d41`, blob `f8b34329e9bd700719af8cd08944b87353c8d3d1`. Он проверяет exact host/root и действующий пилотный login/PIN + приватный all-user signin SHA; сначала находит валидный canonical HTTPS route с обычной TLS-проверкой. Затем на 180 секунд создаёт **один новый случайный файл PHP в публичном docroot**, не читая cookies/login/Bitrix/БД и не вызывая SMS/Redis. PHP возвращает только флаг PUBLIC/LOCAL_OR_RESERVED и **HMAC-отпечаток REMOTE_ADDR** (не исходный IP); временный ключ не печатается. Оператор открывает URL в двух независимых сетях (ПК и телефон с отключенным Wi-Fi), записывает JSON, завершает скрипт ENTER — выполняется inode-bound cleanup файла. Именно различие стабильных IP fingerprint и публичная область могут подтвердить отсутствие простого общего proxy fan-in; это не доказательство «любого клиента» без реальных браузерных ответов. Само размещение временного probe = **кратковременная production-site file write**, не изменение действующего PHP, не публичный rollout. Не считать ничего проверенным до actual operator result и `TEMPORARY_SITE_PHP_REMOVED=YES`.

GitHub offline CI для инструмента добавлено в `.github/workflows/v5-offline-safety.yml`. Первая CI попытка `#40066383999` завершилась FAIL, потому что self-test ошибочно требовал недоступный на GitHub runner `ip`; исправлено разделением self-test tool requirements и строгих production-only network requirements в `043274e`. Итоговая GitHub Actions [#40066436581](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40066436581) завершилась **SUCCESS** для immutable feature code `043274e` (self-test PHP template, Bash lint, rejection on nonproduction host). Это только offline proof, не запуск веб-пробы и не подтверждение внешнего клиентского IP. Original website pilot and validated private release package unchanged. Global SMS quotas remain provisional; mobile V4 warning absent from candidate UI scan (heuristic only); cohort and browser E2E pending.

**Installer source-pin / CI:** final guarded deployment commit [`f9f194ee`](https://github.com/juvantusik/Evrasia_AI_bot/commit/f9f194eea550bcecc1dcebd0914ec6533c3dd4d9); Git blob `c1460244efe598bb23e2addeca8fb430655c7008`; [offline Actions #40071228006](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40071228006) **SUCCESS** (Bash syntax, offline self-test, fail-closed non-production `--preflight/--apply` rejection). **Runtime --preflight and actual --apply on `root@evrasia` have NOT yet run.** Critical rollback improvement: local restored script uses root-only manifest candidate SHA and its own snapshots; no dependency on original private candidate package at rollback time.

### 24.10.2026 — решение владельца о прямом all-users rollout, без отдельной когорты

**Новое явное решение пользователя (превалирует над прежней канареечной последовательностью):** включить проверенную WEBSITE MFA-логику **сразу для всех пользователей**, без дополнительного ограниченного сегмента. Пользователь готов оперативно получать отзывы и запускать откат при обнаруженной проблеме. Это разрешение **на изменение порядка выката**, но не на отказ от host/root/source-SHA guards, текущих backup, защит платных SMS, пароля/Trusted Device/TOTP, автоматического и независимого offline rollback. До фактического operator `--apply` и postcheck нельзя утверждать `INSTALLED` или `ACCEPTED_IN_BROWSER`.

**Текущий feature-only all-users installer:** `site-integration/samzaberu-v5/deployment/website-mfa-all-users-deploy.sh` (Bash `--self-test`, `--preflight`, `--apply`, `--status <release>`, `--rollback <release>`). Использует **уже существующий** 5-file private package `/root/ev-web-mfa-release-packages/all-user-mfa-prepared-20261023-235710-4b06db78` и exact current-live pilot SHA. До site-write проверяет root/hostname, native Bitrix/V4 source, original+candidate SHA, source invariants, PHP lint; root-only snapshot+manifest+локальную копию restore-инструмента; ставит helper первым, signin последним; автооткат при неполной установке и отдельный офлайн-откат с exact file SHAs. **Никаких миграций и массовой обработки Trusted Device.**

**Промежуточный компромисс SMS/IP, до аттестации внешнего `REMOTE_ADDR`:** в кандидатной копии helper на момент установки `IP_PER_HOUR` заменяется с предварительного 20 на **100** (равно `GLOBAL_PER_HOUR=100`), чтобы единый reverse-proxy IP не ограничил весь сайт до 20 оплачиваемых сообщений/час. Глобальные protective ceilings в этой редакции **100 SMS/час и 500 SMS/сутки**, атомарные Redis Lua EVAL и текущие per-account/phone/TTL/resend guards сохраняются; неизвестный IP/Redis ошибка не создают обхода без бюджета. Порог может ограничить легитимный массовый SMS-вход; запуск должен явно показывать значения оператору и предусматривать быстрый откат/дальнейшую корректировку. Нельзя выдавать численные пороги за статистически подтверждённый объем — они *консервативная стартовая конфигурация*, не измеренная нагрузка.

**V4 compatibility warning:** installer точечно вставляет предупреждение в current TOTP settings account candidate после server-side authorized USER_ID gate. Это информирует об известном возможном отказе повторного входа в мобильный V4 после добровольного подключения native TOTP. V4 контроллер и существующие токены не меняются.

**Статус на момент записи:** `DIRECT_ALL_USERS_REQUESTED=YES`; `NEW_RELEASE_INSTALLER=GITHUB_FEATURE_ONLY`; `PRODUCTION_INSTALLED=NO`; `WEBSITE_BROWSER_E2E_ALL_USERS=NOT_RUN`; `MOBILE_V5_AUTH=OFF`. Для определения последнего CI проверять живой workflow, не полагаться на более старую SUCCESS. **Оставшиеся runtime gates исполняются перед atomic install**, а browser E2E и наблюдение SMS/ошибок после установки должны быть проверены отдельно.

## 0. С чего начинать в новом чате

1. Сверить *живую* ветку GitHub `juvantusik/Evrasia_AI_bot`, `feature/samzaberu-api-v5-auth-contract`, последние коммиты/CI; не полагаться на старый HEAD.
2. Прочитать **этот файл**, [рабочий чек-лист](EVRASIA_WEBSITE_MFA_ALL_USERS_RELEASE_CHECKLIST_2026-10-24.md) и [инструкцию для нового чата](EVRASIA_AI_BOT_NEW_CHAT_INSTRUCTION_2026-10-24_WEBSITE_MFA_ALL_USERS.txt). Более ранний [большой checkpoint 23.10](EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-23_V5_AUTH.md) — для независимой ветки мобильного V5 AUTH, а не вместо свежего веб-статуса.
3. **Начать с подготовки безопасного массового web-release**, а не с повторного обзора Redis/Bitrix/репозиториев или установки старого V3. Уже сделанные пилотные изменения сохранять.

## 1. Production: фактически подтверждено

| Направление | Последнее подтверждение |
|---|---|
| Публичный сайт и будущий мобильный API | **https://evrasia.rest**. Не публиковать технический origin `evrasia.spb.ru` как главный домен |
| Bitrix-хост / root | `evrasia`; `/home/site_evrasia/web/evrasia.spb.ru/public_html` (название каталога историческое) |
| Пилот | `USER_ID=880339`; штатный TOTP Bitrix по желанию; **работающий** вход с сервера подтверждённым Trusted Device без повторного TOTP |
| Пилотный вход | `local/components/eurasia/signin/class.php`, текущая после operator PASS SHA256 **`b63094e5d45ad74f28f9594b605944526adbfad9fd0dd507ccabf11f843ed174`** |
| Пилотный PIN | `local/php_interface/pincode.php`, текущая после operator PASS SHA256 **`dee875d300403f11ad13fdfafc760fabd52daf39e03f31ee89f847d4dd427510`** |
| PIN-поведение | После финального патча пользователь подтвердил «всё ок»: на доверенном устройстве PIN доступен непосредственно в ЛК без дополнительного SMS; считать **USER_BROWSER_ACCEPTED**. Не выдавать это за независимое серверное E2E всех возможных веток |
| Неизвестное устройство | Ранее инкогнито с активным TOTP запрашивало штатный код и входило; **последнее регрессионное инкогнито-тестирование после патча PIN отдельно не документировано** |
| Статический текст PIN | Обе версии `account.pincode/main/template.php` и `main2026/template.php` — `NEW_EXACT`, операторский PASS. Уведомление: «Код для списания бонусов доступен прямо в личном кабинете после входа. Дополнительное СМС или сообщение в боте не требуется.» |
| Trusted Devices | Существующие ~44 210 записей сохранены (количество — ранее сообщённый масштаб, не новый SELECT). **Не удалять, не очищать и не «переоформлять» массово** |
| WEBSITE all-users rollout | **НЕ ВКЛЮЧЁН**, подтверждён только пилот. Пакет V3 изолированный |
| Мобильный API V4 | Работает, не менялся и не отключён |
| Мобильный V5 | Public `GET /api/v5/health`, `/legal/consents`, `/legal/registration-documents` на `evrasia.rest` внешне приняты. **V5 AUTH OFF**, protected API не объявлять готовым |

**Реальная хронология:** Пилотная TOTP-проверка была принята ранее. При первом входе по трасту возникала ошибка из-за неверных параметров `CUser::LoginInternal` (`APPLICATION_ID` вместо нативного `PASSWORD_ORIGINAL='Y'`); её исправили в пилотном файле с точной проверкой SHA. Затем вход с трастом успешно проходил без TOTP, но прямой PIN ошибочно заменялся платным SMS. Изменён **только** PIN-контроль доверенного устройства в пилотной ветке. Генератор сначала fail-closed остановился на проверке пробелов; после исправления обратной побайтной проверки production `--apply` и `--status` завершились PASS. Пользователь затем подтвердил работу.

## 2. Закреплённая бизнес-логика WEBSITE

**Всегда сначала правильный пароль; после него — проверка сервером действительного доверия браузера и привязки к тому же Bitrix USER_ID.**

| Состояние | Вход | PIN после завершённого входа |
|---|---|---|
| Trusted Device действителен, TOTP включён | Пароль + серверная проверка траста. **Не спрашивать TOTP повторно** | Показывать PIN в личном кабинете, без SMS |
| Trusted Device отсутствует, TOTP включён | Пароль + **нативный Bitrix TOTP** | Показывать PIN в личном кабинете, без повторного SMS |
| Trusted Device действителен, TOTP выключен | Пароль + действительный траст | Показывать PIN в ЛК, без SMS |
| Нет траста и нет TOTP | Пароль + **SMS challenge на телефон из Bitrix-профиля**, без поля ввода телефона | После успешного SMS-входа PIN в ЛК, **не отправлять отдельный PIN-SMS** |

Сильное подтверждение всегда привязано к текущему пользователю/сессии/браузеру, включая CSRF и отзыв доверия. При недостоверном/отозванном трасте fail closed: для TOTP — код аутентификатора; для отсутствующего TOTP — защищённый SMS challenge. Браузер с cookie сам по себе не доказывает серверное доверие. Нативные ограничения Bitrix, ограничения попыток, SMS anti-spam, phone/user binding и атомарность сохранять.

**TOTP необязателен** для всех клиентов, QR enrollment подтверждается SMS с номера действующего профиля, затем серверным TOTP; отключение и отзыв старых сессий следуют существующей принятой логике. Не хранить plaintext secret и не выдавать PIN через незавершённую авторизацию.

## 3. GitHub: в чём уже создан код

- **Пилот trusted/TOTP login:** `site-integration/samzaberu-v5/deployment/website-mfa-pilot-trust-canary.php`; установленная пилотная версия дополнительно исправлена `website-mfa-trusted-password-contract-repair.sh`, commit [`1113ec9e`](https://github.com/juvantusik/Evrasia_AI_bot/commit/1113ec9edb6ddeb4856f130f04f4c2dfb41edbf4), CI [#40040525194](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40040525194) PASS.
- **Пилотный прямой PIN:** `website-mfa-pilot-pin-trust.php`, исправленная версия commit [`71017f9a`](https://github.com/juvantusik/Evrasia_AI_bot/commit/71017f9a2fdfa44c0e10b0210d59253789a0b6ce), Git blob `d4d6df081173ae90285dc7c4667f2f00cb26e696`, CI [#40041604720](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40041604720) PASS.
- **До-пилотный V3 private candidate (5 файлов):** `website-mfa-rollout-prepare.php`, `website-mfa-trusted-totp-private-stage.php`, `website-mfa-release-package.php`, `website-mfa/GlobalSmsBudget.php`; исходники в GitHub и независимый root-only release package на сервере. **Нет массового --install / нет live rollout.**
- **PIN notice:** `website-pin-notice-two-templates.php`, CI [#40039305981](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40039305981) PASS; оба шаблона уже изменены.
- **SMS / proxy / Redis:** `website-mfa-proxy-redis-readonly.sh`, `website-mfa-release-runtime-check.php`, `website-mfa-remote-addr-probe.sh`; origin-bound TLS probe PASS; Redis atomic Lua проверялся на отдельных короткоживущих приватных probe-ключах, не на живых лимитах.

### Неизменяемые private backups на сервере

| Пакет | Состояние |
|---|---|
| `/root/ev-web-mfa-release-packages/package-v3-20261023-225401-9ec315d6` | Все пять V3 кандидатов/четыре оригинала проверены по SHA/PHP lint. **НЕ СТАВИТЬ КАК ЕСТЬ** |
| `/root/ev-web-mfa-release-packages/pilot-trust-canary-20261023-230052-4a23f5de` | Изолированная установка пилотного логина, исходный файл и offline rollback |
| `/root/ev-web-mfa-release-packages/pilot-trust-canary-20261023-230052-4a23f5de/password-fix-backup.Vs8stGlk` | Отдельная копия логина до исправления `PASSWORD_ORIGINAL` |
| `/root/ev-web-mfa-release-packages/pilot-pin-trust-20261023-234931-e815f73a` | Исходный/patched PIN, manifest, offline rollback |
| `/root/evrasia-pin-notice-backups/pin-notice-20261023-232411-62d869ed` | Исходные оба шаблона PIN notice |
| `/root/ev-web-mfa-checkpoints/public-web-mfa-trust-v3-20261023-221046-448854e0` | Первоначальный source-pinned V3 checkpoint, источник истории и сравнения |

**Rollback локально (только при проблеме, НЕ запускать при нормальной работе):**

- Только PIN: `php /root/ev-web-mfa-release-packages/pilot-pin-trust-20261023-234931-e815f73a/pin-rollback-local.php --rollback /root/ev-web-mfa-release-packages/pilot-pin-trust-20261023-234931-e815f73a`.
- Пилотный login → исходный pre-pilot: `bash /root/ev-web-mfa-release-packages/pilot-trust-canary-20261023-230052-4a23f5de/trusted-password-contract-repair-offline.sh --rollback`. Осторожно: это возвращает исходную login-логику, а не предпилотное PIN-уведомление. Любой новый global release обязан создать **отдельный rollback** с актуальными перед ним SHA и backup.

## 4. Текущие исходные SHA / опасность старого пакета

| Файл/компонент | Baseline прежнего V3 | Последний операторский live SHA |
|---|---|---|
| `totp.enrollment/class.php` | `27de8a0d74b1613672740b2899afcc7a333b2f8e40557f5095fd98ad9ee6608e` | Другого live SHA не подтверждено; проверить перед новым release |
| `signin/class.php` | `6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9` | **`b63094e5d45ad74f28f9594b605944526adbfad9fd0dd507ccabf11f843ed174`** |
| `account/main/template.php` | `022991446ef70634fc6175e2e4b96cc9d318ac2dc51ff2112ecb73ff1e73a823` | Другого live SHA не подтверждено; проверить |
| `local/php_interface/pincode.php` | `c7fa868712ab10bef5e85384158bb1c8b21548379b278de5fb808a0e3505afff` | **`dee875d300403f11ad13fdfafc760fabd52daf39e03f31ee89f847d4dd427510`** |

Предыдущие V3 кандидаты: enrollment `763a0160f8e21d31bfe7a03cc9472bc94410835532299d57265ad55f06eb6ddc`, signin **`04d254d7667c29819557d4df269760fa09e1561d2e6185fe8ab68a3a2d7f3770`**, account `afaa8d03db683a965a83d68eaf3fe5c03fdf1afb450749f2698eb540a5ccfec8`, PIN `0b12ed47cd74bfa03c91bdcab2c4bfd07c7ccac7b67852e9540ed9a71390c94c`, Redis SMS helper `ef0ed60aafceea3d82dd65f239c01dfe9f708bddfdb68fb5a64f8078b3d38b2f`.

**Критично:** V3 собран против прежних оригиналов. **Установка V3 напрямую после пилота нарушит current-live SHA guards**. Кроме того, V3 trusted-TOTP helper был построен **до исправления `PASSWORD_ORIGINAL`**, и перенос старого V3 без этого исправления вернёт ошибку доверенного входа. Массовый веб-выпуск нужно **rebase/merge поверх подтверждённых live SHA**. Не перезаписывать работающий пилот старыми оригиналами; не делать find/replace пилотного `880339` без динамического user/session proof и source-diff review.

Source pins зависимостей: native Bitrix `user.php` `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99`; OTP `Otp.php` `285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c`; trust service `TrustedDeviceWebService.php` `28a28d7e92cd54512a45a477069735af182e5ce9b2142343fa881eb236a92b45`; assurance service `AuthAssuranceService.php` `e2d4b8f8351977f694738098b5655794b897f8cd1dcbdaeb580660db05dda1f9`. Сверить фактические SHA **перед** действиями и при расхождении остановиться до записи.

## 5. Блокеры массового WEBSITE rollout

1. **Актуальная интеграция поверх pilot live**: обновить/пересобрать все 4 сайта-кандидата плюс Redis budget helper, исправленный Bitrix `PASSWORD_ORIGINAL='Y'`, сохранить `hasValidCurrentTrust` в PIN, реальные проверки `USER_ID`, телефона, TOTP, challenge, timeout, нативного `Authorize` и сессии.
2. **Платные SMS:** предлагавшийся общий Redis бюджет `100/час`, `500/день`, `20/час/IP` — **предварительные пороги**, без подтверждённого массового продакшен-включения. Atomic Redis `EVAL/INCR/EXPIRE` с реальными Redis ACL проверен только на отдельных private probe keys (TTL ~45 с). Helper в production **не установлен**. Бюджет должен покрывать **обе платные точки**: SMS для входа без TOTP и SMS для подключения TOTP. Fail closed, плюс per-user/phone и антиспам.
3. **Реальный IP:** проверка временного PHP через origin-bound HTTPS с валидным TLS доказала, что поддельные `X-Forwarded-For` не меняют `REMOTE_ADDR` в **origin-local** запросе. Но `REMOTE_ADDR` определился как local/reserved адрес; **внешний клиентский IP не аттестован**. Не считать его доказанным для per-IP лимита до независимого внешнего теста и анализа доверенного прокси.
4. **Полная матрица браузеров:** современный trusted TOTP pilot accepted, но на массовом кандидатe ещё нужны: новый клиент+TOTP, новый без TOTP с SMS (и PIN без повторного SMS), trusted без TOTP, некорректные password/SMS/OTP, отказ/отзыв траста, CSRF, logout, replay, отсутствие sms-спама, mobile/desktop. Показ PIN только после verified authorization.
5. **Совместимость mobile V4:** исходники V4 `Controllers/V4/Auth.php` используют native `CUser->Login` и могут не обрабатывать TOTP deferred `LoginByOtp` так, как website. При массовом предложении TOTP проверить действующих пользователей мобильного приложения и документировать безопасную совместимость/вариант миграции. **Не переписывать и не отключать V4** ради website rollout.
6. **Откат, observability, cohort:** нужны новые точные live-baseline snapshots, проверенный restore без изменения пользовательских данных, контроль ошибок и платных SMS; сначала ограниченный сегмент и acceptance, потом все. Отдельного рабочего Bitrix staging в Project не подтверждено — не выдумывать его наличие.

## 6. Отдельная ветка мобильного V5 — НЕ СМЕШИВАТЬ

[Мобильное ТЗ](SAMZABERU_V5_MOBILE_DEVELOPER_TZ_LEGAL_TRUST_TOTP_SMS_2026-10-23.md) уже утверждает сценарий trusted → TOTP → SMS и публичные legal GET. В мобильном V5 действует **сильное доверие установки**, привязанное к MFA/security epochs; парольный trust старого V4 нельзя автоматически повышать до сильного V5 trust. Это **другая модель токенов**, не повод сбросить существующие web Trusted Device.

- Public `https://evrasia.rest/api/v5/health`, `/legal/consents`, `/legal/registration-documents` — внешне приняты; `POST /legal/consents/validate` origin-only accepted, не запись.
- Protected AUTH/JWT/PIN/Cart V5 **ещё выключены** на production. Ветка V5 native-factor/transaction tests feature-only; E2E настоящего Bitrix TOTP для V5 не пройдено.
- Не проводить V4→V5 cutover, не менять публичный роутер/legal из-за WEBSITE MFA. Продолжать V5 AUTH параллельно как отдельный workstream, когда потребуется.

## 7. Практический следующий рабочий шаг

**NEXT_STEP=BUILD_REBASED_PUBLIC_WEBSITE_MFA_RELEASE_FROM_CURRENT_PILOT_LIVE**

1. Открыть GitHub live HEAD + все перечисленные source/release helper файлы; зафиксировать последнее CI и реальную версию.
2. **Одна guarded READ_ONLY команда на `root@evrasia`**: проверить все актуальные installed SHA и private checkpoint manifest, host/root, наличие Redis helper по exact path, origin/client IP evidence; не читать/печатать пароли, токены, номер телефона, PIN или cookies.
3. Реализовать **feature-only candidate generator/rebaser**, сохраняющий current pilot login+PIN и обобщающий enrollment/account/login/PIN на текущего серверного пользователя, исправить все V3 trusted-password параметры; статические source-diff and fail-closed guards + CI. Не выполнять сразу production write.
4. Провести интеграционные / негативные тесты SMS quotas, proxy client IP, V4 coexistence и native Bitrix auth; добиться релизных gates из отдельного [чек-листа](EVRASIA_WEBSITE_MFA_ALL_USERS_RELEASE_CHECKLIST_2026-10-24.md).
5. Создать **отдельный current-live rollback/installer** только после успешных тестов; предлагать controlled cohort, наблюдать, затем включать доступ для всех. Оставить необязательный TOTP и исходные доверенные устройства без сбросов.
6. После каждого принятого operator result: анализ вывода → фиксируем GitHub docs/CI/status → следующий один copy-paste блок. Не выполнять десятки бессмысленных аудитов.

**Формат серверных операций:** один полный Bash heredoc, `bash -n` перед запуском; `=== 1. HOST / ENVIRONMENT ===`, `=== 2. GUARDS ===`, `=== 3. ACTION ===`, `=== 4. VERIFY ===`, `=== 5. RESULT ===`; `MODE=READ_ONLY/WRITE`, `DATABASE_WRITE=NO/YES`, `API_WRITE=NO/YES`, `SECRETS_PRINTED=NO`, `FINAL_STATUS=PASS/FAIL`, `FINAL_RC`. Сохранять терминал. Production guarded, idempotent, backup/rollback, no sensitive outputs.

## 8. Источники для контекстного восстановления

- [Web MFA весь исторический контекст](EVRASIA_WEBSITE_TOTP_PUBLIC_OPT_IN_2026-10-24.md) — source map, обсуждение антиспама, Redis/proxy, origin.
- [Финальная пилотная прямая выдача PIN](EVRASIA_WEBSITE_PIN_TRUST_PILOT_2026-10-24.md) — actual source SHA, PIN backup, предыдущий SMS fallback.
- [Уведомление PIN — оба шаблона](EVRASIA_PIN_NOTICE_DUAL_TEMPLATE_2026-10-24.md).
- [Мобильный V5 AUTH checkpoint](EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-23_V5_AUTH.md), [native V5 OTP](SAMZABERU_V5_NATIVE_TOTP_FACTOR_2026-10-23.md), [действующий публичный legal V5](SAMZABERU_V5_EVRASIA_REST_EXTERNAL_ACCEPTANCE_2026-10-23.md).
- [Главная project history](AI_PROJECT_CONTEXT.md) — старые milestones не сильнее live production.

**Финальный статус этого документа:** `PILOT_WEBSITE_MFA_USER_ACCEPTED=YES`; `WEBSITE_MFA_PUBLIC_ROLLOUT_APPROVED=YES`; `WEBSITE_MFA_PUBLIC_ROLLOUT_INSTALLED=NO`; `V5_MOBILE_AUTH_ENABLED=NO`; `V4_DECOMMISSIONED=NO`; `GLOBAL_RELEASE_REBASE_REQUIRED=YES`.
