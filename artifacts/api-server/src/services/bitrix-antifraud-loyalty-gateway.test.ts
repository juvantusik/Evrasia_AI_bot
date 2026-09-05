import assert from "node:assert/strict";
import test from "node:test";
import { BitrixAntiFraudLoyaltyGateway } from "./bitrix-antifraud-loyalty-gateway";

// Добавлено 05.09.2026 ИТ Директор Евразии
test("Anti-Fraud loyalty gateway parses decimal balance and does not expose card number", async () => {
  let tokenHeader = "";
  let requestBody: Record<string, unknown> | undefined;

  const gateway = new BitrixAntiFraudLoyaltyGateway({
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
              active_card_found: true,
              active_card_count: 1,
              card_status_id: 113,
              card_status: "Активна",
              card_type: 28,
              discount_percent: 20,
              bonus_balance: "2438.89",
              total_spend: "997590.83",
              today_sum: "0.00",
              history_summary: null,
              history: null,
              issue: null,
            },
          ],
          unresolved: [],
          requested: 1,
          resolved: 1,
          include_history: false,
          history_days: null,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    },
  });

  const result = await gateway.resolveLoyalty([120445]);

  assert.equal(tokenHeader, "x".repeat(64));
  assert.deepEqual(requestBody, {
    user_ids: [120445],
    include_history: false,
    history_days: 60,
  });
  assert.equal(result.records[0]?.bitrixUserId, 120445);
  assert.equal(result.records[0]?.activeCardFound, true);
  assert.equal(result.records[0]?.cardStatusId, 113);
  assert.equal(result.records[0]?.bonusBalance, "2438.89");
  assert.equal(result.records[0]?.discountPercent, 20);
});

// Добавлено 05.09.2026 ИТ Директор Евразии
test("Anti-Fraud loyalty gateway parses 60-day VIP history money fields", async () => {
  const gateway = new BitrixAntiFraudLoyaltyGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          records: [
            {
              bitrix_user_id: 120445,
              active_card_found: true,
              active_card_count: 1,
              card_status_id: 113,
              card_status: "Активна",
              card_type: 28,
              discount_percent: 20,
              bonus_balance: "2438.89",
              total_spend: "997590.83",
              today_sum: "0.00",
              history_summary: {
                history_days: 60,
                from: "2026-07-07T10:30:00+03:00",
                to: "2026-09-05T10:30:00+03:00",
                visits: 1,
                amount: "11194.44",
                bonus_added: "2238.89",
                bonus_spent: "8620.56",
              },
              history: [
                {
                  restis_id: "123456",
                  occurred_at: "2026-09-04T20:06:22+03:00",
                  restaurant: "Загородный 64",
                  amount: "11194.44",
                  bonus_added: "2238.89",
                  bonus_spent: "8620.56",
                },
              ],
              issue: null,
            },
          ],
          unresolved: [],
          requested: 1,
          resolved: 1,
          include_history: true,
          history_days: 60,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      ),
  });

  const result = await gateway.resolveLoyalty([120445], {
    includeHistory: true,
    historyDays: 60,
  });

  assert.equal(result.historyDays, 60);
  assert.equal(result.records[0]?.historySummary?.bonusSpent, "8620.56");
  assert.equal(result.records[0]?.history?.[0]?.bonusAdded, "2238.89");
  assert.equal(result.records[0]?.history?.[0]?.restaurant, "Загородный 64");
});

// Добавлено 05.09.2026 ИТ Директор Евразии
test("Anti-Fraud loyalty gateway rejects leaked raw card number", async () => {
  const gateway = new BitrixAntiFraudLoyaltyGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          records: [
            {
              bitrix_user_id: 120445,
              active_card_found: true,
              active_card_count: 1,
              card_status_id: 113,
              card_status: "Активна",
              card_type: 28,
              card_number: "0000000000",
              discount_percent: 20,
              bonus_balance: "2438.89",
              total_spend: "997590.83",
              today_sum: "0.00",
              history_summary: null,
              history: null,
              issue: null,
            },
          ],
          unresolved: [],
          requested: 1,
          resolved: 1,
          include_history: false,
          history_days: null,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      ),
  });

  await assert.rejects(
    gateway.resolveLoyalty([120445]),
    /не должен раскрывать номер карты/,
  );
});
