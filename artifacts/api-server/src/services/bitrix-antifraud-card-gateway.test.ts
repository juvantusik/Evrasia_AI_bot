import assert from "node:assert/strict";
import test from "node:test";
import { BitrixAntiFraudCardGateway } from "./bitrix-antifraud-card-gateway";

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Bitrix Anti-Fraud gateway sends X-Anti-Fraud-Token and parses card-map", async () => {
  let tokenHeader = "";
  let requestBody: Record<string, unknown> | undefined;

  const gateway = new BitrixAntiFraudCardGateway({
    apiUrl: "https://evrasia.rest/api/internal/anti-fraud/card-map",
    token: "x".repeat(64),
    fetchImpl: async (_input, init) => {
      tokenHeader = new Headers(init?.headers).get("X-Anti-Fraud-Token") ?? "";
      requestBody = JSON.parse(String(init?.body)) as Record<string, unknown>;
      return new Response(
        JSON.stringify({
          ok: true,
          records: [
            {
              card_number: "17888709",
              bitrix_user_id: 101632,
              card_type: 8,
              card_status: "Активна",
              card_status_id: 113,
              is_active: true,
            },
          ],
          unresolved: ["11112222"],
          ambiguous: [],
          requested: 2,
          resolved: 1,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    },
  });

  const result = await gateway.resolveCards(["17888709", "11112222", "17888709"]);

  assert.equal(tokenHeader, "x".repeat(64));
  assert.deepEqual(requestBody, { cards: ["17888709", "11112222"] });
  assert.equal(result.requested, 2);
  assert.equal(result.resolved, 1);
  assert.equal(result.records[0]?.bitrixUserId, 101632);
  assert.deepEqual(result.unresolved, ["11112222"]);
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Bitrix Anti-Fraud gateway accepts zero resolved cards", async () => {
  const gateway = new BitrixAntiFraudCardGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          records: [],
          unresolved: ["11112222"],
          ambiguous: [],
          requested: 1,
          resolved: 0,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      ),
  });

  const result = await gateway.resolveCards(["11112222"]);
  assert.equal(result.resolved, 0);
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Bitrix Anti-Fraud gateway rejects duplicate classification without exposing CARD_NO", async () => {
  const gateway = new BitrixAntiFraudCardGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          records: [
            {
              card_number: "17888709",
              bitrix_user_id: 101632,
              card_type: 8,
              card_status: "Активна",
              card_status_id: 113,
              is_active: true,
            },
          ],
          unresolved: ["17888709"],
          ambiguous: [],
          requested: 1,
          resolved: 1,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      ),
  });

  await assert.rejects(
    gateway.resolveCards(["17888709"]),
    (error: unknown) => {
      assert.ok(error instanceof Error);
      assert.match(error.message, /повторную карту/);
      assert.doesNotMatch(error.message, /17888709/);
      return true;
    },
  );
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Bitrix Anti-Fraud gateway does not expose HTTP response body in errors", async () => {
  const gateway = new BitrixAntiFraudCardGateway({
    token: "x".repeat(64),
    fetchImpl: async () => new Response("secret diagnostic body", { status: 401 }),
  });

  await assert.rejects(
    gateway.resolveCards(["17888709"]),
    (error: unknown) => {
      assert.ok(error instanceof Error);
      assert.match(error.message, /HTTP 401/);
      assert.doesNotMatch(error.message, /secret diagnostic body/);
      return true;
    },
  );
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Bitrix Anti-Fraud gateway does not expose malformed CARD_NO in errors", async () => {
  const malformed = "123-secret-card";
  const gateway = new BitrixAntiFraudCardGateway({ token: "x".repeat(64) });

  await assert.rejects(
    gateway.resolveCards([malformed]),
    (error: unknown) => {
      assert.ok(error instanceof Error);
      assert.match(error.message, /Некорректный номер карты/);
      assert.doesNotMatch(error.message, new RegExp(malformed));
      return true;
    },
  );
});
