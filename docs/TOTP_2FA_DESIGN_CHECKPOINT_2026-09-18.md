# Evrasia — TOTP 2FA / protected-profile design checkpoint

> Status: **PILOT ENROLLED / NATIVE TOTP LOGIN VERIFIED / DIRECT WEB PIN PILOT DEPLOYED / BUSINESS PIN USE PENDING**
>
> Original design date: **2026-09-18**  
> Latest factual update: **2026-09-23**
>
> Scope: Bitrix website personal account / sign-in / bonus PIN flow on production host `evrasia`.
>
> This document records the full evolution from design/discovery to the current production pilot. Older sections describing global OTP OFF or an unenrolled pilot are historical snapshots and are superseded by the latest sections below. As of 2026-09-23, pilot USER_ID 880339 is enrolled, native password→TOTP and current-session `otpUsed` proof are verified, and the guarded direct web PIN pilot is deployed.

## 1. Operator request and business goal

The operator wants to introduce optional two-factor authentication for Evrasia guests using standard TOTP applications such as **Яндекс Ключ**, Google Authenticator and other compatible authenticators.

The core business idea is **not** to replace the bonus-spending PIN with TOTP.

The desired model is:

- a normal profile continues to receive the bonus-spending PIN through the current VK/SMS channel;
- a guest may voluntarily enable TOTP 2FA;
- enabling 2FA must additionally require an SMS code sent to the phone already belonging to the account;
- only after both SMS ownership confirmation and TOTP confirmation is the profile considered protected;
- for such a protected profile, the bonus-spending PIN may again be shown directly in the personal account without waiting for SMS/VK delivery.

Reason for the SMS step during 2FA enrollment:

- credentials alone are not sufficient proof of current account control;
- an attacker who stole login/password must not be able to enroll the attacker's own authenticator;
- therefore the current account phone must be confirmed **before** the TOTP secret/QR is offered.

Important wording: SMS + TOTP confirms control of the account phone and the enrolled authenticator. It should not be described as legal identity verification.

## 2. Approved conceptual enrollment flow

Target enrollment flow:

```text
authenticated account
        |
        v
"Enable 2FA"
        |
        v
send SMS to current account phone
        |
        v
verify SMS ownership code
        |
        v
generate / present TOTP provisioning QR
        |
        v
guest adds Evrasia to Yandex Key / Authenticator
        |
        v
guest enters current TOTP
        |
        v
server verifies TOTP
        |
        v
activate 2FA
        |
        v
revoke pre-2FA web/mobile sessions
        |
        v
future logins require TOTP
```

Do **not** expose the TOTP provisioning secret/QR before the SMS ownership step succeeds.

## 3. Bonus PIN behavior

Current business reason for SMS:

- historically the bonus-spending PIN could be obtained directly in the personal account;
- after account theft incidents, the PIN was moved behind VK/SMS delivery;
- the proposed protected-profile model restores direct display only for sessions backed by enabled and successfully passed 2FA.

Target behavior:

```text
ordinary profile
    -> obtain RestIS PIN
    -> deliver through current VK/SMS path

protected 2FA profile
    + current session has actually passed TOTP
    -> obtain RestIS PIN
    -> return/display PIN directly in personal account
    -> do not send VK/SMS for that request
```

Critical invariant:

**Do not authorize direct PIN merely because `b_sec_user.ACTIVE=Y`.**

A stolen session created before 2FA enrollment must not automatically gain direct-PIN privileges. The server must know that the current authenticated session itself passed the second factor, or old sessions must be made unusable and the new session marked accordingly.

## 4. Personal-account UX placement

The operator supplied the current personal-account screen and approved the placement concept.

Production template:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/local/templates/eurasia/components/eurasia/account/main/template.php
```

Read-only SHA baseline observed 2026-09-18:

```text
f521b9087914893e7aaf94b672d0195a8679cefd0cde052be95175b6407a637b
```

The existing `globalLogout` button was at line 221 during inspection.

Planned placement:

- after the main account/profile card;
- immediately before **"Выйти со всех устройств"**;
- logically group 2FA and global logout under an account-security area.

Working UI copy:

- title: **Безопасность аккаунта**
- feature: **Двухфакторная аутентификация**
- state: **Не подключена** / **Включена**
- supported apps: Яндекс Ключ, Google Authenticator and other TOTP-compatible applications.

Important user benefit to state explicitly:

> **После подключения 2FA код для списания бонусов будет доступен сразу в личном кабинете — ждать SMS больше не потребуется.**

Do not label the feature as "Яндекс Ключ" only. Product name should remain generic TOTP / application authenticator; Yandex Key is one supported client.

Account JS baseline:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/local/templates/eurasia/js/account.js
SHA256=8a4d6de0051d6c0b9fd6d536fff11a7d433a0a0d238d92652c6e07ac4b4aa321
```

## 5. Pilot scope

First implementation must be controlled on the operator's personal Bitrix account only:

```text
USER_ID=880339
ACTIVE=Y
BLOCKED=empty / not blocked
EMAIL_PRESENT=YES
PHONE_PRESENT=YES
```

At the latest read-only MFA inspection:

```text
OTP_OBJECT_EXISTS=YES
OTP_INITIALIZED=NO
OTP_ACTIVATED=NO
OTP_USER_ACTIVE=NO
SECRET_PRESENT=NO
```

No TOTP secret has been created for this pilot account yet.

Do not roll out to all guests until the pilot enrollment, login challenge, session revocation and direct-PIN behavior are end-to-end accepted.

## 6. Existing Bitrix MFA/TOTP implementation

Bitrix already contains a native MFA implementation:

```text
Bitrix\Security\Mfa\Otp
```

Source:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/bitrix/modules/security/lib/Mfa/Otp.php
```

Confirmed public functionality includes:

- `getByUser($userId)`
- `regenerate(...)`
- `getProvisioningUri(...)`
- `verify(...)`
- `getSyncParameters(...)`
- `syncParameters(...)`
- `save()`
- `activate()`
- `deactivate(...)`
- `verifyUser(...)`
- attempt tracking
- optional remember/skip-cookie support
- recovery-code integration
- mandatory/optional configuration methods.

Therefore the preferred architecture is:

**Reuse native Bitrix MFA/TOTP unless the final pre-write verification proves it unsuitable for ordinary guest accounts.**

Do not introduce a second custom TOTP secret store or parallel verifier without a concrete incompatibility.

## 7. Current global MFA feature state

Read-only runtime inspection on 2026-09-18:

```text
SECURITY_MODULE_LOADED=YES
MFA_OTP_CLASS_EXISTS=YES

OTP_ENABLED=NO
RECOVERY_CODES_ENABLED=NO
OTP_SMS_ENABLED=YES
OTP_EMAIL_ENABLED=NO
DEFAULT_TYPE_CLASS=Bitrix\Security\Mfa\OtpType
TYPES_DESCRIPTION_COUNT=2
```

This is critical.

In the inspected Bitrix source, `verifyUser()` returns success immediately when `isOtpEnabled()` is false.

Therefore:

- creating an OTP record for USER_ID 880339 while global OTP remains disabled would **not** enforce TOTP at login;
- the global Bitrix OTP facility will have to be enabled if native MFA is used;
- it must be enabled in **optional / non-mandatory** mode so accounts without activated OTP continue using the existing login flow.

The final pre-write audit still needs to confirm the exact production option names/current values and verify that optional mode is safe with the current population.

## 8. Native MFA storage

Current Bitrix table:

```text
b_sec_user
```

Confirmed columns:

```text
USER_ID int primary key
ACTIVE char(1)
SECRET varchar(64) nullable
TYPE varchar(16)
PARAMS text nullable
ATTEMPTS int nullable
INITIAL_DATE datetime nullable
SKIP_MANDATORY char(1)
DEACTIVATE_UNTIL datetime nullable
INIT_PARAMS text nullable
EMAIL varchar(255) nullable
DATE_SENT_EMAIL datetime nullable
```

Bitrix source behavior confirmed:

- `getByUser()` reads stored `SECRET` and converts the stored hex representation back to bytes;
- `regenerate()` generates random secret bytes, resets attempts/state and prepares OTP state;
- `save()` stores `getHexSecret()`;
- a regenerated secret cannot be saved as a valid initialized configuration until required initialization/sync parameters exist;
- `activate()` refuses an uninitialized OTP and persists active state after successful initialization.

Recovery table:

```text
b_sec_recovery_codes
```

Confirmed columns:

```text
ID
USER_ID
CODE
USED
USING_DATE
USING_IP
```

Recovery codes exist in the Bitrix subsystem but are globally disabled at the current production state. Do not assume they are available to guests until explicitly enabled/tested.

## 9. Important distinction: Bitrix OTP SMS vs enrollment ownership SMS

Runtime reports:

```text
OTP_SMS_ENABLED=YES
```

Do **not** interpret this as proof that the operator's desired enrollment ownership SMS is already implemented.

The business requirement is explicit:

```text
confirm current account phone by SMS
BEFORE provisioning the TOTP QR/secret
```

This ownership challenge is a product enrollment gate. It may reuse existing Evrasia SMS infrastructure, but it must not be silently conflated with any Bitrix MFA fallback/notification feature.

## 10. Existing QR capability

Composer already contains:

```text
chillerlan/php-qrcode
```

Vendor directory exists:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/vendor/chillerlan/php-qrcode
```

OpenSSL, Sodium and mbstring are present.

A separate QR/TOTP dependency should not be introduced unless required by the actual implementation.

## 11. Current website sign-in flow

On 2026-09-21 the current AJAX sign-in was upgraded with a dormant native Bitrix OTP second-step path while global OTP remained disabled.

Current production files:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/local/components/eurasia/signin/class.php
SHA256=06dcf40fcc5ab5b8ff5b77843bd02424f2136628bff8e2114152bb2a2555fb48

/home/site_evrasia/web/evrasia.spb.ru/public_html/local/templates/eurasia/components/eurasia/signin/main/script.js
SHA256=3f704994375adc0e074907effce43ef64a443c1a0e6708a780d84bd239cd9fc2

/home/site_evrasia/web/evrasia.spb.ru/public_html/local/templates/eurasia/components/eurasia/signin/main/template.php
SHA256=b8e65a204c69faa1e4c3ce84c6db047947c309e4742b3649eae351649a26eec4
```

Current behavior:

```text
eurasia:signin/process
    -> CUser->Login(...)
    -> normal user: complete login and Trusted Device registration
    -> OTP-required user: return otpRequired=true
       -> hidden 6-digit OTP form becomes visible
       -> eurasia:signin/otp
       -> CUser->LoginByOtp(...)
       -> complete login and Trusted Device registration
```

At deployment verification:

```text
OTP_ENABLED=NO
OTP_MANDATORY=NO
OTP_TOTAL_ROWS=0
PILOT_OTP_ROWS=0
```

Therefore the new OTP challenge path is present in production but dormant. It does not yet affect ordinary login and does not itself enable 2FA.

Verified backup retained under the neutral name:

```text
/home/site_evrasia/web/evrasia.spb.ru/backups/totp-login-step-v3-20260921-172331
```

There is also the older/general component:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/local/components/eurasia/signin/component.php
SHA256=8cf0f412599e1275f2f83b5a7a131f7664988b71d9cb642b19b68cb862903132
```

It already contains native MFA rendering logic:

```text
Mfa\Otp::isOtpRequired()
Mfa\Otp::isOtpRequiredByMandatory()
FORM_TYPE = "otp"
```

However, the production AJAX class flow currently does not yet have an explicitly verified second-step UI contract.

Before global OTP is enabled, prove exactly how `CUser->Login()` interacts with Bitrix's MFA login event/deferred parameters in this AJAX path and adapt the frontend if required.

## 12. Bitrix login verifier behavior already confirmed

Relevant native `verifyUser()` behavior was inspected:

- if OTP is globally disabled -> returns true immediately;
- if the user's OTP is not activated -> ordinary login succeeds unless mandatory MFA policy says otherwise;
- if OTP is activated and user is active -> OTP challenge logic is applied;
- accepted OTP format includes a 6-digit OTP;
- failed OTP increments attempt count;
- successful OTP clears prior attempts;
- optional remember-cookie behavior exists if configured;
- recovery-code path exists only when recovery codes are enabled.

This supports the planned **optional 2FA** model in principle, but global production enablement must still be guarded by the final pre-write audit.

## 13. Current website bonus PIN flow

Exact web endpoint:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/pincode.php
SHA256=32463182fde298777c94310e79fb166d382b8d8e91a1611fe225c0b7f55a3ad5
```

Current flow:

1. require an authenticated Bitrix user;
2. derive phone from login or `PERSONAL_PHONE`;
3. call `CRestis::pincode($phone)`;
4. obtain the actual RestIS `PinCode`;
5. if VK bot is enabled/available, send the PIN through VK;
6. otherwise send `"Ваш PIN-код: ..."` by `CSms::send(...)`;
7. return only delivery status/message to the browser.

The current endpoint does **not** display the PIN directly.

Current web UI component:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/local/components/eurasia/account.pincode/component.php
SHA256=972935afca826f7bc18ebc392904bf6e83dd1c8373b91d43ffe234d72565ec65
```

Its legacy/instant path can obtain a 4-digit PIN from RestIS, but the current post-2025 UI explicitly tells guests that the spending code is delivered by bot or SMS.

Template:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/local/templates/eurasia/components/eurasia/account.pincode/main/template.php
SHA256=89ed15352f1867bce939a14e5688ce06ef0a5918d03112aa276d82746798636f
```

JS:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/local/templates/eurasia/components/eurasia/account.pincode/main/script.js
SHA256=59e98e0ae4f111367f9825ddaf862fc143db810e5f091d7c4e012d535dc476da
```

JS posts to:

```text
/local/php_interface/pincode.php
```

Current buttons support the default path and an explicit SMS fallback.

## 14. Current mobile V4 PIN flow

Controller:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/Controllers/V4/PinCode.php
SHA256=7734ddbaba0e7ec9ddfc9f0a9008c0da0c1523e505e52caebac2bbaf637cd0
```

Current behavior is analogous:

- JWT auth;
- resolve user/phone;
- `CRestis::pincode($phone)`;
- send via VK when available;
- otherwise SMS.

Do not automatically change the mobile V4 flow as part of the first website pilot. Treat mobile 2FA/session semantics as a separate follow-up unless explicitly included and tested.

## 15. Existing session-revocation building blocks

Website global logout:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/logout.php
SHA256=e4933de270e85b10dd189628ccae9c0a8a0a83f8a403edd01760cd90eb392471
```

Existing operations include:

- Bitrix `UserAuthActionTable::addLogoutAction($userId)`;
- delete current refresh-token rows from `jwt_tokens`;
- delete legacy `access_token` rows;
- call common mobile JWT revocation.

Common service:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/lib/Services/AntiFraudBlockService.php
SHA256=aaec0e8b918e173e39db9e764a976da256ed410cc200dcef0af65c5d9a0d5702
```

It exposes:

```text
revokeMobileAccessTokens($userId)
```

and already participates in current logout/block revocation.

After successful 2FA activation, old sessions should be revoked so a session established before 2FA cannot continue as if it had passed the second factor.

Do not delete Trusted Device identity rows as part of this revocation.

## 16. Required security invariants

The implementation must preserve all of these:

1. Enrollment SMS confirmation happens before TOTP provisioning.
2. TOTP secret/QR is never logged.
3. Do not print secret values in diagnostics.
4. Do not create custom `UF_2FA` / custom TOTP table when native Bitrix MFA already satisfies the requirement.
5. Global Bitrix OTP, if enabled, must remain optional for users without activated 2FA.
6. Existing users without 2FA must continue to log in exactly as before.
7. Old sessions must not receive direct-PIN privilege after 2FA enrollment.
8. Direct bonus PIN requires both protected-account state and proof that the current session passed TOTP.
9. Ordinary accounts retain current VK/SMS PIN delivery.
10. First production rollout is USER_ID 880339 only.
11. Do not alter anti-fraud risk scoring merely because a profile has 2FA.
12. Do not expose account phone, OTP secret, QR URI or recovery codes in logs/diagnostic output.
13. Disable/reset/recovery flows must not allow a one-SMS shortcut that defeats 2FA.
14. If 2FA is reset/recovered later, consider a cooling-off period before direct bonus-PIN privilege is restored; this remains a design item, not yet implemented.

## 17. Current implementation boundary

Implemented in production on 2026-09-21:

- dormant OTP-aware AJAX login backend;
- hidden 6-digit OTP challenge UI;
- native `CUser->LoginByOtp(...)` continuation path;
- Trusted Device registration moved to complete-authentication semantics for the OTP branch;
- no change to global OTP state or native OTP storage.

Still **not** implemented/enabled:

- no Bitrix OTP global option was changed;
- no row was created/changed in `b_sec_user` for USER_ID 880339;
- no TOTP secret was generated;
- no QR was generated for the pilot account;
- pilot-only SMS ownership endpoint is deployed for USER_ID `880339`;
- pilot-only profile block **Безопасность аккаунта** is deployed and visually accepted by the operator;
- no direct-PIN branch was added;
- no durable session marker for "this session passed TOTP" was added;
- no recovery/disable flow was implemented;
- no rollout beyond USER_ID 880339 exists.

Ordinary website login was then verified end-to-end by the operator with `OTP_ENABLED=NO`: normal login completed successfully with no OTP prompt. This closes the dormant-login compatibility gate before pilot enrollment work.

## 18. Exact continuation point — TOTP 2FA workstream

When the operator explicitly asks to continue the TOTP 2FA workstream, resume from here without repeating the completed audits or dormant-login deployment.

Current production gate:

1. global OTP remains `OFF`;
2. mandatory OTP remains `OFF`;
3. `b_sec_user` remains empty;
4. USER_ID `880339` has no OTP row/secret;
5. dormant OTP-aware login code is deployed.

Next step:

1. ordinary current website login end-to-end with `OTP_ENABLED=NO` is **PASSED**: the operator confirmed normal login with no OTP prompt;
2. pilot-only **Безопасность аккаунта** enrollment UI and SMS ownership challenge for USER_ID `880339` are deployed; the compact combined security/logout layout is visually **ACCEPTED** by the operator;
3. global OTP remains OFF; after the component-permissions fix, the real pilot SMS ownership test for USER_ID `880339` **PASSED**: SMS was received and the code was confirmed successfully in the account UI;
4. native Bitrix pending-enrollment and QR frontend contracts are confirmed, and the pilot QR/TOTP continuation is deployed in production. The browser QR scan + first authenticator-code confirmation for USER_ID `880339` **PASSED**. Read-only post-verification confirmed exactly one isolated pilot row: `OTP_TOTAL_ROWS=1`, `PILOT_OTP_ROWS=1`, `NONPILOT_OTP_ROWS=0`, active TOTP row with secret and init params present, `PILOT_OTP_INITIALIZED=YES`, `PILOT_OTP_ACTIVATED=YES`, recovery codes still 0, while `OTP_ENABLED=NO` and `OTP_MANDATORY=NO`. Production SHAs remain account template `222b3c19d46da4b127e79b37a96c3d3676f8ba0b0bf84a26ec9f3b71cd21de41`, account JS `352447d463aa0dcea91abfae1d0690682424343bf6fbdb9dc8428dee9b6cb17a`, enrollment component `4a1bfbd277980dd529f89b43bc834498a543de3f371df4870963a3a4e6b09833`. Enforcement write is now **PASS** for USER_ID `880339`: pre-2FA sessions/tokens were revoked first (`UserAuthActionTable::addLogoutAction`, pilot `jwt_tokens=0`, `access_token=0`, Redis mobile revocation marker present with positive TTL), then native global OTP was enabled with mandatory explicitly OFF (`otp_enabled=Y`, `otp_mandatory_using=N`). The isolated pilot TOTP row remained active/initialized and no non-pilot OTP rows appeared; application/native source SHAs remained unchanged. Password→TOTP login E2E for USER_ID `880339` is **PASSED**. The account-card UI state bug has now been fixed in production: the card reads native Bitrix OTP state and renders the connected status/disable CTA for an active+initialized TOTP account. A protected `disableTotp` action and frontend flow were deployed; disabling requires the current 6-digit authenticator code and is not a one-click delete. Production SHAs after this UI patch: account template `8e17e304ed19f0cbee84a9dee948fe3da419a142132e919807ab39890b7c3690`, account JS `32e13d5d0a2a9f619681879a157fbc9a8348aabb255ae0c40a8d14691dab56d0`, TOTP component `d7856cbaa006e96b10d62ca5b94065bac91563bc11ed50ea7bfbffb46694050e`. Deployment preserved `OTP_ENABLED=YES`, `OTP_MANDATORY=NO`, one isolated active+initialized pilot TOTP row, and no non-pilot OTP rows. Browser visual acceptance of the connected state is now **PASSED**: `Подключена` and `Отключить 2FA` render correctly, and the follow-up CSS sizing fix for the disable CTA was accepted visually. Do not actually disable the pilot yet. Native auth-context persistence is now proven from production Bitrix: `LoginByOtp()` sets `Authentication\Context::setOtpUsed(true)`; `CUser::UpdateSessionData()` serializes the full context into `SESS_AUTH['CONTEXT']`; public `CUser::getContext()` restores it with `Authentication\Context::jsonDecode()` on later requests; public `Context::isOtpUsed()` reads the persisted flag. The first current-browser `sessionProof` call reached the component but failed because it reused the enrollment-only `requirePilotUser()` guard. Production audit proved that guard intentionally rejects requests once global OTP is enabled and also rejects an existing `b_sec_user` row, so it is unsuitable for post-enrollment session proof. The same audit also found `disableTotpAction()` currently reuses that enrollment-only guard; do **not** test or enable real disable semantics yet. `sessionProofAction()` was corrected to validate the currently authorized pilot directly, and the real browser probe now executes successfully. Result: `authenticated=true`, `pilotUser=true`, `otpActive=true`, `contextUserMatches=true`, but **`otpUsed=false`**, therefore `directPinEligible=false`. This is a security gate failure, not permission to weaken the rule: direct PIN remains disabled. The diagnostic browser probe now proves the persisted session itself was replaced/rebuilt as cookie authentication: `contextMethod=cookie`, `rawSessionContextPresent=true`, `rawSessionUserMatches=true`, `otpUsed=false`, `rawSessionOtpUsed=false`, `rawSessionContextMethod=cookie`, `objectRawOtpUsedMatch=true`, and `directPinEligible=false`. Therefore this is not an in-memory getter/cache discrepancy: `SESS_AUTH['CONTEXT']` itself currently contains a cookie-auth context with `otpUsed=false`. Direct PIN remains disabled. The cookie/session audit and follow-up auth-action audit narrowed the failure further. A single pilot `logout` row remains in `b_user_auth_action` (`ID=5254528`), but it is **not** the cause of the new-session loss: `Authorize()` sets `justAuthorized=true`; `CheckAuthActions()` first marks the action ID in session `AUTH_ACTIONS_PERFORMED`, and when `IsJustAuthorized()` is true it skips executing logout. On the next request in the same PHP session the action is skipped because that action ID was already marked performed. Therefore the stale logout action cannot by itself convert the fresh post-TOTP session into cookie auth. Current facts remain: `auth_multisite=Y`, PHP session cookie domain is host-only/empty, `regenerateIdAfterLogin` is unset, and the final `/account/` context is rebuilt as `method=cookie` / `otpUsed=false`. The topology audit confirmed a real cross-host session risk: Bitrix `auth_multisite=Y`, PHP session cookies are host-only (`session.cookie_domain` empty), site `s1` declares `SERVER_NAME=evrasia.rest`, the web vhost accepts `evrasia.spb.ru`, `www.evrasia.spb.ru`, `evrasia.rest`, and `www.evrasia.rest`, and `.htaccess` 301-redirects `(www.)evrasia.spb.ru` plus `www.evrasia.rest` to `https://evrasia.rest`. Live HEAD also confirms `https://evrasia.spb.ru/account/ -> https://evrasia.rest/account/`. This proves a host switch would lose `PHPSESSID` while Bitrix stored-auth cookies can still restore the user as `method=cookie`, exactly matching the observed `otpUsed=false`; however we have not yet proven that the actual OTP AJAX request ran on the noncanonical host. Important interpretation correction: the existing `method=cookie` / `otpUsed=false` probe was performed some time after the original successful TOTP login, so it does **not** prove the context was lost immediately on redirect; normal PHP/Bitrix session expiry followed by stored-cookie re-auth can produce the same state and would be security-appropriate (direct-PIN privilege should disappear). The fresh canonical private-window test is now **PASSED**. Starting explicitly on `https://evrasia.rest/`, completing password→TOTP, and running `sessionProof` immediately after `/account/` opens returned: `authenticated=true`, `pilotUser=true`, `otpActive=true`, `contextUserMatches=true`, `otpUsed=true`, `contextMethod=password`, raw serialized session context present/user-matching with `rawSessionOtpUsed=true`, `rawSessionContextMethod=password`, `objectRawOtpUsedMatch=true`, and `directPinEligible=true`. This proves the native current-session rule is correct for a fresh TOTP-authenticated session. The earlier later-state `method=cookie` / `otpUsed=false` is therefore an expected trust downgrade after loss/expiry/rebuild of the original PHP session and must remain ineligible for direct PIN. No custom session flag is needed. Next gate before any PIN write: verify an ordinary non-2FA account still logs in normally with global OTP enabled and mandatory OFF; then audit the current production `pincode.php` and its caller and implement pilot-only direct PIN under `otpActive && currentSession.otpUsed`. Also note an unrelated security finding: `local/php_interface/verify_order_by_sms.php` contains a hardcoded `PHPSESSID`; do not print its value again, and handle/remediate it separately after the TOTP continuity gate;
5. verify the next website login requires the second factor for the pilot while ordinary accounts remain unchanged.

Only after enrollment/login/session E2E acceptance should the direct bonus-PIN change be enabled for the pilot.

Then implement web direct-PIN behavior with the explicit rule:

```text
active 2FA account
AND current session is post-TOTP / second-factor-confirmed
    -> direct PIN response

otherwise
    -> current VK/SMS behavior
```

## 19. Documentation / source-of-truth rule

Production factual state wins over this checkpoint.

Before any WRITE:

- re-check current source SHAs;
- re-check Bitrix MFA options/state;
- create backup/rollback;
- do not rely on these SHA values as blind write guards if production has legitimately changed since 2026-09-18;
- use one complete copy/paste server script, syntax-check first, keep terminal open;
- clear terminal/scrollback at script start;
- never print secret values.

This document is a continuation checkpoint, not proof that 2FA is deployed.


Direct-PIN pre-write audit is now **PASS** (`PASS_COUNT=9`, `FAIL_COUNT=0`, `WARN_COUNT=0`). Live production baseline: `/local/php_interface/pincode.php` `32463182fde298777c94310e79fb166d382b8d8e91a1611fe225c0b7f55a3ad5`, account.pincode component `972935afca826f7bc18ebc392904bf6e83dd1c8373b91d43ffe234d72565ec65`, active account.pincode template `5214db0008cff1e31d27b11c4184a17565e4b343f6d033cf04c0337faafedd96`, active account.pincode JS `8c240106d42afe20cfb4693e50de369efbdd9663531c4b4f0927f505ba81f1fc`. The endpoint authenticates through `CurrentUser::get()->getId()`, calls `CRestis::pincode()` once, rate-limits, then delivers through VK/SMS; browser currently never receives the PIN. The old CSRF check is commented out although frontend already sends `sessid`. Pilot direct-PIN patch must remain server-authoritative, pilot-only, canonical-host only, require active initialized TOTP + current native context user match + `isOtpUsed()=true`, require POST+valid Bitrix sessid for direct disclosure, preserve the existing rate limiter, and leave all non-eligible/non-pilot VK/SMS behavior unchanged. Mobile V4 remains out of scope.

A guarded direct-PIN deploy attempt correctly stopped before WRITE because production `account.pincode/main/script.js` had moved from audited SHA `8c240106...` to `d66d71a8b3dc4d310ff15c0a670efd2dbed3bc442e8aa7146cf92521c2fe7ae9`. Follow-up READ_ONLY drift audit reproduced the same SHA, confirmed endpoint/template were unchanged, confirmed no direct-PIN markers/handler existed, and isolated drift to PIN JS only. Comparison with the immediately preceding audit output shows the change is in the MobileID/SMS-code confirmation payload construction (`FormData` -> plain object); the primary `/local/php_interface/pincode.php` AJAX response handler remains structurally unchanged. Accept `d66d71a8...` as the current production JS baseline for the direct-PIN pilot patch; do not revert that change.

Immediately before the direct-PIN pilot WRITE, the live `account.pincode/main/script.js` changed from the earlier audit SHA to `d66d71a8b3dc4d310ff15c0a670efd2dbed3bc442e8aa7146cf92521c2fe7ae9`. A dedicated READ_ONLY drift audit reproduced that SHA, confirmed the drift is isolated to this JS file, found no direct-PIN markers/handler, and confirmed the current response handler still posts to `/local/php_interface/pincode.php` with Bitrix sessid and still has the legacy VK/SMS handling. The current production JS should therefore be treated as the new baseline for the guarded pilot patch; do not roll it back to the older `8c240106...` snapshot. The visible drift includes the MobileID/SMS confirmation helper using a plain data object for `id`, `code`, `sessid` instead of the earlier FormData construction and is unrelated to direct-PIN response handling.

## Direct-PIN pilot deployment / browser acceptance — 2026-09-23

The guarded pilot direct-PIN WRITE completed successfully in production for USER_ID `880339`.

Deployment result:

- `PASS_COUNT=9`, `FAIL_COUNT=0`, `WARN_COUNT=1`;
- warning only: `node` was unavailable for an optional staged JS syntax check; PHP staged/production syntax checks passed;
- rollback was not required;
- deployment backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/direct-pin-pilot-20260923-060243`;
- production PIN endpoint SHA: `4d637e1bff15682d3eae6a5b4e3ffa074e2543daaf408767e2894654df2a0713`;
- production account.pincode template SHA: `569aaba642d5601215b453b04d06837da04b1378a8a69fd079ef777ad5797710`;
- production account.pincode JS SHA: `9b7afe419ee979089fda4b65af3475f415ea1eec8827a3c27d7be594ee5a68b4`;
- PIN component, TOTP component and signin backend remained unchanged;
- OTP state remained `OTP_ENABLED=YES`, `OTP_MANDATORY=NO`, exactly one active initialized pilot TOTP row and zero non-pilot OTP rows;
- no DB/OTP/session/mobile mutation was performed by deployment.

Deployed server-side direct-disclosure rule remains pilot-only and fail-closed:

```text
USER_ID == 880339
AND host == evrasia.rest
AND request == POST
AND valid Bitrix sessid
AND TOTP initialized + activated
AND current auth context user == authenticated user
AND current auth context isOtpUsed() == true
    -> return the existing RestIS PinCode directly to the browser

otherwise
    -> preserve the existing VK/SMS delivery path
```

The existing `CRestis::pincode($phone)` source and the existing Redis one-request-per-minute rate limiter remain the source/rate-control mechanism; no new PIN generator or PIN store was introduced.

Browser acceptance on a fresh incognito canonical session is **partially PASSED**:

- password -> TOTP login succeeded;
- the PIN block switched to the protected-state UI and showed `Показать Пин-код`;
- pressing the button returned and displayed a real PIN in the browser;
- the operator has **not yet confirmed that the displayed PIN was successfully accepted for an actual bonus-spend/payment operation**.

Therefore the direct-display transport/UI E2E is accepted, while **functional PIN validity at real use remains pending operator confirmation**. Do not mark the full direct-PIN business E2E complete until that confirmation is received.

Still pending before broader rollout:

1. operator confirmation that the displayed direct PIN is operational in the real spend/payment flow;
2. negative-path acceptance for a cookie-restored / `otpUsed=false` pilot session (must fall back to legacy VK/SMS and must not disclose PIN);
3. ordinary non-2FA login/PIN compatibility with global OTP enabled and mandatory OFF;
4. rollout beyond USER_ID `880339` remains prohibited until these acceptance gates pass.


### Remaining TOTP/security follow-ups after direct-PIN deployment

Do not repeat enrollment/session-proof/direct-PIN deployment.

The remaining pilot gates are:

1. confirm that the displayed direct PIN is actually accepted in a real bonus-spend/payment operation;
2. explicitly verify a cookie-restored / `otpUsed=false` pilot session falls back to legacy VK/SMS and cannot see direct PIN;
3. verify an ordinary non-2FA account still logs in and uses the legacy PIN path with global OTP enabled and mandatory mode OFF;
4. keep rollout beyond USER_ID `880339` prohibited until those gates pass.

Separate work not to mix into the direct-PIN acceptance:

- `disableTotpAction()` currently reuses the enrollment-only pilot guard and must not be exercised until disable/reset/recovery/cooling-off semantics are redesigned;
- `verify_order_by_sms.php` contains a hardcoded PHP session cookie value. Treat it as sensitive, never reproduce it, and remediate it in a separate guarded security task.
