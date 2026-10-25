# Restis loyalty MS SQL Server: verified schema evidence (2026-10-25)

## Scope and source of evidence

Source: operator-run SQL Server Management Studio (SQL Server 12.0.2269, SQL Server 2014) checks on **EUR-CRM**, reviewed during the Evrasia AI Bot discussion on 2026-10-25. Initial schema checks were read-only; later the operator supplied full trigger definitions and successfully committed **one direct, guarded UPDATE of a single intended account balance**. Live site/API source and the authorized Restis adjustment workflow were not verified. This is evidence, **not** an approved repeatable balance-adjustment procedure or deployed API fix.

**Privacy:** This public GitHub document deliberately excludes guest name, phone, card numbers, PIN, date of birth, account identifiers, and individual balance/transaction values. Example diagnostic SQL must use an operator-supplied, privately held card identifier. Do not copy screenshots of live accounts into public GitHub.

**Production safety:** Metadata checks were SELECT-only; **one production bonus balance UPDATE was subsequently committed by the operator**, under an exact-row and original-value guard. The operator observed successful SQL status and post-update balance plus DATE_CORR timestamp. No trigger enabled/disabled, API/site code change, or history write via the disabled BonusUpdate trigger was performed. The UPDATE was a direct DB correction, not a confirmed audited Restis business adjustment. **Do not repeat or generalize the direct UPDATE from this document.**

## Confirmed databases and tables

Three databases are visible on the EUR-CRM instance and have distinct behavior:

| Database | Object | Verified columns / observation | Interpretation status |
|---|---|---|---|
| `DBCARDS` | `dbo.CheckIN` | `Phone`, `cardNumber`; a phone lookup returned card IDs | Confirmed lookup evidence; **not** evidence those numbers are the canonical current Restis loyalty card |
| `DBCARDS` | `dbo.FD_CARD` | `ID_CODE`, `CARD_NO`, `NAME`, `PHONE`, `MPHONE`, `SUMMA`, `DATE_CORR`, `ID_CARDSTATE` | Observed `SUMMA` values on the CheckIN-linked cards were **not** the known current loyalty balance |
| `DBCARDS` | `dbo.FD_BONUS_INFO`, `dbo.FD_BONUS_CARDS` | `CARD_ID` (bonus info), `CARD_NUM` (bonus cards) | Schema discovered; relation and balance semantics unverified |
| `RBonus_STEAK` | `dbo.Cards` | `CardNumber`, `PHONE`, `MPHONE`, `CardTotalSum`, `LastAddBonus` and other card columns | Search for the known phone and DBCARDS-linked IDs returned **zero** rows in the examined case |
| `RBonus_STEAK` | `dbo.BonusUpdatedHistory` | `PREV_BONUS`, `NEW_BONUS`, `CARD_NUMBER`, `DATE_WRITE` | Search by DBCARDS-linked IDs returned **zero** rows in the examined case; no general conclusion about the whole table |
| `RESTISBONUS` | **`dbo.Cards`** | `ID`, `CardNumber`, `CardType`, `CardTotalSum`, `CardTodaySum`, `SumSpend`, `DateReg`, `CardExpDate`, `CardStatus`, `LastAddBonus`, `SuperNumber`, `NAME`, `PHONE`, `MPHONE`, `DATE_CORR` | **Confirmed:** an exact phone/card lookup matched the user's Restis account and `CardTotalSum` matched the loyalty balance shown by the application after integer rounding |
| `RESTISBONUS` | `dbo.BonusUpdatedHistory` | `PREV_BONUS`, `NEW_BONUS`, `CARD_NUMBER`, `DATE_WRITE` (from schema) | Possible balance history; **no matching rows or history semantics verified yet** in this database |
| `RESTISBONUS` | `dbo.RB_CARD_LOG` | `CardNumber`, `PayedBonus`, `AddBonus`, `Bill_Sum` (from schema) | Possible transaction journal; not yet examined for the actual card |
| `RESTISBONUS` | `dbo.CCARDS` | `CARD_NO`, `PHONE`, `BONUS` | Search with the DBCARDS-linked candidate card IDs/phone returned zero rows; does not disprove other records |
| `RESTISBONUS` | `dbo.caards_1` | `CARD_NO`, `BONUS` | Search with DBCARDS-linked candidate IDs returned zero rows |
| `RESTISBONUS` | `dbo.[YAP cards]` | `CARD_NO`, `PHONE`, `BONUS`, `SUM_BONUS` | Search with candidate IDs/phone returned zero rows |

**Confirmed distinction:** `RESTISBONUS.dbo.Cards.CardTotalSum` held the current balance for the verified account; `LastAddBonus` held a different value and **must not be substituted as the current balance**. The initial `DBCARDS.dbo.FD_CARD.SUMMA` finding was misleading for loyalty-balance API diagnostics.

This is one verified account/path, **not yet proof of the canonical source for every card type, site flow or API version**. Validate how the deployed V4 / site `CRestis` and proposed V5 loyalty readers resolve account to card and refresh balance.

## Verified SQL Server trigger metadata on RESTISBONUS.dbo.Cards

The operator executed read-only `sys.triggers` / `sys.trigger_events` / `sys.sql_modules` inspection. On 2026-10-25, the observed trigger states were:

| Trigger | DML event | is_disabled | State |
|---|---|---:|---|
| `dbo.UpdateRecord` | `UPDATE` | 0 | Enabled |
| `dbo.BonusUpdate` | `UPDATE` | 1 | Disabled |
| `dbo.CARD_DEL` | `DELETE` | 0 | Enabled |

**Full trigger bodies reviewed from the operator's copied SSMS output:** `UpdateRecord` is an `AFTER UPDATE` trigger. Only when `inserted` contains exactly one row, it reads the old and new `PHONE` values and the updated `ID` / `CardNumber`, then runs `UPDATE Cards SET DATE_CORR = GETDATE() WHERE ID = @ID`. The previously present `ChangePhoneCard` insert is commented out. No other explicit DML exists in its supplied body. An internal `UPDATE` of the same table can involve SQL Server trigger recursion/nesting settings: verify `sys.databases.is_recursive_triggers_on` and the environment before any write.

`BonusUpdate` is a **disabled** `AFTER UPDATE` trigger. It loops through rows in `inserted` using a local cursor, and would write `deleted.CardTotalSum` (old), `inserted.CardTotalSum` (new), `GETDATE()`, `inserted.CardNumber`, and `inserted.SuperNumber` to `[RestISBonus].[dbo].[BonusUpdatedHistory]` for matching IDs. It does **not** test whether `CardTotalSum` actually changed: enabling it blindly could log incidental updates (such as `DATE_CORR`) as bonus-change events. It was disabled at the observed checkpoint; the source does **not** establish why. No current journal write is verified for direct balance UPDATE.

`CARD_DEL` is an enabled `DELETE` trigger; it will not fire on a plain UPDATE, and its body was not reviewed.

**Safety conclusion:** The full shown trigger definitions narrow the known database-side effects but are not proof that a direct `CardTotalSum` change is an authorized/audited Restis balance-adjustment mechanism. Check live application writers, dedicated audit/ledger processes, recursive trigger setting, and reconciliation before production mutation. Do not enable `BonusUpdate` as a workaround.

The operator subsequently ran a single guarded transactional correction for their own identified card and supplied an SSMS result: `FINAL_STATUS=PASS`, old and target value confirmed, `COMMIT` completed and `DATE_CORR` refreshed. This was a **real production write**; no external/API freshness, journal reconciliation, or rollback proof was provided. Immediately afterward, a screenshot of the user's authenticated website account displayed **twice the newly written card balance**. The account/card identity and read path responsible for this discrepancy remain unknown; do not infer that Bitrix literally doubled the value. It could be selecting/aggregating different cards, reading another provider/endpoint, or caching a different state.

## New website mismatch finding and existing code context

- A live personal-account screenshot on `evrasia.rest` displayed a balance larger than the committed balance in `RESTISBONUS.dbo.Cards` for the operator's known card (a 2:1 observed ratio); **it was not verified that the site had read the same row at the same instant**. A subsequent independent read-only SQL check in `RESTISBONUS.dbo.Cards` confirmed `BalanceCheck=MATCH_100`, **exactly one** card matching the full phone in `PHONE`/`MPHONE` (`CardCount=1`), and aggregate balance equal to that same card. Therefore, the inspected table does not contain a second matching card explaining the site's higher number. The live Bitrix widget's actual data source, phone mapping, other database tables, protected API and cache remain unknown; do not conclude that its code directly doubles the balance.
- Existing repository service `artifacts/api-server/src/services/bitrix-antifraud-loyalty-gateway.ts` calls the protected `https://evrasia.rest/api/internal/anti-fraud/loyalty`. The multicard test indicates the **site-side `/api/Balance` by Phone can report an account-level `TotalSum` when multiple active cards exist**. This is a lead to investigate, **not proof** of the website widget's own data path or why it showed the larger value.
- Preserve `RESTIS_STATE=113` account/card resolution semantics, source precision and negative balances. Never “fix” a mismatch by multiplying/dividing by 2 without a verified source path.

## Safe diagnostic queries (read-only; no guest identifiers in repository)

```sql
USE [RESTISBONUS];
SET NOCOUNT ON;

-- Enter the intended card identifier locally only; do not commit it.
DECLARE @card_number VARCHAR(100) = '<PRIVATE_CARD_NUMBER>';

SELECT TOP (5)
    ID, CardNumber, CardTotalSum, LastAddBonus, CardStatus, DATE_CORR
FROM dbo.Cards
WHERE CardNumber = @card_number;

SELECT t.name AS TriggerName,
       t.is_disabled AS IsDisabled,
       te.type_desc AS EventType,
       m.definition AS TriggerSQL
FROM sys.triggers AS t
LEFT JOIN sys.trigger_events AS te ON te.object_id = t.object_id
LEFT JOIN sys.sql_modules AS m ON m.object_id = t.object_id
WHERE t.parent_id = OBJECT_ID('dbo.Cards')
ORDER BY t.name, te.type_desc;
```

## Follow-up before any mutation or API change

1. Full definitions for `UpdateRecord` and `BonusUpdate` were reviewed; verify recursion/nesting settings, external writers, reconciliation and transaction dependencies before **any further mutation**.
2. Read real `RESTISBONUS.dbo.RB_CARD_LOG` schema and scoped row history to establish reconciliation and audit semantics; read `BonusUpdatedHistory` as needed. No journal row manipulation.
3. Inspect actual deployed Bitrix V4 / Restis bridge readers and their mapping from the current user to the Restis card; verify API current-response vs the same row and refresh/cache timing. Do **not** alter working V4 blindly.
4. The operator's one successful direct UPDATE is **not evidence** that updating `CardTotalSum` alone is safe as a production procedure. Establish authoritative Restis writer, backup, audit trail, reconciliation, idempotency and DB/API/site post-check before any further change.
5. Continue the **next diagnostic step** in READ_ONLY mode. The previous transaction is already committed. Do not publish user details, PIN or credentials.

## Project continuity

- Repo: `juvantusik/Evrasia_AI_bot`.
- Workstream: Restis loyalty / inaccurate bonus API balances; separate from native Bitrix auth and V5 rollout.
- User-facing public domain: `evrasia.rest`; do not confuse it with SQL Server `EUR-CRM` or the Bitrix DB `eurasia_new`.
- Evidence is from live production SQL metadata and account read, not a SQL Server source repository or deployment manifest.
