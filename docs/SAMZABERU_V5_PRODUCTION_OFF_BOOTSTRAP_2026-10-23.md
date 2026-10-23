# СамЗаберу API V5 — production OFF bootstrap: подтверждённый checkpoint 2026-10-23

**Источник истины:** фактический вывод оператора с сервера `evrasia` от 23 октября 2026. Это запись факта установки загрузчика, **не акт допуска V5 авторизации**. Отдельного тестового сервера/Bitrix staging нет; его существование нельзя предполагать.

## 1. Фактически выполнено на production

- Публичный `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5/router.php` (исходный SHA256 `770fbc6e4e52f7374ef8209e4f0b91919a9cab976266c4f3ce0764dcedcab673`) раньше напрямую подключал внутренний `local/samzaberu-v5/api/v5/router.php`. В нём не было явных регистраций фабрики V5.
- Без изменения внутреннего router (SHA256 `6e534f93bcf873eda337439443cb3670f634f0afc1bfe9938e8acc4cef5ae37d`) установлен **только** двухфайловый исправленный загрузчик: публичный router теперь сначала загружает `local/samzaberu-v5/api/v5/site.php`; тому передаётся фиксированный physical document root; далее site.php загружает существующий внутренний router.
- `site.php` теперь направлен на ожидаемый приватный каталог `dirname(documentRoot)/private/samzaberu-v5` **вне public_html**. Файлы `site-config.json` и `site-factory.php` не создавались и не устанавливались. Защитный `V5PrivatePilotConfig` продолжает отвергать отсутствие/небезопасное размещение приватной конфигурации.
- Кандидат: `/root/ev-v5-checkpoints/v5-bootstrap-corrected-20261023.Vj1s42V9`; установка и проверенные резервные копии: `/root/ev-v5-checkpoints/v5-off-deployed-20261023.npcVIr3E`. Итог оператора: `DEPLOY_STATUS=PASS`, `ALREADY_APPLIED=NO`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `ROLLBACK_STATUS=NOT_NEEDED`.
- CLI smoke после установки: `/api/v5/health` возвращал `auth_ready:false`; `/api/v5/auth/signin` возвращал `v5_unavailable`. **Сетевой HTTPS smoke тогда не выполнялся:** `HTTP_NETWORK_SMOKE=NOT_PERFORMED`.
- V4/production DB не изменялись, SMS не отправлялись, регистраций не было, JWT не выдавались, V5 AUTH **OFF**. Шесть ранее существовавших V5 таблиц нельзя создавать повторно.

## 2. Отдельная диагностическая ветка password / AUTH_EPOCH

- Основной локальный hardened-adapter `/root/ev-v5-checkpoints/password-boundary-hardened-20261020-f1989b9aaefc/V5HardenedPasswordEpochTransaction.php`, SHA256 `cffe143cbb095892cc71231579ce654b1b8b8bf09d2e38423c86a1b3b3849a4e`.
- V3 negative case `V5HardenedPasswordEpochTransaction.missing_state.php`, SHA256 `cfbd18e69d0790d0ff9aff2ea8ff3bfb60f882729481c79571a548ac34e2999e`, имел корректный pinned H_DEPTH SHA, но вспомогательный файл под `/root` был недоступен CLI-пользователю UID 65534 (обычно nobody). Это **Unix UID тестового процесса**, не Bitrix USER_ID и не учётная запись клиента.
- Сформирован приватный кандидат, переключающий только путь H_DEPTH на доступную UID65534 *SHA-identical* копию; SHA256 кандидата `c9056ad8dd2a412d587a2477d03b145e7e71ba29d1a4479d0950d0bc08281645`, checkpoint `/root/ev-v5-checkpoints/v5-depth-access-repair-20261023.Iu6MGLrF`. Совпадает с ранее сохранённым исправленным вариантом 22 октября: не дублировать исправление.
- Сохранённые отчёты `V3_FAILURE_STAGE=COMMIT_ASSERT`, `V3_EXCEPTION_CLASS=RuntimeException`; **это не доказательство ошибки самого hCommit()**, и успешный helper path/lint не доказывает atomic native COMMIT. Нельзя объявлять E2E PASS или выполнять транзакционный тест на живых аккаунтах без отдельного безопасного допуска/backup.

## 3. Что ещё не принято

- `V5TrustedInstalledNativeCoveragePort` не имеет подтверждённой production-реализации и фактического 10-path native `AUTH_EPOCH` coverage attestation.
- `V5PilotScopedNativePorts` и `V5MySqlPilotComposition` содержат orchestration, но **не заменяют** настоящие native trust, Bitrix TOTP consume, bonus PIN reader, strong session/epoch и SMS proof.
- V5 SMS begin/resend и статус отправки реальным оператором не приняты. Не посылать пробные платные SMS.
- Нет отдельного изолированного тестового сервера. Не выдавать synthetic/offline/CLI-проверки за реальный positive Bitrix acceptance.

## 4. Следующие действия

1. Однократный **HTTPS network smoke** независимых публичных `GET /api/v5/health` и `GET /api/v5/legal/consents` с проверкой статус-кодов/документов без публикации body/секретов. Это проверяет фактический web route, ранее проверенный только через CLI.
2. Продолжать V5-first native provider implementation по согласованному contract, начиная с фактической доказательной границы 10 native mutation paths и locked strong-session proofs. Не создавать «пустую» фабрику и не ослаблять release gate.
3. Только после доказанных real native hook/transaction/sms-safe guards — приватная pilot factory с ограничением user IDs/IP, отдельный rollback и контролируемое приёмочное тестирование.
4. До допуска — `V5_AUTH=OFF`, V4 работает без изменений.

**Правило:** никаких новых утверждений о production готовности по наличию файлов, PHP lint, статическому осмотру либо CLI fake HTTPS.

## 5. 2026-10-23 — REAL HTTPS network smoke on evrasia.spb.ru PASS

Оператор выполнил `curl` через реальный HTTPS (не PHP CLI), сохранил JSON-ответы локально на время пробы, проверил итоговые статусы. `FINAL_STATUS=PASS`, `FINAL_RC=0`, без SQL/изменений кода/SMS.

- `GET https://evrasia.spb.ru/api/v5/health`: `HEALTH_HTTP_STATUS=200`, `HEALTH_STATUS=online`, `AUTH_READY=false`.
- `GET https://evrasia.spb.ru/api/v5/legal/consents`: `LEGAL_HTTP_STATUS=200`, `LEGAL_DOCUMENT_COUNT=3`, `LEGAL_TYPES=offer,personal_data,marketing`, `LEGAL_RESPONSE=DOCUMENTS`.
- `PRODUCTION PHP sources unchanged=YES`. Рабочий V4, БД, SMS не затронуты. **AUTH остаётся OFF**.
- **Не проверено:** `https://evrasia.rest/api/v5/...`. Исторический контракт мобильных разработчиков использовал `evrasia.rest`; без отдельной проверки второго хоста нельзя утверждать, что публичные V5-методы доступны с того же API origin. Не менять host в задании разработчикам автоматически. Другие методы `POST /api/v5/auth/*` не приняты.

**Новый next step:** один точечный read-only HTTPS cross-host probe для `evrasia.rest` с выводом только статуса и формата ответа (без следования редиректам и вывода PII), затем продолжить 10-path native hooks AUTH_EPOCH и настоящие провайдеры V5. Никаких повторных больших файловых инвентаризаций.

## 6. 2026-10-23 — evrasia.rest connectivity probe is inconclusive, NOT an HTTP 404

Оператор запустил HTTPS `curl --noproxy '*'` непосредственно на production-хосте `evrasia`:

- `GET https://evrasia.rest/api/v5/health`: `HEALTH_HTTP_STATUS=000`.
- `GET https://evrasia.rest/api/v5/legal/consents`: `LEGAL_HTTP_STATUS=000`.
- Тела ответов не получены, реальные curl exit codes и stderr в исходном сценарии были подавлены `|| true` и `2>/dev/null`. **Нельзя утверждать**, что это HTTP 404, отсутствие V5 route или ошибка приложения: первопричина DNS/сети/TLS/прокси пока неизвестна. `evrasia.spb.ru` ранее успешно отдавал оба GET через HTTPS.
- Скрипт выводил `FINAL_STATUS=PASS` как формальный статус выполнения, хотя доступность `evrasia.rest` не подтверждена. Ошибка удаления временного каталога объясняется оставленными `health.status` и `legal.status`; она не затронула сайт.
- Next: **одна короткая сетевая диагностика** с показом `curl exit code`, факта DNS-резолва и HTTP-статуса в прямом и обычном режимах; без токенов, SMS, обновления V4/V5/БД. Не выполнять deployment/V5 DNS redirect/маршрутизацию до фактического диагноза.
