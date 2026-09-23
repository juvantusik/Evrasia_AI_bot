import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const loadAntiFraudPageSource = async (): Promise<string> =>
  readFile(
    new URL(
      "../../../samzaberu-ops/src/pages/AntiFraudPage.tsx",
      import.meta.url,
    ),
    "utf8",
  );

test("operator physical-history UI is gated by PR65 snapshot completion, not legacy loyalty history", async () => {
  const source = await loadAntiFraudPageSource();

  const match = source.match(
    /const historyCovered = Boolean\(([\s\S]*?)\n  \);/,
  );

  assert.ok(match, "historyCovered gate must exist");

  const gate = match[1];

  assert.match(gate, /operatorInvestigationHistoryCompletedAt/);
  assert.match(gate, /operatorHistoryWindowFrom/);
  assert.match(gate, /operatorHistoryWindowUntil/);
  assert.doesNotMatch(gate, /loyaltyHistoryLoadedAt/);
});

test("operator physical-history metrics remain rendered from account snapshot fields", async () => {
  const source = await loadAntiFraudPageSource();

  assert.match(source, /account\.historyPhysicalVisits \?\? 0/);
  assert.match(source, /account\.historyVisitDays \?\? 0/);
  assert.match(source, /account\.historyRestaurantCount \?\? 0/);
});
