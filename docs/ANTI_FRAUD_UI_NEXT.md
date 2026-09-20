# Anti-Fraud UI — historical plan and current continuation

> Updated: **2026-09-20**
>
> The UI plan captured on 2026-09-09 is no longer the active continuation point.

## Status of the previously planned items

### `Новый`

The earlier plan asked to make newly appeared accounts more visible.

Current production semantics were later changed and finalized through PR #47:

- source: `anti_fraud_web_account_state`;
- `Новый` means the USER_ID first appeared in the web Anti-Fraud interface less than 24 hours ago;
- refreshes do not reset/extend the window;
- forensic case-delta history remains separate.

Status: **IMPLEMENTED / PRODUCTION**.

### Trusted Device / accumulated device-hash visibility

The earlier plan asked to expose factual device-hash accumulation and make device identity visible in cases.

This evolved through PR #57 and PR #58.

Current case-detail display:

- one linked USER_ID on a Trusted Device hash → **Устройство**;
- multiple linked USER_ID values on the same hash → **Общее устройство**;
- both labels refer to the same Trusted Device hash type;
- shared devices remain the linking/grouping evidence;
- single-account Trusted Device hashes are display context and do not group accounts by themselves.

Status: **IMPLEMENTED / PRODUCTION / OPERATOR ACCEPTED**.

For authentication/Trusted Device accumulation counts, continue to use `docs/TRUSTED_DEVICE_DIAGNOSTICS.md`; do not confuse those counts with Anti-Fraud linking metrics.

## Current Anti-Fraud continuation

The next work is no longer a generic UI cleanup.

Current task:

**Step 2 — manual operator investigation by phone: «Добавить на проверку».**

Already done:

- protected Bitrix phone resolver is production and verified.

Pending:

1. bot gateway;
2. persistent operator-investigation state;
3. explicit operator-authorized 60-day enrichment;
4. normal risk scoring;
5. persistent visibility even when automatic Risk is 0;
6. phone-first UI action;
7. explicit operator source/reason, e.g. `Авито`;
8. no auto-block;
9. tests and staged rollout.

Authoritative continuation:

`docs/ANTI_FRAUD_CHECKPOINT_2026-09-20.md`

Do not implement the old 2026-09-09 plan as if it were still pending.
