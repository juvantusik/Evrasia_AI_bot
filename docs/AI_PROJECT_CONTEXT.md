> **24.10.2026 MAIL_EVENT_AND_TEMPLATE_CREATED_MANIFEST_RECONCILIATION_PENDING — LATEST PRODUCTION OPERATOR CHECKPOINT.** `EV_MAIL_BUFFERED_RESUME`: `--self-test 6 PASS`, `--prepared 41 PASS`, `--probe 43 PASS`; native Bitrix prolog/session now loads with CLI output buffering. `--resume` **successfully created native `EVRASIA_WEB_EMAIL_OWNERSHIP` mail event and single `s1` template** (`new_isolated_Bitrix_mail_event_registered` and `new_isolated_Bitrix_mail_message_created` PASS), then `TypeError` at pinned source line 407 from **undefined `$manifest` local variable** only initialized in the original `--install` branch. `DATABASE_WRITE=YES`, `--resume FINAL_RC=1`, wrapper FAIL; **NO email sent, NO code/users/auth API changes**. Existing private manifest still `PREPARED_BEFORE_WRITE`, missing template ID. **DO NOT rerun old `--resume`/--install or delete checkpoint**; native registry rows already created. New feature-only script `site-integration/samzaberu-v5/deployment/website-mfa-email-mail-checkpoint-reconcile.php` immutable commit `ffe9c489e20da51a8e1f5aceea06630ae6552e88`, Git blob `9481bdaca00a2030d43a9a5513955a588ecb6301`. Root-only owner/source guards and exact **READ ONLY** DB row validation (one type and exact one s1 template); only checkpoint **manifest** corrected atomically with independent backup, original TypeError fixed without touching native mail registry or site. New modes `--self-test`, `--preflight`, `--apply` (manifest-only), `--status`; after PASS invoke previous pinned buffered release source `--status` as independent verifier. Production reconciliation NOT YET RUN. Existing empty email proof ledger/secret and red badge intact; email confirmation public endpoint/button, email-first login OFF. [Full checkpoint](EVRASIA_WEBSITE_MFA_EMAIL_FIRST_2026-10-24.md). All SSH blocks strict + immediate `printf '\033[3J\033[2J\033[H'`.

> **24.10.2026 MAIL_SESSION_HEADERS_SENT_PREPARED_RESUME — latest operator checkpoint.** Operator recovery diagnostic `--inspect PASS_COUNT=13` confirmed `EVENT_TYPES_CURRENT=0`, `EVENT_TEMPLATES_CURRENT=0`, original root-only mail manifest `PHASE=PREPARED_BEFORE_WRITE`; live account/signin unchanged. Diagnostic `--site-probe` under UID1005 failed at `RuntimeException Session.php:143` BEFORE native Bitrix mail API loaded; no DB/mail/source writes. Bitrix public `Session::start()` source line143 is "headers already sent"; original CLI PHP printed PASS before `prolog_before.php`, explaining failure. **Do not modify production web session/cookies**. Fixed/extended feature-only `site-integration/samzaberu-v5/deployment/website-mfa-email-mail-event-release.php` immutable commit `597fcfff79ac048d23c0a349e8df15db55699bae`, Git blob `83879f0116ed7ebae7051e4337c8332c6b33bee6`, [CI #40083353000](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40083353000) SUCCESS. New `ob_start()` immediately before first diagnostic output, separate buffered Bitrix prolog, exact root PREPARED manifest + empty event/type guard, `--prepared`, read-only `--probe`, **--resume** existing release (never recreate checkpoint), independent `--status`. Next user command should SHA verify and execute self-test+prepared+probe BEFORE any native mail registry writes, then resume+status, no emails sent. Old `--install` MUST NOT rerun. **Email OTP button and email-first login OFF** until successful mail registry and AJAX backend production deployment. Existing ledger/secret, warning, badge, MFA, PIN, V4/V5 intact. [Full checkpoint](EVRASIA_WEBSITE_MFA_EMAIL_FIRST_2026-10-24.md).

> **24.10.2026 MAIL_EVENT_REGISTRY_INSTALL_FAILED_PRE_WRITE — newest operator checkpoint.** Latest `EV_EMAIL_MAIL_INSTALL` `--self-test 4 PASS`, `--preflight 41 PASS`, `--install` 43 PASS then `FAIL: guarded_email_mail_release_failed` after creating **root-only private checkpoint + manifest**, before Bitrix prolog native classes; `DATABASE_WRITE=NO`, `EMAIL_SENT=NO`, wrapper FAIL. Native event/template not yet installed, endpoint/button OFF. **Do NOT rerun old install nor remove checkpoint.** Dedicated manifest `/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H/email-ownership-mail-event-release/manifest.txt`: PREPARED_BEFORE_WRITE, event `EVRASIA_WEB_EMAIL_OWNERSHIP`, site `s1`, language `ru`. Next one copy-paste root script: immutable `website-mfa-email-mail-failed-release-diagnostic.php` commit `818fdfd28d4f8d103ac47f50b25cccea47bfa6dd` Git blob `803f57dcb00c851f64b19043614210baf044e1fe` [CI #40082321993](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40082321993) SUCCESS. Modes: root `--inspect` checks old stage + read-only type/template SELECT; `--site-probe` executed as site owner UID 1005 tests production-proven minimal Bitrix CLI prolog/class availability, printing only error class/source basename if failing. No credentials, no Bitrix mail registry write, no email. Do not guess exact prolog exception until result. Earlier installed `ev_web_email_ownership_proof` (0 rows)+HMAC secret, account badge, readable warning, website MFA and V4/V5 unchanged. See [full mail checkpoint](EVRASIA_WEBSITE_MFA_EMAIL_FIRST_2026-10-24.md).

> **24.10.2026 EMAIL_SITE_MAIL_READY_S1_15_PASS — NEXT CHECKPOINT.** Оператор выполнил `EV_EMAIL_MAIL_ONLY`: offline 11 PASS, production 15 PASS, wrapper `WRAPPER_FINAL_STATUS=PASS RC=0`. Native Bitrix `b_lang`, site `s1`, main public `evrasia.rest`; `b_event_type`, `b_event_message` существуют; dedicated `EVRASIA_WEB_EMAIL_OWNERSHIP` event=0 templates=0, PHP Redis+loopback Lua EVAL=PASS, `local/ajax` exists, `/local/ajax/ev-email-ownership.php` does not. No writes, existing ledger/secret installed (0 rows). Added isolated `website-mfa-email-mail-event-release.php` immutable commit `0ec72f18dafce1bc221af3efa1fdb72dde6eca0f`, Git blob `5ea7eb6b11979feddbb8b737b8d5581f4f5dc276`; [CI #40081937375](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40081937375) SUCCESS incl selftest/wrong-host denial. One isolated site s1 native Bitrix email event+template only, read-only preflight and post-check, root-only checkpoint, no emails sent or user DB writes; **NOT YET RUN IN PRODUCTION**. Important security fix: `EmailOwnershipMailTransport::payload` explicitly `DUPLICATE=N` so Bitrix `all_bcc` doesn't receive OTP copies, offline regression PASS. Next: root@evrasia installer `--self-test`, `--preflight`, `--install`, `--status` with SHA + strict host guard and immediate screen clear. Then stage source-pinned HTTP endpoint + widget within existing bridge; verify on existing real account. Do NOT rerun proof schema/MFA/login or change V4/V5. [Full checkpoint](EVRASIA_WEBSITE_MFA_EMAIL_FIRST_2026-10-24.md).

> **24.10.2026 WEBSITE_EMAIL_MAIL_ONLY_CHECKPOINT (latest).** Root@evrasia read-only operational inspector recovered auth contract and DB, then `FAIL: table_exists_b_site` (12 PASS; no writes). Actual Bitrix site table = `b_lang` (not `b_site`); mail tables remain `b_event_type`, `b_event_message`. New narrow `--mail-only` inspected mode skips repeating MFA source and checks site ID/mail event collision/Redis/endpoint, source commit `a75b8ef42f085ce901e917423b6dd598c0a72ca4`, Git blob `69284c47c33bc1a5ea26b1bc9fc83184f5dfea7b`, [CI #40081430976](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40081430976) SUCCESS. **Not run in production yet**, no new auth/mail/DB/site changes, existing empty email proof table and secret remain installed. NEXT only `--self-test` and `--mail-only` with immediate clear screen.

> **24.10.2026 VERIFIED_EMAIL_LEDGER_INSTALLED_33_18_PASS — latest operator production checkpoint.** Operator successfully installed new `eurasia_new.ev_web_email_ownership_proof` (empty, **0 rows**, InnoDB exact 7 columns) and `local/php_interface/website-mfa/email-ownership-secret.php` 64-hex secret site UID mode 0600; original `website-mfa-email-proof-bootstrap.php` installer immutable commit `f126f583654e0b03ef26e90d00cd4945be97e975`; `--apply PASS_COUNT=33 FINAL_RC=0`, independent `--status PASS_COUNT=18 FINAL_RC=0`, wrapper `WRAPPER_FINAL_STATUS=PASS WRAPPER_RC=0`. No existing user rows migrated or modified, no site code/auth/V4/V5/PIN/trust or SMS/mail writes. **DO NOT rerun bootstrap --apply**, table and secret already in live prod. Main site still SHA `cc0bb0e...` with red badge and inside-security readable warning; EMAIL CONFIRM BUTTON and EMAIL-FIRST login **OFF**. Feature-only functional button+modal `EmailAccountConfirmWidget.php` and OTP orchestration `EmailOwnershipActionHandler.php`, tests [CI #40080795193](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40080795193) SUCCESS; not installed. Before activating public endpoint + mail template + bridge, one final narrow source gate `website-mfa-email-operational-contract-readonly.php` commit `e1797a59bf1a392ec703f4d87183e4bc98419fa2`, Git blob `50e4414afb4dc0db740fda10a5cbf5f4596404d7` checks server's current MFA assurance method/timestamps, exact site ID, mail event collision, Redis eval, local/ajax path, all read-only/no user data. **Next: run that once; then deploy complete working POST endpoint and button with rollback and real-account E2E.** `UF_CHECK_EMAIL_DATE` is NOT verification. Every SSH block strict shell + immediate screen/scrollback clear `printf '\033[3J\033[2J\033[H'`, no credentials printed.

> **24.10.2026 EMAIL_PROOF_BOOTSTRAP_PREFLIGHT_FALSE_POSITIVE — newest verification checkpoint.** Operator combined read-only `EV_EMAIL_OWNERSHIP_NEXT`: assurance gate 17/17 PASS, DB bootstrap preflight 17 checks then `FAIL: safe_isolated_empty_table_DDL`. No production DB/site/secret/email/SMS writes; `WRAPPER_FINAL_STATUS=FAIL`. Root cause proven in pinned feature source: SQL safety filter blanket-excluded keyword `UPDATE` found in benign `updated_at TIMESTAMP ... ON UPDATE CURRENT_TIMESTAMP` of the **one empty dedicated CREATE TABLE**, not a data mutation statement. Fixed feature installer commit `f126f583654e0b03ef26e90d00cd4945be97e975`, blob `bcd29ed3876a160ebcf0fe1c93683e9d2a74daeb`, [CI #40079978488](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40079978488) SUCCESS: exact single CREATE TABLE + InnoDB, one ON UPDATE clause, no DML and source-pinned DDL comparison; offline hostile SQL fixtures PASS. **Next:** root@evrasia re-run only corrected `--self-test` + `--preflight` (READ ONLY). **No `--apply` until live PASS.** Main account warning already installed SHA `cc0bb0...`, red badge present, functional «Подтвердить почту» and email-first login still OFF. All SSH blocks: strict shell + immediate `printf '\033[3J\033[2J\033[H'`. [Detailed checkpoint](EVRASIA_WEBSITE_MFA_EMAIL_FIRST_2026-10-24.md).

> **24.10.2026 WARNING_RELOCATION_INSTALLED — NEWEST PRODUCTION CHECKPOINT.** Operator **успешно продолжил** существовавший warning release: `website-mfa-security-warning-resume.php` pinned commit `a101760155ef514a633d2baa6509399cd8141021`, `--self-test PASS_COUNT=5`, `--preflight PASS_COUNT=17`, `--apply PASS_COUNT=34 FINAL_RC=0`, independent `--status PASS_COUNT=19 FINAL_RC=0`, wrapper `WRAPPER_FINAL_STATUS=PASS WRAPPER_RC=0`. Live `account/main/template.php` SHA теперь **`cc0bb0e2754704feb29d7c7db6f9064c340b274ada21852e18ed1a99db7723bb`**, UID 1005 mode 0664 сохранились; предупреждение находится внутри «Безопасность аккаунта», font 16px; existing red email badge, MFA/TOTP/trust/PIN/V4/V5 unchanged; DB/SMS/mail writes NONE. Previous warning deployment FAIL has been superseded, no rollback currently needed. Изолированный root-only rollback `/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H/account-warning-relocation/restore.php --rollback`, use only if actual regression. **Новая фаза**: функциональная «Подтвердить почту» + email OTP + green proof; `UF_CHECK_EMAIL_DATE` НЕ ВЕРИФИКАЦИЯ; email-first login OFF. Созданы два ГОЛЬКО feature-only read-only probes: `website-mfa-email-ownership-assurance-gate.php` (CI #40077358990 SUCCESS) и `website-mfa-email-proof-bootstrap.php` (CI #40077481518 SUCCESS, migration `--apply` не исполнялась). Следующий шаг — объединённые `--inspect` + `--preflight` для assurance и пустой ledger без записи. Не пытаться переустановить сайт/MFA/email badge. Каждая SSH команда: `set -Eeuo pipefail; umask 077; printf '\033[3J\033[2J\033[H'`.

> **24.10.2026 WARNING_RELOCATE_APPLY_FAILED — НОВЫЙ PRODUCTION CHECKPOINT.** Последний операторский SSH вывод: `website-mfa-security-warning-relocate.php` --self-test 5 PASS, --preflight 11 PASS, **--apply FAIL FINAL_RC=1**, `SOURCE_FILE_WRITE=NO`, wrapper FAIL. **Предупреждение остаётся на прежнем месте**, но в `/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H/account-warning-relocation` сохранены source-pinned `main.before.php`, `main.candidate.php` и старый `restore.php`. Никакой rollback сейчас не требуется, сайт/DB/V4/V5/OTP/PIN не изменялись. Выявлены две проблемы старого installer: использование метаданных `0600 root` backup вместо live template uid/gid/mode и `exec('php -l')`, который может быть отключён на PHP сервера (точный runtime exception был скрыт, не считать подтверждённым). Feature-only подготовлен `website-mfa-security-warning-resume.php`, immutable commit `a101760155ef514a633d2baa6509399cd8141021`, Git blob `f934de248ed267bfea980ca66b036ff71ef9470e`, [CI #40077003080](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40077003080) SUCCESS. Он **продолжает существующий stage**, хранит прежний восстановитель в отдельной root-only копии, устанавливает фиксированный самостоятельный `restore.php`, проверяет точный SHA исходного/current main, делает lint без shell exec, меняет ТОЛЬКО account/main template с сохранением live владельца и прав; rollback/--status доступны отдельно. **Его --apply ещё не выполнялся**, результат production для него PENDING. После PASS потребуется UI браузер acceptance и отдельное подключение email подтверждения; email button и email-first всё ещё OFF. Все SSH wrappers: strict + clear `printf '\033[3J\033[2J\033[H'` в самом начале. [Полный checkpoint](EVRASIA_WEBSITE_MFA_EMAIL_FIRST_2026-10-24.md).

> **EMAIL UX ИНДИКАТОР / 24.10.2026 — BROWSER OBSERVED, NEXT CHANGE APPROVED.** Реальный operator `root@evrasia` применил badge-only installer: `--install PASS_COUNT=10`, independent `--status PASS_COUNT=4`, wrapper PASS. Скриншот пользователя показывает красный крестик справа от email в ЛК; массовая авторизация без изменений, email-first/почтовая верификация всё ещё OFF, DB proof ledger не создан. Пользователь дополнительно попросил поставить **функциональную** кнопку «Подтвердить почту» рядом с email и перенести микроскопический текст о временных проблемах повторного входа в СамЗаберу **внутрь существующего блока «Безопасность аккаунта»**, обычный шрифт ~15–16px, под 2FA. Фиктивная кнопка запрещена; нужен OTP endpoint + proof ledger. Существующие Bitrix account/main+cached PHP — только на production; immutable `website-mfa-account-ui-confirm-email-audit.php` безопасно извлекает точный контекст; [CI #40076113400](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40076113400) SUCCESS, live audit ещё не запускали. На этом шаге никаких production изменений не сделано. Подробности [EMAIL FIRST CHECKPOINT](EVRASIA_WEBSITE_MFA_EMAIL_FIRST_2026-10-24.md). Для каждого root@evrasia wrapper после `set -Eeuo pipefail; umask 077` сразу `printf '\033[3J\033[2J\033[H'`.

> **24.10.2026 — EMAIL STATUS: UF_CHECK_EMAIL_DATE НЕ ЯВЛЯЕТСЯ ПОДТВЕРЖДЕНИЕМ.** Операторский SHA-pinned source review `EV_EMAIL_WRITER_CONTEXT` подтвердил `PASS_COUNT=12 FINAL_RC=0`: в двух регистрациях сайта и в V4/Auth field `UF_CHECK_EMAIL_DATE` записывается через `date(...)` при создании аккаунта, `UserController::hitEmail()` заполняет дату без проверки кода. Два других пути V4/Profile и UserController, напротив, используют `EmailConfirmationTable` перед обновлением EMAIL/date, но даты у ~287227 старых профилей имеют смешанное происхождение. **Никакой green badge / email-first eligibility на основании даты, ACTIVE=Y, CONFIRM_CODE='': нельзя.** В feature-ветке реализованы `EmailOwnershipEnrollment.php` (одноразовый email OTP после недавней strong website assurance), `EmailOwnershipProofStore.php` (новая проектируемая HMAC-only ledger `ev_web_email_ownership_proof`, profile stamp и Bitrix b_user FOR UPDATE), усиленный `VerifiedEmailStatus.php`, `EmailAccountBadgeBridge.php` и `EmailVerificationBadge.php`, офлайн unit suites. Для двух точных существующих account templates создан `deployment/website-mfa-email-private-candidate-stage.sh` с root-only SHA-pinned PREPARE, сохраняющий rollback snapshots и создающий кандидаты `account/main` и `account/cached` без production site/DB writes. **PRODUCTION EMAIL_FIRST=OFF; EMAIL BADGE=OFF; миграции не проводились; all-users website MFA release остаётся без изменений.** Следующий операторский этап — private stage + candidate QA, затем полная безопасная integration/SQL migration + реальные E2E. Подробности [email-first checkpoint](EVRASIA_WEBSITE_MFA_EMAIL_FIRST_2026-10-24.md). **Во всех последующих SSH скриптах сразу очищать экран + scrollback:** `printf '\033[3J\033[2J\033[H'` после strict shell setup.

> **EMAIL-FIRST WEBSITE MFA / 24.10.2026 — НОВОЕ РЕШЕНИЕ.** Пользователь утвердил: у гостя без действующего Trusted Device и без TOTP, после проверки пароля сначала код на **подтверждённый email**; «Не приходит код?» → по явному нажатию существующий **SMS challenge** на телефон из профиля; остальные ветки доверия/TOTP/PIN не менять. Новые `deployment/website-mfa/EmailLoginChallenge.php` (5 минут, 3 попытки, hash, one-time, user+session+email binding) и `EmailSendBudget.php` (автономный Redis budget, 60с cooldown, provisional 5/час/user и 1000/час global, без IP) с offline tests и `website-mfa-email-first-source-audit.php` (SHA-guarded read-only инспекция актуального production login + frontend) находятся **ТОЛЬКО в GitHub feature**, **PRODUCTION_EMAIL_FIRST=OFF**. Нужно доказать email verification provenance / почтовую доставку, собрать точный минимальный патч live signin+UI и пройти QA. Нельзя считать `b_user.EMAIL` подтверждённым без реального доказательства. Массовый WEBSITE MFA уже установлен, его не откатывать/не переустанавливать для этих задач. [Детальный email-first checkpoint](EVRASIA_WEBSITE_MFA_EMAIL_FIRST_2026-10-24.md).

> **24.10.2026 — WEBSITE MFA ALL-USERS PHYSICALLY INSTALLED, OPERATOR PASS.** Current live production root@evrasia executed pinned installer `f9f194eea550bcecc1dcebd0914ec6533c3dd4d9` (Git blob `c1460244efe598bb23e2addeca8fb430655c7008`, offline CI [#40071228006](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40071228006) SUCCESS): self-test PASS; preflight 17 PASS; apply 20 PASS; independent status 6 PASS; `RELEASE_STATE=INSTALLED_ALL_USERS`; `WRAPPER_FINAL_STATUS=PASS WRAPPER_RC=0`. Exact root-private release/restore directory `/root/ev-web-mfa-release-packages/all-users-website-mfa-20261024-064446-b03bafa5`, command `bash /root/ev-web-mfa-release-packages/all-users-website-mfa-20261024-064446-b03bafa5/restore.sh --rollback /root/ev-web-mfa-release-packages/all-users-website-mfa-20261024-064446-b03bafa5`. Four website PHP files and global SMS budget helper installed with exact source SHAs, native Bitrix/V4/V5 unchanged, Trusted Devices untouched, database writes NO. Caps initial 100/h global, 500/day global, 100/h per server-REMOTE_ADDR (redundant global until external proxy IP verification). **WEBSITE_MFA_ALL_USERS=SOURCE_INSTALLED, USER_BROWSER_ACCEPTANCE=PENDING, ROLLBACK_NOT_TESTED_ON_LIVE=YES.** Do not re-apply; do not roll back without actual reported error. Next: validate browser flows and watch real SMS/login errors. More detail [all-users WEBSITE MFA handoff](EVRASIA_WEBSITE_MFA_ALL_USERS_HANDOFF_2026-10-24.md) and [release checklist](EVRASIA_WEBSITE_MFA_ALL_USERS_RELEASE_CHECKLIST_2026-10-24.md). All earlier entries saying website rollout NOT_INSTALLED are historical.

> **24.10.2026 — WEBSITE MFA direct all-users decision (latest).** Пользователь отменил дополнительную ограниченную группу: после принятого пилота поручил включить веб MFA сразу **для всех пользователей** и готов быстро получать отзывы и откатывать при проблеме. Готовый приватный пакет `/root/ev-web-mfa-release-packages/all-user-mfa-prepared-20261023-235710-4b06db78` проверен по SHA и PHP lint, отдельный source-marker review `PASS_COUNT=17 FINAL_RC=0`. Для controlled direct all-user installation в feature добавлен `site-integration/samzaberu-v5/deployment/website-mfa-all-users-deploy.sh` с `--preflight/--apply/--status/--rollback`: текущий live SHA, root-only backup, локальный offline restore, атомарная замена каждого файла и автооткат при неполной установке. Консервативный стартовый Redis SMS-потолок 100 SMS/час, 500/сутки; IP-лимит 100/час = общий лимит до проверки reverse-proxy client IP, чтобы общий IP прокси не ограничил до 20/час. В настройках TOTP кандидат добавляет предупреждение о возможной проблеме повторного login в мобильный V4. **GitHub feature installer != установленный production релиз**; до operator PASS и браузерной проверки `WEBSITE_ALL_USERS_INSTALLED=NO`; V4 unchanged, V5 AUTH OFF, trusted devices не трогать. Подробнее: [website all-users handoff](EVRASIA_WEBSITE_MFA_ALL_USERS_HANDOFF_2026-10-24.md), [release checklist](EVRASIA_WEBSITE_MFA_ALL_USERS_RELEASE_CHECKLIST_2026-10-24.md).

> **ТЕКУЩИЙ WEBSITE MFA RELEASE CHECKPOINT / 24.10.2026 — ПОСЛЕ ПЕРЕХОДА В НОВЫЙ ЧАТ.** Пилот TOTP + Trusted Device + прямой PIN принят пользователем. Повторная root@evrasia source-only проверка всех 9 production файлов и приватного бюджетного helper завершилась `PASS_COUNT=13 FINAL_RC=0`; установленные SHA сайта не изменились, `GlobalSmsBudget.php` в site **ABSENT**. **УЖЕ СОЗДАН и PHP-linted приватный 5-file all-user package**: `/root/ev-web-mfa-release-packages/all-user-mfa-prepared-20261023-235710-4b06db78`, builder `PASS_COUNT=118 WRAPPER_RC=0`, all-users signin SHA `e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a`, PIN SHA `0b12ed47cd74bfa03c91bdcab2c4bfd07c7ccac7b67852e9540ed9a71390c94c`. Зафиксированы пилотные исправления `PASSWORD_ORIGINAL` и trust/direct PIN parity; только приватная подготовка, **НЕ установлен, нет разрешённого installer**. Дубликат нового GitHub rebaser удалён. **Не пересобирать повторно**. [Текущий all-user handoff](EVRASIA_WEBSITE_MFA_ALL_USERS_HANDOFF_2026-10-24.md), [релизные gates](EVRASIA_WEBSITE_MFA_ALL_USERS_RELEASE_CHECKLIST_2026-10-24.md). Далее: внешний PHP client IP/proxy path, SMS numeric caps, candidate security/rollback, controlled browser cohort; V4 без изменений, возможное влияние opt-in TOTP на повторный login V4 принято как временный риск с предупреждением, V5 AUTH OFF. Массовый rollout ещё НЕ выполнен.

> **АКТУАЛЬНЫЙ WEBSITE MFA HANDOFF / 24.10.2026**: после исправления `PASSWORD_ORIGINAL` и прямого PIN по server-verified Trusted Device пилот `USER_ID=880339` принят в браузере пользователем: «да все ок, можем делать для всех». Приоритет текущей работы — **расширение WEBSITE MFA для всех**, а НЕ повторное изучение пилота или включение mobile V5 AUTH. [Актуальная точка продолжения](EVRASIA_WEBSITE_MFA_ALL_USERS_HANDOFF_2026-10-24.md), [чек-лист безопасного релиза](EVRASIA_WEBSITE_MFA_ALL_USERS_RELEASE_CHECKLIST_2026-10-24.md), [текстовая инструкция нового чата](EVRASIA_AI_BOT_NEW_CHAT_INSTRUCTION_2026-10-24_WEBSITE_MFA_ALL_USERS.txt). Production website login SHA `b63094e5d45ad74f28f9594b605944526adbfad9fd0dd507ccabf11f843ed174`, PIN SHA `dee875d300403f11ad13fdfafc760fabd52daf39e03f31ee89f847d4dd427510`. **Пилот PASS; массовый WEBSITE rollout только ОДОБРЕН, но ЕЩЁ НЕ УСТАНОВЛЕН.** Старый V3 пакет собран против предпилотных SHA — напрямую не устанавливать; нужен rebase поверх live, SMS/IP security gates, V4 compatibility и rollback. API V5 public legal GET принят на `evrasia.rest`, V5 AUTH OFF, V4 не менялся, Trusted Device (~44 210) не очищать. Старые checkpoints ниже — история, не действующая release-инструкция.

> **LATEST ACTIVE WORKSTREAM 18.10.2026 — СамЗаберу API V5 legal/signup:** [new-chat handoff](EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-18_V5_LEGAL_SIGNUP.md), [API contracts](SAMZABERU_V5_DEVELOPER_TOKEN_AND_PUBLIC_CONSENTS_2026-10-18.md). Source-only PROD PASS: site signup `$consentParams=USER_ID,URL`, native `Consent::addByContext` reads `USER_ID,IP,URL,ITEMS`, no direct transaction methods; positive native atomicity not proven. Feature `V5BitrixStagedSignupConsentWriter.php` (staging-only consent half), [CI 39114444560](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39114444560) **30/30 PASS**. Next inspect `CUser::Add` and full registration transaction/effects, then isolated staging E2E; not V4, not production. Older 12–15 Oct context below is historical.

# Evrasia AI Bot — AI Project Context

> **НОВЕЙШАЯ ТОЧКА ПРОДОЛЖЕНИЯ ВСЕГО PROJECT — 2026-10-14:** [EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-14.md](EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-14.md). Последний **фактический** операторский V5 source-only скрипт `v5_phone_check_masked_control_flow_readonly.sh` на `evrasia` уже завершился **5/5 PASS**; в отдельном активном `Controllers/V4/Auth.php` прямых `PhoneAuth::check/sendCode` caller sites **0** (только этот файл). Новый **неисполненный** CI-tested шаг — `v5_phone_check_active_caller_discovery_readonly.sh` в feature (CI 38571136784 SUCCESS). Исторические указания ниже о recovery/native depth и предыдущих скриптах **НЕ являются текущим следующим шагом**. Production/V4/DB/SMS не изменялись; V5 и isolated Bitrix/MySQL staging не развернуты.


> **ПРИОРИТЕТНАЯ ТОЧКА ПРОДОЛЖЕНИЯ V5 (2026-10-13):** [SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md](SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md) — актуальнее всех старых ссылок ниже. Последняя source-only диагностика native MFA writer/events на `evrasia` завершена 9/9 PASS; `Otp::save` очищает recovery перед merge, DataManager before/after порядок доказан. V5 pinned native depth уже реализован **только в GitHub feature**, 22/22 offline suites PASS; isolated Bitrix staging отсутствует. Следующий stdout — `v5_native_recovery_merge_readonly.sh`; не повторять старые source/DB проверки. Production/V4 не изменялись; V5 503 по последней документации.


> **ПЕРЕХОД В НОВЫЙ ЧАТ — текущий SamZaberu V5 checkpoint 2026-10-12:** [`SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-12.md`](SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-12.md). Если первое сообщение пользователя — stdout `v5_native_depth_readonly.sh` с `SOURCE=Connection` и `TRANSACTION_RELATED_VARIABLES`, продолжать **не с нуля**, а с анализа внутренней глубины native Bitrix Mysqli transaction. Изолированный `V5StagingNativeTransactionPermit` (source blob `097a8dbd0e627a7cfbe7eedfcd9c207619b1a992`) проверен только на fake Bitrix (`62/62 PASS`); native depth port **не реализован**. `V5 HTTP=503`; никакого Bitrix runtime staging E2E/deployment, миграций, SMS, production writes. Старые Anti-Fraud production checkpoints остаются отдельными задачами.


> Operational source of truth for continuing Evrasia AI Bot work across chats.
>
> **Last updated:** 2026-10-03
> **Repository:** `juvantusik/Evrasia_AI_bot`
> **Current accepted deployed app revision:** `949aec3fd76af2d6525f7705ad31cd798d533fbb`
> **Current production milestone:** PR #73 is merged, deployed, server-verified and browser-accepted; the 2026-10-02/03 Anti-Fraud incident is closed. Permanent nginx IP-wait guard is installed; normal-reboot acceptance remains pending.

---

## 1. Continuation rule

In a new chat, read in this order:

0. `docs/ANTI_FRAUD_PR73_PRODUCTION_ACCEPTANCE_2026-10-03.md` — **CURRENT AUTHORITATIVE PRODUCTION ACCEPTANCE**: PR #73 + closed Anti-Fraud incident
0A. `docs/ANTIFRAUD_INCIDENT_HANDOFF_2026-10-02.md` — closed incident history/root-cause summary
0B. `docs/SAMZABERU_LEGAL_CONSENTS_REGISTRATION_2026-10-02.md` — accepted registration-consent design; implementation pending
0C. `docs/ANTI_FRAUD_PR65_PRODUCTION_ACCEPTANCE_2026-09-23.md` — previous operator physical-history acceptance
0D. `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md` — historical PR #62 and earlier Anti-Fraud context
0E. `docs/SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md` — accepted SamZaberu Trusted Device E2E
1. `docs/PROJECT_CHECKPOINT.md`
2. `docs/AI_PROJECT_CONTEXT.md`
3. `docs/CURRENT_ARCHITECTURE.md`
4. `docs/SERVER_SCRIPT_RULES.md`
5. `SERVER_UPDATES.md`
6. `docs/ANTI_FRAUD_OPERATOR_SETTINGS.md`
7. `docs/NEW_CHAT_HANDOFF.md`

Source priority:

**production actual state → current GitHub → staging/test → current docs → older discussion**.

Do not replay completed deployment, block/unblock, performance or visual-acceptance steps unless a new change makes them relevant.

A documentation-only commit may advance GitHub `main` without changing production. Keep GitHub head and deployed application revision conceptually separate and verify factual runtime before every production mutation.

---

## 2. Current production baseline

Host/runtime:

- hostname: `eur-bot-01`
- IP: `192.168.103.200`
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- app service/container: `evrasia-ai-bot-app`
- DB service/container: `evrasia-ai-bot-db`
- production DB / role: `evrasia_ai_bot`
- direct app port: `127.0.0.1:18080`
- network: `evrasia-prod-internal`
- volume: `evrasia-postgres-prod-data`

Accepted application baseline after PR #73:

- revision: `949aec3fd76af2d6525f7705ad31cd798d533fbb`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:8f1cbe957e8f85afd00a5c28a03793e602c09b9e8eba3d9a96a8e5366af79ed3`
- image ID: `sha256:4ed7d900f318acce1ecc3d8aac0cfd7a4cfd882990735d1e089235dd13ee0478`
- canonical Compose SHA256: `4580356ddcfbe37e639895510c872a5c13831777a8ee6d032147171bf762af80`
- migrations: **26**
- current confirmed bonus threshold: `40000`
- accepted runtime state: `running healthy`, restart count 0
- deployment backup: `/opt/evrasia-ai-bot/backups/pr73-prod-deploy-20261003-103819`
- nginx IP-wait drop-in SHA256: `835832635b2593dc8786b96ed602a1957931782c482cf15fbe91d180afda8c39`

PR #58 deployment backup:

`/opt/evrasia-ai-bot/backups/pr58-ui-labels-continuation-20260920-084234`

Current PR #58 UI rules:

- `Устройство` = one linked USER_ID on this Trusted Device hash;
- `Общее устройство` = two or more linked USER_ID values on this same hash type;
- if a second USER_ID later appears on the same hash, shared-device grouping can occur after the next sync/scoring cycle.

Current Scout display wording:

- `дней с 3 чекинами за 7 дней`;
- `дней с 3+ чекинами за 60 дней`.

The first phrase is display-only. Technical `days_2plus_7d` still uses `>=2`. Do not change backend semantics unless the operator separately approves a rule change.

Documentation-only commits after this checkpoint may advance GitHub `main` without changing the deployed application identity. Re-read actual runtime revision/image before every production mutation.

---

## 2A. PR #65 manual physical-history invariant

Manual operator physical history is now a dedicated per-investigation snapshot.

- source: targeted protected Check-in endpoint, max 60 days;
- persistence: `anti_fraud_operator_investigation_visits`;
- scope: exact `investigation_id`;
- parent window: `physical_history_from` / `physical_history_until`;
- UI metrics read the dedicated snapshot;
- `anti_fraud_visits` remains automatic risk/history telemetry and must not receive operator physical snapshot events;
- unresolved targeted card mappings fail closed.

Accepted site-side targeted dedup service SHA:
`5f65703d91ee31a9d829cd64cefd011309c8a6c44d3fa96d2d8c77a1f81541e9`.

Production acceptance:
- USER_ID `6645`: 10 source events = 10 snapshot rows = 10 UI physical visits; telemetry intersection 0;
- USER_ID `408974`: 8 source events = 8 snapshot rows = 8 UI physical visits; telemetry intersection 0;
- combined result: **31 PASS / 0 FAIL / 0 WARN**.

---

## 2B. SamZaberu mobile Trusted Device — CLOSED / PRODUCTION / E2E ACCEPTED

Production acceptance completed 2026-09-24.

External mobile contract:

- `device_id` = literal `sz_` + 64 lowercase hex characters = **67 chars total**;
- one random installation identity per app installation;
- `platform` = `ios|android`;
- `sz_` is the namespace and must not be stripped.

Website production adapter:

- host `evrasia`;
- `/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/lib/Services/TrustedDeviceMobileService.php`;
- accepted SHA256 `c59d2a9e1b70aa026b603b457673a842dbaa6b784eae25b40c5e03684b9e612d`;
- backup `/home/site_evrasia/web/evrasia.spb.ru/backups/mobile-device-core-adapter-20260924-174920-286568`.

The mobile adapter derives the shared-core identity as SHA-256 of the **full namespaced normalized external value**, including `sz_`. The resulting 64-hex value is passed to the existing `TrustedDeviceService`, whose accepted SHA remains `32a29a5dff76372961a9ec7879b1f2eaf4deeab0a35e2a62a62fdc4a4c091022`. Shared browser/core behavior was not changed.

Safe USER_ID `880339` E2E proof:

- site row ID `21732`, ACTIVE, PASSWORD, `SAMZABERU_IOS`, created `2026-09-24 19:16:31 MSK`;
- site state changed from 6 -> 7 rows and 6 -> 7 unique hashes;
- Trusted Device bot sync succeeded `19:22:13 MSK`;
- one matching current link reached `anti_fraud_device_links`;
- two matching events reached `anti_fraud_device_events`: `event_type=10` trust-created and `event_type=1` login, both `auth_method=2`;
- `BOT_INGEST_RESULT=PASS_DEVICE_LINK_SYNCED`.

Current non-blocking follow-up: `client_type=NULL` on the accepted bot-side link/events. Explicit SamZaberu iOS/Android display metadata is separate future work.

Do **not** reopen the device-registration flow unless new evidence appears. See the dedicated acceptance document above.

---

## 2C. Anti-Fraud UI / unblock incident — CLOSED / ACCEPTED (2026-10-03)

The 2026-10-02/03 incident is closed.

Proved root causes:

- nginx boot race: `network-online.target` could be reached before DHCP assigned `192.168.103.200/24`; permanent IP-wait systemd drop-in installed;
- successful unblock could leave bot-local `display_name` stale until `bitrix_account_map`; PR #73 now removes only exact trailing ` - блок ИТ` immediately after real successful unblock.

PR #73 production deployment: **58 PASS / 0 WARN / 0 FAIL**, rollback not required. Operator browser acceptance: **«все работает»**.

Authoritative acceptance:
`docs/ANTI_FRAUD_PR73_PRODUCTION_ACCEPTANCE_2026-10-03.md`.

The nginx guard is installed/currently healthy; validate it at the next normal/approved reboot rather than rebooting production only for reassurance.

## 2D. SamZaberu mobile legal consents — registration design correction

The 2026-09-18 mobile Legal Consents TЗ correctly describes the JWT-protected **existing-user** flow but omitted the first-time registration case.

Previously established production facts:

- V4 `/api/v4/signup` creates the Bitrix USER_ID and returns registration data;
- signup itself does **not** issue JWT;
- JWT issue points were already mapped during the all-device logout/session-revocation work.

Accepted correction:

- before JWT, mobile registration obtains the current legal document catalog/content/codes from the server without hardcoding;
- the signup request carries the accepted current `code` values;
- backend validates current/required codes, creates USER_ID and persists native Bitrix consent events for that USER_ID;
- marketing remains optional;
- JWT-protected `/legal/consents` + `/accept` remain for authenticated existing users / later document revisions.

Implementation is **pending**. Do not describe the public document GET or signup `agreements` field as production yet.

Authoritative design note:
`docs/SAMZABERU_LEGAL_CONSENTS_REGISTRATION_2026-10-02.md`.

---

## 3. Product architecture — IMPORTANT

Evrasia AI Bot remains **one production application** with four current directions:

1. **Phonebook** — `/phonebook`
2. **Anti-Fraud** — `/antifraud` + protected scheduler/data pipeline
3. **SamZaberu** — Telegram scenario inside `EvrasiaTelegramBotV2`
4. **Corporate communications / MegaFon** — Telegram scenario/workflow inside the same application

Canonical route contract:

- `/phonebook` = current Phonebook UI
- `/antifraud` = current Anti-Fraud UI
- `/directory` = removed / expected HTTP 404
- `/api/directory/...` = removed / expected HTTP 404

Legacy TEST `evrasia-ai-bot-v17-test` is exited/archival and must not be restarted blindly.

---

## 4. GitHub / release history relevant to current production

### Current Anti-Fraud release lineage after the earlier UI milestones

The earlier PR #32–#45 lineage below is retained as history. Current continuation additionally depends on:

- PR #47 — operator-facing `Новый` changed to persistent web-first-seen semantics with a 24-hour window; migration/state introduced and deployed earlier;
- PR #52 — generic operator watchlist support; useful infrastructure but **not** the final manual-investigation persistence model;
- PR #53 — Avito manual override experiment; **closed unmerged**, never production;
- PR #55 — first Check-in Scout implementation; unsafe image must **never be deployed**;
- PR #56 — corrected Check-in Scout; merged/deployed/verified; migration `0023_anti_fraud_checkin_scout`;
- PR #57 — exposes all case Trusted Device identifiers while keeping shared-device grouping semantics separate;
- PR #58 — Russian device labels + Scout display wording; merged/deployed/operator accepted at revision `700422b3c9004c2d92092a166e50ac5e8e8a6d33`.
- PR #60 — manual operator investigation by phone; merged/deployed/verified with migration `0024_anti_fraud_operator_investigation`.
- PR #62 — operator-visible manual-investigation evidence: exact 60-day window, visit metrics/day summary, Trusted Device prefix and linked USER_ID display; merged/deployed/verified at revision `dfde4c39b3821f6946d3be05448d11aea1fcc441`.
- PR #65 — dedicated operator physical Check-in snapshot, physical-history window fields, UI source switch away from `anti_fraud_visits`, fail-closed unresolved-card handling; merged/deployed/accepted at revision `d1746ceabb513727baad729adbd3333328fc2dab` with migration `0025_anti_fraud_operator_physical_history`.
- PR #67 — removes the stale `loyaltyHistoryLoadedAt` UI gate; merged/deployed/browser-accepted at revision `b0d12a112577de2a35e0a49e55367e3bc459bc07`; no migration. Five legacy latest-ready investigations were backfilled, leaving 0 without physical coverage.
- PR #73 — immediate local display-name cache correction after real successful unblock; merged/deployed/browser-accepted at revision `949aec3fd76af2d6525f7705ad31cd798d533fbb`; no migration.

Detailed current production facts are in `docs/ANTI_FRAUD_PR73_PRODUCTION_ACCEPTANCE_2026-10-03.md`; PR #65 remains the historical physical-history acceptance.

Merged application PRs leading to the current state:

- PR #32 — v1.7 Anti-Fraud release → `33e3548ab014e927e1e00074e27f3a11ef252bbc`
- PR #33 — remove obsolete `/directory` → `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`
- PR #34 — similar-identity grouping + async refresh UX
- PR #35 — CI regression fix → `be631fd31c96434ac7232f5e1641ecf9ea94c823`
- PR #36 — Bitrix blocking status/operator actions → `947c815d15cc12d0ce571edbc2a2905ef9fed009`
- PR #37 — blocking UI/unblock/group bonus → `86c98eacf5959ca2bf8d0f2c95441ff1e4192f8f`
- PR #38 — remove all-pairs identity similarity scan → `971af94e26160914efd2c229a4352d398a65214a`
- PR #41 — inactive operational exclusion + reason localization → `a156db2e30dd2a31d7bd4126410f9f513382adaa`
- PR #43 — configurable bonus threshold + new-account badge → `3ce9f8c1904351d77696e70314bba3c60afeffa5`
- PR #44 — first settings-modal viewport overflow fix → `a45554b25a820615c95b3a3148a38640a4a27507`
- PR #45 — final settings-modal viewport regression fix → `b7402cbe19b14f4d84c77870c8be876fe6f7bf42`

PR #44 was infrastructure/deployment healthy but visually unsatisfactory and is superseded by PR #45.

PR #45 final approach:

- React portal into `document.body`;
- viewport-relative fixed overlay;
- one internal vertical scroll container;
- desktop/modal controls fully reachable;
- existing mobile behavior preserved.

The exact PR #45 merge commit CI passed and published the immutable image in section 2.

---

## 5. Anti-Fraud product contract — current

Anti-Fraud remains advisory/investigative. Risk never auto-blocks an account.

Risk thresholds:

- critical `>=75`
- high `>=50`
- medium `>=25`

High-balance rule is now operator-configurable:

- persisted key: `anti_fraud_bonus_balance_threshold`
- storage: existing `bot_settings`
- current/default production value: `40000`
- strict comparison: current bonus balance **>`threshold`** → +50 and history gate
- equality does not trigger
- saving setting does not itself run refresh
- next scheduled/manual scoring cycle reads it
- no migration
- does not alter grouping and does not auto-block.

Loyalty semantics remain:

- account balance is not multiplied by active-card count
- multiple active cards alone add no automatic risk points
- known zero differs from missing
- negative current balance is valid
- detailed 60-day history is targeted after gate, never fleet-wide.

Grouping evidence remains separate from risk evidence; behavioral signals alone do not merge identities.

---

## 5A. Check-in Scout and manual investigation — current

Check-in Scout is a separate upstream detector for **physical check-in frequency** because the normal 60-day loyalty-history path sits behind a risk gate.

Authoritative physical source:

- Bitrix offline-order/check-in data fed by `VIP_TODAY`;
- do not count `VIP_HISTORY` rows as physical visits.

Production Scout behavior:

- 1 physical check-in/day → normal/no persistent Scout row;
- 2+ in a Moscow day → WATCH;
- 3rd same day or a later WATCH day with 2+ → targeted 60-day deep check;
- WATCH retained through current day + two later calendar days;
- confirmation: 3 different days with 2+ in 7d OR 3 different days with 3+ in 60d;
- confirmed frequency → Risk 100 / Critical independently;
- no auto-block.

Protected site route:

`/api/internal/anti-fraud/checkins`

Manual-investigation Step 2:

- operator action: **«Добавить на проверку»**;
- primary input: phone;
- operator source/reason must be persistent and separate from automatic telemetry;
- no auto-block.

Bitrix phone resolver is already production:

`/api/internal/anti-fraud/phone-resolve`

Resolver behavior:

- 200 unique;
- 400 invalid;
- 404 not found;
- 409 ambiguous without choosing a USER_ID;
- 503 internal/candidate-limit problem.

Bot-side Step 2 from PR #60 is **MERGED / DEPLOYED / VERIFIED** in production.

Implemented in PR #60:

- protected phone gateway using exact production request field `phone`;
- migration `0024_anti_fraud_operator_investigation`;
- persistent operator source/reason and state;
- explicit operator-authorized 60-day history path;
- address-specific worker + restart recovery;
- normal scoring without fake `riskGateConfirmed`;
- persistent Risk-0 visibility;
- phone-first UI action **«Добавить на проверку»**;
- no auto-block;
- gateway regression tests and CI schema-smoke.

Read-only production inspection reconfirmed the Bitrix resolver SHAs and showed `$payload['phone'] ?? null` as the exact input field. Production resolver itself was not modified.

Production acceptance on 2026-09-20: revision `f98d10c327e14b6dd5a34a9117ce25310ed6180e`, immutable digest `sha256:6b21a15ad09bd82643401e6d1f3a2c18ab8dd42adcdfb1f4997b26a71f487e40`, image ID `sha256:af1e6ee925cd55ad2ed63be12fe13e8f18e3f33a95678bfe8f14141756762c43`, migrations 25, deployment 13 PASS / 0 FAIL / 0 WARN, rollback not required. Backup: `/opt/evrasia-ai-bot/backups/pr60-manual-investigation-20260920-153256`.

Do not repeat deployment or backend smoke merely for reassurance. The remaining acceptance step is a deliberate operator-visible end-to-end use of **«Добавить на проверку»** on an account the operator actually intends to investigate.

See `docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md` for hashes, routes, backup paths and deployment proof.

## 6. `Новый` account badge — current semantics

PR #47 changed the operator-facing meaning of `Новый` to a persistent first-seen window:

- source table: `anti_fraud_web_account_state(bitrix_user_id, first_seen_at)`;
- a USER_ID is shown as `Новый` for **24 hours** after first appearing in the web Anti-Fraud interface;
- repeated scheduler/manual refreshes do not reset or extend the 24-hour window;
- historical accounts were bootstrapped as old during rollout;
- forensic case-delta history remains separate and is not the operator-facing 24-hour definition.

Do not revert to the older PR #43 interpretation that equated the operator badge directly with the latest case `addedAccountIds`.

---

## 7. Bitrix account state / operational visibility

Bitrix is the source of truth:

- `ACTIVE=Y`, `BLOCKED=N` → **Активен**
- `ACTIVE=N`, `BLOCKED=N` → **Неактивен**
- any `BLOCKED=Y` → **Заблокирован**

Only `BLOCKED=Y` is a true Bitrix block.

Operational rules remain:

- inactive and blocked hidden by default;
- toggle: `Показать заблокированных и неактивных`;
- inactive remains `Неактивен`;
- KPI/shared-device/duplicate-contact summaries exclude both;
- group bulk block targets active unblocked accounts only;
- risk/group evidence can still include excluded case members.

Localization remains accepted, including Russian operator text for `max_devices_for_same_pair=...` and related generated reason keys.

---

## 8. Manual block / unblock — PRODUCTION ACCEPTED / PR #73

Routes:

- Bitrix: `POST /api/internal/anti-fraud/block`, `POST /api/internal/anti-fraud/unblock`
- bot: `POST /api/anti-fraud/block`, `POST /api/anti-fraud/unblock`

Safe USER_ID `880339` controlled round-trip is already production-proven:

- initial `ACTIVE=Y/BLOCKED=N`
- block `ACTIVE=N/BLOCKED=Y`
- unblock restored `ACTIVE=Y/BLOCKED=N`
- historical reason retained
- exactly two audit transitions
- risk/history/bonus unchanged
- 27 PASS / 0 FAIL / 0 WARN
- no real customer mutation.

The controlled USER_ID `880339` round-trip remains valid historical acceptance and must not be repeated merely for reassurance.

**Current production path is accepted again.** PR #73 removed the transient local-name lag after successful unblock; production deploy and operator browser verification passed. See `docs/ANTI_FRAUD_PR73_PRODUCTION_ACCEPTANCE_2026-10-03.md`.

Never mutate a real customer or fabricate production risk data merely to create a visual fixture.

---

## 9. Similarity performance — COMPLETE

PR #38 removed exhaustive all-pairs candidate scanning while retaining final evaluator semantics.

Production acceptance:

- protected cycle before: 206 s
- after: 53 s
- `/api/healthz`: 24/24 HTTP 200, max 3 ms
- `/api/anti-fraud/scheduler`: 24/24 HTTP 200, max 6 ms
- app restart count 0.

`anti_fraud_risk_scoring` ~3.1 s does not include the later similarity overlay and is not a valid PR #38 performance gate.

Do not rerun unless a future relevant change requires it.

---

## 10. Anti-Fraud scheduler / async contract

Runtime remains:

- scheduler enabled
- interval 15 minutes
- run-on-start false.

Manual refresh:

- accepted `POST /api/anti-fraud/refresh` returns 202
- UI polls `/api/anti-fraud/scheduler`
- already-running cycle remains 409
- persisted `anti_fraud_sync_runs` is authoritative for accepted background cycles.

A later HTTP timeout after 202 is not proof of job failure. Never trigger a second refresh until the prior accepted run is factually complete/failed.

---

## 11. Credentials / integration boundaries

- bot container must **not** receive RestIS credentials;
- loyalty/history access uses protected site-side integration;
- raw loyalty card numbers must not appear in bot UI/API/logs;
- required secret mounts remain protected and must not be printed;
- Trusted Device is app installation/trust identity, not IP/hardware identity;
- SamZaberu external installation identity is `sz_ + 64hex`; the full namespaced value is deterministically hashed in the mobile adapter before entering the shared 64-hex core contract;
- raw SamZaberu `device_id`, full device hash and trust token must not be printed;
- logout must not manufacture a new identity.

---

## 12. Backups / TEST

Retain backups until explicit cleanup approval.

Known current retained backups include:

- `/opt/evrasia-ai-bot/backups/pr44-modal-fix-20260909-111032`
- `/opt/evrasia-ai-bot/backups/pr41-inactive-ui-20260908-110846`
- `/opt/evrasia-ai-bot/backups/anti-fraud-similarity-hotfix-20260908-090617`
- earlier v1.7 backups listed in `SERVER_UPDATES.md`.

Exact final PR #45 backup path was not captured in chat; inspect server before future cleanup/rollback planning.

---

## 13. Mandatory server-script rules

`docs/SERVER_SCRIPT_RULES.md` is mandatory.

Core rules:

- one complete copy/paste block;
- long script → quoted-heredoc wrapper → `bash -n` → execute if valid → remove temp file;
- structured numbered output and explicit `PASS/FAIL/FINAL_STATUS/FINAL_RC`;
- architecture-aware guards only;
- exact production baseline must be read from **factual current production**, not remembered from an earlier step;
- no unrelated Telegram/RestIS gates for Anti-Fraud-only work;
- scheduler race → bounded wait + revalidation;
- existing GHCR auth under `tech`;
- stage Compose in production directory when relative paths exist;
- no secrets in output;
- backup/rollback/post-check for production mutations;
- terminal stays open.

PR #45 deployment lesson: an initial script correctly stopped before cutover because it expected stale `3ce9f8c...` while production had already advanced to PR #44 `a45554b...`. The guard was correct; the script-generation baseline was wrong. Never bypass such a guard—confirm actual state and regenerate against it.

---

## 14. Paused work

Full-Bitrix email investigation remains paused. Historical anomaly investigation around USER_ID 737384 remains paused. Do not resume unless explicitly asked.

---

## 15. Immediate continuation point

The current Anti-Fraud milestone is **implemented, deployed and visually accepted**.

Do not automatically reopen:

- PR #43/#44/#45 work
- modal viewport redesign
- USER_ID 880339 acceptance
- 53-second performance refresh
- archival TEST
- paused email/anomaly investigations.

Start next iteration from the next user requirement, after checking current GitHub/CI and factual production runtime.

---

## 16. Maintenance rule

After any material change to deployed revision/image, topology, DB/migrations, scheduler behavior, integrations, Anti-Fraud rules, canonical routes or backup/rollback state, update:

- `docs/PROJECT_CHECKPOINT.md`
- `docs/AI_PROJECT_CONTEXT.md`
- `docs/CURRENT_ARCHITECTURE.md` when architecture actually changes
- `docs/NEW_CHAT_HANDOFF.md`
- `SERVER_UPDATES.md`
- `docs/ANTI_FRAUD_OPERATOR_SETTINGS.md` for operator-setting/UI semantics
- `docs/SERVER_SCRIPT_RULES.md` when a new operational lesson is learned.

When a decision changes, record **Было → Стало → Причина** and keep production application identity separate from docs-only GitHub revisions.


---

## 17. TOTP 2FA / protected-profile workstream — PLANNED

Authoritative continuation document:

`docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`

Continuation keyword: **ПАНДА ДВА**.

The workstream is read-only investigated and not implemented. It covers optional TOTP 2FA for guest accounts, SMS ownership confirmation before authenticator enrollment, pilot rollout for Bitrix USER_ID `880339`, session revocation after enrollment, and the planned direct bonus-PIN experience for a session that has actually passed the second factor.

Confirmed production facts, file paths, table structures, SHA baselines, current Bitrix MFA state, current signin/PIN flows, security invariants and exact next steps are maintained in the dedicated checkpoint above.

When the operator says **ПАНДА ДВА**, resume from that document and do not repeat the completed discovery audits.
