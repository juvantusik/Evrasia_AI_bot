# СамЗаберу API V5 — переиспользование рабочих бизнес-интеграций V4

> **ПОСЛЕДНЕЕ ПРИНЯТОЕ РЕШЕНИЕ, 23.10.2026:** API V5 **полностью заменит V4**. В настоящем документе фраза «V4 оставить без изменений» означает **только переходный период**, пока существующие клиенты, мобильные приложения и служебные интеграции не переведены на V5 и не пройдена приёмка. Поддерживать V4 бессрочно **не планируем**. Общие бизнес-сервисы Bitrix/RestIS/CartApiService при этом продолжают использоваться V5. Актуальные правила вывода V4: [SAMZABERU_V5_SOLE_API_CUTOVER_2026-10-23.md](SAMZABERU_V5_SOLE_API_CUTOVER_2026-10-23.md).

**Решение пользователя от 2026-10-23: принято к исполнению.** Не строить отдельную бизнес-систему с нуля. Взять доказанные бизнес-правила/услуги реально работающего V4 за основу V5, дополнить нужными V5-функциями. V4 оставить без изменений, отдельно обслуживать действующие мобильные приложения.

## Главное изменение подхода

**Было:** пытались создать значительный набор самостоятельных V5 providers, включая переосмысление уже существующих сервисов V4, и блокировали практическую интеграцию из-за незакрытого полного native security coverage.

**Стало:** V5 — отдельные URL/контроллеры/выдача независимых V5-сессий над проверенными **общими бизнес-сервисами существующего Bitrix/V4**. Дублировать работающие расчёты, профиль, лояльность, бонусы, юридические документы, RestIS, регистрацию доверенных устройств и бизнес-конверсию не требуется, если их исходные сервисы безопасно вызываются в новом контексте.

**Причина:** сокращение объёма V5, сохранение бизнес-поведения для гостей и партнёров и ускорение интеграции СамЗаберу без риска сломать V4.

## Факты, уже подтверждённые по installed production и Project registry

- Важно различать публичный домен **https://evrasia.rest** (основной, использовать в mobile SDK) и production Bitrix origin **evrasia.spb.ru**. Системный путь `/home/site_evrasia/web/evrasia.spb.ru/public_html` — физическая директория сайта, не основание предлагать другой основной публичный API URL.
- Действующие классы V4 живут **на сервере Bitrix**, не в рабочем GitHub package: `api/v4/router.php`, `local/php_interface/Controllers/V4/Auth.php`, `local/php_interface/Controllers/V4/PinCode.php`, `local/php_interface/lib/JwtAuthorization.php`, `local/php_interface/lib/PhoneAuth.php`, `local/php_interface/lib/Services/TrustedDeviceMobileService.php`, `local/php_interface/lib/Services/TrustedDeviceService.php`.
- V4 signin: существующий `CUser->Login` → `JwtAuthorization::createTokens` → `TrustedDeviceMobileService::registerSuccessfulAuthentication(AUTH_PASSWORD)`. Эту цепочку **нельзя просто вызвать** из V5, поскольку выдаваемая парольная V4-сессия и регистрация устройства не доказывают нативную V5 MFA и сильное доверие.
- Существующий V4 сайт исторически использует `CRestis::pincode()` для бонусного PIN и доставляет PIN по VK/SMS. В V5 допускается переиспользовать именно действующий **источник получения PIN** при строгом условии, что вызов не инициирует прежнюю SMS-отправку и сам получает USER_ID только из уже доказанной, неотозванной V5-сессии с запертой строкой пользователя.
- `TrustedDeviceMobileService::validateTrust()` → `TrustedDeviceService::validateTrust()` можно использовать как реальную основу проверки связки device/platform/trust; **V4 AUTH_PASSWORD trust не считается сильным MFA-основанием**.
- `PhoneAuth::check()` и `JwtAuthorization` несут legacy V4 SMS/JWT issuance и не могут использоваться как shortcut для выдачи V5 JWT, MFA или повышения assurance. Существующий провайдер платной SMS и подтверждённый телефон можно переиспользовать только после отдельного fail-closed V5 challenge/rate/verified-phone контроля.
- V5 собственная реализация уже существует: `V5LoginWorkflow`, `V5BitrixNativeFirstFactorComposition`, `V5MySqlStrongSessionStore`, `V5AccessJwtCodec`, `V5MySqlTokenValidation`, `V5MySqlSessionLogout`, `V5PublicLegalCodeValidation`. **Не переписывать всё это с нуля.** Отдельные обязанности V5 — усиленная авторизация/MFA, SMS-budget, доверенное устройство/assurance, JWT/refresh/revocation, atomic epoch rechecks, защищённый вызов PIN и текущие коды согласий.

## Рабочий план реализации — без новых тестовых аккаунтов

1. **Инвентаризировать только business-регистрации** уже существующего `api/v4/router.php` и их непосредственные V4 контроллеры/сервисы по закреплённому SHA; восстановить точные V4 HTTP маршруты (кроме уже исследованного auth/JWT). Не копировать/переписывать V4 router и не повторять MFA/Bitrix/H_DEPTH/network audits. V4 PHP lives on production, so GitHub docs do not contain enough exact method signatures to safely invent adapters.
2. Составить конкретную **паритетную карту** `V4 route → existing service/method → V5 route/DTO → gap`. Сначала профиль и бонусы/карта/история как уже работающие операции; выбирать только те методы, которые реально есть в native V4, не придумывать URL.
3. Подключать **реальные V4 business services напрямую из отдельных V5 handlers**, не импортировать V4 HTTP controller/route middleware, cookie session или V4 JWT. Пользовательская идентичность из SERVER-VERIFIED V5 session only; повторная locked authorization в транзакции для защищённых действий. Текущий V5 business compatibility contract может использовать те же поля/данные, но новые DTO менять только там, где требуется СамЗаберу.
4. Независимо завершать нативные V5 guards, которые действительно критичны: первый парольный фактор, TOTP при включении, иначе доказанное сильное доверие или SMS на verified user phone, лимиты против платного спама, краткоживущие access JWT/отзыв, PIN без повторной SMS после доказанного strong login. Не открывать реальные клиентские сеансы, пока trust/TOTP/SMS + native epoch/current-account guards не подтверждены фактически; не выдавать fake auth providers.
5. Публиковать независимые **read-only** V5-методы (например уже существующий legal GET и подготовленный legal validation POST) отдельно от auth, после точного safe deployment/HTTP smoke. Для клиента использовать **evrasia.rest**, без pilot key и без IP allowlist. V4 не менять и не блокировать.

## Что не переносим с V4

- `CUser::Login()` / `LoginInternal()` внутри V5 password proof (side effects/rehash/session/native login).
- `JwtAuthorization::createTokens`, V4 `refresh` и V4 bearer→V5 strong session upgrade.
- Password-only `AUTH_PASSWORD` как достаточное основание доверять устройству.
- V4 SMS-отправку без verified phone, quota, challenge и rate budget.
- Показ PIN по одному V4 JWT или как побочный эффект unsafe SMS/VK V4 flow.

## Точная текущая точка

GitHub branch `feature/samzaberu-api-v5-auth-contract`, последняя подтверждённая до этого решения docs-only HEAD `390fab3aecd91b5c0e119ce2e9b9f52cefca2f69`. В V5 GitHub уже есть подготовленные новые методы legal validation и protected session logout, с SUCCESS CI. **В production V5 AUTH всё ещё OFF**; нет свидетельства запуска нового операционного `upgrade-public-legal-v5.sh` на сайте; публичный `evrasia.rest` пока не имеет подтверждённой внешней E2E приёмки V5.

**STOP для старой команды деплоя:** операторский upgrade-script `site-integration/samzaberu-v5/deployment/upgrade-public-legal-v5.sh` из commit `49f84e97` проводит HTTPS postcheck с `VHOST=evrasia.spb.ru`. После подтверждения основного домена `evrasia.rest` **не использовать тот старый copy-paste запуск как публичную приёмку**. Сначала изменить и проверить доменные/сертификатные guards для основного публичного адреса и выполнить соответствующую rollout/rollback-верификацию; имена site filesystem и origin при этом не переименовывать по одному URL.

**Следующая задача:** узкий pinned READ_ONLY снимок точных V4 business route registrations + точных контроллеров/методов, затем одна итерация подключения уже имеющихся бизнес-сервисов в V5. Ни одного нового synthetic/production account.

## Implementation progress — 2026-10-23, after reuse decision

- Code commit \`81d93456a0b61c996733ef275d204557df77a81d\`: source-only V4 route+controller declaration reader, PINNED old \`api/v4/router.php\`, controller SHA snapshot and static callback hints. [Offline CI #39970491449](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39970491449) SUCCESS.
- The production readout has **NOT yet been executed** and actual additional V4 business route names remain UNKNOWN. Do not infer them from desired V5 DTOs.
- The next step is ONLY the operator's short immutable-blob fetch+READ_ONLY code-map on \`evrasia\`, followed immediately by first real business adapter implementation on V5. No synthetic accounts, no site/DB/SMS writes, and do not enable V5 auth.

