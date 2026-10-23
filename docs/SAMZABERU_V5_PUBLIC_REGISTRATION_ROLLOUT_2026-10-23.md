# V5-only публичное обновление: юридические документы регистрации

**Назначение:** безопасно опубликовать реальные `GET /api/v5/legal/registration-documents` и `POST /api/v5/legal/consents/validate` без включения авторизации V5, без изменений V4, существующих пользователей и БД. Предыдущий script `upgrade-public-legal-v5.sh` НЕ запускать: он закреплён на старом V4-router SHA и проверяет лишь origin `evrasia.spb.ru`.

## Подготовлено

`site-integration/samzaberu-v5/deployment/upgrade-public-registration-documents-v5.sh` — отдельный V5-only guarded production installer, source commit `0a035f9603686d07643ecefadd3e4057c7fae34a`. Исходник нового **реального** маршрутизатора `api/v5/router.php` Git blob `93c02ff1483d7f49aff567ac7cda3f6a12578dc1`; валидатор текущих юридических кодов `src/V5PublicLegalCodeValidation.php` Git blob `1e6014a101483f8c04b03e6304fabd375e5cc6d8`. Вместо копирования V4 он обновляет два файла внутри НОВОЙ приватной V5 OFF-only release и переключает только существующий V5 публичный shim.

Guards: точный сервер `evrasia`, root, UID/GID `site_evrasia=1005`, canonical path, обычные/не symlink исходники, установленный SHA manifest, old CODE_COMMIT `0caa0248c783188939398a71543ba740f064bb90`, уже действующий V5 public router и его old RELEASE, отсутствие private auth/site-factory, root-owned lock; site `.htaccess`, `api/v4/.htaccess` и **актуальный наблюдавшийся** `api/v4/router.php` SHA `5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d`. Любое расхождение → **FAIL до записи в работающий API**; никогда не обновлять SHA автоматически.

Перед изменением: точный pin SHA источника из GitHub, резервная копия V5 public router/manifest в приватном `/root`, проверка копии; staging новой immutable release и PHP syntax/CLI smoke, отдельно проверяется `GET registration-documents` как отказ без MySQL PDO в `php -n`.

Изменение: атомарный `mv -Tf` только публичного V5 shim и V5 installed SHA manifest, старые release сохраняются. При любой ошибке **после переключения** выполняется условный откат предыдущих router/manifest при неизменном ожидаемом новом SHA; если concurrent-edit — rollback запрещается и нужен ручной разбор. Новую staging release при сбое не удаляем без точного подтверждения.

Postcheck: настоящий сертификат/TLS, HTTP status, JSON + no-store и запрет cookies/redirect/CORS. Проверить origin `evrasia.spb.ru` через `--resolve ... 192.168.103.141`: health 200 `auth_ready:false`, GET legal consents 200, GET registration-documents 200 **с идентичным списком текущих документов**, POST legal validate 200 на двух реальных required codes, POST auth/signin 503. Затем отдельно проверить **основной пользовательский домен `https://evrasia.rest`**: health 200 `auth_ready:false`, GET registration-documents 200 и полное равенство текущего списка документа origin. При любом несовпадении/TLS-ошибке попытаться rollback, не считать релиз успешно установленным. Никаких user/phone/email/PIN/token значений не печатается.

## Важные ограничения

1. **Скрипт не запущен в production на момент записи этой документации.** Не объявлять endpoint доступным разработчику до фактического положительного `FINAL_STATUS=PASS` и проверки, что это не только `ALREADY_APPLIED=YES`.
2. Скрипт рассчитан на **исходную** уже исследованную OFF-only release `0caa0248...`. Если уже применён предыдущий legal-upgrader или baseline успел измениться, script безопасно откажет; нельзя вычищать чужие release или произвольно обходить guard.
3. По явной воле пользователя V4 сохраняется **только временно** до полной миграции потребителей; V5 — один целевой API. Но этот выпуск публикует лишь read-only registration/legal, не выдает AUTH/JWT, не превращает GET cart в production и не переименовывает физический сайт.
4. CI `bash -n`, wrong-host refusal и остальные source-only PHP tests не заменяют реальный production HTTP E2E. Все текущие мобильные и серверные клиенты должны по-прежнему получать работу V4 до отдельного cutover.

**Документ интерфейса:** [SAMZABERU_V5_REGISTRATION_DOCUMENTS_2026-10-23.md](SAMZABERU_V5_REGISTRATION_DOCUMENTS_2026-10-23.md).

**NEXT_STEP:** проверить success CI pinned guarded installer, выполнить **ОДНУ** production operator команду по точному Git blob SHA и прислать вывод; затем признать/не признать текущий публичный endpoint в `evrasia.rest` по факту, не по CI. Аутентификация V5 remains OFF.
