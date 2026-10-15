# Evrasia AI Bot / СамЗаберу V5 — START HERE / 15 октября 2026

**Главная точка продолжения. Новый чат — не новый проект.** Читать этот файл, текущий `SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md` и `SAMZABERU_V5_INTEGRATION_DECISIONS_2026-10-15.md`. Полные предыдущие редакции сохранены в Git, в том числе commit `91b7f9d5ce73aa672344d81c95b6427053735df8`.

## 1. Сначала учесть НОВОЕ подтверждённое решение пользователя

**Отдельной VM / нового сервера не будет. V5 делаем ПАРАЛЛЕЛЬНО V4 на существующем Bitrix-сервере `evrasia`.** Контейнерная копия всего сайта, второй MySQL instance и второй набор клиентов не являются обязательными условиями. Предыдущие требования выделить отдельную инфраструктуру отменены как обязательная архитектура. Не просить адрес новой VM и не блокировать feature-разработку её отсутствием.

Цель: `/api/v4/…` продолжает обслуживать существующее приложение; `/api/v5/…` подключается рядом отдельными обработчиками. Аккаунты Bitrix и бизнес-данные общие; V5 использует свои ключи, токены, служебные таблицы, конфигурацию и выключатель. Существующий бот на `eur-bot-01` не переносим. Физическое размещение V5-таблиц в существующей БД допустимо; это НЕ уже выполненная миграция.

Общая БД означает общие security events: password/MFA/phone/block/logout через сайт/V4 должны корректно инвалидировать V5. Эти проверки не отменены. Решение о размещении НЕ разрешает произвольные production DDL/сбросы/реальные SMS или эксперименты со сбоями. Для конкретных серверных изменений нужны точная цель, backup, разрешение, post-check и rollback.

Issue #76 теперь называется **«V5: параллельное подключение рядом с V4 на существующем Bitrix»**. Это задача подключения, а не выдачи VM. Старый provisioning plan помечен как заменённый. CI-only и fault-injection scripts по-прежнему нельзя запускать на production; их host/DB guards не отключались.

## 2. Актуальный код и CI

Repo: `juvantusik/Evrasia_AI_bot`. Рабочая ветка: **`feature/samzaberu-api-v5-auth-contract`**.

**Последний code/test commit с обеими успешными CI: `029aaff9115d39d6a489457867fbbe532434303d`.**

- Offline Actions **38748326281**, job **116467747751**: SUCCESS, **26 approved suites**, lint **83 PHP files**. В существующий RouteMap suite включены **198 новых assertions** `V5ParallelHttpEntryTest.php`. Лог реально прочитан; FINAL_STATUS=PASS, FINAL_RC=0.
- Реальная одноразовая MariaDB/InnoDB Actions **38748326331**, job **116467747777**: SUCCESS, **43 sessions + 117 SMS + 45 receipt + 63 bridge + 61 locked-password = 329 assertions**. Лог реально прочитан; FINAL_STATUS=PASS, FINAL_RC=0. Новые 198 assertions относятся к offline, НЕ к MariaDB; не складывать их в число DB-проверок.
- Последующие HEAD могут содержать только документацию. Сверять live ref и diff относительно `029aaff`; doc-only HEAD не означает отдельный кодовый прогон.
- Main при последнем live read: `7c2e9f8e4501b92469bcdeea18f8340676ad52c9`, не изменялся. Push main запускает build/publish latest без paths-фильтра, поэтому docs туда автоматически не переносить.

### Последний инкремент: настоящий PHP entry вместо постоянной заглушки

`site-integration/samzaberu-v5/api/v5/router.php` теперь вызывает `src/V5ParallelHttpEntry.php` и существующие `V5PhpHttpRequestFactory → V5HttpTransportBoundary → V5AuthHttpHandler`. Отдельный Bramus-router внутри заглушки больше не требуется; пять контрактных V5 путей не изменены. V4 router/bootstrap, общие rewrite rules и V4 auth cookies не подключаются.

Для включения нужны одновременно server-side `EVRASIA_SAMZABERU_V5_ROUTER_READY === true` и Closure в `$GLOBALS['EVRASIA_SAMZABERU_V5_HTTP_FACTORY']`, возвращающая существующий transport с настоящими проверенными providers. Это private PHP configuration, НЕ HTTP input. Factory должна быть ленивой: сам её registration не запускает Bitrix. Config/пилотный ACL/native factory пока НЕ установлены на сайте. Флаг не заменяет native proof/coverage.

При выключении/отсутствии factory — 503 до чтения тела или вызова factory. При включённой конфигурации до factory проверяются точный V5 путь, HTTPS, POST, Content-Type, Content-Length, лимит 8192 байта и JSON-object. Чтение php://input ограничено 8193 байтами для обнаружения переполнения. V4/похожие/encoded/query/direct-router пути не перенаправляются в legacy API. Ответы no-store; ошибки не раскрывают исключения; PHP emitter подавляет обычный случайный вывод factory и Set-Cookie/Location в V5-ответе. Factory остаётся доверенным проверяемым серверным кодом, не sandbox произвольного PHP.

198 новых тестов подтверждают default-off, отсутствие body I/O/native factory при раннем отказе, точные пять путей, HTTPS/proxy-header denials, методы/JSON/размеры, обработку ошибок, прохождение до существующего login handler с корректным отказом 401. Три дочерних PHP процесса исполняют реальный router и подтверждают default-off даже при переданных клиентом флагах или одном ready constant. Test double всегда отвергает пароль; авторизацию он не обходит и токены не выпускает.

**Это feature-код, НЕ live deployment и НЕ проверка Apache/Nginx coexistence.** На сайте этот entry не установлен; HTTP503 остаётся последним историческим baseline, свежего HTTP-запроса не было.

## 3. Принятая бизнес-логика — сохраняется

1. Логин/пароль без CUser::Login, V4-cookie или PHP login; текущие active/block/expiry/Anti-Fraud и лимиты попыток.
2. Native TOTP имеет приоритет; при ошибке/неизвестном состоянии fallback на SMS запрещён.
3. Без TOTP вход без SMS разрешает только сильное V5 trusted device, не слабая V4 trust.
4. Без TOTP/strong trust — password-bound SMS на подтверждённый телефон аккаунта, не номер с клиента. После сильного входа PIN без повторной SMS.
5. SMS: десять rate/стоимостных окон, cooldown/лимиты ошибок, HMAC кода и encrypted outbox, identity/device/phone/epoch binding, тариф/currency/idempotency. Claimed/unknown автоматически не отправлять повторно. Independent SENT receipt обязателен; late receipt не оживляет challenge и не меняет бюджет.
6. PHONE_EVENT_VERSION изменяется и при повторном подтверждении того же E.164. AUTH_EPOCH атомарен с native security mutations; старые сессии/доказательства недействительны.
7. V4/UI/названия/API-контракты не перепроектировать.

## 4. Уже реализовано / что остаётся

Уже есть workflow, JWT/opaque refresh, транзакционные V5 strong sessions/CAS/revocation, password-bound TOTP begin, одноразовое challenge consumption, consumed-grant proof, encrypted SMS outbox/budgets/one-paid-claim/receipt reconciliation, strict HTTP transport, PIN session guard и Bitrix bridge. `V5BitrixLockedPasswordTotpProof` реализует fresh locked password/MFA-state recheck и использует существующий `V5SecurityEventVersions` stamp, а не новую epoch-систему.

**Не завершены:** реальная V5-only site composition/configuration и отдельное ограничение пилота; native TOTP-code/replay и полное security event coverage; фактические phone verification/reconfirmation writers; SMS password-bound begin/resend и реальный paid provider/receipt/tariff contract; RestIS PIN reader; approved additive schema installation; live V4/V5 coexistence acceptance.

`V5BitrixLockedTotpFactor` из `SAMZABERU_V5_TOTP_VERIFICATION_PENDING_2026-10-15.md` — только неподтверждённый черновик, отсутствует в src рабочей ветки. Предыдущая заблокированная загрузка теста не повторялась. В текущем инкременте этот черновик не включался и не приписывался к CI. Доработанный locked-password BEGIN не равен готовой проверке TOTP-кода.

## 5. Ближайший шаг без возврата к созданию VM

Подготовить V5-only site bootstrap/composition на существующем Bitrix и точную схему безопасной установки private package + public V5 shim, без загрузки V4 auth bootstrap. Контракт подключения описан в `site-integration/samzaberu-v5/api/v5/README.md`. Native services остаются обязательными, не подставлять permissive callback=true. Перед установкой — только целевой preflight конкретных файлов/версии PHP/rewrite; завершённые CUser/PhoneAuth аудиты не повторять.

Далее отдельным согласованным изменением — additive V5-таблицы в существующей БД, с backup/verify/rollback; существующие account/V4 таблицы не пересоздавать. Контролируемая приёмка на явно разрешённых тестовых аккаунтах; не запускать CI CREATE/ALTER fixtures, fault injection, массовые resets или платные SMS на реальных клиентах. Сначала сохранить V4, затем отдельно включить пилот V5. Shared-host безопасные native проверки требуют подходящего проверенного permit, а не удаления запретов из старых staging/fault scripts.

## 6. Контекст, который НЕ изучать заново

Bitrix host `evrasia`, docroot `/home/site_evrasia/web/evrasia.spb.ru/public_html`; бот отдельно на `eur-bot-01`. Полные source-only findings: `EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-14.md`, `SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md`, `STRUCTURE_REGISTRY.md`, phone-native-writer и MFA-recovery decision документы от 13 октября. Это архив доказательств, не актуальный список NEXT.

CUser phone delete/add НЕ доказаны как successful CONFIRMED writer; same-number event остаётся реальным требованием. Известный SHA-drift user.php не объяснён, автоматически baseline не утверждать и файл не исправлять. Нативные recovery/useCode/save/delete/background mutations не объявлять атомарными только по return true.

История code checkpoints: `c3f92ba` (329 DB), `8c395f0` (268), `dc2caed` (205), `4a6ea09` (160). Старый receipt run38736335930 был FAIL из-за fixture ordering, исправленный run сохранён. Порядок и SHA подробностей доступны в предыдущем checkpoint и Git history.

**Статусы:** OFFLINE_TESTED=PASS; REAL_EPHEMERAL_MARIADB_PASS=YES(329); PARALLEL_ENTRY_OFFLINE_TESTED=YES(198); REAL_BITRIX_STAGING_TESTED=NO; REAL_SITE_PARALLEL_ACCEPTANCE=NOT_RUN; PRODUCTION_DEPLOYED=NO. Production/V4/SQL/SMS не изменялись этой итерацией.

Для серверных операций — один copy-paste Bash wrapper, syntax check, exact host/path/DB/backup guards, структурированный FINAL_STATUS/FINAL_RC, без secrets/PII, терминал не закрывать. Безопасную feature/CI работу продолжать самостоятельно. Приоритет: production fact > live feature > реальные испытания > актуальная документация > старые обсуждения.
