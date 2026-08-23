import assert from "node:assert/strict";
import test from "node:test";
import {
  getOperatorAccessOverview,
  isAllowedOperatorId,
  NATALIA_OPERATOR_ID,
  resolveTelegramOperatorId,
} from "./operator-access";

test("Natalia Telegram ID resolves to her assigned operator access", () => {
  const previousNataliaId = process.env.NATALIA_TELEGRAM_ID;
  process.env.NATALIA_TELEGRAM_ID = "1943162631";

  try {
    assert.equal(resolveTelegramOperatorId("1943162631"), NATALIA_OPERATOR_ID);
    assert.equal(isAllowedOperatorId(NATALIA_OPERATOR_ID), true);
    assert.equal(resolveTelegramOperatorId("1943162632"), null);

    const nataliaOverview = getOperatorAccessOverview().find(
      (operator) => operator.operatorId === NATALIA_OPERATOR_ID,
    );
    assert.equal(nataliaOverview?.configured, true);
    assert.equal(nataliaOverview?.accessMode, "assigned");
    assert.equal(nataliaOverview?.restaurantCount, 16);
  } finally {
    if (previousNataliaId === undefined) delete process.env.NATALIA_TELEGRAM_ID;
    else process.env.NATALIA_TELEGRAM_ID = previousNataliaId;
  }
});