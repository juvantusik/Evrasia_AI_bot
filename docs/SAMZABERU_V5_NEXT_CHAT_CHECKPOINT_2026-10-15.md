# СамЗаберу V5 — checkpoint private pilot bootstrap

15 октября 2026. Читать вместе с START HERE и integration decisions. Новая VM не требуется: пользователь выбрал V5 рядом с V4 на существующем `evrasia`.

## Последний подтверждённый код

**`0caa0248c783188939398a71543ba740f064bb90`**.

| Проверка | Результат |
|---|---|
| Offline run38750965561 / job116476933149 | SUCCESS; прочитан лог:86 PHP lint,26 approved suites,198 прежних entry и59 новых private-pilot assertions; FINAL_STATUS=PASS, FINAL_RC=0 |
| Ephemeral run38750966026 / job116476934579 | SUCCESS, все шаги completed. Неизменённый DB suite329; новые59 — не MariaDB checks. Новый полный DB лог не выгружался, состав329 ранее подтверждён логами и не менялся. |
| Локально | Четыре новых PHP файла прошли lint; большие blobs сверены с локальным Git SHA. Полный suite локально не запускался: GitHub DNS недоступен. |

Compare2bec823b..0caa0248: src/V5PrivatePilotConfig.php (+144), api/v5/site.php (+22), deployment/public-router.php (+31), deployment/site-config.example.json (+6), tests/V5PrivatePilotConfigTest.php (+165), RouteMapTest.php (+1). Рабочие transport/router/providers/V4/schema/workflows не менялись.

## Закрытый стык конфигурации

Private loader читает только site-config.json и фиксированный site-factory.php из release вне docroot. Нет включения файла из HTTP input. Только off/pilot; для pilot нужны SHA256 отдельного случайного ключа, срок до24часов, 1–16 точных peer IP, безопасные права/владелец/отсутствие ссылок. Private PHP factory не исполняется при чтении JSON. Перед её вызовом перепроверяются файлы, permissions и срок.

Public shim template → private api/v5/site.php → существующие router/entry/transport. Site entry удаляет старую global factory и pilot header перед native кодом. Off/invalid/denied остаются503 до body I/O/native bootstrap; допущенный запрос ещё проходит все прежние metadata/body/auth проверки. Empty transport в тесте не выпускает токены и остаётся503.

Pilot key допускает только запрос. Не является логином, TOTP, trust, native coverage или разрешением действий с аккаунтом. Ограничение на согласованные тестовые аккаунты должно быть реализовано native factory отдельно. Ключ не отправлять в URL/логи/репозиторий/публичную app-сборку; production key не генерировался.

Новые59tests включают permissions/symlink/hardlink/path/expiry/config-change/peer/TLS/header denials и независимые PHP-процессы с фактическим public shim/site/router. Это CLI, не реальный Apache/Nginx/TLS/Bitrix тест. Локальный отказной тест Bash-блока также не является запуском на evrasia.

## Следующий шаг, который уже подготовлен

В текущем чате дан один read-only installation-layout block для `evrasia`/root. Известны site owner `site_evrasia`, docroot `/home/site_evrasia/web/evrasia.spb.ru/public_html`, private parent из истории `/home/site_evrasia/web/evrasia.spb.ru/private`. Блок проверяет только точные пути, владельцев, entry/rewrite SHA и PHP/FPM параметры размещения. Сайт, БД и native PHP не меняет/не исполняет. **Ожидается вывод этого блока**, не новый пересказ истории и не адрес VM.

После вывода: точный план установки выключенного release+shim с backup/guards/rollback; V4/root rewrite не заменять. `site-factory.php` с настоящей native composition пока отсутствует. Активировать pilot только после native prerequisites и явного допуска. DDL/платные SMS/account mutations отдельно, без переноса synthetic CI schemas на production.

## История и остающиеся ограничения

Предыдущий code029aaff (run38748326281/38748326331) остаётся историческим entry checkpoint:198 offline +329 DB. После него doc-only2bec823b также запустил offline38749036057; это не новый код. Ещё раньше c3f92ba329,8c395f0268,dc2caed205,4a6ea09160. Старый неуспешный receipt fixture run не стирать.

Native TOTP-code/replay, successful phone verification/same-number event,10-path AUTH_EPOCH hooks,strong trust,SMS lifecycle/provider/receipt и RestIS не закрыты. Старый native-staging permit не отключён; нужен подходящий проверенный shared-site scope. Pending V5BitrixLockedTotpFactor не в src/CI; ранее заблокированный payload не повторялся.

Main baseline7c2e9f8e4501b92469bcdeea18f8340676ad52c9 не менялся. Новые doc-only HEAD не приравнивать к code-test commit; API README может запускать offline workflow по paths.

OFFLINE_TESTED=PASS; REAL_EPHEMERAL_MARIADB_PASS=YES(329); PRIVATE_PILOT_BOOTSTRAP_OFFLINE=PASS(59); REAL_SITE_PARALLEL_ACCEPTANCE=NOT_RUN; PRODUCTION_DEPLOYED=NO; ACTUAL_SMS_SENT=NO. HTTP503 — исторический baseline.
