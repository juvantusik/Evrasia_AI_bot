# /phonebook — canonical import ЮЛ: runbook

Дата: 13.09.2026
Jira: KAN-77
Статус: IN DEVELOPMENT / NO PRODUCTION IMPORT

## Назначение

Инструмент `scripts/legal-entity-canonical-import.mjs` сравнивает canonical identity set из `docs/LEGAL_ENTITY_IMPORT_REVIEW_2026-09-13.md` с `corporate_legal_entities`.

По умолчанию он работает только в `DRY_RUN` и не пишет в БД.

## Источник

Canonical review содержит 62 ЮЛ:

- 49 со статусом `OK`;
- 13 со статусом `NEEDS_REVIEW`;
- генеральный директор берётся из `Списки дир-ов от 06.04.26.xlsx`;
- две `Евразия-Триумф` различаются по ИНН, а не по названию.

## Matching

Главный ключ сопоставления — `ИНН`.

Запрещено:

- объединять ЮЛ только по названию;
- автоматически писать строки `NEEDS_REVIEW`;
- автоматически исправлять спорные КПП/ОГРН/банк/адрес.

Вторая `Евразия-Триумф` имеет в review display-суффикс `(М.222)`, но в master сохраняется реальное название `ООО "Евразия-Триумф"`; идентичность определяется ИНН `7810591971`.

## Dry-run

Из корня репозитория:

```bash
DATABASE_URL='...' pnpm --filter @workspace/scripts legal-entities:import
```

Вывод структурирован:

- `MATCH` — запись уже совпадает;
- `CREATE` — verified ЮЛ отсутствует и может быть создано;
- `UPDATE` — verified ЮЛ найдено по ИНН, но canonical поля отличаются;
- `SKIP` — `NEEDS_REVIEW` или отсутствует ИНН;
- `CONFLICT` — опасная неоднозначность, например дубли одного ИНН или inactive-match.

Dry-run всегда должен показывать:

```text
MODE=DRY_RUN
DATABASE_WRITE=NO
MATCH_KEY=INN
NAME_ONLY_MERGE=NO
NEEDS_REVIEW_AUTO_WRITE=NO
SECRET_VALUES_PRINTED=NO
```

## Write guard

Write mode существует только для будущего подтверждённого rollout и не должен запускаться без отдельного решения.

Для него одновременно требуются:

```text
--apply
LEGAL_ENTITY_IMPORT_APPLY=YES
LEGAL_ENTITY_IMPORT_CONFIRM=KAN-77
```

Кроме того, `CONFLICT_COUNT > 0` полностью блокирует запись.

Write mode обрабатывает только canonical строки со статусом `OK`. `NEEDS_REVIEW` автоматически не пишутся.

## Что пока не импортируется этим инструментом

На текущем этапе инструмент работает только с identity/core полями master ЮЛ:

- name;
- INN;
- KPP;
- OGRN;
- general_director;
- source;
- verification_status.

Банковские счета будут добавлены отдельным шагом после формирования структурированного canonical bank dataset. До этого спорные банковские комплекты (Манхеттен, ЛОТОС, Максимус СПБ и др.) не должны попадать в автоматический import.

Операторские лицевые счета на текущем этапе продолжают формироваться из существующего phonebook sync и не импортируются из старых реквизитных карточек.

## Production rule

Перед любым production write:

1. выполнить read-only dry-run;
2. сохранить полный вывод;
3. проверить `CREATE/UPDATE/SKIP/CONFLICT`;
4. отдельно разобрать каждый `CONFLICT`;
5. сделать backup PostgreSQL;
6. только после подтверждения выполнять write mode;
7. повторить dry-run после записи — ожидаемый результат для imported verified rows: `MATCH`.

Никакой production import на момент создания этого runbook не выполнялся.
