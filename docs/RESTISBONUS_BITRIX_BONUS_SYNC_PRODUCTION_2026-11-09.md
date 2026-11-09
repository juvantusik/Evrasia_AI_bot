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

**Operational boundary (historical checkpoint):** On initial 2026-11-09 bonus-outbox diagnostics the separate MariaDB 11.4 upgrade was paused and no DB changes were performed *by that diagnostics checkpoint*. **Superseded later on the same date:** production MariaDB in-place upgrade was explicitly requested and completed independently (see section 12). The SQL Server RESTISBONUS outbox/worker and application API V4/V5 were not changed by the database-package upgrade.

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

## 10. Targeted consolidation of three active Bitrix accounts (2026-11-09)

**Production result confirmed by the operator:** `FINAL_STATUS=PASS`, `FINAL_RC=0`, `OPERATION_STATUS=COMPLETED`. A separately selected group of three **active** Bitrix accounts shared a phone-number fallback mapping. The operator designated one account to retain and two to delete. Phone number, exact customer user IDs, and backup contents are intentionally omitted from this repository.

- Initial preflight verified the exact phone-owner set, all three `ACTIVE=Y`, and zero/NULL `UF_BONUS` for the two source accounts. Each source had exactly **one live `b_sale_order` order**. The first attempt halted **before writes** because `b_sale_buyer_stat` had user references; this is derived buyer statistics, not an additional order.
- Revised guarded procedure identified **2 old buyer-stat rows** across the two source users, **1 site/currency group** to recalculate, and checked **13 other sale tables** having direct `USER_ID` fields. No other blocking sale references were found.
- Verified private snapshot was written before changes (customer data retained only under restricted production backup storage). Then two source orders were re-owned by the retained user using an exact-row, transactional update to `b_sale_order.USER_ID`; `Bitrix\\Sale\\BuyerStatistic::calculate()` recalculated target statistics for the verified site/currency group, and obsolete source buyer-stat rows were cleared in the same transaction.
- After committing the order/statistic changes, `CUser::Delete()` successfully deleted **both** source accounts; final checks verified both source users absent, retained user present and active, **both transferred orders preserved**, and retained `UF_BONUS` unchanged. Results: `ORDERS_MOVED=2`, `USERS_DELETED=2`, `ORDERS_DELETED=0`.
- No changes to API V4/V5, SQL Server RESTISBONUS, or direct bonus balances. This cleanup does not independently explain or resolve historical `BITRIX_BRIDGE_RETRY` events. **Do not re-run completed deletion/consolidation.**

**Implementation note:** Since production Bitrix maintains derived `b_sale_buyer_stat` records, future account consolidation must inspect those references instead of assuming an order-only user transfer is sufficient. Keep all other sale tables guarded and verify production code/API before reuse.

## 11. Consolidation preserving historic order-change audit (2026-11-09)

**Confirmed operator result:** `FINAL_STATUS=PASS`, `FINAL_RC=0`, `OPERATION_STATUS=COMPLETED`. This separate customer-account consolidation is complete on production. Omit personally identifying phone/account numbers and private backup contents from this public note.

- One of the two proposed source Bitrix users was already absent; preflight verified it had no current orders. The remaining source account was `ACTIVE=N`, with `UF_BONUS=571` and **one** live `b_sale_order` order. The designated retained account was `ACTIVE=Y`, with `UF_BONUS=1158` and zero current orders.
- Initial attempt stopped with `USER_STATE_OR_PHONE` when it incorrectly required the absent account to exist. Follow-up read-only checks established that account's absence. A second guarded attempt stopped before changes because `b_sale_order_change.USER_ID` had **20** references to the source; follow-up schema/reference audit verified they were historical order-change audit entries, **not 20 additional orders**.
- The successful operation explicitly **preserved all 20 audit entries unchanged** (pre/post full-row SHA comparison) and guarded all other direct sale `USER_ID` references: **12** other sale tables inspected, none blocking. Only one source live order and one stale source `b_sale_buyer_stat` record needed handling.
- Before any data write, a private targeted snapshot of source/destination account rows, profile fields, groups, phone-auth data, current orders, buyer statistics, and all 20 audit entries was written and verified. The backup is inside the private production website backup directory; it is not a full DB backup.
- In a transactional update, the one source order's `b_sale_order.USER_ID` was reassigned to the destination, target buyer statistics recalculated with `Bitrix\\Sale\\BuyerStatistic::calculate()`, and obsolete source `b_sale_buyer_stat` row cleared. Order-change audit rows were not rewritten or deleted.
- `CUser::Delete()` deleted the single remaining source account; final checks verified its absence, the other source's continued absence, the retained account active, the transferred order present under the retained user, the audit entries unchanged, and the retained `UF_BONUS=1158` unchanged. `ORDERS_MOVED=1`, `USERS_DELETED_THIS_RUN=1`, `ORDERS_DELETED=0`.
- **No bonus transfer**: the deleted account's 571 Bitrix bonus value was captured in the private snapshot, not credited to the retained account. No direct RESTISBONUS / SQL Server writes were made. Do not assume the source's SQL Server state was cleared or that the historical `BITRIX_BRIDGE_RETRY` investigation is resolved.
- **Do not rerun** the completed script. For future consolidations, distinguish `b_sale_order_change.USER_ID` audit actors from mutable sale-order owners, and keep audit rows immutable while preserving/restating buyer statistics.

## 12. Production MariaDB in-place upgrade completed (2026-11-09)

**Status: completed in production, operator output `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`.** This was an explicitly authorized separate infrastructure operation on website server `evrasia` (Debian 12), **not** on an isolated test instance and not on `eur-bot-01`. Database `eurasia_new` remains connected to Bitrix.

- **Before:** MariaDB `11.4.5-MariaDB-deb12` / package `1:11.4.5+maria~deb12`. Legacy system schema: `mysql.user` MyISAM **BASE TABLE**, `mysql.global_priv` absent; `mysql.proc` and `mysql.event` MyISAM. The old grant-table structure caused earlier MySQL system-schema dump failures.
- **Target and result:** MariaDB `11.4.13-MariaDB-deb12` / `1:11.4.13+maria~deb12`, verified after package upgrade and service restart. APT dry-run proposed **11 package changes, 0 removals**; final package upgrade completed. `mariadb-upgrade --upgrade-system-tables --force` completed after fixing blockers. `mysql.user` is now a **VIEW**, `mysql.global_priv` a real **Aria** table; `SELECT COUNT(*) FROM mysql.user` succeeds. All **8 original grant accounts** still present after restart (9 total entries after creation of `mariadb.sys`).
- **Obstacles and fixes:** First system-table conversion created `mysql.global_priv` and the compatibility view but failed with SQL error **1102** (`Incorrect database name`), because hidden backup directory `/var/lib/mysql/.evrasia-mariadb-backups` was inside the MariaDB `datadir`. At that intermediate checkpoint the new `mysql.user` view failed with SQL **1449** until `FLUSH PRIVILEGES` was executed, making the new `mariadb.sys` definer usable. Source/destination backup directories were on **different filesystems**, so an atomic rename was rejected. The backup tree was copied to an external volume, compared byte-for-byte (`diff -r -q`), verified and only then the old directory was deleted. The system-table upgrade and 11.4.13 package upgrade then succeeded.
- **Backup status:** Operator confirmed an existing VM backup containing the site and DB; its restore has **not** been independently tested here. Existing application logical backup archive `application-databases.sql.gz` was present and size-checked (**4,582,380,749 bytes**). Entire backup directory copied and verified before removal of the original location. **Current archive directory**: `/var/lib/evrasia-mariadb-backups` (outside `datadir`). Historical application and system backups retained under private website backup storage. This is not proof of full VM rollback readiness.
- **Production postchecks:** service active after restart, exact server/package versions, admin DB access, compatibility view query, original account-set preservation, application DB `eurasia_new` query, and **fresh PHP/Bitrix database connection** all PASS. No direct writes to external `RESTISBONUS` SQL Server or bonus sync outbox and no application source-code or API V4/V5 changes.
- **Backup path reference review completed (operator read-only check):** inspected **77** files across selected cron, systemd, and maintenance-script locations and root crontab: `BACKUP_PATH_REFERENCE_FILES=0`, `OLD_PATH_REFERENCES=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. No old hard-coded backup path found **within the checked scope**, and no configuration changes were needed. This does not prove that a future scheduled backup has been executed or restore-tested. Do not recreate the old backup directory *inside* MariaDB `datadir`, as it can reintroduce error 1102 in future upgrade runs.
- **Do not rerun** the completed upgrade scripts or downgrade packages. Distinguish this successful database-engine upgrade from unresolved bonus outbox `BITRIX_BRIDGE_RETRY` issues.

**Production operator final status:** `SYSTEM_TABLE_UPGRADE_COMPLETE=YES`; `PACKAGE_INSTALL_COMPLETE=YES`; `SERVER_VERSION=11.4.13-MariaDB-deb12`; `ORIGINAL_ACCOUNTS_MISSING_AFTER_RESTART=0`; `BACKUP_VERIFIED_COPY=YES`; `BACKUPS_RELOCATED=YES` (reported as `ORIGINAL_BACKUP_DIR_REMOVED=YES` in the final script).

## 13. Production MariaDB 11.4.13 to 11.8.9 LTS upgrade completed (2026-11-09)

**Confirmed final operator result:** `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`; production host `evrasia`, Debian 12, website database `eurasia_new`. This is **newer than section 12**; current production MariaDB is **`11.8.9-MariaDB-deb12`** (formerly `11.4.13-MariaDB-deb12`).

- **Read-only compatibility preflight:** `innodb_snapshot_isolation=0`, `tx_isolation=REPEATABLE-READ`, zero obsolete `wsrep_load_data_splitting` options found, zero explicit system-versioned tables found. The MariaDB repository was exclusively configured in `/etc/apt/sources.list.d/mariadb.sources` for 11.4. A fresh in-place 11.8 migration was explicitly authorized, **not** an isolated test upgrade.
- **New backup before cutover:** verified compressed `--all-databases` logical dump with routines/events/triggers (gzip integrity and SHA256): `/var/lib/evrasia-mariadb-backups/pre-118-20261109.6mwQ6qRd/all-databases.sql.gz`, **4,591,991,088 bytes**. `--single-transaction` gives an InnoDB-consistent snapshot, **not a transactional guarantee for MyISAM/other nontransactional tables**. Archive integrity was checked, but a complete restore was **not** tested. Prior VM snapshot/backup availability was reported by the operator; recoverability is unverified here.
- **Repository/packages:** switched MariaDB APT source to 11.8; refreshed indices. Target package `1:11.8.9+maria~deb12` was present; APT dry-run proposed **10 changes, 0 removals**. Preserved legacy-compatible transaction behavior by deploying `/etc/mysql/mariadb.conf.d/99-evrasia-11-8-compat.cnf` with `innodb_snapshot_isolation = OFF`.
- **Install:** MariaDB 11.4.13 cleanly stopped; target packages installed; MariaDB started successfully as **`11.8.9-MariaDB-deb12`**. Verified `innodb_snapshot_isolation=OFF`. Subsequently ran `mariadb-upgrade`, completed successfully.
- **Postchecks:** `mysql.user` query succeeded, `eurasia_new.b_user` query succeeded, **zero original MariaDB grant accounts missing**, fresh PHP/Bitrix DB connection PASS; final flags `PACKAGE_INSTALL_COMPLETED=YES`, `MARIADB_UPGRADE_COMPLETED=YES`, `BITRIX_NEW_CONNECTION=YES`. Work/log directory `/home/site_evrasia/web/evrasia.spb.ru/backups/mariadb-118-prod.yn2rpacz`.
- **Scope/limits:** no application source files, API V4/V5, or external SQL Server RESTISBONUS data were intentionally modified in this operation. A fresh PHP DB connection proves DB access but **is not a full HTTP, order, payment, or bonus synchronization smoke test**. The MariaDB package upgrade is complete; don't re-run the migration script or attempt an in-place downgrade. The existing bonus `BITRIX_BRIDGE_RETRY` investigation remains open.

**Current state / continuation:** production MariaDB **11.8.9**, 11.8 APT repository, `innodb_snapshot_isolation=OFF`, fresh gzip/SHA256-verified logical backup outside MariaDB datadir. If further verification is needed, prefer lightweight read-only website/API health checks without creating orders or writing customer/bonus data.

## 14. Three-account merge into formatted-phone Bitrix account (2026-11-09)

**Production operator result: complete**, `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`. A distinct group of **three** active customer accounts associated with the same phone was consolidated into **one existing** account, specifically the one whose login already used the `+7 (XXX) ...` formatted-phone pattern. Customer phone number, email addresses, exact customer IDs and personal backup data are intentionally omitted from this repository.

- **Before:** retained account 3 sale orders; duplicate source accounts 2 and 3 sale orders (5 total to transfer); one `b_sale_buyer_stat` row per account (3 total). All three `UF_BONUS` fields empty. Read-only scan of 15 `b_sale_*` and 5 `ev_v5_*` tables having a `USER_ID` field found **no other direct source-user references**, including no `b_sale_order_change.USER_ID` rows for these sources.
- **First attempt failed safely:** initial protected transaction stopped at `ORDERS_CHANGED_BEFORE_WRITE`; `TRANSACTION_ROLLBACK=YES`, `TRANSFER_COMMITTED=NO`. This resulted from strict PHP comparison of re-fetched Bitrix database rows including converted date objects, not evidence of order mutation.
- **Minimal fix:** in the private operational script, `fetchRows()` was changed from `$result->fetch()` to `$result->fetchRaw()` so that order-content comparisons use raw database column values. No business logic or website/API files were modified. Same identities, ownership counts and source references verified again.
- **Verified restricted private snapshot:** fresh targeted user, group, user-field, order, buyer-statistics and order-change data backed up and SHA256-checked under `/home/site_evrasia/web/evrasia.spb.ru/backups/three-user-merge-fixed.U7RAe76N`.
- **Successful run:** exactly **5** source order rows reassigned to retained existing account inside the guarded transaction. Order contents except ownership were checked unchanged, source buyer-stat rows removed, retained buyer statistics recalculated through `Bitrix\\Sale\\BuyerStatistic::calculate()`. Transaction committed; `CUser::Delete()` then removed the two redundant source accounts.
- **Independent postcheck:** retained account active, both source accounts absent, retained account owns all **8** orders, source users have no remaining `b_sale_order` or `b_sale_buyer_stat` references; `ORDERS_DELETED=0`. `RESTISBONUS_WRITE=NO`; no API V4/V5 code changes. This does not by itself prove that external SQL Server outbox errors were resolved.
- **Do not rerun the merge.** If further bonus issues are reported, use the separate read-only SQL Server outbox monitoring workflow and compare against earlier checkpoint counts, without replaying exhausted events.

## 15. RESTISBONUS read-only outbox rescan after account maintenance (2026-11-09)

**Operator-confirmed SSMS query, server `EUR-CRM`, SQL Server `12.0.2569.0` (SQL Server 2014), DB `RESTISBONUS`, server UTC `2026-11-09 10:27:48.610`.** This was read-only; no event status update, replay or new SQL permissions.

- Latest totals: `ProcessingStatus=2` ACKNOWLEDGED **218341** (**+156** vs previous snapshot); `ProcessingStatus=3` EXHAUSTED **1087** (**+9**); zero pending or in-progress rows (neither status 0 nor 1 returned).
- Exhausted error codes: `BITRIX_BRIDGE_RETRY=1064` (**+9**), `SOURCE_CARD_NOT_UNIQUE=21` (unchanged), `SOURCE_CARD_STATUS=2` (unchanged).
- Recent events by **creation time**, not processing/failure time: 1 h **105 total / 105 acknowledged / 0 exhausted**; 6 h **156 / 156 / 0**; 24 h **8854 / 8814 acknowledged / 40 exhausted** (99.55% acknowledged). Latest **100 events by EventID: 100 acknowledged, zero pending/failed**.
- **Reconciliation:** prior checkpoint had 9 pending; new snapshot zero pending and 9 more exhausted, while acknowledged increased by exactly 156. This strongly suggests the nine previously pending events exhausted their retries; **not proven without a matching earlier EventID-level record**. Do not misinterpret 40 rows created during last 24 h as 40 new failures during the last 24 h.
- The `ev-bonus-sync.timer` was active; `ev-bonus-sync.service` showed `Result=success` and `ExecMainStatus=0`. This is supporting service evidence, **not proof that all events succeeded**.
- **Two web-server read-only Python probes failed** to select `dbo.ev_bitrix_bonus_events`: they used `connect_source()` under the read-only SQL Server identity intended for card data, which lacked outbox table access. First returned no visible sys.columns; next got `OperationalError` on `SELECT TOP(0)`. This is a diagnostic-permission mismatch, not confirmed database corruption. Subsequent read-only **SSMS** query on `EUR-CRM` succeeded. Do not expand SQL permissions to make the diagnostic work without explicit approval.
- **Next investigatory step:** determine why previously pending nine rows ended with `BITRIX_BRIDGE_RETRY`. Bridge returns only a generic status in the SQL outbox, so actual PHP errors require the separate carefully bounded symbolic trace mechanism. Existing `bridge-timings.log` hit its confirmed 128 KiB logging cutoff; do not replay exhausted events or reset their statuses as a shortcut. Do not alter MariaDB, SQL Server, worker, or replay policies based on these aggregate counts alone.

## 16. Private one-exception diagnostic trace enabled (2026-11-09)

**Operator-confirmed production change: `FINAL_STATUS=PASS`, `FINAL_RC=0`, `WRAPPER_RC=0`, `TRACE_CONFIG_VERIFIED=YES`.** This updates the older section-6/section-15 statement that `EV_BONUS_TRACE_FILE` was not configured.

- Exact production runner, worker and PHP Bridge source SHA checks all passed; source files were not changed. `ev-bonus-sync.timer` remained active; no manual event replay, SQL Server/Bitrix database write, service restart or application API edit.
- Added systemd drop-in `/etc/systemd/system/ev-bonus-sync.service.d/90-ev-bonus-error-trace.conf`, setting `EV_BONUS_TRACE_FILE=/root/ev-bonus-sync-trace/last-exception.log`. Executed `systemctl daemon-reload`, verified effective systemd environment. Directory permissions root/0700, file root/0600. Worker PHP subprocess does not override `env` and inherits the unit environment. The runner/worker/bridge source hashes were unchanged.
- PHP Bridge catches `Throwable` and writes class, filtered symbolic reason, PHP source basename and line number **on exception only**. The trace write uses `LOCK_EX` **without `FILE_APPEND`**, so this is a **single last-exception snapshot** (each subsequent exception overwrites it), not an accumulating log. Before enabling, the operator-side safety preflight checked code contract and that an explicit set of test phone/email/password/free-text examples did not match the reason filter. Do not assume this substitutes for complete privacy review of all possible exception class/filename paths.
- The old `bridge-timings.log` 128 KiB cutoff remains unchanged; the new `last-exception.log` is independent and should not be confused with it.
- **Pending:** read the trace through a **sanitizing read-only script**, classify exception or determine file still empty. A blank trace does **not** prove no `BITRIX_BRIDGE_RETRY` happened: Bridge can return `RETRY` without throwing. Historical exhausted events were not replayed, and their original exceptions cannot be reconstructed from generic SQL error codes alone.
