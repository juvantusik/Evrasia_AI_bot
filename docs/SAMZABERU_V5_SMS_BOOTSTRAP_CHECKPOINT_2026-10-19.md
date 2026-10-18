# СамЗаберу V5 — SMS phone bootstrap: проверенная контрольная точка 2026-10-19

> **STATUS: INACTIVE FEATURE / LOCAL PRODUCTION-HOST LAB TESTS / NOT DEPLOYED AS V5 AUTH.** Это фиксация вывода фактически выполненных оператором скриптов 19.10.2026 на `evrasia`, а не утверждение о наличии этих локально установленных PHP/SQL-файлов в GitHub. **Не смешивать с параллельной веткой V5 signup/legal**: [handoff 2026-10-18](EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-18_V5_LEGAL_SIGNUP.md). Следующая точка по SMS — `IMPLEMENT_SAFE_BOOTSTRAP_SMS_ISSUANCE`.

## 1. Контекст, окружение и неизменные правила

- Репозиторий `juvantusik/Evrasia_AI_bot`, рабочая GitHub-ветка `feature/samzaberu-api-v5-auth-contract`; проверять live HEAD/CI перед новыми коммитами.
- Production-хост `evrasia`, Bitrix-сайт `/home/site_evrasia/web/evrasia.spb.ru/public_html`, БД `eurasia_new`. Локальные кандидатные компоненты: `local/samzaberu-v5/src`, SQL: `local/samzaberu-v5/schema` относительно корня сайта.
- V4 и VK-коды списания бонусов не трогать. **VK — не канал SMS для логина**. Обычные SMS V5 планируются через Stream Telecom (`gateway.api.sc`).
- V5 HTTP/signin не включён, native SMS send для bootstrap не реализован, реальные SMS и запросы провайдеру на этой стадии не выполнялись, миграции bootstrap в `eurasia_new` НЕ применены.
- Все реальные PHP+MariaDB тесты выполнялись на хосте production только с временной лабораторной схемой, ограниченным DB-пользователем и UTS-хостнеймом `ev-v5-staging-lab`. PHP production guards не удалялись, тестовые БД/пользователи удалены (`SCRATCH_SCHEMA_REMOVED=YES`, `TEMP_DB_USER_REMOVED=YES`). Это **не** реальная приёмка SMS-провайдера/Bitrix авторизации.
- Пилот UID `880339`: контроль `AUTH_EPOCH=1`, `PHONE_EVENT_VERSION=0`, `PHONE_BINDING_HMAC=NULL` до/после лабораторных тестов. Не раскрывать секреты, телефоны и SMS-коды.

## 2. Фактически прошедшие интеграционные испытания

1. **SMS-attestation → proof issuer → proof consumer**: `REAL_PHP_ATTESTATION=PASS`, `REAL_PHP_ISSUER=PASS`, `REAL_PHP_PROOF_CONSUMER=PASS`. Проверены неверный код и durable failed counter, пять неудач→cancelled, неверный user/phone, отсутствие `sent_attested`, истечение TTL, одноразовость, подтверждение/откат SQL, обнуление digest при consume. Итог `FINAL_STATUS=PASS FINAL_RC=0 WRAPPER_RC=0`.
2. **Привязка provider SMS ID к попытке**: `REAL_PHP_BINDING_TEST=PASS`, `ATOMICITY_TEST=PASS`, `PROVIDER_MESSAGE_REPLAY_PROTECTION=PASS`. Проверены уникальность SMS ID и attempt, запрет замены ID, wrong user/expired/verified/already-attested, rollback и запрет установки `sent_attested` при одной регистрации. Итог `FINAL_STATUS=PASS FINAL_RC=0 WRAPPER_RC=0`.
3. **Статусный attestor с имитатором провайдера и настоящей MariaDB**: `REAL_PHP_ATTESTOR_DATABASE_TEST=PASS`, `ATOMIC_STATE_TRANSITIONS=PASS`, `INVALID_AND_UNKNOWN_STATUS_DENIED=PASS`. Успешный `send/deliver` в синтетическом провайдере проводит атомарные `registered → confirmed` и `sent_attested=0 → 1`. Wrong user, expired, verified, missing binding, replay, `not_deliver`, `unknown` — отказ. Итог `FINAL_STATUS=PASS FINAL_RC=0 WRAPPER_RC=0`. **LIVE_PROVIDER_VERIFICATION=NOT_TESTED, MOCK_PROVIDER=YES.**

## 3. Неактивные локальные компоненты (operator SHA-256; не утверждать, что эти файлы уже находятся в GitHub)

| PHP-файл в `local/samzaberu-v5/src/` | SHA-256 |
|---|---|
| `V5PhoneBootstrapSmsIssuer.php` | `2108548a941893a39d6d7e4db4a69696542ba41ef7feb9ff5cba2f37c504665f` |
| `V5MySqlPhoneBootstrapSmsAttestation.php` | `04a22c0e48fa64159671193200d671ae9679a09ba44c90345409e18d1b802c41` |
| `V5MySqlPhoneBootstrapProof.php` | `dc66f14d8639f5b22598db184e73e3e166c44310e3343d269a5dc28eb1391289` |
| `V5PhoneBindingBootstrap.php` | `bfaeec7702ea76b4928728512cd4b274561a854ac73d7edb40e6d2933e4208af` |
| `V5StreamTelecomResponseParser.php` | `755cc899e11de1c932dabdec399d69639456d4e3569e7154eba88f6778ec306e` |
| `V5StreamTelecomStatusClient.php` | `c3c5de2d0a1fabe2b2010e0606fed2f0617012d683ada4627740a3683c4cb088` |
| `V5PhoneBootstrapProviderBinding.php` | `9c01da98138bb338c92f2db004fb9aef2f886e467f92944af8a24fe41db69408` |
| `V5PhoneBootstrapStatusAttestor.php` | `77c87dd599177863b755173a366f576d297ed03068080b820597e81d9fdd8efe` |

SQL proposals в `local/samzaberu-v5/schema/`, **НЕ применены к production**:

| SQL-файл | SHA-256 |
|---|---|
| `PROPOSED_v5_phone_bootstrap_proofs.sql` | `9177ed11be3b482968c1c1669cca61c86311edd736a3ed725f8edc899429b964` |
| `PROPOSED_v5_phone_bootstrap_sms_challenges.sql` | `a2db43c4911f568d8c7562657c703351d6691eaaeddef46aebe6fab8589e9d41` |
| `PROPOSED_v5_phone_bootstrap_provider_receipts.sql` | `4f8b33a5538c890a98e60f54fa9c57f749ba13aca945eebfd1a1b813a117de1e` |

В production уже существуют **другие**, login-oriented V5 таблицы: `ev_v5_auth_challenges`, `ev_v5_sms_outbox`, `ev_v5_sms_budget_windows`, `ev_v5_auth_attempt_windows`, `ev_v5_strong_sessions` и `ev_samzaberu_auth_state`. Не расширять или переиспользовать login outbox для bootstrap без явного анализа назначения.

## 4. Что обнаружено об обычном SMS-канале

- Действующий `local/php_interface/lib/CSms.php::send()` делает cURL-запрос на `gateway.api.sc`, но возвращает `true` без проверки ответа провайдера — **не использовать это значение как доказательство отправки**. `Controllers/V4/Sms.php` также возвращает клиенту успех без контроля transport result. **Ничего из этого в V4 не менялось**.
- `CSms::sendByVk()` и `getMessageStatus()` относятся к другому каналу VK/списанию бонусов и по решению пользователя **исключены** из данного потока.
- Неактивные Stream Telecom parser/status client созданы и прошли офлайн-тесты; **реальный endpoint и реальный формат acceptance/status для конкретного legacy GET-контракта не проверены**. Текущие numeric SMS ID 10–30 символов — временное допущение; нужно сверить с реальным API, не посылать реальную SMS без согласованного теста.
- `sent_attested=1` должен устанавливать только защищённый server-side status verifier, который читает `smsid` из ранее зарегистрированной DB-связи, сверяет ownership/expiry/status и делает переход в одной транзакции. Сама регистрация `smsid` **не** создаёт подтверждение.
- Unknown/timeout нельзя трактовать как sent; не допускать автоматической повторной тарифицируемой отправки.

## 5. Следующая инженерная задача без возврата к прежним аудитам

**`IMPLEMENT_SAFE_BOOTSTRAP_SMS_ISSUANCE`**: создание purpose-bound bootstrap challenge + проверка нативного аккаунта/phone binding и изменения номера под блокировкой; квоты и тарифный upper bound **до отправки**, в том числе per-account/phone/IP/global/cost; reservation и write-once outbox в одной транзакции; dispatch вне транзакции; тайм-аут/unknown не приводит к повторной отправке без подлинной idempotency-гарантии; capture provider acceptance/message ID, authoritative status verification, затем подтверждение challenge; SMS code — только серверный одноразовый consume (5 ошибок). Использовать ранее реализованные V5 budget/outbox contracts из [SMS guards](SAMZABERU_V5_SMS_SEND_GUARDS_2026-10-11.md), но **разделять login и bootstrap purpose**.

Нужно независимо проверить документацию текущего Stream Telecom legacy GET endpoint и ответы без раскрытия credentials. Сначала реализовать и испытать issuer/outbox/quota на временной MariaDB и synthetic provider; не отправлять живые SMS и не подключать V5 HTTP без отдельной контролируемой приёмки и rollback. Не переписывать V4/VK/bonus paths.

## 6. Инструкция продолжения

«Продолжаем `Evrasia AI Bot / СамЗаберу V5`. Читай эту контрольную точку от 19.10.2026 вместе с последним handoff от 18.10 (signup/legal) и `SAMZABERU_V5_SMS_SEND_GUARDS_2026-10-11.md`. Проверь HEAD рабочей ветки, CI и фактические серверные SHA. SMS attestation/issuer/proof + provider-id binding + status attestor с mock уже прошли PHP/MariaDB PASS, тестовые схемы удалены; реальные SMS и production миграции не выполнялись, V5 OFF/503. Следующее — safe bootstrap SMS issuance, тарифные квоты, сохранение provider message ID и authoritative receipt. Не затрагивать V4/VK/bonus, не повторять завершённые исследования. Давай один безопасный pasteable wrapper за шаг, очистка терминала в начале, guards, `FINAL_STATUS/FINAL_RC/WRAPPER_RC`, без секретов».
