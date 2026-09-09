# Anti-Fraud UI — next planned improvements

> Status: **PLANNED**, not implemented.
>
> Captured on 2026-09-09 for the next working session.

## Why this document exists

The current Anti-Fraud UI milestone through PR #45 is production accepted. The next iteration is not a bugfix to that modal work; it is a separate operator-visibility improvement focused on case dynamics and device-hash accumulation.

Do not implement from memory alone. Before changing code, inspect current `main`, current production runtime and the exact UI/data structures that drive case-dynamics labels and device-hash statistics.

---

## 1. New accounts should be visible as a case-dynamics status

### Current problem

PR #43 added the structural `Новый` account marker based on the latest `addedAccountIds` for a previously observed case, but in practical operator use newly appeared users are not visible enough in the current table/workflow.

### Desired behavior

The operator wants **`Новый`** to appear in the same visual/status area where case dynamics such as `усилился`, `без изменений`, etc. are shown — effectively as another clearly visible dynamics state.

Important semantic constraint:

- `Новый` is not a Bitrix account-state value;
- `Новый` is not a risk score;
- it means the account appeared in the latest `addedAccountIds` for a previously observed case;
- a newly observed case must not mark all of its members as new;
- preserve the already accepted PR #43 membership-delta semantics.

Before implementation, verify the exact existing set/names/priorities of case-dynamics statuses in current code. Do not guess a status enum or overwrite another dynamics state without first understanding how multiple conditions should be represented.

The UX goal is operator visibility: a newly added account should be immediately obvious in the same place the operator already looks for case change/dynamics.

---

## 2. Visual progress for accumulated `device_hash_id`

### Current need

Add an operator-visible indicator showing how much `device_hash_id` data has already been accumulated, so the operator can see collection progress over time.

The first useful version should answer at minimum:

- how many device hashes are currently known/accumulated;
- ideally how many are unique versus raw records, depending on the actual current data model;
- enough context that the number is meaningful as progress rather than an unexplained counter.

Do not invent the SQL/table/column source. Inspect the factual current schema and the Anti-Fraud collection pipeline first.

### Future segmentation

The visualization should be designed so it can later split device hashes by origin/source:

1. website;
2. SamZaberu application;
3. mobile waiter application.

This source split is a **future requirement**. First inspect whether source/origin is already persisted for each `device_hash_id`. If it is not, adding the visual split will require a data-model/integration change before the UI can report it honestly.

Do not infer source from unreliable heuristics if the system does not currently persist an authoritative source attribute.

---

## 3. UX direction

The next iteration should remain consistent with the accepted Anti-Fraud design. Do not redesign the page globally.

Preferred approach:

- make `Новый` visible in the established case-dynamics/status presentation rather than adding another unrelated badge location;
- add a compact device-hash progress/KPI element in an existing summary/operator-information area;
- keep the first version simple and factual;
- design the progress widget so future source segmentation can be added without reworking the whole page.

Desktop/mobile behavior must both be checked.

---

## 4. Investigation order for the next session

1. Read `docs/PROJECT_CHECKPOINT.md`, `docs/NEW_CHAT_HANDOFF.md`, `docs/ANTI_FRAUD_OPERATOR_SETTINGS.md` and this document.
2. Inspect current GitHub `main` and current production runtime before mutation.
3. Find the exact code that renders case-dynamics labels/statuses and the existing `addedAccountIds` / `Новый` marker.
4. Determine how status priority/composition currently works before introducing `Новый` into that area.
5. Inspect the factual DB/schema/query path for `device_hash_id` and determine what count(s) can be reported accurately now.
6. Determine whether device-hash source/origin is already stored. If not, document the required future data-model change rather than faking source segmentation.
7. Implement minimal UI changes, test desktop/mobile, then stage/deploy using the normal production guards.

---

## 5. Status summary

- PR #43 `Новый` membership-delta semantics: **IMPLEMENTED / PRODUCTION**
- `Новый` as clearly visible case-dynamics status: **PLANNED**
- total `device_hash_id` accumulation/progress visualization: **PLANNED**
- device-hash segmentation by website / SamZaberu / mobile waiter: **FUTURE / DATA-SOURCE CHECK REQUIRED**
