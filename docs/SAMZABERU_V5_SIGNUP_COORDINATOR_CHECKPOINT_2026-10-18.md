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
