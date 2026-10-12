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
