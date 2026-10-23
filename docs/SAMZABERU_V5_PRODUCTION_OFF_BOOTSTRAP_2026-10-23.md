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
