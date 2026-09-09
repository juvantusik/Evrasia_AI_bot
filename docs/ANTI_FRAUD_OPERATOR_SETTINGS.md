# Anti-Fraud operator settings

Status: implementation branch; production baseline is unchanged until a controlled deployment is accepted.

## Bonus balance threshold

The Anti-Fraud web UI exposes an operator setting `Порог бонусного баланса`.

- Default: `40000.00` bonuses.
- Persisted key: `anti_fraud_bonus_balance_threshold` in the existing `bot_settings` table.
- No schema migration is required.
- The existing strict comparison is preserved: only `bonus_balance > threshold` adds `+50` historical-behavior risk and opens the targeted 60-day history gate.
- Saving the setting does not start a refresh. The new value is read by the next risk-scoring run, including a normal scheduled run or an operator-triggered `Обновить сейчас` cycle.
- The setting does not change grouping rules and does not automatically block an account.

## `Новый` account badge

Case dynamics already persist the previous and current account membership for each Anti-Fraud case.

The UI marks an account with `Новый` when its USER_ID is present in the latest `addedAccountIds` for an existing case. A case that is observed for the first time does not mark every member as new.

The badge represents the latest structural addition recorded for the case. No synthetic time window or guessed timestamp is introduced.
