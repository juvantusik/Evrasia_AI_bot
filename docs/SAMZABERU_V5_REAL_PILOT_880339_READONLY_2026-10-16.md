# СамЗаберу V5 — текущий реальный pilot Bitrix USER_ID=880339 (READ_ONLY 2026-10-16)

**Операторский stdout на рабочем Bitrix `evrasia`, 2026-10-16;
FINAL_STATUS=PASS, FINAL_RC=0, MODE=READ_ONLY.**

Цель: проверить только последнюю фактическую MFA/phone/epoch точку
для ранее согласованного пилотного Bitrix USER_ID `880339`,
а НЕ запустить V5 или повторить удаление/подключение TOTP.

## Результаты

Проверка осуществлена реальным
`Bitrix\Main\DB\MysqliConnection->getResource()` на базе
`eurasia_new` при `@@session.tx_read_only=1`,
`START TRANSACTION READ ONLY`, затем `ROLLBACK`.
Тест не загружал `prolog_before.php`, не запускал сайт/агенты,
не записывал пользователя, MFA/телефон/базу и не отправлял SMS.

| Свойство | Фактическое значение |
| --- | --- |
| `ACCOUNT_EXISTS` | YES |
| `ACCOUNT_ACTIVE` | Y |
| `BLOCKED_COLUMN` | YES |
| `ACCOUNT_BLOCKED` | NO |
| `OTP_ROW_EXISTS` | NO |
| `RECOVERY_CODE_ROWS` | 0 |
| `V5_AUTH_STATE_EXISTS` | NO |
| `NATIVE_PHONE_ROWS` | 1 |
| `NATIVE_CONFIRMED_PHONE_ROWS` | 1 |
| `USER_RECORDS_CHANGED` | NO |
| `MFA_RECORDS_CHANGED` | NO |
| `BACKUP_CREATED` | NO |
| `SECRET_VALUES_PRINTED` | NO |

Ранее подтверждённые native SHA и все обязательные методы реального
`Bitrix\Security\Mfa\Otp` не изменяли в этом запуске;
native-driver SHA проверен PASS. Путь, изоляция и принадлежность
физической MariaDB-драйверной сессии подтверждены предыдущим
`SAMZABERU_V5_REAL_BITRIX_READONLY_ACCEPTANCE_2026-10-16.md`.

## Исторический пилот

В `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md` (с дополнением
24 сентября) зафиксирован **уже успешно выполненный** настоящий
enrollment + TOTP login + browser disable-flow на этом же аккаунте.
После успешного `Otp::delete()` текущий `OTP_ROW_EXISTS=NO`
согласуется с ожидаемым состоянием, но это не новая проверка
`Otp::delete()` под V5 epoch.

## Текущая граница и следующий практический шаг

1. **Нельзя сейчас тестировать удаление существующего OTP:** строки
   `b_sec_user` для `880339` нет.
2. Тест `Otp::save()` потребует настоящего enrollment/recovery
   writer на пилотном аккаунте, который временно изменит MFA и может
   повлиять на его штатный вход. Важны согласованный момент,
   контролируемый backup и независимая проверка post-state.
   Не делать это по одному слову «дальше» без отдельного допуска на
   запись в production, даже для личного аккаунта оператора.
3. Для пилота `ev_samzaberu_auth_state` пуст по SELECT, и текущий
   V5 fail-closed epoch lookup не может пройти. Инициализация V5 epoch
   `V5PilotAuthEpochInitializer` запрещена, пока не аттестованы
   все 10 atomic native security mutation paths; нет права обходить
   этот gate «ради теста». Native confirmed phone=1 НЕ устанавливает
   PHONE_EVENT_VERSION/HMAC V5.
4. Никакого повторения production DDL для шести V5 таблиц,
   полного dump или изменяющего SQL без одобрения. Сначала собрать
   однозначное разрешение на тестовый MFA write-scope, затем отдельный
   guarded precheck → backup с hash/проверкой → минимальная mutation
   → независимый post-check → verified safe recovery/rollback,
   без автоматического retry после commit uncertainty.
5. Не проверять тот же host/native driver/SAVEPOINT физического
   `mysqli` снова: соответствующий CHECKPOINT уже PASS.

**STATUS:** `PILOT_OTP_ENROLLED=NO`;
`PILOT_RECOVERY_COUNT=0`; `PILOT_V5_AUTH_STATE=ABSENT`;
`PILOT_PHONE_CONFIRMED=YES`; `PRODUCTION_WRITE=NO`.
