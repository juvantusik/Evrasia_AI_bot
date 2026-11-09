import assert from "node:assert/strict";
import test from "node:test";
import {
  detectAntiFraudReceiptOverlaps,
  type AntiFraudClosedReceipt,
} from "./anti-fraud-receipt-overlaps";

const receipt = (
  receiptKey: string,
  openedAt: string,
  closedAt: string,
  extras: Partial<AntiFraudClosedReceipt> = {},
): AntiFraudClosedReceipt => ({
  cardKey: "opaque-card-1",
  receiptKey,
  restaurantKey: "rest-1",
  restaurantName: "Энгельса 134",
  openedAt: new Date(openedAt),
  closedAt: new Date(closedAt),
  closedAtEvidence: "confirmed_receipt_close",
  ...extras,
});

test("one card, same restaurant: 2026-11-03 example overlaps for 23m45s", () => {
  const matches = detectAntiFraudReceiptOverlaps([
    receipt("check-one", "2026-11-03T18:09:42+03:00", "2026-11-03T19:02:01+03:00", {
      amount: "1240.00",
    }),
    receipt("check-two", "2026-11-03T18:38:16+03:00", "2026-11-03T20:21:52+03:00", {
      amount: "1825.00",
    }),
  ]);
  assert.equal(matches.length, 1);
  assert.equal(matches[0].restaurantScope, "same_restaurant");
  assert.equal(matches[0].overlapMilliseconds, 23 * 60_000 + 45_000);
  assert.equal(matches[0].first.amount, "1240.00");
  assert.equal(matches[0].second.amount, "1825.00");
});

test("same card in different restaurants ranks above same-restaurant overlap", () => {
  const matches = detectAntiFraudReceiptOverlaps([
    receipt("1", "2026-11-03T18:00:00+03:00", "2026-11-03T19:00:00+03:00"),
    receipt("2", "2026-11-03T18:30:00+03:00", "2026-11-03T19:30:00+03:00"),
    receipt("3", "2026-11-03T18:40:00+03:00", "2026-11-03T19:40:00+03:00", {
      restaurantKey: "rest-2",
      restaurantName: "Другой ресторан",
    }),
  ]);
  assert.equal(matches.length, 3);
  assert.equal(matches[0].restaurantScope, "different_restaurants");
  assert.equal(matches[1].restaurantScope, "different_restaurants");
  assert.equal(matches[2].restaurantScope, "same_restaurant");
});

test("different cards and adjacent checks never count as overlap", () => {
  const matches = detectAntiFraudReceiptOverlaps([
    receipt("1", "2026-11-03T18:00:00+03:00", "2026-11-03T19:00:00+03:00"),
    receipt("2", "2026-11-03T18:30:00+03:00", "2026-11-03T19:30:00+03:00", {
      cardKey: "opaque-card-2",
    }),
    receipt("3", "2026-11-03T19:00:00+03:00", "2026-11-03T19:30:00+03:00"),
  ]);
  assert.deepEqual(matches, []);
});

test("duplicate monetary rows of one receipt count only once", () => {
  const base = receipt(
    "one",
    "2026-11-03T18:00:00+03:00",
    "2026-11-03T19:00:00+03:00",
  );
  const other = receipt(
    "two",
    "2026-11-03T18:30:00+03:00",
    "2026-11-03T19:30:00+03:00",
  );
  assert.equal(detectAntiFraudReceiptOverlaps([base, {...base}, other]).length, 1);
});

test("conflicting duplicates fail closed, rather than making up receipt evidence", () => {
  const base = receipt(
    "one",
    "2026-11-03T18:00:00+03:00",
    "2026-11-03T19:00:00+03:00",
  );
  assert.throws(
    () => detectAntiFraudReceiptOverlaps([
      base,
      {...base, closedAt: new Date("2026-11-03T19:05:00+03:00")},
    ]),
    /contradictory/,
  );
});

test("operation timestamps cannot masquerade as confirmed receipt closing", () => {
  const base = receipt(
    "one",
    "2026-11-03T18:00:00+03:00",
    "2026-11-03T19:00:00+03:00",
  );
  assert.throws(
    () => detectAntiFraudReceiptOverlaps([
      {...base, closedAtEvidence: "operation_time" as AntiFraudClosedReceipt["closedAtEvidence"]},
    ]),
    /required verified/,
  );
});

test("reject missing IDs and impossible or invalid intervals", () => {
  const base = receipt(
    "one",
    "2026-11-03T18:00:00+03:00",
    "2026-11-03T19:00:00+03:00",
  );
  assert.throws(() => detectAntiFraudReceiptOverlaps([{...base, receiptKey: ""}]));
  assert.throws(() => detectAntiFraudReceiptOverlaps([{...base, cardKey: ""}]));
  assert.throws(() => detectAntiFraudReceiptOverlaps([{...base, openedAt: base.closedAt}]));
  assert.throws(() => detectAntiFraudReceiptOverlaps([{...base, closedAt: new Date("invalid")}]));
});

test("one card with three concurrent receipts gives all three pairs", () => {
  const matches = detectAntiFraudReceiptOverlaps([
    receipt("1", "2026-11-03T18:00:00+03:00", "2026-11-03T20:00:00+03:00"),
    receipt("2", "2026-11-03T18:15:00+03:00", "2026-11-03T20:00:00+03:00"),
    receipt("3", "2026-11-03T18:30:00+03:00", "2026-11-03T20:00:00+03:00"),
  ]);
  assert.equal(matches.length, 3);
});

test("future versions must not infer same customer from only Bitrix USER_ID", () => {
  assert.deepEqual(detectAntiFraudReceiptOverlaps([
    receipt("1", "2026-11-03T18:00:00+03:00", "2026-11-03T20:00:00+03:00", {
      cardKey: "card-A",
    }),
    receipt("2", "2026-11-03T18:15:00+03:00", "2026-11-03T20:00:00+03:00", {
      cardKey: "card-B",
    }),
  ]), []);
});
