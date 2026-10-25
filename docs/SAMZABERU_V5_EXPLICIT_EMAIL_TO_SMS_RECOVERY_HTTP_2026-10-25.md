# СамЗаберу API V5: явный переход email OTP → SMS fallback (25.10.2026)

**Статус:** source-only HTTP boundary. **Не активировано на production.** Никакая SMS/OTP реализация не дублирована.

## Зачем нужен отдельный V5 HTTP route

Политика владельца подтверждена: **сильное доверенное устройство → native TOTP → подтверждённый email OTP; SMS только по явно выбранному восстановлению**. Сайт уже реализовал работающую ручную кнопку email → SMS, но её PHP-сессионный AJAX endpoint нельзя считать API мобильной JWT-сессии. В V5 до этого коммита был только `/auth/challenge/resend`, который обслуживает **уже начатый SMS challenge**, а не переход с email на SMS.

Добавлен V5-only `POST /api/v5/auth/challenge/use-sms`, принимающий только следующий JSON:

```json
{
  "challenge_id": "<64 lowercase hex символа ID ранее выданного EMAIL challenge>",
  "device_id": "<существующий валидный sz_-ID установленного приложения>",
  "platform": "ios"
}
```

`platform` может быть `ios` или `android`. Нет полей phone, email, user_id, password, login, trust_token, method, issuer, rate_limit или site cookie. Запрос принимается только через существующий V5 HTTP boundary (HTTPS/POST/application-json/строгий путь/no-store/без query string).

**Если recovery разрешён существующими server-side доказательствами**, серверный provider возвращает `202`, `status=challenge_pending`, `challenge_type=sms`, **новый** `challenge_id`, `expires_in`. Это **не JWT**. При вводе доставленного SMS-кода приложение вызывает уже существующий `POST /api/v5/auth/challenge/verify` с новым SMS challenge. Верификация обязана пройти existing SMS/TOTP consumption, verified phone, AUTH_EPOCH и strong V5 grant checks. Если recovery не разрешён, исходный challenge недействителен, фактор TOTP обязателен или native provider отсутствует — **нет отправки, нет JWT**.

## Недостающий, намеренно НЕ реализованный private provider

`V5ExplicitSmsRecoveryPort::requestSmsInsteadOfEmail(emailChallengeId, deviceId, platform)` — только интерфейс делегирования, а **не** новый алгоритм SMS, ограничения, SQL-таблица или транспорт.

Для будущего private Bitrix-adapter строго необходимо:

1. На том же физическом Bitrix/SQL transaction проверить, что исходный email challenge **существует**, относится к этому устройству/платформе и пользователю, был создан только после правильного пароля, имеет `status=accepted`, TTL не истёк и `AUTH_EPOCH`/email ownership stamp остались актуальны.
2. Проверить, что current native TOTP **не активен** и действующая политика сайта действительно допускает **явный SMS recovery** этому пользователю. Нельзя принимать client `phone` или трактовать отсутствие email как безусловное разрешение на SMS.
3. Использовать уже работающие **сайт-native SMS challenge/transport, verified-phone, антифрод и per-user rate limits**, без второго алгоритма, без нового global blocking cap и без новой rate-table. Не заявлять, что существующий веб-метод с PHP cookie уже обеспечивает мобильное device/epoch-proof.
4. Под row locks/idempotency инвалидировать или supersede email challenge и выдать **новый** мобильный SMS challenge ID (не равный email ID), с уже проверенными SMS receipt и code lifecycle. Если невозможно сделать атомарный переход, **deny**; нельзя оставить возможность одновременно использовать оба challenge или потратить SMS-ресурс повторно на гонке.
5. Ошибка SMS provider/Redis/Bitrix/базы — fail closed, без автоматического переключения и без вывода внешних ответов, OTP, телефонов, ключей.
6. До согласования отдельной миграции `eurasia_new.ev_v5_email_auth_challenges`, native-provider, реального E2E и V5 enable — **V5 AUTH OFF**.

**Дополнительный compile-time guard:** optional provider в `buildEmailFirst()` теперь имеет тип `V5AttestedExplicitSmsRecoveryPort`, который расширяет `V5ExplicitSmsRecoveryPort` и обязан положительно пройти `verifiedExistingSiteRecovery(PDO $db) === true` до сборки HTTP boundary. `null`, отрицательный ответ и исключения запрещают привязку; ни один из этих путей не отправляет SMS. Положительная оценка provider всё ещё **не является** реальной Bitrix E2E и требует независимой ревизии установленного кода. Source-only тест `V5MySqlPublicCompositionTest.php` проверяет отказ при отсутствии этой аттестации.

**Текущий GitHub-код**: `V5RouteMap.php`, `V5AuthHttpHandler.php`, `V5LoginWorkflow.php`, `V5MySqlChallengeHttpPort.php`, `V5MySqlPublicComposition.php`. `buildEmailFirst()` принимает optional `V5ExplicitSmsRecoveryPort` последним аргументом; при отсутствии provider route отклоняется. Если он передан, обязательна отдельная positive attestation. Legacy `build()`, существующий `resendSms()`, рабочий сайт, V4 и Restis не изменены.

**Source-only CI:** `tests/V5ManualSmsFallbackHttpTest.php` и `tests/V5MySqlPublicCompositionTest.php` проверяют именно boundary, методы, отсутствие destination override и fail-closed. Их mock pending ticket **не подтверждает фактическую отправку SMS**. Без БД, пользователей, Redis подключения или реальных сообщений.

**Отдельное согласование DDL остаётся необходимым:** см. `SAMZABERU_V5_EMAIL_LEDGER_PRODUCTION_MIGRATION_APPROVAL_2026-10-25.md`. Само сообщение «дальше» его не заменяет.
