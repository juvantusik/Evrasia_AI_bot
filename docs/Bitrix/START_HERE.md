# Bitrix — START HERE: переход в новый чат

> **Последняя точка:** 2026-10-11. **Рабочая ветка документации:** `docs/bitrix-v3c-handoff-20261011` (пока PR не объединён в `main`). **Текущая задача:** V3C, защищённая авторизация мобильного приложения **СамЗаберу** через существующий Bitrix. **Ничего не деплоить.**

## 0. Сначала восстановить правильную рабочую ветку

Это **не** задача бота Anti-Fraud (хост `eur-bot-01`) и **не** повторное внедрение 2FA веб-кабинета. V3C относится к кандидату новой безопасной **мобильной V4-аутентификации** сайта Bitrix (хост `evrasia`).

- GitHub: `juvantusik/Evrasia_AI_bot`, default `main`; baseline `main` при подготовке документации: `ce524a81782b4bb08345c4b804c8116ff588140b` (docs merge #74, 2026-10-03). Последний подтверждённый deployed **bot application** revision из общих документов: `949aec3fd76af2d6525f7705ad31cd798d533fbb`; docs-only коммиты не меняют production.
- На Bitrix PHP/SQL V3C **не активированы**: нет миграции `ev_samzaberu_auth_state`, нет восьми новых триггеров в production, нет переключения V4 контроллера на V3C.
- Исследование и испытания велись на Bitrix production-хосте, **но только в isolated /tmp stage и собственной MariaDB через Unix socket**, без чтения реальных аккаунтов и без записи в production DB.
- **Последний подтверждённый результат:** `V3C_REAL_VERIFIER_PDO_E2E=PASS`, `REAL_VERIFIER_PDO_RUNTIME=PASS`, `E2E_CASE_COUNT=25`, `E2E_FAIL_COUNT=0`, shell `PASS_COUNT=29`, `FAIL_COUNT=0`, `INSTANCE_STOPPED=YES`, `SANDBOX_REMOVED=YES`, `FINAL_RC=0`.
- В **этом** E2E применён оригинальный `Impro\MobileId\PhoneNormalizer`, настоящий `NativeTotpVerifierV3C`, настоящий `PdoTotpStoreV3C`, настоящее вычисление identity fingerprint, настоящая projection и восемь SQL-триггеров **в изолированной MariaDB**. **`NATIVE_BITRIX_TOTP_TESTED=NO`**: кандидат генерации `newParams` был синтетическим (`123456`). Не описывать этот тест как проверку реального криптографического TOTP.

## 1. Что уже пройдено — не повторять без причины

| Этап | Результат |
| --- | --- |
| SQL-триггеры AUTH_EPOCH, отдельная MariaDB | 34/34 PASS |
| Двухсоединительные конкурентные/транзакционные сценарии | 19/19 PASS |
| Настоящий PDO store, включая CAS при `PARAMS=NULL`, replay, secret mismatch, rollback | 14/14 PASS |
| Оригинальный PhoneNormalizer + V3C canonical references + locked projection без DB | 14/14 PASS |
| **Сквозная проверка real verifier + PDO + identity + MariaDB + synthetic TOTP candidate** | **25/25 PASS** |

Эти группы дают 106 проверенных сценариев по отдельным наборам, **а не 106 уникальных проверок полного production-приложения**. Отдельные статические тесты и fake-PDO проверки не приплюсовывать повторно. См. [V3C_TEST_ACCEPTANCE](V3C_TEST_ACCEPTANCE_2026-10-11.md).

### Важные диагностические ловушки, уже устранены

1. Исходный standalone `TotpProvider` интерфейс был определён в `unified_tests.php`, не в отдельном production-файле. Его извлекли **без выполнения теста** в `/tmp/evrasia-v3c-contract-stage.hFf8dA6d/TotpProvider.php` (SHA256: `c1b18f17dec07887c244fccf8c03b51eee6ab271feefb9aeeb42afcb1b02aa0b`). Интерфейс `TotpProofStoreV3C` находится **в текущем** `NativeTotpVerifierV3C.php`, включая `lockPhone`. Не брать старую версию из другой /tmp stage.
2. Первая E2E-попытка остановилась на `FAILED_CASE=BUILD_ACCOUNT_SNAPSHOT`, **до** `verifyAndConsume()`, потому что офлайн-тест не загрузил `Impro\MobileId\PhoneNormalizer`. Класс найден в локальном модуле `impro.mobileid`; в приложении его регистрирует `include.php`, а в тесте класс подключён прямым `require_once` **без Bitrix bootstrap**. Повтор 2026-10-11 прошёл 25/25. **Не исправлять рабочий `CanonicalNativeRefsV3C` из-за прежнего test-loader failure.**
3. Статическая диагностика PHP-методов маскировала **все числовые литералы как 0**. Выражения из таких дампов (`strlen(...) > 0`, `rowCount() === 0` и др.) не являются подлинными исходными условиями. Для выводов брать реальные исходники с контролем SHA и результаты исполнения.

## 2. Точная следующая операция

**Исследовать и затем отдельно проверить нативный Bitrix TOTP candidate (`TotpCandidateV3C::newParams`) в полностью изолированном окружении**, прежде чем готовить внедрение:

1. READ ONLY: проверить исходники актуального Bitrix `Bitrix\Security\Mfa\Otp` / type classes / параметры `verify`, `getSyncParameters`, `syncParameters`, `save` и уже подготовленные PHP/SQL stage-файлы; зафиксировать SHA и зависимости. **Не вызывать** native methods на реальных пользователях.
2. Собрать офлайн-адаптер/тестовый стенд со **синтетическим** TOTP secret, корректным 6-значным кодом, неверным кодом, повторным кодом, `PARAMS=NULL` и фиксацией с реальным PDO — не менять production MFA settings.
3. Отдельно доказать совместимость с существующей V4 авторизацией/JWT, сменой пароля/телефона, отключением 2FA и revocation; затем подготовить миграцию, rollout/rollback и проверку на staging/pilot. **Не выполнять deployment без явного разрешения.**

**Известный путь к контроллеру production:** `/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/Controllers/V4/Auth.php`; SHA256 `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` на последнем source guard; проверить актуальный SHA заново перед проектированием патча.

Детали stage, контрактов, SHA и SQL — [подробный checkpoint](V3C_SECURE_AUTH_CHECKPOINT_2026-10-11.md).

## 3. Параллельные системы — не смешивать

- **2FA веб-кабинета** уже имеет контролируемый production-пилот и browser-accepted отключение 2026-09-24. Источник: [TOTP_2FA_DESIGN_CHECKPOINT](../TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md), раздел 20. Старый заголовок «PLANNED» в `AI_PROJECT_CONTEXT` относится к первоначальному этапу и **не должен перекрывать** поздний production addendum.
- **СамЗаберу Trusted Device** уже production/E2E accepted 2026-09-24; не менять существующий конвертер `sz_ + 64hex` ради V3C.
- **СамЗаберу legal consents при регистрации**: поправка к ТЗ принята 2026-10-02, но новая pre-JWT registration-фаза **не внедрена**. Не смешивать с V3C.
- **Anti-Fraud bot**: отдельный production, PR #73 closed/accepted 2026-10-03; текущий GitHub default-branch и production image разные понятия.

См. [сводная карта интеграций](INTEGRATIONS_AND_BOUNDARIES.md).

## 4. Обязательный формат следующей итерации

- **Исследование → вывод → одно минимальное изменение только при необходимости → проверки → следующий шаг.**
- Для серверной операции: **один** целый copy-paste Bash wrapper, heredoc + `bash -n` до исполнения, guard host/user/paths/SHA/DB, пять секций вывода и `PASS/FAIL/FINAL_STATUS/FINAL_RC/RUN_RC`; терминал не закрывать.
- До согласования: `MODE=READ_ONLY` или `ISOLATED_DATABASE_TEST`, `PRODUCTION_FILES_WRITE=NO`, `PRODUCTION_DATABASE_WRITE=NO`, не выполнять Bitrix bootstrap/логин/SMS/JWT с реальными аккаунтами.
- Не выводить настоящие телефоны, пароли, хеши аутентификации, TOTP secret/QR, токены, HMAC keys и служебные credentials. Не менять `Auth.php` или глобальные MFA-настройки без отдельного согласования.

## 5. Сообщение для нового чата

> Продолжаем единый Project Evrasia AI Bot, ветка **Bitrix / SamZaberu secure auth V3C**. Открой GitHub `juvantusik/Evrasia_AI_bot`, ветку `docs/bitrix-v3c-handoff-20261011` (или `main`, если документация уже merged), прочитай `docs/Bitrix/START_HERE.md`, затем `docs/Bitrix/V3C_SECURE_AUTH_CHECKPOINT_2026-10-11.md`, `docs/Bitrix/V3C_TEST_ACCEPTANCE_2026-10-11.md` и `docs/Bitrix/INTEGRATIONS_AND_BOUNDARIES.md`. Последний проверенный E2E real verifier + real PDO + 8 triggers + original PhoneNormalizer с **синтетическим TOTP-кандидатом: 25/25 PASS, FINAL_RC=0**; MariaDB остановлена/удалена; **V3C НЕ В PRODUCTION**. Не повторяй пройденные проверки, не меняй production. Продолжай с диагностики/безопасной подготовки тестов **реального Bitrix TOTP candidate**; сначала проверь текущие SHA исходников и контракты, выдай следующий один read-only скрипт.

## 6. Как поддерживать историю

Последующие чаты — это **та же** работа. Записывать новое фактическое состояние отдельно от планов, отмечать даты, SHA, пройденные сценарии, staging/production и изменение решений `Было → Стало → Причина`. Не удалять историю предыдущих неудачных тестов, если она объясняет найденные границы зависимостей.
