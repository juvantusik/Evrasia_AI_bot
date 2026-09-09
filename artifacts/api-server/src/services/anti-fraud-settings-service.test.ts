import assert from "node:assert/strict";
import test from "node:test";

process.env.DATABASE_URL ??= "postgresql://test:test@127.0.0.1:5432/test";

const { DEFAULT_ANTI_FRAUD_BONUS_BALANCE_THRESHOLD } = await import(
  "./anti-fraud-settings-service"
);

test("Anti-Fraud bonus threshold default remains 40000", () => {
  assert.equal(DEFAULT_ANTI_FRAUD_BONUS_BALANCE_THRESHOLD, 40_000);
});
