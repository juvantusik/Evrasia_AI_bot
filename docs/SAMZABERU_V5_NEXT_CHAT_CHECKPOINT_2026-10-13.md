# Evrasia / СамЗаберу API V5 — актуальная точка продолжения 2026-10-13

## АКТУАЛЬНО 2026-10-14: оба V4 телефонных якоря — PHP COMMENTS; следующий active-code inventory

**Последний принятый stdout оператора:** на production host `evrasia` read-only whole-original-file PHP tokenizer с root-owned `tokenizer.so`. Exact pinned SHA V4 `Controllers/V4/Auth.php = 72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`; `PASS_COUNT=4`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. `SOURCE_ANCHOR_891_LEXICAL_KIND=COMMENT`, `SOURCE_ANCHOR_1012_LEXICAL_KIND=COMMENT`, **`BOTH_SOURCE_ANCHORS_ACTIVE_PHP=NO`**. All probed lines 880, 889–896, 1009–1014 = `COMMENT_ONLY`, token shape NONE, line 1015 = `NO_ACTIVE_TOKENS`. Source-only; no Bitrix bootstrap/DB/SMS/config/user values/production writes.

**ГЛАВНАЯ КОРРЕКЦИЯ ПРОЕКТНОГО КОНТЕКСТА (БЫЛО → СТАЛО → ПОЧЕМУ):** ранее многочисленные текстовые grep/AWK методы описывали `Auth::confirm_request` около 869/876/880/891, `Auth::confirm` 1012 и `CUser->Update` в соседних интервалах как возможный **действующий** путь подтверждения телефона. **СТАЛО:** эти проверенные snippets — PHP-комментарии; они НЕ могут быть активными обработчиками PHP на указанных строках. Старые аудиты и выводы о «кандидатах на write» — исключительно исторические `SOURCE_TEXT_ONLY`, для release gate не подходят. Настоящий выполняемый обработчик phone verification **НЕ НАЙДЕН**. Не делать из этого вывод, что весь V4 не работает; другие реальные методы/интеграции ещё не проверены.

**Один следующий шаг оператора:** запустить подготовленную новую feature-only read-only диагностику `site-integration/samzaberu-v5/tests/diagnostics/v5_active_v4_phone_lexical_map_readonly.sh` с `V5ActiveV4PhoneLexicalMap.php`. Два точных source SHA: `Auth.php=72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`, `PhoneAuth.php=a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27`. Системный tokenizer на `evrasia` уже проверен; парсится **полный** PHP, печатаются только активно объявленные белосписочные auth методы, live `UserPhoneAuthTable::` calls/CONFIRMED refs и отдельно текстовые попадания внутри комментариев. Original PHP/значения не печатать. Этот скрипт **на production ещё НЕ запускали**. При FAIL анализировать отказ, не возвращаться к старым V4 комментариям.

**Тесты самого алгоритма:** [GitHub Actions 38544913782](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38544913782) `completed/success`; реальный PHP tokenizer synthetic active class/phone call positives, commented legacy negative, sensitive canary suppressed, host refusal, 22 approved V5 offline suites PASS. Это не тест actual production source.

**ЗАПРЕТ НА ПОВТОРЫ:** MFA 9/9+6/6+7/7, phone metadata 5/5, 6/6, 5/5, 4/4, 4/4, 4/4 и whole-file lexical 4/4 закрыты. Не возвращаться к `Auth.php` строкам 869–1026 как доказанным working writers; сначала новый verified active-method map. Native `PHONE_EVENT_VERSION`/same-number reconfirm/`AUTH_EPOCH` 10-path transaction доказаны НЕ БЫЛИ, isolated Bitrix+MySQL staging не аттестован. `V5_HTTP=503` — последнее документированное состояние. V4/production не менялись.

---

## 2026-10-14 — tokenizer module работает, но COMMENT_MASKED 16/16 не является исходным кодом

Последний операторский пакет содержит **ДВА запуска** на `evrasia`: (1) старый `php -n` preflight **2 PASS / 1 FAIL** из-за отсутствия `token_get_all`; (2) исправленный read-only wrapper **`PASS_COUNT=4`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`**, `TOKENIZER_LOAD_MODE=EXPLICIT_ROOT_OWNED_TOKENIZER_SO`, PHP ini не загружен, exact SHA `Auth.php=72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` совпал. Реально подтвердили, что на production есть и успешно загружается root-owned отдельный `tokenizer.so`. Нет Bitrix bootstrap/DB/SMS/production changes/PII.

**Критическое недоказанное:** все **16/16** строки (`SOURCE_LINE_880`, 889–896, 1009–1014) предыдущий lexer вывел **только `COMMENT_MASKED`**, т.е. конкретные выражения `CONFIRMED` так и НЕ классифицированы как active code. Причина может быть реальное комментирование целевого фрагмента либо **ошибочная лексическая трактовка из-за отдельно склеенного `<?php ` для каждой исходной строки**. Старые shell regex искали исходный текст без проверки PHP lexical comment boundaries; нельзя утверждать, что найденные `$phoneuser['CONFIRMED']` и CUser calls исполняются.

**Новый единственный подготовленный read-only шаг в feature (ЕЩЁ НЕ РАН НА PRODUCTION):** Сохранён и исправлен `site-integration/samzaberu-v5/tests/diagnostics/V5PhoneConfirmedExpressionTokens.php`: теперь использует настоящий `token_get_all($source)` для **всего оригинального PHP-файла** и точные байтовые позиции якорей строки 891 и 1012. Печатает `SOURCE_ANCHOR_891_LEXICAL_KIND`, `SOURCE_ANCHOR_1012_LEXICAL_KIND`, `BOTH_SOURCE_ANCHORS_ACTIVE_PHP`, строковые `LEXICAL_CLASS` и только маскированные `TOKEN_SHAPE` без исходного PHP/literal values. Это позволит различить `PHP_CODE`, `COMMENT`, `INLINE_HTML`. Guarded `v5_phone_confirmed_expression_tokens_readonly.sh` с уже production proven `TOKENIZER_LOAD_MODE=EXPLICIT_ROOT_OWNED_TOKENIZER_SO` остаётся тем же. Для copy-paste требуется один wrapper, создающий точную новую версию PHP parser через heredoc, а не повтор старого разбиения по строкам.

[GitHub Actions 38528408976](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38528408976) **SUCCESS** после обновления parser и тестов: active PHP positive, changed-source-anchor negative, one-line `//` comments, spanning multiline `/*...*/` comments и PHP+comment на одной строке, parser no value leaks, 22 approved offline suites + PHP lint. CI **не знает**, является ли реальный production код комментарием.

Интерпретировать будущий stdout по `SOURCE_ANCHOR_891_LEXICAL_KIND`, `SOURCE_ANCHOR_1012_LEXICAL_KIND`, `BOTH_SOURCE_ANCHORS_ACTIVE_PHP`, line classes/shapes, `FINAL_STATUS`. При COMMENT/INLINE_HTML не выдавать текстовые match за работающий `CONFIRMED` event. Все предыдущие MFA and phone source checks закрыты; не повторять. Нет проверенного Bitrix/MySQL staging, 10-path/phone version/epoch native transaction proof; V5 HTTP 503 — последний документированный baseline, V4/prod unchanged.

---

## 2026-10-14 — последний production запуск PHP lexical probe FAILED до парсинга

Оператор (production `evrasia`) выполнил предыдущий one-block `v5_phone_confirmed_expression_tokens_readonly.sh`. **Не выдавать за успех:** `FINAL_STATUS=FAIL`, `PASS_COUNT=2`, `FAIL_COUNT=1`, `FINAL_RC=1`. Guards hostname/root, checksum real `local/php_interface/Controllers/V4/Auth.php` SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`, PHP tokenizer-file lint PASS. Аварийная остановка на `=== 3. PHP TOKENIZER AVAILABILITY ===`: **`php -n` не предоставляет `token_get_all()`**. `PHP_TOKENIZER_EXECUTED=NO`, никакого вывода `SOURCE_LINE_891/1012_TOKEN_SHAPE` или source analysis. Данные пользователей, PHP site code, Bitrix bootstrap, SQL, SMS, production writes не затронуты. Это инструментальный отказ PHP CLI extension, не функциональный отказ V4/Bitrix.

**Следующее одно безопасное действие:** в feature обновлён `tests/diagnostics/v5_phone_confirmed_expression_tokens_readonly.sh` — при отсутствии встроенного tokenizer делает только `php -n -r 'echo ini_get("extension_dir")'` (php.ini по-прежнему отключён), разрешает root-owned non-symlink `tokenizer.so` строго из `/usr/lib/php/*`, затем запускает `php -n -d extension=<trusted tokenizer.so>` и проверяет наличие функции до анализа. Если shared extension отсутствует или не загрузится, безопасный `FINAL_STATUS=FAIL`, дальнейшая работа — разбор конкретного stdout без запуска другого PHP runtime. **Работоспособность shared tokenizer на production пока не подтверждена.**

Предыдущий GitHub CI run [38525901881](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38525901881) `SUCCESS` в isolated PHP container: source token positive/negative fake tests, 22 offline suites; это не доказывает доступности `tokenizer.so` именно на `evrasia` (в CI tokenizer был доступен при `-n`).

Зафиксированная до отказа цепочка не менялась: `Auth::confirm_request` line 891 `$phoneuser['CONFIRMED']` RHS `OTHER_OR_MULTILINE`, `Auth::confirm` line 1012 `OTHER_REFERENCE`, прямых phone-table mutators в ограниченных диапазонах не увидели, но не доказан реальный phone DB write. Ни один из предыдущих MFA/phone audits **не повторять**. Нет проверенного Bitrix/MySQL staging и native 10-path transaction proof; `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, `SAME_PHONE_RECONFIRM_ATOMICITY=NOT_PROVEN`, V5 HTTP 503 — последний документированный baseline. Production/V4 не менялись.

---

## Новейший операторский результат: native phone semantic boundary 4/4 PASS

Оператор фактически запустил на production `evrasia` `v5_phone_confirmation_semantic_boundary_readonly.sh`. SHA `Auth.php=72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` совпал, `PASS_COUNT=4`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `SOURCE_ANCHORS_VALID=YES`. Никаких SQL, Bitrix bootstrap, SMS, пользовательских/секретных данных и production writes.

**Новые source-only факты:** `PHONEUSER_CONFIRMED_ASSIGNMENT_VALUE=OTHER_OR_MULTILINE` на `Auth::confirm_request` line **891** (это **не** сразу литерал `Y`/`N`, но конкретное выражение неизвестно). `ROW_READ_ASSIGNED_TO_PHONEUSER_SAME_LINE=NOT_PROVEN` на **880**. `Auth::confirm` line **1012** `CONFIRM_1012_FIELD_KIND=OTHER_REFERENCE`, `CONFIRM_1012_HAS_Y_LITERAL=NO`, `CONFIRM_1012_HAS_N_LITERAL=NO`; классификация неизвестна, **не** отсутствие проверки. `PHONEUSER_DIRECT_REFS_AFTER_891=0`, `REQUEST_AFTER_891_NATIVE_PHONE_TABLE_MUTATOR_CALLS=0`, `CONFIRM_NATIVE_PHONE_TABLE_MUTATOR_CALLS=0` — только **прямые статические совпадения** в ограниченных диапазонах. `CONFIRM_INSTANCE_MUTATOR_LINE=1026` — уже известный `$obuser->Update` на CUser. Нельзя выводить физическое сохранение `CONFIRMED=Y`, atomicity или same E.164 repeat event.

**СЛЕДУЮЩАЯ ОДНА source-only команда для оператора:** в feature подготовлен `site-integration/samzaberu-v5/tests/diagnostics/V5PhoneConfirmedExpressionTokens.php` плюс guarded `v5_phone_confirmed_expression_tokens_readonly.sh` (**ещё НЕ выполнен на `evrasia`**). После pinned SHA и host guard запускает только `php -n` встроенный `token_get_all` без include/eval/Bitrix bootstrap, печатает **только маскированную структуру** 16 фиксированных строк вокруг 891 и 1012 (класс токена и whitelisted `CONFIRMED`/`Y`/`N`/`$phoneuser`; нет реальных литералов/значений). Его положительный и два отрицательных synthetic tests, no secret leakage и wrong-host denial подтверждены [GitHub Actions run 38436298620](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38436298620), `completed/success` (22/22 approved PHP offline suites, PHP lint). Не путать с реальным Bitrix runtime.

Не повторять уже закрытые MFA source audits 9/9+6/6+7/7 и phone source audits inventory 5/5, call order 6/6, flow 5/5, update receiver 4/4, array lineage corrected 4/4, semantic boundary 4/4. Нет отдельного проверенного Bitrix staging, native hooks/epoch/same-phone version bump не реализованы/не доказаны. Последний документированный V5 HTTP baseline = 503, V4/production unchanged.

---

## АКТУАЛЬНЫЙ операторский результат: исправленная CONFIRMED lineage 4/4 PASS

Оператор реально выполнил на production `evrasia` исправленный shell+`awk -f` source-only `v5_phone_confirmed_array_lineage_readonly.sh`: `PASS_COUNT=4`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `SOURCE_ANCHORS_VALID=YES`. SHA проверенного `local/php_interface/Controllers/V4/Auth.php` — `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`. Bitrix, SQL, SMS, конфигурация и реальные данные пользователей не трогались. Предыдущий AWK-syntax FAIL исправлен, **повторно не проверять**.

Критический установленный source-факт: **`$phoneuser['CONFIRMED'] = ...` в `Auth::confirm_request()` на строке 891**. `CONFIRMED_ARRAY_VARIABLE=$phoneuser`, `CONFIRMED_RECEIVER_REUSES_AFTER_891=0` (только прямое текстовое использование на строках 892–938, не реальное runtime absence). `UserPhoneAuthTable::getList` 869, `::add` 876 и `::getRowById` 880 идут РАНЬШЕ присвоения на 891. `Auth::confirm()` на строке 1012 имеет `CONFIRMED` reference, но переменная владельца этого выражения не определена (`CONFIRM_METHOD_CONFIRMED_RECEIVER=UNRESOLVED`). Пять class call markers включают `cuser::generatephonecode` 901, `usertable::getlist` 992, `Auth::confirm()` instance mutation 1026 (ранее receiver `$obuser` — CUser). **Прямой записи в БД `CONFIRMED=N→Y` и PHONE_EVENT_VERSION bump этот результат не доказывает.**

**Текущий следующий узкий шаг:** создана в feature, ещё **НЕ выполнялась на production**, `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_confirmation_semantic_boundary_readonly.sh` вместе с `v5_phone_confirmation_semantic_boundary.awk`. Использует тот же pinned SHA, отмечает классификацию RHS для `$phoneuser['CONFIRMED']` на строке 891 (`LITERAL_N`, `LITERAL_Y`, `OTHER_OR_MULTILINE`), классификацию `CONFIRMED` reference на 1012 и scoped native phone-table mutator call counts после 891 и в confirm, без вывода исходных строк или значений телефонов/SMS. Только source-metadata, не физическая транзакция. Два предыдущих source-аудита 4/4 и все MFA/phone инвентаризации не повторять.

Перед подготовкой пользователю диагностического блока положительные и отрицательные **реальные AWK исполнения на синтетических PHP-строках** прошли [GitHub Actions run 38434850663](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38434850663) `completed/success` на commit `c7d26fc2e649759ec387833ff35886b7ecfe69fb`: N/Y literal classification, tampered 891/1012 rejection, wrong-host denial, 48 PHP lint, 22 offline suites, 9 audit shell lints + selftests.

**Неактивное состояние неизменно:** `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, `SAME_PHONE_RECONFIRM_ATOMICITY=NOT_PROVEN`, native shared DB transaction / 10-path mutation coverage NOT PROVEN, отдельного проверенного Bitrix+MySQL staging НЕТ; `V5_HTTP=503` — последний документированный baseline. Production/V4/SMS/DB не менялись; source-only аудит не является E2E runtime proof.

---

## АКТУАЛЬНО: phone array lineage — первый запуск FAIL из-за AWK, исправление offline CI PASS

Поступил и разобран полный stdout **последнего** production read-only `v5_phone_confirmed_array_lineage_readonly.sh` на `evrasia`. **Это FAIL, не PASS**: `PASS_COUNT=2`, `FAIL_COUNT=1`, `FINAL_STATUS=FAIL`, `FINAL_RC=1`. Host guard и проверка ранее pinned V4 `Auth.php` SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` прошли. На AWK `emit(` с переносом сразу после открывающей скобки пришло `unexpected newline or end of string` (cmd line 79), поэтому анализ `CONFIRMED_ARRAY_VARIABLE` **НЕ состоялся**. Никаких Bitrix bootstrap, DB, SMS или production writes. Не трактовать FAIL как сбой функционала.

**Только GitHub feature исправлен диагностический парсер**: `tests/diagnostics/v5_phone_confirmed_array_lineage.awk` — отдельный POSIX AWK файл; wrapper `tests/diagnostics/v5_phone_confirmed_array_lineage_readonly.sh` теперь вызывает `awk -f`; CI selftest `tests/diagnostics/V5PhoneConfirmedArrayLineageAwkSelfTest.sh` выполняет именно этот AWK на **синтетической** 1040-строчной фикстуре с правильными 869/876/880/891/1012 и двумя отклонениями, все положительные и отрицательные сценарии PASS. Проверен [GitHub Actions 38425322406](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38425322406) `completed/success`: POSIX awk execution positive + two malformed anchors negative, 48 PHP lint, 22 offline suites, wrong-host denial. Предыдущие CI-checks только lint bash и wrong-host; недостающий позитивный AWK тест теперь добавлен. **На настоящем `Auth.php` исправленный код ещё НЕ запускали**.

**Следующий ответ пользователя ожидается от исправленного ОДНОГО wrapper-командного блока**, генерирующего локальный temporary AWK через heredoc и проверяющего точный SHA исходника. Из вывода интересуют только `SOURCE_ANCHORS_VALID`, `CONFIRMED_ARRAY_VARIABLE`, `CONFIRMED_RECEIVER_REUSES_AFTER_891`, `FINAL_STATUS`, без повторения других проверок.

Уже завершено и не повторять: MFA source checks 9/9+6/6+7/7, phone inventory 5/5, phone call-order 6/6, phone flow 5/5, receiver 4/4; сбой был **только** в последнем parser. Без isolated Bitrix/MySQL staging и native 10-path proof сохраняются `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, `SAME_PHONE_RECONFIRM_ATOMICITY=NOT_PROVEN`, `V5_HTTP=503` по последнему baseline. Никаких production/V4 изменений.

---

## Предыдущая принятая диагностика: PHONE update receiver 4/4 → CONFIRMED array lineage

Оператор выполнил на `evrasia` `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_update_receiver_readonly.sh`, предоставил stdout: **PASS_COUNT=4, FAIL_COUNT=0, FINAL_STATUS=PASS, FINAL_RC=0**; pinned `Controllers/V4/Auth.php` SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362` совпал. Не было Bitrix bootstrap/SQL/SMS/изменений production, PHP-source/значения не печатались.

Установлены синтаксические receiver для всех 3 известных `->update`: **`$obuser`** в `Auth::confirmation()` line **137**, `Auth::confirm_request()` **851**, `Auth::confirm()` **1026**. В каждом методе рядом найден `new CUser` (136, 835, 1025) и обращение к `ACTIVE` (138, 852, 1027). Значит это **CUser update ветки**, а не прямые `UserPhoneAuthTable::update`; тип и состав аргументов полностью не печатались. Самостоятельно от них в `confirm_request` на строке **891** есть **`$ARRAY['CONFIRMED']=...`** (имя конкретного array receiver пока неизвестно); `UserPhoneAuthTable::getList` **869**, `::add` **876**, `::getRowById` **880**. `Auth::confirm()` reference к `CONFIRMED` **1012**. Запись PHP array НЕ доказывает персистентное `CONFIRMED=Y`, общий `PHONE_EVENT_VERSION` CAS и single-event reconfirm того же E.164.

**СЛЕДУЮЩИЙ и ТОЛЬКО следующий операторский шаг:** `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_confirmed_array_lineage_readonly.sh` (есть только в feature branch, **ещё не запускался на `evrasia`**). Скрипт проверяет pinned SHA `Auth.php`, exact anchors 869/876/880/891/1012, сообщает только `CONFIRMED_ARRAY_VARIABLE`, символические ссылки на использование переменной после строки 891 и whitelist class/method tags. Нет PHP statements, values/phone/SMS, config, Bitrix bootstrap или SQL. При `FAIL` исследовать причину, **не перепроверять** предыдущие 4 аудита и MFA.

GitHub CI [38398655750](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38398655750) `completed/success`: **8 Bash lints, 48 PHP files lint, 22 offline suites PASS, wrong-host denial** нового диагностического скрипта. Это НЕ реальный Bitrix proof.

**Текущие незакрытые gates:** `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, native same-phone reconfirm revocation не доказана, нет независимого Bitrix/MySQL staging и общего 10-path `AUTH_EPOCH` atomicity. В V4 и production не вносились изменения, HTTP V5 503 — **последний документированный baseline**, а не новый runtime-check. Предыдущие MFA source audits 9/9+6/6+7/7, phone inventory 5/5, call order 6/6, flow 5/5, receiver 4/4 **ЗАВЕРШЕНЫ и не требуют повторного запуска**.

---

## Предыдущий закрытый этап: PHONE flow 5/5 → update receiver

Оператор прислал stdout `v5_phone_confirmation_transition_flow_readonly.sh` с production `evrasia`. **5/5 PASS**, SHA pinned `Controllers/V4/Auth.php` и `lib/PhoneAuth.php` совпали; SQL/Bitrix bootstrap/SMS/production writes = NO. Новый подтверждённый source факт: `Auth::confirmation()->update` **строка 137**, `Auth::confirm_request()->update` **851**, `Auth::confirm()->update` **1026**. Последний имеет reference к `CONFIRMED` в строке **1012**, `confirm_request` — **845/873/891/896**. Другие источники ранее показали `UserPhoneAuthTable::getList` 869, `::add` 876, `::getRowById` 880 и нативную `UserPhoneAuthTable.php` SHA `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991`. Этого НЕ достаточно, чтобы утверждать, какой update меняет поле `CONFIRMED` или используется при reconfirm того же номера.

**Следующая команда:** подготовлен в **feature-only** `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_update_receiver_readonly.sh`; точный static SHA для `Auth.php=72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`; script prints only whitelisted metadata and receiver PHP variable identifiers around известных update lines 137/851/1026. Принимаем stdout только после запуска оператором на `evrasia` под root. При `FAIL` сначала разобрать причины, а не запускать повторный ORM/MFA скан. Скрипт еще **НЕ запускался на production**.

**CI подтверждён:** GitHub Actions [38396960091](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38396960091) `completed/success` по commit `a34cda2a295a5ab31fabd72f6e82e5ba9a4d0a2e`; **7 Bash lints, 48 PHP lint, 22 offline suite, guarded wrong-host denial**. CI — не real Bitrix source-flow execution/transaction proof.

Завершённые шаги **не повторять**: MFA/recovery source 9/9, 6/6, 7/7; phone inventory 5/5, phone call-order 6/6, phone flow 5/5. Документ: `docs/SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md` (feature). Истина release-гейта неизменна: `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, same E.164 reconfirm bump не доказан, native 10-path coverage и изолированный staging Bitrix/MySQL **не подтверждены**. V5 HTTP 503 по последнему документированному baseline, V4/production/SQL/SMS не изменялись.

---

## Ранее закрытый этап: phone call order 6/6 SOURCE VERIFIED

**Фактически последний операторский результат:** `v5_phone_confirmation_order_readonly.sh`, запущен на `evrasia`, `FINAL_STATUS=PASS`, `PASS_COUNT=6`, `FAIL_COUNT=0`, SHA всех 3 PHP source совпали; режим строго read-only, Bitrix не загружался, MySQL/SMS/production writes не было.

Метаданные `Auth.php` (реальный production source SHA `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`): `signup` uses phone-confirm helper (311/334/339), `UserPhoneAuthTable::add` (372). `confirm_request` has `CONFIRMED` refs (845/873/891/896), native `UserPhoneAuthTable::getList` (869), `::add` (876), `::getRowById` (880). `confirm` starts at 954 and references `CONFIRMED` at 1012, **no direct static UserPhoneAuthTable writer in the narrow classifier**. Native `UserPhoneAuthTable.php` SHA `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991` callbacks `onBeforeAdd/onBeforeUpdate` (96/101) delegate to `modifyFields` (98/103) and normalize `PHONE_NUMBER` (112/115). `V4_PHONE_TABLE_STATIC_CALLS=4`, `V4_CONFIRMED_REFERENCES=5`, no inline literal Y assignment candidate; absence of matching regex **не доказывает** отсутствие dynamic/multiline `CONFIRMED` write.

**Новая следующая команда только для read-only static inspection:** `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_confirmation_transition_flow_readonly.sh` в feature. Она ещё **не запускалась на production**. Строгие source SHA V4, вывод только line/method/control-flow tokens и безопасных `->save/->update/->set` method identifiers (никаких original source lines, аргументов, телефонов, SMS). От пользователя следующим ожидается её stdout; разбирать именно его, не повторяя MFA 9/6/7 и phone inventory 5/6. CI [run 38393921083](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38393921083) **SUCCESS**: 6 bash lint, 48 PHP lint, 22 offline suite PASS, wrong-host denial; **не positive Bitrix runtime/DB**.

**До реального staging:** `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`, real repeated same-phone confirmation atomicity and `AUTH_EPOCH` shared transaction **NOT PROVEN**; `V5_HTTP=503` по последней документации, V4/production не менялись. [Подробный phone evidence doc](SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md) обновлён в feature, общий registry синхронизирован в обеих ветках. Физически изолированный Bitrix/MySQL staging остаётся отдельным обязательным барьером.


## Завершённый предыдущий этап: native PHONE 5/5 (заменён результатом 6/6 выше)

После завершения трёх MFA/recovery source-only аудитов (9/9, 6/6, 7/7) начата **параллельная** ещё не закрытая ветка V5 `PHONE_EVENT_VERSION` / подтверждение телефона. Оператор с `evrasia` предоставил stdout `v5_phone_event_writer_inventory_readonly.sh`: **5/5 PASS**, 0 FAIL, pinned V4 source SHA совпали, без Bitrix bootstrap/DB/доступа к пользовательским телефонам/секретам/SMS/production writes. Подтверждён native `bitrix/modules/main/lib/UserPhoneAuthTable.php` SHA `3f9c093ee81923870ef8305e1afe7b277364515995517f2136c25f33d86f4991`; `onBeforeAdd` (96), `onBeforeUpdate` (101), `modifyFields` (106), `normalizePhoneNumber` (123), `CONFIRMED` field reference (64). В действующем `Auth.php` найдены `confirm_request` (779), `confirm` (954), `signup` (263) и table/CONFIRMED references.

**Текущий ближайший ввод пользователя ожидается из НОВОГО** `site-integration/samzaberu-v5/tests/diagnostics/v5_phone_confirmation_order_readonly.sh` (GitHub-only, ещё не run на production). Он печатает строго source metadata с номерами строк для вызовов `UserPhoneAuthTable` / `CONFIRMED` без source/PII/config/DB/SMS; SHA трех файлов из verified inventory pin-guarded. Из полученного вывода установить порядок native phone writes, определить точно где обновляется поле `CONFIRMED`, включая повторное подтверждение того же E.164. Следующий узкий source-only аудит НЕ означает разрешение V5 или доказанное событие. Старый инвентаризационный скрипт 5/5 не повторять; MFA audits не повторять.

GitHub Actions [run 38392077308](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38392077308) `completed/success`: Bash -n пяти аудит-скриптов, 48 PHP lint, 22 offline suites; фейковая prod-среда отсекается до чтения исходников. Обновлён [doc phone native writers](SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md) (в feature) и одинаковый registry в обеих ветках. В production и V4 изменений нет. Изолированный Bitrix/MySQL staging всё ещё не подтверждён и остаётся независимым runtime-blocker. `PHONE_EVENT_VERSION` writer/10-path native coverage ещё НЕ установлены, `NATIVE_PHONE_EVENT_ATOMICITY_PROVEN=NO`, `V5_HTTP=503` по последнему документированному состоянию.


> **ACTUAL / SUPERSEDES 2026-10-12 V5 HANDOFF.** Этот checkpoint описывает рабочую ветку GitHub и проверенный read-only вывод сервера, а не production-релиз. Все старые ссылки на *не реализован* native transaction depth port описывают 2026-10-12 и уже устарели.

## 1. Состояние проекта

- Repo: `juvantusik/Evrasia_AI_bot`; код V5 — исключительно `feature/samzaberu-api-v5-auth-contract`. `main` — документация/история production; не мержить кандидата ради проверки.
- На production сайтах и боте изменений в рамках текущей ветки **не было**. V4 / Trusted Device / TOTP работают по последним отдельно подтверждённым production-фактам. V5 router остаётся с HTTP 503 по **последнему документированному** состоянию; новые HTTP-проверки в этом шаге не выполнялись.
- Реальной Bitrix staging VM/отдельной MySQL-БД с независимо доказанной изоляцией **не зарегистрировано**. Реальный Bitrix ORM↔native rollback, staging MFA/epoch concurrency, SMS sender и 10-path atomicity **не проводились**. В production запрещены любые изменения БД, внедрение хуков, SMS и V5-активация.
- GitHub Actions `.github/workflows/v5-offline-safety.yml`: последний проверенный run [38378187055](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38378187055), `success`; PHP 8.4.26; 48 PHP files lint PASS; все 22 offline suites PASS; три shell source diagnostics `bash -n` PASS; staging preflight и rollback entrypoints в негативном сценарии отказывают без bootstrap. **Это fake/offline проверки, не production/staging E2E.**
- Source-backed staging-only `V5VerifiedBitrixNativeTransactionDepth.php` (Reflection на `protected int $transactionLevel`, SHA-pinned Bitrix classes) уже реализован в feature, прошёл 18 синтетических checks; `V5StagingNativeTransactionPermit` 62/62 fake tests; `V5BitrixPdoCompatibilityBridge` 80/80 fake tests. **Нет реального production permit** и независимого native 10-path security coverage.

## 2. Последний ФАКТИЧЕСКИЙ вывод оператора

На `evrasia` оператор выполнил `site-integration/samzaberu-v5/tests/diagnostics/v5_native_mfa_writer_order_readonly.sh`; результат `MODE=READ_ONLY`, `PASS_COUNT=9`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. Все **шесть** pinned SHA исходников Bitrix совпали; `DATABASE_CONNECTION=NO`, `BITRIX_BOOTSTRAP=NO`, `PRODUCTION_APPLICATION_WRITE=NO`.

Подтверждённый native source order:
- `Otp::save()`: `RecoveryCodesTable::clearByUser()` при regenerated (стр. 370) **до** `UserTable::add` (374), затем cache invalidation. `Otp::delete()`: `UserTable::delete` (390).
- Background `OtpEvents::onRecheckDeactivate()`: `UserTable::update` (30/34), отдельный writer; `CSecurityUser::update()` может вызвать OTP deactivate/delete/regenerate/save; `onAfterUserUpdate` — только MFA cache invalidation.
- Native `DataManager` add: before-event 906, on-event 939, `strategy->add` 956, after-event 1015. Update: before 1277/1306, actual SQL 1337, after 1362. Delete: before 1665/1674, actual SQL 1691, after 1702.
- `Merge` strategy invokes `prepareMerge` and `Helper::executeAndGetInsertedId` (89-100). Это **только статический порядок**, не доказательство общего BEGIN/COMMIT/ROLLBACK. Нельзя доверять `OnAfterUserUpdate` как атомарному MFA+AUTH_EPOCH или ставить 10-path coverage positive.
- Подробности в `docs/SAMZABERU_V5_NATIVE_MUTATION_COVERAGE_2026-10-12.md` и `docs/STRUCTURE_REGISTRY.md`, обновлены по фактическому выводу.

## 3. Второй ФАКТИЧЕСКИЙ production source-only аудит: recovery / merge — 6/6 PASS

На `evrasia` оператор запустил уже подготовленный `v5_native_recovery_merge_readonly.sh`, результат `PASS_COUNT=6`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `MODE=READ_ONLY`, `BITRIX_BOOTSTRAP=NO`, `DATABASE_CONNECTION=NO`, `PRODUCTION_APPLICATION_WRITE=NO`. Это **не runtime/DB proof**.

Факты: native `Bitrix\Security\Mfa\UserTable` (таблица `b_sec_user`) использует `MergeByDefaultTrait` SHA `30153b42a1a652b44ca70f2bb14335d08f970d0ebef8ca85e7f349f8ad29c198`. Он делегирует выбор стратегии `BaseMergeTrait::getCachedMergeStrategy()`, тело этого trait **ещё не изучено**.

`RecoveryCodesTable.php` SHA `a5ff49a123bd7f3864acfc1fee9010965a8d9d3775d774442de66d5667d5e357`: `clearByUser()` (84–101) выбирает ID recovery-кодов и удаляет каждый вызовом `static::delete($code['ID'])` в цикле, возвращая `true` без проверки returned delete result. `regenerateCodes()` (фрагмент 111–129) очищает старые и многократно добавляет новые отдельными `static::add()`; окончание метода не изучено.

Поскольку в уже проверенном `Otp::save()` `clearByUser()` вызывается **раньше** `UserTable::add()`, `UserTable::OnBeforeAdd` срабатывает слишком поздно, чтобы начинать транзакцию, охватывающую весь перевыпуск TOTP/recovery. Нужен owner scope **до удаления recovery**, общая блокировка `b_user`, epoch CAS и rollback на том же native DB handle. Это **техническое отрицательное ограничение**, НЕ доказательство реальной порчи recovery и НЕ подтверждение наличия такого owner scope. V5 остаётся закрытым.

## 4. ТРЕТИЙ ФАКТИЧЕСКИЙ production source-only аудит — 7/7 PASS

Оператор выполнил ранее подготовленный `v5_native_recovery_final_boundary_readonly.sh` на `evrasia`. Результат: `MODE=READ_ONLY`, `PASS_COUNT=7`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, три pinned SHA совпали, без Bitrix bootstrap, SQL и изменения production.

**Новые факты:**
- `UserTable::onAfterDelete()` (101–105) вызывает `RecoveryCodesTable::clearByUser(USER_ID)` **после** нативного SQL удаления MFA. Один `OnAfterDelete` не доказывает общую транзакцию.
- `RecoveryCodesTable::regenerateCodes()` (111–133) вызывает отдельные `static::add($code)` для десяти кодов и затем возвращает true, не проверяя каждую ORM Result.
- `RecoveryCodesTable::useCode()` (143–172) выбирает `USED=N`, вызывает `static::update(ID, USED=Y)`, не проверяет Result и устанавливает `found=true` при совпадении строки. Атомарного условного consume `USED=N -> Y` в проверенном нативном методе нет. При конкуренции возможны два успешных ответа, но **это гипотеза безопасности, НЕ production incident / E2E proof**.
- Впервые pinned `BaseMergeTrait.php` SHA `18deb72c645694c6378662056834f5c3dd0e409d8d3c271e4f49fd910c9ce5b8`: `getCachedMergeStrategy()` кэширует `new Merge(static::getEntity())`. Этот trait не является владельцем транзакции.

**Все три связанные source-only диагностики завершены:** 9/9, 6/6, 7/7 PASS. Их **НЕ повторять**. В коде Bitrix production ничего не менялось.

## 5. Принятое техническое ограничение и актуальная точка продолжения

Полный доказательный разбор и **предложенный, ещё не реализованный** atomic owner-scope:
[`SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md`](SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md).
Главное: для MFA secret replacement / delete / recovery требуется один транзакционный scope **до первых recovery операций**, общий `b_user FOR UPDATE` mutex, один настоящий native Bitrix `Application::getConnection()`, проверка результатов или postconditions, `AUTH_EPOCH` CAS и доказанный rollback. `OnBeforeAdd` начинается уже после `clearByUser`, `OnAfterDelete` начинается после native DELETE. Нельзя считать `useCode() === true` доказательством безопасного одноразового recovery-подтверждения для V5.

**Минимальное изменение кода только в GitHub feature**: расширены негативные `V5NativeMutationCoverageTest.php` для `OtpDeleteAndDisable` / `OtpRecoveryAndReset`. Действующий 10-path `V5NativeMutationCoverageMatrix` не менялся, дополнительный release gate НЕ создан. Проверенный [GitHub Actions run 38381811549](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38381811549) SUCCESS: **82/82 MFA coverage synthetic assertions**, **22/22 offline suite PASS**, 48 PHP lint PASS. Реальных MFA операций и БД тестов не было.

**Следующий обязательный шаг именно для реальных транзакционных испытаний MFA** — не новые MFA source-only аудиты на production, а независимое подтверждение/выделение изолированного Bitrix+MySQL staging согласно `docs/SAMZABERU_V5_ISOLATED_STAGING_READINESS_2026-10-13.md`. На нём только после инфраструктурных guards — ранее подготовленный read-only native depth/permit preflight; затем, после отдельного разрешения, synthetic ORM/native rollback, fault-injection для OTP/recovery и конкурентный `useCode` consume, а также доказательство всех десяти native mutation paths с `AUTH_EPOCH`. Нельзя подменять staging одиночными успешными offline checks.

`PRODUCTION_WRITE=NO` · `DB_WRITE=NO` · `SMS_SENT=NO` · `V5_HTTP=503` (последний документированный baseline) · `STAGING_BITRIX_ATTESTED=NO` · `NATIVE_MFA_ATOMICITY_PROVEN=NO`.
