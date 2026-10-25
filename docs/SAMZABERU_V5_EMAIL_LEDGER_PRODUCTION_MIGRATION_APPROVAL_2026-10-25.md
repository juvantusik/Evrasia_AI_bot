# СамЗаберу API V5: минимальная таблица email challenge — пакет отдельного согласования

**Дата:** 25.10.2026. **Статус: PROPOSED / NOT APPROVED / NOT EXECUTED.**

## Решение, необходимое от владельца

Разрешить **исключительно CREATE TABLE** `eurasia_new.ev_v5_email_auth_challenges` по точному подготовленному кандидату:
`site-integration/samzaberu-v5/schema/PROPOSED_ev_v5_email_auth_challenges.sql`.

Это **не** разрешение на запуск V5 AUTH, отправку OTP, создание пользователей, изменение API V4, сайта, Restis, любой существующей таблицы, global email/SMS лимитов, Redis или PHP-FPM. Не считать сообщение «дальше» отдельным разрешением на DDL.

## Реально подтверждённая база, без повторного исследования

- Production host: `evrasia`; Bitrix root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`; DB: **существующая** `eurasia_new`.
- Предыдущие operator read-only checkpoints: `b_user`, `b_sec_user`, `ev_samzaberu_auth_state`, `ev_web_email_ownership_proof`, `ev_v5_auth_challenges`, `ev_v5_strong_sessions` — InnoDB.
- `ev_v5_email_auth_challenges` **отсутствует в последнем подтверждённом снимке**.
- `b_user.LOGIN_ATTEMPTS` nullable int(18) подтверждён, код отказывает при NULL.
- Текущий login mail template 95 / `EVRASIA_WEB_EMAIL_LOGIN` / site `s1` подтверждён, не проверять повторно ради DDL.
- Сайт работает с trust → native TOTP → verified email OTP → ручной SMS fallback; V5 AUTH в production OFF.
- Global email cap 1000/час удалён из production SOURCE guarded deployment (оператор PASS); индивидуальные Redis ограничения 60с и 5/час сохранены. Runtime PHP-FPM OPcache не подтверждён; это отдельный вопрос от SQL миграции.
- **Никаких disposable/isolated DB, MariaDB CI, synthetic accounts**.

## Зачем нужна ровно эта таблица

Мобильному приложению недостаточно Bitrix PHP-сессии сайта. Должен быть устойчивый `device_binding` + `AUTH_EPOCH` + `email_stamp` + одноразовый `code_hmac` и `status` для challenge. Существующий `ev_v5_auth_challenges` обслуживает SMS/TOTP с иным статусным/доставочным контрактом; автоматически считать его совместимым нельзя.

Предлагаемые колонки (всего **11**): `challenge_id`, `user_id`, `device_binding`, `platform`, `status`, `email_stamp`, `auth_epoch`, `code_hmac`, `issued_at`, `expires_at`, `failed_attempts`.

- `challenge_id` PRIMARY KEY; индекс `(user_id, status, expires_at)` обслуживает точечное ограничение pending challenges.
- Поля хранят HMAC/хэши и числовые состояния; email/OTP/пароль/сырое device ID не сохраняются.
- Issuer: `pending → accepted` только после положительного результата enqueue; verification: `accepted → consumed` со стиранием `code_hmac`; неправильные попытки ограничены 3, ошибка может перевести запись в `cancelled`.
- TTL не более 300 секунд, binding текущего пользователя, устройства и версии почты проверяется заново перед JWT.
- SQL candidate **не содержит** UPDATE/DELETE/ALTER существующих таблиц, foreign key на Bitrix и новой системы rate-limit.
- Pure-PHP schema contract gate: `tests/V5EmailLedgerSchemaContractTest.php`, без БД и пользователей.

## План после отдельного одобрения (не запускался)

1. **Guard/read-only:** `hostname -s=evrasia`, root, единственный DB `eurasia_new`, актуальный разрешённый Git blob SQL, V5 AUTH **OFF**, exact schema name, отсутствие целевой таблицы, наличие/engine ровно трёх parent таблиц; при несоответствии abort BEFORE WRITE. Никогда не подменять настоящее окружение изолированной БД.
2. **Backup:** подтвердить актуальную консистентную backup-точку production и её пригодность к восстановлению, сделать отдельную приватную резервную копию `eurasia_new` с манифестом, контрольными суммами и защищёнными правами на `/root`; проверить что backup действительно читается. Никакие данные клиентов/дампы не публиковать в public GitHub.
3. **Один DDL:** только точный, предварительно сверенный `CREATE TABLE ev_v5_email_auth_challenges` из pinned SQL. Помнить, что MariaDB DDL обычно implicit COMMIT, **ROLLBACK транзакции не откатит CREATE**.
4. **Post-DDL:** через `information_schema` сверить engine InnoDB, **все 11** колонок/типы/nullability, PRIMARY и составной индекс; убедиться, что таблица пустая, и приложение/website/V4 не затронуты.
5. **Failure/rollback:** не делать слепой `DROP`. Немедленный rollback возможен **только если** создана точно эта таблица данным запуском, V5 AUTH по-прежнему OFF, подтверждённо **ноль строк**, fingerprint схемы совпадает и оператор отдельно разрешил удаление; иначе остановка + ручной rollback по приватному плану/backup. Никакого `rm -rf`, `TRUNCATE`, `docker compose down -v`.
6. **Не активировать AUTH:** создание таблицы — только SQL prerequisite. Ещё нужны attest реальных Bitrix PDO ownership/transaction continuity, native hooks `AUTH_EPOCH`, trust/TOTP/current email proof, Redis и private V5 site factory. Реальные email OTP/сессии — позже, только с явным разрешением и реальным согласованным аккаунтом.
7. **Post-change:** зафиксировать только результаты проверки, SQL fingerprint, backup location (без секретов), `FINAL_STATUS/FINAL_RC` в handoff; Restis commits сохраняются.

## Стоп-факторы

Отказ владельца, недоступный или неверифицированный backup, уже существующая целевая таблица с другой структурой, несоответствие живого окружения, permission/syntax failures, неожиданный иной HEAD/SQL blob, неустановленные native security hooks при попытке включить V5, неопределённый rollback — **не выполнять DDL / не включать V5**.

**Ожидаемое отдельное решение владельца:** «Разрешаю создать `ev_v5_email_auth_challenges` только в существующей `eurasia_new` по согласованному плану с backup и проверкой; V5 AUTH оставить OFF». До него **MODE=SOURCE_ONLY**.
