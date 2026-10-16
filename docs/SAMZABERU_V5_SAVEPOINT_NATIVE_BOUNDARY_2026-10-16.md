# СамЗаберу V5 — SAVEPOINT continuity guard против скрытого COMMIT+BEGIN

16 октября 2026. **Feature-only code/test**, production не менялся. Назначение: закрыть
пробел предыдущего `V5StagingNativeMutationScope`: `CONNECTION_ID()`,
`@@session.in_transaction=1` и native depth==1 могли не заметить, что
сторонний native writer сделал `COMMIT` и сразу `BEGIN` на том же соединении.

## Исправление

Источник: `src/V5StagingNativeMutationScope.php`, code commit
`1987ddc114352470a476f3f3e5e8eda057458f8b`.

Сразу **перед reviewed native callback** создаётся уникальный случайный
`SAVEPOINT v5_scope_<32 hex>` через то же native Bitrix-style mysqli connection.
Независимо от того, вернул callback `true`/`false` или выбросил ошибку, контур
проверяет `RELEASE SAVEPOINT`. MySQL/MariaDB уничтожает savepoints при COMMIT,
поэтому скрытый `COMMIT+BEGIN` не восстанавливает исходную точку, даже при
совпавших connection ID и depth. Ошибка/отказ `RELEASE` переводит scope в
`uncertain`: нет отчёта об успешном commit, автоматического rollback,
retry или повторного использования этого объекта. Caller обязан отбросить
ненадёжное native connection, разобраться с возможным ранним COMMIT и
последствиями. Скрытый нативный COMMIT **мог уже необратимо зафиксировать
изменение**, этот тест не отменяет его.

При обычной ошибке callback с сохранённым savepoint выполняется rollback
проверенно принадлежащей транзакции. При неопределённом результате финального
COMMIT также нет retry и scope permanently poisoned. Никаких HTTP endpoints,
production hooks, native Bitrix mutation implementations или AUTH_EPOCH
initialization исправление не устанавливает.

### Что НЕ доказано

- Тестируемые `Bitrix\\Main\\DB\\MysqliConnection`,
  `V5NativeMutationPath` proofs и callback на CI **синтетические**.
- Не проверено, что фактический installed Bitrix SQL helper/native driver
  возвращает корректный error на `RELEASE SAVEPOINT` после native COMMIT;
  требуется реальный isolated Bitrix acceptance с exact SHA/rollback.
- Сохранение savepoint доказывает отсутствие промежуточного COMMIT **в
  проверенном диапазоне callback**, но не доказывает сам по себе native
  business-event semantics, существование десяти атомарных native hooks,
  отсутствие неожиданных изменений без COMMIT или корректность coverage
  provider. Все native mutation paths должны быть source-pinned и отдельно
  приняты тестами конкурентности.
- Saved connection ID/depth без savepoint недостаточны. Даже с savepoint
  нельзя автоматически активировать V5 или чинить V4.

## Фактический CI evidence

- GitHub offline run `38896370467`: completed SUCCESS, 26 suites.
- GitHub disposable MariaDB run `38896370806`: completed SUCCESS,
  **530 checks = 43 separate sessions +487 dependent**, из них **40** для
  staging native scope вместо прежних 33, то есть **+7** новых.
- Тест явно исполнил `UPDATE b_user` в disposable fixture, затем
  `COMMIT+BEGIN` на том же mysqli PDO. Независимый reader уже видел
  нативное изменение, но V5 `AUTH_EPOCH` остался прежним; поэтому
  тест требует `uncertain`, а не `rolled_back` или `success`.
  Проверено, что wrapper не откатывает возможную чужую новую транзакцию,
  а poisoned объект не может запускаться снова даже после тестовой очистки.
  Временное изменение в disposable fixture восстановлено в CI.
- При обычном native SQL failure теперь проверяется, что ошибка пришла
  **внутри** reviewed callback, а не при установке savepoint.

## Production / next gate

Последняя операторская проверка 15.10: шесть новых пустых V5 InnoDB tables
в `eurasia_new`, режим V5 OFF, HTTP503, нет per-user AUTH_EPOCH и
site-factory; root/V4 unchanged. Schema-only migration evidence:
`/root/ev-v5-ddl-backup.20261015T174912Z.2010173`.

**Следующий реальный, а не synthetic gate:** доказать установленный native
writer pre-write entrypoint и его SAME physical transaction на фактическом
Bitrix release, особенно OTP save/reset c recovery deletes до ORM events,
фоновые MFA update, confirmed phone/reconfirmation и logout. На production
core/V4 не патчить. Никакого повторного CREATE шести таблиц. Изолированная
проверка должна не использовать реальные аккаунты или paid SMS.
