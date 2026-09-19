import assert from "node:assert/strict";
import test from "node:test";
import {
  evaluateCheckinScoutPhysicalHistory,
  summarizeCheckinScoutSnapshot,
  type CheckinScoutObservation,
} from "./anti-fraud-checkin-scout-rules";

const record = (
  bitrixUserId: number,
  sourceRestisId: string,
  occurredAt: string,
): CheckinScoutObservation => ({
  bitrixUserId,
  sourceRestisId,
  occurredAt: new Date(occurredAt),
});

// Добавлено 19.09.2026 ИТ Директор Евразии
test("Scout does not persist normal one-checkin-per-day users", () => {
  const summary = summarizeCheckinScoutSnapshot([
    record(100, "a1", "2026-09-17T09:00:00.000Z"),
    record(100, "a2", "2026-09-18T09:00:00.000Z"),
    record(100, "a3", "2026-09-19T09:00:00.000Z"),
  ]);

  assert.deepEqual(summary, []);
});

test("Scout creates a candidate only at two checkins in the same Moscow day", () => {
  const summary = summarizeCheckinScoutSnapshot([
    record(100, "normal", "2026-09-19T07:00:00.000Z"),
    record(200, "b1", "2026-09-19T08:00:00.000Z"),
    record(200, "b2", "2026-09-19T12:00:00.000Z"),
  ]);

  assert.deepEqual(summary, [
    {
      bitrixUserId: 200,
      days: [{ day: "2026-09-19", checkins: 2 }],
    },
  ]);
});

test("Scout keeps repeated 2+ days and deduplicates the same event id", () => {
  const summary = summarizeCheckinScoutSnapshot([
    record(300, "c1", "2026-09-17T08:00:00.000Z"),
    record(300, "c2", "2026-09-17T11:00:00.000Z"),
    record(300, "c3", "2026-09-18T08:00:00.000Z"),
    record(300, "c4", "2026-09-18T11:00:00.000Z"),
    record(300, "c4", "2026-09-18T11:00:00.000Z"),
  ]);

  assert.deepEqual(summary, [
    {
      bitrixUserId: 300,
      days: [
        { day: "2026-09-17", checkins: 2 },
        { day: "2026-09-18", checkins: 2 },
      ],
    },
  ]);
});

test("Scout groups timestamps by Europe/Moscow calendar day", () => {
  const summary = summarizeCheckinScoutSnapshot([
    // 21:30Z = 00:30 next Moscow day.
    record(400, "d1", "2026-09-18T21:30:00.000Z"),
    record(400, "d2", "2026-09-19T08:00:00.000Z"),
  ]);

  assert.deepEqual(summary, [
    {
      bitrixUserId: 400,
      days: [{ day: "2026-09-19", checkins: 2 }],
    },
  ]);
});


test("physical 60-day history confirms 3 days with 2+ in the last 7 days", () => {
  const history = [
    record(27987, "n1", "2026-09-15T09:00:00.000Z"),
    record(27987, "n2", "2026-09-15T10:00:00.000Z"),
    record(27987, "n3", "2026-09-16T09:00:00.000Z"),
    record(27987, "n4", "2026-09-16T10:00:00.000Z"),
    record(27987, "n5", "2026-09-17T09:00:00.000Z"),
    record(27987, "n6", "2026-09-17T10:00:00.000Z"),
    record(27987, "n7", "2026-09-17T11:00:00.000Z"),
  ];

  const result = evaluateCheckinScoutPhysicalHistory(
    history,
    new Date("2026-09-19T08:00:00.000Z"),
  );

  assert.equal(result.days2Plus7d, 3);
  assert.equal(result.days3Plus60d, 1);
  assert.equal(result.confirmed, true);
});

test("physical 60-day history keeps a single 2-checkin day unconfirmed", () => {
  const history = [
    record(1969724, "k1", "2026-09-18T18:00:00.000Z"),
    record(1969724, "k2", "2026-09-18T19:30:00.000Z"),
  ];

  const result = evaluateCheckinScoutPhysicalHistory(
    history,
    new Date("2026-09-19T08:00:00.000Z"),
  );

  assert.equal(result.days2Plus7d, 1);
  assert.equal(result.days3Plus60d, 0);
  assert.equal(result.confirmed, false);
});

test("physical history confirms three 3+ days even outside the last 7 days", () => {
  const history = [
    record(500, "x1", "2026-08-01T08:00:00.000Z"),
    record(500, "x2", "2026-08-01T09:00:00.000Z"),
    record(500, "x3", "2026-08-01T10:00:00.000Z"),
    record(500, "y1", "2026-08-10T08:00:00.000Z"),
    record(500, "y2", "2026-08-10T09:00:00.000Z"),
    record(500, "y3", "2026-08-10T10:00:00.000Z"),
    record(500, "z1", "2026-08-20T08:00:00.000Z"),
    record(500, "z2", "2026-08-20T09:00:00.000Z"),
    record(500, "z3", "2026-08-20T10:00:00.000Z"),
  ];

  const result = evaluateCheckinScoutPhysicalHistory(
    history,
    new Date("2026-09-19T08:00:00.000Z"),
  );

  assert.equal(result.days2Plus7d, 0);
  assert.equal(result.days3Plus60d, 3);
  assert.equal(result.confirmed, true);
});

test("physical history rejects mixed USER_ID payloads", () => {
  assert.throws(
    () => evaluateCheckinScoutPhysicalHistory([
      record(1, "a", "2026-09-18T09:00:00.000Z"),
      record(2, "b", "2026-09-18T10:00:00.000Z"),
    ]),
    /одному USER_ID/,
  );
});
