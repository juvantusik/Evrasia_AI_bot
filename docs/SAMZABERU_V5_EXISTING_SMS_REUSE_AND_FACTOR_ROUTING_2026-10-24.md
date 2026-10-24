# СамЗаберу V5 — переиспользование SMS challenge и безопасное разделение факторов (24.10.2026)

## Подтверждённое решение владельца

**Не переписывать работающий серверный SMS challenge сайта «Евразия» и не переносить его production PHP/JS в GitHub.** Владелец 24.10 подтвердил реальный вход по email OTP и по SMS fallback на `https://evrasia.rest`; ранее снят только блокирующий глобальный SMS budget reserve, пользовательские rate limits сохранены. Это отдельный рабочий website flow — не mobile V5 AUTH acceptance.

Сайт использует серверные Bitrix компоненты `local/components/eurasia/signin/class.php` и `local/components/eurasia/totp.enrollment/class.php` (проверенные production SHA — в документации source checkpoint). **Их не меняли и не копировали сюда.** Нельзя приписывать уже работающий сайт новому V5-коду или вызывать браузерный AJAX endpoint из мобильного клиента: для SMS через mobile нужны password proof, независимая V5-сессия, подтверждённый телефон, server-side challenge, ограничения и собственные release gates.

## Какие серверные компоненты SMS в V5 уже были

- `src/V5BitrixLockedSmsEligibility.php`: locked b_user/OTP/confirmed phone, epoch и политики допуска; requires real verified-phone event version, native account restrictions, non-bypassable MFA.
- `src/V5SmsDispatchBudget.php`: seven mandatory account/phone/IP/device rates; global hour/day/cost optional by owner decision. No arbitrary shared 500/day block.
- `src/V5MySqlSmsOutbox.php`: atomic quota, encrypted outbox, HMAC-only code; accepts 7–10 windows, verifies locked identity and challenge.
- `src/V5MySqlSmsDispatchClaims.php`: single claim, provider outcome tracking and unknown-send fail closed. A queued SMS is not yet a delivered SMS.
- `src/V5MySqlChallengeConsumption.php`: one-time SMS/TOTP consumption, requires receipt-confirmed sent status, current factor and epoch; no JWT issuance without a separately verified strong session.
- `src/V5MySqlChallengeHttpPort.php`: HTTP boundary for beginBound and resendSms, delegating creation to mandatory `V5PasswordBoundChallengeLifecyclePort`; cannot itself create SMS challenge or send SMS.
- `src/V5MySqlPasswordBoundTotpIssuer.php`: password-bound TOTP challenge issuer with newly verified savepoint/transaction-continuity guard. TOTP-only, no SMS provider.
- `src/V5SmsChallengeIssueCoordinator.php` and `V5AccountSmsTransportPort.php`: older candidate contracts are not evidence of fully integrated mobile password-bound/receipt-confirmed lifecycle. Do not silently connect the user-ID-only shortcut to mobile login.

**Therefore:** the components are present; what is *not* attested is the live mobile password-bound SMS issuance/resend provider and real provider outcome proof integrated into `V5PublicVerifiedFactorLifecyclePort`. We must reuse existing server transport/business logic where safe, not create another OTP/challenge implementation.

## Implemented now — minimal factor routing, not a new SMS subsystem

- [`7ac6b38cdfe21517937cb8e5b1ba86b95e090cf6`](https://github.com/juvantusik/Evrasia_AI_bot/commit/7ac6b38cdfe21517937cb8e5b1ba86b95e090cf6) adds only feature-only `src/V5SeparatedFactorLifecycle.php` and offline tests, plus CI registration. It takes two preexisting, separately verified factor providers via `V5PasswordBoundChallengeLifecyclePort`; does **not** make a public deployable implementation, access the website, send SMS, mint tokens, connect to Bitrix or define a new OTP algorithm.
- [`a32b0c4f51cb64f7d472ebdab863163ebcff9d8d`](https://github.com/juvantusik/Evrasia_AI_bot/commit/a32b0c4f51cb64f7d472ebdab863163ebcff9d8d) fixes test integration to use the **already existing** `V5MySqlChallengeHttpPort` required by `V5LoginWorkflow`; no production code changes.
- Security behavior: strong trusted device remains handled first by `V5LoginWorkflow`. Otherwise an enrolled TOTP requires TOTP only. No TOTP silently downgrades to SMS on error. SMS-only account may select SMS. `begin(userId)` prohibited; `beginBound` requires password evidence and appropriate factor, and resend is SMS-only with original challenge ID. Any unknown/failed provider result denies.
- **Deliberately not implementing** `V5PublicVerifiedFactorLifecyclePort`: that requires independently real-attested mobile SMS issue/resend/provider receipts and native TOTP transaction proofs. This routing object alone cannot enable V5 AUTH.

## Actual CI result

[GitHub Actions #40116254015](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40116254015) **completed/success**: PHP lint PASS; new separated TOTP/SMS routing **26 assertions PASS**; prior SMS finance budget **125 assertions PASS**; SQL outbox staging **137 assertions PASS**; `FIRST_FACTOR_PUBLIC_GATES=PASS`. All offline. General [offline safety #40116253952](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40116253952) remains FAIL (separate existing website-email UI JS regression, no proven new failure in staged V5 factor routing). Do not declare full CI green.

## Guards / next stage

`WORKING_WEBSITE_SMS_CHANGED=NO`; `WEBSITE_TOTP_CHANGED=NO`; `API_V4_CHANGED=NO`; `PRODUCTION_DATABASE_WRITE=NO`; `SMS_SENT_BY_CI=NO`; `V5_PUBLIC_AUTH=OFF`.

**Next**: safely adapt **existing website SMS dispatch as a server-side V5 transport behind V5-specific password-bound issuance, verified phone/epoch provenance, 7 mandatory user/phone/IP/device caps, durable outbox and independently attested provider receipt.** Browser-only website action and V4 JWT cannot be substituted for mobile challenge/session. Need real Bitrix staging/production read-only source attestation before any live enablement. No synthetic production users; no website rollout or V4 rewrites.
