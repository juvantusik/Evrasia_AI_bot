import assert from "node:assert/strict";
import test from "node:test";
import {
  BitrixAntiFraudPhoneResolverError,
  BitrixAntiFraudPhoneResolverGateway,
} from "./bitrix-antifraud-phone-resolver-gateway";

test("Phone resolver gateway sends exact production request field and parses unique USER_ID", async () => {
  let requestBody: Record<string, unknown> | null = null;
  let tokenHeader = "";

  const gateway = new BitrixAntiFraudPhoneResolverGateway({
    token: "x".repeat(64),
    fetchImpl: async (_input, init) => {
      requestBody = JSON.parse(String(init?.body)) as Record<string, unknown>;
      tokenHeader = new Headers(init?.headers).get("X-Anti-Fraud-Token") ?? "";
      return new Response(
        JSON.stringify({
          ok: true,
          status: "unique",
          bitrix_user_id: 1969724,
          active: true,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    },
  });

  const result = await gateway.resolvePhone("+7 921 903-46-92");

  assert.deepEqual(requestBody, { phone: "+7 921 903-46-92" });
  assert.equal(tokenHeader, "x".repeat(64));
  assert.deepEqual(result, { bitrixUserId: 1969724 });
});

test("Phone resolver gateway rejects invalid phone before protected request", async () => {
  let called = false;
  const gateway = new BitrixAntiFraudPhoneResolverGateway({
    token: "x".repeat(64),
    fetchImpl: async () => {
      called = true;
      return new Response("", { status: 500 });
    },
  });

  await assert.rejects(
    gateway.resolvePhone("12"),
    (error: unknown) => {
      assert.ok(error instanceof BitrixAntiFraudPhoneResolverError);
      assert.equal(error.httpStatus, 400);
      return true;
    },
  );
  assert.equal(called, false);
});

test("Phone resolver gateway preserves not-found semantics", async () => {
  const gateway = new BitrixAntiFraudPhoneResolverGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({ ok: false, status: "not_found", error: "private-detail" }),
        { status: 404 },
      ),
  });

  await assert.rejects(
    gateway.resolvePhone("+7 999 123-45-67"),
    (error: unknown) => {
      assert.ok(error instanceof BitrixAntiFraudPhoneResolverError);
      assert.equal(error.httpStatus, 404);
      assert.doesNotMatch(error.message, /private-detail/);
      return true;
    },
  );
});

test("Phone resolver gateway returns only safe ambiguous match count", async () => {
  const gateway = new BitrixAntiFraudPhoneResolverGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: false,
          status: "ambiguous",
          match_count: 3,
          matches: [{ bitrix_user_id: 1, phone: "secret" }],
        }),
        { status: 409 },
      ),
  });

  await assert.rejects(
    gateway.resolvePhone("+7 999 123-45-67"),
    (error: unknown) => {
      assert.ok(error instanceof BitrixAntiFraudPhoneResolverError);
      assert.equal(error.httpStatus, 409);
      assert.equal(error.matchCount, 3);
      assert.doesNotMatch(error.message, /secret/);
      return true;
    },
  );
});

test("Phone resolver gateway maps protected auth and unexpected HTTP failures to safe 503", async () => {
  for (const status of [401, 500]) {
    const gateway = new BitrixAntiFraudPhoneResolverGateway({
      token: "x".repeat(64),
      fetchImpl: async () =>
        new Response("token=super-secret phone=79990001122", { status }),
    });

    await assert.rejects(
      gateway.resolvePhone("+7 999 123-45-67"),
      (error: unknown) => {
        assert.ok(error instanceof BitrixAntiFraudPhoneResolverError);
        assert.equal(error.httpStatus, 503);
        assert.doesNotMatch(error.message, /super-secret|79990001122/);
        return true;
      },
    );
  }
});

test("Phone resolver gateway rejects malformed unique response as 503", async () => {
  const gateway = new BitrixAntiFraudPhoneResolverGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          status: "unique",
          bitrix_user_id: "1969724",
          active: true,
        }),
        { status: 200 },
      ),
  });

  await assert.rejects(
    gateway.resolvePhone("+7 999 123-45-67"),
    (error: unknown) => {
      assert.ok(error instanceof BitrixAntiFraudPhoneResolverError);
      assert.equal(error.httpStatus, 503);
      return true;
    },
  );
});
