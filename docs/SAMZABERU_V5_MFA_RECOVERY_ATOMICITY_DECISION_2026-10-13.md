# СамЗаберу API V5 — нативные MFA / recovery: доказательная граница транзакции

**2026-10-13. Статус: SOURCE-VERIFIED NEGATIVE GATES / DESIGN-ONLY.**
Никаких production изменений, запуска Bitrix, операций с реальными аккаунтами, миграций или SMS. Изолированный Bitrix staging + MySQL **не подтверждён**.

## 1. Факты фактически установленного Bitrix

Оператор прислал третий последовательный production source-only read-only аудит:
\`v5_native_recovery_final_boundary_readonly.sh\`, \`PASS_COUNT=7\`, \`FAIL_COUNT=0\`,
\`FINAL_STATUS=PASS\`, \`FINAL_RC=0\`,
\`DATABASE_CONNECTION=NO\`, \`BITRIX_BOOTSTRAP=NO\`,
\`PRODUCTION_APPLICATION_WRITE=NO\`. SHA-256 трёх закреплённых исходников совпали.

Файлы:

- \`bitrix/modules/security/lib/Mfa/UserTable.php\` —
  \`cf3979c6bed5684bacc758656c55431a60cefe2e356b1b2a4e63fb323a283a64\`.
  \`UserTable::onAfterDelete\` (101–105) читает первичный \`USER_ID\` из события ORM и вызывает
  \`RecoveryCodesTable::clearByUser\` **после** SQL-удаления MFA в \`b_sec_user\`, согласно ранее проверенному \`DataManager\` событийному порядку.
- \`bitrix/modules/security/lib/Mfa/RecoveryCodesTable.php\` —
  \`a5ff49a123bd7f3864acfc1fee9010965a8d9d3775d774442de66d5667d5e357\`.
  \`getTableName()\` возвращает \`b_sec_recovery_codes\`; \`CODES_PER_USER=10\`.
  \`clearByUser\` (84–101) выбирает коды пользователя и по одному вызывает
  \`static::delete(ID)\`, **не проверяя ORM Result каждой операции**, затем возвращает \`true\`.
  \`regenerateCodes\` (111–133) сперва очищает старые, потом по одному добавляет 10 новых,
  **не проверяя ORM Result каждой операции**, затем возвращает \`true\`.
  \`useCode\` (143–172) выбирает \`USED=N\` записи, сверяет строку кода,
  вызывает \`static::update(ID, ['USED'=>'Y', ...])\` и устанавливает \`$found=true\`
  **без проверки успешности update Result и без условного SQL-CAS \`USED=N\`**.
  Из этой реализации нельзя вывести однократное использование при конкуренции:
  два одновременно читающих запроса потенциально могут получить \`true\`.
  Это **потенциальный риск**, не подтверждённый production incident/E2E.
- \`bitrix/modules/main/lib/ORM/Data/AddStrategy/Trait/MergeByDefaultTrait.php\` —
  \`30153b42a1a652b44ca70f2bb14335d08f970d0ebef8ca85e7f349f8ad29c198\`.
  Он выбирает cached merge strategy.
- \`bitrix/modules/main/lib/ORM/Data/AddStrategy/Internal/BaseMergeTrait.php\` —
  **впервые подтверждён SHA**
  \`18deb72c645694c6378662056834f5c3dd0e409d8d3c271e4f49fd910c9ce5b8\`.
  \`getCachedMergeStrategy()\` хранит по классу результат \`getMergeStrategy()\`,
  который возвращает \`new Merge(static::getEntity())\`.
  Этот код не начинает/не завершает транзакцию.
  Merge SQL выполняется через native ORM \`getConnection()\` по уже проверенному источнику.

Предыдущие source-backed результаты: \`Otp::save\` при regeneration
вызывает \`clearByUser\` до \`UserTable::add\`;
\`Otp::delete\` удаляет \`UserTable\`, который уже после SQL вызывает \`onAfterDelete\`;
\`OtpEvents::onRecheckDeactivate\` напрямую обновляет \`UserTable\`.
Ни один из трёх аудитов **не исполнял** native Bitrix, не исследовал живые transaction boundaries или concurrency.

## 2. Запрещённые выводы (release gates)

1. **\`OnBeforeAdd\` в \`UserTable\` слишком поздний** для старта транзакции, защищающей удаление recovery-кодов, которое начинается до \`UserTable::add\`. \`OnAfterDelete\` тоже вызывается **после** MFA SQL delete и не может сам по себе создать задним числом общий transaction scope.
2. **\`useCode() === true\` не равно доказанному успешному атомарному \`USED:N → Y\`.**
   Native update Result игнорируется; в исходнике нет условной атомарной CAS по \`USED=N\` на запись. До реального доказательства на isolated staging native recovery-код нельзя автоматически считать V5 strong-MFA proof.
3. **\`MergeByDefaultTrait\` — только выбор SQL-стратегии**, не транзакционный владелец.
4. **Нельзя выдать 10-path \`V5NativeMutationCoverageMatrix\` положительный допуск**
   только на основании найденных callback-функций или валидных SHA. Фоновый агент, админские native mutation calls, recovery и logout остаются независимыми путями без доказанной общей транзакции.

**Текущий результат:** \`OTP_RECOVERY_NATIVE_ATOMICITY=NOT_PROVEN\`,
\`NATIVE_RECOVERY_SINGLE_USE=NOT_PROVEN\`,
\`NATIVE_HOOKS_PROVEN_ATOMIC=NO\`, \`V5_HTTP=503\` (last documented baseline).

## 3. Предложенный контракт одной операции (только дизайн, НЕ код для production)

Для сценариев, вызовы которых контролирует V5, и только после staging attestation:

1. Проверить точный Bitrix release/source SHA, физически изолированный DB-instance,
   тот же \`Application::getConnection()\`, \`V5VerifiedBitrixNativeTransactionDepthPort\`,
   \`V5StagingNativeTransactionPermit\` и \`@@session.in_transaction=0\`.
   При недоказанной эксклюзивности **отказ до BEGIN**.
2. Один установленный owner вызывает \`startTransaction\` **до любых**
   \`RecoveryCodesTable::clearByUser\`, \`Otp::save/delete\` или MFA mutations,
   удостоверяется в том же MySQL \`CONNECTION_ID()\` и Bitrix depth=1.
   Внутри захватывает \`b_user\` (\`SELECT ... FOR UPDATE\`) **первой**
   и придерживается единого порядка блокировок с V3C/SMS.
3. Выполняет **только авторизованные** native изменения через штатный Bitrix ORM
   на **той же** connection. Для методов, игнорирующих \`Result::isSuccess()\`,
   одного \`return true\` недостаточно: до COMMIT проверяются ожидаемые
   постусловия в БД под тем же lock, либо используется другой доказанный
   fail-closed контракт без изменения нативной бизнес-логики.
4. Выполняет \`AUTH_EPOCH\` CAS / требуемый \`PHONE_EVENT_VERSION\` в **этой же физической**
   транзакции. Проверяет ровно ожидаемое количество затронутых строк.
   Любой unknown native Result, неполная очистка recovery, CAS failure,
   смена DB session или повреждение счётчика — остановка и безопасный rollback
   **только доказанно своего scope**, иначе ручная сверка isolated staging.
5. \`COMMIT\` только после верификации всех условий. Не отправлять SMS и не вызывать
   внешние API внутри этого transaction scope. Не признавать MFA-доказательство
   или новую доверенную сессию до успешного COMMIT и независимой проверки версии.
6. **Этот wrapper не покрывает автоматически прямые native call sites**:
   \`CSecurityUser::update\`, background \`OtpEvents\`, админские действия,
   системные recovery/phone/reset/logout вызовы. Если хоть один native путь
   может изменять MFA/состояние без того же owner/mutex/epoch, глобальный
   release gate остаётся \`NO\`. Не обходить его «изолированным V5 endpoint».

Для \`useCode\` дополнительно нужен **самостоятельно проверенный** линейризуемый
consume (\`USED=N → Y\` с проверкой результата CAS, без второго успешного consume)
в рамках того же \`b_user\` lock/epoch discipline. Нынешний нативный
\`RecoveryCodesTable::useCode\` сам по себе этих гарантий **не доказывает**.
Никакого production патча Bitrix core не планировать без отдельного решения.

## 4. Обязательные isolated-staging сценарии (отдельное разрешение на test writes)

- \`Otp::save\` с regenerated secret: принудительная ошибка после частичной
  очистки recovery / перед Merge / после Merge / на \`AUTH_EPOCH\` CAS;
  старый TOTP, набор recovery и epoch должны совместно откатываться.
- \`Otp::delete\`: fault в \`onAfterDelete\` / \`clearByUser\` — отсутствие
  отдельно закоммиченной MFA delete при rollback.
- \`regenerateCodes\`: inject fail на каждом отдельном delete/add и проверить,
  что «частичный набор из 10» не считается успешным.
- \`useCode\`: две конкурентные операции над **одним синтетическим** recovery-кодом:
  **строго одна** может пройти проверенное одноразовое consume; fail/timeout
  \`static::update\` не может давать \`true\` как доказательство.
- Фоновый \`OtpEvents::onRecheckDeactivate\` и прочие 10 mutation categories:
  один lock order \`b_user\`, один DB handle, rollback native и \`AUTH_EPOCH\`
  плюс конкурентное race испытание по каждому пути.

**Первый допуск — только read-only staging preflight**, далее отдельное согласование
синтетических insert/update/delete в \`evrasia_v5_test_*\`.
Нет доказанного Bitrix/MySQL staging — нет запуска этих тестов.

## 5. Что уже в коде и что изменять не надо

Существующий \`src/V5NativeMutationCoverage.php\` уже содержит нужные
\`OtpDeleteAndDisable\` и \`OtpRecoveryAndReset\`, отдельные 5 факторов proof
и strict all-10 fail-closed gate. **Новый конкурирующий release gate не создаём.**
Минимально расширены синтетические проверки существующего
\`tests/V5NativeMutationCoverageTest.php\`, чтобы при любом ложном
delete/recovery аттестационном флаге общий допуск оставался запрещён.
Ни одна из этих проверок не даёт реального положительного transaction proof.

Документы: \`docs/SAMZABERU_V5_NATIVE_MUTATION_COVERAGE_2026-10-12.md\`,
\`docs/STRUCTURE_REGISTRY.md\` и
\`docs/SAMZABERU_V5_NEXT_CHAT_CHECKPOINT_2026-10-13.md\`.
