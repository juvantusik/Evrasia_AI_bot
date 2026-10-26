# Evrasia AI Bot — SamZaberu API V5 — handoff 2026-10-26

## 0. ПРИОРИТЕТ № 1: РАБОТАЮЩИЕ HTTP-РУЧКИ ДЛЯ РАЗРАБОТЧИКА «САМ ЗАБЕРУ»

**НЕ заменять реализацию API V5 очередными исследованиями/перепроверками безопасности и повторным переносом готовой бизнес-логики V4.** Пользователь требует срочно реализовать и запустить регистрацию с юрдокументами, signin, trusted-device, TOTP, email OTP, SMS challenge, refresh/logout и PIN. Работать итерациями *реальный endpoint → точечный тест → следующий endpoint*. История микропроверок находится в предыдущем handoff; **не начинать с неё**.

Готовая инструкция для дословной вставки в новый чат: [EVRASIA_V5_NEXT_CHAT_INSTRUCTION_2026-10-26_AUTH_ENDPOINTS_FIRST.txt](EVRASIA_V5_NEXT_CHAT_INSTRUCTION_2026-10-26_AUTH_ENDPOINTS_FIRST.txt).

## 1. Источники и точное состояние

- Repo: `juvantusik/Evrasia_AI_bot`; branch: `feature/v5-production-native-preflight-20261025`. Branch HEAD при подготовке (до handoff): `790765469d1069705408f2450318b953f5e05569`; после сохранения документации новые commits.
- Production: `hostname -s = evrasia`, docroot `/home/site_evrasia/web/evrasia.spb.ru/public_html`, публичный домен `https://evrasia.rest`, Bitrix DB `eurasia_new`.
- Действующая история [EVRASIA_V5_STANDALONE_LIVE_HANDOFF_2026-10-25.md](EVRASIA_V5_STANDALONE_LIVE_HANDOFF_2026-10-25.md), особенно разделы **19–21**.
- Публичные read-only V5: `GET /api/v5/health`, `GET /api/v5/legal/consents`, `GET /api/v5/legal/registration-documents` — ранее проверены, работали; `POST /api/v5/legal/consents/validate` также действовал. Не переписывать.
- Защищенная авторизация `POST /api/v5/auth/signin`: **production 503 v5_unavailable** до установки реальной HTTP factory. Ни одна новая ручка signup/TOTP/email/SMS не подтверждена работающей на production.
- Последний подтвержденный публичный production router SHA256: V5 `a134380714cb2299ee47eb46ab6b248985a863f8aa5b7104290bde14847fa266`, V4 `5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d`. Feature router отличается от production: **не заменять целиком**.
- V4 работает независимо. Не менять V4, сайт, БД или трасты без необходимости и guards.

## 2. Новые ручки: файл и состояние

| Метод | Назначение | Состояние |
|---|---|---|
| `GET /api/v5/legal/registration-documents` | Документы до регистрации без JWT | Уже доступно |
| `GET /api/v5/legal/consents` | Текущий перечень | Уже доступно |
| `POST /api/v5/legal/consents/validate` | Проверка codes/versions | Уже доступно |
| `POST /api/v5/auth/signup` | Новый Bitrix user + legal agreements + confirmation | HTTP/Bitrix code в GitHub; **не deployed** |
| `POST /api/v5/auth/signin` | Password + trusted-device/TOTP/email/SMS | Production **503** |
| `POST /api/v5/auth/totp/verify` | Проверить фактор TOTP | Route+handler в GitHub; **не deployed** |
| `POST /api/v5/auth/email/verify` | Проверить email OTP | Route+handler в GitHub; **не deployed** |
| `POST /api/v5/auth/sms/verify` | Проверить SMS challenge | Route+handler в GitHub; **не deployed** |
| `POST /api/v5/auth/challenge/verify` | Универсальная проверка | Реализован core, нет public factory |
| `POST /api/v5/auth/challenge/resend` | Ресенд, не смешивая email/SMS | Нет public factory |
| `POST /api/v5/auth/challenge/use-sms` | Явный email → SMS fallback | Нет public factory |
| `POST /api/v5/auth/refresh` / `logout` | V5 JWT/refresh/revoke | Core есть; нет public factory |
| `POST /api/v5/loyalty/pin` | PIN в сильной V5 сессии | Core есть; нет public factory |

Код:
- `site-integration/samzaberu-v5/src/V5RouteMap.php` — URL → action.
- `.../src/V5AuthHttpHandler.php` — JSON входы signup / verify / refresh / logout.
- `.../src/V5LoginWorkflow.php` — trusted-first, challenge type-bound verify.
- `.../src/V5ProductionAuthHttpComposition.php` — составные провайдеры MFA, sessions, optional signup.
- `.../src/V5MySqlPublicComposition.php`, `V5MySqlEmailOtp.php`, `V5MySqlStrongSessionStore.php` — ранее написанные реализация factor/session, использовать, не пересоздавать.
- `.../src/V5BitrixNativeRegistrationWriter.php` — **новый candidate**, использует CUser::Add, UserPhoneAuthTable и native Consent::addByContext, но **реальная запись и подтверждение не проверены**, возможны несовпадения сигнатур/политики Bitrix. Нужен непосредственно нужный прикладной тест прежде чем принимать live-пользователей.
- `.../src/V5CurrentWebsiteLegalCatalogPort.php`, `.../src/V5PublicSignupEndpoint.php` — самостоятельная pre-auth регистрация.
- `.../api/v5/router.php` — feature-код dispatch signup; **НЕ production router**.
- `.../tests/V5RegistrationAndFactorRoutesTest.php` — офлайн контрактные тесты, **нет подтверждения выполнения PHP PASS**.

## 3. Подготовленный способ следующего шага

`site-integration/samzaberu-v5/deployment/install-v5-registration-and-factor-routes-20261026.sh`
- GitHub commit `f77e934f340c12a84e07dace5dab3268e43170dd`;
- SHA256 `f5e91cb4c06ba6844526a39e26e522e49297e99032356bb69e1c09f3a345a559`;
- **ПОДГОТОВЛЕН, НА PRODUCTION НЕ ЗАПУЩЕН.** Путь к рабочему `/api/v5/auth/signup`: pinned source+PHP lint, backup, точечный V5-only router patch, invalid-request HTTP probe, rollback. Не включит sign-in.
- Прежде чем дать этот installer к запуску, завершить только критичные для создания реального Bitrix user проверки поведения native writer; не начинать новый многодневный security research.
- Отдельный installer `install-v5-business-http-composition-20261026.sh` также подготовлен, без подтвержденного запуска; не приоритет до auth.

## 4. Неприкосновенные бизнес-правила

1. V4 независим и не переписывается.
2. Мобильное приложение создаёт/хранит `trust_token`; сервер проверяет действующий токен, но не создаёт и не возвращает мобильный trust_token.
3. Валидный trust переходит прямо к сильной сессии, **даже если TOTP включен**. Нет траста + активный TOTP → серверный TOTP. Нет TOTP → допустимый email OTP, при необходимости явный SMS fallback.
4. JWT/refresh/PIN — только после реально подтвержденной сильной сессии; server checks token+epoch/revocation.
5. Регистрация ДО JWT: приложению сначала отдать текущие legal documents (offer+PD обязательно, marketing добровольно), принять `agreements`, повторно проверить свежие коды и записать native Bitrix consents с созданным USER_ID.
6. Нет синтетических аккаунтов на production. Пароли, PIN, SMS, токены, secrets не печатать. При изменениях на production: guards, backup/rollback, один paste block, `FINAL_STATUS`/`FINAL_RC`.
7. **Не блокировать написание/подключение HTTP ручек из-за очередного глобального аудита 10 native paths.** Но никаких обходов второго фактора или вывода JWT без MFA.

## 5. Установленные компоненты и закрытые сопутствующие задачи

- Production V5 `V5ProductionAuthHttpComposition.php` только начальный SHA `693329cf2594ed957f79c7b846088e4a253d19802c335c57d3f83a82cbf71b74`, новая business/MFA композиция не подтверждена установленной.
- V5 `V5ProductionNativeCoverageEvidence.php` в `Controllers/V5/Runtime` уже установлен; пара `V5ProductionNativeSecurityEventBridge.php` SHA `5a3151e46cdc8a1966f4cac095db16b863df7f508a425dfacf22fa8310afc16b` и `V5BitrixOtpNativeMutationPort.php` SHA `d19767ad4761406a3ad7db4ca2c9f992f42554760d6d30773ec66dbaa09501de` уже установлена. Повторить не надо.
- Website legacy бонусный PIN по SMS/ВК для сессий без strong assurance закрыт: `pincode.php` SHA `3ae1a9a3bf9d570cc032bffdd88cb641d57e7032be37f50d6fc701d7a6686eef`.
- Упоминание Google Authenticator удалено из UI сайта (Яндекс Ключ/TOTP остались): template SHA `0c8ca7b10b8816b457405660f74a833706bb33e2d1d2dc33cece8426444e0774`. Оба website patch успешны (operator `FINAL_STATUS=PASS`).

## 6. ЧТО НОВЫЙ ЧАТ ДОЛЖЕН СДЕЛАТЬ, А НЕ ОБСУЖДАТЬ

**Развернуть и проверить сначала signup+consents, затем собрать private V5 auth factory для signin + TOTP + email + SMS + refresh/logout/PIN и получить реальные HTTP ответы.** После этого написать интеграционное ТЗ разработчику «Сам Заберу» с примерами JSON. Не выполнять повторный аудит всей системы и не возвращаться к V4-корзине вместо MFA.
