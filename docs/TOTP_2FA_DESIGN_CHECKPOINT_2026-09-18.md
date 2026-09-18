# Evrasia — TOTP 2FA / protected-profile design checkpoint

> Status: **PLANNED / INVESTIGATED / NOT IMPLEMENTED**
>
> Date: **2026-09-18**
>
> Continuation keyword: **ПАНДА ДВА**
>
> Scope: Bitrix website personal account / sign-in / bonus PIN flow on production host `evrasia`.
>
> This document records the operator request, confirmed production structure, security invariants, source paths, current SHA baselines, and the exact continuation point. No 2FA production mutation had been performed at the time of this checkpoint.

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

Primary current class action:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/local/components/eurasia/signin/class.php
SHA256=faa773dd38eccc86057cdb157d8d812a17c50ebc305c067f12fcd0a196255f68
```

Current successful first-factor path:

```text
BX.ajax.runComponentAction('eurasia:signin', 'process', ...)
    -> processAction()
    -> CUser->Login($login, $password, 'Y', 'Y')
    -> TrustedDeviceWebService::registerSuccessfulPasswordLogin(...)
    -> frontend redirects to /account/
```

Frontend:

```text
/home/site_evrasia/web/evrasia.spb.ru/public_html/local/templates/eurasia/components/eurasia/signin/main/script.js
SHA256=cb42522b82a5eeb528a5023e829f0ae46f32b3ac16156c7ee7bd7107ea86b7e7
```

Current JS redirects to `/account/` immediately on successful component action.

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

## 17. What has NOT been implemented

At checkpoint time:

- no Bitrix OTP global option was changed;
- no row was created/changed in `b_sec_user` for USER_ID 880339;
- no TOTP secret was generated;
- no QR was generated for the pilot account;
- no SMS enrollment endpoint was created;
- no profile 2FA block was deployed;
- no login JS/backend was changed;
- no direct-PIN branch was added;
- no session marker for "this session passed TOTP" was added;
- no recovery/disable flow was implemented;
- no rollout beyond USER_ID 880339 exists.

All 2FA work so far is **read-only investigation and design**.

## 18. Exact continuation point — keyword "ПАНДА ДВА"

When the operator writes **ПАНДА ДВА**, resume from here without repeating the completed audits.

The next step is the already planned final **READ_ONLY pre-write audit**, limited to:

1. count rows in `b_sec_user`;
2. identify whether any active OTP users already exist;
3. inspect exact Bitrix global OTP option names/current values;
4. prove optional/mandatory behavior and keep mandatory OFF;
5. inspect native setup call sites for the exact sequence around:
   - `regenerate()`
   - `syncParameters()`
   - `save()/activate()`;
6. confirm Bitrix login event wiring for `verifyUser()`;
7. confirm no existing production MFA user would be affected unexpectedly.

If that confirms the expected state, proceed to the first guarded WRITE:

```text
optional Bitrix OTP enabled globally
        |
        +-> only USER_ID 880339 gets pilot enrollment UI
        |
        +-> SMS ownership verification
        |
        +-> QR / provisioning URI
        |
        +-> TOTP verification
        |
        +-> activate native Bitrix MFA for 880339
        |
        +-> revoke pre-2FA sessions
        |
        +-> verify next website login requires second factor
```

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
