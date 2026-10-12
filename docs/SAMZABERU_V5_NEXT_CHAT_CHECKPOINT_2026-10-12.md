# Evrasia AI Bot — точка продолжения API V5 для нового чата

**Зафиксировано 2026-10-12.** Этот документ — актуальная точка продолжения **ветки СамЗаберу API V5**, НЕ новая точка Anti-Fraud production. Приоритет: фактическое production → текущий GitHub → staging → docs → старые обсуждения.

## 1. Короткая инструкция нового чата

Если пользователь первым сообщением вставил консольный вывод с заголовком **`TRANSACTION NESTING / OWNERSHIP SOURCE TOKENS`** или строками `SOURCE=Connection`, `SOURCE=MysqlCommonConnection`, `SOURCE=MysqliConnection`, то:

1. Считать это **результатом следующего согласованного read-only скрипта** `site-integration/samzaberu-v5/tests/diagnostics/v5_native_depth_readonly.sh`. Не переспрашивать «что это за вывод» и не начинать новый аудит `b_user`/V4/TOTP.
2. Проверить `FINAL_STATUS` и source SHA; при `FAIL` или изменившемся SHA **не обходить guards**, остановиться и разобрать расхождение.
3. По `TRANSACTION_RELATED_VARIABLES` и `METHOD=startTransaction/commitTransaction/rollbackTransaction` определить, как установленная версия Bitrix учитывает внутренний счётчик/вложенность и какие ещё **узкие** доказательства нужны для `V5VerifiedNativeTransactionDepthPort`. Статический вывод не доказывает runtime ownership. Если исходниковых сведений недостаточно — запросить **только целевой** безопасный read-only фрагмент, а не повторять уже выполненные широкие исследования.
4. Затем продолжить минимальную GitHub-only реализацию с автономными тестами, сохранив V5 fail-closed HTTP 503, без production SQL / Bitrix hooks / миграций / SMS.
5. Использовать один цельный copy-paste диагностический скрипт с guard, секреты не выводить, production изменения не выполнять без отдельного явного разрешения.

**Точка входа:** настоящее сообщение пользователя в новом чате — это вход в шаг 3, а не новая постановка задачи.

## 2. GitHub и документы

Репозиторий: `juvantusik/Evrasia_AI_bot`.

- Рабочая ветка **`feature/samzaberu-api-v5-auth-contract`** — исходники/тесты V5, только GitHub, **НЕ deployed**.
- `main` — постоянная история production и общий реестр. **`docs/STRUCTURE_REGISTRY.md` поддерживать побайтово одинаковым** в `main` и feature.
- Вначале читать: `docs/STRUCTURE_REGISTRY.md` (новые подтверждённые факты), настоящий checkpoint, `docs/SAMZABERU_V5_STAGING_TRANSACTION_PERMIT_2026-10-12.md`, `docs/SAMZABERU_V5_BITRIX_TRANSACTION_BRIDGE_2026-10-12.md`, `site-integration/samzaberu-v5/README.md`.
- События 2FA: `docs/SAMZABERU_V5_NATIVE_MUTATION_COVERAGE_2026-10-12.md`. SMS финансовая защита: `docs/SAMZABERU_V5_SMS_SEND_GUARDS_2026-10-11.md`. Ранее согласованная логика: `docs/SAMZABERU_API_V5_AUTH_CONTRACT_2026-10-11.md`.
- Остальные `PROJECT_CHECKPOINT`, `AI_PROJECT_CONTEXT`, `NEW_CHAT_HANDOFF` и `CURRENT_ARCHITECTURE` содержат исторические разделы Anti-Fraud; не выдавать старую стадию TOTP за текущую.

## 3. Подтверждённые факты production (ничего сегодня не менять)

Production Bitrix host `evrasia`, root `/home/site_evrasia/web/evrasia.spb.ru/public_html`. Bot host `eur-bot-01`.

- Сайт работает со штатным `Bitrix\Main\DB\MysqliConnection`, а не PDO; `bitrix/.settings.php` (прочитана **только как текст**, без секрета) содержит class hint `MYSQLI`.
- В присланных пользователем двух завершённых **READ_ONLY** статических диагностиках: `FINAL_STATUS=PASS`, 8/8 и 4/4; проверены зафиксированные SHA исходников ORM, MFA, ``connection.php` / `mysqlcommonconnection.php` / `mysqliconnection.php`, методы native `startTransaction / commitTransaction / rollbackTransaction`. Сайт/БД не менялись.
- Путь Bitrix source **строчный**: `bitrix/modules/main/lib/db/`.
- SHA256 `connection.php`: `efed4b13015a6f57063d30666baa79456f49ead188e3daf0395bd2350074a21c`.
- SHA256 `mysqlcommonconnection.php`: `2a43ad5a5ae90982b52dbea5980bfae469e23aa024c371687696f25aed280893`.
- SHA256 `mysqliconnection.php`: `82877ae53f27a6bc429a8b9cda9709cc86f42cfc14225c4c1ba56a43c3121ec1`.
- ORM `UserTable::add/update/delete` использует Bitrix connection. Встроенные `Otp::save()` / `Otp::delete()` / `OtpEvents::onRecheckDeactivate()` — независимые пути мутаций MFA. **Нет доказательства** общей atomically-updated `AUTH_EPOCH` + `b_user` lock для всех native writers.
- Физические восемь проверенных столбцов `b_user` и источники V3C/TOTP/Trusted Device уже зафиксированы в `STRUCTURE_REGISTRY`. **Не повторять этот аудит**.
- API V4 и production-сервисы не модифицированы в данной V5-ветке; `site-integration/samzaberu-v5/api/v5/router.php` по-прежнему **HTTP 503 fail-closed**.
- `ev_samzaberu_auth_state` и три V5 SMS-таблицы — **пока только DDL PROPOSALS**, не считать существующими в production/staging.

## 4. Последняя подтверждённая реализация GitHub-only

Бизнес-логика, которую НЕ менять без необходимости: TOTP-аккаунт всегда вводит authenticator code; иначе strong MFA-proven trust может отменить SMS, а без trust сервер выдаёт SMS challenge на подтверждённый номер без поля ввода телефона; после strong MFA-backed V5 сессии бонусный PIN показывается без повторной SMS.

Накопленные автономные фазы, **не суммировать как единый live E2E**:

- Первичная защита пароля: four-scope first-factor gate, atomic windows, simulated PDO; 70 + 74 исторических PASS. Staging MySQL 24-worker тест **создан, но не запускался на реальном MySQL**.
- SMS: 10 HMAC/count/financial quotas `V5SmsDispatchBudget` (111 PASS); `V5MySqlSmsOutbox` (123 fake-PDO PASS); `V5BitrixLockedSmsEligibility` (88 PASS); event-based `V5SecurityEventVersions` (84 PASS).
- `V5NativeMutationCoverage` — 10 категорий нативных security/MFA событий (включая фонового MFA агента); 60 offline PASS. **Native hook/attestation не установлен**.
- `V5BitrixPdoCompatibilityBridge` — эмулирует только нужный PDO-интерфейс, но SQL/transactions отправляет через native `Application::getConnection()`; 80 fake-Mysqli PASS. Это **не реальный PDO MySQL** и **не server-side prepared statements**.
- `tests/integration/V5BitrixNativeOrmTransactionAcceptance.php` + `schema/PROPOSED_ev_v5_native_tx_probe.sql` — prepared-only staging проверка ORM↔SQL read-your-writes/ROLLBACK в обе стороны, локальный fake smoke прошёл, **реального isolated staging ещё нет/не запускали**.
- **Самая свежая точка:** `src/V5StagingNativeTransactionPermit.php` реализует staging-only предварительную проверку idle native MySQL session, identical connection ID, schema, host, two snapshots and **independent Bitrix native transaction depth=0**. `tests/V5StagingNativeTransactionPermitTest.php` **62/62 PASS на локальных fake objects**. Blob SHA: source `097a8dbd0e627a7cfbe7eedfcd9c207619b1a992`, test `0aa6af73091c68733d0a5dcf5e6c224f2bf03bf4`. **Реальной реализации `V5VerifiedNativeTransactionDepthPort` ещё нет.**

Не путать статус OFFLINE TESTED с `staging` / `production`.

## 5. Последний скрипт для передачи вывода в новый чат

Файл в GitHub feature:
`site-integration/samzaberu-v5/tests/diagnostics/v5_native_depth_readonly.sh`.

Blob SHA: `5718f64f6eb6256645bffd0d526bb19ab0c0eee4`.

Это **новая** целевая read-only диагностика **только статических исходников** трёх файлов `Connection/MysqlCommonConnection/MysqliConnection`. Она отдельно проверена локально: `bash -n PASS`, embedded PHP syntax PASS, parser fake-source PASS; на неподходящем host ожидаемо отказывает **до чтения production**. **На хосте `evrasia` оператор её ещё не запускал**; вывод ещё не предоставлен.

Оператор должен выполнить **один цельный copy-paste wrapper** из чата 2026-10-12 на `evrasia` как root (он создаёт временный /tmp-файл, проверяет `bash -n`, запускает, удаляет файл, не закрывает SSH). Вывод `===1…5===` вставить **первым сообщением нового чата**; дополнительная история не требуется. Скрипт не делает DB-connection, Bitrix bootstrap, SMS/API write; исходники/секреты не печатает. **Даже `FINAL_STATUS=PASS` означает только «source read-only parser успешно отработал», не «transaction depth proven».**

## 6. Следующие технические шаги после получения вывода

1. По текущему SHA-backed output разобраться, как `Connection` и `MysqlCommonConnection` ведут transaction counters, как наследуются методы и есть ли авторитетный read-only depth API.
2. Если подтверждено безопасно — написать trusted `V5VerifiedNativeTransactionDepthPort` с proper no-foreign-rollback доказательством; если native API не позволяет это подтвердить — остаться fail-closed и выбрать явное соглашение владения/сериализации, не выдумывать «always zero».
3. Только на isolated staging — guarded `V5BitrixNativeOrmTransactionAcceptance` и real MySQL конкуренция. Нет staging — продолжать GitHub-only тесты, не трогая production.
4. Отдельный, **НЕ закрытый** барьер: все 10 native MFA/phone/password/logout/security mutation paths должны в одной физической транзакции с общими `b_user` locks обновлять `AUTH_EPOCH`; `OnAfter*` после commit не является доказательством.
5. Позже — SMS gateway/pricing, atomic code consume, V5 JWT/refresh/assurance и бонусный PIN, отдельный согласованный staging/production rollout.

**ПРОВЕРКА ГРАНИЦ:** `MODE=GITHUB_ONLY`; `PRODUCTION_WRITE=NO`; `DB_MIGRATIONS=NO`; `SMS_SENT=NO`; `NATIVE_DEPTH_PROVIDER=NOT_IMPLEMENTED`; `REAL_STAGING_E2E=NOT_RUN`; `V5_HTTP=503`; `V4_UNCHANGED=YES`.
