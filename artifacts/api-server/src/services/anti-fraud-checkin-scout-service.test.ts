import assert from "node:assert/strict";
import test from "node:test";
import {
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
