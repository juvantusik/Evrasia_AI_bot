import assert from "node:assert/strict";
import test from "node:test";
import { BitrixAntiFraudCheckinGateway } from "./bitrix-antifraud-checkin-gateway";

const responseBody = (days: number, bitrixUserId: number) => ({
  ok: true,
  days,
  from: "2026-07-21T00:00:00+03:00",
  to: "2026-09-19T10:00:00+03:00",
  records: [
    {
      source_restis_id: "offline:" + "a".repeat(64),
      bitrix_user_id: bitrixUserId,
      occurred_at: "2026-09-18T22:30:14+03:00",
      restaurant: "Тест",
    },
  ],
  unresolved_card_count: 0,
});

// Добавлено 19.09.2026 ИТ Директор Евразии
test("Check-in gateway requests the 3-day full snapshot without user_ids", async () => {
  let body: Record<string, unknown> | null = null;

  const gateway = new BitrixAntiFraudCheckinGateway({
    token: "x".repeat(64),
    fetchImpl: async (_input, init) => {
      body = JSON.parse(String(init?.body)) as Record<string, unknown>;
      return new Response(
        JSON.stringify(responseBody(3, 27987)),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    },
  });

  const result = await gateway.fetchRecentCheckins();

  assert.deepEqual(body, { days: 3 });
  assert.equal(result.records[0]?.bitrixUserId, 27987);
});

test("Check-in gateway requests targeted 60-day physical history", async () => {
  let body: Record<string, unknown> | null = null;

  const gateway = new BitrixAntiFraudCheckinGateway({
    token: "x".repeat(64),
    fetchImpl: async (_input, init) => {
      body = JSON.parse(String(init?.body)) as Record<string, unknown>;
      return new Response(
        JSON.stringify(responseBody(60, 1969724)),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    },
  });

  const result = await gateway.fetchUserHistory(1969724, 60);

  assert.deepEqual(body, {
    days: 60,
    user_ids: [1969724],
  });
  assert.equal(result.days, 60);
  assert.equal(result.records[0]?.bitrixUserId, 1969724);
});

test("Check-in gateway rejects an unexpected USER_ID in targeted history", async () => {
  const gateway = new BitrixAntiFraudCheckinGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify(responseBody(60, 999999)),
        { status: 200, headers: { "Content-Type": "application/json" } },
      ),
  });

  await assert.rejects(
    gateway.fetchUserHistory(1969724, 60),
    /вне адресного запроса/,
  );
});

test("Check-in gateway does not expose HTTP response body in errors", async () => {
  const gateway = new BitrixAntiFraudCheckinGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response("secret-checkin-data", { status: 500 }),
  });

  await assert.rejects(
    gateway.fetchRecentCheckins(),
    (error: unknown) => {
      assert.ok(error instanceof Error);
      assert.match(error.message, /HTTP 500/);
      assert.doesNotMatch(error.message, /secret-checkin-data/);
      return true;
    },
  );
});
