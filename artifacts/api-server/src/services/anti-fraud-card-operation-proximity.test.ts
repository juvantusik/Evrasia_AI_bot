import assert from "node:assert/strict";
import test from "node:test";
import {
  detectCrossRestaurantCardOperationProximity,
  type AntiFraudPositiveCardOperation,
} from "./anti-fraud-card-operation-proximity";

const event = (
  operationKey: string,
  receiptKey: string,
  restaurantKey: string,
  at: string,
  overrides: Partial<AntiFraudPositiveCardOperation> = {},
): AntiFraudPositiveCardOperation => ({
  cardKey: "opaque-loyalty-card-A",
  operationKey,
  receiptKey,
  restaurantKey,
  occurredAt: new Date(at),
  ...overrides,
});

test("2026-11-02: same card, 2 restaurants, 11m05s between recorded operations", () => {
  const matches = detectCrossRestaurantCardOperationProximity([
    event("id-a", "961405", "167", "2026-11-02T15:33:44+03:00"),
    event("id-b", "742989", "62", "2026-11-02T15:44:49+03:00"),
  ]);
  assert.equal(matches.length, 1);
  assert.equal(matches[0].gapSeconds, 665);
  assert.equal(matches[0].evidence, "positive_bonus_operations_not_receipt_closure");
  assert.equal(matches[0].firstRestaurantKey, "167");
  assert.equal(matches[0].secondRestaurantKey, "62");
});

test("same restaurant never counts even for very close operations", () => {
  assert.deepEqual(detectCrossRestaurantCardOperationProximity([
    event("a", "1", "41", "2026-11-03T19:00:00+03:00"),
    event("b", "2", "41", "2026-11-03T19:00:01+03:00"),
  ]), []);
});

test("two cards are not equivalent even when they belong to one user", () => {
  assert.deepEqual(detectCrossRestaurantCardOperationProximity([
    event("a", "1", "41", "2026-11-03T19:00:00+03:00"),
    event("b", "2", "42", "2026-11-03T19:00:01+03:00", {
      cardKey: "opaque-loyalty-card-B",
    }),
  ]), []);
});

test("over 30 minutes excluded by default; 30 minutes exactly included", () => {
  const first = event("a", "1", "41", "2026-11-03T19:00:00+03:00");
  assert.equal(detectCrossRestaurantCardOperationProximity([
    first, event("b", "2", "42", "2026-11-03T19:30:00+03:00"),
  ]).length, 1);
  assert.equal(detectCrossRestaurantCardOperationProximity([
    first, event("b", "2", "42", "2026-11-03T19:30:01+03:00"),
  ]).length, 0);
});

test("duplicate source operation is ignored; conflicting ID is rejected", () => {
  const first = event("a", "1", "41", "2026-11-03T19:00:00+03:00");
  const second = event("b", "2", "42", "2026-11-03T19:01:00+03:00");
  assert.equal(detectCrossRestaurantCardOperationProximity([
    first, {...first}, second,
  ]).length, 1);
  assert.throws(() => detectCrossRestaurantCardOperationProximity([
    first, {...first, receiptKey: "unexpected"},
  ]), /conflicting operation identity/);
});

test("multiple operations per receipt emit one closest cross-restaurant receipt pair", () => {
  const matches = detectCrossRestaurantCardOperationProximity([
    event("a1", "1", "41", "2026-11-03T19:00:00+03:00"),
    event("b1", "2", "42", "2026-11-03T19:20:00+03:00"),
    event("b2", "2", "42", "2026-11-03T19:21:00+03:00"),
    event("a2", "1", "41", "2026-11-03T19:22:00+03:00"),
  ]);
  assert.equal(matches.length, 1);
  assert.equal(matches[0].gapSeconds, 60);
  assert.equal(matches[0].firstOperationKey, "b2");
  assert.equal(matches[0].secondOperationKey, "a2");
});

test("missing identity, invalid time and unreasonable threshold fail closed", () => {
  const first = event("a", "1", "41", "2026-11-03T19:00:00+03:00");
  assert.throws(() => detectCrossRestaurantCardOperationProximity([
    {...first, cardKey: ""},
  ]), /incomplete/);
  assert.throws(() => detectCrossRestaurantCardOperationProximity([
    {...first, occurredAt: new Date("bad")},
  ]), /incomplete/);
  assert.throws(() => detectCrossRestaurantCardOperationProximity([first], 0));
  assert.throws(() => detectCrossRestaurantCardOperationProximity([first], 3601));
});
