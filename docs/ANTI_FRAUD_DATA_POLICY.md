<!-- Обновлено 08.09.2026 ИТ Директор Евразии -->
# Anti-Fraud — правила хранения и минимизации данных

## Назначение

Модуль Anti-Fraud хранит только данные, необходимые для объяснимого анализа устройств, посещений, связей между аккаунтами, текущего статуса Bitrix и внутреннего аудита ручных операторских действий.

Risk scoring остаётся рекомендательным: **автоматическая блокировка по risk score не выполняется**. Блокировка/разблокировка возможна только как явное ручное действие оператора через утверждённый Anti-Fraud workflow.

## RestIS и карты лояльности

- `CARD_NO` RestIS является техническим идентификатором карты лояльности, а не номером банковской карты.
- Raw `CARD_NO` хранится только там, где он необходим для сервисного сопоставления; он запрещён в операторском UI, публичных API-ответах и application-логах Anti-Fraud.
- История посещений использует внутреннюю связь с картой и не должна дублировать raw `CARD_NO` в объяснениях риска.
- `anti_fraud_cards.bitrix_card_status_id` хранит raw ID статуса карты из Bitrix card-map; это не состояние RestIS и не должно интерпретироваться как RestIS enum.

### Текущий поток и адресная история

- `VIP_TODAY` используется как текущий инкрементальный поток посещений.
- Массовый исторический backfill по всей базе карт запрещён.
- `VIP_HISTORY` разрешается только адресно после Anti-Fraud history gate.
- Для истории выбираются только карты нужного Bitrix `USER_ID`, допустимые текущим loyalty contract.
- Неактивные карты не должны участвовать в Visit Behavior Risk.
- Целевое окно исторического анализа — последние 60 дней.
- Повторный запрос истории ограничивается metadata-полями покрытия; свежая выборка не должна бесконтрольно загружаться повторно.
- Если полнота ответа не доказана, enrichment не должен маркироваться как полностью успешное покрытие.
- Бот-контейнер **не содержит RestIS credentials**. Доступ к loyalty/history выполняется через защищённый site-side API. Не копировать RestIS credentials в `evrasia-ai-bot-app`.

## Bitrix-аккаунты и PII

- `phone_normalized`, `email_normalized` и `display_name` являются персональными данными и используются только внутри Anti-Fraud для анализа/отображения уполномоченному сотруднику.
- Эти поля не должны попадать в Telegram-логи, технические ошибки, метрики и общие журналы приложения.
- Связь аккаунта с одной или несколькими картами определяется через текущую Anti-Fraud модель карт; текущий номер карты не должен размножаться по API/UI.
- Значения телефона, email и имени запрещено включать в тексты ошибок collector/gateway и технические логи.
- Collector не удаляет локальный аккаунт при временном `unresolved`; такие случаи фиксируются как состояние синхронизации, а не повод молча удалять данные.

### Адресный Bitrix account-map

- `account-map` использует явный набор Bitrix `USER_ID` и не предназначен как произвольная массовая выгрузка всей пользовательской базы.
- Collector формирует список из аккаунтов, которые уже нужны Anti-Fraud по картам/устройствам/сохранённым аккаунтам.
- Запрошенные ID должны быть фактически классифицированы источником как resolved/unresolved по текущему contract.
- Текущий production collector сохраняет необходимые Anti-Fraud поля аккаунта, включая Bitrix state:
  - `bitrix_active`
  - `bitrix_blocked`
  - `bitrix_block_reason`
  - необходимые PII/display поля для операторского расследования.
- Bitrix остаётся source of truth; refresh должен перечитывать актуальный factual state.

## Bitrix state / ручная блокировка

Текущий статус определяется так:

- `ACTIVE=Y`, `BLOCKED=N` → `Активен`
- `ACTIVE=N`, `BLOCKED=N` → `Неактивен`
- любой `BLOCKED=Y` → `Заблокирован`.

Важно:

- только `BLOCKED=Y` является истинной блокировкой Bitrix;
- `ACTIVE=N`, `BLOCKED=N` не переписывается локально как блокировка и сохраняет отдельный статус `Неактивен`;
- operational UI при этом исключает и заблокированные, и неактивные аккаунты из обычных списков/KPI по умолчанию;
- группа может по-прежнему использовать такие аккаунты в aggregate evidence/group bonus, чтобы не терять фактический состав расследования.

Ручная блокировка:

- выполняется только по явному операторскому действию;
- пишет `ACTIVE=N`, `BLOCKED=Y` через защищённый Bitrix endpoint;
- использует фиксированное публичное основание, а не произвольный клиентский текст;
- после изменения factual state перечитывается и проверяется;
- повторная блокировка уже заблокированного аккаунта идемпотентна и не должна стирать/заменять внешнее основание.

Ручная разблокировка:

- переводит blocked account в `ACTIVE=Y`, `BLOCKED=N`;
- повторный вызов для уже разблокированного аккаунта идемпотентен;
- историческое основание блокировки сохраняется, если отдельный утверждённый contract не требует иного.

Internal audit:

- block/unblock actions фиксируются в `anti_fraud_block_audit`;
- аудит может хранить внутренний source/case/account transition/result для расследования;
- customer-facing reason не должен содержать case ID, risk score, device IDs, similarity mechanics или иные detection internals;
- UI не должен выдумывать дату блокировки: `blockedAt` допустим только из authoritative app audit для текущего состояния.

## Trusted Device

- IP-адрес не хранится и не используется как фактор доверия или Anti-Fraud identity signal.
- `device_hash` используется как технический псевдоним устройства и должен соответствовать SHA-256 hex-формату `^[a-f0-9]{64}$`.
- `event_type`, `auth_method`, `client_type` и status текущей связи сохраняются как raw text; ingestion не должен самопроизвольно трактовать их как risk enum.
- Текущие user-device связи зеркалируются отдельно в `anti_fraud_device_links`, потому что поток событий может быть неполным и не является достаточным источником текущего snapshot состояния.
- `anti_fraud_device_links` синхронизируется полным snapshot с reconcile по source link identity.
- `anti_fraud_device_events` загружается инкрементально; конфликтующие дубликаты source event нельзя молча перезаписывать.
- Trusted Device export не передаёт телефон, email, имя пользователя, IP или trust token.
- Trusted Device — это app install/trust identity, а не IMEI/MAC/advertising ID/hardware identity.
- Для SamZaberu mobile внешний installation ID имеет контракт: literal `sz_` + 64 lowercase hex = 67 символов.
- Префикс `sz_` является namespace и не удаляется; mobile adapter вычисляет SHA-256 от **полного namespaced значения**, включая `sz_`, чтобы получить 64-hex core identity для общего `TrustedDeviceService`.
- Общий `TrustedDeviceService` сохраняет прежний 64-hex opaque-token contract; browser flow не меняется.
- Raw SamZaberu `device_id`, полный `DEVICE_ID_HASH` и trust token не должны печататься в routine diagnostics/logs.
- Logout не должен создавать новый device identity; reinstall может создать новый installation identity.
- Production E2E acceptance этого mobile flow зафиксирован в `docs/SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md`.

## Explainable Risk Scoring v1.7

- `anti_fraud_risk_scores` хранит последний рассчитанный score по Bitrix `USER_ID`.
- `anti_fraud_risk_reasons` хранит агрегированные reason codes/details без raw loyalty-card numbers и полного `device_hash`.
- Risk scoring не изменяет `Bitrix ACTIVE/BLOCKED` автоматически.
- Device/linked-account/identity/visit factors должны оставаться объяснимыми и соответствовать утверждённым правилам.
- Суточная частота посещений считается суммарно по всем ресторанам.
- History gate используется только для адресного 60-дневного enrichment.
- Базовые thresholds:
  - critical `>=75`
  - high `>=50`
  - medium `>=25`.
- Текущий bonus rule: баланс строго `>40000.00` даёт +50 и history gate; ровно `40000.00` не даёт этот trigger.
- Несколько активных loyalty cards сами по себе не добавляют автоматические risk points.
- Баланс аккаунта нельзя суммировать/умножать по числу активных карт.
- `0.00` — известный ноль; `NULL` — неизвестно/недоступно; отрицательный текущий баланс допустим.

## Identity Similarity / grouping

- Grouping evidence и risk evidence — разные понятия.
- Поведенческий риск сам по себе не должен объединять отдельные identity в один case.
- Similar phone/email может участвовать в identity links только по текущему corroboration contract.
- Candidate-index optimization используется только для сужения candidate pairs; финальный evaluator остаётся source of truth.
- Операторский UI должен локализовать технические reason-details и не показывать сырые generated keys вроде `max_devices_for_same_pair`, `matching_other_accounts`, `max_gap_days`, `similar_phone_links`, `similar_email_links`.

## Operational visibility / excluded states

После PR #41:

- blocked и inactive accounts скрыты из обычных operational views по умолчанию;
- общий toggle: `Показать заблокированных и неактивных`;
- KPI/shared-device/duplicate-contact operational summaries исключают оба состояния;
- inactive account остаётся visually `Неактивен`;
- group bulk block не должен автоматически захватывать inactive accounts;
- group bonus и evidence могут включать excluded accounts для сохранения фактического состава case.

Это правило влияет на отображение и operational workload, но **не переписывает factual Bitrix state**.

## Ссылочная целостность

- Внешние источники синхронизируются независимо и могут приходить в разном порядке; FK от внешнего Bitrix `USER_ID` на все Anti-Fraud таблицы не требуется автоматически.
- Master/card/visit relationships должны сохранять текущую referential integrity, утверждённую schema migrations.
- Миграции 0018/0019 добавили blocked-state fields и audit; текущий production migration count — 20.

## Доступ

- Anti-Fraud данные не являются частью публичного Phonebook или Telegram API.
- Anti-Fraud web UI доступен только разрешённым административным/операторским ролям.
- Service tokens хранятся в secret files/env boundary и не сохраняются в БД как открытый текст.
- Секреты запрещено печатать в диагностике/документации.

## Acceptance fixtures / production safety

- Безопасный тестовый аккаунт не обязан быть видимым в каждом UI path.
- Перед mutation-driven acceptance нужно доказать eligibility fixture для конкретного view/API.
- Нельзя выбирать реального клиента только для удобства скриншота/визуального acceptance.
- Нельзя фабриковать production risk/case data ради UI visibility без отдельного дизайна и явного approval.
- Если safe fixture не покрывает визуальный path, backend/API acceptance и UI observation разделяются честно; непроверенное визуальное состояние не выдаётся за проверенное.

## Retention

Сроки хранения PII, посещений, device events и audit data должны соответствовать утверждённой политике/правовым требованиям. Не добавлять новые бессрочные накопители PII/device telemetry без отдельного решения.

Backup/archival retention управляется отдельно от application data retention и не должен очищаться автоматически без явного операторского approval.
