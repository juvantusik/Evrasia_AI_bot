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
