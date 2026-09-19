import assert from "node:assert/strict";
import test from "node:test";

process.env.DATABASE_URL ??= "postgresql://test:test@127.0.0.1:5432/test";

const { parseAntiFraudOperatorWatchlist } = await import(
  "./anti-fraud-operator-watchlist-service"
);

test("legacy numeric watchlist stays backward compatible", () => {
  assert.deepEqual(parseAntiFraudOperatorWatchlist("[27987,1969724]"), [
    {
      bitrixUserId: 27987,
      label: "Наблюдение",
      riskOverride: null,
      reason: null,
    },
    {
      bitrixUserId: 1969724,
      label: "Наблюдение",
      riskOverride: null,
      reason: null,
    },
  ]);
});

test("structured operator entry preserves label and risk override", () => {
  assert.deepEqual(
    parseAntiFraudOperatorWatchlist(
      JSON.stringify([
        {
          userId: 27987,
          label: "Авито",
          risk: 100,
          reason: "confirmed_checkin_sale",
        },
      ]),
    ),
    [
      {
        bitrixUserId: 27987,
        label: "Авито",
        riskOverride: 100,
        reason: "confirmed_checkin_sale",
      },
    ],
  );
});

test("operator risk is clamped and duplicate USER_ID uses latest entry", () => {
  const records = parseAntiFraudOperatorWatchlist(
    JSON.stringify([
      { userId: 27987, label: "old", risk: 10 },
      { userId: 27987, label: "Авито", risk: 150 },
    ]),
  );

  assert.deepEqual(records, [
    {
      bitrixUserId: 27987,
      label: "Авито",
      riskOverride: 100,
      reason: null,
    },
  ]);
});
