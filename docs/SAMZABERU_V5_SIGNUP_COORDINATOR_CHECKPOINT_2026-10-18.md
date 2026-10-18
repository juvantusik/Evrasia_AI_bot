# V5 signup coordinator — checkpoint 2026-10-18

## Что проверено оператором (production, строго READ_ONLY)

В `eurasia_new.b_module_to_module` для `main/OnAfterUserAdd` зарегистрирован один обработчик: `CRatings::OnAfterUserRegister`. Его источник `bitrix/modules/main/classes/general/ratings.php` SHA256 `6295d17b2f6b204e6c34dc1de59613d96d0edca936aa8aac9bcff54d5ed204eb`, строки 1736–1805. В обработчике обнаружены SQL-write, `CRatings::UpdateRatingUserBonus`, `CUser::AppendUserGroup`, `$CACHE_MANAGER->Clean`. `UpdateRatingUserBonus` выполняет SQL Update/Insert и cache Clean; `AppendUserGroup` определён в `bitrix/modules/main/classes/general/user.php` (строка 4344), содержит DB operations/events. Исходник CUser SHA256 `bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99`; `CUser::Add` строки 198–358. Прямые begin/commit/rollback в `CUser::Add` не обнаружены. Эти исходниковые наблюдения **не** доказывают общей атомарности вложенных операций или кеша.

## Новая часть feature-only

Добавлен `src/V5StagedSignupCoordinator.php` — стадийный управляющий контракт с единственным владельцем Bitrix-native транзакции через существующий `V5BitrixPdoCompatibilityBridge`. До BEGIN необходима позитивная независимая аттестация отсутствия внешних эффектов и отдельный stage acknowledgement; fail-closed по умолчанию. После начала: создать пользователя → native consent persistence → pre-commit evidence → owner commit → post-commit readback. При обычной ошибке попытка rollback. При неопределённом commit/rollback — **NO RETRY**, не считать пользователя отсутствующим. Не созданы native `CUser::Add` adapter, isolated deployment/writer, API HTTP route и positive real Bitrix E2E.

Добавлен `tests/V5StagedSignupCoordinatorTest.php` — негативный синтетический тест default deny; использует существующую fake-Bitrix test fixture. Это не positive native acceptance. Два новых коммита feature: `08adee5627062a9063976571ef6936762c9f9efb`, `6cd73552a07e34df6d8d09186c0a758c5bb52647`.

## Блокеры перед настоящим signup

1. Адаптер `CUser::Add` должен доказать отсутствие необратимых side effects, включая `CFile::SaveForDB`, `CRatings`, cache/index, и косвенные callbacks.
2. Доказать одинаковое соединение/native transaction depth, полный rollback user + native consent events и отсутствие остаточных side effects **на изолированном Bitrix + MySQL**, включая races и uncertain commit.
3. Проверить, что workflow CI включает новый test, и отдельно выполнить native positive E2E. Нельзя считать синтетический green CI доказательством native атомарности.
4. Текущий `POST /api/v5/signup` не работает; разработчику не выдавать как deployed. `POST /api/v5/auth/token/validate` и публичный `GET /api/v5/legal/consents` — feature-contract candidates, V5 production OFF/503.

Production, V4 и production DB не изменялись. Запрещено менять их без отдельного разрешения.

## Исправление fail-closed после checkpoint

Коммит `b3d2e1430451b6f134b41a131e64438b9999455f`: coordinator больше не делает вид, что обычный rollback гарантирован, когда native consent writer сообщает о потерянной транзакции, смене соединения или hidden COMMIT. Такие ошибки выделены в `v5_signup_transaction_uncertain_no_retry`, без rollback-claim и без retry. Исключения post-commit readback теперь переводятся в `v5_signup_postcommit_unverified_no_retry`. Это минимальный defensive change и **не** positive native acceptance.

CI текущих feature commits: подтверждённого нового успешного workflow run пока нет; исторические 30/30 относятся к прежнему коду. Требуется запуск и проверка нового теста и отдельный native E2E до любого deployment.

## Продолжение feature и offline workflow

- `8f2e0205b40a6fff00cb96ffb51ed4520d5c5ea7`: реальный workflow `.github/workflows/v5-offline-safety.yml` обновлён с 30 до **31 planned suites**, включая `V5StagedSignupCoordinatorTest.php`. Это ожидание, а не свидетельство CI PASS.
- `bbae287c3afb5231e647d1d42cdf20988acca932`: test fixture расширена на *обычный rollback* и *неопределённое состояние без ложного rollback*, с исправленным PHP namespace syntax. Все проверки пока синтетические.
- Историческая 30/30 успешная проверка **не покрывала** coordinator. Не утверждать успешный 31/31 без фактического GitHub Actions результата.
- Внедрение CUser-native adapter, явное доказательство side-effects isolation и positive real-Bitrix E2E остаются заблокированными. Никакой production deploy.

## Synthetic success-path regression extension

Code/test commit `6e1309fa2a7bb20e90a4ba7e602aea673fc9f832`: broadened coordinator synthetic coverage to normal commit, rejection of repeated registration after success, and both `false` and exception during post-commit readback. Both readback failures must report uncertain/no-retry; rollback after successful commit is forbidden. This remains **synthetic fake Bitrix**, not proof that native `CUser::Add` is atomic.

This ChatGPT connector currently returns no workflow runs for latest commits (its commit-run lookup is PR-triggered only). Therefore CI for these edits is **UNVERIFIED**, not PASS, until a matching Actions run ID/job result is inspected. Staging native writer/positive real-E2E not implemented or executed. Production OFF/503 unchanged.

## 18 Oct — native continuity pre-commit gate

Commits `bad576159bcdf667eb9207bfb6b2709d5d17f11d` and `9a2cd1c4751ad8f2b00b9fec6ff4d620db56017e`: coordinator adds explicit production hostname deny and requires `V5StagedSignupNativePort::transactionContinuityBeforeCommit()` **after** native user and consent evidence, immediately **before** COMMIT. Failed continuity is an uncertain state and must **not** be reported as successfully rolled back; no retry. Synthetic test includes denial of uncertain continuity and checks zero fake COMMIT/ROLLBACK. This is a staging-port contract, not proof of actual Bitrix native transaction continuity. No production adapter, HTTP exposure, real E2E or deploy.

The test is included in the configured 31-suite workflow; latest CI status is not asserted until a matching run and job result are actually observed.

## 18 Oct — first native CUser staging identity gate

Commits `24de07dba8550dcc4f219937d7c8250b3971f394`, `7dd5f21b71106c8d2648717ca7183fbcd9d82507`, `a7c933fb75bae062e5b97a532aacf386aff91cde`: new `src/V5StagedCUserSourceGate.php` verifies actual loaded non-static `CUser::Add` reflection filename, source SHA256 of operator-observed Bitrix version, isolated staging document root, CLI and non-production host. New negative offline test denies absent Bitrix bootstrap regardless of env ack. Offline workflow expanded to **32** planned suites.

IMPORTANT: source identity alone does **not** imply CUser::Add transactional safety. No implementation of `V5StagedSignupNativePort` creates users; no native positive E2E executed; no release permission. Last matching GitHub CI run still needs to be verified.

## Native signup adapter status (next feature commit)

Introduced `src/V5StagedBitrixSignupAdapter.php` implementing the coordinator native port, with a **hard-deny CUser::Add call**. This is a non-deployable integration scaffold, *not* functional registration. Source pinning is checked independently but neither a source hash nor staging env may permit user creation while core Bitrix side effects are unproven. Added `tests/V5StagedBitrixSignupAdapterTest.php`, whose 6 checks verify rejection even when a method is directly invoked. Offline workflow plans **33** suites, pending matching CI run. Commits: `7fc597147080ad40893c8903ba567acfa1dfd50d`, `b3a6fdd62ab634de55ab5c26a7c2c23474b45075`, `6eb9e6287f3b9e27bdbe5a87c862c03c6baa3ae0`.

A release-ready native adapter **cannot** be claimed until actual isolated Bitrix acceptance proves full pre-existing/native-hook side effect constraints, transaction continuity, rollback and post-commit verification. Production V5 OFF/503; no HTTP write route.

## 2026-10-18 — rollback continuity enforcement

Commits `c6ca759a634fba331109602fe4c81fe53efc6d49` through `3a570ce0ceaa1b7a80478639a76937a8860fc77f` add mandatory `V5StagedSignupNativePort::transactionContinuityBeforeRollback()` prior to any coordinator rollback after regular native writer error. This prevents blindly rolling back a possible NEW transaction following a hidden COMMIT+BEGIN. An unverified scope produces `v5_signup_transaction_uncertain_no_retry`, not a fake successful rollback. Adapter remains hard-deny; synthetic regression now includes 9 scenarios; workflow still declares 33 offline suites. Running the suite and positive Bitrix E2E remain unverified; no deployment.

## Следующий шаг: staging CUser preflight (без записи пользователей)

Коммит `6bae5e6d11c60c201f5ee51d118002e7ec7afba7`: файл `tests/integration/V5StagedSignupCUserPreflight.php` проверяет перед любыми тестовыми DML жёсткую изоляцию host/docroot/schema, source-pinned native `CUser::Add`, depth=0, native MySQL application connection и staging exclusive permit. Он не вызывает `CUser::Add`, Consent writer или управление транзакциями. Bitrix bootstrap на isolated staging может иметь побочные эффекты — НЕ запускать на production. Коммит `62d4d2142bf0384b8748e8e473fa687e2f4312f3`: отрицательная CI-проверка отказа нового preflight до bootstrap. Число объявленных offline suites остаётся 33. Успешное выполнение реального preflight, 33/33 offline CI и positive native signup rollback **не подтверждены**. Никакого production deploy.

## Feature branch CUser::Add candidate implementation

Commit `0308713a1108d91b4b50fe4c6c914a6852d204a0`: `V5NativeCUserAddCandidate::add` now contains the real `new \\CUser(); $user->Add($fields)` call and checks canonical positive returned user ID. Source identity + isolated CLI/root guard run before the call, followed by a separately injected `V5CUserNativeWriteAttestation` positive decision. **No implementation of this attestation is supplied** and the production-oriented `V5StagedBitrixSignupAdapter` remains hard-deny, so these writes are not reachable in current deployment. Native CUser errors are intentionally generic, without leaking passwords, user details or LAST_ERROR.

No standalone Bitrix staging environment exists (user confirmed); do **not** repeatedly require one for continued feature coding. Real native side-effect/transaction acceptance remains unavailable and is not claimed to have passed. Production/V4 never changed; V5 OFF/503.

## Adapter wiring (native creation → consents, still hard disabled)

Commit `13e8826e98ccf6c07001d996c98c22eff4398c5c` wires `V5NativeCUserAddCandidate::add()` into `V5StagedBitrixSignupAdapter::createUserInsideOwnedTransaction()`, and `V5BitrixStagedSignupConsentWriter::persistForNewUser()` into `persistNativeConsentsFor()`. Calls are ordered, single-use, and the consent writer receives only the exact ID just created by the adapter. The adapter's `safeToCreateWithoutExternalEffects()` still *always returns false*, so neither coordinator nor direct adapter calls can execute user writes. This is source-level integration, **not operational signup**. No isolated Bitrix exists; do not request one repeatedly. Production/V4 unchanged and V5 OFF/503. Offline CI is not freshly verified.
