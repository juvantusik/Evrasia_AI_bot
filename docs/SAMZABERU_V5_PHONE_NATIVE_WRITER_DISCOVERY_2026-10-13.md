# СамЗаберу V5 — следующий source-only этап: native phone confirmation writers

**2026-10-13 · GITHUB-ONLY / PRODUCTION AUDIT NOT YET RUN / NO SITE CHANGES.**

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