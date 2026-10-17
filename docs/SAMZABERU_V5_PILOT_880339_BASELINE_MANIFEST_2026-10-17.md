# V5 — Pilot USER_ID 880339: verified read-only baseline snapshot (2026-10-17)

## 2026-10-17 evening: website TOTP/PIN browser acceptance (newer than zero-OTP snapshot)

**Current confirmed website behavior (operator's real browser and temporary guarded PHP probe):**
- On pilot `880339`, native Bitrix TOTP was re-enrolled via the existing website flow after a separately approved one-condition fix in `local/components/eurasia/totp.enrollment/class.php`. This is **V4/website functionality**, **NOT** a V5 native writer/epoch acceptance test.
- A first PIN-request observation in a previously existing/cookie-restored browser session reported `OTP_INITIALIZED=YES`, `OTP_ACTIVATED=YES`, `CONTEXT_USER_MATCHES_PILOT=YES`, but `CONTEXT_OTP_USED=NO` and `DIRECT_PIN_ELIGIBLE=NO`. This is consistent with the existing security rule: merely having TOTP enabled does not grant direct PIN.
- After **full logout → fresh login/password → actual native TOTP code → PIN click**, the guarded live PIN-endpoint probe reported `REQUEST_POST=YES`, `CANONICAL_HOST=YES`, `RESOLVED_USER=YES`, `OTP_INITIALIZED=YES`, `OTP_ACTIVATED=YES`, `CONTEXT_USER_MATCHES=YES`, `CONTEXT_OTP_USED=YES`, `DIRECT_PIN_ELIGIBLE=YES`, `EXCEPTION_FREE=YES`. Probe observed boolean flags only; no PIN/OTP/credentials logged and no diagnostic SMS sent.
- Temporary production `local/php_interface/pincode.php` instrumentation was **automatically reverted**: `ORIGINAL_RESTORED=YES`, `FINAL_STATUS=PASS`, `FINAL_RC=0`, verified original SHA-256 `c7fa868712ab10bef5e85384158bb1c8b21548379b278de5fb808a0e3505afff`.
- Operator then confirmed an **actual on-screen bonus PIN with no SMS** using the original restored endpoint. **Website acceptance: PASS**.
- Crucial chronological correction: `OTP_ROW_COUNT=0` in the morning's verified metadata manifest describes the **earlier snapshot only**. The pilot was **subsequently re-enrolled** in the website; that old manifest is not proof of current empty MFA, not a full backup, and must not be used as a current-state guard. A new exact current DB row count was not collected in the fresh browser acceptance; real `Otp::isInitialized()/isActivated()` were YES in the PHP request.
- This proves the browser's V4/native TOTP→PIN eligibility and display path, **not** V5 `AUTH_EPOCH`, native `Otp::save/delete` adapter atomicity, ten-path native security hook coverage, or V5 readiness. No V5 activation, migration, epoch initialization, or V4 core changes were made as part of these diagnostic probes.

**Next V5 gate:** honor the decision to run V5 beside V4 on the existing Bitrix installation without requiring a new VM. Finish **independently verified real native Bitrix transaction/hook acceptance** for the staged V5 adapter and all required security-event paths before writing `AUTH_EPOCH` or activating V5. Do not repeat the closed V4/browser TOTP enrollment/PIN tests as a substitute for V5 evidence, and do not attempt further production MFA mutation without separate, precisely scoped approval.

## Actual operator output — PASS

On production host `evrasia`, the operator executed a guarded CLI snapshot of
native Bitrix `eurasia_new` pilot `b_user.ID=880339` using the verified
real `Bitrix\Main\DB\MysqliConnection->getResource()`. The connection
was set to `SESSION TRANSACTION READ ONLY` and verified before
`START TRANSACTION READ ONLY`, with `ROLLBACK` after metadata reads.

```text
FINAL_STATUS=PASS
FINAL_RC=0
PILOT_ID=880339
ACCOUNT_ACTIVE=Y
ACCOUNT_BLOCKED=NO
OTP_ROW_COUNT=0
RECOVERY_ROW_COUNT=0
V5_EPOCH_ROW_COUNT=0
PHONE_ROW_COUNT=1
CONFIRMED_PHONE_ROW_COUNT=1
DATABASE_WRITE=NO
MFA_WRITE=NO
APPLICATION_WRITE=NO
BACKUP_FILE_WRITE=YES
OTP_SAVE_CALLED=NO
OTP_DELETE_CALLED=NO
SMS_SENT=NO
```

**Verified file** (operator stdout, no direct filesystem reinspection):
```text
BACKUP_PATH=/root/ev-v5-pilot-880339.3OlQWRPf/baseline.json
BACKUP_SHA256=59619ceb253333a909d995e2f23c0e25c64658d5ea469b894a3aa90882024c25
BACKUP_BYTES=527
BACKUP_CONTENT=EMPTY_MFA_STATE_MANIFEST
FULL_DATABASE_BACKUP=NO
WEB_SESSIONS_BACKED_UP=NO
```

**Meaning:** This file is a verified *metadata/status baseline manifest*, not a
full content-preserving backup of `b_user`, `b_sec_user`,
`b_sec_recovery_codes`, `ev_samzaberu_auth_state` or sessions.
It accurately records that the MFA and recovery tables had **zero rows for
the pilot** at the time of observation. Do not claim it can restore a
future nonempty TOTP secret or authentication session, or safely rollback
effects of native `Otp::save()` or `Otp::delete()`.

## Next controlled acceptance

The 2026-09-24 native website TOTP enrollment/login/disable tests **already
passed** on 880339 (see `docs/TOTP_2FA_DESIGN_CHECKPOINT_2026-09-18.md`,
historical addendum). Re-enrollment through the existing website UI would
test V4/native enrollment again, **not** the GitHub-only
`V5BitrixOtpNativeMutationPort` and not its epoch CAS or ten native
security writers. Avoid presenting it as V5 adapter acceptance.

Any additional *production* MFA write on 880339 requires an explicit
operator-approved active test: verified current user state, attention to
OTP/recovery changes, session revocation, and a precise recovery path.
Do not silently reuse the empty-state manifest as a full backup;
do not initialize V5 epoch while the ten-path installed coverage is
unattested. Production V5 stays OFF, V4 and `main` unchanged.
