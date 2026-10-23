# СамЗаберу API V5 — production OFF bootstrap: подтверждённый checkpoint 2026-10-23

**Источник истины:** фактический вывод оператора с сервера `evrasia` от 23 октября 2026. Это запись факта установки загрузчика, **не акт допуска V5 авторизации**. Отдельного тестового сервера/Bitrix staging нет; его существование нельзя предполагать.

## 1. Фактически выполнено на production

- Публичный `/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5/router.php` (исходный SHA256 `770fbc6e4e52f7374ef8209e4f0b91919a9cab976266c4f3ce0764dcedcab673`) раньше напрямую подключал внутренний `local/samzaberu-v5/api/v5/router.php`. В нём не было явных регистраций фабрики V5.
- Без изменения внутреннего router (SHA256 `6e534f93bcf873eda337439443cb3670f634f0afc1bfe9938e8acc4cef5ae37d`) установлен **только** двухфайловый исправленный загрузчик: публичный router теперь сначала загружает `local/samzaberu-v5/api/v5/site.php`; тому передаётся фиксированный physical document root; далее site.php загружает существующий внутренний router.
- `site.php` теперь направлен на ожидаемый приватный каталог `dirname(documentRoot)/private/samzaberu-v5` **вне public_html**. Файлы `site-config.json` и `site-factory.php` не создавались и не устанавливались. Защитный `V5PrivatePilotConfig` продолжает отвергать отсутствие/небезопасное размещение приватной конфигурации.
- Кандидат: `/root/ev-v5-checkpoints/v5-bootstrap-corrected-20261023.Vj1s42V9`; установка и проверенные резервные копии: `/root/ev-v5-checkpoints/v5-off-deployed-20261023.npcVIr3E`. Итог оператора: `DEPLOY_STATUS=PASS`, `ALREADY_APPLIED=NO`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `ROLLBACK_STATUS=NOT_NEEDED`.
- CLI smoke после установки: `/api/v5/health` возвращал `auth_ready:false`; `/api/v5/auth/signin` возвращал `v5_unavailable`. **Сетевой HTTPS smoke тогда не выполнялся:** `HTTP_NETWORK_SMOKE=NOT_PERFORMED`.
- V4/production DB не изменялись, SMS не отправлялись, регистраций не было, JWT не выдавались, V5 AUTH **OFF**. Шесть ранее существовавших V5 таблиц нельзя создавать повторно.

## 2. Отдельная диагностическая ветка password / AUTH_EPOCH

- Основной локальный hardened-adapter `/root/ev-v5-checkpoints/password-boundary-hardened-20261020-f1989b9aaefc/V5HardenedPasswordEpochTransaction.php`, SHA256 `cffe143cbb095892cc71231579ce654b1b8b8bf09d2e38423c86a1b3b3849a4e`.
- V3 negative case `V5HardenedPasswordEpochTransaction.missing_state.php`, SHA256 `cfbd18e69d0790d0ff9aff2ea8ff3bfb60f882729481c79571a548ac34e2999e`, имел корректный pinned H_DEPTH SHA, но вспомогательный файл под `/root` был недоступен CLI-пользователю UID 65534 (обычно nobody). Это **Unix UID тестового процесса**, не Bitrix USER_ID и не учётная запись клиента.
- Сформирован приватный кандидат, переключающий только путь H_DEPTH на доступную UID65534 *SHA-identical* копию; SHA256 кандидата `c9056ad8dd2a412d587a2477d03b145e7e71ba29d1a4479d0950d0bc08281645`, checkpoint `/root/ev-v5-checkpoints/v5-depth-access-repair-20261023.Iu6MGLrF`. Совпадает с ранее сохранённым исправленным вариантом 22 октября: не дублировать исправление.
- Сохранённые отчёты `V3_FAILURE_STAGE=COMMIT_ASSERT`, `V3_EXCEPTION_CLASS=RuntimeException`; **это не доказательство ошибки самого hCommit()**, и успешный helper path/lint не доказывает atomic native COMMIT. Нельзя объявлять E2E PASS или выполнять транзакционный тест на живых аккаунтах без отдельного безопасного допуска/backup.

## 3. Что ещё не принято

- `V5TrustedInstalledNativeCoveragePort` не имеет подтверждённой production-реализации и фактического 10-path native `AUTH_EPOCH` coverage attestation.
- `V5PilotScopedNativePorts` и `V5MySqlPilotComposition` содержат orchestration, но **не заменяют** настоящие native trust, Bitrix TOTP consume, bonus PIN reader, strong session/epoch и SMS proof.
- V5 SMS begin/resend и статус отправки реальным оператором не приняты. Не посылать пробные платные SMS.
- Нет отдельного изолированного тестового сервера. Не выдавать synthetic/offline/CLI-проверки за реальный positive Bitrix acceptance.

## 4. Следующие действия

1. Однократный **HTTPS network smoke** независимых публичных `GET /api/v5/health` и `GET /api/v5/legal/consents` с проверкой статус-кодов/документов без публикации body/секретов. Это проверяет фактический web route, ранее проверенный только через CLI.
2. Продолжать V5-first native provider implementation по согласованному contract, начиная с фактической доказательной границы 10 native mutation paths и locked strong-session proofs. Не создавать «пустую» фабрику и не ослаблять release gate.
3. Только после доказанных real native hook/transaction/sms-safe guards — приватная pilot factory с ограничением user IDs/IP, отдельный rollback и контролируемое приёмочное тестирование.
4. До допуска — `V5_AUTH=OFF`, V4 работает без изменений.

**Правило:** никаких новых утверждений о production готовности по наличию файлов, PHP lint, статическому осмотру либо CLI fake HTTPS.

## 5. 2026-10-23 — REAL HTTPS network smoke on evrasia.spb.ru PASS

Оператор выполнил `curl` через реальный HTTPS (не PHP CLI), сохранил JSON-ответы локально на время пробы, проверил итоговые статусы. `FINAL_STATUS=PASS`, `FINAL_RC=0`, без SQL/изменений кода/SMS.

- `GET https://evrasia.spb.ru/api/v5/health`: `HEALTH_HTTP_STATUS=200`, `HEALTH_STATUS=online`, `AUTH_READY=false`.
- `GET https://evrasia.spb.ru/api/v5/legal/consents`: `LEGAL_HTTP_STATUS=200`, `LEGAL_DOCUMENT_COUNT=3`, `LEGAL_TYPES=offer,personal_data,marketing`, `LEGAL_RESPONSE=DOCUMENTS`.
- `PRODUCTION PHP sources unchanged=YES`. Рабочий V4, БД, SMS не затронуты. **AUTH остаётся OFF**.
- **Не проверено:** `https://evrasia.rest/api/v5/...`. Исторический контракт мобильных разработчиков использовал `evrasia.rest`; без отдельной проверки второго хоста нельзя утверждать, что публичные V5-методы доступны с того же API origin. Не менять host в задании разработчикам автоматически. Другие методы `POST /api/v5/auth/*` не приняты.

**Новый next step:** один точечный read-only HTTPS cross-host probe для `evrasia.rest` с выводом только статуса и формата ответа (без следования редиректам и вывода PII), затем продолжить 10-path native hooks AUTH_EPOCH и настоящие провайдеры V5. Никаких повторных больших файловых инвентаризаций.

## 6. 2026-10-23 — evrasia.rest connectivity probe is inconclusive, NOT an HTTP 404

Оператор запустил HTTPS `curl --noproxy '*'` непосредственно на production-хосте `evrasia`:

- `GET https://evrasia.rest/api/v5/health`: `HEALTH_HTTP_STATUS=000`.
- `GET https://evrasia.rest/api/v5/legal/consents`: `LEGAL_HTTP_STATUS=000`.
- Тела ответов не получены, реальные curl exit codes и stderr в исходном сценарии были подавлены `|| true` и `2>/dev/null`. **Нельзя утверждать**, что это HTTP 404, отсутствие V5 route или ошибка приложения: первопричина DNS/сети/TLS/прокси пока неизвестна. `evrasia.spb.ru` ранее успешно отдавал оба GET через HTTPS.
- Скрипт выводил `FINAL_STATUS=PASS` как формальный статус выполнения, хотя доступность `evrasia.rest` не подтверждена. Ошибка удаления временного каталога объясняется оставленными `health.status` и `legal.status`; она не затронула сайт.
- Next: **одна короткая сетевая диагностика** с показом `curl exit code`, факта DNS-резолва и HTTP-статуса в прямом и обычном режимах; без токенов, SMS, обновления V4/V5/БД. Не выполнять deployment/V5 DNS redirect/маршрутизацию до фактического диагноза.

## 7. 2026-10-23 — Mobile client PUBLIC IP requirement and GitHub code parity

**Confirmed operator requirement:** guests of the SamZaberu mobile app connect to `https://evrasia.rest/api/v5/` over the public Internet/mobile provider/Wi-Fi. **Never put `192.168.103.141` into the app** and never IP-allowlist all mobile end users. The public domain must resolve/connect to the router's actual external IP, and native V5 auth remains the security boundary.

Canonical network facts from `docs/BITRIX_ANTI_FRAUD_PRODUCTION_MAP.md`: nginx binds `192.168.103.141:443`, which is the **LAN target for self-checks only**; existing public `evrasia.rest` DNS address cannot reliably be looped back to from the Bitrix server (hairpin issue). Existing server-side `curl` probes returned `RC=7` and `HTTP_STATUS=000` **from evrasia itself**. That is NOT a mobile/public-network availability verdict and must not trigger speculative nginx or DNS changes. Public website origin `https://evrasia.rest/` is publicly addressable; **GET V5 JSON on that origin from an actual cellular connection remains to be confirmed separately**. Correct end-user acceptance is from a genuinely external phone (Wi-Fi OFF) or external non-LAN runner, without `--resolve`.

**Pilot IP gate vs production public route:** the existing `V5PrivatePilotConfig` strictly enforces `mode=pilot`, short expiry, exact allowed peer IPs and a separate `X-Evrasia-V5-Pilot` request key. This is a temporary **closed pilot gate**, not the public client API contract; do not distribute pilot key to the mobile application, put `allowed_peer_ips` of client IPs into a production config, or weaken pilot checks. A reviewed separate public-mode activation (without a client IP allowlist but with genuine password, TOTP/trust/SMS/challenge/JWT/epoch controls) is necessary before general mobile access. **Not implemented or enabled yet.**

**Feature branch source synchronized with verified production OFF-only configuration path:**
- `site-integration/samzaberu-v5/api/v5/site.php`: `V5PrivatePilotConfig::load(dirname($root) . '/private/samzaberu-v5', $root)`, matching the deployed production path rather than the earlier package-directory path. Commit `0e7338b97bf0a3b83cc2b4983fb889ea32413036`.
- `site-integration/samzaberu-v5/tests/V5PrivatePilotConfigTest.php`: offline pilot fixture now uses this same private directory tree outside docroot. Commit `41ed34f8d520311956d2241aac9b7bc0f62096cf`.
- These are **GitHub-only** changes. No new production PHP deployment, no new private config/factory, no DB/SMS writes, no V5 AUTH enablement. GitHub Actions/CI pass for these commits is **NOT YET confirmed**; do not call them tested just because commits succeeded.

## 8. 2026-10-23 — PUBLIC mobile V5 GitHub-only composition (no IP/client allowlist)

**No production activation.** Operators must not reuse `V5PrivatePilotConfig` (client IP whitelist + pilot secret) as the future consumer API. `evrasia.rest` is the public HTTPS origin for arbitrary mobile client networks; `192.168.103.141` is for LAN self-probes only.

Feature commits:
- `463a395e271b119048bf78e327f7e80afb6d0609`: `src/V5PublicTrafficPreflight.php` explicitly ignores mobile client IP / spoofable X-Forwarded-For and rejects a supplied pilot header; requires trusted server HTTPS, clean strict MySQL/PDO preflight, existing `V5TrustedInstalledNativeCoveragePort::releaseVerifiedOnThisServer` and a separate `V5PublicDependencyAttestationPort::verifiedForPublicTraffic` before any public factory could be composed. Neither attestation has a production implementation yet.
- `9dfb056532d36f895c5a8131921c1c2f3a0ea37a`: `tests/V5PublicTrafficPreflightTest.php`, **33 synthetic local assertions** PASS using a locally defined interface stub; no real Bitrix/MySQL execution.
- `c86fcaa50e43fda58e3c4351a998e7c3462aa49c`: `src/V5MySqlPublicComposition.php` uses existing `V5StrictNativeSecurityHookCoverage` *per user*, the V5 session store, locked consumed-grant proof, challenge consumer, refresh, PIN and token-validate interfaces. It does NOT alter pilot composition, install a public factory or change any route. A complete SMS/TOTP password-bound lifecycle must be supplied by a trusted server provider.
- `cfffcc944d5b5ed065b7d57bf795f96be2e46a09`: `tests/V5MySqlPublicCompositionTest.php` covers default denial, HTTPS, pilot separation and synthetic rejected login.
- `e594fb9ecc8556f5a2dc369f6b9fa97f6bdfdab8`, `02d618474e420ea3111a7745a327b4b3862f236e`: stronger `V5PublicVerifiedFactorLifecyclePort` means **existing TOTP-only staging issuer is not type-compatible with public composition**; separate read-only `verifiedPublicSmsAndTotpLifecycle(PDO)` must return explicit true. False/unknown/exception aborts before composing session/challenge providers. **10 synthetic composition assertions** passed locally with stubs. These tests are **not live native Bitrix acceptance**.
- PHP lint PASS locally on added source/tests, 43 synthetic assertions combined. GitHub Actions CI on current branch is **NOT CONFIRMED**; no public website deployment of these files has occurred.

**Critical release blockers (unchanged):** ten-path native `AUTH_EPOCH` mutation coverage, atomic Bitrix password/MFA/phone/logout transitions, real trusted devices and TOTP replay, real verified phone and paid SMS issue/resend/provider status, locked PIN reader, real authenticated access-token validation, actual native provider attestation and controlled E2E without harm to production. No separate Bitrix test server exists. The public preflight and composition may not be called by `site.php` before all mandatory proofs are installed. Existing V5 AUTH stays OFF and V4 unchanged.

**Next:** implement and source-review the real native provider set and independent SMS lifecycle, not another network hairpin investigation; keep the deployed private pilot OFF. A genuine outside-mobile-network HTTP test of `evrasia.rest/api/v5` remains separate and unverified.

## 9. 2026-10-23 — Native Bitrix first-factor wiring (feature-only, no HTTP enable)

**Concrete implementation, not a production deployment:** `src/V5BitrixNativeFirstFactorComposition.php` composes existing native components for a V5 password-first-factor request: fixed server HTTPS / `REMOTE_ADDR` (IP used ONLY for anti-bruteforce rate keys, never mobile IP allowlist), TWO distinct verified MySQL/PDO connections (account SELECT and four-window atomic InnoDB rate budgets), `V5PdoUserRowSource` physical `b_user` unique EMAIL/LOGIN reads, `V5NativePhoneLoginNormalizer`, `V5NativeBitrixMfaRowSource`, `V5NativeTotpStatus`, `V5StrictNoMfaFallbackPolicy` with real native global OTP config, `V5StrictExistingLoginPolicy`, `V5MySqlAttemptWindowStore`, and `V5BitrixPasswordProofPort`. No `CUser::Login`, session creation, token/PIN or SMS.

- Commit `df40a0a7cdb1a11a289b101c3bae175621b55d1f`: native first-factor composition.
- Commit `67dad4bbfc151f38a4bf18240d501730a6ea26d7`: dedicated negative tests, fake PDO never touches DB; fail closed for missing server TLS, REMOTE_ADDR, invalid mobile install identity, disabled MySQL, nested transaction and unavailable rate limit.
- New `src/V5PinnedBitrixPasswordComparator.php` (commit `c1dc4c639fec31a76c8979dcd8f2a04e6406d126`) wraps the existing `V5NativeBitrixPasswordComparator` but additionally REFUSES password proof unless loaded `Bitrix\Main\Security\Password` comes from the canonical case-sensitive `/bitrix/modules/main/lib/Security/Password.php` and SHA256 is the previously verified `a1ffbba30510caa373087127d3af5714d36be526f5df854610f80ff227a10296`. Does NOT create its own password algorithm or call Bitrix login. Wired in `69edbffc954ebe1b40de131431f7c14350f60847`, with fail-closed test in `3e1cf009eda7dfce42797935d9b8ff7dcde33542`.
- GitHub Actions workflow `.github/workflows/v5-native-first-factor-offline.yml` added in `f84fafc37815517b14d7c09e3d839eef6e0ceb15`, extended pinned-comparator lint in `46940145b4725de20bac9e489eabd8d2221b847e`. It has strict CI/non-production guards, PHP lint and executes three targeted unit files. **Run result NOT VERIFIED in current session**: GitHub connector returned no combined statuses / PR runs; never claim execution PASS from the presence of a workflow.
- Code and tests were re-read and verified on the feature branch. **Not** published to production, not connected to `site.php`, no live auth, SMS, JWT, DB schema or Bitrix mutation.

**Outstanding true native providers:** `V5CurrentLoginControlsPort` — actual site/user login attempts ceiling, temporary block and security/login restrictions; `V5PerUserSmsEligibilityPort` — actual per-user and group mandatory MFA/SMS fallback allowance. They are REQUIRED by the new factory but no source-backed production implementation is claimed. Do not guess max login failures or set fallback true because the MFA row is missing.

**Other production release gates unchanged:** real 10-path `AUTH_EPOCH` native mutation/rollback, full trusted-device assurance and native TOTP replay, SMS delivery provider+quotas, signed JWT backed by active revocation/epoch, RestIS PIN read without SMS, endpoint-specific request-context construction. Existing pilot OFF; all mobile users use `evrasia.rest` public origin when ready, no client IP allowlist.

## 10. 2026-10-23 — installed native MFA sources pinned; permission/lockout still denied

Operator **production READ_ONLY** scan on `evrasia` (`FINAL_STATUS=PASS`, `DATABASE_CONNECTION=NO`, `BITRIX_BOOTSTRAP=NO`, `PRODUCTION_CODE_WRITE=NO`):

- `bitrix/modules/main/classes/general/user.php` SHA256 `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99`; `CUser::Login` begins at observed line 1744. The limited masked-pattern inspection **did not reveal** the authoritative native login-attempt limit, temporary lockout or custom `OnBeforeUserLogin` semantics. No approved positive `V5CurrentLoginControlsPort`.
- `bitrix/modules/security/lib/Mfa/Otp.php` SHA256 `285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c`. Code shows `canSkipMandatory()` and `canSkipMandatoryByRights()` at 987–1010, including `CAccess::getUserCodesArray($this->getUserId())`; `isMandatoryUsing()` is checked in the native login MFA path near 1145. **Implication:** not safe to derive per-user optional SMS mode solely from global `isMandatoryUsing()` flag, particularly when mandatory rights or overrides are in scope. `V5PerUserSmsEligibilityPort` still has no positive source-backed site provider; global mandatory currently fails closed.
- `Bitrix\Security\Mfa\UserTable.php` previously verified source hash `cf3979c6bed5684bacc758656c55431a60cefe2e356b1b2a4e63fb323a283a64`, according to pinned existing project evidence.

**New feature-only change:** `src/V5PinnedBitrixMfaPolicies.php` uses ReflectionClass and exact canonical file SHA256 to verify those **specific installed** UserTable and Otp sources before delegating read-only native MFA source and global OTP config; missing/mismatched code denies without interpreting unknown as "no OTP". Commit `6a5021f4bb1a7447d1729ebb94bedb933fbcd6c0`. Wired into `V5BitrixNativeFirstFactorComposition` in `b5e621cb024f3bc4079991c3577106b27e017b8d`. Negative offline checks in `tests/V5BitrixNativeFirstFactorCompositionTest.php` extended at `da64d01b864a2f9d3fc9c3d7586af007624b9bea` (expected unknown when Bitrix runtime absent, no SMS fallback, never silently absent MFA). The offline workflow includes the new file in lint list at `43c58ee32438569972e874132724ac8be94b16e4`.

**NOT CLAIMED:** real positive native policy, Bitrix login, native transaction, all-ten hooks, public auth acceptance or passing new CI. A source pin is not sufficient per-user rights attestation. V4, production code/DB and SMS unchanged; V5 AUTH OFF.

**Next narrow source-backed task:** inspect exact masked PHP control-flow windows from actual installed `CUser::Login` and `Otp::canSkipMandatory`, `getMandatoryRights`, mandatory decision, plus method definitions of `isOtpRequired` / `isMandatoryUsing` to determine whether a side-effect-free per-user rights and native lockout policy can be implemented. Do not call `CUser::Login` as a credential probe; do not guess its attempt limits or treat every `Otp::canSkipMandatory` as permission for SMS.

## 11. 2026-10-23 — native rights veto and global-OTP-on factor gate

**Operator's latest production SOURCE-ONLY run PASS**, `FINAL_RC=0`, no Bitrix bootstrap, SQL, production writes or SMS. Exact installed SHA256 remains:
- `bitrix/modules/main/classes/general/user.php`: `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99`.
- `bitrix/modules/security/lib/Mfa/Otp.php`: `285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c`.

Observed `CUser::Login` (1744–1934) calls `GetModuleEvents` and `LoginInternal`, may establish cookies/session and emits authorization events; **cannot be called as V5 first-factor proof**. `Otp::isOtpRequired` (1302) tests only whether deferred parameters exist: NOT a direct user obligation indicator. In `Otp::verifyUser`, native `isOtpEnabled() === false` immediately returns `true` (1130–1133) **without verifying any TOTP**. `canSkipMandatory()` (987–997) combines `isMandatorySkipped()` and `canSkipMandatoryByRights()` (1005–1013), which intersects `Otp::getMandatoryRights()` with `CAccess::getUserCodesArray(userId)`. `isMandatoryUsing()` at 1423 and `getMandatoryRights()` at 1444 access Bitrix options. The ability to skip mandatory enrollment is **not** permission to bypass already enabled native MFA or replace required TOTP with SMS.

**New GitHub-only code:**
- `src/V5BitrixMfaAdmissionGuards.php` commit `ef86a1a040bdc1b591bf30e8d06eee8505512adc`. `V5PinnedBitrixMandatoryRightsProbe` queries exact, source-pinned native `Otp::getMandatoryRights` and `CAccess::getUserCodesArray` (no HTTP supplied rights) with strict input validation and fail-close. `V5MandatoryRightsSmsEligibility` is a **veto/AND** with independently reviewed site `V5PerUserSmsEligibilityPort`, never a standalone SMS grant. `V5NativeTotpEnabledGate` requires native OTP globally enabled before accepting an active configured TOTP credential, and denies when global mandatory policy conflicts with a no-MFA/SMS assessment. Two global-option reads can detect a simple intervening change; this is NOT native transaction or 10-path atomicity proof.
- `tests/V5BitrixMfaAdmissionGuardsTest.php` commit `0e47bf1162a79d09f3fa8acefcc9d0e0d940a400`; the guard logic and 21 synthetic assertions were run in a **local PHP CLI harness with minimal interface stubs**, `php -l` PASS. That run is **not** the repository's real Bitrix/MySQL native E2E or full integrated CI.
- Wired into `V5BitrixNativeFirstFactorComposition` commit `c185d01f23effe3e04e91d6a09cd5a22817f6d2d`, and its reflection/negative tests updated commit `fe36a9006b8c1a900a666a7d8cdb5821a8938320`. Re-read both committed sources to verify wiring. **No live V5 admission**; no private factory, no code deployment.
- An attempted modification of `.github/workflows/v5-native-first-factor-offline.yml` was **blocked** by the GitHub tool. Its contents were not changed and the standalone new 21-assertion test is not newly listed by this attempted update. No new GitHub Actions successful run has been confirmed, do not claim automated acceptance.

**Still missing and cannot be guessed:** source-backed read-only `V5CurrentLoginControlsPort` for exact site `LoginInternal` + event-handler security/temporary lockout logic; separately independently attested `V5PerUserSmsEligibilityPort` custom site policy (the new rights probe alone does not grant SMS). Need inspect the **specific native `LoginInternal` method**, since the broad `Login` method does not show policy thresholds. Full 10-path native AUTH_EPOCH proof, SMS dispatch/provider receipts, trust/TOTP replay, PIN and JWT validation remain disabled/unattested. Public mobile access must continue to support arbitrary client IPs; IP is only an anti-bruteforce bucket.

## 12. 2026-10-23 — native CUser policy limits wired; CI 132/132 PASS

This section supersedes the earlier **unconfirmed CI** status for the new first-factor classes. Operator's latest production *source-only* evidence remains `FINAL_STATUS=PASS`, `FINAL_RC=0`, no DB/Bitrix execution. In `CUser::LoginInternal()` (installed `user.php` SHA `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99`, lines ~1944–2180), the following decision points were observed:
- `$loginAttempts = intval(current LOGIN_ATTEMPTS) + 1`;
- `getLoginAttempts()` is the CAPTCHA threshold; if the **next** attempt exceeds it, regular login may require CAPTCHA. V5 has no approved CAPTCHA equivalent, so fail before that threshold.
- `getBlockLoginAttempts()` AND `getBlockTime()` define a wrong-password blocking boundary. V5 preflight takes the stricter of configured CAPTCHA and enabled block thresholds, without assuming any threshold when a native value is zero.
- Password hash comparisons and optional native rehash / password expiry / password age history and event rules are separate; `LoginInternal` is **NOT** a safe read-only proof method and must not be called.

**GitHub-only implementation** (no production installation):
- `src/V5BitrixNativeLoginPolicyLimits.php`, commit `71bd066118069376a8200cbcccb557bc0899b85f`: a pinned-source *candidate* reader for public static `CUser::getPolicy($userId)` (method public/static existence checked before calling) with exact canonical-source SHA256 pin. It reads **only** `getLoginAttempts`, `getBlockLoginAttempts`, `getBlockTime`; malformed/unavailable/source-drift -> UNKNOWN. It does not call `LoginInternal`, `CUser::Login`, CAPTCHA, `blockUser` or event handlers. `V5NativeCappedLoginControls` composes the native cap with the existing separately trusted `V5CurrentLoginControlsPort` by taking the stricter known cap; existing site veto/unknown cannot be upgraded to true. The live method visibility/read-only behavior have **NOT yet been verified** in an installed Bitrix request, so this remains a candidate.
- Wired into `V5BitrixNativeFirstFactorComposition.php`, commit `1a272753e5b9b59d06d8a1e4d4014edc52fd9996`.
- `tests/V5BitrixNativeLoginPolicyLimitsTest.php` new, commit `3266cc4670ce56fc809d0ab8003d9fd4e9433efc`; native CAPTCHA/block ordering, disabled native thresholds, independent site limits, lock/security/event vetoes, exception failure, source canary. The assembly negative wiring test extended in commit `c15760aa03b56b44161fe35a62062b84d8629fd7`.
- Existing CI workflow `.github/workflows/v5-native-first-factor-offline.yml` now runs this test plus the previously unlisted `V5BitrixMfaAdmissionGuardsTest.php`, and lints both source files. Commit `d5032c52c7130c2bbbcc027fba8da5c755cf9b1c`.

**Verified GitHub Actions run: [#39949357505](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39949357505), push commit `d5032c52c7130c2bbbcc027fba8da5c755cf9b1c`, completed SUCCESS (job `first-factor-offline` success, PHP lint success, test step success, final pass)**. Job logs:
- public mobile preflight: **33** synthetic assertions PASS;
- public V5 composition: **10** synthetic assertions PASS;
- native Bitrix first-factor composition: **40** synthetic assertions PASS;
- native mandatory-MFA/global-OTP admission: **21** synthetic assertions PASS;
- native LoginInternal attempt limit cap: **28** synthetic assertions PASS;
- **132 synthetic assertions total**. `FIRST_FACTOR_PUBLIC_GATES=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `REAL_BITRIX_NATIVE_E2E=NOT_RUN`, `REAL_BITRIX_POLICY_ATTESTED=NO`.

**Critical unresolved release blockers:** the independent `V5CurrentLoginControlsPort` still needs trusted, real site-specific evidence for current temporary block, security/admission/event hook restrictions and password expiry/history; `V5PerUserSmsEligibilityPort` still needs actual authorized group/site policy. Source-pinned `CUser::getPolicy` itself has not yet been accepted on real loaded Bitrix and cannot prove these other facts. The ten native `AUTH_EPOCH` mutation paths, SMS issue/resend actual provider receipts, trusted-device enrollment, native TOTP replay, independent JWT/token validation and RestIS PIN reader remain unattested. Production V5 AUTH **OFF**, V4 untouched, no production code/DB changes, no SMS.

## 13. 2026-10-23 — restored full offline V5 regression after exact native savepoint bridge repair

**Why broader CI was failing:** target first-factor workflow already SUCCESS, but `V5 offline safety checks` had failures predating new CUser limits: `V5MySqlStrongSessionStore::transaction()` added an exact `SAVEPOINT v5_strong_session_guard` / `RELEASE ...` continuity check and `V5MySqlChallengeConsumption::verifyAndConsume()` added `v5_native_factor_guard`. The V5 native Bitrix PDO compatibility bridge rejected ALL SAVEPOINT SQL, so the synthetic HTTP strong-session test failed at `V5MySqlStrongSessionStore.php:393` with generic `session_unavailable`. A separate older synthetic signup native-receipt fixture used `https://example.test` legal URLs, violating current trusted-origin contract. Neither issue proves a production incident; V5 live AUTH stays OFF.

**Minimal feature-branch repairs (no broad SQL allowlist, no changes to auth proof policy):**
- Commit `8243816b44478abc2077d11a33b87182428a36f8`: Bitrix PDO bridge allows only the exact strong-session guard `SAVEPOINT/RELEASE SAVEPOINT` pair **inside an owned bridge transaction**; all other savepoint names/rollback-to/savepoint SQL batches denied.
- `9e635d07f13fcc0e2006103edc2802654abac93e`: native mock validates the savepoint creation/release before a committed V5 strong session.
- `cfb6c4aecbae54216109af1ca37d5a4047377e4d`: direct bridge negative tests for arbitrary savepoints, nested/unowned transactions.
- `13e33fdfb20b93d09a7df4099a72068f078e2d5d`: ONLY test fixture links updated to `https://evrasia.spb.ru/legal/public-offer/` and `/legal/personal-data-consent/`. Live legal-origin validation was NOT relaxed.
- `9c058568fd08e7bda99b58a1e41c0a4bd6122e3a`: bridge allows the second exact native-factor savepoint guard pair required for one-time SMS/TOTP challenge consumption, same mandatory transaction ownership.
- `213187b39000e0c9ed0f1a448453dad4381d727c`: direct bridge test extends mock to require exact second pair, no arbitrary SQL.

**Verified full offline CI result:**
- [GitHub Actions V5 offline safety checks #39950293630](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39950293630), revision `9c058568fd...`, completed **SUCCESS**; PHP fake Bitrix bridge **106/106** assertions PASS, synthetic strong-session HTTP/JWT/PIN **212/212** PASS, transactional V5 session/refresh offline **279/279** PASS, all other offline suites and wrong-host default-deny tests PASS, `FINAL_STATUS=PASS`. Later run [#39950395081](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39950395081), revision `213187b390...`, also **SUCCESS**, covering final strict second-savepoint negative tests.
- Native first-factor targeted CI already verified separately [#39949357505](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39949357505), **132 synthetic assertions PASS**, no Bitrix bootstrap.

**Do not overclaim:** PHP/fixture offline CI SUCCESS is NOT real native Bitrix transaction-depth+AUTH_EPOCH 10-path acceptance. As of writing, ephemeral MariaDB run [#39950293611](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39950293611) for native-factor savepoint repair is still being checked separately, and no success attestation is recorded here. No production deployment, DB write, SMS, JWT for real users, or V4 change occurred. Default `V5_AUTH=OFF`.

## 14. 2026-10-23 — native getPolicy declaring-file pin and next source-only admission

The native policy reader `V5PinnedBitrixLoginAttemptLimits` now additionally requires the reflection method `getPolicy` itself (not only CUser class) to have the same declaring class and **same pinned file** as the verified `CUser` source. No inherited method of unknown provenance may be used. Commit `d86e375fd4d6f5b2e87c4f458ff207944698f177`. A method missing, non-public, non-static, inherited from other file or redefined after a Bitrix upgrade continues to return UNKNOWN and deny V5. **No positive Bitrix method attestation has been executed.**

One narrow read-only inspection is now needed on production **only to determine method declaration visibility/origin** of `CUser::getPolicy`. No native execution, user data, SQL, login attempt or SMS. Do not re-run any prior H_DEPTH/COMMIT parser probes for this.

## 15. 2026-10-23 — installed CUser::getPolicy source declaration confirmed (source-only, not runtime)

**Production operator evidence, on host \`evrasia\`:** exact source-only, PHP-tokenizer inspection after canonical path and SHA256 check. \`MODE=READ_ONLY\`, \`BITRIX_BOOTSTRAP=NO\`, \`PHP_CLASS_LOADED=NO\`, \`METHOD_INVOKED=NO\`, \`DATABASE_CONNECTION=NO\`, \`DATABASE_WRITE=NO\`, \`PRODUCTION_CODE_WRITE=NO\`, \`SMS_SENT=NO\`. \`FINAL_STATUS=PASS\`, \`FINAL_RC=0\`, \`WRAPPER_RC=0\`. Source unchanged after scan.

- Installed \`/home/site_evrasia/web/evrasia.spb.ru/public_html/bitrix/modules/main/classes/general/user.php\`: SHA256 \`bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99\`.
- Exactly one \`CUser\` declaration at line 36, \`extends CDBResult\`. Exactly one \`getPolicy\` method declaration at line **4615**, **declared directly in CUser**, visibility **explicit public**, **static**. \`PINNED_SOURCE_DECLARATION_COMPATIBLE=YES\`.
- Existing feature \`V5PinnedBitrixLoginAttemptLimits\` pinned-source/method-declaring-file preconditions are **lexically compatible** with this installed source. No reason to weaken or rewrite that guard.

**Strict evidence boundary:** lexical declaration PASS is **not** runtime ReflectionClass/ReflectionMethod acceptance, does not prove \`CUser::getPolicy()\` is free of side effects, does not prove policy values are sound, and does not positively implement \`V5CurrentLoginControlsPort\` (timed block, password expiry/history, site event/security vetoes). \`REAL_REFLECTION_ATTESTED=NO\`, \`NATIVE_SIDE_EFFECT_FREEDOM_ATTESTED=NO\`, \`SITE_POLICY_ATTESTED=NO\`. No native auth, 10-path AUTH_EPOCH, SMS, strong session or public production release was accepted; **V5 AUTH remains OFF**.

**NEXT_STEP=GETPOLICY_BODY_THEN_SOURCE_BACKED_SITE_LOGIN_CONTROLS**. Inspect only the exact installed source-backed \`getPolicy\` implementation and directly relevant native blocked/password-expiry/security rules (source-only with literal strings/comments masked), then implement **independent**, fail-closed site-specific admissions without calling \`CUser::Login\` / \`LoginInternal\` / guessed event handlers. Preserve all strict public-release gates; no broad PHP inventory, hairpin debugging or H_DEPTH replay.

## 16. 2026-10-23 — source-only getPolicy body confirms DB/ORM/cache dependencies

Operator on production \`evrasia\` performed next **pinned SHA read-only text/token inspection** of the same \`bitrix/modules/main/classes/general/user.php\`; \`FINAL_STATUS=PASS\`, \`FINAL_RC=0\`, \`WRAPPER_RC=0\`, and post-scan source SHA unchanged. \`BITRIX_BOOTSTRAP=NO\`, \`METHOD_INVOKED=NO\`, \`SOURCE_EXECUTED=NO\`, \`DATABASE_CONNECTION=NO\`, \`DATABASE_WRITE=NO\`, \`PRODUCTION_CODE_WRITE=NO\`, \`SMS_SENT=NO\`. This is **not runtime policy acceptance**.

At installed \`CUser::getPolicy($userId)\` line 4615, observed up to line 4710: function-local static cache keyed by serialized user identifier; \`GroupTable::getList()\` to read the base group-2 policy (cache TTL 86400 in source call); user-group branches also \`GroupTable::getList()\` for additional policies; scalar user ID branch constructs current-time expression through \`Main\Application::getConnection()->getSqlHelper()\`; \`Policy\RulesCollection\` constructed; group policy passed to \`unserialize(..., [<masked option> => false])\`; the option name was masked by the probe, so do NOT claim \`allowed_classes\` was positively identified. Method **uses live Bitrix DB/ORM connections and caching**, so cannot be assumed safe to execute under a SOURCE-ONLY read-only script; no source-backed claim about SQL and cache side effects, complete method tail, or runtime values is made.

Separate method declarations: \`protected static function blockUser(...)\` line 2182, \`public static function GetByID(...)\` line 3881. \`CUser::LoginInternal()\` source previously confirms CAPTCHA threshold, password-failure blocking, possible rehash and password-expiry/history checks. There remains NO positive independently attested \`V5CurrentLoginControlsPort\` for timed lock, security restrictions, expiry and site login hooks. Keep CUser::Login/LoginInternal/blockUser uncalled and **V5 AUTH OFF**.

**NEXT_STEP=PINNED_GETPOLICY_TAIL_BLOCKUSER_PASSWORD_EXPIRY_SOURCE_INSPECTION**: inspect narrow masked source windows at \`getPolicy\` tail (~4708 onward), \`blockUser\` (~2182 onward), and password-expiry decision (~2060-2135); stop before implementing a permissive site-controls reader. Do not repeat earlier SHA, network, H_DEPTH, login audit, or trigger paid SMS.

## 17. 2026-10-23 — native getPolicy tail, blocking and expiry source-only

**Operator evidence from installed \`evrasia\` Bitrix, same pinned \`user.php\` SHA256 \`bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99\`.** Three **targeted, token-masked windows**, no source execution, bootstrapping, DB connection, user-data read/write, SMS or V5 enablement. \`FINAL_STATUS=PASS\`, \`FINAL_RC=0\`, \`WRAPPER_RC=0\`; post-check exact source SHA unchanged.

1. \`CUser::getPolicy\` tail, lines 4708–4736: verifies group policy is an array, merges matching keys into \`Policy\RulesCollection\` by \`assignValue($val)\`, records group ID, memoizes result in method-local static cache (observed \`count($cache) <= 10\` condition), returns policy. Earlier body reads group rules with Bitrix \`GroupTable::getList\` and Bitrix connection; it is **not a standalone pure calculation** or authenticated policy/readback attestation. No observed explicit DML in these masked method windows; avoid stronger claims about ORM/cache side effects or freshness.
2. \`CUser::blockUser\` lines 2182–2196 is **side-effecting**: instantiates \`CUser\`, calls \`Update($userId, ... , false)\`, creates a future \`DateTime\`, registers unblock \`CAgent::AddAgent(..., $unblockDate->toString())\`, optionally records \`CEventLog::Log(SEVERITY_SECURITY,...)\`. The exact field/agent callback text was **masked**; do not infer authoritative per-user unlock deadline or new schema from this output. Never call \`blockUser\` from V5 first-factor proof.
3. \`CUser::LoginInternal\` lines 2062–2132 (source-only): after password match, native branch can rehash stored password and update a digest (writes), tests a native expired flag, invokes \`CheckPasswordAgainstPolicy($enteredPassword, $policy->getValues())\` when configured, and checks \`UserPasswordTable::passwordExpired(userId, $policy->getPasswordChangeDays())\`. Failed check changes the login decision. **A check of \`b_user.PASSWORD_EXPIRED=N\` alone is insufficient** to reproduce this entire native password-expiry/policy behavior, and the method cannot be executed as a side-effect-free V5 policy probe.

**Existing feature code cross-check:** \`V5SiteAccountReader\` already reads authoritative \`b_user.ACTIVE/BLOCKED/PASSWORD_EXPIRED/LOGIN_ATTEMPTS\` and denies known inactive, blocked, expired states; \`V5PinnedBitrixLoginAttemptLimits\` separately caps CAPTCHA and block attempt thresholds; \`V5StrictExistingLoginPolicy\` separately requires all site-control vetoes to be positively cleared. These MUST NOT be weakened or duplicated with an always-true provider. Existing port \`V5CurrentLoginControlsPort::readForUser(userId)\` does **not** receive the entered password needed for configured \`CheckPasswordAgainstPolicy\`, so do not claim that the site-controls reader alone completes this native password requirement.

**Current evidence/status:** Installed source verified; native source behavior understood for specific branches; no positive runtime Reflection/side-effect safety or current site's security-event/temporary-lock policy attested. \`SITE_LOGIN_CONTROLS_ATTESTED=NO\`, \`REAL_BITRIX_NATIVE_E2E=NOT_RUN\`, \`V5_AUTH=OFF\`; V4, production code/database, live accounts and SMS unchanged.

**NEXT_STEP=SOURCE_BACKED_NATIVE_PASSWORD_AND_SITE_LOGIN_ADMISSION**: Determine the exact blocked-until agent/field semantics and \`UserPasswordTable::passwordExpired\` read contract from narrowly targeted installed-source inspection; establish the site's actual custom \`OnBeforeUserLogin\` / security veto registrations without invoking handlers. Then implement minimal separate fail-closed password-sensitive admission and site-controls guards, with tests and no positive grants until every source dependency is verified. Do not repeat broad file inventories, H_DEPTH or network hairpin diagnosis.
