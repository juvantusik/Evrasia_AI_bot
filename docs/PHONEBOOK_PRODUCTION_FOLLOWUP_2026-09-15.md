# /phonebook v1.8 — production follow-up 15.09.2026

Дата: 15.09.2026
Статус: **PRODUCTION / ACCEPTED, follow-up cleanup in progress**

## 1. Production runtime

Подтверждённый production host: `eur-bot-01`.

Текущий production application runtime после PR #50:

- revision: `1ed726927c5064198d769bb998597889c6ad07d1`;
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:f86c59983ef1857cf26b9763cd019d809ff821a8e2b01904c2b3b408f8f0eb5c`;
- app status: `running`;
- app health: `healthy`;
- restart count: `0`;
- DB health: `healthy`;
- migrations: `23`.

Deployment completed without rollback.

## 2. Delete UX decision

### Было

Удаление/архивирование строки реквизитов во вкладке `/phonebook -> Реквизиты` запрашивало `PHONEBOOK_WEB_WRITE_TOKEN` через editor prompt. Backend DELETE также проходил через общий `requireEditor()`.

### Стало

Для удаления/архивирования ЮЛ используется только обычное пользовательское подтверждение `Уверены, что хотите удалить?`.

Именно DELETE legal-entity больше не требует `PHONEBOOK_WEB_WRITE_TOKEN`.

Создание и редактирование записей остаются под существующей защитой; это изменение их не ослабляет.

### Причина

Пользователь явно не заказывал отдельный ключ для удаления. Для archive/delete UX достаточно подтверждения действия, а сам archive guard по активным связям остаётся защитой целостности данных.

## 3. Archive guard остаётся обязательным

ЮЛ нельзя архивировать, пока на него ссылаются активные строки:

- `corporate_phone_directory.legal_entity_id` с `active=true`;
- `corporate_directory_restaurants.legal_entity_id` с `active=true`.

Guard не снимается ради удобства. Сначала надо понять, является ли связь актуальной или stale legacy хвостом.

## 4. Stale phone cleanup rule

Принято operational rule:

Если телефонная строка:

1. существует в `corporate_phone_directory`;
2. не находится в доступных phone/source-like таблицах вне aggregate;
3. не используется в `corporate_directory_restaurants` через `actual_personal_phone` / `general_personal_phone`;
4. не имеет другой активной строки-владельца с тем же номером;

то такую строку можно считать stale legacy хвостом и **деактивировать (`active=false`)**, а не физически удалять.

Перед production write обязательны exact guards, backup, транзакция и post-check.

Важно: на текущем production PostgreSQL generic schema discovery обнаружил только следующие phone-like таблицы:

- `public.corporate_directory_restaurants`;
- `public.corporate_phone_audit`;
- `public.corporate_phone_directory`.

Отдельные production source tables с именами MegaFon/T2 в этой БД не обнаружены. Поэтому корректная формулировка проверки: **`NOT_FOUND_OUTSIDE_AGGREGATE` в доступных source/phone-like таблицах**, а не утверждение, что была отдельно опрошена внешняя система оператора.

## 5. Four stale legal entities cleanup

Для четырёх старых ЮЛ archive guard показывал активные номера, хотя пользователь не видел их в актуальном phonebook flow:

- `ООО "А-15 Новое Колпино` — 5 active phone rows;
- `ООО "Век"` — 5 active phone rows;
- `ООО "Евразия2008"` — 4 active phone rows;
- `ООО "Кайхон"` — 4 active phone rows.

Всего: **18 active phone rows**.

Проверено до изменения:

- все 18 номеров существуют только по одной строке в `corporate_phone_directory`;
- `rows_with_other_active_same_phone=0`;
- все 18 получили `NOT_FOUND_OUTSIDE_AGGREGATE`;
- direct restaurant phone matches = `0`;
- у четырёх ЮЛ active restaurant links = `0`.

Production action:

- физический DELETE не выполнялся;
- ровно 18 target rows переведены в `active=false`;
- transaction committed;
- backup создан и проверен:
  `/opt/evrasia-ai-bot/backups/stale-phone-retire/20260915-081631/evrasia_ai_bot.pre-stale-phone-retire.dump`.

Post-check:

- `UPDATED_ROWS=18`;
- у всех четырёх `active_phone_links=0`;
- у всех четырёх `active_restaurant_links=0`;
- app remained `healthy`.

После этого четыре ЮЛ освобождены от stale phone guard и могут архивироваться штатной кнопкой UI.

## 6. ООО «Евразия-Большевиков» legacy numbers

Отдельно обнаружено старое активное ЮЛ:

- ID: `le-e6748431e3df3ddd16c8a04c`;
- name: `ООО "Евразия-Большевиков"`;
- INN: `7811360046`.

У него было 7 active legacy T2 rows:

- `78129807115` — городской;
- `79112202077` — сотрудник, Гречко О.П.;
- `79500067741` — POS / internet SIM;
- `79516658528` — POS / internet SIM;
- `79523710518` — федеральный;
- `79533794763` — временный;
- `79534106865` — временный, Гречко О.П.

Перед cleanup подтверждено:

- exact active target count = `7`;
- все 7 `NOT_FOUND_OUTSIDE_AGGREGATE`;
- restaurant phone references = `0`;
- каждый номер имел ровно одну строку в phone directory и владельца `ООО "Евразия-Большевиков"`;
- пользователь дополнительно подтвердил, что Гречко О.П. давно переведена в `Евразия Южная`, и за Большевиков эти номера не числятся.

Пользователь после production script подтвердил: **«все ок, получилось»**.

Фиксируем результат как successful stale cleanup: 7 legacy rows деактивированы через `active=false`; история сохранена. Само ЮЛ физически не удалялось этим скриптом.

## 7. Important distinction: restaurant №28 «Большевиков 18»

Не путать с `ООО "Евразия-Большевиков"`.

В `corporate_directory_restaurants` существует отдельная запись:

- restaurant id/number: `28`;
- address: `Большевиков 18`;
- active: `true` на момент проверки;
- linked master: `ООО "Евразия-Манхеттен"`;
- legal_entity_id: `le-b534bcce3b02ace0d23bfac2`;
- INN master: `7805576103`.

Пользователь сообщил, что ресторан закрыт в мае 2026 года.

У `ООО "Евразия-Манхеттен"` на момент диагностики было 8 active phone rows. Они **не деактивировались** в cleanup семи legacy rows `ООО "Евразия-Большевиков"`.

Эти 8 строк и active restaurant №28 являются отдельной открытой задачей.

## 8. Open item / next continuation point

Следующий шаг по Phonebook:

1. отдельно разобрать закрытый ресторан №28 `Большевиков 18`;
2. определить, какие из 8 phone rows `ООО "Евразия-Манхеттен"` относятся именно к закрытому ресторану, а какие могут использоваться этим ЮЛ где-то ещё;
3. после фактической проверки закрыть/деактивировать только obsolete связи;
4. не переносить решение для старого `ООО "Евразия-Большевиков"` автоматически на `ООО "Евразия-Манхеттен"`.

До этой проверки 8 phone rows `Евразия-Манхеттен` не трогать.

## 9. Operational lessons

- archive guard может корректно блокировать UI даже для скрытого legacy мусора — сначала проверять фактические DB links;
- отсутствие номера в видимом UI не равно отсутствию активной строки в БД;
- stale cleanup делать через `active=false`, не физический DELETE;
- не смешивать старое ЮЛ и текущий restaurant owner только из-за слова `Большевиков`;
- source discovery должен опираться на фактическую схему БД, а не на предположение о названиях operator tables;
- production cleanup всегда: read-only investigation → exact target set → backup → transaction → post-check.
