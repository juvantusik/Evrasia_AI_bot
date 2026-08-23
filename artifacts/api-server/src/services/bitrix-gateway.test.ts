import assert from "node:assert/strict";
import test from "node:test";
import { BitrixHttpGateway } from "./bitrix-gateway";
import type { RestaurantDirectoryEntry } from "./restaurant-directory";

const restaurant: RestaurantDirectoryEntry = {
  id: "307",
  name: "Славы 43",
  address: "Славы 43",
  operatorId: "operator",
  operatorName: "Оператор",
};

test("Bitrix gateway updates an existing personal rule", async () => {
  let requestBody: Record<string, unknown> | undefined;
  let authorization = "";

  const gateway = new BitrixHttpGateway({
    apiUrl: "https://order.evrasia.rest/api/internal/samzaberu/",
    apiToken: "test-token",
    fetchImpl: async (_input, init) => {
      authorization = new Headers(init?.headers).get("Authorization") ?? "";
      requestBody = JSON.parse(String(init?.body)) as Record<string, unknown>;
      return new Response(
        JSON.stringify({
          ok: true,
          state: {
            restaurant: { id: 307, name: "пр. Славы, 43", active: true },
            blocked: true,
            personal_rules: [
              { id: 2052559, effective: true, blocks_orders: true },
            ],
            group_rules: [],
          },
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    },
  });

  const until = new Date("2026-08-24T19:00:00.000Z");
  const result = await gateway.applyStop(restaurant, until, false);

  assert.equal(result.ruleId, "2052559");
  assert.equal(authorization, "Bearer test-token");
  assert.deepEqual(requestBody, {
    action: "stop",
    restaurant_id: 307,
    until: until.toISOString(),
  });
});

test("Bitrix gateway confirms that enable removed the effective block", async () => {
  const gateway = new BitrixHttpGateway({
    apiUrl: "https://order.evrasia.rest/api/internal/samzaberu/",
    apiToken: "test-token",
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          state: {
            restaurant: { id: 307, name: "пр. Славы, 43", active: true },
            blocked: false,
            personal_rules: [
              { id: 2052559, effective: false, blocks_orders: false },
            ],
            group_rules: [],
          },
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      ),
  });

  await gateway.applyEnable(restaurant, false);
});

test("Bitrix gateway propagates a protected group-rule conflict", async () => {
  const gateway = new BitrixHttpGateway({
    apiUrl: "https://order.evrasia.rest/api/internal/samzaberu/",
    apiToken: "test-token",
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: false,
          error: "group_rule_blocks_restaurant",
          message: "Restaurant is blocked by a group rule",
        }),
        { status: 409, headers: { "Content-Type": "application/json" } },
      ),
  });

  await assert.rejects(
    gateway.applyEnable(restaurant, false),
    /group rule/,
  );
});

test("Bitrix gateway never calls the API for simulated failures", async () => {
  let called = false;
  const gateway = new BitrixHttpGateway({
    apiUrl: "https://order.evrasia.rest/api/internal/samzaberu/",
    apiToken: "test-token",
    fetchImpl: async () => {
      called = true;
      throw new Error("unexpected request");
    },
  });

  await assert.rejects(
    gateway.applyStop(restaurant, new Date(Date.now() + 60_000), true),
    /Имитация сбоя/,
  );
  assert.equal(called, false);
});
