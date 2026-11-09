# RESTISBONUS -> Bitrix: production bonus event outbox (checkpoint 2026-11-09)

> **Status:** documented from real production read-only inspections and SQL Server query results on 2026-11-09. This is **not** a deployment and does not change any running service or database. **Read this file first** before investigating `BITRIX_BRIDGE_RETRY` again.
>
> **System boundary:** This is the external RESTISBONUS -> website Bitrix bonus synchronization running on website host `evrasia` (`evrasia.spb.ru`), **not** the `eur-bot-01` / Evrasia AI Bot application. Do not confuse the SQL Server outbox with the site's MariaDB or the bot's PostgreSQL.

## 1. Confirmed systems and data flow

```text
Microsoft SQL Server 2014 / RESTISBONUS
  dbo.Cards.CardTotalSum                 (source balance)
  dbo.tr_ev_bitrix_bonus_changes         (AFTER UPDATE event producer)
           |
           v
  dbo.ev_bitrix_bonus_events             (persistent SQL Server outbox)
           |
           v
Website host evrasia
  systemd ev-bonus-sync.timer
           |
           v
  ev-bonus-sync.service  [oneshot; inactive/dead after successful run is normal]
           |
           v
  /root/ev-bonus-sync-runtime/event_sync/event_runner.py
           |
           v   Python import of event_worker; bounded batch; output suppressed internally
  /root/ev-bonus-sync-runtime/event_sync/event_worker.py
           |
           +--> SQL Server: claim -> find source card -> read/normalize balance
           |
           +--> subprocess PHP, 90-second timeout
                  /root/ev-bonus-sync-runtime/event_sync/event_bridge.php
                        |
                        +--> Bitrix user matching
                        +--> bonus_transaction_executor.php
                        +--> cas_writer.php  (transactional compare-and-swap)
                        +--> bonus cache invalidation (existing policy)
           |
           v
  SQL Server acknowledge OR retry / manual-review state
```

**Runtime file locations (confirmed, not guesses):**

| File | Absolute path or location | SHA-256 from production |
| --- | --- | --- |
| Runner | `/root/ev-bonus-sync-runtime/event_sync/event_runner.py` | `7dab415c7929661e647ed766c7950afccd8825434fc49b89af8b1b79a377c1b5` |
| Worker | `/root/ev-bonus-sync-runtime/event_sync/event_worker.py` | `54cd8c7f370a7096a0f29bb2203839485f6cdf1a7680b2b28acdeeea09faa137` |
| PHP bridge | `/root/ev-bonus-sync-runtime/event_sync/event_bridge.php` | `2fc34351b4f4b3cb59438ea3873ab878b46deb5bfeb4bdc828329515df36f783` |
| Transaction executor | `/root/ev-bonus-sync-runtime/bonus_transaction_executor.php` | SHA not recorded |
| CAS writer | `/root/ev-bonus-sync-runtime/cas_writer.php` | SHA not recorded |

These are **website-server runtime files**, not necessarily source-controlled in this GitHub repository. Source inspection must use the actual production versions. The incorrect earlier search in `/home/site_evrasia/web/evrasia.spb.ru/public_html/local` found **zero** matching files; **do not repeat that search**.

`event_runner.py` is the **actual** `ExecStart` target of `ev-bonus-sync.service`; a direct reference to `event_worker.py` in the service is not expected. The runner checks the worker's pinned SHA against `EXPECTED_SHA` and release manifest, imports it as a Python module, wraps stages for timing, handles at most 100 claims per invocation (also uses an internal deadline), and suppresses the imported worker's stdout/stderr. Therefore per-event `ACKNOWLEDGED` / `RETRY_RECORDED` text is **not expected** in the systemd journal. Do not change the worker without accounting for the runner's pinned hash.

## 2. SQL Server outbox: known contract

- Database: `RESTISBONUS`, outbox: `dbo.ev_bitrix_bonus_events`.
- Confirmed read-only query columns: `EventID`, `ProcessingStatus`, `Attempts`, `LastErrorCode`, `CreatedAt`, `NextAttemptAt`.
- Status `0` = pending, `2` = acknowledged/processed, `3` = attempts exhausted/manual review. Status `1` represents in-progress, but there were no such rows in the latest snapshot.
- Retry limit: 10 attempts; all current status-3 events reached 10 attempts.
- Worker logic: claim an event, validate source phone/card, fetch authoritative source balance, call PHP Bridge, then acknowledge on an accepted result or record a retry/error. The SQL-side claim/ack/retry procedures exist, but **their exact names and signatures are not recorded here**; inspect before any write.

**Snapshot, 2026-11-09 (operator SQL results, not live monitoring):**

| Status | Count |
| --- | ---: |
| 0 pending | 9 |
| 1 processing | 0 |
| 2 processed | 218185 |
| 3 exhausted | 1078 |
| **Total** | **219272** |

**Status 3 breakdown:** `BITRIX_BRIDGE_RETRY` = 1055; `SOURCE_CARD_NOT_UNIQUE` = 21; `SOURCE_CARD_STATUS` = 2. All 1078 have `Attempts=10`. By `CreatedAt`: older than 7 days = 810; 1–7 days = 237; last 24 hours = 31. **`CreatedAt` is the event creation time, not the latest failure time.** Do not interpret the latter distribution as failure-time timestamps.

## 3. Exact bridge result / failure contract

- Worker `call_bitrix_bridge()` uses `subprocess.run` with a **90-second timeout**, captures stdout, and treats nonzero PHP exit code, anything other than **one output line**, or an unexpected output status as `RETRY`.
- Accepted PHP statuses include `UPDATED`, `ALREADY_EQUAL` and `NO_SITE_USER`; they are acknowledged by the worker (not requeued).
- When the bridge returns `RETRY`, or the subprocess result fails validation, `execute_one` records `BITRIX_BRIDGE_RETRY` via `retry_event`.
- Source-data validation errors are recorded separately (e.g. `SOURCE_CARD_NOT_UNIQUE`, `SOURCE_CARD_STATUS`).
- `event_bridge.php` initializes result to `RETRY`, checks release gates and catches `Throwable`; on exception it retains/returns `RETRY`.
- `cas_writer.php` uses a transaction and checks expected old balance; verified symbolic exception codes include `OLD_BALANCE_CHANGED`, `CAS_CONFLICT`, `CAS_POSTCHECK_FAILED` and `STORED_BALANCE_NONFINITE`. **Do not assume any one of these was the historical cause** of the 1055 bridge retries without evidence.
- The bridge can append a *symbolic* exception trace **only if** `EV_BONUS_TRACE_FILE` is configured. The latest systemd/runner inspection did **not** resolve that setting. Logging may be incomplete, and historic exception reasons are not recoverable from the generic SQL error code.

**Safety:** Never bulk reset `ProcessingStatus=3` to `0` or replay the 1055 events merely because the bridge can now run. First verify whether the corresponding Bitrix balance writes already took effect; do not cause repeated or stale balance writes. The existing compare-and-swap checks are part of this safeguard, not proof that arbitrary replay is safe.

## 4. Confirmed logging cutoff: 128 KiB

**Root cause of the stalled timing log is confirmed, not a hypothesis.**

Production `event_bridge.php`, lines 67–77, checks:

```php
if (
    @is_file($path) &&
    @filesize($path) < 131072
) {
    @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
}
```

Thus when the file reaches/exceeds **131072 bytes (128 KiB)**, it silently stops appending; a final entry can take it slightly past the limit. The operator confirmed:

- Filename: `bridge-timings.log` (full absolute path was **not printed**, resolve from `event_bridge.php` line 67).
- Actual size: **131114 bytes**, or 42 bytes above the guard threshold.
- Last write: **2026-11-08 04:21:12 UTC** (= 07:21 MSK, 08:21 Yerevan).
- Rotated timing files detected: **0**.
- Bridge code was not modified after that log's last write.
- The worker continued operating; **stale timing logs do not prove an idle worker**.

Timing log contained **1701** parsed entries: `UPDATED=1604`, `ALREADY_EQUAL=10`, `NO_SITE_USER=20`, `RETRY=67`. These 67 log entries are *not* necessarily the same 1055 status-3 events.

| Existing metric | UPDATED (1604) | RETRY (67) |
| --- | ---: | ---: |
| `TOTAL_MS` median | 141 ms | 139 ms |
| `TOTAL_MS` p95 | 7785 ms | 2483 ms |
| `TOTAL_MS` max | 12265 ms | 2655 ms |
| `PROLOG_MS` median | 131 ms | 132 ms |
| `LOOKUP_MS` median | 2 ms | 4 ms |
| `CAS_MS` median | 3 ms | no sample reported |

**Interpretation only:** observed logged `RETRY` events were short, so a 90-second worker timeout is not supported as their cause. Exact PHP failure reasons remain **unconfirmed**; the CAS branch may not have been reached for many of these errors.

## 5. Service operational facts

- `ev-bonus-sync.timer`: confirmed `active / waiting`.
- `ev-bonus-sync.service`: periodically runs `event_runner.py` and usually ends `inactive / dead` with `Result=success` / exit 0. At one inspection it was `activating` during a run. Both observations are normal for this periodic service.
- The runner checks a release manifest and exact worker hash. It prints aggregate claimed count and stage-duration metrics but suppresses worker per-event output.
- Do not confuse “service successful” with “all bonus events successfully applied”: status 3 is stored in SQL Server, and ordinary runner exit 0 can coexist with failed events.

## 6. Next step / do not repeat

1. **Do not rerun the already completed source-discovery and runner-dispatch audits.** Actual entrypoint and file paths are documented above.
2. Make a *minimal, verified* change to logging **only after inspecting current code and choosing a bounded rotation strategy**; avoid unlimited trace files or silently discarding them. The 128 KiB cap is proven; no fix has been deployed as of this checkpoint.
3. Configure a safe, private `EV_BONUS_TRACE_FILE` for the *effective* runner -> PHP Bridge environment if targeted error capture is required. Avoid logging raw phone numbers, card numbers, secrets or exception messages that might contain personal information.
4. Analyze fresh failures **without rerunning status-3 events**, and distinguish mismatch, transient failure, CAS conflict and SQL/Bitrix runtime exception.
5. Only after validating idempotence and the actual final balance, plan **strictly scoped** retries with backups, expected-row checks, and post-checks. No blanket reset.

**Operational boundary:** The separate MariaDB 11.4 upgrade/restructure workstream is paused by the operator; do not resume it as part of bonus outbox investigation. No production DB, SQL Server, worker or site-code change was made by this documentation checkpoint.

## 7. Evidence / freshness

- Production host guards and runtime file discovery: PASS, 2026-11-09.
- Runner/worker/bridge SHA values: from operator's read-only production scripts.
- Last SQL Server breakdown: 2026-11-09 operator query output.
- Log cutoff: exact `@filesize($path) < 131072` condition and actual file size verified on production.
- Current issue **open**: reason for 1055 `BITRIX_BRIDGE_RETRY` events unclassified; exception trace not resolved; safe logging fix not installed.

> This document is a point-in-time **observed production checkpoint**. Refresh actual runtime state before any future write. GitHub documentation alone is not proof of the server's current state.

## 8. Production Bitrix account cleanup after duplicate-phone investigation (2026-11-09)

**Status: completed on production; operator-supplied execution result was `FINAL_STATUS=PASS`, `FINAL_RC=0`, and `OPERATION_STATUS=COMPLETED`.** This is a separate, targeted account/order maintenance operation, not a fix for all historic bonus outbox retries. No phone numbers or private customer/order contents are included in this public repository.

- Investigation found **zero** duplicate phone-owner groups in `b_user_phone_auth`, but **more than 30** duplicate phone-owner groups in the indexed fallback fields `b_user.EV_BONUS_LOGIN_NORM` / `EV_BONUS_PHONE_NORM` (first 30 were printed). Actual bridge invokes fallback only under its defined conditions, so these generic duplicates are not yet correlated with all `BITRIX_BRIDGE_RETRY` events.
- Read-only scan of **1,386,013 non-NULL `b_uts_user.UF_BONUS`** values showed `NON_FINITE_COUNT=0` and `NON_NUMERIC_COUNT=0`. Thus no malformed/non-finite balance was found in that scan; this does **not** test for unusually large or negative *numeric* balances.
- A single operator-selected phone group originally had **three** Bitrix accounts. Operator selected two redundant accounts for deletion and designated the remaining one as the preserved owner. Exact personal phone/account identifiers are retained only in restricted operator diagnostics, not in this public GitHub note.
- Before deletion, the two redundant accounts had **four `b_sale_order` orders** (1 and 3). Other checked sale tables had no direct `USER_ID` references to those targets.
- Verified private backup snapshot of affected user/order records exists under the site's backup area; it is **not** a full database backup and should not be treated as a comprehensive automatic rollback.
- First attempt using `Bitrix\\Sale\\Order::load()->setFieldNoDemand('USER_ID', ...)->save()` **failed before committing any order transfer**, with the exact error obscured by the diagnostic's error sanitizer (`FAIL_CODE=A1`). Follow-up read-only query verified 4/4 orders still had their original owners and all three accounts still existed. **Do not present that attempt as successful or reuse the same code unmodified.**
- Successful follow-up used a **guarded, transactional, exact-four-row `b_sale_order.USER_ID` reassignment** to the preserved account, verified all four new owners, then invoked standard `CUser::Delete()` for each of the two redundant accounts. Final verification: both targets absent; preserved account exists; all four orders exist and belong to the preserved account. `USERS_DELETED=2`, `ORDERS_MOVED=4`, `ORDERS_DELETED=0`. Do not repeat this operation.
- No bonuses were re-applied, no SQL Server status-3 events retried, and no runner/bridge code deployed as part of this cleanup. **The historical 1,055 `BITRIX_BRIDGE_RETRY` events remain an independent open investigation**; this account cleanup does not prove or resolve their causes.

**Operational lesson:** A successful `Bitrix\\Sale\\Order::load()` and public presence of `setFieldNoDemand()` do not prove that the attempted owner-change `save()` will succeed on this production version. For any future account deletion involving orders, determine actual relations and verify all current states before making changes; do not blindly delete sale rows or assume an application transaction protects every user-deletion handler.
## 9. Follow-up targeted inactive-account cleanup (2026-11-09)

**Status: completed in production according to operator's exact execution output; final run `FINAL_STATUS=PASS`, `FINAL_RC=0`, `OPERATION_STATUS=COMPLETED`.** This is an additional cleanup after the two-account/4-order transfer documented in section 8. No customer phone numbers, full bonus records, or private backup contents are published here.

- The operator supplied a separate group of **60 Bitrix user IDs across 20 phone-number groups** for an `ACTIVE` status check. A mismatch in an early script's phone guard aborted without changes; another draft failed in PHP because numeric phone-number array keys were implicitly cast to integers. A corrected script reported 33 `ACTIVE=Y`, 27 `ACTIVE=N`, zero absent and zero phone mismatches.
- **First successful cleanup:** one inactive account with zero `UF_BONUS` and no observed sale references was deleted with `CUser::Delete()`, after a verified private snapshot. Result: `USERS_DELETED=1`.
- Read-only follow-up inspected the **26 remaining inactive accounts**: 25 had exactly `UF_BONUS=-1`; one had `UF_BONUS=571` and one sale reference. The operator explicitly chose to **retain the account with 571** and delete only the 25 inactive accounts at -1.
- **Second successful cleanup:** all exact 25 selected accounts passed checks for `ACTIVE=N`, matching expected phone fields, `UF_BONUS=-1`, no checked sale references, no admin group membership, and no conflicting phone-auth rows. A private snapshot was written and verified before the deletions; all 25 accounts were deleted with `CUser::Delete()` and independently checked absent. The account with +571 was rechecked as present with unchanged balance at the end. `USERS_DELETED=25`.
- **Aggregate for this separate 60-user group:** **26 inactive accounts deleted** (1 zero-balance + 25 balance -1), **33 active accounts preserved**, **1 inactive account with +571 and a sale relation preserved**. The two earlier successful deletions described in section 8 are separate from these 26.
- The final successful private snapshot is stored under the production site's backup directory, in a dedicated `bitrix-delete-25-minus-one.*` directory, with checksum verified by the execution script. It contains personal account data, so it must stay private. It is a targeted snapshot, not a complete database backup or automatic rollback.
- The cleanup did **not** delete orders, change bonus balances directly, or write to RESTISBONUS / SQL Server. Account-deletion hooks may have other application effects, which are not independently audited by the reported result.
- The historical `BITRIX_BRIDGE_RETRY` status-3 failures **remain open**; do not equate removal of duplicate accounts with verified resolution of all past events. **Do not rerun the deletion scripts** on completed targets.

**Checkpoint boundary:** The last operator output confirms account deletion and preservation checks, not a fresh full count of all users or a proof that every duplicate phone group is resolved.
