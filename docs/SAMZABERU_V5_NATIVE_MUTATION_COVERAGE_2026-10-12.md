# API V5 — атомарность нативных событий Bitrix и обязательный release gate

**Дата:** 2026-10-12. **Состояние:** SOURCE-VERIFIED fragments + GITHUB-ONLY CODE / OFFLINE TESTED. **НЕ deployed, не интегрировано в Bitrix, production без изменений.**

Первоисточники: [единый реестр](STRUCTURE_REGISTRY.md), [V3C/TOTP checkpoint](TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md), [V5 SMS contract](SAMZABERU_V5_SMS_SEND_GUARDS_2026-10-11.md). Ниже использованы **уже сохранённые результаты read-only инспекции production 2026-10-11**, не новый аудит таблиц.

## 1. Выявленный реальный интеграционный риск

В ранее подтверждённом production source:

- `bitrix/modules/security/lib/Mfa/Otp.php` SHA256 `285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c`: `Otp::save()` вызывает `RecoveryCodesTable::clearByUser()` при регенерации и затем `UserTable::add($fields)`; `Otp::delete()` вызывает `UserTable::delete($userId)`. В этих проверенных выдержках **НЕ доказаны** общий `b_user FOR UPDATE`, одна физическая PDO-сессия с V5 и CAS `AUTH_EPOCH`. Это **не утверждение**, что нативный ORM вообще не использует транзакции; необходима проверка фактической границы.
- `bitrix/modules/security/lib/Mfa/OtpEvents.php`: фоновый `OtpEvents::onRecheckDeactivate()` выполняет `UserTable::update($user['USER_ID'], ['ACTIVE' => 'Y', ...])` и чистит MFA-кэш. Это отдельный путь изменения MFA, **не обычное обновление профиля**; покрытие только `OnAfterUserUpdate` недостаточно.
- `bitrix/modules/security/classes/general/user.php` SHA256 `4f177416df72ffb7f92573c57028d2607238a2df1e1654c3e594624ad7795fa3`: `CSecurityUser::update()` может вызывать `$otp->deactivate()` или `$otp->setActive(true)->save()`, а `onAfterUserUpdate()` в проверенном участке только инвалидирует OTP-кэш при изменении `ACTIVE`. Это не доказательство записи `AUTH_EPOCH` в том же коммите.
- Для остальных событий — смена/сброс пароля, глобальное изменение mandatory OTP, вход по recovery, телефонная верификация/повторная верификация/отзыв, logout/refresh/revoke/block — **полная транзакционная совместимость пока не проверена**. Не подменять неизвестное предположением.
- Ранее V3C согласовал порядок `b_user → b_sec_user → b_user_phone_auth → ev_samzaberu_auth_state`. Но этот порядок **не гарантирует** сериализацию при отсутствии `b_sec_user`, если native enrollment другой транзакцией не блокирует тот же `b_user`. Также нельзя допустить взаимную блокировку при противоположном порядке `challenge → b_user` и `b_user → challenge`.

**Вывод:** просто поставить `OnAfterUserUpdate`/`OnAfterUserLogout` и увеличивать epoch после native commit — небезопасное ложное покрытие. Нельзя ставить `V5NativeSecurityHookCoveragePort=true` на основании списка зарегистрированных обработчиков. К моменту, когда callback сработал, оригинальная MFA/phone/password запись могла быть уже зафиксирована.

## 2. Новая зафиксированная граница допуска

Файл `site-integration/samzaberu-v5/src/V5NativeMutationCoverage.php` содержит:

- `V5NativeMutationPath` — **10 обязательных независимых групп**, включая фоновый MFA-агент, recovery, повторное подтверждение того же телефона и logout/session revocation.
- `V5NativeMutationProof` для каждого пути: фактический SHA нативного writer source и **пять обязательных подтверждений** — *тот же PDO/MySQL connection; b_user row lock перед изменением; epoch в том же transaction; общий rollback native и epoch; выполненный конкурентный staging тест*. Все пять строго true.
- `V5NativeMutationCoverageMatrix`: отсутствие/повтор записи любого пути либо unknown/failure одного из пяти свойств отклоняет общий допуск. Возвращает только имена отсутствующих категорий; секреты и персональные данные не записываются.
- `V5StrictNativeSecurityHookCoverage` реализует действующий интерфейс `V5NativeSecurityHookCoveragePort` из `V5SecurityEventVersions.php`. Он требует **два независимых источника доверенного результата**: (`a`) доказательство, что именно на текущем сервере установлена и проверена нужная редакция, и (`b`) полный набор доказательств для данного аккаунта. Также отклоняет не-MySQL, не exception PDO mode, отсутствующее/ошибочное состояние.
- `V5TrustedInstalledNativeCoveragePort` — интерфейс. **Production/staging реализации не существует.** Подставлять тестовый fake или разрешающий флаг из HTTP/конфига нельзя. Это gate, **не установщик хуков** и не обход безопасности. Когда real evidence provider не подключён, phone event/auth epoch остаются недействительными и V5 SMS не открывается.

Тест `tests/V5NativeMutationCoverageTest.php`: **60/60 PASS** PHP 8.4.24, без ядра Bitrix и БД. Позитивная матрица в тесте **искусственная**, ничего не доказывает о нативных событиях production. Негативные случаи: каждый пропущенный путь, каждое отсутствующее условие атомарности, отсутствие общего локального релиза/настоящей per-user evidence, дубли, неверный SHA, плохая PDO/ошибки источника.

## 3. Проверяемые условия перед какой-либо реальной интеграцией

Для каждого native writer entrypoint независимо, включая agent/recovery:

1. Выяснить **на фактических исходниках этого релиза**, можно ли перехватить изменение *до записи* и в одной транзакции с `AUTH_EPOCH` / `PHONE_EVENT_VERSION` (установленная в проекте PDO-кандидата транзакция и Bitrix ORM обычно не могут считаться автоматически общими). Не выдумывать поведение внешнего API.
2. Доказать `b_user FOR UPDATE` **до** native MFA INSERT/DELETE/UPDATE, включая случай, когда `b_sec_user` ещё нет. Подтвердить единый глобальный порядок lock с SMS challenge и V3C, включая конкурентное включение TOTP на пустом MFA.
3. Подтвердить, что провал epoch CAS **откатывает native изменение** в том же физическом MySQL transaction. OnAfter/послекоммитные callbacks не считаются достаточными.
4. Покрыть фоновый `OtpEvents::onRecheckDeactivate()`, очистку recovery-кодов, phone confirm/reconfirm/reset, password/lockout/logout/refresh invalidation. Отсутствующий путь = `COVERAGE=NO`, никакой V5 assured сессии.
5. После настоящей минимальной интеграции только на isolated staging выполнить конкурентные тесты «SMS reserve ↔ MFA enrolment», «SMS reserve ↔ reconfirm same phone», «native write failure ↔ epoch rollback», двухпроцессный login/revoke и проверить rollback. Записать точные SHA источников и тестовую ревизию до положительного attestation.
6. Лишь после этого запускать независимый V5 JWT / SMS verification / бонусный PIN pilot с отдельно согласованной миграцией и rollback. **Не патчить наугад Bitrix core в production**.

**Текущий статус:** `NATIVE_HOOKS_PROVEN_ATOMIC=NO`; `V5_NATIVE_COVERAGE_PROVIDER_INSTALLED=NO`; `V3C_AUTH_STATE_TABLE_INSTALLED=NO`; `REAL_MYSQL_CONCURRENCY_E2E=NO`; `V5_HTTP=503`; `V4_UNCHANGED`; `PRODUCTION_WRITE=NO`.

**Следующая рабочая точка:** узко исследовать транзакционную границу конкретных native writer entrypoints (без повторной схемы `b_user`), решить, какой штатный Bitrix transactional connection и hooks можно безопасно разделить с V5/ V3C; затем интеграционный adapter для *всех* десяти потоков, не раньше.



## 4. Native transport milestone (2026-10-12) — no native event hooks yet

The user provided two successful READ_ONLY source diagnostics: Bitrix settings point to **MYSQLI**, actual `MysqliConnection` source exists at lowercase `lib/db/mysqliconnection.php`, and native parent `Connection` / `MysqlCommonConnection` has `startTransaction/commitTransaction/rollbackTransaction`. The V5 source now includes a staged **same-application-connection compatibility layer** `V5BitrixPdoCompatibilityBridge.php` instead of a separate PDO connection (see `docs/SAMZABERU_V5_BITRIX_TRANSACTION_BRIDGE_2026-10-12.md`). **80/80 offline synthetic assertions PASS.**

**No change to the ten-path compatibility verdict:** V5 native MFA writers/agent have not been shown to hold `b_user` mutex and bump shared `AUTH_EPOCH` in the same physical transaction. The live implementation of `V5NativeBitrixExclusiveTransactionPermitPort` does not exist either. A same-connection bridge alone does **NOT** solve those problems; never set `NATIVE_HOOKS_PROVEN_ATOMIC=YES` or activate V5 from these unit tests. Next: staging runtime Bitrix connection and native event coverage under a single accepted lock discipline.


## 5. Next parallel source-order diagnostic (2026-10-13)

Separate physical Bitrix/MySQL staging is **not yet attested**, so a real native transaction E2E cannot be claimed. In parallel, a *new*, narrowly scoped source-order audit is prepared at `site-integration/samzaberu-v5/tests/diagnostics/v5_native_mfa_writer_order_readonly.sh`. It runs **only** on production host `evrasia` as root in `MODE=READ_ONLY`, verifies **six exact pinned SHA-256 hashes** from previous source inspections, then prints bounded **Bitrix core source fragments** for `Otp::save/delete`, `OtpEvents::onRecheckDeactivate`, `CSecurityUser::update/onAfterUserUpdate`, ORM Merge strategy, and ordered markers from `DataManager::sysAddInternal/update/delete`. The audit **does not** read configuration/secrets/users, launch Bitrix, open a DB connection, install any hook, or alter production. No source excerpt is printed before all six pins match.

**Status now: SCRIPT PREPARED, NOT YET RUN ON PRODUCTION.** GitHub Action on the feature branch adds **`bash -n` only** for this diagnostic; its production source audit is **not executed** by CI and is not a substitute for the operator's pinned source output.

After the operator shares the full script output, use the exact source ordering to separate native writer mutation boundaries that might allow a pre-write transaction/lock from post-write/after-event hooks that cannot establish atomic `AUTH_EPOCH` updates. Avoid inventing atomicity or promoting a 10-path coverage gate. The six-source audit is **not** full coverage of all ten mutation paths; phone verification, password resets and logout/session invalidation remain separately unproven. Staging remain a separate and required release gate.

`PRODUCTION_WRITE=NO`, `DATABASE_CONNECTION=NO`, `BITRIX_BOOTSTRAP=NO`, `NATIVE_EVENT_ATOMICITY_PROVEN=NO`.


## 6. REAL production source-order result (2026-10-13)

The operator completed the previously prepared `v5_native_mfa_writer_order_readonly.sh` on `evrasia`. Exact production output: `MODE=READ_ONLY`, six source SHA pins matched, `PASS_COUNT=9`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, no Bitrix bootstrap, DB connection, user-data read or application write.

**Verified order in the installed native code:**

- `Otp::save()`, Otp.php lines 344-379: if `regenerated`, calls `RecoveryCodesTable::clearByUser($userId)` at 370, then `UserTable::add($fields)` at 374 (with the MergeByDefaultTrait pathway indicated by the native comment), then clears the MFA cache. **At least two distinct native write calls** are visible in that source; **no explicit transaction wrapper within `save()`**. Do not assume they are atomically committed.
- `Otp::delete()`, lines 386-394: calls `UserTable::delete($userId)` then clears MFA cache.
- `OtpEvents::onRecheckDeactivate()`, lines 14-40: directly updates the MFA `UserTable` via `UserTable::update` per user, then clears cache, without a transaction wrapper in the shown method. This background writer must remain a separate one of ten proof categories.
- `CSecurityUser::update()`, lines 53-147: administrator/user actions can call `$otp->deactivate()`, `$otp->delete()`, `$otp->regenerate()` and `->setActive(true)->save()`. `onAfterUserUpdate()`, lines 159-166, only invalidates MFA cache on `ACTIVE`, **not** a shared epoch-CAS proof.
- `DataManager::sysAddInternal` native source lines 906 `callOnBeforeAddEvent`, 939 `callOnAddEvent`, 956 `$strategy->add(...)`, 1015 `callOnAfterAddEvent`. `update` lines 1277/1306 before, actual SQL `queryExecute` 1337, after event 1362. `delete` lines 1665/1674 before, actual SQL `queryExecute` 1691, after event 1702.
- The native Merge strategy uses `prepareMerge` and `Helper::executeAndGetInsertedId` (lines 89-100). **Event invocation order is static-source evidence, not transaction ownership**: a before callback may run before SQL yet cannot on its own establish a transaction enclosing the native writer; `OnAfter` is also too late when the SQL autocommits.

**Consequence / no premature release:** a release relying on `OnAfterUserUpdate` alone cannot satisfy native MFA/recovery/agent event and shared `AUTH_EPOCH` atomicity. The native writer paths require a separately proven outer transaction/lock discipline, consistent user-row mutex, and verified rollback/concurrency behavior on truly isolated staging. `NATIVE_EVENT_ATOMICITY_PROVEN=NO`; `10_PATH_ATOMICITY=NOT_VERIFIED`.

**Next narrow source-only check:** locate and inspect the actual `RecoveryCodesTable::clearByUser` body and MFA `UserTable` merge trait wiring (not re-auditing database schema or whole Bitrix). Confirm whether any method itself starts/commits, dispatches events or uses another DB connection. Then decide which parts can be included in one call-owned transaction; do not install hooks or migrate.


## 7. Recovery-cleanup / default merge focused audit prepared — 2026-10-13

New `site-integration/samzaberu-v5/tests/diagnostics/v5_native_recovery_merge_readonly.sh` (**GITHUB-ONLY / NOT YET RUN ON PRODUCTION**) checks host/root and two already pinned source SHA values, prints only the bounded `UserTable.php` declaration, finds the framework's `MergeByDefaultTrait` in the canonical ORM tree and reports its file SHA/initial source, then finds `RecoveryCodesTable::clearByUser` strictly inside the security/Mfa tree and prints only its 46-line method context and SHA. Discovered files are source-inventory candidates, not preapproved versions: do not attribute them runtime proofs until the source and hashes are reviewed. Any path ambiguity, missing method, unexpected size or source drift fails closed; no bootstrap/DB/User data or writes. The audit complements — does NOT repeat — the completed 9/9 writer/event-order audit.

The 22-suite offline V5 GitHub workflow has been extended to `bash -n` the new script without executing it. Run only when the exact CI result is confirmed and only on `evrasia` in read-only mode. Next: analyze the output, then isolate any new native writer transaction behavior that requires a staging rollback proof. `NATIVE_MFA_ATOMICITY=UNVERIFIED` until proven on isolated Bitrix/MySQL.


## 8. Operator verified recovery / merge structure (2026-10-13)

Received the operator's **second** production read-only source inspection, `v5_native_recovery_merge_readonly.sh`, with `MODE=READ_ONLY`, `PASS_COUNT=6`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `BITRIX_BOOTSTRAP=NO`, `DATABASE_CONNECTION=NO`, `DATABASE_WRITE=NO`, `PRODUCTION_APPLICATION_WRITE=NO`.

**Concrete source structure and exact SHA facts:**

- `Bitrix\Security\Mfa\UserTable` actually declares `use Bitrix\Main\ORM\Data\AddStrategy\Trait\MergeByDefaultTrait;`, and its `getTableName()` resolves to `b_sec_user`. `UserTable.php` pre-existing known SHA `cf3979c6bed5684bacc758656c55431a60cefe2e356b1b2a4e63fb323a283a64`.
- Actual `bitrix/modules/main/lib/ORM/Data/AddStrategy/Trait/MergeByDefaultTrait.php` SHA `30153b42a1a652b44ca70f2bb14335d08f970d0ebef8ca85e7f349f8ad29c198`; it `use BaseMergeTrait` and `getAddStrategy()` returns `self::getCachedMergeStrategy()`. The previously source-pinned `Merge::add` invokes `prepareMerge` / `Helper::executeAndGetInsertedId`. However the **body of BaseMergeTrait has not yet been reviewed**; do not invent any transaction behavior.
- Actual `bitrix/modules/security/lib/Mfa/RecoveryCodesTable.php` SHA `a5ff49a123bd7f3864acfc1fee9010965a8d9d3775d774442de66d5667d5e357`. `clearByUser($userId)` lines 84–101 casts/checks a positive user ID, selects recovery-code `ID` values, loops each row and calls **`static::delete($code['ID'])`** separately, then returns `true` **without checking those delete-result objects**. It does not open any explicit transaction in this method.
- `regenerateCodes($userId)` in the reviewed fragment (111–129) calls `clearByUser`, then loops to create fresh recovery codes via **separate `static::add($code)` calls**. The remainder of the method was outside the bounded excerpt, so avoid asserting what that remainder does.
- Read together with the already verified `Otp::save()` order `clearByUser` (line 370) **before** `UserTable::add` (374), **a `UserTable` before-add hook begins after recovery deletes have already been attempted**. Therefore **a transaction started only by an ORM `UserTable::OnBeforeAdd` callback is too late to protect the entire OTP replacement operation**. An enclosing scope at or above the call to `Otp::save` (or a separately proven native pre-clear boundary) is required, subject to future safe ownership and common lock proof.

This is a **verified source-order negative design constraint**, not evidence of a real partially deleted account or that the framework never uses an outer transaction. The native MIME/recovery atomicity remains `NOT_PROVEN`, with the `otp_recovery_and_reset` and `otp_save_and_enrollment` release gates failing closed. No security workaround or source patch was deployed.

**Next final narrow read-only source query prepared:** `tests/diagnostics/v5_native_recovery_final_boundary_readonly.sh` (GitHub only, not run on production). It checks exact UserTable/RecoveryCodesTable/MergeByDefaultTrait SHA, prints `UserTable` delete-event method (lines 94–175), RecoveryCodesTable declaration and tail of `regenerateCodes`, and resolves `BaseMergeTrait::getCachedMergeStrategy` with its actual SHA; avoids reprinting the already inspected `Otp::save` and `DataManager` ordering. No staging runtime or MySQL is used; CI only lints it.


## 9. Final MFA recovery/source boundary audit: 7/7 PASS (2026-10-13)

Operator actually ran the third, previously prepared production **source-only** script \`v5_native_recovery_final_boundary_readonly.sh\`:
\`PASS_COUNT=7\`, \`FAIL_COUNT=0\`, \`FINAL_STATUS=PASS\`, \`FINAL_RC=0\`,
\`BITRIX_BOOTSTRAP=NO\`, \`DATABASE_CONNECTION=NO\`, \`DATABASE_WRITE=NO\`,
\`PRODUCTION_APPLICATION_WRITE=NO\`. Three pinned source SHA-256 checks passed.

- \`UserTable::onAfterDelete\` (native \`UserTable.php\` 101–105) invokes
  \`RecoveryCodesTable::clearByUser(USER_ID)\` **after** the ORM \`b_sec_user\` DELETE
  SQL. This is not a proven single transaction: a callback after SQL cannot
  retroactively encompass an already autocommitted native operation.
- \`RecoveryCodesTable.php\`, SHA \`a5ff49a123bd7f3864acfc1fee9010965a8d9d3775d774442de66d5667d5e357\`:
  \`regenerateCodes\` finishes its repeated \`static::add($code)\` calls at line 129
  and returns \`true\` at 132 without checking ORM \`Result\` objects.
  \`useCode\` (143–172) reads \`USED=N\`, then \`static::update(ID, USED=Y)\`,
  **ignores the update Result**, sets \`$found=true\` and returns it. Native
  method does not use a conditional write \`WHERE USED=N\` and does not visibly
  lock or serialize this consume. **Two concurrent successes / false proof on
  silent DB failure are potential risks, NOT proven incidents.**
- \`BaseMergeTrait.php\`, **new discovered verified SHA**
  \`18deb72c645694c6378662056834f5c3dd0e409d8d3c271e4f49fd910c9ce5b8\`.
  Its \`getCachedMergeStrategy()\` caches one strategy per class;
  \`getMergeStrategy()\` constructs \`new Merge(static::getEntity())\`.
  Neither opens or owns a transaction. Previous Merge source ties the SQL
  execution to the ORM native connection, which itself is not ownership proof.

**Decision (no core patch):** Neither \`UserTable::OnBeforeAdd\` (runs after recovery
clear), \`UserTable::OnAfterDelete\` (runs after MFA delete), nor a checked
\`MergeByDefaultTrait\` version suffices to guarantee single-unit MFA, recovery,
and \`AUTH_EPOCH\` changes. A controlled transaction must begin **before** the
earliest recovery/MFA write, lock \`b_user\` with stable lock ordering,
run native writes and epoch CAS on one verified native DB handle, check
success/postconditions, and commit only after all pass. The remaining
non-V5-owned/admin/agent paths must separately prove this same discipline.

**Native recovery code consumption is not accepted as automatically trusted
V5 strong-MFA evidence** until isolated staging proves a single-use atomic
\`USED=N -> Y\` transition and concurrency safety; an unexamined
\`useCode() === true\` must not upgrade V5 session assurance.

Full source-verified negative constraints, proposed owner scope, fault-injection
and concurrency acceptance: \`docs/SAMZABERU_V5_MFA_RECOVERY_ATOMICITY_DECISION_2026-10-13.md\`.

No replacement gate was created: the existing \`V5NativeMutationCoverageMatrix\`
already requires five independent proofs for every one of ten categories.
\`V5NativeMutationCoverageTest.php\` gained **22 extra synthetic assertions**
covering false-positive \`OtpDeleteAndDisable\` and \`OtpRecoveryAndReset\`
proofs. Actual GitHub Actions run [38381811549](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/38381811549) **SUCCESS**:
82/82 assertions for native coverage, all 22 offline test suites PASS,
48 PHP lint PASS, and pre-bootstrap staging denials PASS. This is NOT a
real Bitrix/MySQL test, and proves NO native hook transaction atomicity.

**SOURCE AUDITS FOR THIS CHAIN ARE FINISHED** (operator 9/9, 6/6, 7/7).
Do not repeat them. Next gate: establish independently isolated staging
Bitrix+MySQL, verify actual shared-connection rollback and single-use recovery
concurrency, then prove all 10 mutation paths. No changes to production/V4.
