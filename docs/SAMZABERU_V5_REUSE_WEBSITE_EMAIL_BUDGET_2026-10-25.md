# API V5 / «СамЗаберу» — переиспользовать веб-лимиты email, а не писать новые (25.10.2026)

## Новое решение владельца 25.10.2026 — общий email cap ОТМЕНЁН в исходниках

**Подтверждение установки на production (вывод оператора, 25.10.2026):** guarded single-file deployment на `evrasia` завершился `FINAL_STATUS=PASS`, `FINAL_RC=0`. Установленный `EmailSendBudget.php` теперь точно соответствует Git blob `cc6c1b775f584eb84b7811c7fb5ba204ba3be2aa` (до установки `b20486bf4b99318cfd0ad22a6fe2db2e0cc09f01`), PHP lint и сохранение metadata PASS, приватная резервная копия проверена. `GLOBAL_EMAIL_BLOCKING_CAP=REMOVED_FROM_SOURCE`, `PER_USER_EMAIL_PROTECTION=PRESERVED`, `GLOBAL_REDIS_COUNTER=NONBLOCKING_TELEMETRY`. **PHP-FPM OPcache runtime НЕ проверен** (`PHP_FPM_OPCACHE_RUNTIME_VERIFIED=NO`): не приписывать исполнение новым FPM-процессам без дополнительного read-only evidence. Ни одна БД, Redis key, API V4, SMS, website login business flow и V5 AUTH не менялись в ходе этой установки. Исторические фразы ниже о том, что production ещё не обновлён, отменены этим результатом.


После предыдущего checkpoint владелец подтвердил: **убрать общий блокирующий лимит 1000 email/час**. Общие блокирующие ограничения email/SMS не возвращать до накопления реальной статистики и отдельного решения. Сохраняются индивидуальные защиты email: cooldown **60 секунд на пользователя**, **5 писем/час на пользователя**, верификация факторов, TTL OTP и защита от повторной отправки. Не путать отсутствие общего cap с отсутствием антиспам-защит.

В feature-исходнике `deployment/website-mfa/EmailSendBudget.php` больше **нет сравнения общего счётчика Redis с квотой**. Существующий hourly global key оставлен **только для неблокирующего учёта успешных резервирований** (не означает SMTP delivery); ошибка агрегирующего счётчика не должна приводить к запрету отправки. В `V5ExistingWebsiteEmailBudget` сохранено переиспользование **того же** website budget/Redis, без новой таблицы или системы лимитов. Source-only тесты проверяют отсутствие глобального блокирующего условия.

**Граница:** это изменения GitHub feature branch, НЕ доказательство установленной версии на `evrasia`. Живой website `EmailSendBudget.php` пока не сверен с новым исходником и **production не менялся этими GitHub-коммитами**. Перед изменением работающего сайта обязательны узкая read-only source/hash проверка, backup, PHP lint, обратимый точечный patch и проверка. V5 AUTH по-прежнему OFF. После накопления статистики отдельно рассмотреть реальные отправки/доставки email и SMS (средние, пики, отказы, провайдерские расходы), а не подменять их числом Redis reservations.

Ниже записано **историческое состояние до этого решения**; его потолок `1000/час` НЕ является актуально утверждённой политикой.



## Решение и граница ответственности

Владелец остановил создание второго механизма лимитов: **вчера уже построен рабочий login сайта с доверенным устройством → TOTP → email OTP → ручной резервный SMS**. Для V5 не требуется новый алгоритм выдачи OTP, отправки email/SMS или ещё одна система ограничений. Но **сессионный PHP storage** веб-сайта не является переносимым доказательством для независимой мобильной V5 JWT-сессии. Для V5 остаются необходимыми device-bound, AUTH_EPOCH-bound, одноразовый challenge ledger и нативная серверная проверка перед JWT. Нельзя просто вызывать веб-AJAX, считать cookie V5 JWT или дублировать пользовательские лимиты.

### Исторический GitHub бюджет до отмены общего лимита

Проверен именно GitHub исходник `deployment/website-mfa/EmailSendBudget.php`: atomic Redis LUA, отдельный per-account cooldown **60 секунд**, **5 писем/час на пользователя**, и **общий лимит 1000 писем/час**. Namespace Redis `evrasia:{web_mfa_email_login}:v1:`. Это **GitHub source**, не подтверждение текущего установленного production-слоя; фактический Redis URI и версия сервиса требуют аттестации. Не добавлять новых общих потолков в V5. **Важно:** подключение V5 к действующему общему потолку 1000/час может лишить входа гостей обоих каналов при общей нагрузке. До одобрения владельца и оценки количества отправок публичная активация V5 запрещена.

### Исправления feature-ветки

- [`19be84c2`](https://github.com/juvantusik/Evrasia_AI_bot/commit/19be84c25052462ad9e42d055b2b867400c7a3b0): `src/V5ExistingWebsiteEmailBudget.php` — только узкий **вызов уже существующего** `EmailSendBudget::reserve()` через тот же предоставленный сервером Redis; **никакого нового rate-алгоритма, Redis подключения, SQL rate table или инфраструктуры**. Проверяет текущий V5-owned PDO, server-proven recipient context и installation ID до вызова сайта. Недоступный Redis, исчерпание старого лимита или ошибка => FAIL CLOSED, без SMS fallback.
- [`99adea9d`](https://github.com/juvantusik/Evrasia_AI_bot/commit/99adea9d3357e22705c219f3f0025400cd681fa0): pure-PHP regression `V5ExistingWebsiteEmailBudgetTest.php`, без Redis connection, DB connection, пользователей и письма. Тест сверяет именно сайт Lua/Redis prefix + реальный глобальный cap; он не утверждает, что общий cap отключён.
- **Снята параллельная SQL rate-система** (всё только из feature GitHub): [`3db26193`](https://github.com/juvantusik/Evrasia_AI_bot/commit/3db26193064592a39e0c62e0b22b9ba05d016c8f) убрал `ev_v5_email_rate_windows` из native SQL allowlist; [`0a888ea9`](https://github.com/juvantusik/Evrasia_AI_bot/commit/0a888ea9bc225545c92b23a738e851d33966bcf3), [`866753c4`](https://github.com/juvantusik/Evrasia_AI_bot/commit/866753c488c75d938508fb7ff4b16bb125772617), [`37e648ee`](https://github.com/juvantusik/Evrasia_AI_bot/commit/37e648eef2e213e4fdea9b59f530a0a3685c1aff) удалили V5 отдельный rate class, его unit test и отдельную SQL-таблицу; существующие сайт/Redis/schema `eurasia_new` не менялись.
- [`96dcfb65`](https://github.com/juvantusik/Evrasia_AI_bot/commit/96dcfb65309a84a903c12682fa9eb1e267d7907d) CI теперь проверяет только новый boundary к исходному `EmailSendBudget` + предыдущие security gate tests, без создания базы и synthetic users.

[GitHub Actions #40177880562](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40177880562): **completed SUCCESS**: `PASS: V5 reuse existing website email Redis budget (17 assertions)`, старые SQL/email-native/website-template/first-factor PHP тесты PASS, `PHP_LINT=PASS`, `FIRST_FACTOR_PUBLIC_GATES=PASS`. Лог доказывает только код, а не реальный Bitrix/Redis E2E.

### Важное различие атомарности

`EmailSendBudget::reserve` атомарен **в Redis Lua**, но НЕ является частью коммита `eurasia_new`. V5-эмитент сначала подтверждает пароль/почту и блокирует аккаунт в SQL, затем использует существующий Redis quota внутри собственного SQL scope; если последующий SQL INSERT/COMMIT не удался, Redis cooldown **останется потраченным**, хотя письмо не отправлено. Это безопасная закрытая ошибка, но **операционно неприятный эффект**, который нужно подтвердить на настоящем Bitrix до подключения. Не заявлять Redis+SQL «единой транзакцией». Запрещено использовать отдельный Redis сервер или новый namespace, если требуется соблюсти общие веб-лимиты.

### Остаточные блокеры производства

1. Нет `ev_v5_email_auth_challenges` в существующей `eurasia_new` (schema-only real audit); требуется одобренная миграция именно этой таблицы **в той же базе**; никаких isolated DB.
2. Установленную на сайте версию `EmailSendBudget` и подключение к тому же Redis ещё нужно узко source/hash-верифицировать. Ранее существовавший общий лимит 1000/час теперь отменён решением владельца **в GitHub source**, но не подтверждён как снятый на production. Сайт изменять только после точной проверки живого файла, резервной копии и отдельного guarded deployment.
3. `V5ExistingWebsiteEmailBudget` **ещё не включён в production composition**; необходимы V5 server bootstrap, locked-native Bitrix connection and callback ownership, настоящий challenge route/receipt, strong JWT grant attestation и проверка реальным пользователем (без synthetic аккаунтов).
4. Public `/api/v5` по-прежнему OFF/HTTP 503; V4, сайт, бонусы/Restis не изменялись.

`NEXT=PIN_REAL_SITE_REDIS_AND_VALIDATE_V5_SHARED_EMAIL_BUDGET_WITHOUT_WRITES`. Затем завершить native composition и собственно V5 API, не строить очередной отдельный сервис лимитов.
