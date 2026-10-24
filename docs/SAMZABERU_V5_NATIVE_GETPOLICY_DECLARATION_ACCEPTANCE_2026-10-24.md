# СамЗаберу V5 — подтверждение декларации CUser::getPolicy, 24.10.2026

## Фактический ответ production root@evrasia (READ_ONLY)

Операторский `EV_V5_NATIVE_POLICY_NEXT` завершился `FINAL_STATUS=PASS`, `FINAL_RC=0`, `BITRIX_USER_SOURCE=PINNED_PASS`, `SOURCE_DECLARATION_SCAN=PASS`.

- Файл: `/home/site_evrasia/web/evrasia.spb.ru/public_html/bitrix/modules/main/classes/general/user.php`.
- Полный SHA256: `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99` — совпал с production.
- `CLASS=CUser`, `EXTENDS=CDBResult`.
- `getPolicy`: **единственное объявление**, строка **4615**, **PUBLIC=YES**, **STATIC=YES**.
- `CheckPasswordAgainstPolicy`: **единственное объявление**, строка **2581**, **PUBLIC=YES**, **STATIC=YES**.
- `bitrix/modules/main/classes/mysql/user.php`: **не существует** в этой установке.
- Bitrix bootstrap не выполнялся; база не читалась, аутентификация не запускалась; ни сайт, ни V4, ни V5 не менялись.

## Вывод для existing V5 code

Адаптер `src/V5BitrixNativeLoginPolicyLimits.php:V5PinnedBitrixLoginAttemptLimits` уже требует `CUser::getPolicy` с этим exact SHA, публичный static метод, объявленный в `CUser` и в том же `user.php`. **Текущая проверка декларации совпадает**, переписывать его и отключать guards не требуется.

`src/V5BitrixNativePasswordLoginAdmission.php` также ожидает `getPolicy` и `CheckPasswordAgainstPolicy` с тем же SHA; этот частный source guard подтверждён. Но это **не подтверждение**, что методы можно вызывать безопасно во всех контекстах: их реализация, ORM/роли/обработчики, текущее состояние блокировки и actual native event rules здесь не исследовались. Нет positive live-result V5 password/login E2E.

`V5BitrixNativeFirstFactorComposition::build()` по-прежнему требует отдельно предоставленный `V5CurrentLoginControlsPort`, который должен положительно подтвердить текущую временную блокировку, ограничения безопасности и правила сайта. Нет основания создавать фиктивный provider, считать `LOGIN_ATTEMPTS` достаточным или подставлять `true` вместо недоступных проверок.

## Дальнейшая точка продолжения

`NEXT_STEP=PINNED_LOGIN_INTERNAL_ADMISSION_BRANCH_MAP_THEN_REAL_SITE_CONTROLS`: узкий read-only разбор **только нативного CUser::LoginInternal / обработчиков допуска**, с source SHA guard, без вызова метода, Bitrix prolog, DB/SMS. Зафиксировать точные поля и зависимости для будущего `V5CurrentLoginControlsPort`. Если правила нельзя доказать read-only, держать `UNKNOWN → DENY`, V5 AUTH OFF и параллельно продвигать безопасные V5 business/DTO методы на реальных V4 сервисах.

Не путать website email+SMS browser acceptance с mobile V5 AUTH readiness. Работающий V4 не менять, mobile V5 production signin не включать, общие artificial SMS caps 100/час и 500/сутки не возвращать без решения владельца.
