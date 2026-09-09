# Anti-Fraud operator settings

Status: **production deployed and operator accepted on 2026-09-09**.

Implementation lineage:

- PR #43 — `Anti-Fraud: configurable bonus threshold and new-account badge`, merge `3ce9f8c1904351d77696e70314bba3c60afeffa5`
- PR #44 — `Fix Anti-Fraud settings modal viewport overflow`, merge `a45554b25a820615c95b3a3148a38640a4a27507`; intermediate viewport/scroll fix, later visually rejected by the operator as unsatisfactory
- PR #45 — `Fix Anti-Fraud settings modal viewport regression`, merge `b7402cbe19b14f4d84c77870c8be876fe6f7bf42`; renders the modal through a React portal into `document.body`, restoring viewport-relative fixed positioning and a single internal vertical scroll container

The final PR #45 UI was deployed to production and the operator confirmed: **«все супер, отображение как надо»**.

## Bonus balance threshold

The Anti-Fraud web UI exposes an operator setting `Порог бонусного баланса` through the `Настройка` button placed immediately to the left of `Обновить сейчас`.

- Current confirmed production/default value: `40000.00` bonuses.
- Persisted key: `anti_fraud_bonus_balance_threshold` in the existing `bot_settings` table.
- No schema migration is required; production migration count remains **20**.
- The strict comparison is preserved: only `bonus_balance > threshold` adds `+50` historical-behavior risk and opens the targeted 60-day history gate.
- Exactly equal balance does not trigger the rule.
- Saving the setting does not start a refresh. The new value is read by the next risk-scoring run, including a normal scheduled run or an operator-triggered `Обновить сейчас` cycle.
- The setting does not change grouping rules and does not automatically block an account.
- The operator may change `40000` to another value such as `30000` for a controlled check; the new value becomes effective on the next scoring cycle.

## `Новый` account badge

Case dynamics persist the previous and current account membership for each Anti-Fraud case.

The UI marks an account with `Новый` when its USER_ID is present in the latest `addedAccountIds` for an already existing/previously observed case.

A case that is observed for the first time does **not** mark every member as new. The badge is only for an account that newly enters an already known linkage/case.

The badge represents the latest structural addition recorded for the case. No synthetic time window or guessed timestamp is introduced.

## Settings modal viewport acceptance

Final accepted behavior after PR #45:

- the overlay is rendered through a React portal into `document.body`;
- `position: fixed` is therefore relative to the viewport rather than the sticky header/backdrop-filter containing block;
- desktop modal fits the viewport;
- one internal vertical scroll container is used when content height requires it;
- lower controls/buttons remain reachable;
- existing mobile behavior is preserved;
- no backend, DB schema, scheduler, threshold semantics or Bitrix behavior changed as part of PR #45.
