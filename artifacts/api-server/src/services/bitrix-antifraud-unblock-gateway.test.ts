import assert from "node:assert/strict";
import test from "node:test";
import {
  buildAntiFraudUnblockAccountCacheUpdate,
  IT_BLOCK_NAME_SUFFIX,
  shouldStripAntiFraudUnblockNameSuffix,
} from "./anti-fraud-unblock-cache";
import { BitrixAntiFraudUnblockGateway } from "./bitrix-antifraud-unblock-gateway";

const reason = "Историческое основание блокировки";

test("Bitrix unblock gateway sends protected write request and preserves reason", async () => {
  let tokenHeader = "";
  let requestBody: Record<string, unknown> | undefined;

  const gateway = new BitrixAntiFraudUnblockGateway({
    token: "x".repeat(64),
    fetchImpl: async (_input, init) => {
      tokenHeader = new Headers(init?.headers).get("X-Anti-Fraud-Token") ?? "";
      requestBody = JSON.parse(String(init?.body)) as Record<string, unknown>;
      return new Response(JSON.stringify({
        ok: true,
        dry_run: false,
        requested: 1,
        resolved: 1,
        unresolved: [],
        records: [{
          bitrix_user_id: 101,
          before: { active: false, blocked: true, block_reason: reason },
          after: { active: true, blocked: false, block_reason: reason },
          already_unblocked: false,
          changed: true,
          success: true,
          result: "unblocked",
        }],
      }), { status: 200, headers: { "Content-Type": "application/json" } });
    },
  });

  const result = await gateway.unblockAccounts([101, 101]);
  assert.equal(tokenHeader, "x".repeat(64));
  assert.deepEqual(requestBody, { user_ids: [101], dry_run: false });
  assert.equal(result.records[0]?.after?.active, true);
  assert.equal(result.records[0]?.after?.blocked, false);
  assert.equal(result.records[0]?.after?.blockReason, reason);
});

test("Bitrix unblock gateway accepts idempotent already-unblocked result", async () => {
  const gateway = new BitrixAntiFraudUnblockGateway({
    token: "x".repeat(64),
    fetchImpl: async () => new Response(JSON.stringify({
      ok: true,
      dry_run: false,
      requested: 1,
      resolved: 1,
      unresolved: [],
      records: [{
        bitrix_user_id: 102,
        before: { active: true, blocked: false, block_reason: reason },
        after: { active: true, blocked: false, block_reason: reason },
        already_unblocked: true,
        changed: false,
        success: true,
        result: "already_unblocked",
      }],
    }), { status: 200, headers: { "Content-Type": "application/json" } }),
  });

  const result = await gateway.unblockAccounts([102]);
  assert.equal(result.records[0]?.alreadyUnblocked, true);
  assert.equal(result.records[0]?.changed, false);
});

test("Bitrix unblock gateway rejects HTTP failures without echoing server body", async () => {
  const secret = "PRIVATE-BITRIX-DIAGNOSTIC";
  const gateway = new BitrixAntiFraudUnblockGateway({
    token: "x".repeat(64),
    fetchImpl: async () => new Response(`server diagnostic ${secret}`, { status: 500 }),
  });

  await assert.rejects(
    gateway.unblockAccounts([101]),
    (error: unknown) => {
      assert.ok(error instanceof Error);
      assert.match(error.message, /HTTP 500/);
      assert.doesNotMatch(error.message, new RegExp(secret));
      return true;
    },
  );
});

test("Anti-Fraud unblock cache update strips only the exact trailing IT marker", () => {
  const strip = shouldStripAntiFraudUnblockNameSuffix(true, "unblocked");
  assert.equal(strip, true);

  const update = buildAntiFraudUnblockAccountCacheUpdate(
    881346,
    true,
    false,
    reason,
    strip,
  );

  assert.match(update.text, /display_name = CASE/);
  assert.match(
    update.text,
    /right\(display_name, char_length\(\$6::text\)\) = \$6::text/,
  );
  assert.match(
    update.text,
    /left\(display_name, char_length\(display_name\) - char_length\(\$6::text\)\)/,
  );
  assert.deepEqual(update.values, [
    881346,
    true,
    false,
    reason,
    true,
    IT_BLOCK_NAME_SUFFIX,
  ]);
});

test("Anti-Fraud idempotent already-unblocked result does not rewrite the local name", () => {
  assert.equal(
    shouldStripAntiFraudUnblockNameSuffix(true, "already_unblocked"),
    false,
  );
  assert.equal(
    shouldStripAntiFraudUnblockNameSuffix(false, "unblocked"),
    false,
  );
});
