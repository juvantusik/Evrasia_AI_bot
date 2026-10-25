# Evrasia AI Bot — RestIS → Bitrix bonus event sync: verified checkpoint / 2026-10-25

> **READ FIRST for the BONUS EVENT SYNC workstream.** This is a dated operator-output checkpoint, **not a deployment receipt**. No production bonus synchronization was enabled. Do not confuse with the independent V5 AUTH / website MFA rollout. Companion copy/paste instruction: [EVRASIA_BONUS_EVENT_SYNC_NEW_CHAT_INSTRUCTION_2026-10-25.txt](EVRASIA_BONUS_EVENT_SYNC_NEW_CHAT_INSTRUCTION_2026-10-25.txt).

## 1. Business decision (accepted, do not reopen)

- Source of bonus truth for this pipeline: Microsoft SQL Server `EUR-CRM`, database `RESTISBONUS`, `dbo.Cards.CardTotalSum` (`FLOAT NULL`). Convert using agreed **floor** policy, write integer to Bitrix MariaDB `eurasia_new.b_uts_user.UF_BONUS`; preserve explicit negative/NULL policy review before a real write. The user explicitly accepted a one-bonus RestIS SQL/API rounding mismatch and rejected spending more time reconciling the public API.
- **Event-driven**: SQL Server `AFTER UPDATE` trigger detecting changed `CardTotalSum` → durable SQL outbox on EUR-CRM → worker on Bitrix host → read current RestIS balance → unique validated user mapping → existing guarded CAS of `UF_BONUS` → clear **only** targeted `bonus_user<ID>` cache in cache directory `bonus` → acknowledge only after success. Retry/lease/dead-letter for failures.
- Only existing registered active website customers; do not sync all cards as a full-scan production job. Historical backfill for already registered users and on-registration initial balance are separate required paths; subsequent updates alone do not establish initial state. Actual criterion proving *website-only enrollment* **not yet established**.
- **Do not modify gifts, gift cache, API V4, V5 AUTH, website MFA, PIN/TOTP/email/SMS/Trusted Devices**. Do not create synthetic customer accounts. Public domain is `evrasia.rest`; legacy Bitrix filesystem under `evrasia.spb.ru`.

## 2. Physical systems

| Role | Verified/known location |
|---|---|
| RestIS SQL Server | `EUR-CRM` (previously identified as `192.168.103.137`), SQL Server 2014, `RESTISBONUS`, `dbo.Cards`, primary key single `INT` column, existing phone lookup via `Cards.PHONE` |
| Bitrix production host | hostname `evrasia`, site `/home/site_evrasia/web/evrasia.spb.ru/public_html`, public domain `evrasia.rest` |
| Bitrix target | MariaDB `eurasia_new`, `b_user_phone_auth`, `b_user`, `b_uts_user.UF_BONUS` |
| Root staging | `/root/ev-bonus-sync-staging` and `event_sync/`; root-only lock `.cursor_guard.lock` |
| RestIS read-only configuration | `/root/ev-bonus-secrets/restis-ro.json` and `freetds.conf`, FreeTDS alias `evrasia_restisbonus`, existing principal `ev_bonus_sync_ro` |
| Future worker SQL identity | Separate least-privileged **claim/ack/retry** principal NOT provisioned as of this checkpoint; do not reuse read-only credentials for outbox writes |

Do not print credentials, full card/phone identifiers, individual balances, trigger source, or private connection strings.

## 3. Latest verified EUR-CRM source trigger review — operator output

The last SQL Server operator batch (current admin session) returned `FINAL_STATUS=PASS; FINAL_RC=0`:

```text
DATABASE_AND_TRIGGER_IDENTITY=PASS
SESSION_SYSADMIN=YES
SESSION_DB_OWNER=YES
CARDS_VIEW_DEFINITION_PERMISSION=YES
CARDS_ALTER_PERMISSION=YES
TRIGGER_DEFINITION_VISIBLE=YES
TRIGGER_SOURCE_REVIEW=AVAILABLE
USES_INSERTED=YES
USES_DELETED=YES
REFERENCES_CARDTOTALSUM=NO
HAS_ROLLBACK_MARKER=NO
HAS_RAISERROR_MARKER=NO
HAS_THROW_MARKER=NO
HAS_TRANSACTION_MARKER=NO
HAS_CARDS_SELF_UPDATE_MARKER=NO
AUTOMATED_MARKERS_ARE_HEURISTIC=YES
MANUAL_COMPATIBILITY_REVIEW_REQUIRED=YES
NEXT_STEP=REVIEW_AND_TEST_EVENT_OUTBOX
MODE=READ_ONLY
DATABASE_WRITE=NO
SQL_PERMISSIONS_CHANGED=NO
EXISTING_TRIGGERS_MODIFIED=NO
NEW_TRIGGER_INSTALLED=NO
BONUS_DATA_CHANGED=NO
CUSTOMER_DATA_PRINTED=NO
```

The active `UPDATE` trigger's **definition is accessible to the admin session**, and the simple text-marker audit found no direct references to `CardTotalSum` or obvious rollback/self-update constructs. **This is not proof of coexistence or performance safety.** Manual source compatibility review and an isolated outbox migration/trigger test remain mandatory.

Earlier read-only RestIS schema review via service account: `dbo.Cards` has **three existing triggers**: two enabled (one `UPDATE`, one `DELETE`) and one disabled (`UPDATE`); zero `INSTEAD OF`; database recursive triggers off; outbox objects absent; worker role absent. The service account could read metadata but **not definitions** for any of the three, because `VIEW DEFINITION=NO`, `IsEncrypted=NO`.

A prior **attempt** by another SQL script to `GRANT VIEW DEFINITION` to `ev_bonus_sync_ro` failed with **SQL error 15151**, `FINAL_STATUS=FAIL`; no permission change was attested. Do not assert a root cause from error number alone. The subsequent admin session with `SYSADMIN=YES` could directly read the source, so repeating this grant is not a prerequisite to the next test. **Admin privilege on the reviewed session is not evidence that the Bitrix-host worker has DDL permissions**.

## 4. Bitrix scope — real current operator counts

Most recent `b_user` grouping of active users with existing `b_user_phone_auth` mapping:

| `b_user.LID` | Distinct active phone-linked users in query |
|---|---:|
| `NULL` | 61,717 |
| `s1` (site metadata matches evrasia.rest) | 83 |
| **TOTAL** | **61,800** |

`BITRIX_LID_DIAGNOSTIC=PASS`, `FINAL_STATUS=PASS`, `FINAL_RC=0`. Therefore **never restrict to `LID='s1'`**: it would discard most legitimate mapped users. Equally, `LID=NULL` **does not prove website origin**. Prior approximate snapshots were 61,797 mappings (not a frozen total); earlier global normalization audit found zero collision keys and ~250 invalid/unusable phone rows. Before enabling writes, explicitly enforce unique phone-to-user mapping and resolve the registration-origin business requirement; ambiguous/invalid mappings must fail closed.

Earlier live mapping preview of ten real active Bitrix users (not synthetic): 10 resolved, all had `b_uts_user` rows, 8 prospective balance changes, 2 equal. `EvBonusTransactionExecutor` payload validation passed for all 10. **No actual CAS executed**.

## 5. Prepared Bitrix-side components — NOT LIVE

```text
/root/ev-bonus-sync-staging/
  cas_writer.php                       (guarded transactional compare-and-swap)
  bonus_cache_policy.php               (targeted bonus cache only)
  bonus_transaction_executor.php       (payload validation, CAS + cache)
  backfill_audit_page.php              (historical one-page read-only)
  backfill_audit_state.json            (historical audit completed: 61,773)
  event_sync/
    install_restis_outbox.sql           (v1; obsolete; DO NOT INSTALL)
    install_restis_outbox_v2.sql        (v2; prepared, NOT tested or installed)
    event_sync_manifest.json           (v1 historical)
    event_sync_v2_manifest.json         (v2 release gates OFF)
    event_worker.py                     (staged worker; selftest PASS; run OFF)
    event_bridge.php                    (staged bridge; release OFF)
    grant_trigger_definition_review.sql  (generated; NOT executed)
    revoke_trigger_definition_review.sql (generated; NOT executed)
    executor-backups/                  (verified backup of old executor)
```

Most recent staging executor fix, completed with `FINAL_STATUS=PASS; FINAL_RC=0; WRAPPER_RC=0`:

- Replaced ONLY the existing standalone `require .../.settings.php` configuration read (lines ~90–95) in `bonus_transaction_executor.php` with `\\Bitrix\\Main\\Config\\Configuration::getValue('connections')` plus `default` config lookup.
- Exact old block matched, backup created and compared, patched staging PHP lint PASS. PDO tested using loaded Bitrix config: `BITRIX_D7_CONNECTION=PASS`, `INDEPENDENT_PDO_CONNECTION=PASS`, `STAGING_CONFIG_PDO_TEST=PASS`.
- `EvBonusCasWriter::writesEnabled()=false`, `EvBonusCachePolicy::clearEnabled()=false`, `EvBonusTransactionExecutor::enabled()=false`, all reconfirmed. CAS/cache source hashes unchanged. **This proves connection + lint, not actual CAS write correctness or complete worker integration**.
- This patched PHP **exists on the private staging server only**, **has NOT been committed to GitHub** by the handoff docs action, and is not deployed as live Bitrix PHP.

`event_worker.py` offline `--selftest` PASS; v2 outbox migration and DB routines were **not deployed or exercised**. `event_bridge.php` release disabled. `checkpoint.json` (live activation checkpoint) absent in earlier audit. No cron/systemd worker enabled.

Earlier source-to-Bitrix read-only audit counted 22,838 potentially differing user balances across ~61,773; it is only a historical *candidate count*, not a migration or write receipt. No reconciliation was performed.

## 6. Remaining release blockers (minimal, no unnecessary hardening scope)

1. **Review actual SQL source of enabled `UPDATE` trigger** through the already-confirmed admin session, inspect behavior/side effects beyond keyword markers; preserve existing triggers unchanged.
2. **Review and test v2 SQL in an isolated SQL Server 2014-compatible test/scratch database** before installation against production `RESTISBONUS`. Test `INSERTED`/`DELETED` multi-row updates, NULL ↔ non-NULL, float changes and no-change updates, rollback atomicity, queue safety and performance, existing trigger coexistence, claim/lease/ack/retry, idempotency/duplicate events, default trigger recursion behavior. Do not fabricate customer users.
3. Confirm **stable source identity** and how phone updates or multiple cards per phone affect events. Review staged v2 outbox key and claim SQL for semantic/concurrency flaws; fail closed rather than silently updating wrong Bitrix account.
4. Provision **separate minimum-privilege SQL worker principal** to execute queue stored procedures only. Existing `ev_bonus_sync_ro` stays read-only for fetching current source balance. Avoid printing or committing secrets. Admin changes require explicit guarded release commands and rollback.
5. Verify executor/bridge input contract and real database transaction handling. Run **controlled bounded real existing-user CAS** only after rollback-ready backups and user authorization; do not call PDO success a CAS PASS. Check targeted `bonus` cache only, preserve gifts.
6. Establish registered-site-user eligibility distinct from `LID`, including new registration initial-fill, historical one-time backfill and invalid/unmatched phones.
7. Deploy queue on EUR-CRM only after isolated compatibility PASS and a reviewed rollback. Enable worker and write gates **separately**, verify actual end-to-end delivery, retries, source transaction health and site cache; no blind global rollout.

**Next single concrete step:** under authorized SQL admin on EUR-CRM, complete manual compatible behavior review of the already visible active `UPDATE` trigger; then test the existing `event_sync/install_restis_outbox_v2.sql` in an isolated SQL database or refine it before live installation. Do not repeat the now-closed `LID` or PDO connection investigations.

## 7. Release invariants / precise labels

```text
SQL_TRIGGER_INSTALLED=NO
RESTIS_OUTBOX_CREATED=NO
RESTIS_WORKER_ROLE_EXISTS=NO  (earlier read-only snapshot)
EVENT_WORKER_ENABLED=NO
BITRIX_CAS_EXECUTED=NO
BONUS_CACHE_CLEARED=NO
STAGING_EXECUTOR_PDO=PASS
THREE_WRITE_GATES=DISABLED
GIFTS_CHANGED=NO
API_V4_CHANGED=NO
API_V5_AUTH_ENABLED_BY_BONUS_SYNC=NO
PRODUCTION_READINESS=DENIED
```

Do not change these labels to PASS without a new operator receipt. Always distinguish **prepared / offline test / real database test / installed / enabled / production verified**.

## 8. Work conventions and repo status

- Repository: `juvantusik/Evrasia_AI_bot`. Feature working context: `feature/samzaberu-api-v5-auth-contract`; checked HEAD at this documentation handoff: `6fa8773746e9a40e22008d67636c896eefeec3ed`. Default `main` was `7c2e9f8e4501b92469bcdeea18f8340676ad52c9` (older unrelated context). This document is created on **`docs/bonus-event-sync-checkpoint-20261025`**, based on that feature HEAD; do not imply docs branch is deployed or merged.
- A docs-only commit cannot certify staging source SHA, active RestIS trigger compatibility or site production installation. Re-inspect actual current HEAD and live artifacts before any write.
- Operator commands: **ONE single pasteable Bash wrapper** with `bash -n`, host/root guards, exclusive lock, explicit mode, mandatory backups before writes, verification, cleanup, `=== 1...5 ===`, `PASS/FAIL`, `FINAL_STATUS`, `FINAL_RC`, `WRAPPER_RC`. For SQL administrator commands use exact database and object guards, no secrets, clearly state any write.
- Follow-up new chat first reads this handoff + sibling .txt instruction, then current GitHub branch, then the private staging manifest. Do not ask user to restate the history or redo passed diagnostics.

**Change rationale — Было → Стало → Причина:** direct `.settings.php` require in private staging executor → native Bitrix `Configuration::getValue('connections')` → repeated source include failed although D7 connection was already established. SQL source trigger was previously inaccessible to read-only worker → source visible to admin session without granting worker privileges → inspect compatibility using admin and keep worker least privilege.


## 9. Verified follow-up from BonusBalance 2 — 2026-10-25

These findings are from operator-supplied outputs; they **do not authorize production enablement**:

- Bitrix-host source-only precheck: original V2 SQL/worker/bridge/executor/manifest all present and syntax/manifest checked. Existing component hashes recorded. V2 outbox DDL: `dbo.ev_bitrix_bonus_events`, trigger `dbo.tr_ev_bitrix_bonus_changes`, procedures `dbo.ev_bonus_claim`, `dbo.ev_bonus_ack`, `dbo.ev_bonus_retry`.
- Initial SQL Server 2014 `tempdb` isolated trigger test: multirow UPDATE, NULL transitions, unchanged values, rollback atomicity, `DATE_CORR` coexistence model and combined phone+balance updates PASS. Confirmed a real V2 **bug**: expired leases could claim an event after its tenth attempt (`ATTEMPT_CAP_BYPASS_REPRODUCED=YES`).
- Staging-only fix: in `ev_bonus_claim`, first mark eligible expired/exhausted events status=3 in batches up to 100 with `LEASE_ATTEMPTS_EXHAUSTED`, then restrict claim to `Attempts < 10`. Original staging SQL SHA-256 `e724f28c7e77833e7a84014af58a73dd9055bfbe3bb9b45de4f2536900f9b34d`; patched staging SQL SHA-256 **`97f7e01f41be0975bf9970d973ce4b67fa52812708f97c37319eb494408cd0f8`**. Backup created and verified. No live SQL changes.
- Patched logic in isolated SQL Server 2014: normal claim, active lease preservation, 10th permitted/11th blocked, terminal expired state and 100+5 batch transition PASS.
- Exact V2 DDL and procedure bodies extracted from this patched staging SQL, with only names redirected to `tempdb` lab objects, executed and rolled back on SQL Server 2014: table/index/trigger/claim/ack/retry compiled; multirow, unchanged-value, NULL, transaction rollback, claim/ack/retry, 10th attempt and 11th-block checks PASS. `ACTUAL_V2_DEFINITIONS_TESTED=YES` is **isolated/test-object verification only**.
- Two concurrent real SQL sessions through existing RestIS non-admin connection with isolated `tempdb` global temporary queue and claim body extracted from patched V2: one event to two workers claimed once, two events to two workers distributed distinctly, no duplicate detected. Temp object dropped. This is a bounded concurrency check, **not a load test**.
- Offline actual Python worker function tests with mocked external calls: positive/negative `floor`, invalid balance rejection, signed JSON payload, bridge stdout/ACK contract, empty queue, successful ACK, unsuccessful/unknown/error retry PASS. `OFFLINE_WORKER_FUNCTIONAL_TEST=PASS`; PHP process, actual source DB reads, Bitrix CAS and cache clear **not executed**.
- Worker `event_worker.py` SHA-256 still `290a1c04a5cb2bc69fd082ee67faeac42a60dac72ef75b5efc09dbd32302d536`; PHP bridge `d436cc830f1b1cf0af690e9682d95fafc052c6b087da0fbb6b946173fa974fcf`; executor `357f47bc3a1c2b0295c2bcc0689a54c06126c8828054fbaa164f08a8a878483d`; V2 manifest `445f6538e3687f28cebdbcab0a475225e78392bde11ed6b01b189b5ffe222370`.

**Next**: examine the PHP bridge → CAS executor → user mapping → targeted bonus cache implementation and status semantics; test bounded actual integration only with separate authorization and rollback-ready controls. Validate phone-change / multi-card semantics and retry-vs-ACK mapping. Before live RestIS installation, prepare least-privilege SQL worker role, backup, rollback and separate enable gates. Do not modify V4/V5 or gifts.

Current state: `SQL_MIGRATION_APPLIED=NO`; `NEW_TRIGGER_INSTALLED=NO`; `EVENT_WORKER_ENABLED=NO`; `CAS_EXECUTED=NO`; `BONUS_CACHE_CLEARED=NO`; `DATABASE_WRITE=NO` in these tests; `PRODUCTION_READINESS=DENIED`. Staging SQL and worker implementation **have not been committed to GitHub** by this docs-only checkpoint.
