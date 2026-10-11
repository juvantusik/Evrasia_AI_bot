# Bitrix — документация и точка продолжения проекта Evrasia

> **Актуально на:** 2026-10-11. **Репозиторий:** `juvantusik/Evrasia_AI_bot`.
>
> Это раздел о сайте Evrasia на Bitrix, мобильных V4/СамЗаберу и их интеграциях с Anti-Fraud. **Не считать содержимое этого раздела доказательством внедрения V3C в production.**
>
> **Начать новый чат:** [START_HERE.md](START_HERE.md).

## 1. Что читать

1. **[START_HERE.md](START_HERE.md)** — компактная точка продолжения, правила, точное последнее подтверждённое состояние и готовое сообщение для нового чата.
2. **[V3C_SECURE_AUTH_CHECKPOINT_2026-10-11.md](V3C_SECURE_AUTH_CHECKPOINT_2026-10-11.md)** — подробная архитектура защищённой мобильной авторизации СамЗаберу V3C, файлы/контрольные суммы, решение по AUTH_EPOCH, границы production.
3. **[V3C_TEST_ACCEPTANCE_2026-10-11.md](V3C_TEST_ACCEPTANCE_2026-10-11.md)** — история диагностик и результаты 25/25 последнего сквозного теста, что действительно проверено и что ещё нет.
4. **[INTEGRATIONS_AND_BOUNDARIES.md](INTEGRATIONS_AND_BOUNDARIES.md)** — 2FA сайта, Trusted Device, юридические согласия, Anti-Fraud и границы ответственности.
5. [../TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md](../TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md) — отдельная ветка *веб-кабинета*: есть production addendum от 2026-09-24, а первоначальные разделы имеют исторический характер.
6. [../BITRIX_ANTI_FRAUD_PRODUCTION_MAP.md](../BITRIX_ANTI_FRAUD_PRODUCTION_MAP.md), [../AI_PROJECT_CONTEXT.md](../AI_PROJECT_CONTEXT.md), [../NEW_CHAT_HANDOFF.md](../NEW_CHAT_HANDOFF.md) — общая история бота/Bitrix; разделять факты разных систем.

## 2. Статусы — разные направления, не смешивать

| Направление | Подтверждённый статус | Источник / оговорка |
| --- | --- | --- |
| **СамЗаберу V3C — защищённая мобильная авторизация** | **OFFLINE / ISOLATED TESTED; НЕ В PRODUCTION** | На 2026-10-11 пройден сквозной тест реального verifier + PDO + MariaDB с *синтетическим* TOTP-кандидатом (25/25). Нативный Bitrix TOTP в этом тесте не вызывался. |
| **2FA веб-кабинета Bitrix** | **Контролируемый production-пилот проходил внедрение; отключение 2FA браузерно принято 2026-09-24** | Статус текущего OTP пользователя и масштаб rollout проверять по фактическому production; старая надпись «PLANNED» в сводных файлах относится к начальному checkpoint. |
| **СамЗаберу Trusted Device** | **PRODUCTION / E2E ACCEPTED, 2026-09-24** | [Фактическая приёмка](../SAMZABERU_TRUSTED_DEVICE_PRODUCTION_ACCEPTANCE_2026-09-24.md). Не переписывать стабильный контракт устройств. |
| **Юридические документы и согласия сайта** | **PRODUCTION** | [Карта сайта](../WEBSITE_LEGAL_CONSENT_INTEGRATION.md). |
| **СамЗаберу: согласия при новой регистрации** | **DESIGN ACCEPTED / NOT IMPLEMENTED (2026-10-02)** | [Поправка к ТЗ](../SAMZABERU_LEGAL_CONSENTS_REGISTRATION_2026-10-02.md); не путать с JWT-protected post-auth. |
| **Anti-Fraud bot PR #73** | **PRODUCTION / ACCEPTED 2026-10-03** | [Приёмка](../ANTI_FRAUD_PR73_PRODUCTION_ACCEPTANCE_2026-10-03.md). Развивается отдельно от mobile V3C. |

## 3. Главные правила

- Источники по приоритету: **фактический production → актуальный GitHub → staging/test → документация → старые обсуждения**.
- **V3C** — внутреннее имя исследуемого безопасного мобильного auth-протокола, **не версия Bitrix**, не новый продукт и не релиз, который уже подключён.
- Текущая кодовая база бота в этом репозитории не означает, что кандидат PHP-файлы Bitrix размещены в нём или развёрнуты. PHP V3C и SQL-кандидаты на момент checkpoint размещались только в изолированном stage сайта; **в GitHub добавлена документация, а не исполняемый патч**.
- 2FA веб-кабинета, V4 mobile login, SMS регистрации и PIN бонусов — **разные** контракты; не объединять их автоматически.
- Репозиторий публичный. Не сохранять пароли, ключи HMAC, TOTP secret/QR, bearer/JWT, номера реальных пользователей или сырой диагностический дамп. Пути и хеши здесь приводятся как технические ориентиры, **не как разрешение на изменение production**.
- Никаких изменений production, Bitrix DB, глобальных MFA-настроек, интерфейса, JWT или логики авторизации без отдельного согласования.

## 4. Поддержка раздела

После **каждого** существенного изменения V3C обновлять checkpoint + тестовую приёмку + START_HERE. После реального Bitrix production cutover добавлять отдельный документ фактической приёмки с backup/rollback и ссылкой на exact revision; до этого **не менять** статус `NOT_DEPLOYED`.

Точки входа общего проекта: [AI_PROJECT_CONTEXT](../AI_PROJECT_CONTEXT.md), [PROJECT_CHECKPOINT](../PROJECT_CHECKPOINT.md), [SERVER_SCRIPT_RULES](../SERVER_SCRIPT_RULES.md).
