import assert from "node:assert/strict";
import test from "node:test";
import { BitrixAntiFraudBlockGateway } from "./bitrix-antifraud-block-gateway";

const reason = "По результатам проведенной проверки подтверждено нарушение Правил программы лояльности «Бонусный Клуб Евразия», квалифицированное как недобросовестное использование Программы. В соответствии с п. 3.9 Правил применена блокировка учетной записи и связанных с ней возможностей участия в Программе.";

test("Bitrix block gateway sends protected write request and parses per-user results", async () => {
  let tokenHeader = "";
  let requestBody: Record<string, unknown> | undefined;

  const gateway = new BitrixAntiFraudBlockGateway({
    token: "x".repeat(64),
    fetchImpl: async (_input, init) => {
      tokenHeader = new Headers(init?.headers).get("X-Anti-Fraud-Token") ?? "";
      requestBody = JSON.parse(String(init?.body)) as Record<string, unknown>;
      return new Response(JSON.stringify({
        ok: true,
        dry_run: false,
        reason,
        requested: 2,
        resolved: 2,
        unresolved: [],
        records: [
          {
            bitrix_user_id: 101,
            before: { active: true, blocked: false, block_reason: null },
            after: { active: false, blocked: true, block_reason: reason },
            already_blocked: false,
            changed: true,
            success: true,
            result: "blocked",
          },
          {
            bitrix_user_id: 102,
            before: { active: false, blocked: true, block_reason: "Внешняя блокировка" },
            after: { active: false, blocked: true, block_reason: "Внешняя блокировка" },
            already_blocked: true,
            changed: false,
            success: true,
            result: "already_blocked",
          },
        ],
      }), { status: 200, headers: { "Content-Type": "application/json" } });
    },
  });

  const result = await gateway.blockAccounts([101, 102, 101]);

  assert.equal(tokenHeader, "x".repeat(64));
  assert.deepEqual(requestBody, { user_ids: [101, 102], dry_run: false });
  assert.equal(result.requested, 2);
  assert.equal(result.records[0]?.result, "blocked");
  assert.equal(result.records[0]?.after?.blockReason, reason);
  assert.equal(result.records[1]?.result, "already_blocked");
  assert.equal(result.records[1]?.changed, false);
});

test("Bitrix block gateway preserves partial per-user result when source ok=false", async () => {
  const gateway = new BitrixAntiFraudBlockGateway({
    token: "x".repeat(64),
    fetchImpl: async () => new Response(JSON.stringify({
      ok: false,
      dry_run: false,
      reason,
      requested: 2,
      resolved: 2,
      unresolved: [],
      records: [
        {
          bitrix_user_id: 101,
          before: { active: true, blocked: false, block_reason: null },
          after: { active: false, blocked: true, block_reason: reason },
          already_blocked: false,
          changed: true,
          success: true,
          result: "blocked",
        },
        {
          bitrix_user_id: 102,
          before: { active: true, blocked: false, block_reason: null },
          after: null,
          already_blocked: false,
          changed: false,
          success: false,
          result: "update_failed",
        },
      ],
    }), { status: 200, headers: { "Content-Type": "application/json" } }),
  });

  const result = await gateway.blockAccounts([101, 102]);
  assert.equal(result.records[0]?.success, true);
  assert.equal(result.records[1]?.success, false);
  assert.equal(result.records[1]?.result, "update_failed");
});

test("Bitrix block gateway rejects HTTP failures without echoing server body", async () => {
  const secret = "AF-SECRET-CASE-ID";
  const gateway = new BitrixAntiFraudBlockGateway({
    token: "x".repeat(64),
    fetchImpl: async () => new Response(`server diagnostic ${secret}`, { status: 500 }),
  });

  await assert.rejects(
    gateway.blockAccounts([101]),
    (error: unknown) => {
      assert.ok(error instanceof Error);
      assert.match(error.message, /HTTP 500/);
      assert.doesNotMatch(error.message, new RegExp(secret));
      return true;
    },
  );
});
