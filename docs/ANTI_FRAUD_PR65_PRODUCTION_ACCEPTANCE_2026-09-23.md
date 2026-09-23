# Anti-Fraud PR #65 — Production Acceptance 2026-09-23

> **Authoritative acceptance record for PR #65, PR #67 UI completion, legacy snapshot backfill, and the accompanying website targeted Check-in dedup fix.**
>
> Source priority: **actual production → current GitHub → staging/test → current docs → older discussion**.

## 1. Final status

PR #65 **Anti-Fraud: separate operator physical history from risk telemetry** and PR #67 **Anti-Fraud: show PR65 physical history in UI** are:

**MERGED / DEPLOYED / PRODUCTION / VERIFIED / VISUALLY ACCEPTED**

GitHub / CI:

- merge commit / production revision: `d1746ceabb513727baad729adbd3333328fc2dab`
- post-merge workflow: `35829487405`
- workflow result: **SUCCESS**
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:ca788e0dcc62fbcc4a810c79866a684f2160d062c2486e4c7577c520374c72b8`
- image config ID: `sha256:34e3c50395b0a34a3b8efe044fc1ad6e7b90771824449a38354babaefdea451c`
- platform: `linux/amd64`

Production runtime:

- host: `eur-bot-01`
- app container: `evrasia-ai-bot-app`
- DB container: `evrasia-ai-bot-db`
- DB / role: `evrasia_ai_bot`
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- accepted Compose SHA256 after cutover: `bdcba0082691165ce17c6eca05a28f8bdab42b86ef3edbc9a2fbb5181d0ce097`
- production migrations: **26**
- migration `0025_anti_fraud_operator_physical_history`: applied and schema-verified
- app restart count at acceptance: **0**
- DB container was not recreated
- rollback: **not required**

Deployment backup:

- directory: `/opt/evrasia-ai-bot/backups/pr65-operator-physical-history-20260923-102119`
- DB dump: `/opt/evrasia-ai-bot/backups/pr65-operator-physical-history-20260923-102119/evrasia_ai_bot.before-pr65.dump`
- DB dump SHA256: `22643ef67f2d016886aa87c53c18cfcdc3c113ee502b2d76272255086747403f`
- DB dump was verified with `pg_restore -l`

Deployment result:

**61 PASS / 0 FAIL / 0 WARN**

## 2. Why PR #65 was required

PR #62 made manual-investigation history visible in the Anti-Fraud UI, but the UI still calculated operator-visible physical history from `anti_fraud_visits` with `loyalty_verified IS TRUE`.

That source is not the authoritative physical Check-in source for manual investigations and it is also part of automatic risk telemetry.

For multi-card accounts this caused the operator UI to show zero or incomplete physical history even when the targeted protected Check-in endpoint had real physical events.

PR #65 separates these concepts.

## 3. Accepted architecture

### Operator investigation physical history

A manual investigation now calls the existing targeted protected Check-in gateway for the requested USER_ID and 60-day window.

The result is stored in the dedicated table:

`anti_fraud_operator_investigation_visits`

The table is scoped by `investigation_id` and stores:

- `investigation_id`
- `physical_event_id`
- `bitrix_user_id`
- `occurred_at`
- `restaurant`
- `created_at`

Primary identity:

`(investigation_id, physical_event_id)`

The parent `anti_fraud_operator_investigations` table also stores:

- `physical_history_from`
- `physical_history_until`

A successful empty targeted history remains a valid completed window.

A targeted response with unresolved cards fails closed and must not be marked as a complete physical-history snapshot.

### Worker sequence

Accepted operator-investigation flow:

`USER_ID → account-map → loyalty/balance enrichment → targeted Check-in 60d → operator physical snapshot → history_ready → normal explainable scoring → ready`

`history_completed_at` is set only after the targeted physical snapshot succeeds.

### UI source

Operator-visible visit/day/restaurant metrics now read only from the latest investigation's dedicated snapshot.

They no longer infer operator physical history from `anti_fraud_visits`.

### Risk telemetry invariant

`anti_fraud_visits` remains the existing automatic risk/history telemetry table.

**Operator targeted physical snapshot events must not be written into `anti_fraud_visits`.**

This was verified in production by exact event-ID intersection, not by a weak total-row-count comparison.

No PR #65 change introduced automatic blocking, grouping-rule changes or a new risk rule.

## 4. Website targeted Check-in dedup fix

The protected site endpoint had a separate multi-card duplication issue.

Production file:

`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/lib/Services/AntiFraudCheckinScoutService.php`

Old accepted SHA:

`fd497e84b1ddb3afc16e497395215576dd38feb9d5e73132b4f9278b48f16e9b`

Current accepted SHA:

`5f65703d91ee31a9d829cd64cefd011309c8a6c44d3fa96d2d8c77a1f81541e9`

Backup:

`/home/site_evrasia/web/evrasia.spb.ru/backups/anti-fraud-targeted-dedup-20260923-095449`

Root cause:

the response event fingerprint included the Bitrix `cardId`. One physical RestIS event recorded under two Bitrix card elements therefore became two different `source_restis_id` values.

Accepted targeted-only change:

- ordinary Scout identity remains unchanged
- targeted 60-day mode excludes `cardId` from event identity
- raw RestIS ID remains inside the opaque hash input and is not exposed

Exact semantic change:

`$cardId` → `$targeted ? null : $cardId`

For USER_ID `6645`:

- raw `hl_orders` rows in the 60-day query: **17**
- original Scout-style events: **13**
- canonical targeted physical events: **10**
- unique physical RestIS events: **10**
- unresolved cards: **0**

Local site verification:

**17 PASS / 0 FAIL**

External production boundary verification from `eur-bot-01`:

- HTTP 200
- protected endpoint records: **10**
- gateway parsed records: **10**
- gateway unique source IDs: **10**
- unresolved cards: **0**
- classification: `TARGETED_DEDUP_CONFIRMED_END_TO_END`

## 5. Production acceptance — USER_ID 6645

Investigation:

`bd2efcea-1f28-49c7-9f40-3fdf0e271c43`

Persisted state:

- status: `ready`
- history completed: yes
- physical window from: yes
- physical window until: yes
- scoring completed: yes
- investigation completed: yes

Physical source / snapshot:

- live targeted Check-in events: **10**
- snapshot rows: **10**
- distinct snapshot physical event IDs: **10**
- wrong-USER_ID snapshot rows: **0**
- live source event-set SHA256 and snapshot event-set SHA256 matched exactly
- snapshot ↔ `anti_fraud_visits` physical-event intersection: **0**

UI-facing API:

- exact USER_ID match: **1**
- operator status: `ready`
- physical visits: **10**
- visit days: **9**
- restaurants: **4**
- physical window present: yes
- history completion timestamp present: yes
- investigation completion timestamp present: yes

Result:

**USER_ID 6645 ACCEPTANCE PASS**

## 6. Production acceptance — USER_ID 408974

Investigation:

`24834997-57ee-4a34-a82d-2f40d06e5d33`

Persisted state:

- status: `ready`
- history completed: yes
- physical window from: yes
- physical window until: yes
- scoring completed: yes
- investigation completed: yes

Physical source / snapshot:

- live targeted Check-in events: **8**
- snapshot rows: **8**
- distinct snapshot physical event IDs: **8**
- wrong-USER_ID snapshot rows: **0**
- pre/post live source event set was stable
- snapshot event-set SHA256 matched the targeted source exactly
- snapshot ↔ `anti_fraud_visits` physical-event intersection: **0**

UI-facing API:

- exact USER_ID match: **1**
- operator status: `ready`
- physical visits: **8**
- visit days: **7**
- restaurants: **7**
- physical window present: yes
- history completion timestamp present: yes
- investigation completion timestamp present: yes

Result:

**USER_ID 408974 ACCEPTANCE PASS**

## 7. Final acceptance result

Combined PR #65 operator-history acceptance:

**31 PASS / 0 FAIL / 0 WARN**

Final runtime remained:

- image config ID: `sha256:34e3c50395b0a34a3b8efe044fc1ad6e7b90771824449a38354babaefdea451c`
- revision: `d1746ceabb513727baad729adbd3333328fc2dab`
- migrations: **26**
- restart count: **0**
- health: PASS

No Bitrix user-state write was performed.
No auto-block was performed.
No manual full Anti-Fraud refresh was triggered by the acceptance scripts.
Raw phone values, card numbers, RestIS IDs and secret values were not printed.

## 8. Acceptance harness note

The first acceptance continuation for USER_ID `6645` reported a false script failure after the investigation had actually completed successfully.

The persisted row was already:

`ready|1|1|1|1|1`

Root cause was the diagnostic harness: polling messages were written to stdout and became part of command-substitution output, so the parser consumed the first polling line instead of the final state row.

Production was not at fault, no rollback was required, and the corrected diagnostic sent polling output to stderr.

Do not treat that harness failure as an application failure.

## 9. Current continuation point

The PR #65 engineering work is complete and production-accepted.

Do not:

- redo the site-side multi-card dedup investigation
- move operator physical snapshot rows into `anti_fraud_visits`
- restart PR #65 deployment or migration
- repeat USER_ID 6645 / 408974 acceptance merely for reassurance

A manual browser visual spot-check of those cards may be performed if desired, but it is not an unresolved backend/API correctness issue.

Any future manual-investigation change must preserve the separation:

`operator physical snapshot ≠ automatic risk telemetry`.


## 10. PR #67 — visible UI completion

After PR #65 backend acceptance, browser inspection proved that the React UI still hid valid physical-history snapshots when legacy `loyaltyHistoryLoadedAt` was absent.

Root cause:

`historyCovered` incorrectly required both the new PR #65 physical snapshot fields and the old loyalty-history coverage marker.

Accepted PR #67 change:

- remove `loyaltyHistoryLoadedAt` from the physical-history display gate;
- keep the authoritative gate on:
  - `operatorInvestigationHistoryCompletedAt`;
  - `operatorHistoryWindowFrom`;
  - `operatorHistoryWindowUntil`;
- no DB migration;
- no scoring/grouping/blocking change;
- no Anti-Fraud refresh;
- regression guard added to prevent reintroducing the legacy loyalty dependency.

Production PR #67 runtime:

- revision: `b0d12a112577de2a35e0a49e55367e3bc459bc07`;
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:a9545807cf8b09c0a159e6d7bf8b3a1850ee5a7356966826c4d10a25bbf98767`;
- image config ID: `sha256:44916797485a87a94ead3e4cfc8445727b0a1752c08d9fa81123dd5172ae34a1`;
- canonical Compose SHA256: `8f9246704bf8cc75b2b9c2b6b849953766668e2af27790f4b05ea83a082d2d1c`;
- migrations: **26**;
- restart count: **0**;
- frontend asset changed from `/assets/antifraud-DH3ofxdZ.js` to `/assets/antifraud-2JsE0ngX.js`;
- deployment result: **41 PASS / 0 FAIL / 0 WARN**;
- rollback: not required;
- backup: `/opt/evrasia-ai-bot/backups/pr67-operator-history-ui-20260923-105939`.

## 11. Legacy ready-investigation physical snapshot backfill

Production audit after PR #67 showed:

- latest investigations total: **10**;
- latest `ready`: **7**;
- `ready` with physical snapshot: **2**;
- `ready` missing physical snapshot: **5**.

The five legacy latest-ready investigations were:

- USER_ID `67429`;
- USER_ID `2564174`;
- USER_ID `778635`;
- USER_ID `263189`;
- USER_ID `1969724`.

They were completed before PR #65 snapshot persistence existed. Therefore the correct remedy was a one-time physical-snapshot backfill, not a new investigation and not a scoring rerun.

Backfill safety contract:

- targeted Check-in read only;
- writes only `anti_fraud_operator_investigation_visits` and `physical_history_from/until` on the already-existing latest `ready` investigation;
- no scoring write;
- no `anti_fraud_visits` write;
- no Bitrix user-state write;
- no blocking;
- no app restart;
- exact source-event set hash verified against persisted snapshot;
- snapshot-to-`anti_fraud_visits` intersection required to remain zero.

Backup:

- directory: `/opt/evrasia-ai-bot/backups/pr65-legacy-physical-backfill-20260923-112902`;
- dump: `operator-history.before-backfill.dump`;
- SHA256: `634ebbc3954153b2644482462c4b0becd28beaeabfee4d0a5aee257d4157a84f`;
- archive verified with `pg_restore -l`.

Accepted backfill results:

- USER_ID `67429`: **8 visits / 6 days / 6 restaurants**;
- USER_ID `2564174`: **22 / 15 / 17**;
- USER_ID `778635`: **66 / 38 / 33**;
- USER_ID `263189`: **39 / 26 / 22**;
- USER_ID `1969724`: **11 / 10 / 9**.

For every account:

- source event count = snapshot row count;
- snapshot event IDs were distinct;
- source/snapshot event-set SHA256 matched exactly;
- snapshot-to-`anti_fraud_visits` intersection = **0**;
- UI-facing API returned the completed physical snapshot.

Global result:

- remaining latest `ready` without physical snapshot: **0**;
- latest `ready` with physical snapshot: **7**;
- backfilled: **5**;
- result: **55 PASS / 0 FAIL / 0 WARN**.

## 12. Browser visual acceptance

After PR #67 deployment and the legacy snapshot backfill, the operator rechecked the Anti-Fraud interface and confirmed that the required information is now visible and correct.

**BROWSER VISUAL ACCEPTANCE: PASS**

This closes the previously missing final validation layer. The backend/API-only acceptance from earlier in the day was not sufficient by itself.

Current rule for future UI work:

**do not call an operator-visible Anti-Fraud change accepted until the actual browser rendering has been visually confirmed when the task is about what the operator sees.**

## 13. Final continuation point

The operator physical-history workstream is complete.

Current accepted production application is PR #67 at revision `b0d12a112577de2a35e0a49e55367e3bc459bc07`, with all seven current latest-ready manual investigations covered by physical snapshots.

Do not redo PR #65/PR #67 deployment, multi-card dedup, legacy snapshot backfill, or USER_ID acceptance checks merely for reassurance.
