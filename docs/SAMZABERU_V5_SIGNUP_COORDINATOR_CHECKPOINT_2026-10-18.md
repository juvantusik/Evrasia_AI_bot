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

## Native signup receipt checks (latest)

Added `src/V5SignupPersistenceEvidence.php`: pure verification of **server-created** native b_user identity/status plus native consent event IDs/USER_ID/AGREEMENT_ID/originator bound to the already validated consent plan. Test `tests/V5SignupPersistenceEvidenceTest.php` covers valid synthetic evidence and seven mismatch/replay/forgery cases. Commits: `b5d7f2d4f123451a144f2d55d054f8a4f0880b8d`, `cda12f8c1c16b6b3bba7527f028976384b43c262`. Offline workflow expanded to 35 planned suites at `4c03f779f2309ffe6264f2f53c19e76f26d0bce1`. **Not wired to authoritative native DB reads yet**; actual Bitrix persistence, commit and side-effect atomicity remain unverified. Existing adapter is hard-disabled; no standalone Bitrix staging environment available. V5 OFF/503, production/V4 untouched.

## 2026-10-18: read-only native signup receipt readback

Commits `59eb10fd8658f98050656d404c2abb68c45a6af8`, `490f6003ead5363b935e64e1946a674914875d5b`, `6188b1071d8b4d2662062b2db64fc130b71b113a`. Added `src/V5SignupNativeReceiptReader.php` which reads **exact** freshly created b_user and native consent event IDs via the injected native query connection, rejects absent/duplicate rows and mismatched origin, user ID or consent plan using the existing pure evidence policy. Synthetic fake-query test added; workflow now lists 36 offline suites. Not yet attached to the hard-disabled native adapter: doing so still needs separately proven native transaction ownership and postcommit connection identity. No actual Bitrix write or native E2E was run. No standalone staging Bitrix exists, and it must not be re-requested as a prerequisite for continued feature coding. V5 OFF/503; production/V4 unchanged.

## 2026-10-18: precommit receipt reader attached to disabled adapter

Commit `305f7a618291824b9f4efa711af2f871055f6d2a` connects the previously developed `V5SignupNativeReceiptReader` to `V5StagedBitrixSignupAdapter::verifyUncommitted`. It accepts only consent events from the same created user; the source gate and the full native-effects hard denial remain in force. Explicit postcommit verification is still **DENIED** because no independent new authoritative connection/transaction identity proof exists. Commit `e4390216d5538ca605dd7263336fc21705b5ac5d` extends synthetic adapter test to deny both transaction continuity methods. Workflow still lists 36 suites, but actual CI pass and live Bitrix E2E are not confirmed. The user has no separate Bitrix staging; no request to create one. Production/V4 untouched; V5 OFF/503.

## 2026-10-18: Auth prioritization — second-factor policy

In response to agreed security priorities (TOTP first, otherwise verified trust or SMS), feature branch adds `src/V5SamzaberuSecondFactorPolicy.php` (commit `5e927217b82d5ef7bf5bdb31886ffb2d274b58a3`). Pure deny-by-default policy: only server-verified password, active/unblocked account, known TOTP state and known trusted-device result may select a path. Enabled TOTP always overrides trust/SMS. TOTP-disabled, server-verified trust can skip SMS; otherwise require SMS. Session authorization requires proof bound to the current attempt. Offline synthetic test `tests/V5SamzaberuSecondFactorPolicyTest.php` (commit `bb5510af33b95935b7c851717ec3b889c05aaa3d`); CI now configured for 37 suites (commit `94688eeae7e8c6246046f165acc8efe19a046735`). **This is not wired to HTTP, SMS provider, token issuance, TOTP challenge or trust storage**. Positive CI execution not verified. Reuse V4 integrations only after actual source review, never presume they meet V5 security requirements. No isolated staging exists. V4/production unchanged; V5 OFF/503. Preserve final V4-vs-V5 security comparison requirement.

## 2026-10-18: SMS challenge and dispatch policy (offline-only)

User approved proceeding in one larger logical development phase. Added pure `V5SmsLoginChallenge` with server-HMAC of six-digit code, 300-second expiry, max five incorrect attempts, single-use consumption, and mandatory server-supplied authenticated account ID plus cryptographically unpredictable per-login attempt ID (commit `5fa682683bdb6a2f7106e2b3c48047c630d56161`). Added 12-case synthetic regression (`e0159c420e09c5e3455aa0f8402d5857b4117a43`). Added `V5SmsDispatchPolicy` with 60-second cooldown, at most two dispatches per login attempt and three per account per rolling hour; missing throttle state rejects (commits `d15305401971612dd7676dd1347b215bce954fab`, `f368f1efd9f2061093f7d2fa83560e1db32883ba`). Workflow configured to run 39 offline suites (`b085961ba3be4746c021fd5058a3c475cab42299`). **CI success is not yet independently observed.**

These are **pure server-side policy components, not integrated HTTP/login handlers**. Requirements before real deployment: trusted account phone resolver, correct V4 provider behavior established from actual code, collision-resistant attempt IDs and SMS code generation, atomic CAS or DB row locking of challenge and throttle mutations, deliver-after-reservation and safe failure accounting, deny unless durable consumption committed, separation of login proof vs active session token, full token/session revocation and verified trust device binding. No client-supplied phone or control flags. No Bitrix isolated test stand exists; do not demand one. No V4 or production writes; V5 OFF/503. Keep eventual V4-vs-V5 vulnerability assessment on checklist, and describe only vulnerabilities supported by evidence.

## 2026-10-18: SMS durable verification boundary

Added `src/V5SmsChallengeVerifier.php` and `V5AtomicSmsChallengeStore` interface (commit `fb252da88f43956851c948a0fdc450c08496b060`). The API-facing verifier only returns success after its store reports **committed** one-time challenge consumption. Malformed input, thrown store errors, failed persistence and replay all deny. Store implementations must lock/atomically persist both failed-attempt counters and successful consumption; the interface by itself cannot prove an implementation does this. Fake transactional tests in `tests/V5SmsChallengeVerifierTest.php` (commit `48388988d2521901c3f48cfc309f60add2699d17`) exercise failures, replay and invalid input. Offline CI is configured for 40 suites (commit `ae7ab87de4c778970c648441a4a897fc6c8b4e67`) but green execution has NOT been verified.

**Still missing:** proven persistent DB store (CAS/row locking), throttle storage, verified Bitrix phone resolution, SMS provider adapter, TOTP integration, device-bound trust, login-attempt lifecycle, session/token writer, and HTTP route. Existing pure policy and interface must NOT be treated as a deployed login. User has no separate Bitrix staging; don't require it. V5 remains OFF/503. Production/V4 untouched; final V4 vs V5 security comparison remains required.

## 2026-10-18: SMS HMAC consistency and cryptographic generation

After previously blocked GitHub writes, commits `26595d79cf4f75ceb9d047bc3a3c8ed9ad5bb371` and `727fc807401d0f9be0c772b34117c4efdfaace03` centralize native SMS HMAC verification in `V5SmsLoginChallenge::matchesMac`, test correct code, wrong attempt, wrong user and invalid hash format. Added `V5SmsChallengeFactory` in `02069de63d4da938e6a844b9b423606a4f2a30a9`: CSPRNG-issued 128-bit attempt IDs and uniformly generated six-digit SMS codes; secret is supplied server-side and must have at least 32 bytes. Test commit `cf96ca22fb1f2e0a9c0274559f36e61aced1dd1f`, CI config updated to 41 offline suites in `b0acd3bf576dfd18369a36b5016c100a940457cf`. **CI result not verified**. This feature does not supply a deployed atomic SQL challenge store, SMS transport, Bitrix phone resolution or HTTP route. Prior attempt to commit an SQL store was rejected by the write tool, so that store was **not** added. Nothing in V4 or production was modified, and V5 remains OFF/503. Continue eventual V4-vs-V5 security comparison using factual source evidence.

## 2026-10-18 — candidate PDO SMS consumption implementation

Commits `5a32af32815271dc4eefd90fc5a6eecd04440da7`, `7d88fed69a4f80403bdbcdd014ef4b2f1cb2e9e0`: `src/V5PdoSmsConsumeStore.php` implements the `V5AtomicSmsChallengeStore` interface as a **non-deployed candidate**. Uses PDO-owned transaction, `SELECT ... FOR UPDATE` on one fixed `ev_v5_sms_login_challenge` table, the shared HMAC comparison, a conditional attempt-counter/consumed UPDATE and returns success only after a successful COMMIT. It rejects an ambient transaction, malformed client-side values, expired or consumed records, and bad counters. Any DB exception or uncertain COMMIT denies session access; operator reconciliation required for unclear outcomes. Negative test `tests/V5PdoSmsConsumeStoreTest.php` in commit `673708b23bc48fe66493b2152b44217275854941`, workflow now configured for 42 offline suites (`6c28d79688bb6e3fd26fa85cc70be4b38a7df6d3`). CI success and real transaction/concurrency behavior **not yet verified**.

**Required DB shape before any deployment (schema NOT installed):** fixed table `ev_v5_sms_login_challenge` in separately authorized V5 schema with columns `attempt_id CHAR(32) PRIMARY KEY`, `user_id INT NOT NULL`, `code_mac CHAR(64) NOT NULL`, `expires_at BIGINT NOT NULL`, `failed_attempts INT NOT NULL`, `consumed TINYINT NOT NULL`, engine InnoDB. Needs separate account/hour and attempt quota persistence plus dispatch reservation before provider call. Connection provenance and schema identity are not attested by this candidate: do not instantiate with an arbitrary PDO or production Bitrix connection. No migrations, no HTTP route, no SMS sent. No separate Bitrix staging available; do not request it. V4/production unchanged; V5 OFF/503. Comparative V4/V5 security audit still required.

## 2026-10-18 — SMS reservation before delivery

Introduced `V5SmsQuotaReservationPort`, `V5AccountSmsTransportPort`, and `V5SmsReservedDispatcher`: code may reach an account-bound SMS transport only **after** a separately implemented atomic/durable quota reservation accepts the attempt. The client cannot provide a destination number. A failed reservation, invalid argument, or thrown reservation error means no transport call; unknown provider outcome does not trigger automatic retry. Commits `24b87f27773dd1989b8886e7a66822d81e995d57`, `49c4bcd3450b8275b158581a77312c99a9bbfe9c`, `ef7b9830c8fdaa741aa9cca10d94bd7577b6e299`. Synthetic negative dispatch test `8b6e6c5c56bc1634e244180eec0e2cbb46ed0316`; workflow configured to run 43 offline suites `9989e486eaec372d9804bcd644e7b9b565200972`. CI PASS **not verified**.

Both ports remain interfaces without implementations: no durable reservation store, no confirmed Bitrix phone resolver, no actual SMS provider connected. Do NOT treat this as complete anti-fraud or production-ready SMS dispatch. This flow should be called only after an authenticated account, REQUIRE_SMS decision, and challenge-issue durability are established. No standalone Bitrix staging exists. Production/V4 unchanged, V5 OFF/503. End-of-project V4 vs V5 security assessment remains required.

## 2026-10-18 — concrete SMS quota reservation candidate

Added `src/V5PdoSmsQuotaReservation.php` (`a11d5133c82dfec8cb5a7d15ed4f0b9de813f69a`) implementing `V5SmsQuotaReservationPort` on an injected dedicated PDO. It serializes account-level reservations via SELECT FOR UPDATE on an existing dedicated account row, reads one-hour reservations and all-time per-attempt count, applies `V5SmsDispatchPolicy`, inserts a reservation and returns approval only after commit. Failed query/commit denies dispatch. Test `tests/V5PdoSmsQuotaReservationTest.php` (`419a6ab7ba4bb4597379107bfe794f83fc89746f`) tests negative input and failed transaction using fake PDO; workflow configured with 44 offline suites (`e11e8a52ea079ada0230bef237eb6464deed9622`), results NOT observed.

**NO migration/deployment**. Requires an independently authorized dedicated V5 DB, pre-created `ev_v5_sms_quota_accounts(user_id PRIMARY KEY)` seeded by a trusted process, and `ev_v5_sms_reservations(user_id,attempt_id,sent_at)` with correct InnoDB indexes and grant isolation. The lock is valid only if all reservation writers obey the identical account-row protocol; this has NOT been proven under concurrent MySQL tests. Reservation intentionally counts even when provider send fails or is uncertain. Unverified PDO identity and unknown provider/confirmed-phone integration remain deployment blockers. Existing V4 untouched; production unaffected; V5 OFF/503. Later compare factual V4/V5 security properties.

## 2026-10-18 — end-to-end SMS issuance ordering, feature-only

A new `V5SmsChallengeIssueCoordinator` (commit `c436bdda5426ea4c17b78a1b15c74dde2fe5d97a`) now composes existing CSPRNG `V5SmsChallengeFactory`, `V5SmsLoginChallenge`, quota reservation and account-bound transport. It generates id/code on the server, requires `V5SmsChallengeIssueStore::issueOnce` durable persistence before quota reservation and network sending; no token/session is issued. The returned attempt ID is not an MFA success signal. Failed storage prevents any SMS; quota denial prevents delivery; failed/uncertain provider result does not trigger retries. Synthetic coordination tests `1e3122569b75f9cc2c28fc2ab22dbe25d91258df`; workflow configured for 45 suites `0e84ff86fdf3014e04e8a112510582dab6b61858`. CI PASS has NOT been observed.

This is still NOT a deployed complete signup/login system. The issuance store remains a port without a database implementation; source of the authenticated account and MFA-required decision must be enforced by the eventual HTTP/login controller, not client parameters. Confirmed Bitrix phone, provider behavior, real MySQL concurrency/rollback, token revocation and trusted device proof need implementation and verification. No isolated Bitrix staging exists; do not demand one. Never change V4 or production without explicit approval. V5 OFF/503. Later carry out evidence-backed V4-vs-V5 security assessment.

## 2026-10-18 — durable SMS issuance store candidate

Feature commits `10550ef0d981d6203bdb19573620e6830222a5ac` and `2d00812bc3d09b7d476fed73a77d04dc5b966e56`: `V5PdoSmsChallengeIssueStore` implements the previously missing `V5SmsChallengeIssueStore` using a fixed dedicated `ev_v5_sms_login_challenge` table, INSERT under its own PDO transaction and return true only after successful COMMIT. Duplicate primary attempt IDs or uncertain SQL outcomes fail closed (never send SMS). Refuses ambient transaction; checks code-MAC, attempt ID and initial unconsumed state. Negative fake PDO test `2857bca71da7f21a450391c6caaf44943cfa4739`; offline workflow now specifies 46 suites (`9dfa5407dcbefcc458cd568735231405a0933f85`). **Successful execution of CI and real InnoDB concurrency testing are not verified.**

Issuance, reservation, and consumption now have candidate concrete PDO adapters, but neither connection identity nor authoritative phone resolution, SMS provider, TOTP/trusted-device policy integration, session issuance, revocation, safe schema migrations or real E2E have been implemented. The caller must never issue tokens on SMS delivery alone. There is no standalone Bitrix staging. No operational HTTP integration, no deployed DB schema or production/V4 writes; V5 stays OFF/503. At the end, compare V4 vs V5 security with evidence.

## Architectural correction — eliminate parallel SMS pipeline before release

Review of actual feature branch reveals an already-existing, significantly stronger flow: `V5LoginWorkflow`, `V5SmsDispatchBudget`, `V5MySqlSmsOutbox`, and `V5MySqlAttemptWindowStore`. The budget model includes 10 account/phone/IP/device/global limits, monetary SMS caps, verified-account destination provenance and a single DB transaction coupling password-proven challenge locks, quotas and encrypted idempotent outbox. `V5LoginWorkflow` has TOTP priority, password-bound challenge start and session proof ports. The later simplified pipeline `V5SmsChallengeIssueCoordinator`, `V5SmsReservedDispatcher`, `V5PdoSmsQuotaReservation`, `V5PdoSmsChallengeIssueStore`, and `V5PdoSmsConsumeStore` is **NOT the chosen production architecture**, must not be wired to HTTP, and must not replace the stronger challenge/outbox logic. It lacks important security dimensions (cost caps, verified-phone change detection, device binding, native auth epoch and encrypted outbox) already accounted for by the main code.

Decision: continue building and verifying the existing main V5 native-aware flow. Treat the simpler code as experimental feature-only material until safely removed/reconciled; avoid copying any of its interfaces into production. Do not claim SMS V5 ready: site-proven implementations, DB schema verification, provider transport and true E2E remain outstanding. Production and V4 unchanged, V5 OFF/503. Final evidence-based V4-vs-V5 vulnerability comparison remains required.

## Canonical SMS outbox transaction continuity fix

Feature commit `92ffb7754637aa6a6431d3ea9d792d583bfaa3d8` adds fail-closed checks in the CANONICAL `V5MySqlSmsOutbox` that a PDO transaction remains active immediately after native locked account/SMS eligibility callbacks and immediately before COMMIT. A callback losing its transaction must not cause budget/outbox writes. Synthetic regression `6792302a423c383b2ea5855e24a33a30d0be7a6c` exercises the lost-transaction path and absence of quota/outbox writes. This does NOT attest against hidden COMMIT+BEGIN, changed physical DB connection or other nontransactional Bitrix side effects; those still require native transaction identity controls. Existing 46 suites unchanged; CI execution not verified. No deployment, V4 or production changes.

## Canonical SMS regression added to pinned CI

While inspecting `.github/workflows/v5-offline-safety.yml`, discovered that the critical canonical `tests/V5MySqlSmsOutboxTest.php` existed but was **omitted** from the enumerated executed suites. The test exercises transactional quota/outbox atomicity, resend cooldown/caps, denied site eligibility, encrypted message HMAC and failure rollback, including the new lost-transaction case. Commit `26094127989c56c374457351eb6fbaed8a83fbb5` adds it to the executed list and moves the expected number from 46 to 47. This is a *CI coverage correction*, not proof of green CI: an actual successful run was not independently observed. Do not claim production readiness. Production and V4 untouched, V5 OFF/503.
