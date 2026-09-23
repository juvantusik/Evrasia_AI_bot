import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import {
  buildAntiFraudOperatorPhysicalHistorySnapshot,
} from "./anti-fraud-operator-physical-history-service";
import type { BitrixAntiFraudCheckinResult } from "./bitrix-antifraud-checkin-gateway";

const result = (
  records: BitrixAntiFraudCheckinResult["records"],
): BitrixAntiFraudCheckinResult => ({
  days: 60,
  from: new Date("2026-07-25T09:00:00+03:00"),
  to: new Date("2026-09-23T09:00:00+03:00"),
  records,
  unresolvedCardCount: 0,
});

test("operator physical snapshot keeps targeted records without touching card data", () => {
  const snapshot = buildAntiFraudOperatorPhysicalHistorySnapshot(
    result([
      {
        sourceRestisId: "offline:physical-a",
        bitrixUserId: 6645,
        occurredAt: new Date("2026-09-19T16:19:40+03:00"),
        restaurant: "Грибоедова 10",
      },
      {
        sourceRestisId: "offline:physical-b",
        bitrixUserId: 6645,
        occurredAt: new Date("2026-09-18T16:31:23+03:00"),
        restaurant: "Московский 222",
      },
    ]),
    6645,
  );

  assert.equal(snapshot.records.length, 2);
  assert.deepEqual(
    snapshot.records.map((record) => record.physicalEventId),
    ["offline:physical-a", "offline:physical-b"],
  );
  assert.ok(snapshot.records.every((record) => !("cardId" in record)));
});

test("operator physical snapshot records a successful empty window", () => {
  const snapshot = buildAntiFraudOperatorPhysicalHistorySnapshot(result([]), 6645);

  assert.equal(snapshot.records.length, 0);
  assert.equal(snapshot.from.toISOString(), "2026-07-25T06:00:00.000Z");
  assert.equal(snapshot.to.toISOString(), "2026-09-23T06:00:00.000Z");
});

test("operator physical snapshot rejects a USER_ID outside the investigation", () => {
  assert.throws(
    () => buildAntiFraudOperatorPhysicalHistorySnapshot(
      result([
        {
          sourceRestisId: "offline:wrong-user",
          bitrixUserId: 999999,
          occurredAt: new Date("2026-09-19T16:19:40+03:00"),
          restaurant: "Тест",
        },
      ]),
      6645,
    ),
    /USER_ID вне операторского расследования/,
  );
});

test("operator physical snapshot rejects duplicate event ids defensively", () => {
  assert.throws(
    () => buildAntiFraudOperatorPhysicalHistorySnapshot(
      result([
        {
          sourceRestisId: "offline:duplicate",
          bitrixUserId: 6645,
          occurredAt: new Date("2026-09-19T16:19:40+03:00"),
          restaurant: "Тест",
        },
        {
          sourceRestisId: "offline:duplicate",
          bitrixUserId: 6645,
          occurredAt: new Date("2026-09-19T17:19:40+03:00"),
          restaurant: "Тест",
        },
      ]),
      6645,
    ),
    /повторный event id/,
  );
});

test("operator physical snapshot service never writes automatic anti_fraud_visits telemetry", async () => {
  const source = await readFile(
    new URL("./anti-fraud-operator-physical-history-service.ts", import.meta.url),
    "utf8",
  );

  assert.doesNotMatch(source, /INSERT\s+INTO\s+anti_fraud_visits/i);
  assert.doesNotMatch(source, /UPDATE\s+anti_fraud_visits/i);
  assert.doesNotMatch(source, /DELETE\s+FROM\s+anti_fraud_visits/i);
  assert.match(source, /anti_fraud_operator_investigation_visits/);
});

test("operator physical snapshot fails closed when targeted history has unresolved cards", () => {
  const history = result([]);
  history.unresolvedCardCount = 1;

  assert.throws(
    () => buildAntiFraudOperatorPhysicalHistorySnapshot(history, 6645),
    /не разрешила все карты/,
  );
});
