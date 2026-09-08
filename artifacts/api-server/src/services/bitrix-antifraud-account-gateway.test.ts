import assert from "node:assert/strict";
import test from "node:test";
import { BitrixAntiFraudAccountGateway } from "./bitrix-antifraud-account-gateway";

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Bitrix Anti-Fraud account gateway sends token and parses only requested users", async () => {
  let tokenHeader = "";
  let requestBody: Record<string, unknown> | undefined;

  const gateway = new BitrixAntiFraudAccountGateway({
    token: "x".repeat(64),
    fetchImpl: async (_input, init) => {
      tokenHeader = new Headers(init?.headers).get("X-Anti-Fraud-Token") ?? "";
      requestBody = JSON.parse(String(init?.body)) as Record<string, unknown>;
      return new Response(
        JSON.stringify({
          ok: true,
          records: [
            {
              bitrix_user_id: 120445,
              phone_normalized: "79991234567",
              email_normalized: "test@example.com",
              display_name: "Тестовый пользователь",
              registered_at: "2025-08-30T10:00:00+03:00",
              bitrix_active: true,
              bitrix_blocked: true,
              block_reason: "Нарушение правил программы лояльности",
              // Старое поле может присутствовать в legacy response, но account-map его намеренно игнорирует.
              bonus_balance: 45678,
            },
          ],
          unresolved: [415307],
          requested: 2,
          resolved: 1,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    },
  });

  const result = await gateway.resolveAccounts([120445, 415307, 120445]);

  assert.equal(tokenHeader, "x".repeat(64));
  assert.deepEqual(requestBody, { user_ids: [120445, 415307] });
  assert.equal(result.requested, 2);
  assert.equal(result.resolved, 1);
  assert.equal(result.records[0]?.bitrixUserId, 120445);
  assert.equal(result.records[0]?.phoneNormalized, "79991234567");
  assert.equal(result.records[0]?.emailNormalized, "test@example.com");
  assert.equal(result.records[0]?.bitrixActive, true);
  assert.equal(result.records[0]?.bitrixBlocked, true);
  assert.equal(result.records[0]?.bitrixBlockReason, "Нарушение правил программы лояльности");
  assert.deepEqual(result.unresolved, [415307]);
  assert.equal(Object.prototype.hasOwnProperty.call(result.records[0] ?? {}, "bonusBalance"), false);
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Bitrix Anti-Fraud account gateway accepts nullable PII and block reason fields", async () => {
  const gateway = new BitrixAntiFraudAccountGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          records: [
            {
              bitrix_user_id: 120445,
              phone_normalized: null,
              email_normalized: null,
              display_name: null,
              registered_at: null,
              bitrix_active: false,
              bitrix_blocked: false,
              block_reason: null,
            },
          ],
          unresolved: [],
          requested: 1,
          resolved: 1,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      ),
  });

  const result = await gateway.resolveAccounts([120445]);
  assert.equal(result.records[0]?.phoneNormalized, null);
  assert.equal(result.records[0]?.registeredAt, null);
  assert.equal(result.records[0]?.bitrixActive, false);
  assert.equal(result.records[0]?.bitrixBlocked, false);
  assert.equal(result.records[0]?.bitrixBlockReason, null);
});

// Добавлено 07.09.2026 ИТ Директор Евразии
test("Bitrix Anti-Fraud account gateway rejects malformed blocked status", async () => {
  const gateway = new BitrixAntiFraudAccountGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          records: [
            {
              bitrix_user_id: 120445,
              phone_normalized: null,
              email_normalized: null,
              display_name: null,
              registered_at: null,
              bitrix_active: true,
              bitrix_blocked: "Y",
              block_reason: null,
            },
          ],
          unresolved: [],
          requested: 1,
          resolved: 1,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      ),
  });

  await assert.rejects(gateway.resolveAccounts([120445]), /bitrix_blocked/);
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Bitrix Anti-Fraud account gateway rejects duplicate classification without exposing PII", async () => {
  const secretPhone = "79998887766";
  const gateway = new BitrixAntiFraudAccountGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          records: [
            {
              bitrix_user_id: 120445,
              phone_normalized: secretPhone,
              email_normalized: "secret@example.com",
              display_name: "Секретное имя",
              registered_at: null,
              bitrix_active: true,
              bitrix_blocked: false,
              block_reason: null,
            },
          ],
          unresolved: [120445],
          requested: 1,
          resolved: 1,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      ),
  });

  await assert.rejects(
    gateway.resolveAccounts([120445]),
    (error: unknown) => {
      assert.ok(error instanceof Error);
      assert.match(error.message, /повторный USER_ID/);
      assert.doesNotMatch(error.message, new RegExp(secretPhone));
      assert.doesNotMatch(error.message, /secret@example\.com/);
      assert.doesNotMatch(error.message, /Секретное имя/);
      return true;
    },
  );
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("Bitrix Anti-Fraud account gateway does not expose HTTP response body", async () => {
  const gateway = new BitrixAntiFraudAccountGateway({
    token: "x".repeat(64),
    fetchImpl: async () => new Response("phone=79998887766 email=secret@example.com", { status: 500 }),
  });

  await assert.rejects(
    gateway.resolveAccounts([120445]),
    (error: unknown) => {
      assert.ok(error instanceof Error);
      assert.match(error.message, /HTTP 500/);
      assert.doesNotMatch(error.message, /79998887766/);
      assert.doesNotMatch(error.message, /secret@example\.com/);
      return true;
    },
  );
});
