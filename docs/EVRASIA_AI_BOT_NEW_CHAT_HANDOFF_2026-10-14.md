# Evrasia AI Bot — передача проекта в новый чат (14 октября 2026)

> **НАЧАТЬ С ЭТОГО / АКТУАЛЬНО ПО ОПЕРАТОРСКОМУ STDOUT 14.10.2026.** Новый production source-only origin/namespace probe **5/5 PASS**, wrapper **5/5 PASS**, RUN_RC=0; завершён, повторять нельзя. PinCode.php44: **FULLY_QUALIFIED** absolute constructor, line46 check, shared braced block, conditional context and **NO dominance proof**. PhoneAuth.php46: receiver exactly **$this** in function27, no parameter/capture/direct binding records. Exact two source SHA stayed pinned: PinCode 7734ddbaba0e7ec9ddfc9f0a9008c0da0c0a1523e505e52caebac2bbaf637cd0; PhoneAuth a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27. **Unresolved:** class owner of $this and whether PhoneAuth.php check method declaration at135 belongs to same class; PinCode fully qualified constructor class actual identity still masked. No runtime, native verified phone writer, PHONE_EVENT_VERSION or deployment proof.

> **NEXT — PREPARED / CI PASS / PRODUCTION NOT RUN:** Feature code commit `a88c7199ee0bdf808afee6c5aa9fca6b09e46fbb`; [V5 CI run 38605496088](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38605496088) completed/success, all V5 offline checks and synthetic positive/mismatched FQCN/other-class/comment/string/SHA/wrong-host fixtures. Narrow two-file SHA-pinned diagnostic `tests/diagnostics/V5ObjectCheckClassLinkage.php` + `v5_object_check_class_linkage_readonly.sh` compares PinCode new absolute name with the lexical class declaration containing PhoneAuth $this on line46, plus checks direct method declaration check135. Only allowlisted masked enums and numeric lines print. No V4/site/DB/SMS writes. Ask operator for **one guarded Bash block** and analyze next stdout, not old 5/5 or 6/6 runs. The following 6/6 handoff section is HISTORICAL.

---

## Предыдущий завершённый этап: receiver binding 6/6 (историческая запись)

> **НАЧАТЬ ЗДЕСЬ / ОБНОВЛЕНО ПО ПОСЛЕДНЕМУ STDOUT.** Это тот же Project и та же общая история. Последний операторский этап уже выполнен **6/6 PASS**; не просить этот вывод повторно и не запускать старые скрипты. Следующий narrow source-only вопрос описан ниже как **PLANNED / NOT IMPLEMENTED / NOT RUN**. Сначала сверить live GitHub feature/code/docs/CI; затем подготовить и протестировать следующий минимальный probe. Production факт > текущий GitHub > staging/test > актуальная документация > старые обсуждения.

## 1. Последняя подтверждённая точка

**Latest actual production operator stdout:** `v5_object_check_receiver_binding_readonly.sh` on `evrasia`, `PASS_COUNT=6`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`; `RUN_RC=0`, `LAUNCHER_RC=0`, `WRAPPER_FINAL_RC=0`. Diagnostic commit `747f0985d20cf72566f092233f6e6544bed5a5df`; shell SHA-256 `e7c4b1875d16c28ffde1e54cfe96eaf9ca15c3fdb86f0ee1497aa3e7eb342aac`, PHP analyzer SHA-256 `9b60dbebff516dc2f674e99b7658379d24cf505039e1c3fad40cfe17ea933e13`; Bash syntax and PHP lint RC=0. All three production source pins matched; both analyzed buffers independently returned `ANALYZED_SOURCE_SHA_MATCHED=YES`.

**New source-only evidence:**
- `Controllers/V4/PinCode.php`, SHA `7734ddbaba0e7ec9ddfc9f0a9008c0da0c0a1523e505e52caebac2bbaf637cd0`: one active variable object-check anchor at operator/token line **46**; enclosing function declaration **14**, body **15–83**. `PRIOR_ASSIGNMENT_RECORDS=1`; assignment operator line **44**, RHS `NEW_CLASS_EXPRESSION`, class-basename hint **`OTHER_CLASS_MASKED`**, nested block depth **2**. This is a lexical constructor-expression hint, not a disclosed class or proof that the assignment dominates the call.
- `lib/PhoneAuth.php`, SHA `a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27`: one active variable object-check anchor at operator/token line **46**; enclosing function declaration **27**, body **27–63**. **`PRIOR_ASSIGNMENT_RECORDS=0`** in the implemented direct-assignment subset only; parameter/global/capture/reference/other binding origins remain unresolved. Zero records does not prove no initialization.

Both reports: `CLASS_NAMESPACE_RESOLVED=NO`, `RECEIVER_CLASS_PROVEN=NO`, `RECEIVER_BINDING_COVERAGE=PARTIAL`, `CONTROL_FLOW_AND_ALIASING_RESOLVED=NO`, `CONDITIONAL_CONTEXT_UNRESOLVED=YES`, `STRING_INTERPOLATION_COVERAGE=EXCLUDED`. `OTHER_CLASS_MASKED` cannot be renamed to JwtAuthorization, PhoneAuth, SMS, Bitrix or another guessed class; basename enum coverage and aliases are limited. Operator/token line46 is the called `check`, not a method declaration. Earlier mapped `PhoneAuth::check` declaration is line135 and does not establish the line46 receiver.

**Было → Стало → Причина:** two localized variable check calls with unknown receiver origin → PinCode has one detected local constructor-expression assignment at44, PhoneAuth has no detected direct assignment in27–63; class/namespace/control flow still unresolved → accepted new production 6/6 stdout. Completed caller discovery 18 files / locator 5/5 / binding 6/6 must not be repeated.

**Next question for the new chat (PLANNED, NOT IMPLEMENTED, NOT RUN):** only the same two pinned files: resolve necessary lexical namespace/import and binding-origin hints. PinCode: classify the source constructor expression at44 and its conditional relationship to the check46 inside function14/body15–83. PhoneAuth: identify the receiver binding through function27 parameters/global/capture/reference or other excluded lexical forms needed for check46. Do not assume a receiver type or runtime success; keep identifiers/literals/arguments/source statements masked. Before implementing a new narrow diagnostic, inspect current feature helpers and reuse them where appropriate; test synthetic cases and CI, then issue one full guarded READ_ONLY Bash block. No new diagnostic was prepared in this transition turn because the user requested result recording and handoff only.

**Safety/proof boundaries:** READ_ONLY, trusted root-owned system tokenizer.so under `php -n`; php.ini/Bitrix bootstrap/site execution/SQL/database connections or writes/SMS/real user rows/config files/production app writes absent, source statements and secret values not printed. `REAL_SMS_SUCCESS_AND_HTTP_ROUTE_PROVEN=NO`, `NATIVE_PHONE_VERIFIED_EVENT_PROVEN=NO`, `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN` remain unchanged.

**Latest code CI:** [38595306842](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38595306842) completed/success, code commit above; exact-read SHA mismatch refusal, masking/scopes/caps, wrong-host denial and all 22 V5 offline suites PASS. This is offline acceptance, not runtime/Bitrix/MySQL staging or deployment. Native phone event version including SAME-E.164 reconfirm, atomic AUTH_EPOCH/MFA/recovery/ten mutation paths, independent isolated Bitrix/MySQL staging remain unproven. V5 HTTP503 is only last documented baseline, not a fresh probe; V4 and production unchanged.



## 2. Архитектура, ветки, источники

| Система | Проверенное состояние |
|---|---|
| GitHub | **juvantusik/Evrasia_AI_bot** |
| Рабочая V5 feature | **feature/samzaberu-api-v5-auth-contract**, каталог **site-integration/samzaberu-v5/**. НЕ развёрнуто |
| Общие документы | **main** и feature; checkpoint и registry между ветками должны быть идентичными |
| Сайт / Bitrix production | host **evrasia**, корень **/home/site_evrasia/web/evrasia.spb.ru/public_html** |
| Бот / Anti-Fraud production | **eur-bot-01**, приложение **evrasia-ai-bot-app**, **отдельная PostgreSQL evrasia_ai_bot**; не путать с Bitrix/MySQL |
| Staging Bitrix/MySQL | **НЕ АТТЕСТОВАН/НЕ ПОДТВЕРЖДЁН**. Staging Docker Compose бота не считается независимым staging сайта |
| API V5 | HTTP **503 по последнему документированному baseline**, не новая online verification; V4 production не менялся |

**Индекс уже установленных структур всех подсистем**, Bitrix, Trusted Device, Bot Anti-Fraud, юрсогласий, V4, MFA, источников БД: [STRUCTURE_REGISTRY.md](STRUCTURE_REGISTRY.md). **Весь текущий прогресс V5**: [SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md](SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md), читать первый раздел 2026-10-14; нижние разделы исторические. **Телефонная source форензика**: [SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md](https://github.com/juvantusik/Evrasia_AI_bot/blob/feature/samzaberu-api-v5-auth-contract/docs/SAMZABERU_V5_PHONE_NATIVE_WRITER_DISCOVERY_2026-10-13.md) в feature. Общая бот/Anti-Fraud/Consent история: [NEW_CHAT_HANDOFF.md](NEW_CHAT_HANDOFF.md), [PROJECT_CHECKPOINT.md](PROJECT_CHECKPOINT.md), [AI_PROJECT_CONTEXT.md](AI_PROJECT_CONTEXT.md); правила выдачи команд: [SERVER_SCRIPT_RULES.md](SERVER_SCRIPT_RULES.md).

**Не путать readiness:** GitHub Actions 22 V5 offline suites PASS — не означает, что V5 route, миграции, JWT, outbox, SMS worker или native hooks существуют на production. Build image main success — не свидетельство deploy на eur-bot-01 или связки V5 с Bitrix.

## 3. Принятое поведение V5 (не переделывать)

Отдельный V5 для будущей версии приложения СамЗаберу; действующий V4 не менять. После подтверждённого пароля:
- включённая TOTP — запрашивается код Authenticator и после успешного входа PIN/бонусы без нового SMS;
- без TOTP, но с **проверенным strong trust**, который был получен при реальном SMS/TOTP enrollment — можно пропустить SMS; старый V4 trust после `AUTH_PASSWORD` не является proof сильного устройства;
- без strong trust/TOTP — SMS-челлендж только на **серверно подтверждённый номер аккаунта из ЛК**, не на номер из клиентского ввода; после успешного входа нет второго SMS для PIN/бонусов;
- anti-spam и денежные квоты SMS, общее proof and replay state, revocation, TOTP double-use prevention и SMS challenge — fail-closed; V4 JWT или статус «2FA включена» недостаточны для защищённого PIN.
- SamZaberu Trusted Device: `device_id="sz_"+64 lowercase hex`, SHA-256 хеш всего ID **с префиксом**, не менять core service.
- Консенсы/оферты, антифрод, статусы «Новый», блокировки и UI — отдельные ранее реализованные подсистемы. Не переделывать или переносить их в V5 без новой задачи.

Контракт: [SAMZABERU_API_V5_AUTH_CONTRACT_2026-10-11.md](https://github.com/juvantusik/Evrasia_AI_bot/blob/feature/samzaberu-api-v5-auth-contract/docs/SAMZABERU_API_V5_AUTH_CONTRACT_2026-10-11.md), source directory `site-integration/samzaberu-v5/README.md` — feature.


## 4. Что уже доказано про телефон; какое следующее действие

**Pinned production V4 source SHA:**
- `local/php_interface/Controllers/V4/Auth.php` → `72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362`.
- `local/php_interface/lib/PhoneAuth.php` → `a25f8583f461b5645220f3d3f057db653894cd721d863b6398d42469b8039d27`.

**Ключевая коррекция (БЫЛО → СТАЛО → ПРИЧИНА):** ранние grep/AWK нашли текст `Auth::confirm_request()`/`Auth::confirm()` и предполагаемые записи `CONFIRMED` на 891, 1012. Теперь точный whole-file PHP lexer **доказал оба якоря — COMMENT, не активный PHP**. `confirm_request` и `confirm` в исследованной версии закомментированы. Старые текстовые PASS были поиском слов в комментариях, **не** реальными native writers. Запрещено снова проектировать hook на 891 или называть эти методы действующими.

**Активный код, production source-only подтверждено:**
- В Auth.php активные методы: `sendSms` 22, `confirmation` 60, `signup` 263, `forgot_password` 551, `change_password` 680.
- `signup` line372: единственный найденный **прямой активный** `UserPhoneAuthTable::add`, короткий array со строковыми полями `USER_ID` и `PHONE_NUMBER`; явный `CONFIRMED` в этом массиве **не найден**. Native default/persistent state и реально исполненный SQL НЕ доказаны.
- В PhoneAuth.php активные методы `sendCode` 65, `check` 135, `createUser` 188, `sendSms` 220.
- `sendCode` static add106 и `check` static deletes153/160 принадлежат **\Evrasia\Orm\PhoneAuthCodeTable** (custom SMS code store), **НЕ native Bitrix UserPhoneAuthTable**. Удаление SMS-code не равно `b_user_phone_auth.CONFIRMED` event.
- Последняя полная проверка структуры if/returns и прямых V4 Auth callers — **5/5 PASS**, её результат в `1. Всё получено без запуска сайта/SQL/SMS.


**Дополнительный актуальный production pin:** `local/php_interface/Controllers/V4/PinCode.php` → `7734ddbaba0e7ec9ddfc9f0a9008c0da0c0a1523e505e52caebac2bbaf637cd0`. Этот SHA теперь подтверждён последними locator и receiver-binding runs, а не только историческим PIN-аудитом.

**Новая текущая цепочка завершённых шагов:**
- Bounded `v5_phone_check_active_caller_discovery_readonly.sh`: **5/5 PASS**, 18 PHP / 32465 tokens, PhoneAuth matched static/new/import=0, unresolved object-check calls=2, candidate source files=0. Candidate output was filtered by static/new/import; zero is not route absence.
- `v5_object_check_locations_readonly.sh`: **5/5 PASS**, those two sites localized to PinCode.php46 and PhoneAuth.php46, variable receivers, exact source SHA; class unknown.
- `v5_object_check_receiver_binding_readonly.sh`: **6/6 PASS**, latest factual operator output. PinCode scope function14/body15–83; assignment44 NEW_CLASS_EXPRESSION OTHER_CLASS_MASKED depth2 before check46. PhoneAuth scope function27/body27–63; zero detected direct assignments before check46 in partial subset. No namespace/class/control-flow/runtime proof.

**Следующий ровно один вопрос для нового чата — только запланирован:** в этих же двух pinned-файлах source-only установить необходимые признаки lexical receiver origin и namespace/import. Для PinCode — constructor expression44 и связь с check46, не выдавая ближайшее присваивание за dominance/runtime proof. Для PhoneAuth — параметры function27, global/capture/reference и только нужные пропущенные binding forms для check46. На основе фактических символов можно ограниченно разрешать code provenance, но unknowns остаются unknowns; заранее запрещено назвать получателя JwtAuthorization/PhoneAuth/SMS/Bitrix. Не расширять поиск на весь сайт и не повторять 18-file inventory.

**Статус следующего probe:** `NEXT_PROBE_IMPLEMENTED=NO`, `NEXT_PROBE_CI=NOT_RUN`, `NEXT_PROBE_PRODUCTION_RUN=NO`. Новый файл/готовый wrapper в этом transition-turn не создавался: пользователь попросил только зафиксировать результат и передать контекст. В новом чате сначала inspect current feature implementations, включая последние helpers, затем minimal source-only implementation + synthetic tests + CI; лишь после PASS выдать оператору ОДИН полностью копируемый Bash heredoc launcher.

**Последние существующие code helpers (completed, не повторять production runs):**
- `site-integration/samzaberu-v5/tests/diagnostics/V5PhoneCheckActiveCallerDiscovery.php`: default inventory и opt-in `--locate-object-checks`, уже выполнены.
- `site-integration/samzaberu-v5/tests/diagnostics/V5ObjectCheckReceiverBinding.php`: CLI `--source-only --receiver-line 46 --source-label LABEL --expected-sha256 SHA PATH`, exact read-buffer pin, ordinary direct assignment hints only, strings/interpolations excluded, caps/guards, reports buffered.
- `v5_object_check_receiver_binding_readonly.sh`: three source pins, only two analyzed PHP targets, reports publish only after both succeed.
- Selftests `V5PhoneCheckActiveCallerDiscoverySelfTest.sh`, `V5ObjectCheckLocationsSelfTest.sh`, `V5ObjectCheckReceiverBindingSelfTest.sh`; workflow `.github/workflows/v5-offline-safety.yml`.

Latest tested code commit `747f0985d20cf72566f092233f6e6544bed5a5df`, [CI 38595306842](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38595306842) SUCCESS; live feature may be newer due to documentation commits. Do not recreate existing V5 components or native MFA prototypes.

## 5. Незакрытые release gates — не заявлять готовность V5

- `NATIVE_PHONE_VERIFICATION_PROVEN=NO`: actual verified phone state/Bitrix event writer пока не найден.
- `PHONE_EVENT_VERSION_WRITER=NOT_PROVEN`; **повторное подтверждение ТОГО ЖЕ E.164** обязано увеличивать событие/версию, не только изменение phone string.
- `AUTH_EPOCH` one-connection transaction/CAS/rollback, ordered `b_user → b_sec_user → b_user_phone_auth → auth_state` lock, все **10 native MFA/security/phone mutation paths** — real runtime аттестация отсутствует. Proposed `ev_samzaberu_auth_state` schema только в GitHub, миграции не было.
- MFA/recovery probes **9/9, 6/6, 7/7** source-only завершены. Знаем ordering: `Otp::save` clears recovery **до** merge, `onAfterDelete` cleanup **после** удаления, `RecoveryCodesTable::useCode` не доказывает atomic single-use CAS и игнорирует результат update; это risk gap, не доказанный exploit. [MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md](SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md). Не повторять source audits.
- Нет проверенного изолированного Bitrix/MySQL staging и proof actual ORM↔native SQL rollback/concurrent use; [ISOLATED_STAGING_READINESS_2026-10-13.md](https://github.com/juvantusik/Evrasia_AI_bot/blob/feature/samzaberu-api-v5-auth-contract/docs/SAMZABERU_V5_ISOLATED_STAGING_READINESS_2026-10-13.md). Docker staging бота не подходит. Real test writes ONLY after explicit staging guards and separate authorization.
- Component inventory `AuthDecision / V5LoginWorkflow / V5SmsBudget / V5MySqlSmsOutbox / V5SecurityEventVersions / native 10-path coverage` в GitHub feature уже существует и проверен только offline; не разрабатывать параллельные реализации без изменения требований. `V5_HTTP=503` — last documented baseline, not new curl check.


## 6. Правила продолжения и передачи

1. Read this handoff, first/latest checkpoint section and relevant registry entries; no re-reading all 100k registry or global discovery to repeat known facts. Check live `main` and feature/code/PR/CI before changes.
2. Preserve accepted V5 behavior from section3: password first, native TOTP priority, only proven strong trust bypasses SMS, otherwise SMS solely to server-confirmed account number; protected PIN after complete V5 login without a second SMS. Existing V4/core/mobile IDs/Consent/Anti-Fraud/UI remain as accepted.
3. Production source-only scripts: evrasia/root; exact source SHA, canonical path/symlink/size/caps guards before relevant reads; expected SHA of actual analyzed bytes; php -n and trusted tokenizer.so; no site PHP/include/eval/Bitrix/bootstrap/SQL/SMS/user/config/secret values. New native runtime writes require separate isolated staging attestation and authorization.
4. Operator delivery: ONE Bash heredoc temporary wrapper → bash -n / php -n -l → guarded run → numbered stages / PASS-FAIL / actual RC / cleanup / SSH stays open. No curl|bash. Distorted paste echo alone is not failure when actual verified hashes/syntax/all RC pass.
5. After new actual stdout: first analyze, then document accepted facts. Shared checkpoint and STRUCTURE_REGISTRY identical in main+feature; canonical handoff current in both, forensic report in feature; preserve Было → Стало → Причина and CURRENT/PROPOSED/OFFLINE/PRODUCTION boundaries.
6. Completed masked flow5/5, caller5/5, locator5/5, binding6/6 and prior phone/MFA9/9+6/6+7/7 do not repeat. No V5 deploy, live HTTP readiness or phone writer proof implied by CI/build image success.

**Checkpoint marker:**
`LAST_OPERATOR_STEP=v5_object_check_receiver_binding_readonly.sh | LAST_OPERATOR_STATUS=PASS_6_6 | PINCODE_CHECK46_ASSIGNMENT44_NEW_CLASS_OTHER_MASKED | PHONEAUTH_CHECK46_SCOPE27_DIRECT_ASSIGNMENTS0_PARTIAL | RECEIVER_CLASS_NAMESPACE_CONTROL_FLOW=NOT_PROVEN | NEXT_STEP=TARGETED_RECEIVER_ORIGIN_AND_NAMESPACE_HINTS | NEXT_IMPLEMENTED=NO | NEXT_CI=NOT_RUN | NEXT_PRODUCTION_RUN=NO | V5_HTTP_LAST_DOCUMENTED=503 | STAGING_BITRIX_MYSQL_ATTESTED=NO | PHONE_VERSION_AUTH_EPOCH_NATIVE_ATOMICITY=NOT_PROVEN`.

**GitHub snapshot BEFORE recording this result:** main `9645857cec620903530736c881e4a9b2d2a53210`; feature `fad73d507d0d4028761f3da729f520f880f5e369`. Those are historical after this docs update; new chat must read live branch heads. Latest application/helper code CI remains the commit above; docs-only commits do not establish deployment.

