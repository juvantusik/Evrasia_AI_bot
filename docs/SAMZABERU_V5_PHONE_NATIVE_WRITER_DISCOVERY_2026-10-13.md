# СамЗаберу V5 — следующий source-only этап: native phone confirmation writers

**⚠ АКТУАЛЬНО 2026-10-14: production whole-file PHP lexer подтвердил, что V4 `Auth.php` строки 891 и 1012 — КОММЕНТАРИИ, не активный PHP. Все ранее опубликованные `UserPhoneAuthTable`/ `CUser` / `CONFIRMED` текстовые совпадения в диапазонах 880–896 и 1009–1014 НЕЛЬЗЯ считать действующими write paths. Нижеследующая история source inventories — историческая, пересмотрена ниже.**


**2026-10-13 · PHONE SOURCE INVENTORY 5/5 AND FOLLOW-UP CALL-ORDER 6/6 BOTH SOURCE-VERIFIED. REAL PHONE EVENT ATOMICITY STILL NOT PROVEN.**

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


## 2026-10-13 — second phone source-order result: operator 6/6 PASS

Оператор выполнил на production `evrasia` `v5_phone_confirmation_order_readonly.sh`: все три SHA-256 совпали (V4 `PhoneAuth.php`, V4 `Controllers/V4/Auth.php`, native `UserPhoneAuthTable.php`), `PASS_COUNT=6`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. `DATABASE_CONNECTION=NO`, `DATABASE_WRITE=NO`, `BITRIX_BOOTSTRAP=NO`, `PRODUCTION_APPLICATION_WRITE=NO`, `SMS_SENT=NO`, `USER_DATA_READ=NO`, `SOURCE_LINES_PRINTED=NO`, `SECRETS_PRINTED=NO`.

**Доказанные static call-site markers (в фактически установленном исходнике):**

- `Auth::signup`: ссылки на phone-confirm helper на строках **311 / 334 / 339**, далее `UserPhoneAuthTable::add` на **372**.
- `Auth::confirm_request`: ссылки на `CONFIRMED` **845 / 873 / 891 / 896**; вызовы native `UserPhoneAuthTable::getList` **869**, `::add` **876**, `::getRowById` **880**.
- `Auth::confirm`: ссылка на `CONFIRMED` **1012**, но в ограниченном статическом скане **нет прямого вызова `UserPhoneAuthTable::add/update/delete`** внутри `confirm`.
- Native `UserPhoneAuthTable::onBeforeAdd` строка **96** и `::onBeforeUpdate` **101** направляют поля на `modifyFields` на **98/103**, которое обрабатывает `PHONE_NUMBER` на **112/115**, вызывает normalizer (115). Нативный callback на этом мета-уровне **не показал запись `PHONE_EVENT_VERSION` или границу транзакции**.
- У второго аудита `V4_PHONE_TABLE_STATIC_CALLS=4`, `V4_CONFIRMED_REFERENCES=5`, `NATIVE_CALLBACK_METADATA_MARKERS=12`. `CONFIRMED_Y_ASSIGNMENT_CANDIDATE` **не напечатан**. Это означает только, что узкий регэксп не встретил литерал `'CONFIRMED' => 'Y'` в одной строке; это **НЕ** доказательство отсутствия записи `CONFIRMED=Y` (динамические/многострочные варианты не исключены).

**Вывод:** статическая карта `confirm_request` и `confirm` не раскрыла линейную точку фактического сохранения подтверждения, операции `CONFIRMED=N→Y` при том же E.164 и `PHONE_EVENT_VERSION` CAS. Не делаем предположений на основе имени `confirm` и не используем существующее `CONFIRMED=Y` как факт повторной верификации. В V5 по-прежнему `PHONE_EVENT_VERSION_WRITER=NOT_INSTALLED`, `NATIVE_PHONE_EVENT_ATOMICITY_PROVEN=NO`, release fail-closed; production и V4 не изменены.

**Следующий узкий source-only шаг (только GitHub, не запускался на production):**
`tests/diagnostics/v5_phone_confirmation_transition_flow_readonly.sh`. В отличие от первых двух metadata inventories, он выводит ограниченный **санитизированный граф ветвлений и вызовов экземпляров** для `Auth::confirmation/signup/confirm_request/confirm` и `PhoneAuth::sendCode/check`; пытается найти косвенные `->update/->save/->set` и динамические изменения поля, не выводя строки PHP, номера телефонов, коды SMS или конфигурации. Перед анализом проверяет SHA обоих уже pinned V4 файлов; для native table новых копий исходника не требуется. Результат всё равно будет только source metadata и не может заменить изолированный staging rollback/concurrency proof.

## Третий phone source-flow аудит: operator 5/5 PASS, кандидаты на запись найдены

**Фактический stdout оператора 2026-10-13:** `v5_phone_confirmation_transition_flow_readonly.sh` на production host `evrasia` завершился `PASS_COUNT=5`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. Обе SHA-256 исходников V4 совпали с pinned `Auth.php=72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` и `PhoneAuth.php=a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27`. Прочитаны **только статические исходники**, `BITRIX_BOOTSTRAP=NO`, `DATABASE_CONNECTION=NO`, `PRODUCTION_APPLICATION_WRITE=NO`, `SMS_SENT=NO`, исходные PHP строки и секреты не печатались.

**Новые точные позиции вызовов экземпляров, которые не показывались предыдущими двумя статическими сканами:**

- `Auth::confirmation()` (метод от 60): `getList()` 96, `fetch()` 100, и **`->update()` строка 137** — владелец экземпляра/таблица ещё не идентифицированы.
- `Auth::confirm_request()` (779): прямые `UserPhoneAuthTable::getList` 869, `::add` 876, `::getRowById` 880; дополнительно **`->update()` строка 851**; рядом `CONFIRMED` ссылка 845. Не доказано, что этот `update` относится к phone auth или меняет `CONFIRMED`.
- `Auth::confirm()` (954): ссылка на `CONFIRMED` 1012, **`->update()` строка 1026**, при этом прямого статического `UserPhoneAuthTable::update()` найдено не было. Для выяснения фактической таблицы следует найти receiver и field map, не угадывать.
- `PhoneAuth::sendCode()` (65–123): `->sendSms()` на строке 115; `PhoneAuth::check()` (135–179): проверки/возвраты и генерация tokens (`createTokens` 162), в этом диапазоне прямые phone-table writes не выявлены. Результат является **source metadata**, не проверкой SMS delivery/code validation runtime.

**Границы доказательства:** вывод `INSTANCE_METHOD_CALL update` — это синтаксический факт наличия метода с таким именем, не факт обновления `b_user_phone_auth`. Поля аргументов / SQL и успешный native transaction не исследовались. `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, `PHONE_SAME_NUMBER_RECONFIRM_ATOMICITY=NOT_PROVEN`. При повторном подтверждении того же E.164 нельзя полагаться на HMAC номера как на свидетельство нового подтверждения.

**Следующий ровно один source-only шаг (GITHUB ONLY, на prod ещё не запускался):** `tests/diagnostics/v5_phone_update_receiver_readonly.sh`. Привязан к точному SHA V4 `Auth.php` и строго к трём строкам 137/851/1026. Печатает только безопасные идентификаторы PHP-переменных receiver, whitelisted field-reference tags (`CONFIRMED`, `PHONE_NUMBER`, `ACTIVE`, `USER_ID`) и приблизительную provenance/branch-структуру; никакого PHP-source, аргументов, кодов SMS, телефонов, SQL, Bitrix bootstrap или production changes. Если одна из уже подтверждённых позиций перестала соответствовать источнику, `FINAL_STATUS=FAIL`. Результат не станет real DB proof. На isolated staging отдельно остаётся требование доказать SAME-number reconfirm bump `PHONE_EVENT_VERSION` и совместный `AUTH_EPOCH` CAS.

Первый source inventory 5/5 и второй call-order 6/6 **закрыты**, третий flow 5/5 тоже **закрыт**. Не повторять. Все три MFA source-audits 9/9 + 6/6 + 7/7 **также закрыты**. Никакой V5 security hook не установлен; V4 и prod без изменений.

## Четвёртая диагностика: уточнение трёх `update()` — оператор 4/4 PASS

Получен **фактический production stdout**, `v5_phone_update_receiver_readonly.sh` на `evrasia` с `PASS_COUNT=4`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `MODE=READ_ONLY`. Exact `Auth.php` SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` совпал. `DATABASE_CONNECTION=NO`, `BITRIX_BOOTSTRAP=NO`, `PRODUCTION_APPLICATION_WRITE=NO`, `SMS_SENT=NO`, секреты/телефоны/строки PHP не печатались.

**Переаттестованные записи по source-line metadata:**

- `Auth::confirmation()` строка **136** `CONSTRUCT_CUSER`, **137** `INSTANCE_UPDATE_RECEIVER IDENT=$obuser`, **138** reference `ACTIVE`.
- `Auth::confirm_request()` строка **835** `CONSTRUCT_CUSER`, **851** `INSTANCE_UPDATE_RECEIVER IDENT=$obuser`, **852** reference `ACTIVE`. Потом отдельный native phone-table путь `UserPhoneAuthTable::getList` **869**, `::add` **876**, `::getRowById` **880**.
- `Auth::confirm()` строка **1025** `CONSTRUCT_CUSER`, **1026** `INSTANCE_UPDATE_RECEIVER IDENT=$obuser`, **1027** reference `ACTIVE`. Источник `CONFIRMED` reference **1012**; совпадение этого имени с реальной записью в phone-auth table **не доказано**.
- **Критично:** `Auth::confirm_request()` строка **891** `FIELD_CONFIRMED_SUBSCRIPT_WRITE`, то есть source-code присваивает значение элементу массива `['CONFIRMED']`. До этого есть `UserPhoneAuthTable::getRowById` (880), после — `CONFIRMED` reference 896. Но это **пока изменение PHP-массива**, ни физический DB UPDATE, ни завершённое phone confirmation событие, ни source-level гарантии обновления phone row отсюда не следуют. Не считать новое `PHONE_EVENT_VERSION` доказанным.

**Техническое уточнение:** три найденных `->update()` имеют receiver `$obuser` и находятся рядом с `new CUser` — это по статическим меткам **ветка обновления основного пользователя**, а не прямой `UserPhoneAuthTable::update`. Абсолютное разрешение типов и данных требует source-value-free provenance либо ревью полного вызова; текущая диагностика доказывает receiver и расположение конструктора, не аргументы.

**Следующий узкий шаг — единственный новый скрипт, ещё НЕ запущен на production:** `tests/diagnostics/v5_phone_confirmed_array_lineage_readonly.sh`. Он проверяет exact SHA того же `Auth.php`, source anchors `UserPhoneAuthTable` (869/876/880), запись `['CONFIRMED']` (891) и reference `CONFIRMED` (1012); выводит **только имя PHP array receiver, имена ограниченных классов/методов и символические метки дальнейших обращений в 869–938 и 992–1034**. Никаких source statements, значений/телефонов/SMS/конфигурации, Bitrix bootstrap или DB. Если source changed/anchors gone => `FINAL_STATUS=FAIL`. Отчёт укажет, используется ли изменённый массив дальше, **но не заменит** реального native phone DB write/epoch/rollback на isolated staging.

Все предыдущие phone source-аудиты **5/5, 6/6, 5/5, 4/4** приняты, не запускать повторно. `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, same E.164 reconfirm bump не доказан, 10-path native coverage gating остаётся закрытым. V4/production не изменяли.

## 2026-10-13 — CONFIRMED array lineage: production parser failure, corrected offline

Оператор запустил из последнего ответа ChatGPT read-only wrapper `v5_phone_confirmed_array_lineage_readonly.sh` на `evrasia`, полный stdout: **`PASS_COUNT=2`, `FAIL_COUNT=1`, `FINAL_STATUS=FAIL`, `FINAL_RC=1`**. Host/root guard, source guard и pinned `Auth.php` SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` прошли. **AWK не приступил к анализу PHP**, остановился на `awk: cmd. line:79: emit( ... unexpected newline or end of string`: реальная ошибка в переданном ChatGPT AWK body из-за строки, разорванной непосредственно после `emit(`; установленный на хосте POSIX awk не принимает такой перенос.

Это **ошибка диагностического текста, НЕ ошибка Bitrix, НЕ сбой транзакции/БД**. Выполненный wrapper имеет `BITRIX_BOOTSTRAP=NO`, `DATABASE_CONNECTION=NO`, `DATABASE_WRITE=NO`, `PRODUCTION_APPLICATION_WRITE=NO`, `SMS_SENT=NO`, `USER_DATA_READ=NO`, `SECRETS_PRINTED=NO`; все прежние source-audits остаются действительными. **`CONFIRMED_ARRAY_VARIABLE` и post-891 lineage пока НЕ установлены**, даже частично.

**Исправление ТОЛЬКО в feature GitHub:** программа AWK выделена в `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_confirmed_array_lineage.awk`; shell `v5_phone_confirmed_array_lineage_readonly.sh` вызывает `awk -f` по этому локальному проверенному файлу. Создан `V5PhoneConfirmedArrayLineageAwkSelfTest.sh`: на фальшивом 1040-строчном PHP-образце выполняется ТОТ ЖЕ AWK parser (положительная проверка `SOURCE_ANCHORS_VALID=YES`, `CONFIRMED_ARRAY_VARIABLE=$phonerow`, `CONFIRMED_RECEIVER_REUSES_AFTER_891=1`, `DIRECT_CLASS_METHOD_CALL_MARKERS=3`) и отвергает две изменённые фикстуры — поле `CONFIRMED` строка 891 и native table method строка 869. Программа не читает production во время CI.

**Реально проверено**: [GitHub Actions run 38425322406](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38425322406) `completed/success` для коммита `ef1144352e797db8c9d6bf2c7e00b5fba703c853`: позитивное исполнение parser PASS, два негативных сценария PASS, отказ production-only wrapper на CI-hосте PASS, 48 PHP lint PASS, 22 offline suites PASS. Предыдущий CI проверял только `bash -n` и wrong-host denial, **не компилировал AWK body** — именно этот пробел устранён новым положительным тестом. Эти тесты **НЕ** являются доказательством реального Bitrix/MySQL исполнения.

**Следующий шаг после пользовательского read-only исполнения исправленного wrapper:** разобрать только его значения `CONFIRMED_ARRAY_VARIABLE`, `CONFIRMED_RECEIVER_REUSES_AFTER_891`, `SOURCE_ANCHORS_VALID` и `FINAL_STATUS` без повторных MFA/phone 9/6/7 и 5/6/5/4 аудитов. При новом `FAIL` не выдавать release gate. Реальное атомарное подтверждение/reconfirm того же E.164 и `PHONE_EVENT_VERSION` ещё **не доказаны**; V5 503 по последнему документированному состоянию, production/V4 не менялись.

## 2026-10-13 — исправленный CONFIRMED field-array lineage: production 4/4 PASS

Оператор **фактически выполнил исправленный** source-only wrapper с отдельным `awk -f` на `evrasia`. Результат: `PASS_COUNT=4`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. Exact SHA `local/php_interface/Controllers/V4/Auth.php = 72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` совпал. `MODE=READ_ONLY`, Bitrix bootstrap/SQL/SMS/production app writes/user data/config/source statement disclosure отсутствовали. **Предыдущий parser FAIL закрыт**, не повторять его.

**Новое source-verified:**
- `SOURCE_ANCHORS_VALID=YES` для строк `UserPhoneAuthTable::getList` **869**, `::add` **876**, `::getRowById` **880**, `['CONFIRMED'] = ...` **891**, reference `CONFIRMED` **1012**.
- `Auth::confirm_request()` строка **891** — запись PHP-array по receiver **`$phoneuser`**: `CONFIRMED_ARRAY_VARIABLE=$phoneuser`, `CONFIRMED_ARRAY_WRITE_RECEIVER IDENT=$phoneuser`.
- `CONFIRMED_RECEIVER_REUSES_AFTER_891=0` означает отсутствие **прямых лексических** упоминаний `$phoneuser` на строках **892–938** (граница конкретного сканера). Это не доказывает отсутствие косвенного вызова, ссылки / by-reference alias, потока за пределами 938 или SQL side-effect до строки 891.
- `Auth::confirm()` reference `CONFIRMED` на **1012** (той же ранее известной исходной строке) имел `CONFIRM_METHOD_CONFIRMED_RECEIVER IDENT=UNRESOLVED`: точное выражение и источник значения всё ещё не определены.
- `DIRECT_CLASS_METHOD_CALL_MARKERS=5`: `userphoneauthtable::getlist` 869, `::add` 876, `::getrowbyid` 880; `cuser::generatephonecode` 901, `usertable::getlist` 992; `CONFIRM_METHOD_INSTANCE_MUTATOR` **1026**, ранее подтверждённый receiver `$obuser` / `CUser`. В пределах данного скана не обнаружено прямого `UserPhoneAuthTable::update` после 891 и в 992–1034.

**Вывод по безопасности:** пока **нельзя** утверждать, что `CONFIRMED=Y` физически записан в `b_user_phone_auth` в этих методах. `CONFIRMED` PHP-array write — не native phone event, не атомарная версия, не proof повторной проверки того же E.164. Не создавать hook на 891 и не утверждать, что `::add` / `::getRowById` после него (оба выполняются раньше). Нужен строго ограниченный source-only анализ assignment RHS и способа подтверждения в `confirm()`/других методах; native atomicity остаётся runtime staging блокером.

**Следующий разрешённый анализ:** только точная семантика fixed field tags и native persistence paths в `Auth::confirm_request` 869–938 и `Auth::confirm` 985–1120; не повторять MFA, предыдущий phone path inventory, receiver 4/4 или field-array 4/4. CI для любой новой AWK-диагностики должен действительно запускать **её parser на positive+negative synthetic fixtures**, поскольку `bash -n` сам по себе AWK не проверяет. Никаких production writes.

## 2026-10-13 — semantic source-only boundary фактически 4/4 PASS

Оператор предоставил полный вывод с `evrasia` последней read-only диагностики `v5_phone_confirmation_semantic_boundary_readonly.sh`: `PASS_COUNT=4`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. Exact pinned `local/php_interface/Controllers/V4/Auth.php` SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` совпал, `SOURCE_ANCHORS_VALID=YES`. Не исполнялся Bitrix/сайт PHP, SQL, пользовательские данные, конфигурация, SMS, production writes; output только source metadata.

**Новые проверенные символические факты:**
- `PHONE_ROW_READ_LINE_880=YES`, но `ROW_READ_ASSIGNED_TO_PHONEUSER_SAME_LINE=NOT_PROVEN`: не считать совпадение с `$phoneuser` доказанным.
- В `Auth::confirm_request` на **891** присваивание `$phoneuser['CONFIRMED']` имеет `PHONEUSER_CONFIRMED_ASSIGNMENT_VALUE=OTHER_OR_MULTILINE` — строго означает **не literal `'Y'`/`'N'` сразу после `=` на этой строке**. Значение неизвестно; может быть условием, переменной, функцией или многострочной конструкцией.
- В `Auth::confirm` на **1012** `CONFIRM_1012_FIELD_KIND=OTHER_REFERENCE` и `CONFIRM_1012_HAS_Y_LITERAL=NO`, `CONFIRM_1012_HAS_N_LITERAL=NO`. Показанный классификатор не распознал известные формы, поэтому **нельзя делать вывод об отсутствии телефонной проверки**.
- `PHONEUSER_DIRECT_REFS_AFTER_891=0`, `REQUEST_AFTER_891_NATIVE_PHONE_TABLE_MUTATOR_CALLS=0` и `CONFIRM_NATIVE_PHONE_TABLE_MUTATOR_CALLS=0` — **только прямые синтаксические совпадения в заданных диапазонах**. Есть `CONFIRM_INSTANCE_MUTATOR_LINE=1026`, ранее подтверждён `$obuser->Update()` с `new CUser`, а не прямой `UserPhoneAuthTable::update`. Нет доказанного native persistent change `CONFIRMED=N→Y`, phone event epoch CAS или same-E.164 reconfirm.

**Следующий минимальный source-only разбор**: не повторять ни один прежний аудит. В feature только подготовлены `tests/diagnostics/V5PhoneConfirmedExpressionTokens.php` и guarded `v5_phone_confirmed_expression_tokens_readonly.sh`. Используют штатный `php -n` (никакой php.ini, `include`/bootstrap/eval) исключительно для **лексического разбора 16 ограниченных строк** `Auth.php` вокруг 891 и 1012, чтобы вывести masked token shape. Все строковые литералы, номера и неизвестные имена переменных маскируются; выводятся только структурные PHP tokens и whitelist `CONFIRMED`, `Y`, `N`, `$phoneuser` без исходных строк PHP. При неверном host/SHA/anchor/отсутствующем токенизаторе — FAIL до обработки настоящего PHP-кода. Это диагностика source-only, не SQL/Bitrix runtime proof.

[GitHub Actions 38436298620](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38436298620) `completed/success` на commit `daeb06ba7ae65d18cb8ce30f443111d6d7172af1`: positive synthetic lexer masking PASS, altered 891 and 1012 both rejected, wrong-host guard PASS, all 22 offline V5 suites PASS, PHP lint PASS. Контрольные fake secret literals/identifiers не утекли. **На production следующий token scan ещё не запускался.**

Граница релиза: `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, `SAME_PHONE_RECONFIRM_ATOMICITY=NOT_PROVEN`, `NATIVE_PHONE_EVENT_ATOMICITY_PROVEN=NO`. Bitrix/MySQL staging по-прежнему отдельно не аттестован, V5 503 — последний документированный baseline, V4/prod никаких изменений.

## 2026-10-14 — production tokenizer preflight failed with php -n (2 PASS / 1 FAIL)

Оператор запустил защищённый `v5_phone_confirmed_expression_tokens_readonly.sh` на production `evrasia`. Важен **фактический** итог `PASS_COUNT=2`, `FAIL_COUNT=1`, `FINAL_STATUS=FAIL`, `FINAL_RC=1`, а не текст об успешных прошлых CI-тестах. PHP parser lint: `No syntax errors detected`. Host/root guard PASS, pinned SHA `Auth.php=72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` PASS. Остановка строго в `=== 3. PHP TOKENIZER AVAILABILITY ===`: `FAIL: PHP tokenizer unavailable without php.ini`. Это вызвано тем, что `php -n` в установленной CLI-конфигурации **не предоставляет** `token_get_all`; вероятно, `tokenizer` — отдельно подключаемый модуль. Причина его недоступности как built-in подтверждена, существование `tokenizer.so` в системном extension dir **пока не проверялось на production**.

`PHP_TOKENIZER_EXECUTED=NO`, `DATABASE_CONNECTION=NO`, `DATABASE_WRITE=NO`, `BITRIX_BOOTSTRAP=NO`, `PRODUCTION_APPLICATION_WRITE=NO`, `SMS_SENT=NO`, `USER_DATA_READ=NO`, `SECRETS_PRINTED=NO`. **Нет** вывода `SOURCE_LINE_891_TOKEN_SHAPE` или `SOURCE_LINE_1012_TOKEN_SHAPE`, содержимое выражений всё ещё **неизвестно**. Не считать проблемой V4 / Bitrix или дефектом данных.

**Минимальный исправленный preflight в GitHub feature (ещё НЕ выполнен на production):** `tests/diagnostics/v5_phone_confirmed_expression_tokens_readonly.sh` теперь выбирает `php -n` только если `token_get_all()` встроен. Иначе не загружая `php.ini`, определяет стандартный PHP `extension_dir` командой `php -n -r`, разрешает исключительно абсолютный путь `/usr/lib/php/.../tokenizer.so`, проверяет обычный файл, отсутствие symlink, точный realpath, root uid файла (`stat`), и подключает **только** эту системную библиотеку через `php -n -d extension=<trusted absolute path>`. Затем заново проверяет `token_get_all()` перед чтением PHP как текста. Если такой системной библиотеки нет или расширение не загрузилось, **FAIL до source lexer**, без fallback на произвольные расширения или полный `php.ini`. В output добавлен `TOKENIZER_LOAD_MODE=BUILT_IN|EXPLICIT_ROOT_OWNED_TOKENIZER_SO` при успехе. На данном production путь и наличие `.so` пока **не подтверждены**, поэтому положительный результат обещать нельзя.

GitHub Actions [38525901881](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38525901881) `completed/success`: Bash lint и synthetic `token_get_all` positive/negative проверки, 22 offline suites. **CI работал в другой среде PHP** и не доказывает, что shared tokenizer.so присутствует и загрузится на production Debian; это останется предметом следующего read-only запуска.

Все MFA source audits 9/9+6/6+7/7, телефонные inventories 5/5+6/6+5/5+4/4+4/4+4/4 **завершены и не повторяются**. Изолированного staging Bitrix/MySQL нет; `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, `SAME_PHONE_RECONFIRM_ATOMICITY=NOT_PROVEN`, V5 HTTP 503 — последний документированный baseline, V4/prod не изменялись.

## 2026-10-14 — production tokenizer loaded 4/4, but 16/16 COMMENT_MASKED (not semantic proof)

Оператор предоставил два последовательных stdout из production `evrasia` (оба source-only):

1. Первый повторённый прежний wrapper `php -n` — как раньше `PASS_COUNT=2`, `FAIL_COUNT=1`, `FINAL_STATUS=FAIL`, на отсутствии функции `token_get_all` без подключаемого расширения; actual lexer **не выполнен**.
2. **Следующий исправленный wrapper с системным `tokenizer.so` — `PASS_COUNT=4`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `TOKENIZER_LOAD_MODE=EXPLICIT_ROOT_OWNED_TOKENIZER_SO`, `PHP_TOKENIZER_EXECUTED=YES`, `PHP_INI_LOADED=NO`**. Подтверждено **реальное присутствие и возможность загрузить** отдельный root-owned shared module по безопасному пути на этом production сервере; pinned `Auth.php` SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` совпал. Без Bitrix bootstrap, MySQL/DB, SMS, user data/config, application writes.

**Критически:** получены `SOURCE_LINE_880`, `889`–`896`, `1009`–`1014` все с `TOKEN_SHAPE=COMMENT_MASKED` (**16/16**) при прежнем алгоритме `token_get_all('<?php ' . $line)` **по отдельной строке**. Такой output **не даёт структуру выражений** и **не доказывает**, что проверяемые позиции действительно исполняются PHP: исходные строки могут быть закомментированы, либо искусственный PHP open tag на каждой строке создаёт ошибочную классификацию из-за утраты lexical context. Итоговый `PASS 4/4` — проверка guards + доступность токенизатора + работа parser, НЕ подтверждение активного `CONFIRMED` writer. Все старые source-text regexp находили текстовые совпадения без подтверждения `T_COMMENT` versus live tokens; ранее сделанные выводы о **фактическом** выполнении этих мест обязаны оставаться строго SOURCE_TEXT_ONLY до новой проверки.

**Минимальная поправка в GitHub feature-only:** сохранён существующий `V5PhoneConfirmedExpressionTokens.php`, но переписан способ анализа: теперь `token_get_all($source)` **на полном оригинальном PHP-файле** (без подставных `<?php` и без include/eval), для позиций строк 891 (`$phoneuser['CONFIRMED']`) и 1012 (`CONFIRMED` reference) вычислены байтовые смещения и определяется тип токена **именно на позиции текстового якоря**: `PHP_CODE`, `COMMENT` или `INLINE_HTML`. Для 16 выбранных строк отдельно выводится `LEXICAL_CLASS=ACTIVE_PHP|ACTIVE_PHP_AND_COMMENT|COMMENT_ONLY|INLINE_HTML_ONLY|NO_ACTIVE_TOKENS` и только для реального PHP-кода маскированные `TOKEN_SHAPE`; source values и неизвестные identifiers не выводятся. `BOTH_SOURCE_ANCHORS_ACTIVE_PHP` — только source-level факт, не proof runtime branch/DB. Если какой-либо якорь в комментарии, явно `NO` и `COMMENT_OR_INLINE_ANCHOR_NEEDS_REVIEW=YES` — нельзя активировать release gate.

**Новая положительная и отрицательная проверка на настоящем PHP 8.4 токенизаторе в CI:** [38528408976](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38528408976) `completed/success` — активный fake PHP source, обе tampered source anchors, fake `//` comments, реальные `/*...*/` spanning comments, смесь live PHP и комментария на той же строке; 22 offline suites PASS, PHP lint PASS, wrong-host denial. Успех CI **НЕ значит**, что реальный `Auth.php` содержит live PHP на этих строках — это и должен установить следующий операторский source-only запуск.

**Следующая точка:** присланный оператором результат **исправленной full-file PHP lexer диагностики**, не повторять прошлые MFA 9/9+6/6+7/7 и все прежние phone inventories/scans. Интерпретировать `SOURCE_ANCHOR_891_LEXICAL_KIND`, `SOURCE_ANCHOR_1012_LEXICAL_KIND`, `BOTH_SOURCE_ANCHORS_ACTIVE_PHP`, `SOURCE_LINE_891/1012_TOKEN_SHAPE`, `FINAL_STATUS`; при `COMMENT` не искать немедленно mutation hooks в предполагаемых live методах. Не доказаны shared Bitrix/MySQL transaction, single-event same E.164 re-verify, `PHONE_EVENT_VERSION`, `AUTH_EPOCH`, 10 native paths; V5 HTTP 503 по последнему документированному baseline.

## 2026-10-14 — confirmed on production: both V4 phone anchors belong to PHP comments

Оператор фактически выполнил последнюю source-only диагностику **whole-file** `token_get_all($source)` на `evrasia`. Пинованный SHA `local/php_interface/Controllers/V4/Auth.php = 72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` совпал. `PASS_COUNT=4`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `TOKENIZER_LOAD_MODE=EXPLICIT_ROOT_OWNED_TOKENIZER_SO`, `PHP_INI_LOADED=NO`, `BITRIX_BOOTSTRAP=NO`, `DATABASE_CONNECTION=NO`, `DATABASE_WRITE=NO`, `SMS_SENT=NO`, `PRODUCTION_APPLICATION_WRITE=NO`.

**Критический факт с настоящим PHP lexical state:**
- `SOURCE_TEXT_ANCHORS_VALID=YES` — текст в файле присутствует, но это само по себе не PHP-код.
- `SOURCE_ANCHOR_891_LEXICAL_KIND=COMMENT` и `SOURCE_ANCHOR_1012_LEXICAL_KIND=COMMENT`; `BOTH_SOURCE_ANCHORS_ACTIVE_PHP=NO`.
- `SOURCE_LINE_880`, `SOURCE_LINE_889..896`, `SOURCE_LINE_1009..1014` — `LEXICAL_CLASS=COMMENT_ONLY`, `TOKEN_SHAPE=NONE`. `SOURCE_LINE_1015_LEXICAL_CLASS=NO_ACTIVE_TOKENS`.
- `COMMENT_OR_INLINE_ANCHOR_NEEDS_REVIEW=YES`, `ACTUAL_DB_PERSISTENCE_PROVEN=NO`.

**Переоценка ранее принятого (БЫЛО → СТАЛО → ПРИЧИНА):** `Auth::confirm_request` line 891 `$phoneuser['CONFIRMED'] = ...`, `Auth::confirm` line 1012, `UserPhoneAuthTable::getList/add/getRowById` lines 869/876/880 и `CUser->Update` line 851/1026 считались кандидатами на реальные операции. **СТАЛО:** по крайней мере названные проверенные source ranges **закомментированы**, а потому **не являются исполняемыми PHP-вызовами**. Ранее regex/row-focused анализаторы находили текст даже внутри комментариев и давали локальный `PASS` за корректность совпадений; это не доказывало активность. Старые 5/5, 6/6, 5/5, 4/4, 4/4, 4/4 остаются честной историей source-text diagnostics, но **НЕ доказательством native phone mutation coverage**.

**Граница вывода:** не исследованы все другие участки `Auth.php`, `PhoneAuth.php`, runtime роутинг Bitrix или смежные контроллеры. Нельзя заключать, что весь V4 не работает или что в проекте нет другой действующей ветки подтверждения телефона. Нужно найти **настоящие T_FUNCTION / T_STRING call sites из полного PHP-токенизатора**, строго отдельно от комментариев. Не выдавать конфигурационные secrets, SQL/пользовательские номера, исходный текст, не выполнять Bitrix. На production целевой активный путь ещё НЕ обнаружен.

**Следующий этап:** сначала узкий source-only lexical inventory именно активных методов/вызовов в pinned V4 `Auth.php` и `PhoneAuth.php` с `token_get_all` на полном файле; затем, по фактическому результату, исследовать только активную ветку V4. Реальная atomic phone version + `AUTH_EPOCH` всё ещё требует отдельно изолированного Bitrix/MySQL staging; нет native writer proof, нет 10-path proof, V5 HTTP 503 по последнему документированному baseline.
