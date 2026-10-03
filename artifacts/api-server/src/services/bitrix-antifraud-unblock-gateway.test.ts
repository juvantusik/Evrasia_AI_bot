import assert from "node:assert/strict";
import test from "node:test";
import { pool } from "@workspace/db";
import { BitrixAntiFraudUnblockGateway } from "./bitrix-antifraud-unblock-gateway";
import { unblockAntiFraudAccounts } from "./anti-fraud-unblock-service";

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

type CapturedQuery = {
  text: string;
  values: unknown[] | undefined;
};

const withCapturedPoolQueries = async (
  action: (queries: CapturedQuery[]) => Promise<void>,
): Promise<void> => {
  const mutablePool = pool as unknown as {
    query: (text: string, values?: unknown[]) => Promise<unknown>;
  };
  const originalQuery = mutablePool.query;
  const queries: CapturedQuery[] = [];

  mutablePool.query = async (text: string, values?: unknown[]) => {
    queries.push({ text, values });
    return { rows: [], rowCount: 1 };
  };

  try {
    await action(queries);
  } finally {
    mutablePool.query = originalQuery;
  }
};

test("Anti-Fraud unblock immediately strips only the exact trailing IT marker from local cache", async () => {
  const gateway = new BitrixAntiFraudUnblockGateway({
    token: "x".repeat(64),
    fetchImpl: async () => new Response(JSON.stringify({
      ok: true,
      dry_run: false,
      requested: 1,
      resolved: 1,
      unresolved: [],
      records: [{
        bitrix_user_id: 881346,
        before: { active: true, blocked: true, block_reason: reason },
        after: { active: true, blocked: false, block_reason: reason },
        already_unblocked: false,
        changed: true,
        success: true,
        result: "unblocked",
      }],
    }), { status: 200, headers: { "Content-Type": "application/json" } }),
  });

  await withCapturedPoolQueries(async (queries) => {
    const result = await unblockAntiFraudAccounts([881346], "case-regression", { gateway });

    assert.equal(result.ok, true);
    const update = queries.find((query) => query.text.includes("UPDATE anti_fraud_accounts"));
    assert.ok(update);
    assert.match(update.text, /display_name = CASE/);
    assert.match(
      update.text,
      /right\(display_name, char_length\(\$6::text\)\) = \$6::text/,
    );
    assert.match(
      update.text,
      /left\(display_name, char_length\(display_name\) - char_length\(\$6::text\)\)/,
    );
    assert.equal(update.values?.[4], true);
    assert.equal(update.values?.[5], " - блок ИТ");
  });
});

test("Anti-Fraud idempotent already-unblocked result does not rewrite the local name", async () => {
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

  await withCapturedPoolQueries(async (queries) => {
    const result = await unblockAntiFraudAccounts([102], "case-idempotent", { gateway });

    assert.equal(result.ok, true);
    const update = queries.find((query) => query.text.includes("UPDATE anti_fraud_accounts"));
    assert.ok(update);
    assert.equal(update.values?.[4], false);
    assert.equal(update.values?.[5], " - блок ИТ");
  });
});
