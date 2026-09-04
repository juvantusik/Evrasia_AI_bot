import assert from "node:assert/strict";
import test from "node:test";
import { assertTrustedDeviceLinkSnapshotSafe } from "./trusted-device-snapshot-guard";

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Trusted Device snapshot guard allows the first import and normal snapshots", () => {
  assert.doesNotThrow(() => assertTrustedDeviceLinkSnapshotSafe(0, 0));
  assert.doesNotThrow(() => assertTrustedDeviceLinkSnapshotSafe(0, 1420));
  assert.doesNotThrow(() => assertTrustedDeviceLinkSnapshotSafe(1420, 1420));
  assert.doesNotThrow(() => assertTrustedDeviceLinkSnapshotSafe(1420, 710));
  assert.doesNotThrow(() => assertTrustedDeviceLinkSnapshotSafe(3, 2));
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Trusted Device snapshot guard blocks empty and more-than-half shrink", () => {
  assert.throws(
    () => assertTrustedDeviceLinkSnapshotSafe(1420, 0),
    /existing=1420; fetched=0/,
  );
  assert.throws(
    () => assertTrustedDeviceLinkSnapshotSafe(1420, 709),
    /existing=1420; fetched=709/,
  );
  assert.throws(
    () => assertTrustedDeviceLinkSnapshotSafe(3, 1),
    /existing=3; fetched=1/,
  );
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Trusted Device snapshot guard rejects invalid counters", () => {
  assert.throws(() => assertTrustedDeviceLinkSnapshotSafe(-1, 0), /некорректные счётчики/);
  assert.throws(() => assertTrustedDeviceLinkSnapshotSafe(1.5, 1), /некорректные счётчики/);
});
