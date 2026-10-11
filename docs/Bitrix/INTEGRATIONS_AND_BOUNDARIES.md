# Bitrix — карта интеграций и границы текущих работ

> **Дата контекстного снимка:** 2026-10-11. Каждое направление ниже имеет **свою** приёмку, статус и границы. Старые checkpoint'ы не должны автоматически перекрывать более поздние production-акты.
>
> Данный файл — навигация, не инструкция по deployment.

## 1. Две серверные системы

**Сайт / API Bitrix**: production host `evrasia` (виртуальный сайт `evrasia.spb.ru`, API `evrasia.rest`, document root `/home/site_evrasia/web/evrasia.spb.ru/public_html`). Здесь существуют учётные записи `b_user`, OTP `b_sec_user`, mobile V4 auth, Trusted Device, согласия и сервисы Anti-Fraud.

**Отдельный бот Evrasia AI Bot / Anti-Fraud**: production host `eur-bot-01`, Compose, PostgreSQL и веб-интерфейс оператора. Этот репозиторий содержит код бота; deployment бота и Bitrix — **разные события**. На 2026-10-03 Anti-Fraud PR #73 merged/production accepted; ссылка: [фактическая приёмка](../ANTI_FRAUD_PR73_PRODUCTION_ACCEPTANCE_2026-10-03.md). Не использовать ревизию бота как baseline для PHP сайта.

**Правило:** Bitrix — источник истины по `ACTIVE` и `BLOCKED`; риск-скоринг бота не должен автоматически менять состояние аккаунта. Три пользовательских состояния: «Активен», «Неактивен», «Заблокирован»; блокировка только по `BLOCKED=Y`. Источник: [BITRIX_ANTI_FRAUD_PRODUCTION_MAP](../BITRIX_ANTI_FRAUD_PRODUCTION_MAP.md). Принятая логика оператора / UI уже работает, не переписывать ради V3C.

## 2. Веб-сайт: TOTP 2FA / защищённый профиль

**Отдельный** поток, не равный СамЗаберу V3C:

- Требование: опциональная 2FA (Яндекс Ключ / Google Authenticator / другие совместимые приложения), подтверждение **текущего телефона по SMS ДО выдачи TOTP QR/secret**.
- TOTP **не** заменяет PIN для списания бонусов.
- Только доказавшая второй фактор **текущая сессия** может получить привилегию прямого отображения bonus PIN; обычный аккаунт остаётся на VK/SMS.
- Изначальный пилот — один контролируемый аккаунт; глобальная mandatory 2FA для всех не согласована.
- Веб-пилот с native Bitrix TOTP продвинулся до production: SMS gate, enrollment, TOTP login, revoke старых сессий; отключение через свежий TOTP было **browser accepted 2026-09-24**.
- Важное исправление disable: `disableTotpAction()` ранее ошибочно повторно использовал enrollment-only `requirePilotUser()`; создан отдельный `requirePilotUserForDisable()`. Последний документированный production SHA компонента `local/components/eurasia/totp.enrollment/class.php`: `16220efe992af6fbf0b2910adb29be0ecdb3b100b7ad0d43fa4de58b73495789` после приёмки 2026-09-24. **Не возвращать старый guard**. Состояние OTP/включения пользователя с тех пор перепроверять live.
- Не считать пилот массовым rollout всех пользовательских аккаунтов.

Смотреть **последнюю секцию 20** [TOTP_2FA_DESIGN_CHECKPOINT](../TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md), а не только его исходные разделы «планируется». Сводные `AI_PROJECT_CONTEXT` и `PROJECT_CHECKPOINT` содержат ранние плановые разделы, которые хронологически **устарели** по отношению к addendum от 2026-09-24. Это историческое противоречие прямо обозначено в [START_HERE](START_HERE.md).

## 3. СамЗаберу V4: новая защищённая мобильная авторизация V3C

**НЕ внедрено.** В изолированной лаборатории проверены candidate SQL/Auth epoch, DTO/projection, actual verifier+PDO (последние **25/25 PASS**), но через **синтетический** TOTP generator/consumer. Основная следующая задача: доказать native Bitrix TOTP candidate, а затем отдельно интеграцию в mobile V4 login/JWT. Подробности в [V3C checkpoint](V3C_SECURE_AUTH_CHECKPOINT_2026-10-11.md).

- Existing mobile auth controller `local/php_interface/Controllers/V4/Auth.php` имеет контрольную SHA только как last read-only baseline. Точка использования native TOTP ещё подлежит проверке.
- Proposed standalone `ev_samzaberu_auth_state` + 8 SQL triggers **не в production**, никакого `AUTH_EPOCH` rollout.
- Наличие web-site TOTP pilot **не означает**, что mobile V4 уже требует TOTP.
- Не менять bonus PIN, веб-JWT или logout косвенно через V3C без отдельной согласованной интеграционной спецификации.

## 4. СамЗаберу Trusted Device — уже production

**2026-09-24: PRODUCTION / END-TO-END ACCEPTED.** Контракт клиента: `sz_` + 64 lowercase hex, всего 67 символов. Сайт вычисляет SHA-256 **всего значения, включая `sz_`**, чтобы получить 64-hex opaque identity для существующего общего Trusted Device service; этот сервис повторно хеширует его при хранении. Подтверждён путь:

```text
SamZaberu mobile
  -> V4 password auth
  -> TrustedDeviceMobileService
  -> derived namespaced identity
  -> TrustedDeviceService
  -> ev_trusted_devices
  -> protected export
  -> bot anti_fraud_device_links + anti_fraud_device_events
```

**Не передавать 67-символьную строку напрямую в 64-hex core и не отбрасывать `sz_`.** Работа уже закрыта. Единственный упоминавшийся non-blocking follow-up — bot-side `client_type=NULL` для отображения платформы, отдельно от безопасности аутентификации. Источник: [SamZaberu Trusted Device acceptance](../SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md).

## 5. Юридические документы, оферта, ПД и мобильные согласия

**Сайт**: юридические страницы, оферта от 11.09.2026 (включая §1.19 «Недобросовестное использование»), отдельное согласие ПД и необязательное маркетинговое согласие — production. Bitrix native consent persistence: `Bitrix\Main\UserConsent\Consent::addByContext(...)`, authoritative `b_consent_user_consent`; действующие mandatory agreement IDs 1/2, маркетинговый ID 3 необязателен. Состояние маркетинговой подписки `UF_SMS`/`UF_SUBSCRIBE` не заменяет запись native consent. Источник: [WEBSITE_LEGAL_CONSENT_INTEGRATION](../WEBSITE_LEGAL_CONSENT_INTEGRATION.md).

**Мобильная регистрация СамЗаберу** — отдельное незакрытое направление. Старое ТЗ с JWT-protected `GET /api/v4/legal/consents` и `POST /api/v4/legal/consents/accept` описывало существующего авторизованного пользователя; новый `Auth::signup()` сам JWT не выдаёт. Поэтому для регистрации принято **двухфазное** изменение дизайна:

1. **До JWT / до создания пользователя:** публично получить с сайта актуальные тексты, версии/коды обязательных документов (не hardcode в приложении).
2. **Регистрация:** передать принятые коды, backend перепроверяет их актуальность и записывает native Bitrix consent events после получения нового user ID.
3. Текущие JWT-protected методы остаются для post-auth и последующих версий документов.

**Статус: DESIGN CORRECTION ACCEPTED / IMPLEMENTATION NOT DONE (2026-10-02).** Конкретная анонимная endpoint path, originator и error contract ещё не утверждены для релиза. Не путать с V3C/Trusted Device. Источник: [SamZaberu legal registration correction](../SAMZABERU_LEGAL_CONSENTS_REGISTRATION_2026-10-02.md).

## 6. Production/read-only границы и источники

- Веб-продукт, сайт Bitrix, Anti-Fraud bot, SamZaberu V4 и внешние OTP/Device/Legal контракты развиваются связанно, но **deploy отдельно**.
- Подтвержденные production изменения имеют собственные акт, rollback и exact SHA; staging /tmp proof не переводит функцию в production.
- Скрипты для сайта и скрипты для бота обязаны иметь **разные** host guards. Текущий V3C stage ориентирован на `evrasia`, bot — `eur-bot-01`.
- До любой production мутации: read-only проверка текущих SHA, версии/ролей/таблиц/backup; при несовпадении guard остановиться, не обходить проверку.
- Пользовательские аккаунты, TOTP/phone secret, cookies/JWT, реальные номера телефонов и токены никогда не публиковать. Репозиторий **публичный**.
- Продолжение по текущей задаче — [START_HERE.md](START_HERE.md), базовые правила — [SERVER_SCRIPT_RULES.md](../SERVER_SCRIPT_RULES.md).

## 7. Таблица исторических статусов

| Источник | Актуальное использование |
| --- | --- |
| `docs/AI_PROJECT_CONTEXT.md` | общая история и текущая ревизия Anti-Fraud на 2026-10-03; V3C добавлен позже в этот раздел Bitrix |
| `docs/PROJECT_CHECKPOINT.md` / `docs/NEW_CHAT_HANDOFF.md` | старые продолжения, включая **ранний** TOTP «PLANNED»; смотреть поздний production addendum и настоящий V3C checkpoint |
| `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md` | источник веб-2FA; поздняя секция 20 supersedes исходную плановую часть |
| `docs/Bitrix/V3C_TEST_ACCEPTANCE_2026-10-11.md` | последний фактический **isolated** E2E V3C; не production |
| `docs/SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md` | уже принятая production интеграция мобильного устройства, не перепроверять без нового требования |
