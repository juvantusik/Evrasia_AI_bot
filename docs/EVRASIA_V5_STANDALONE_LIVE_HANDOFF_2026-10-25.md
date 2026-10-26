# Evrasia AI Bot / СамЗаберу — API V5: подтверждённая точка продолжения (25.10.2026)

**Статус:** независимая исходная копия API V4 уже размещена как отдельный V5 на действующем Bitrix-сервере. V4 продолжает работать; V5 **публично закрыт**, полноценная новая авторизация ещё НЕ подключена. Это authoritative checkpoint после копирования / замены namespace / прямого PIN / удаления автоматической выдачи траста после пароля. Сначала прочитать этот файл; детали — в `docs/EVRASIA_AI_BOT_NEW_CHAT_HANDOFF_2026-10-25_V5_MVP.md` (§37–41).

## 1. Владелец проекта: обязательная стратегия, без повторных споров

- **Копия работающего V4 уже сделана на сервере.** V5 должен оставаться самостоятельным: собственные файлы, namespace и router; НЕ наследовать Controllers/V4, НЕ ходить в /api/v4, НЕ требовать работающего V4 в runtime. Не переписывать заново корзину, заказы, скидки, профиль, источники цен и существующую логику СамЗаберу. Взята исходная бизнес-логика V4, меняем только необходимое для V5.
- Приоритет: быстро выпустить реально работающий API V5. После MVP можно заниматься второстепенным «укреплением». **Нельзя отказываться от обязательной проверки второго фактора/трастов, доступов к чужим данным, целостности JWT и защиты PIN.** Не тратить время на очередные самостоятельные архитектуры или десятки новых mock-only адаптеров.
- Основной публичный домен `https://evrasia.rest`. Сервер hostname `evrasia`; исходный сайт в `/home/site_evrasia/web/evrasia.spb.ru/public_html`; БД Bitrix `eurasia_new`.
- Никогда не изменять production V4; V5 пока остаётся HTTP-отключён до реальной end-to-end проверки. Никаких синтетических гостей, пробных реальных заказов, SMS или списания бонусов без отдельного согласования.

## 2. Фактически создано и проверено НА СЕРВЕРЕ

```
SITE=/home/site_evrasia/web/evrasia.spb.ru/public_html
$SITE/api/v4/                              # действующий V4
$SITE/api/v5/                              # независимая копия V4, редактируем только её
$SITE/api/v4/router.php                    # действующий V4 router
$SITE/api/v5/router.php                    # отдельный V5 router; временный 503
$SITE/local/php_interface/Controllers/V4/  # действующие контроллеры
$SITE/local/php_interface/Controllers/V5/  # 11 самостоятельных копий контроллеров
```

**Выполнено (подтверждено stdout оператора):**
1. Source snapshot V4 56 файлов: `V4_SNAPSHOT=PASS`, 56/56. Копирование V4 в две целевые папки V5: `FINAL_STATUS=PASS, WRAPPER_RC=0`. Прежний экспериментальный V5 сохранён в backup.
2. Namespace: `V5_CONTROLLERS_UPDATED=11`, `V5_ROUTER_CLASS_REFERENCES_UPDATED=40`, `V5_SOURCE_FILES_UPDATED=13`, `PHP_SYNTAX=PASS`, `V4_INTEGRITY=PASS`, `FINAL_STATUS=PASS`.
3. В V5 **только** `Controllers/V5/PinCode.php` установлен обработчик прямого PIN из `CRestis::pincode()` для собственного подтверждённого Bitrix пользователя, без SMS/ВКонтакте, с сохранением ведущих нулей. Установлено `V5_DIRECT_PIN_CONTROLLER=INSTALLED`, PHP lint PASS. ВНИМАНИЕ: `Auth::verifiedStrongUserId()` ещё не реализован; в текущем виде PIN закрыт (возвращает недоступность), не считается готовым E2E.
4. В `Controllers/V5/Auth.php` уже **удалён блок `TrustedDeviceMobileService::registerSuccessfulAuthentication(... AUTH_PASSWORD ...)`**, выполнявшийся сразу после пароля. Повторный запуск поиска ничего не нашёл, потому что блок уже удалён. Это отдельно подтверждено сравнением с исходной копией:
   - `ORIGINAL_TRUST_REGISTRATION=YES`; `ORIGINAL_AUTH_PASSWORD=YES`.
   - `CURRENT_TRUST_REGISTRATION=NO`; `CURRENT_AUTH_PASSWORD=NO`.
   - `CURRENT_EQUALS_LAST_BACKUP=YES`; `ORIGINAL_METHOD_COUNT=9`; `CURRENT_METHOD_COUNT=9`; `REMOVED_METHODS=NONE`; `CHANGED_BLOCKS=2`.
   - `CURRENT_OLD_JWT=YES`; `CURRENT_STRONG_USER_METHOD=NO`; `V5_ROUTER_BLOCKED=YES`; PHP lint PASS; `WRAPPER_RC=0`.
   **Не пытаться повторно удалять этот блок!** Последние две попытки повторить удаление были остановлены SHA-guard/отсутствием уже удалённого блока без изменения файлов.
5. Весь V5 пока закрыт на HTTP: скопированный `api/v5/router.php` содержит начальный ответ `503 v5_under_construction`; есть `api/v5/.htaccess` `Require all denied` и ограничения каталога. **Не открывать до работы V5 Auth.** Старый JWT V4 ещё в `Auth.php`; его реальная замена — главная следующая задача.

**Backups:**
```
/home/site_evrasia/web/evrasia.spb.ru/backups/v5-before-v4-copy.DLXqsAuy
/home/site_evrasia/web/evrasia.spb.ru/backups/v5-namespace.WArkhLvE
/home/site_evrasia/web/evrasia.spb.ru/backups/v5-pin-before.dD4cN8d8
/home/site_evrasia/web/evrasia.spb.ru/backups/v5-auth-trust.VmRZbRHH
```
Не удалять; не переписывать V5 папки очередной копией из V4.

## 3. Новая авторизация и правило PIN

Вход через логин/пароль, затем:
- При **сохранённом и реально сервером подтверждённом действующем старом трасте** — доступ без нового TOTP/email/SMS, даже если траст исторически был зарегистрирован после одного пароля. **Старые трасты не стирать и принудительно не обновлять.** Приоритет над TOTP.
- Если действительного траста нет — серверный native Bitrix TOTP при включённом TOTP; иначе email OTP, **только ручной SMS fallback** при разрешённых условиях. Не отправлять платную SMS автоматически.
- **Новые трасты создавать/регистрировать только после успешного TOTP, email OTP или SMS**, никогда после одного пароля, одного `device_id`, старого trusted-login или JWT refresh.
- После успешной проверки и выпуска настоящей **V5-only** сессии показать PIN в ЛК без повторных SMS и VK; PIN из уже работающего `CRestis::pincode()` по учётной записи пользователя. Нельзя доверять клиентскому USER_ID/телефону или V4 JWT для этого.
- Публичные legal документы и коды согласий до регистрации; обязательные оферта/ПД — сохранять через существующий Bitrix `UserConsent`; маркетинг опционален. Не менять V4.
- В `Controllers/V5/Auth.php` сейчас ещё старый `JwtAuthorization`; `PinCode` ждёт `Auth::verifiedStrongUserId()`. Подключить их к одной доказанной сессии V5.

## 4. Особенно важно: кто создаёт trust_token

**САМОЕ ПОСЛЕДНЕЕ УТОЧНЕНИЕ ВЛАДЕЛЬЦА:** мобильное приложение **само генерирует токен доверия на устройстве**. **Сервер НЕ должен возвращать trust_token приложению** как результат регистрации. Не предлагать вновь «вернуть trust_token», не приписывать приложению отсутствующую логику.

Идентификатор установки мобильного приложения — `device_id` формата `sz_` + 64 lowercase hex (67 символов), отдельно `platform=ios|android`. Токен доверия — отдельная сущность. В действующих PHP и GitHub-контрактах есть `TrustedDeviceMobileService::validatePresentedTrust(userId, {device_id,platform,trust_token})` и hash-based trust storage. **Не смешивать device_id с secret/token**; сервер обязан проверить переданный приложением материал владения и связать его с текущим пользователем, установкой, статусом и сроком действия. Новый V5 должен сохранять доказанный протокол приложения. При несовпадении старого серверного генератора и заявленного клиентского генератора — сначала проверить исходный код и реальный контракт, а не выводить токен обратно и не принимать один device_id как авторизацию.

Важно: на основе предыдущих source-inspection заметок старый `registerSuccessfulAuthentication` создавал серверный токен, который старый `signin` не возвращал. Наличие пассивно накопленных записей **само по себе не доказывает**, что приложение имеет совпадающий текущий secret; сохранение старых действительных трастов — бизнес-правило, но способ подтверждения именно существующего устройства нужно сверить с реальным мобильным протоколом без выдумки. Владелец подчеркнул, что приложение токен создаёт само: использовать новый восьмифайловый runtime archive, чтобы точно сопоставить эти факты.

## 5. Полученные приватные архивы и НОВЫЙ АРХИВ

Ранее владелец прикрепил 5-файловый архив реальной авторизации: `evrasia-v5-auth-src.2ssakqOZ.tar.gz` (V5 Auth, Bitrix website signin/TOTP, AuthAssuranceService, TrustedDeviceWebService); он был изучен. Приватные исходники НЕ публиковать в GitHub и НЕ выводить ключи.

**ПОСЛЕДНЯЯ КОМАНДА В ЭТОМ ЧАТЕ (подтверждён PASS) СОЗДАЛА новый архив восьми файлов:**
```
ARCHIVE=/home/tech/evrasia-v5-runtime.DhwrZNst.tar.gz
SOURCE_FILES=8
V4_CHANGED=NO
V5_CHANGED=NO
DATABASE_WRITE=NO
FINAL_STATUS=PASS
WRAPPER_RC=0
```

Список его восьми файлов:
```
local/php_interface/lib/JwtAuthorization.php
local/php_interface/Controllers/V5/Auth.php
local/php_interface/Controllers/V5/PinCode.php
local/php_interface/website-mfa/EmailFirstLoginRuntime.php
local/php_interface/website-mfa/EmailFirstSessionBridge.php
local/php_interface/website-mfa/EmailFirstManualSmsChoice.php
local/php_interface/website-mfa/EmailFirstSmsRatePolicy.php
local/php_interface/website-mfa/EmailFirstSmsResendGuard.php
```

**Этот новый .tar.gz пока находится только в /home/tech на сервере; владелец прислал ТОЛЬКО stdout, не сам архив.** Следующее действие: получить этот готовый файл через WinSCP/SFTP и приложить к новому чату, **не создавать архив заново**. При необходимости также приложить старый пятифайловый архив, если он недоступен из нового чата. Архивы содержат приватные исходники, не коммитить в публичный repo.

## 6. GitHub: какие изменения уже существуют, а что НЕ стоит переустанавливать

Repo: `juvantusik/Evrasia_AI_bot`. Feature branch: `feature/v5-production-native-preflight-20261025`. Рабочие production V4 PHP НЕ хранятся в GitHub — их уже скопировали непосредственно в установленные каталоги V5.

На GitHub (источники/тесты, **не live V5**) имеются:
- `site-integration/samzaberu-v5/src/AuthDecision.php` — старый валидный траст допускает вход, даже с историческим AUTH_PASSWORD; без траста → TOTP/email.
- `.../src/V5LoginWorkflow.php`; `V5MySqlStrongSessionStore.php`; `V5ExistingMobileTrustProof.php` — образцы V5 strong session, использующие текущий validated trust; не подменять им готовую бизнес-логику V4.
- `.../src/V5BitrixRealRestisPinReader.php` — компонент прямого PIN, уже есть отдельный live V5 PinCode контроллер.
- Пройденные source-only тесты для grandfathering trust и прямого PIN: GitHub Actions `40224770862`, `40222521881`, `40225714406` SUCCESS. Это НЕ подтверждает работающую публичную авторизацию V5.
- В предыдущих handoff-документах встречаются **устаревшие формулировки**, будто старый траст с AUTH_PASSWORD всегда нужно отклонять, либо будто V5 ещё не был скопирован. Этот файл их явно отменяет.

## 7. Обязательная следующая работа — СРАЗУ, без очередного кругового аудита

1. В НОВОМ ЧАТЕ принять уже созданный архив `evrasia-v5-runtime.DhwrZNst.tar.gz`, тщательно сопоставить установленный V5 `Auth.php`, `JwtAuthorization.php` и website-mfa email runtime/bridge с уже изученными website signin/TOTP и trust исходниками.
2. **Проверить реальный контракт генерируемого приложением trust_token и его проверки/записи на сервере**, не предлагать выдавать токен приложению из API, не использовать только `device_id` как доказательство траста. Учесть исторически накопленные записи и избежать массовых SMS.
3. Сформировать и внести **минимальную точечную V5-only правку**: в собственном `Controllers/V5/Auth.php` вместо выдачи старого JWT при одном пароле — реальная схема пароль → действующий old trust ИЛИ TOTP/email/ручной SMS → сессия V5; новые трасты после проверенного MFA; добавить `verifiedStrongUserId()` для готового `Controllers/V5/PinCode.php`. Использовать существующие website components и V5-auth источники, не переписывать Cart/Orders.
4. Сохранить и проверить резервную копию изменяемого V5 файла; проверить PHP lint, локальное подключение к Bitrix и реальный авторизованный тест на разрешённом аккаунте. Не открывать публичный роутер раньше результата. Для deployment скриптов использовать изолированное `bash <<'TAG'`, очистку терминала **`printf '\033[3J\033[2J\033[H'`** в самом начале, без глобального `set -e` в интерактивной root-оболочке (чтобы PuTTY не закрывался). Не выполнять опасные команды со слепым совпадением старого SHA. Не повторять уже выполненные изменения.
5. Только после успешного E2E и явного разрешения снять 503/.htaccess-блокировку V5; V4 оставить функционирующим до миграции мобильного клиента.

Не отвлекаться на отдельный проект BonusBalance/Restis trigger/outbox и его выводы, случайно вставляемые в общий терминал: это отдельная задача, она не влияет на прогресс V5.

**Последний вывод оператора:** новый архив `/home/tech/evrasia-v5-runtime.DhwrZNst.tar.gz` создан и проверен, `FINAL_STATUS=PASS`, `WRAPPER_RC=0`. Именно его содержимое ожидается следующим.

## 8. 2026-10-26 LIVE V5 SESSION DEPLOY CHECKPOINT

Operator-reported production results (V5-only; public HTTP remains 503 + deny):

- V5 Auth Boundary installed in 6 V5 files; copied V4 JWT issuance paths blocked. Backup: `backups/v5-auth-boundary.by91xpwa`. V4 unchanged.
- Session schema `eurasia_new.ev_v5_strong_sessions` was adjusted to `assurance enum('totp','sms','trusted','email')`, with backup and postcheck; installation of 12 V5 store dependency files subsequently PASS.
- Installed five V5 runtime core files (decision, login workflow, JWT, policy, mobile trust proof); `V5MySqlStrongSessionStore` installed; `Auth::verifiedStrongUserId()` now calls `V5StrongSessionGate`, which is unbound/fail-closed until native factory is implemented. Gate backup: `backups/v5-strong-gate.OsJvVyFn`.
- Final accepted V5 native read-only check: `FINAL_STATUS=PASS`; Bitrix `b_user` 5/5 relevant columns; `AUTH_STATE_ROWS=1`, `INVALID_EPOCH_ROWS=0` (previous check); `V5_SESSION_ROWS=0`; 2 triggers on b_user/OTP tables, 6 registered user event handlers. Three `AUTH_EPOCH` references in V5 Runtime libraries, not a confirmed live writer. Only `local/php_interface/init.php` found for user hook source. **No evidence yet that the actual triggers/handlers increment `AUTH_EPOCH` on all password/MFA/block/revocation paths.**
- `V5_HTTP_SESSION_FACTORY_CONNECTED=NO`, `V5_ACCESS_REFRESH_ENABLED=NO`, V5 publicly blocked, no synthetic guests / SMS / email. This means live V5 login and direct PIN are NOT yet functioning.
- **Immediate next implementation blocker:** verify exact existing trigger targets and Bitrix handler bindings (no raw secrets), then implement and verify native auth-state mutation coverage and the V5-only strong-session factory/locked trust proof. Never infer coverage merely from trigger or handler counts; do not allow password-only sessions or V4 JWT. Avoid new cart/orders rewrites and keep V4 untouched.

### 2026-10-26 Mobile Trusted Device count

Operator-verified READ_ONLY production snapshot on `evrasia` / `eurasia_new.ev_trusted_devices`: `ALL_TRUST_ROWS=45146`, `ALL_UNIQUE_DEVICE_HASHES=44765`, `ALL_UNIQUE_USERS=36957`. **Only rows tagged with exact persisted `USER_AGENT` marker `SamZaberu platform=ios/android`:** `MOBILE_ROWS=4`, `MOBILE_UNIQUE_DEVICE_IDS=3`, `MOBILE_UNIQUE_USERS=3`, `MOBILE_ACTIVE_ROWS=4`, `MOBILE_ACTIVE_UNIQUE_DEVICE_IDS=3`, `MOBILE_ACTIVE_UNEXPIRED_TOKEN_ROWS=4`; all four matched iOS, zero matched Android. These are **marker-matched counts, NOT proven complete inventory of all external `sz_...` installations**: the original namespaced device IDs are irreversibly hashed into `DEVICE_ID_HASH`, so neither `LIKE 'sz_%'` nor a total mobile count inferred from all trusted devices is valid. Trusted Device table has **zero native triggers** and no direct trigger-based `AUTH_EPOCH` synchronization for trust revocation; V5 must freshly validate/revoke or bind sessions in a secure transactional scheme. No user IDs, tokens, device IDs were exported; no DB writes or HTTP enable.

### 2026-10-26 Locked mobile trust proof installed; revocation binding design

- Operator installer PASS: V5-only `Runtime/V5LockedMobileTrustProof.php` installed after source SHA/PHP syntax verification. `V4_UNCHANGED`, `V5_HTTP_BLOCKED`, `V5_STRONG_STORE_FACTORY_BOUND=NO`, `V5_ACCESS_REFRESH_ENABLED=NO`, no DB mutation.
- Actual `ev_trusted_devices` has no trigger connected to `AUTH_EPOCH`. Thus a JWT issued after a verified trust MUST subsequently be invalidated on trust token rotation/revocation/expiry, independently of `AUTH_EPOCH`; otherwise a revoked device can keep accessing PIN through an unexpired session.
- Feature-only helper `src/V5TrustedSessionBinding.php` (commit `49b8e096`) and offline test `tests/V5TrustedSessionBindingTest.php` (commit `34720617`) compute a keyed V5 session binding to stored `DEVICE_ID_HASH`, platform, `TRUST_TOKEN_HASH`, and `TOKEN_VERSION`. 15 local assertions PASS incl revoked, rotated, expired, wrong user/platform/token; no plaintext device ID/token in binding.
- **IMPORTANT**: new helper is NOT YET CALLED by `V5MySqlStrongSessionStore`, so revocation is not yet enforced on live JWT/refresh/PIN. Do not install a new factory or issue trusted JWT until binding is persisted at session issuance and checked under the same transaction for every protected action and refresh. Existing `V5LockedMobileTrustProof` validates entry trust only. Future work must add native same-connection Bitrix SQL allowlist proof/coverage and avoid modifying V4.
- Old V4 mobile registration generated the trust token on server and did not necessarily expose it to the app; do not infer that 4 marker-matched rows mean 4 clients possess their tokens. New client-owned token enrollment after MFA remains required.

### 2026-10-26 V5 trusted-session revocation implementation and CI

- Feature-branch implementation commit `0882e814fa6571319d4bd950321af0ffcab191cb`: `V5MySqlStrongSessionStore.php` binds trusted session `device_binding` to server-locked current device/token hash, platform and `TOKEN_VERSION` using `V5TrustedSessionBinding.php`. At every access-validation, refresh, PIN and other protected session action, it re-reads the user's current trust rows under `FOR UPDATE` and denies revoked, rotated, expired, missing or mismatched trust. TOTP/email/SMS session semantics unchanged. No raw token/ID in V5 strong-session table.
- `V5BitrixPdoCompatibilityBridge.php` exact SQL whitelist updated for two narrow user-scoped `ev_trusted_devices` row-lock reads and the extended session row projection; arbitrary shared trust SQL remains denied. Existing V4 service untouched.
- GitHub Actions runs `40263457814` (V5 mobile trust revocation safety) and `40263457738` (V5 checkout source-only admission) both SUCCESS for commit `0882e814`. Offline tests: 15 binding assertions, 354 transactional strong-session assertions, 222 synthetic native Bitrix E2E assertions, PHP lint PASS. No real Bitrix production sessions tested; CI is offline.
- A guarded operator installer `evrasia-v5-trust-revalidation-install-20261026.sh` prepared locally to update **only** Runtime/`V5MySqlStrongSessionStore.php` and add Runtime/`V5TrustedSessionBinding.php`, pinned to commit `0882e814`; expects old store blob `13d86396172f789b0eb792240370e9660bcffa4e`, new store blob `59717c54507a6733abdea987438fb69cf8f2708f`, helper blob `bd24cdb27b8856e63df8e7586905a22e64509c42`. Not yet operator-executed/production-verified as of this note. Public V5 503 + .htaccess deny retained. The native factory, epoch coverage attestation and live access/refresh issuance are still NOT connected; V4 remains active.
- Next: after operator installer PASS, develop/attest native Bitrix transaction factory and epoch mutation coverage; do not label strong-session HTTP enabled or real JWTs issued until end-to-end tested. Preserve app-generated trust token rule and V4 independence.

### Trusted Device replacement policy (user clarification 2026-10-26)

**User-confirmed intended business rule (not yet evidenced as deployed):** for each user, retain no more than **one active browser trusted-device identity and one active SamZaberu mobile installation trust** at a time. A newly strongly verified and enrolled device **replaces/revokes the previous trust in that same category**, while preserving the other category's trust (browser versus mobile). A mere password attempt or observation of new `device_id` is not sufficient to revoke/re-enroll; require successful strong admission/MFA. Historical DB rows may remain for audit but must not be accepted as active trust. Any already-issued V5 JWT/refresh tied to a replaced mobile token must immediately cease to authorize; installed V5 revalidation gate already supports denial **once revocation/rotation is persisted**. Changes to shared production V4 TrustedDeviceService/registration are NOT implicitly authorized; establish actual existing production replacement behavior and implement minimal safe compatible changes only after source/transaction evidence. **Do not treat this intended rule as implemented:** production `ev_trusted_devices` has unique `(USER_ID, DEVICE_ID_HASH)`, not `(USER_ID, client_category)`; existing trusted device rows accumulate. Previously documented `4` marker-matched mobile rows / `3` unique mobile hashes / `3` users do not demonstrate `3` active mobile devices for any one user. The keyset pagination implemented on feature for existing legacy data is defensive compatibility, not a new policy to allow multiple concurrent active devices.

### 2026-10-26 Exclusive mobile trust source discrepancy and V5 scope

- The operator's archived deployed-site **snapshot** (`local/php_interface/lib/Services/TrustedDeviceService.php`, SHA256 `58e107742f5a40b1b28cd439bee5b9721b020ddb25873feb01b79a404c644417`; `TrustedDeviceMobileService.php`, SHA256 `e6b47cac5d5ea747e082e114970f8cf3b83ea6b84a9d3e0f1341b030773c0e7e`) shows a concrete **case-sensitive legacy exclusivity bug**: the mobile wrapper passes `$exclusive ? 'SAMZABERU_' : null` to the shared core, but its real persisted `USER_AGENT` starts `SamZaberu platform=ios` or `SamZaberu platform=android`. The core only revokes old rows when `str_starts_with($existingAgent, $exclusiveUserAgentPrefix)` returns true. Also the ordinary `registerSuccessfulAuthentication` default is `$exclusive=false`; the exclusive path appears inside `transferAuthenticatedSession`, not the normal registration path. No evidence the old transfer method is currently called. This means **a method for replacement exists but is not proven to enforce the user-accepted policy**. Snapshot is NOT a fresh production source attestation: before any live fix, compare installed source and deployment paths under read-only guards.
- Existing observed production aggregation: 4 active marker-matched mobile rows / 3 users / 3 device hashes. Because 4 active mobile rows represent only 3 distinct users, **at least one user has more than one active mobile trust row**. These are not raw device IDs and do not count every external `sz_` installation. Do not print user IDs/tokens/hashes.
- New V5-only `src/V5MobileTrustReplacementPolicy.php` and `tests/V5MobileTrustReplacementPolicyTest.php` explicitly match actual stored `SamZaberu platform=ios/android` namespace, reject `SAMZABERU_`, exclude browser/other accounts/revoked rows, preserve current target device, and compute same double hash over full `sz_...64hex`. Commits `78e7e9e2`, `367fb60a`; offline CI run `40264296601` SUCCESS with 32 replacement-scope assertions plus 15 binding, 356 strong session, 234 native bridge assertions. **This is source-only candidate policy, not a live writer and not yet installed.**
- Next engineering step: implement atomic **V5-only enrollment after verified strong MFA**, accepting a token generated on the mobile client, using exact existing core user-wide MySQL advisory lock (`ev_td_u_` + first 56 hex of SHA256(user ID)), the same native Bitrix transaction/row locks and audited revocation of other mobile rows only. Do not reuse the core method that generates/returns server token. No change to V4 or browser trusts without explicit approval. Confirm exact production source and existing event/table schema first; keep public V5 closed until native factory/epoch coverage/actual E2E proven.

### 2026-10-26 PROD verified mobile exclusivity bug + V5 native writer candidate

**PRODUCTION READ-ONLY direct evidence:** Operator on `evrasia` verified installed `TrustedDeviceService.php` SHA256 `58e107742f5a40b1b28cd439bee5b9721b020ddb25873feb01b79a404c644417` and `TrustedDeviceMobileService.php` SHA256 `e6b47cac5d5ea747e082e114970f8cf3b83ea6b84a9d3e0f1341b030773c0e7e`. Exact legacy mobile exclusivity prefix mismatch confirmed live: `SAMZABERU_` vs `SamZaberu platform=`. `ACTIVE_MOBILE_ROWS=4`, `ACTIVE_MOBILE_USERS=3`, `ACTIVE_MOBILE_HASHES=3`, `USERS_WITH_MULTIPLE_ACTIVE_MOBILE_ROWS=1`, `MAX_ACTIVE_MOBILE_ROWS_PER_USER=2`; zero PII printed, V4/V5 DB unchanged, public V5 blocked, `FINAL_STATUS=PASS`.

**Implementation in GitHub only (NOT deployed or wired):** `src/V5NativeMobileTrustReplacement.php` at commit `33c64bc409abc975ff002d5a52e52bb068b7554a` implements the site-native Bitrix ORM mutations using the exact `SamZaberu platform=ios/android` scope, client-generated token hashed server-side, same trust-user advisory mutex ownership proof, V5-only existing-session revocation and native device auth events; registered browser trust untouched. This class **requires consumed TOTP/SMS/email strong MFA grant verification and native transaction ownership** (not password-only). `V5BitrixPdoCompatibilityBridge.php` gained `assertOwnedNativeAuthMutationContinuity()` at commit `d4ea2062`, rejecting any non-owned/non-strict HTTP native mutation. `V5NativeMobileTrustReplacementTest.php` is a fail-closed unowned transaction test. GitHub Actions `40264974349` SUCCESS for commit `70a121dd`, including PHP lint of new writer, 4 negative writer assertions, 32 scope policy, 15 binding, 356 strong store and 234 native bridge synthetic assertions. These **do not** establish real production Bitrix ORM transaction or strong-MFA E2E acceptance. **No native production factory is connected; no live write done.**

**Critical remaining integration:** acquire the existing `ev_td_u_` user-wide advisory lock **before** beginning V5 strong-session issuance; keep it held **through COMMIT/ROLLBACK**. Within that same strictly guarded native transaction: lock current `b_user`/epoch, revalidate consumed grant/MFA, invoke the native mobile replacement, revoke earlier V5 mobile sessions, insert ONE new V5 strong session; commit; release user mutex; only then return access/refresh. Do not call current writer independently from HTTP/CLI or before this invariant is satisfied. Also production provider to attest native 10-path epoch mutation coverage is still absent; keep V5 HTTP OFF and V4 untouched. No automatic rewrite/revoke of currently duplicated real records solely from timestamps — operator ownership not proven.

### 2026-10-26 Atomic post-MFA mobile trust enrollment composition

**New feature-only implementation (not deployed):** `V5NativeMobileTrustUserLock.php` (commit `69f53b16`) holds same production `ev_td_u_` MariaDB mutex before strong V5 session transaction and until after commit/rollback; requires strict protected V5 HTTP ingress and pinned TrustedDeviceService source. `V5MySqlStrongSessionStore.php` gains `afterConsumedChallengeAndEnrollMobileTrust()` (commits `90409ace`, `c6df47ed`), requiring consumed TOTP/SMS/email proof under b_user/epoch lock and app-created 64hex trust token; inside same session issuance transaction invokes `V5NativeMobileTrustReplacement` to rotate/create this mobile trust, revoke old mobile-only trusted devices + other V5 mobile sessions, persist trusted-device audit events and then insert one new V5 session; returns JWT only after commit/mutex release. Browser trust unaffected; shared V4 login/registry unchanged; NO secrets returned. Pinned native bridge `assertOwnedNativeAuthMutationContinuity()` enforces owned native transaction for Bitrix ORM writes.

**Offline CI:** `V5 mobile trust revocation safety` GitHub Actions run `40265150305` SUCCESS at commit `1377ddb9` (before last audit-auth-method refinement `c505e68f`): PHP lint new locker/writer/store, fail-closed unowned MFA writer 4 assertions + unowned enrollment store 2 assertions, previous 15 binding/32 scope/356+ strong store/234 native bridge. Tests do **NOT** attest real production native transactions/HTTP, no V5 factory wired, no live DB writes. Last source commit `c505e68f` aligns CREATED_METHOD and event AUTH_METHOD with SMS vs password+TOTP/email.

**Next:** (1) verify CI for latest `c505e68f`; (2) implement release-grade native exclusive transaction permit and 10-path security AUTH_EPOCH coverage (currently NOT attested), provide full tested V5 private HTTP factory; (3) isolated real Bitrix+MariaDB test for MFA -> native trust enrollment -> other mobile trust/V5 session revoked -> new JWT, rollback/race; (4) guarded V5-only install, then carefully controlled V5 HTTP activation. Do not modify existing V4/mobile shared core source or auto-revoke currently duplicated real account based only on timestamp. Current production remains V5_HTTP_OFF, FACTORY_BOUND=NO, ACCESS_REFRESH_ENABLED=NO.

### 2026-10-26 Production native protected bridge + strong-session factory (source-only)

- Added feature-only `site-integration/samzaberu-v5/src/V5ProductionNativeExclusiveTransactionPermit.php` (commit `9c8c0dfb`), implementing the actual `V5NativeBitrixExclusiveTransactionPermitPort` for an exact pinned protected `/api/v5` HTTPS request on Bitrix's SAME physical production native master mysqli. It consumes permit before every success/failure (no retry/foreign BEGIN), checks strict `V5ProductionNativeRequestBoundaryGuard` and `V5ProductionNativeTransactionPreflight`, and leaves post-BEGIN unpredictable savepoint continuity to existing bridge.
- Added `src/V5ProductionNativeBridgeFactory.php` (commit `6487cea7`) to obtain existing `Application::getConnection()`, verify protected FPM route, create the strict compatibility bridge on the SAME connection with `requireNativeContinuity=true`; no independent PDO connection, no DB write, no Bitrix bootstrap, no router registration.
- Added `src/V5ProductionStrongSessionFactory.php` (commit `1a77832b`) composing the above bridge with the existing independent V5 access/refresh codecs, `V5MySqlStrongSessionStore`, `V5MySqlTokenValidation`, and strict ten-path `V5StrictNativeSecurityHookCoverage`. Requires a separately trusted `V5TrustedInstalledNativeCoveragePort` attesting the installed release before a context is returned and rechecks per-user coverage before session issuance/action. **There is currently NO production implementation of the required complete ten-path evidence port or all real MFA providers. No public site factory is bound and V5 still HTTP 503/.htaccess-denied. Do NOT treat source availability as production readiness.**
- Added offline tests `tests/V5ProductionNativeBridgeFactoryTest.php` and `tests/V5ProductionStrongSessionFactoryTest.php` proving fail-closed CLI/no-native-bootstrap/no-release-callback behavior, one-use permit and invalid keys. GitHub Actions `V5 mobile trust revocation safety` run **40265715414 SUCCESS**, commit `14427548bab993ea774a70741be9502fe45c91a3`: 11 native permit, 6 production strong factory release checks, 15 trust binding, 32 replacement, 4 unowned writer, 358 session and 234 native synthetic E2E assertions, all PASS; PHP lint PASS. Offline tests only; real protected HTTP/native Bitrix release not yet tested.
- Next strictly staged work: implement production-installed native mutation evidence for the 10 declared `V5NativeMutationPath` cases (current installed trigger audit demonstrates four b_user/b_sec_user trigger definitions but does not alone prove full policy, logout and phone mutation coverage); independently validate site-owned password / MFA proof and authorise real native Bitrix transactional staging E2E, then connect a private V5 HTTP factory. Do not deploy fake coverage or enable HTTP until those gates pass. Production code/DB/V4 unchanged in this checkpoint.

### 2026-10-26 Required real native trigger fingerprint gate

- Feature-only `src/V5ProductionNativeEpochTriggerInventory.php` at commit `2b001723` reads four exact `information_schema.TRIGGERS` entries through the **same physical Bitrix master mysqli** as the guarded native factory, checks `TRIGGER_NAME`, table, INSERT/UPDATE/DELETE event, AFTER timing and SHA2(ACTION_STATEMENT,256). SHA pins are grounded in 2026-10-26 operator-produced production hashes for `ev_v5_b_sec_user_auth_epoch_ad` `53742ec9...`, `ai` `b37acc9e...`, `au` `cfe69b7a...`, and `ev_v5_b_user_auth_epoch_au` `4f9bd316...`. SQL bodies are never logged. Mismatch/missing/extra/unknown/foreign connection fails closed. This is a **necessary prerequisite only**, not proof of all 10 native mutation paths, concurrent atomicity, phone events, MFA or logout.
- `src/V5ProductionStrongSessionFactory.php` now checks this live trigger SHA identity before calling independently supplied `V5TrustedInstalledNativeCoveragePort::releaseVerifiedOnThisServer()`; commit `6d0a80d7`. No live factory bound, route remains 503 and .htaccess denied. The external evidence provider is still missing for the 10 native mutation paths. `tests/V5ProductionNativeEpochTriggerInventoryTest.php` has 20 targeted assertions including altered body, missing/duplicate/wrong event/namespace; commit `657f8fe8` fixed one erroneous synthetic test mutation (no product issue).
- CI run `40266219631` **SUCCESS**: PHP lint, 20 trigger-fingerprint assertions, 15 trust binding, 32 mobile replacement, 4 unowned native writer, 11 permit, 6 strict factory, 358 strong session, 234 native Bitrix bridge. All offline; **NO real Bitrix production trigger query or positive live transaction test yet**. No production/database writes.
- Next real blocker is NOT trigger identity (now source-guarded) but *independently proven event coverage*, particularly `b_user_phone_auth` confirmation/reconfirmation/revocation, `b_sec_recovery_codes` mutations, and logout/session invalidation. Obtain targeted read-only metadata from those native tables and actual hook source paths without printing PII/SQL secrets, then design atomic V5-only event synchronization and staging race tests. Never claim ten-path coverage from the existing four trigger hashes alone.

### 2026-10-26 Native phone/recovery epoch event gap and disposable MariaDB acceptance

**Read-only live production evidence provided by operator:** `b_user_phone_auth` and `b_sec_recovery_codes` exist with their expected native columns; **zero triggers and zero AUTH_EPOCH references** on both. `b_module_to_module` counts for main events: `OnAfterUserLogin=1`, `OnAfterUserUpdate=2`, `OnBeforeUserDelete=3`, `OnAfterUserLogout=0`, `OnBeforeUserLogout=0`. `FINAL_STATUS=PASS`, `MODE=READ_ONLY`, no user/device/token data retrieved, production V4/V5/DB unchanged, V5 HTTP still blocked. Note: absence of a global website logout hook does **not** negate V5's own exact-session logout `V5MySqlSessionLogout::revoke()` and `V5MySqlStrongSessionStore::revokeAccess()`; don't globally revoke unrelated mobile sessions on browser logout without an explicit policy.

**Implemented only in GitHub feature branch, not deployed:** `schema/PROPOSED_native_phone_recovery_epoch_triggers.sql` (commit `164d6954`) proposes 6 `AFTER INSERT/UPDATE/DELETE` triggers: 3 on native `b_user_phone_auth` (INSERT/DELETE always bump existing epoch; UPDATE only if number/CONFIRMED/owner changes), 3 on native `b_sec_recovery_codes` (INSERT/DELETE always; UPDATE only if USED/CODE/owner changes). They update **only already-existing** `ev_samzaberu_auth_state.AUTH_EPOCH` rows inside the native InnoDB statement transaction, never initialize unknown accounts and never alter native data, phone event version or binding HMAC. Unrelated attempts/date updates do not bump. They do **not** prove same-number reconfirmation with identical values; the existing `V5SecurityEventVersionWriter::recordPhoneConfirmed` still needs a reviewed same-transaction native confirmation callsite, plus initial per-user state and all 10 path evidence. These triggers are a candidate and might affect shared V4 native DML locking; require staging and rollback before production installation.

**Real disposable DB CI:** `tests/integration/V5NativePhoneRecoveryEpochSchema.sql` and `V5NativePhoneRecoveryEpochTriggerFixture.sql`, workflow `.github/workflows/v5-native-epoch-atomicity-ci.yml` test actual ephemeral MariaDB 11.4 InnoDB with **no real Bitrix accounts, no production connection**. CI `40266857192` SUCCESS with **23/23** trigger/rollback assertions. Follow-up CI `40266929838` SUCCESS for commit `cf08d1ae0667c44f28d898ee851d270502f56092`: 6 triggers created, 23/23 assertions, and two independent OS/MySQL processes racing same user epoch increment **both preserved**; `FINAL_STATUS=PASS`, production write=NO. Do not claim production Bitrix MFA flow acceptance from these isolated DB tests.

**Remaining release blockers:** show same-connection atomic Bitrix operation for phone confirmed/reconfirmed (including same-number reconfirmation), MFA recovery/OTP lifecycle, and secure per-session V5 logout, complete native 10-path coverage and first real trusted release provider; verify event order/deadlocks with actual Bitrix in physically isolated staging. Do not install 6 candidate triggers or enable public V5 until relevant native tests, backup and rollback plan are accepted. V4 remains independent and all existing trusted-device records unchanged.

### 2026-10-26 OPERATOR DIRECTIVE: ONLY LIVE BITRIX EXISTS

**FINAL USER DIRECTIVE, HIGHEST PRIORITY (2026-10-26):** THERE IS NO ISOLATED / STAGING / TEST BITRIX SERVER, AND THE USER WILL NOT CREATE ONE. Do NOT suggest, demand, wait for, or plan any test or release against an isolated Bitrix installation. The ONLY real Bitrix host is live production **evrasia** (`/home/site_evrasia/web/evrasia.spb.ru/public_html`); public domain is `evrasia.rest`. User explicitly orders building and deploying **working API V5 on that live host NOW**, using the separately copied `api/v5` + `Controllers/V5` sources, with V4 completely independent and unchanged. Development/source edits, narrow preflight checks, backups, guarded V5-only rollout, and tests against the real Bitrix server are accepted. NEVER create synthetic users, send test SMS, or change V4. Do NOT substitute endless offline tests, source pins, staging E2E, architectural proposals or extra evidence layers for delivering functional live V5 endpoints. Prior assistant repeatedly violated this by recommending nonexistent isolated Bitrix; do not repeat.

**Actual last confirmed live state:** live `api/v5/router.php` begins with `503 v5_under_construction`, `api/v5/.htaccess` denies all, and `V5/Auth.php::signin` uses copied legacy `CUser` login/token behavior, so blindly lifting the 503/.htaccess block can expose **password-only legacy tokens without V5 MFA or independent V5 JWT**. Do not open all legacy V5 routes indiscriminately. Replace/guard V5 auth route with verified V5-only auth implementation and enable in controlled phases on the SAME live host. Keep all backups and production guards; implement real functions rather than unbounded probes.

### 2026-10-26 USER DELIVERY PRIORITY: LIVE V5 API FIRST

- User explicitly orders: **first real working API V5 endpoints on live evrasia, then updated developer TZ and actual API contracts, ONLY AFTER THAT optional Bitrix security enhancements.** No isolated Bitrix exists, no request to build one. Do not restart generic validation loops, change V4, invent users, or treat offline code as delivered API.
- Operator-confirmed 2026-10-26 live: public `/api/v5/router.php` SHA256 `804638f1b6ad1c6ebd7c221ac5981205848a84b1a9de6b07fd6e05f6a343e01f` returns `v5_under_construction`, public `/api/v5/.htaccess` SHA256 `fa28d421a28ed9b6f4b997d5119c2b8adbacff6e2dd119a179b71230f9664934` contains `Require all denied`, V5 `Auth.php` SHA256 `033acd7fd153416482a2002f1cae3d6cba40316d84c452f61244a1e230ab2d5f`. Real HTTPS GET health/legal and POST signin all returned HTTP 403 at `evrasia.spb.ru` origin 192.168.103.141 due to Apache deny. `local/samzaberu-v5/api/v5/router.php` and `site.php` exist; V5 native factory 15 source files installed already, but `V5_STRONG_FACTORY_WIRED=NO` and access/refresh OFF. User's last failed V5 installer `UNREVIEWED_V5_HTTP_DRIFT` resulted from a WRONG expected public router SHA (`6886...` versus real `8046...`), with zero writes.
- A new one-shot live installer is authored as conversation artifact `evrasia-v5-live-route-activate-20261026.sh` (not yet operator-run), pinned to the two exact live public hashes; it replaces only public V5 router/.htaccess, delegates only public health/legal routes to already installed local V5, denies legacy copied Auth/JWT. Backups and automatic rollback on HTTP mismatch; proves health 200, documents 200, signin 503 and V4 unchanged. This **does NOT finish auth V5**; immediately after its operator PASS, wire native V5 strong signin/MFA/access-refresh rather than return to unrelated diagnostics.


## 9. 2026-10-26 LIVE PUBLIC HTTP AND AUTH BINDING VERIFIED

**Production operator confirmed 2026-10-26:** V5-only public Apache rewrite repair succeeded and was verified through both direct Apache HTTPS :8443 and public nginx HTTPS :443 for the live `evrasia.rest` host. Old V4 code untouched. Last confirmed result:

- `GET /api/v5/health` HTTP 200 (Apache AND nginx).
- `GET /api/v5/legal/registration-documents` HTTP 200 (nginx).
- `POST /api/v5/auth/signin` HTTP 503 (intentional fail-closed; not operational).
- `FINAL_STATUS=PASS`, `FINAL_RC=0`, `V5_DIR_MODE=755`, `ROLLBACK_STATUS=NOT_NEEDED`.
- Backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/v5-public-rewrite.taEI9HS5`.
- Pinned live router fingerprint reported after activation: `a134380714cb2299ee47eb46ab6b248985a863f8aa5b7104290bde14847fa266`.

**Latest read-only live auth inventory:** `Controllers/V5/Auth.php` SHA256 `033acd7fd153416482a2002f1cae3d6cba40316d84c452f61244a1e230ab2d5f`. `signin()` still returns 503 before copied legacy `CUser::Login`. `Auth::verifiedStrongUserId()` delegates to `V5StrongSessionGate`, but `V5StrongSessionGate::bindVerifiedStore()` has 0 call sites. All 5 key installed V5 runtime classes syntax OK; 32 V5 runtime PHP files listed by user, including `V5ProductionStrongSessionFactory`, strong-session store, challenge and trust components.

**Exact unresolved release-critical gap:** scoped installed PHP source inventory of 163 files found **0 implementers** of `V5TrustedInstalledNativeCoveragePort`; **0 call sites** for `V5ProductionStrongSessionFactory::buildForProtectedHttp()`; **0 call sites** for `V5StrongSessionGate::bindVerifiedStore()`. Existing `V5LockedStrongSessionProofPort`, `V5PasswordProofPort`, `V5TrustProofPort`, `V5AtomicChallengePort`, `V5StrongSessionPort` have implementations; not evidence that the complete auth chain works. Feature `V5MySqlPilotComposition` wires pilot ports but requires trustworthy coverage, MFA, account and session providers; cannot be declared production-ready by merely switching its pilot flag.

**Next:** implement and verify the genuine installed native mutation coverage evidence/provider and necessary locked proof, then bind the existing V5 strong session store and HTTP factory to the V5-only router. Preserve device-generated trust-token semantics, trusted-first priority, real Bitrix TOTP/email OTP/manual SMS and V4 independence. Do not bypass MFA, substitute `true` for native coverage, or route copied password-only `Auth::signin`. Public health/legal remain live and should not be reinstalled/reverted.


## 10. 2026-10-26 Installed trigger hashes and trusted-session runtime use

Latest verified `evrasia` production READ-ONLY inspection (`FINAL_STATUS=PASS`, `FINAL_RC=0`): `eurasia_new` has the four V5 AUTH_EPOCH trigger rows with names and SHA256 exactly matching `V5ProductionNativeEpochTriggerInventory.php` (3 on `b_sec_user`, 1 on `b_user`). There is an independent `ev_web_email_ownership_revoke_au` trigger on `b_user`; **zero triggers** on `b_user_phone_auth` and `ev_trusted_devices`. This proves presence and fingerprints, **NOT** complete ten-path authentication mutation coverage.

Installed `Controllers/V5/Runtime/V5MySqlStrongSessionStore.php` SHA256 `7ded20f394088e68c68d448d012a49925283b0969794f6096f4f2736f21add03`. It already has **two runtime calls to `V5TrustedSessionBinding::`** (plus import), 13 references to lockedEpoch, one nativeCoverage check, rotateRefresh and revokeAccess. Source of the matching repo class calls `::presented` at trusted issuance and `::current` within `validatedState()` under a locked transaction when authorizing already-issued trusted sessions. **Do not repeat the earlier incorrect statement that trust revocation binding is absent or implement a second one without confirming installed behavior.** No trusted-device SQL trigger by itself is not proof of a revocation hole if current locked trust binding is checked per action.

Installed `local/php_interface/init.php` registers four static `main` events, `OnAfterUserAuthorize`, `OnBeforeProlog`, `OnBuildGlobalMenu`, `OnPageStart`; no evident static password/logout/phone native AUTH_EPOCH registration from this source. That inventory cannot establish all dynamic event handlers. A separate scan of 123 site PHP source files found no AUTH_EPOCH marker in those scanned paths, but native SQL triggers DO exist. Neither code scan nor fingerprints alone prove phone reconfirmation, mandatory MFA policy, site logout, or user-specific 10-path coverage.

**Unresolved practical blocker stays specific:** missing real `V5TrustedInstalledNativeCoveragePort` implementation/evidence and private HTTP wiring (`buildForProtectedHttp`/gate bind both zero call sites); also ensure production MFA/password/challenge providers meet the existing fail-closed contracts. Public HTTP health/legal are confirmed live; V5 signin must remain closed until these are proven. Do not modify V4, duplicate trusted-session binding or create another JWT design.


## 11. Coverage implementation begun after final native event inventory

2026-10-26 operator supplied `b_module_to_module` READ_ONLY event registry: 21 main/security event types, including `main/OnUserLogout` (3 standard sale/subscribe handlers), `main/OnAfterUserUpdate` (2 search/security handlers), with **no verified V5 AUTH_EPOCH logout/phone/reconfirmation coverage handler** in the printed registry. Registry names do not attest that user-specific security changes are reflected transactionally in the V5 epoch.

Code implementation started in feature branch, commit `5e0f505bc1b3a33718649b5a69b931773496ad8b`: `site-integration/samzaberu-v5/src/V5ProductionNativeCoverageEvidence.php`. It implements the missing `V5TrustedInstalledNativeCoveragePort` *as a safe fail-closed baseline*, verifies the existing four pinned trigger identities using the existing inventory and explicitly enumerates the ten unverified release paths. **It returns `null` for `releaseVerifiedOnThisServer` and `proofForUser` until genuine native per-user writer and concurrency evidence is implemented**; it does not claim coverage, does not enable signin or V5 JWT, and is NOT yet deployed onto production. A syntax-equivalent local candidate was PHP linted. Do NOT describe this as a completed production auth provider or as live V5 signin.

Next engineering change must implement actual site-native security-event mutation ownership/coverage (including phone reconfirmation, logout and mandatory-MFA policy) with real verified transaction semantics on this Bitrix version, then complete the provider rather than substitute a boolean or static event-registry counts. Only after validated providers exist should existing `V5ProductionStrongSessionFactory` and `V5StrongSessionGate` be wired to protected HTTP. Current live public V5 health/legal remain HTTP 200 and signin 503.


## 12. 2026-10-26 Production HTTP auth composition implementation

**Code created, not activated:** feature-branch commit \`88bcc1d1963a220feb208e4e1dd92c5ad26bbe69\` adds \`site-integration/samzaberu-v5/src/V5ProductionAuthHttpComposition.php\`. It is the first concrete **production HTTP auth wiring** (previously only V5MySqlPilotComposition existed): it assembles existing V5MySqlConsumedChallengeProof, V5ProductionStrongSessionFactory, same-native-transaction challenge consumption, password-bound TOTP issue, V5LoginWorkflow, V5MySqlSessionActions, V5MySqlSessionLogout and V5HttpTransportBoundary. Explicit native evidence, password, trust, factors, PIN and independent JWT/refresh codecs remain required typed server-side dependencies; optional email verify/consume ports must be supplied as a pair; SMS recovery remains explicit. Invalid/CLI ingress or keys fail closed before calling the native factory. **No new V4 login/JWT is used.**

Test commit \`886d5bfcfa2fc9584ed6e4200e2559e8109be98a\`: \`tests/V5ProductionAuthHttpCompositionTest.php\` (offline negative/source contract; no Bitrix/DB). Local candidate lint and 17 offline assertions passed. GitHub production composition source blob verified to match local candidate \`e2a14b8f063e5c2056e9503ff4c54b15302c9e50\`, local SHA256 \`8655162b63c192d85d736d9efbeb3c7735527533f70831824ea14ae6784131bd\`.

**Do not misreport as live auth:** The production HTTP router is NOT wired to this new composition, the V5StrongSessionGate is NOT bound, and the real native 10-path \`V5TrustedInstalledNativeCoveragePort\` release provider remains unverified; the current fail-closed baseline returns null. Login continues to return 503; public health/legal 200. Next implementation must establish REAL native security mutation ownership and independent release evidence, then server-only auth providers and protected HTTP factory binding, then actual user-allowed E2E. V4 remains independent and production DB was not modified by these repository commits.


## 13. 2026-10-26 Production composition installation path correction

Operator's `EV_V5_INSTALL_AUTH_COMPOSITION` deployment failed safely (`FINAL_RC=20`, `MISSING_DEPENDENCY=V5ProductionStrongSessionFactory.php`, `ALREADY_APPLIED=NO`, `ROLLBACK_STATUS=NOT_REQUIRED`) because installer searched `local/samzaberu-v5/src` whereas the factory was earlier installed in `local/php_interface/Controllers/V5/Runtime`. NO production modification occurred. **Do not copy/symlink the runtime factory/store into src merely to satisfy the old installer:** the two V5 directories contain overlapping `Evrasia\\Samzaberu\\V5` PHP symbols and careless simultaneous loading would cause class redeclaration or mismatched versions.

Feature commit `8191361df2269474876a3e3d912eda19db2c3121` removes all seven relative `require_once __DIR__` imports from the new `src/V5ProductionAuthHttpComposition.php`. It is now an inert, class-loader-neutral composition until an explicitly reviewed private production ingress supplies a single coherent set of class dependencies; no route automatically imports it. Verified fixed source SHA256 `693329cf2594ed957f79c7b846088e4a253d19802c335c57d3f83a82cbf71b74` and Git blob SHA `40537cde9d54e61578d297730d1a0811739542f7`. Local `php -l` and existing offline CLI fail-closed assertions PASS (17 assertions before the new regression assertion). Regression test updated with a check for cross-tree imports in commit `b2519d0440d8c7cd4c91262f1993205ba6454a45`.

**Safe next deployment:** install *only that inert updated composition source* under `local/samzaberu-v5/src` with exact SHA guard, PHP lint, rollback, and no dependency-copy or public router changes. Afterward full functionality still requires one reviewed private autoload/dependency tree, real 10-path `V5TrustedInstalledNativeCoveragePort` evidence and private HTTP binding. Auth `signin` intentionally remains HTTP 503; health/legal HTTP 200; V4 untouched.


## 14. 2026-10-26 Composition successfully installed in live V5 source tree

**Operator-verified production result:** revised fixed `V5ProductionAuthHttpComposition.php` installed in `/home/site_evrasia/web/evrasia.spb.ru/public_html/local/samzaberu-v5/src/V5ProductionAuthHttpComposition.php`. HTTPS public router pinned during install. Result: `PASS: PRODUCTION_AND_RUNTIME`, `PASS: PINNED_SOURCE`, `PASS: INSTALLED`, `PASS: V5_COMPOSITION_READY`, PHP syntax clean, `FINAL_STATUS=PASS`, `FINAL_RC=0`; V4, DB, public router unchanged; V5_AUTH_ENABLED=NO. Fixed source commit `8191361df2269474876a3e3d912eda19db2c3121` SHA256 `693329cf2594ed957f79c7b846088e4a253d19802c335c57d3f83a82cbf71b74`. This **replaces the earlier failed deployment** from section 13: no need to retry or reinstall.

Next: build one coherent protected V5 PHP dependency tree: production has both `local/samzaberu-v5/src` and `Controllers/V5/Runtime` with overlapping class names; do not load both copies in one request. Provide trusted server-side security-event release evidence (ten-path model still unverified), native MFA/password providers and private FPM HTTP factory. Only then remove protected auth 503 with supervised E2E. Website health/legal remain live HTTP 200. Client generates trust_token, server never returns it.


## 15. 2026-10-26 Coverage source alignment and negative test

After production V5 auth composition installed PASS (section 14), updated the existing fail-closed `V5ProductionNativeCoverageEvidence` in commit `9b3f9ca1c480004bee2c7f6e992ac43d295f0c1d` to **derive mandatory event paths from `V5NativeMutationPath::cases()`** instead of an independent hardcoded list. New test `tests/V5ProductionNativeCoverageEvidenceTest.php`, commit `b620362425051d7537b049e45d71e4036222d9ff`: checks enum alignment, missing evidence denial, CLI failing closed without Bitrix. Verified GitHub blob `0ae4b66d7ec84918d3a6bbe21c7c369fa5bdd32d`, SHA256 `879801be73c3b42feb4966ede4f7147990d2a5183443171fa99f5f1a0a38c306` for exact source. This code is **not yet installed to production**, and still DOES NOT establish genuine ten-path mutation release evidence; it must never be counted as successful MFA auth activation. The natural installed destination is `Controllers/V5/Runtime` where `V5ProductionStrongSessionFactory` and its two source dependencies already exist, avoiding any cross-tree class duplication.


## 16. 2026-10-26 Native security event transaction writer implementation

**Operator live PASS:** `V5ProductionNativeCoverageEvidence.php` installed to `Controllers/V5/Runtime` with SHA256 `879801be73c3b42feb4966ede4f7147990d2a5183443171fa99f5f1a0a38c306`; syntax OK; CLI fail-closed check PASS; `FINAL_STATUS=PASS`, `FINAL_RC=0`; public router and V4 unchanged, V5_AUTH_ENABLED=NO. Do not reinstall.

GitHub implementation to move beyond provider-only placeholders: `src/V5ProductionNativeSecurityEventBridge.php`, commit `6b063fa4f261318934754f1a76351b6ea762fa9d` (original `297114a...` fixed an erroneous pre-BEGIN-only gate), immutable source SHA256 `f0fc371d395c7b5b90a5d8e8a4e858ab8d8ead5ea773d5a264ce7d113ae0a758`. The new internal V5-only transaction adapter takes the EXISTING `V5SecurityEventVersionWriter` and an actual trusted native Bitrix mutation callback, requires FPM V5-owned transaction continuity, acquires b_user then MFA/phone locks before callback, rechecks continuity immediately before/after, calls the existing atomic epoch/phone version CAS writer on the SAME bridge, leaves COMMIT/ROLLBACK to owner. Does not attach to V4 or public router; source is not a proof that callbacks are installed or that every native event is covered. New negative CLI guard test `tests/V5ProductionNativeSecurityEventBridgeTest.php` commit `fee1057e116d1ca33846b2658e7815a2bb9b7d6e` (source SHA256 `e98e856043be9c5e58bb7fb782fa9e58de9414820ccff977a73884a4d2b8ceb7`). Install only after exact source validation, PHP lint and confirm no root router changes.

Existing `schema/PROPOSED_native_phone_recovery_epoch_triggers.sql` already contains six **staging candidate only** SQL trigger definitions for phone and TOTP recovery, and explicitly warns that same-number phone reconfirmation requires native owner hook; installing these on shared production V4 paths would be unsafe without verified native transaction/race proof. Current installed four epoch triggers are verified but not full ten-path release. `V5MySqlSessionLogout` already provides V5 session-specific revoke; browser logout need not automatically globally revoke independent mobile sessions without explicit policy. Keep V5 signin 503 until native release evidence genuinely exists.


## 17. 2026-10-26 Live website PIN/2FA incident fixes and next V5 native TOTP adapter

**Operator-verified production website changes** (separate from V5 mobile API):
- `local/php_interface/pincode.php` now SHA256 `3ae1a9a3bf9d570cc032bffdd88cb641d57e7032be37f50d6fc701d7a6686eef` following exact-SHA backup+patch `FINAL_STATUS=PASS FINAL_RC=0`. When current session lacks strong direct-PIN assurance, it returns an error requiring fresh authorization BEFORE RestIS / VK / SMS transport; direct PIN after established site trust/TOTP/email MFA remains intact. Backup `/root/ev-web-pin-relogin.tPTGPCCv`. User-facing real-browser acceptance of that change not yet supplied; no claims of SMS/VK delivery logs.
- `local/templates/eurasia/components/eurasia/account/main/template.php` now SHA256 `0c8ca7b10b8816b457405660f74a833706bb33e2d1d2dc33cece8426444e0774`, after single user-visible Google Authenticator mention removed; Yandex Key and TOTP generic support retained. Operator `FINAL_STATUS=PASS FINAL_RC=0`, PHP lint PASS, backup `/root/ev-mfa-ui-copy.IXH56pfI`. Browser cache refresh still should be verified.
- Neither patch modified `Controllers/V4`, V5 API, DB or trusted-device rows. Avoid confusing website PIN delivery SMS with site login SMS challenge.

**Next API V5 code state:** current branch contains SHA-pinned `V5BitrixOtpNativeMutationPort.php` with `usesSameOwnedBridge()` (commit `20445e21c97ef021a472ee2e03d42fc7af5c45c3`), and `V5ProductionNativeSecurityEventBridge::applyReviewedNativeOtp()` (latest source commit `e54e1ecc137fda7f46aba373237b8265645f5945`), with CLI deny regression test commit `27f286bb9afe7fd561bf4ea25fcbded1d51a3859`. **These latest OTP integration revisions are not yet installed on production**, nor is there proof all 10 native mutation paths installed. The older base `V5ProductionNativeSecurityEventBridge` is installed production only and fails closed by default. V5 strong release evidence still returns null; public auth remains HTTP 503. Next task is verify/install compatible OTP+bridge source together under source guards and test actual native Bitrix same-transaction save/delete with no synthetic user; no changes to V4 or core.
