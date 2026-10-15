# V3C — результаты isolated integration / E2E на 2026-10-11

> **Финальная приёмка тестового пути:** `V3C_REAL_VERIFIER_PDO_E2E=PASS` / `REAL_VERIFIER_PDO_RUNTIME=PASS`.
>
> **Особенность:** настоящий verifier + PDO + MariaDB + триггеры + projection + нормализатор телефона; **TOTP candidate — синтетический**. Данный документ **не** является актом production deployment и **не** доказывает исполнение нативного `Bitrix\Security\Mfa\Otp`.

## 1. Latest authoritative execution — 2026-10-11

По полному терминальному выводу оператора, переданному в Project:

```text
SYNTAX_RC=0
HOST=evrasia
USER=root
ORIGINAL_PHONE_NORMALIZER_LOADED=YES
REAL_VERIFIER_CLASS_LOADED=YES
REAL_PDO_STORE_CLASS_LOADED=YES
E2E_CASE_COUNT=25
E2E_FAIL_COUNT=0
ORIGINAL_PHONE_NORMALIZER_EXECUTED=YES
REAL_VERIFIER_EXECUTED=YES
REAL_PDO_STORE_EXECUTED=YES
REAL_EPOCH_PROOF_EXECUTED=YES
REAL_PROJECTION_EXECUTED=YES
NATIVE_BITRIX_TOTP_EXECUTED=NO
REAL_VERIFIER_PDO_RUNTIME=PASS
INSTANCE_STOPPED=YES
SANDBOX_REMOVED=YES
PASS_COUNT=29
FAIL_COUNT=0
PRODUCTION_DATABASE_READ=NO
PRODUCTION_DATABASE_WRITE=NO
PRODUCTION_FILES_WRITE=NO
PRODUCTION_CONFIG_LOADED=NO
BITRIX_BOOTSTRAP=NO
AUTH_EPOCH_MIGRATION=NO
PRODUCTION_TRIGGERS_INSTALLED=NO
V3C_ACTIVATED=NO
V3C_REAL_VERIFIER_PDO_E2E=PASS
FINAL_STATUS=PASS
FINAL_RC=0
RUN_RC=0
```

Указанные результаты **операторские фактические выводы конкретного offline run**. Они не являются логами CI, не доказывают, что staged /tmp по-прежнему существует, и не подтверждают актуальный production SHA после даты запуска.

## 2. Все 25 E2E кейсов — ровно по финальному выводу

| # | `checkCase` из E2E | Что подтверждено | Итог |
| --: | --- | --- | --- |
| 1 | `REAL_COMPONENTS_INSTANTIATED` | Настоящие verifier и PDO store инстанцируются | PASS |
| 2 | `EIGHT_SANDBOX_TRIGGERS` | В изолированной DB установлены 8 предложенных триггеров | PASS |
| 3 | `EIGHT_SYNTHETIC_ACCOUNTS_INITIALIZED` | В тестовой схеме восемь несуществующих пользователей, ожидаемый AUTH_EPOCH | PASS |
| 4 | `REAL_PROJECTION_AND_FINGERPRINT` | Каноническая проекция / актуальный HMAC-snapshot совпадают | PASS |
| 5 | `REAL_VERIFIER_ACCEPTS_SYNTHETIC_CODE` | Настоящий verifier пропускает синтетический успешный TOTP candidate | PASS |
| 6 | `REAL_VERIFIER_CONSUMES_PARAMS` | `PARAMS` обновлён и зафиксирован | PASS |
| 7 | `REAL_VERIFIER_RESETS_ATTEMPTS` | `ATTEMPTS` сброшен | PASS |
| 8 | `SUCCESS_PRESERVES_AUTH_EPOCH` | Штатный TOTP не инвалидирует собственный epoch | PASS |
| 9 | `REPLAYED_CODE_REJECTED` | Повторное подтверждение отвергнуто | PASS |
| 10 | `REPLAY_PRESERVES_PARAMS` | Replay не изменил сохранённое значение | PASS |
| 11 | `INVALID_CODE_REJECTED` | Неверный синтетический код отвергнут | PASS |
| 12 | `FAILED_REQUESTS_CLOSE_TRANSACTION` | Проваленные вызовы не оставляют открытых транзакций | PASS |
| 13 | `PASSWORD_CHANGE_BUMPS_EPOCH` | Изменение пароля увеличивает epoch | PASS |
| 14 | `STALE_PASSWORD_SNAPSHOT_REJECTED` | Snapshot до смены пароля отклонён | PASS |
| 15 | `PHONE_CHANGE_BUMPS_EPOCH` | Изменение подтверждённого телефона увеличивает epoch | PASS |
| 16 | `STALE_PHONE_SNAPSHOT_REJECTED` | Snapshot до смены телефона отклонён | PASS |
| 17 | `TOTP_SECRET_CHANGE_BUMPS_EPOCH` | Ротация TOTP-секрета увеличивает epoch | PASS |
| 18 | `STALE_TOTP_SNAPSHOT_REJECTED` | Snapshot до ротации TOTP отвергнут | PASS |
| 19 | `BLOCKED_ACCOUNT_REJECTED` | Заблокированный аккаунт не подтверждается | PASS |
| 20 | `DISABLED_TOTP_REJECTED` | Отключённый TOTP не подтверждается | PASS |
| 21 | `MISSING_STATE_REJECTED` | Отсутствующая state-строка приводит к отказу | PASS |
| 22 | `OLD_VERSION_REJECTED` | Старая версия после смены пароля отклонена | PASS |
| 23 | `FRESH_VERSION_PROOF_VALID` | Новая версия аккаунта имеет корректный proof | PASS |
| 24 | `FRESH_VERSION_CAN_COMPLETE_TOTP` | С новой версией подтверждение снова проходит, параметры фиксируются | PASS |
| 25 | `ALL_TRANSACTIONS_CLOSED` | После всех попыток PDO не в транзакции | PASS |

**Реальный нативный TOTP алгоритм в этих кейсах не проверялся.** Синтетический `TotpCandidateV3C` возвращал тестовое следующее `PARAMS` только при фиксированных синтетических входных условиях. Поэтому PASS по replay — проверка orchestration + CAS + синтетического кандидата; отдельно обязательно проверить replay при настоящем Bitrix TOTP.

## 3. История предыдущих отдельных проверок

| Время / этап | Проверки | Фактический результат |
| --- | ---: | --- |
| Trigger SQL, независимая MariaDB | **34** | 34/34 PASS |
| Concurrent transaction/locking, два соединения | **19** | 19/19 PASS |
| Реальный `PdoTotpStoreV3C` + 8 sandbox triggers | **14** | 14/14 PASS |
| Оригинальный `Impro\MobileId\PhoneNormalizer` + V3C canonical refs/projection, in-memory | **14** | 14/14 PASS |
| **Последний E2E real verifier+PDO+synthetic candidate** | **25** | 25/25 PASS |

Всего `34 + 19 + 14 + 14 + 25 = 106` сценариев по **разным тестовым наборам**. Не выдавать сумму за единую систему end-to-end на Bitrix production. Ранее fake-PDO tests (17/17), static source checks и loader smoke также выполнялись, но имеют **другое покрытие** и не включены в 106.

### Real PDO store: 14/14 (до E2E)

Подтверждены:

- 4 real store locks;
- CAS сохраняет новое `PARAMS` и обнуляет `ATTEMPTS`;
- `AUTH_EPOCH` не меняется из-за штатного CAS;
- replay / wrong secret отклоняются;
- rollback работает;
- **CAS при `PARAMS=NULL` обновляет корректно** (`NULL_PARAMS_CAS_UPDATED=YES`, return type bool);
- независимость второго аккаунта;
- транзакции и postcheck корректны.

### Real projection + PhoneNormalizer: 14/14 (до E2E)

Подтверждены:

- original `PhoneNormalizer` загружается и может вызываться без Bitrix bootstrap;
- подтверждённый телефон даёт canonical HMAC reference, 64 hex;
- разные записи одного и того же номера нормализуются в один reference;
- изменение номера меняет reference;
- неподтверждённый телефон отвергается;
- отсутствие phone-row поддерживается;
- версия TOTP — 64 hex и меняется при смене секретного материала;
- `LockedIdentityProjectionV3C::enrich` даёт корректный массив с одним добавленным каноническим полем в `user` и одним version field в `otp`.

## 4. Ранее падавшие тесты и снятые причины

**А. Первоначальное падение real PDO loader.**

Результат `FAILED_CASE=CONNECT`/`ERROR_CLASS=Error` оказался меткой теста **до require**. `TotpProvider` был определён в файле теста, `TotpProofStoreV3C` — в файле verifier. Изолированное выделение оригинального contract и правильный порядок загрузки дали `V3C_LOAD_ORDER=PASS` (9/9 shell), а затем PDO 14/14.

**Б. Первое E2E verifier+PDO остановилось на `FAILED_CASE=BUILD_ACCOUNT_SNAPSHOT`.**

Реальный verifier **ещё не вызывался**. Исследование обнаружило `CALLBACK_ERROR_CLASS=Error`, `ERROR_CATEGORY=MISSING_CLASS`, `MISSING_SYMBOL=Impro\MobileId\PhoneNormalizer`; причина — в тесте не был загружен native helper, а не отсутствие класса на сайте. Static-discovery нашло файл в модуле `impro.mobileid`, подтверждён original SHA, затем in-memory projection 14/14 PASS.

**В. Исправленная E2E попытка.**

Поменялся **только тестовый порядок загрузки**: перед `CanonicalNativeRefsV3C` / projection загружается оригинальный `PhoneNormalizer.php`. Никаких исходников production/V3C ради этого не исправляли. Итог: 25/25 PASS.

**Г. Опасная диагностическая иллюзия.**

Ранее использованный sanitizer отображал *любую* PHP числовую константу как `0`. Псевдостроки `strlen($x) > 0`, `rowCount() === 0` из вывода не доказывают такие условия в оригинальном коде. Проверять **оригинальные файлы + контрольные суммы**, не полагаться на redacted token output для граничных значений.

## 5. Настройка окружения при E2E

- Host guard: `evrasia`, root.
- Отдельный datadir из `mktemp -d`, MariaDB 11.4.5, не-root user `mysql`, только private Unix socket и `--skip-networking`.
- DB identity guard по `@@datadir`, `@@socket`, `@@skip_networking`, `@@log_bin`.
- В isolated database создавались **синтетические** формы `b_user`, `b_sec_user`, `b_user_phone_auth`, `ev_samzaberu_auth_state`, загружался candidate восьми триггеров.
- После run: `INSTANCE_STOPPED=YES`, `SANDBOX_REMOVED=YES`; `MODE=ISOLATED_DATABASE_TEST`, production files/database не изменены. Скрипт фиксировал `NATIVE_BITRIX_TOTP_TESTED=NO`.
- Проверялись SHA оригинальных файлов до/после. Доступ к действующему Bitrix MFA secret, реальным пользователям, SMS и JWT не осуществлялся.

## 6. Неизмеренное / оставшееся

**Не считать покрытыми** до отдельного испытания:

1. Нативный Bitrix TOTP генератор/верификатор (`Otp::verify` / state `PARAMS` и actual candidate implementation), реальные TOTP временные окна и ошибки clock skew.
2. Реальные V4 Auth.php API/JWT/refresh/session/Logout интеграционные ветки, включая обязательность TOTP для конкретного mobile login.
3. Backfill/`AUTH_EPOCH`/триггеры с **реальной production schema** и действующим населением базы.
4. Безопасный момент активации между обновлением кода и SQL/DDL, сменой пароля/телефона и конкурирующими JWT запросами.
5. Криптографическая replay-привязка нативного Bitrix candidate при одновременных запросах; операторский приёмочный сценарий.
6. Возможные изменения production кода/настроек после зафиксированных SHA.
7. Production deployment, backup + tested rollback + cutover, пилот и post-deployment browser/mobile acceptance.

**Решение не принималось** о запуске миграции или V3C для пользователей.

## 7. Следующая работа

Начинать только с READ-ONLY **native Bitrix TOTP source/contract audit** для точной подготовки standalone native candidate test, с минимумом повторных действий. После каждого нового isolated run записывать исходники/SHA, наборы кейсов/покрытие, остановку лаборатории и статус `PRODUCTION_MUTATION=NO`.

См. [связанную архитектуру и inventory](V3C_SECURE_AUTH_CHECKPOINT_2026-10-11.md), [готовую точку перехода](START_HERE.md).
