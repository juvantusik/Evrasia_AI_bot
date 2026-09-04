import assert from "node:assert/strict";
import test from "node:test";
import {
  scoreAntiFraudSignals,
  type AntiFraudRiskSignals,
} from "./anti-fraud-risk-engine";

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
  activeCardCount: 1,
  bitrixActive: true,
  historyEnriched: false,
  ...overrides,
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("two accounts on one device without fast switching stay below history gate", () => {
  const score = scoreAntiFraudSignals(
    baseSignals({
      maxAccountsOnDevice: 2,
      sharedDeviceCount: 1,
      linkedAccountCount: 1,
    }),
  );

  assert.equal(score.deviceRisk, 20);
  assert.equal(score.linkedAccountRisk, 10);
  assert.equal(score.overallRisk, 30);
  assert.equal(score.riskLevel, "medium");
  assert.equal(score.historyGate, false);
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

// Добавлено 03.09.2026 ИТ Директор Евразии
test("77-second switch on a two-account device crosses the history gate", () => {
  const score = scoreAntiFraudSignals(
    baseSignals({
      maxAccountsOnDevice: 2,
      sharedDeviceCount: 1,
      linkedAccountCount: 1,
      fastestSwitchSeconds: 77,
      fastSwitchCount5m: 1,
    }),
  );

  assert.equal(score.deviceRisk, 55);
  assert.equal(score.linkedAccountRisk, 10);
  assert.equal(score.overallRisk, 65);
  assert.equal(score.riskLevel, "high");
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
