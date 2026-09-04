import assert from "node:assert/strict";
import test from "node:test";
import { BitrixAntiFraudDeviceGateway } from "./bitrix-antifraud-device-gateway";

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Trusted Device gateway sends token and parses links page", async () => {
  let tokenHeader = "";
  let requestBody: Record<string, unknown> | undefined;

  const gateway = new BitrixAntiFraudDeviceGateway({
    token: "x".repeat(64),
    fetchImpl: async (_input, init) => {
      tokenHeader = new Headers(init?.headers).get("X-Anti-Fraud-Token") ?? "";
      requestBody = JSON.parse(String(init?.body)) as Record<string, unknown>;
      return new Response(
        JSON.stringify({
          ok: true,
          stream: "links",
          records: [
            {
              source_link_id: "123",
              bitrix_user_id: 120445,
              device_hash: "a".repeat(64),
              status: "1",
              client_type: "web",
              created_at: "2026-09-03T18:52:03+03:00",
              last_seen_at: "2026-09-04T13:32:26+03:00",
              updated_at: "2026-09-04T13:32:26+03:00",
            },
          ],
          next_cursor: "123",
          has_more: false,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    },
  });

  const page = await gateway.fetchLinksPage();

  assert.equal(tokenHeader, "x".repeat(64));
  assert.deepEqual(requestBody, { stream: "links", after_id: null, limit: 1000 });
  assert.equal(page.records.length, 1);
  assert.equal(page.records[0]?.sourceLinkId, "123");
  assert.equal(page.records[0]?.bitrixUserId, 120445);
  assert.equal(page.records[0]?.deviceHash, "a".repeat(64));
  assert.equal(page.records[0]?.status, "1");
  assert.equal(page.nextCursor, "123");
  assert.equal(page.hasMore, false);
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Trusted Device gateway parses incremental events without PII", async () => {
  const gateway = new BitrixAntiFraudDeviceGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          stream: "events",
          records: [
            {
              source_event_id: "456",
              bitrix_user_id: 415307,
              device_hash: "b".repeat(64),
              event_type: "login_success",
              auth_method: "password",
              client_type: null,
              occurred_at: "2026-09-03T19:07:44+03:00",
            },
          ],
          next_cursor: "456",
          has_more: false,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      ),
  });

  const page = await gateway.fetchEventsPage("455");
  assert.equal(page.records[0]?.sourceEventId, "456");
  assert.equal(page.records[0]?.eventType, "login_success");
  assert.equal(page.records[0]?.authMethod, "password");
  assert.equal(page.records[0]?.clientType, null);
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Trusted Device gateway rejects malformed device hash", async () => {
  const gateway = new BitrixAntiFraudDeviceGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          stream: "events",
          records: [
            {
              source_event_id: "456",
              bitrix_user_id: 415307,
              device_hash: "not-a-hash",
              event_type: "login_success",
              auth_method: null,
              client_type: null,
              occurred_at: "2026-09-03T19:07:44+03:00",
            },
          ],
          next_cursor: "456",
          has_more: false,
        }),
        { status: 200 },
      ),
  });

  await assert.rejects(gateway.fetchEventsPage(), /device_hash/);
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Trusted Device gateway does not expose HTTP response body", async () => {
  const gateway = new BitrixAntiFraudDeviceGateway({
    token: "x".repeat(64),
    fetchImpl: async () => new Response("secret-user-data", { status: 500 }),
  });

  await assert.rejects(
    gateway.fetchEventsPage(),
    (error: unknown) => {
      assert.ok(error instanceof Error);
      assert.match(error.message, /HTTP 500/);
      assert.doesNotMatch(error.message, /secret-user-data/);
      return true;
    },
  );
});
