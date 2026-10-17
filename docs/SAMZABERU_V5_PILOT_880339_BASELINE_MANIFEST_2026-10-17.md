# V5 — Pilot USER_ID 880339: verified read-only baseline snapshot (2026-10-17)

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
