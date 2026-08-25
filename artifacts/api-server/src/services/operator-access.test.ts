import assert from "node:assert/strict";
import test from "node:test";
import {
  EKATERINA_OPERATOR_ID,
  getOperatorAccessOverview,
  isAllowedOperatorId,
  MARINA_OPERATOR_ID,
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

test("Ekaterina Telegram ID resolves to her 13 assigned restaurants", () => {
  const previousEkaterinaId = process.env.EKATERINA_TELEGRAM_ID;
  process.env.EKATERINA_TELEGRAM_ID = "952658667";

  try {
    assert.equal(resolveTelegramOperatorId("952658667"), EKATERINA_OPERATOR_ID);
    assert.equal(isAllowedOperatorId(EKATERINA_OPERATOR_ID), true);
    assert.equal(resolveTelegramOperatorId("952658668"), null);

    const ekaterinaOverview = getOperatorAccessOverview().find(
      (operator) => operator.operatorId === EKATERINA_OPERATOR_ID,
    );
    assert.equal(ekaterinaOverview?.configured, true);
    assert.equal(ekaterinaOverview?.accessMode, "assigned");
    assert.equal(ekaterinaOverview?.restaurantCount, 13);
  } finally {
    if (previousEkaterinaId === undefined) delete process.env.EKATERINA_TELEGRAM_ID;
    else process.env.EKATERINA_TELEGRAM_ID = previousEkaterinaId;
  }
});

test("Marina Telegram ID resolves to her 14 assigned restaurants", () => {
  const previousMarinaId = process.env.MARINA_TELEGRAM_ID;
  process.env.MARINA_TELEGRAM_ID = "1112785891";

  try {
    assert.equal(resolveTelegramOperatorId("1112785891"), MARINA_OPERATOR_ID);
    assert.equal(isAllowedOperatorId(MARINA_OPERATOR_ID), true);
    assert.equal(resolveTelegramOperatorId("1112785892"), null);

    const marinaOverview = getOperatorAccessOverview().find(
      (operator) => operator.operatorId === MARINA_OPERATOR_ID,
    );
    assert.equal(marinaOverview?.configured, true);
    assert.equal(marinaOverview?.accessMode, "assigned");
    assert.equal(marinaOverview?.restaurantCount, 14);
  } finally {
    if (previousMarinaId === undefined) delete process.env.MARINA_TELEGRAM_ID;
    else process.env.MARINA_TELEGRAM_ID = previousMarinaId;
  }
});
