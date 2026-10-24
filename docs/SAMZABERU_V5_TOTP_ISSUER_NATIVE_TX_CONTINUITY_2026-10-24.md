# СамЗаберу V5: нативная непрерывность транзакции при создании TOTP challenge — 24.10.2026

## Задача

В staging-only классе `V5MySqlPasswordBoundTotpIssuer::beginBound()` до этого этапа password/TOTP/epoch recheck выполнялся внутри транзакции `b_user + ev_samzaberu_auth_state`, но не удостоверялся, что вызванный callback не совершил **скрытый COMMIT** или **COMMIT+BEGIN** до создания `ev_v5_auth_challenges`. Проверка `PDO::inTransaction()` или Bitrix depth сама по себе не доказывает непрерывность *исходной* транзакции. Возврат правдоподобного stamp из нового контекста не должен приводить к созданию нового challenge.

## Изменения GitHub (НЕ на production)

- [`be1c6a46f9e05c500fb4e2da9e475297fe59addc`](https://github.com/juvantusik/Evrasia_AI_bot/commit/be1c6a46f9e05c500fb4e2da9e475297fe59addc) — узкий `SAVEPOINT v5_totp_issue_guard` перед `reverifyCurrentPasswordAndTotpWithUserLock()` и обязательный `RELEASE` на исходной транзакции **до первого INSERT**. Источник `V5BitrixPdoCompatibilityBridge` allowlist расширен строго двумя фиксированными SQL-шаблонами без wildcard.
- При исключении callback допускается один controlled `RELEASE` оригинального savepoint, чтобы доказать возможность корректного rollback. Если оригинальный savepoint исчез/RELEASE неопределён — запрещаем rollback чужой возможной транзакции. Bitrix compatibility bridge переводится в terminal poison и его нужно уничтожить вместе со scope. Никакой одобрительной ветки при сохранённом `inTransaction()` без savepoint нет.
- [`f1022c3207eb02496539f2f599483b89cf920594`](https://github.com/juvantusik/Evrasia_AI_bot/commit/f1022c3207eb02496539f2f599483b89cf920594) — эпизодический CI fixture восстанавливает исходную `Application::$current` после имитаций скрытого native COMMIT/COMMIT+BEGIN, чтобы следующие тесты оставались привязаны к собственному Bitrix bridge.
- `tests/integration/V5EphemeralMySqlLockedPasswordTotpTest.php` проверяет на **настоящей изолированной MariaDB/InnoDB**: malicious callback с `commit()` и с `commit()+beginTransaction()` возвращает формально валидный 64-hex stamp, но **не может создать challenge**, выполнить SQL после poison либо инициировать rollback чужого перезапущенного scope.

## Release boundaries

Эти изменения feature-only; они **не делают native TOTP в мобильном V5 готовым к запуску**. Реальные Bitrix staging tests нативного Otp replay ledger, auth epoch hook coverage, доверенных устройств, current site veto, SMS lifecycle, provider receipts и mobile token issuing остаются отдельными prereqs. Публичный V5 AUTH OFF. V4 и работающий сайт с email/SMS login не изменены, реальные SMS не отправлялись. Не загружать production website PHP/JS в репозиторий.

CI: [GitHub Actions ephemeral MariaDB](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40115770592) (проверить конкретный результат; до PASS не объявлять accepted). Общий offline safety JS regression может оставаться FAIL независимо от TOTP SQL. Следующий шаг после green CI: нативный proof V5 factory и SMS/TOTP lifecycle acceptance, без дефолтных permissive ports.
