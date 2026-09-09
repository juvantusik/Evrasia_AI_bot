import assert from "node:assert/strict";
import test from "node:test";

// Добавлено 03.09.2026 ИТ Директор Евразии
// Risk unit tests не обращаются к БД, но production-модуль создаёт pg Pool при импорте.
// Задаём синтаксически корректный test-only DATABASE_URL до dynamic import, чтобы CI проверял
// чистую scoring-логику без зависимости от production/runtime окружения.
process.env.DATABASE_URL ??= "postgresql://test:test@127.0.0.1:5432/test";

const { scoreAntiFraudSignals } = await import("./anti-fraud-risk-engine");
const { resolveAntiFraudCaseLineageRenames } = await import(
  "./anti-fraud-case-dynamics-service"
);
type AntiFraudRiskSignals = Parameters<typeof scoreAntiFraudSignals>[0];

const baseSignals = (overrides: Partial<AntiFraudRiskSignals> = {}): AntiFraudRiskSignals => ({
  bitrixUserId: 1,
  maxAccountsOnDevice: 0,
  sharedDeviceCount: 0,
  linkedAccountCount: 0,
  fastestSwitchSeconds: null,
  fastSwitchCount5m: 0,
  repeatedPairDeviceCount: 0,
  duplicatePhoneAccounts: 0,
  duplicateEmailAccounts: 0,
  nearbyLinkedVisitPairs: 0,
  maxVisitsPerDay60d: 0,
  highVisitDays60d: 0,
  longestHighVisitSequence2d: 0,
  maxDistinctRestaurantsOnHighVisitDay: 0,
  activeCardCount: 1,
  bitrixActive: true,
  historyEnriched: false,
  bonusBalance: null,
  ...overrides,
});

// Обновлено 05.09.2026 ИТ Директор Евразии
test("two accounts on one device trigger the 60-day history gate", () => {
  const score = scoreAntiFraudSignals(
    baseSignals({
      maxAccountsOnDevice: 2,
      sharedDeviceCount: 1,
      linkedAccountCount: 1,
    }),
  );

  assert.equal(score.deviceRisk, 40);
  assert.equal(score.linkedAccountRisk, 10);
  assert.equal(score.overallRisk, 50);
  assert.equal(score.riskLevel, "high");
  assert.equal(score.historyGate, true);

  const reason = score.reasons.find((item) => item.code === "shared_device_accounts");
  assert.ok(reason);
  assert.equal(reason.score, 40);
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("three accounts with repeated 13-second switching become critical", () => {
  const score = scoreAntiFraudSignals(
    baseSignals({
      maxAccountsOnDevice: 3,
      sharedDeviceCount: 1,
      linkedAccountCount: 2,
      fastestSwitchSeconds: 13,
      fastSwitchCount5m: 5,
    }),
  );

  assert.equal(score.deviceRisk, 90);
  assert.equal(score.linkedAccountRisk, 20);
  assert.equal(score.overallRisk, 100);
  assert.equal(score.riskLevel, "critical");
  assert.equal(score.historyGate, true);
  assert.ok(score.reasons.some((reason) => reason.code === "fast_account_switch"));
  assert.ok(score.reasons.some((reason) => reason.code === "repeated_fast_switches"));
});

// Обновлено 05.09.2026 ИТ Директор Евразии
test("77-second switch on a two-account device becomes critical", () => {
  const score = scoreAntiFraudSignals(
    baseSignals({
      maxAccountsOnDevice: 2,
      sharedDeviceCount: 1,
      linkedAccountCount: 1,
      fastestSwitchSeconds: 77,
      fastSwitchCount5m: 1,
    }),
  );

  assert.equal(score.deviceRisk, 75);
  assert.equal(score.linkedAccountRisk, 10);
  assert.equal(score.overallRisk, 85);
  assert.equal(score.riskLevel, "critical");
  assert.equal(score.historyGate, true);
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("same account cluster repeated across several devices is a strong linked-account signal", () => {
  const score = scoreAntiFraudSignals(
    baseSignals({
      maxAccountsOnDevice: 3,
      sharedDeviceCount: 3,
      linkedAccountCount: 2,
      repeatedPairDeviceCount: 3,
    }),
  );

  assert.equal(score.deviceRisk, 50);
  assert.equal(score.linkedAccountRisk, 60);
  assert.equal(score.overallRisk, 100);
  assert.equal(score.riskLevel, "critical");
  assert.equal(score.historyGate, true);
  assert.ok(score.reasons.some((reason) => reason.code === "repeated_device_pair"));
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("three visits in one day trigger history regardless of restaurant count", () => {
  const sameRestaurant = scoreAntiFraudSignals(
    baseSignals({
      maxVisitsPerDay60d: 3,
      highVisitDays60d: 1,
      maxDistinctRestaurantsOnHighVisitDay: 1,
    }),
  );
  const differentRestaurants = scoreAntiFraudSignals(
    baseSignals({
      maxVisitsPerDay60d: 3,
      highVisitDays60d: 1,
      maxDistinctRestaurantsOnHighVisitDay: 3,
    }),
  );

  assert.equal(sameRestaurant.visitBehaviorRisk, 50);
  assert.equal(differentRestaurants.visitBehaviorRisk, 50);
  assert.equal(sameRestaurant.historyGate, true);
  assert.equal(differentRestaurants.historyGate, true);

  const reason = differentRestaurants.reasons.find(
    (item) => item.code === "high_daily_visit_frequency",
  );
  assert.ok(reason);
  assert.match(reason.details, /max_distinct_restaurants=3/);
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("three-plus visits every day or every other day are critical behavior", () => {
  const score = scoreAntiFraudSignals(
    baseSignals({
      maxVisitsPerDay60d: 3,
      highVisitDays60d: 3,
      longestHighVisitSequence2d: 3,
      maxDistinctRestaurantsOnHighVisitDay: 2,
    }),
  );

  assert.equal(score.visitBehaviorRisk, 85);
  assert.equal(score.overallRisk, 85);
  assert.equal(score.riskLevel, "critical");
  assert.equal(score.historyGate, true);
  assert.ok(score.reasons.some((reason) => reason.code === "repeated_high_visit_days"));
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("five visits on repeated high-visit days clamp visit risk to 100", () => {
  const score = scoreAntiFraudSignals(
    baseSignals({
      maxVisitsPerDay60d: 5,
      highVisitDays60d: 5,
      longestHighVisitSequence2d: 5,
      maxDistinctRestaurantsOnHighVisitDay: 5,
    }),
  );

  assert.equal(score.visitBehaviorRisk, 100);
  assert.equal(score.overallRisk, 100);
  assert.equal(score.riskLevel, "critical");
  assert.equal(score.historyGate, true);
});

// Добавлено 05.09.2026 ИТ Директор Евразии
test("exactly 40000.00 bonuses do not trigger the high-balance gate", () => {
  const score = scoreAntiFraudSignals(baseSignals({ bonusBalance: 40_000 }));

  assert.equal(score.historicalBehaviorRisk, 0);
  assert.equal(score.overallRisk, 0);
  assert.equal(score.historyGate, false);
  assert.equal(score.reasons.some((reason) => reason.code === "high_bonus_balance"), false);
});

// Обновлено 05.09.2026 ИТ Директор Евразии
test("40000.01 bonuses add 50 risk and trigger 60-day history gate", () => {
  const score = scoreAntiFraudSignals(baseSignals({ bonusBalance: 40_000.01 }));

  assert.equal(score.historicalBehaviorRisk, 50);
  assert.equal(score.overallRisk, 50);
  assert.equal(score.riskLevel, "high");
  assert.equal(score.historyGate, true);

  const reason = score.reasons.find((item) => item.code === "high_bonus_balance");
  assert.ok(reason);
  assert.equal(reason.score, 50);
  assert.match(reason.details, /bonus_balance=40000\.01/);
  assert.match(reason.details, /threshold=40000\.00/);
  assert.match(reason.details, /history_window_days=60/);
});

test("custom 30000.00 threshold preserves strict greater-than boundary", () => {
  const exact = scoreAntiFraudSignals(baseSignals({ bonusBalance: 30_000 }), 50, 30_000);
  const above = scoreAntiFraudSignals(baseSignals({ bonusBalance: 30_000.01 }), 50, 30_000);

  assert.equal(exact.historicalBehaviorRisk, 0);
  assert.equal(exact.historyGate, false);
  assert.equal(above.historicalBehaviorRisk, 50);
  assert.equal(above.overallRisk, 50);
  assert.equal(above.historyGate, true);

  const reason = above.reasons.find((item) => item.code === "high_bonus_balance");
  assert.ok(reason);
  assert.match(reason.details, /bonus_balance=30000\.01/);
  assert.match(reason.details, /threshold=30000\.00/);
});

test("case lineage follows an existing group when a lower USER_ID joins", () => {
  const renames = resolveAntiFraudCaseLineageRenames(
    [{ caseId: "AF-50", accountIds: [50, 100, 200] }],
    [{ caseId: "AF-100", accountIds: [100, 200] }],
  );

  assert.deepEqual(renames, [{ fromCaseId: "AF-100", toCaseId: "AF-50" }]);
});

test("case lineage does not guess when two previous cases merge", () => {
  const renames = resolveAntiFraudCaseLineageRenames(
    [{ caseId: "AF-50", accountIds: [50, 100, 200, 300] }],
    [
      { caseId: "AF-100", accountIds: [100, 200] },
      { caseId: "AF-50-old", accountIds: [50, 300] },
    ],
  );

  assert.deepEqual(renames, []);
});
