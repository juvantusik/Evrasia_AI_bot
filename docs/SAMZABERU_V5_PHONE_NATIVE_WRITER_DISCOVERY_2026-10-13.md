# СамЗаберу V5 — следующий source-only этап: native phone confirmation writers

**2026-10-13 · FIRST PHONE SOURCE INVENTORY PRODUCTION SOURCE-VERIFIED 5/5. SECOND CALL-ORDER AUDIT GITHUB-ONLY / NOT RUN.**

## Почему это отдельная задача

Три независимых source-only диагностики native MFA/recovery уже завершены и зафиксированы как 9/9, 6/6 и 7/7 PASS. Их повторять не требуется. Реальный изолированный Bitrix/MySQL staging пока не подтверждён, поэтому запускать транзакционный E2E нельзя. Отдельный незакрытый release gate — нативные операции подтверждения и отзыва телефона (в том числе **повторное подтверждение того же E.164**).

В V5 уже предусмотрены `PHONE_EVENT_VERSION`, `PHONE_BINDING_HMAC`, `AUTH_EPOCH` и существующий fail-closed `V5NativeMutationCoverageMatrix`. Версия phone verification должна увеличиваться при каждом новом подтверждении, даже если строка номера не изменилась. Отсутствующая native event coverage **не должна** трактоваться как разрешение SMS challenge или сильной сессии.

## Подготовленная диагностика

`site-integration/samzaberu-v5/tests/diagnostics/v5_phone_event_writer_inventory_readonly.sh`.

Ожидается запуск **только пользователем на production host `evrasia` под root**, только read-only static inspection; никакого GitHub Actions исполнения на production. Сначала сверяет два ранее подтверждённых SHA-256 исходников V4 `local/php_interface/lib/PhoneAuth.php` и `local/php_interface/Controllers/V4/Auth.php`; SHA mismatch ⇒ stop. Затем без вывода исходных строк/аргументов/секретов показывает только идентификаторы методов и метки строк, относящиеся к `UserPhoneAuthTable`, `CONFIRMED`, phone events. Ограниченно ищет декларацию `UserPhoneAuthTable` только среди имен PHP файлов телефонной категории в `bitrix/modules/main/lib` и печатает путь + SHA. Не открывает config, не читает аккаунты/телефоны, не запускает Bitrix, не отправляет SMS и не выполняет SQL.

**Это инвентаризация путей и мест в коде, не доказательство события или транзакции.** При ошибке хоста, SHA, отсутствующем native классе или неоднозначности — `FINAL_STATUS=FAIL` и отказ без изменений.

## Проверки

GitHub Actions [run 38382985133](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38382985133): `completed/success`, PHP 8.4.26, 48 PHP-файлов lint PASS, 22 offline suite PASS (включая 82 negative native coverage assertions), Bash `-n` четырёх production source-only диагностик PASS, новый phone-audit в изолированном CI правильно отказался на неподходящем host **до чтения production исходников**. Никакого real Bitrix runtime, DB E2E или production security event аттестации.

## Что делать с выводом

После получения от оператора строк `SOURCE=...`, `METHOD_LINE=...`, `MARKER_LINE=...`, `SHA256=...` и `FINAL_STATUS` установить **реальные** точки native `CONFIRMED`/phone mutator и event dispatch; лишь затем спроектировать узкую дополнительную read-only проверку порядка pre-write → SQL → post-write. Не подменять этот шаг повторным аудитом таблиц `b_user_phone_auth`, V4/Trusted Device, OTP/recovery или SMS. Если обнаружится неподтверждённая реализация / исходник изменился, остановиться, а не предполагать семантику.

Только для **планирования**: native phone confirmation/reconfirmation/revocation должны обновлять `PHONE_EVENT_VERSION` в той же физической транзакции/lock-order `b_user → b_sec_user → b_user_phone_auth → ev_samzaberu_auth_state` и уметь совместно откатываться с `AUTH_EPOCH`. Пока реальный Bitrix/MySQL staging и все 10 путей не доказаны, `NATIVE_PHONE_EVENT_ATOMICITY_PROVEN=NO`, `V5_HTTP=503` по последней документированной базе.

`MODE=READ_ONLY` · `PRODUCTION_WRITE=NO` · `DATABASE_WRITE=NO` · `SMS_SENT=NO` · `STAGING_BITRIX_ATTESTED=NO`.

## 2026-10-13 — operator production inventory completed: 5/5 PASS

Оператор выполнил скрипт `v5_phone_event_writer_inventory_readonly.sh` на `evrasia`, получено `MODE=READ_ONLY`, `PASS_COUNT=5`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `DATABASE_CONNECTION=NO`, `DATABASE_WRITE=NO`, `BITRIX_BOOTSTRAP=NO`, `USER_DATA_READ=NO`, `CONFIG_FILES_READ=NO`, `SECRETS_PRINTED=NO`, `SMS_SENT=NO`. Исходники V4 `PhoneAuth.php` и `Controllers/V4/Auth.php` имеют прежние pinned SHA, drift не обнаружен.

**Впервые source-verified:** native `bitrix/modules/main/lib/UserPhoneAuthTable.php`, SHA-256 `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991`. Метод `getTableName` (стр. 34), `getMap` (39), `validatePhoneNumber` (83), `onBeforeAdd` (96), `onBeforeUpdate` (101), `modifyFields` (106), `normalizePhoneNumber` (123); `CONFIRMED` reference в схеме на стр. 64. Название/существование pre-write callback не является доказательством transaction owner или `PHONE_EVENT_VERSION` writer.

**Уже известные V4 исходники / точки входа:**
- `local/php_interface/lib/PhoneAuth.php`, SHA `a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27`: `sendCode` (65), `check` (135), `createUser` (188), `sendSms` (220), без обнаруженных static table/confirmed markers в первом мета-аудите.
- `local/php_interface/Controllers/V4/Auth.php`, SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`: `signup` (263), `confirm_request` (779), `confirm` (954), `forgot_password` (551), `change_password` (680). `UserPhoneAuthTable` на 372 (в signup) и 869/876/880 (в confirm_request), `CONFIRMED` references на 845/873/891/896 (в confirm_request) и 1012 (в confirm). Исходник секретов, SMS и пользовательские данные не печатались.

**Чего данный результат НЕ устанавливает:** какие конкретно `UserPhoneAuthTable::add/update/delete` выполняются, сохраняется ли повторное подтверждение того же номера в БД, записывается ли `PHONE_EVENT_VERSION`, есть ли native shared transaction, меняется ли phone event при восстановлении пароля. Флаг `NATIVE_PHONE_EVENT_ATOMICITY_PROVEN=NO` сохраняется; SMS V5 не разрешать.

## Следующий узкий аудит source call-order (2026-10-13)

В feature подготовлен `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_confirmation_order_readonly.sh`; **на production НЕ выполнялся**. Перед чтением жёстко проверяет SHA всех трёх названных исходников. Затем строго для V4 методов `signup`, `confirm_request`, `confirm` и native `UserPhoneAuthTable` callback-функций печатает **только** строки и метки операций: `BITRIX_PHONE_TABLE_CALL` + имя статического метода; `CONFIRMED_FIELD_REFERENCE`, варианты `CONFIRMED_Y_ASSIGNMENT_CANDIDATE`; event/transaction markers; native callback/normalization markers. Никаких исходных строк, значений, переменных, phone, SMS, DB connections, config, Bitrix bootstrap. Ошибка SHA/хоста/отсутствие релевантных markers → `FINAL_STATUS=FAIL`.

Следующий операторский stdout `v5_phone_confirmation_order_readonly.sh` позволит уточнить точный порядок `UserPhoneAuthTable` вызовов и пересечение с `CONFIRMED`. Это **статический call-site inventory**, не доказательство исполнения ветвей и не подтверждение идентичного номера при reconfirm. После разбора результата не повторять поиск класса / MFA три аудита.

**Транзакционный/релизный блокер прежний:** `PHONE_EVENT_VERSION` должен увеличиваться даже при новом подтверждении того же номера, иначе сохранённый SMS challenge не отзывается; одна физическая DB transaction и общий `b_user` mutex требуются для native phone/version/epoch, но runtime isolated staging ещё не существует. `V5_HTTP=503` по последнему документированному baseline.
