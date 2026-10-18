# EVRASIA AI BOT — НОВЫЙ ЧАТ / CHECKPOINT 18.10.2026
## СамЗаберу: API V5, валидация JWT, публичные соглашения, регистрация с нативными согласиями

> **АКТУАЛЬНАЯ ТОЧКА ПРОДОЛЖЕНИЯ.** Этот документ новее предыдущих handoff от 12–15 октября. Не начинать проект заново и не повторять завершённые проверки TOTP→PIN, схемы `b_consent_agreement`, реальных кодов документов или поиска `Consent.php`.
>
> **ФАКТЫ НА МОМЕНТ HANDOFF:** репозиторий `juvantusik/Evrasia_AI_bot`, рабочая ветка `feature/samzaberu-api-v5-auth-contract`. Последний проверенный GitHub-code commit `3aac037c8dbbbc8b2e037372994113c0ae1160a9` — [offline V5 CI 39114444560](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39114444560), **SUCCESS, 30/30 suites**, включая 13 негативных проверок нового нативного signup-consent writer. После него на ветке были docs-only commits, последний увиденный перед этим handoff HEAD `9250e9617e650e47ee01ff45b5d8d3c6927e257b`; **в новом чате ОБЯЗАТЕЛЬНО перечитать текущий HEAD, diff и CI**, не принимать этот SHA за вечную истину. [Disposable MariaDB integration 39114359217](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39114359217) SUCCESS на более раннем code commit `b43186c9143d5786d2ebf0f3c4bb8ed30d4d463f` — не реальный тест Bitrix/CUser::Add.
>
> **Производственное состояние на основании последних подтверждённых операторских проверок:** хост `evrasia`, сайт `/home/site_evrasia/web/evrasia.spb.ru/public_html`, БД Bitrix `eurasia_new`; V4 работает; V5 HTTP/router остаётся **OFF / 503**, 6 таблиц V5 уже созданы (не повторять DDL), полноценная инициализация `AUTH_EPOCH` и 10-path native hook coverage не подтверждена. Production/site/V4/БД/SMS в текущем этапе не менялись.

### 1. Принятое решение пользователя (не возвращаться к V4)

Разработчик «СамЗаберу» запросил **«ручку валидации токена и ручку на согласие без привязки к токену»**. Пользователь 18 октября **явно выбрал делать обе сразу в API V5, не в V4**. Не предлагать снова промежуточную V4 реализацию.

- **`POST /api/v5/auth/token/validate`**: `{"access_token":"<V5_ACCESS_JWT>"}`, успешная V5 strong server session -> `200 {"valid":true}`; недействующий/отозванный/V4/refresh token -> `401 {"valid":false}`. Проверяются криптография, текущая сессия, пользователь, `AUTH_EPOCH`, отзыв; одной подписи JWT недостаточно. Staged feature implementation uses `V5MySqlTokenValidation` and existing strong-session port. **НЕ развернуто как живой URL**.
- **`GET /api/v5/legal/consents`**: публично, **без JWT**, без `USER_ID`, без cookie, read-only. Возвращает текущие серверные `code,type,title,required,format,content,url` для оферты/ПД/необязательного маркетинга. V5 feature implements strict `V5BitrixCurrentLegalCatalog`, reading native `b_consent_agreement`; actual source table/schema/current rows checked operator read-only. **Не развернуто как живой URL**.
- **Будущий `POST /api/v5/signup`**: приложение **до JWT** получает каталог, пользователь выбирает документы, передаёт **актуальные `agreements: [<codes>]`** при регистрации. Сервер заново проверяет редакции и обязательность, создаёт реальный Bitrix USER_ID и сохраняет через нативный `Bitrix\Main\UserConsent\Consent::addByContext`. Маркетинг только при отдельном явном согласии. **НЕТ анонимного `POST /legal/consents/accept` с чужим USER_ID**. Полного V5 signup HTTP маршрута, CUser::Add writer и приёмки сейчас **нет**.
- Не выдавать разработчику URL как уже работающие в production; передать согласованный контракт и точный статус. Полный документ: [SAMZABERU_V5_DEVELOPER_TOKEN_AND_PUBLIC_CONSENTS_2026-10-18.md](SAMZABERU_V5_DEVELOPER_TOKEN_AND_PUBLIC_CONSENTS_2026-10-18.md).

### 2. Доказанный реальный Bitrix: юридические документы (операторский READ_ONLY PASS)

18.10 оператор на production прочёл `eurasia_new.b_consent_agreement` под проверенным `START TRANSACTION READ ONLY`; `ROLLBACK=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, DB/site writes **NO**.

- `REQUIRED_COLUMNS=COMPLETE`, `ACTIVE_AGREEMENT_ROWS=3`, `ACTIVE_FAMILIES_UNIQUE=YES`, `FEATURE_CATALOG_MAPPING_COMPATIBLE=YES`.
- Фактические действующие коды: `EVRASIA_OFFER_20260911` (обязательно), `EVRASIA_PD_20260911` (обязательно), `EVRASIA_MARKETING_20260911` (необязательно). Их текущая совместимость проверена, **но не зашивать эти редакции и ID** в API или мобильное приложение.
- Во всех трёх: `ACTIVE=Y`, `USE_URL=Y`, `IS_AGREEMENT_TEXT_HTML=N`, `CONTENT_BYTES=0`, `TITLE_PRESENT=YES`, `CANONICAL_URL=YES`. Пустой `AGREEMENT_TEXT` штатен при `USE_URL=Y`.
- Для контекста native consent event storage: `b_consent_user_consent` (`ID, AGREEMENT_ID, USER_ID, ORIGINATOR_ID, ORIGIN_ID` и другие). Нельзя подменять фактические consent events через USER `UF_*`.
- Реальный текущий native catalog **по структуре совместим** с V5 feature adapter, **но этот PHP-класс ещё не исполнялся на production**. Не объявлять deployed/E2E.

### 3. Доказанный реальный Bitrix: метод и вызовы сохранения согласия (оператор READ_ONLY PASS)

Четыре source-only проверки, последняя 18.10: `FINAL_STATUS=PASS`, `FINAL_RC=0`, `BITRIX_BOOTSTRAP=NO`, `DATABASE_CONNECTION=NO`, `SITE_FILE_WRITE=NO`; SHA guards пройдены.

- Сайт: `local/components/eurasia/signup/class.php`; pinned SHA256 `1389951029525c046a00206397c2bc45ec14a0b5acaccbe75686b63fb2b83b84`, **3 вызова** `Consent::addByContext`. Все три передают `$agreementIds[<type>]`, внутренний literal originator, `(string)$userID`, `$consentParams`.
- Native метод: `bitrix/modules/main/lib/UserConsent/Consent.php` — **регистр каталога и имени файла важен**; SHA256 `cd29a7d79bf3babb75a6fe5ed03b59ba6d4d01f5c3fc33f579b95e2d37f64e2b`.
- Сигнатура точно: `addByContext($agreementId, $originatorId = null, $originId = null, array $params = array())`.
- Метод в исходнике имеет `return null` (наблюдались две ветви) и `return $userConsentId`; `null` никогда не считать успехом.
- **ПОСЛЕДНИЙ ОПЕРАТОРСКИЙ СКРИПТ** подтвердил:
  ```text
  CONSENT_PARAMS_KEYS=USER_ID,URL
  CONSENT_PARAMS_VALUE_METHODS=
  NATIVE_PARAMS_KEYS=USER_ID,IP,URL,ITEMS
  NATIVE_CALLED_METHODS=isExist,isActive,getCurrent,getRequest,GetID,getRemoteAddress,isHttps,getHttpHost,getRequestUri,add,isSuccess,getId,addItems
  NATIVE_DIRECT_TRANSACTION_CALLS=
  NATIVE_DIRECT_RETURN_COUNT=3
  NATIVE_DIRECT_THROW_COUNT=0
  SOURCE_VALUES_PRINTED=NO
  SOURCE_EXECUTED=NO
  FINAL_STATUS=PASS
  FINAL_RC=0
  ```
- **Критическая интерпретация**: внутри непосредственно `addByContext` не найдены прямые begin/commit/rollback. **Это НЕ доказательство атомарности всей цепочки** `CUser::Add → ORM UserConsent::add → addItems → commit/rollback`: вложенные вызовы и внешние side effects не проанализированы в реальном E2E.
- Исторический `NATIVE_SOURCE_PRESENT=NO` был **только ошибкой предположения о lowercase пути**, не отсутствием класса. И первая read-only диагностика с PHP parse error не исполняла DB; два последующих исправленных источниковых/DB-скрипта дали PASS. Не возвращаться к этой ложной проблеме.

### 4. Что уже реализовано в GitHub feature и прошло CI

Все файлы ниже находятся под `site-integration/samzaberu-v5/`:

- `src/V5RouteMap.php`, `src/V5HttpTransportBoundary.php`, `src/V5ParallelHttpEntry.php`, `src/V5AuthHttpHandler.php`: V5-only token validation + legal GET route candidates, strict transport guards; без native provider/HTTP deployment выдаётся fail-closed / 503.
- `src/V5MySqlTokenValidation.php`: V5 access JWT + authoritative strong-session/epoch/revocation, не signature-only.
- `src/V5LegalConsentContract.php`: серверный перечень документов, актуальные коды, required vs optional, отказ устаревшим/неполным согласиям.
- `src/V5BitrixCurrentLegalCatalog.php`: bounded read-only native `b_consent_agreement`; действующие URL/флаги production сверены, synthetic tests PASS.
- `src/V5SignupNativeConsentPlan.php`: список принятых current code→строго server-derived actual `AGREEMENT_ID`; rejects stale/duplicate/other users. `V5SignupNativeConsentEvidence` подтверждает per-event native ID/USER_ID/AGREEMENT_ID/ORIGINATOR_ID/ORIGIN_ID в native readback. **Это pure policy/evidence, не DB writer.**
- **НОВЕЙШЕЕ:** `src/V5BitrixStagedSignupConsentWriter.php`: feature-only CLI/staging **consent half** будущей атомарной V5 регистрации. Требует один bridge-owned current native Bitrix transaction/depth=1, exact source SHA, lock on server-created `b_user`, locked current agreements; затем `Consent::addByContext` с server-chosen `evrasia_signup`, trusted new user ID и `URL`, locked consent readback; random SAVEPOINT detects hidden native COMMIT+BEGIN. **Не создаёт CUser, не владеет COMMIT/ROLLBACK, не публикует signup API.** Denies production host `evrasia`, including same-host unapproved staging. On uncertain COMMIT **NO retry**.
- `tests/V5BitrixStagedSignupConsentWriterTest.php`: 13 **negative** guard assertions (fake Bitrix, no actual native write). `tests/V5SignupNativeConsentPlanTest.php`: 56 synthetic assertions. В CI всего **30/30 offline suites SUCCESS** [run 39114444560](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39114444560) for code commit `3aac037c8dbbbc8b2e037372994113c0ae1160a9`; PHP lint included. [MariaDB CI 39114359217](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39114359217) SUCCESS on earlier code `b43186c9143d5786d2ebf0f3c4bb8ed30d4d463f`. **REAL BITRIX signup/write positive E2E NOT RUN**. Do not label the 13 negative assertions real writer acceptance.
- Feature branch documentation and checkpoints updated. Current docs-only HEAD at prior audit: `9250e9617e650e47ee01ff45b5d8d3c6927e257b`; check latest again.

### 5. Дополнительные решения / границы

- Существующий сайт и V4 живут независимо от V5; не переписывать их ради новой ручки.
- 17.10 пилот `USER_ID=880339`: **свежий login/password/TOTP → прямой PIN без SMS подтверждён браузером**; temp guarded `pincode.php` probe автоматически откатился, исходный SHA `c7fa868712ab10bef5e85384158bb1c8b21548379b278de5fb808a0e3505afff`. До свежего TOTP для cookie-restored session `CONTEXT_OTP_USED=NO` / direct PIN denied — ожидаемая безопасность. Это подтверждает website path, **не V5 JWT/epoch**. Старый утренний `OTP_ROW_COUNT=0` для пилота устарел после новой MFA enrollment; не считать его текущим фактом.
- Пересоздавать 6 V5 таблиц, инициализировать `AUTH_EPOCH`, менять MFA или включать V5 без отдельного согласования **запрещено**.
- Всю V5 авторизацию по-прежнему блокирует **реальная десятипутевая native security hook/epoch acceptance** и настоящее Bitrix staging доказательство. Не выдавать token validate, SMS, TOTP, trusted-device и PIN endpoints за живые.
- Отдельная VM **не является обязательной** по архитектурному решению: целевая совместная работа V4/V5 на существующем сайте. Но это НЕ разрешение отключить fail-closed production-host guard для staging writes; нужна реально изолированная staging schema/root и отдельно проверенная isolation policy, затем согласование. Не делать рискованный эксперимент на production.
- **Production, БД и V4 не менять без отдельного явного согласия.** PR #32/исторические Anti-Fraud ветки не трогать. Расследование USER_ID `737384` приостановлено — не продолжать самовольно.
- Выполнять [docs/SERVER_SCRIPT_RULES.md](SERVER_SCRIPT_RULES.md): один цельный copy-paste script c heredoc, `bash -n`, guards, SHA, backup/rollback для write, `PASS/FAIL/FINAL_STATUS/FINAL_RC`, редактирование секретов. Не закрывать SSH. Ответы из реальных stdout трактовать как факты, синтетический CI отдельно.
- Пользователь просит **быстро завершать**, не повторять давно закрытые источниковые аудиты. Двигаться исследование только по точному блокеру → изменение в feature → CI → checkpoint → следующий шаг. После проверки выдавать готовый следующий шаг, не ждать многократного «дальше».

### 6. ТОЧНОЕ СЛЕДУЮЩЕЕ ДЕЙСТВИЕ В НОВОМ ЧАТЕ

1. Открыть этот checkpoint, [V5 START_HERE](SAMZABERU_V5_START_HERE_2026-10-15.md), [V5 NEXT_CHAT_CHECKPOINT](SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md), [developer contracts](SAMZABERU_V5_DEVELOPER_TOKEN_AND_PUBLIC_CONSENTS_2026-10-18.md), [script rules](SERVER_SCRIPT_RULES.md), затем **живой feature HEAD/diff/CI**. Не ограничиваться старым состоянием ветки/старым чатом.
2. **НЕ запускать повторно** каталог agreement IDs, источники `Consent.php`, `$consentParams`, OTP/PIN, 6-table migrations; они уже подтверждены.
3. Следующий нерешённый технический gate — **исследовать конкретный фактический `CUser::Add` путь** регистрации Bitrix + нативный `UserConsent` ORM writer's implicit transaction/side effects (в том числе почта/event hooks) в source-pinned targeted READ_ONLY режиме. Не печатать PII, секреты или реальные consent тексты.
4. Сразу после этого **дополнить недостающую V5-only native signup orchestration в feature**: create-new-user on same isolated native transaction → fresh active versions and locked IDs → use staged `V5BitrixStagedSignupConsentWriter` → evidence checks → caller-owned commit+postcheck; failure → complete rollback and prove no partial user/consent, uncertain outcome → block retry. Separate email/SMS side-effect treatment. Без самостоятельного production write.
5. Добавить positive E2E только на реально изолированном тестовом Bitrix+MySQL, включая обязательные offer/PD, marketing opt-in/out, stale revision, native null, rollback/race/uncertain COMMIT; сохранять строгие host/schema guards. Старые тесты 30 suites должны остаться PASS. После независимой приёмки реализовать и задокументировать staged V5 `POST /api/v5/signup`, не экспонировать до safety gates; status developer routes отдельно.
6. Документировать «было → стало → причина», фактические коммиты и CI в этом файле/START_HERE/NEXT_CHAT_CHECKPOINT. Для значимых production/staging действий запросить отдельное разрешение.

### 7. Текст начального сообщения нового чата

```text
Продолжаем Evrasia AI Bot, это тот же Project. Репозиторий juvantusik/Evrasia_AI_bot, ветка feature/samzaberu-api-v5-auth-contract.

Сначала прочитай docs/EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-18_V5_LEGAL_SIGNUP.md, затем docs/SAMZABERU_V5_START_HERE_2026-10-15.md, docs/SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-15.md, docs/SAMZABERU_V5_DEVELOPER_TOKEN_AND_PUBLIC_CONSENTS_2026-10-18.md, docs/SERVER_SCRIPT_RULES.md и сверь живой GitHub HEAD/code/CI. Старые handoff исторические.

Последний операторский READ_ONLY PASS: CONSENT_PARAMS_KEYS=USER_ID,URL; NATIVE_PARAMS_KEYS=USER_ID,IP,URL,ITEMS; NATIVE_CALLED_METHODS=isExist,isActive,getCurrent,getRequest,GetID,getRemoteAddress,isHttps,getHttpHost,getRequestUri,add,isSuccess,getId,addItems; NATIVE_DIRECT_TRANSACTION_CALLS пусто; FINAL_STATUS=PASS FINAL_RC=0. Нативный Consent.php и сайт signup SHA pinned, актуальные 3 Bitrix agreement rows проверены. Не повторяй эти проверки.

В feature уже готовы V5-only POST /api/v5/auth/token/validate, GET /api/v5/legal/consents, V5SignupNativeConsentPlan и V5BitrixStagedSignupConsentWriter (только staging consent half). Offline CI 30/30 PASS (run 39114444560). Полный V5 signup и нативная атомарность CUser::Add + Consent::addByContext НЕ подтверждены, позитивный real Bitrix writer E2E не проводился. V5 production OFF/503, V4 работает, production/БД не менять.

Продолжи СРАЗУ с точного CUser::Add + side-effect/transaction gate, затем реализуй V5-only signup orchestration с реальными отказоустойчивыми guards и тестами в feature; не переделывай ранее готовое. Не создавать новую VM без причины, не отключать production-host guard и не запускать native writes на production без моего отдельного разрешения. Пользователь хочет быстрее получить работающие две ручки разработчику СамЗаберу, но нельзя выдавать их за live до проверки/деплоя.

Не проси пересказывать историю. Работай итерациями и фиксируй реальные результаты в GitHub docs. Для диагностики сервера дай ОДИН цельный безопасный copy-paste блок с bash -n и FINAL_STATUS/FINAL_RC.
```
