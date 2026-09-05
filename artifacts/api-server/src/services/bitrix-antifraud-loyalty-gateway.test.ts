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
  assert.match(result.records[0]?.history?.[0]?.eventId ?? "", /^loyalty:[a-f0-9]{64}$/);
});

// Реальный VIP_HISTORY Нэлли показал 11 групп, где один source restis_id относится
// к разным денежным операциям в то же время и в том же ресторане.
test("Anti-Fraud loyalty gateway accepts repeated source restis_id when event payload differs", async () => {
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
                visits: 2,
                amount: "12000.00",
                bonus_added: "2400.00",
                bonus_spent: "8620.56",
              },
              history: [
                {
                  restis_id: "same-source-id",
                  occurred_at: "2026-09-04T20:06:22+03:00",
                  restaurant: "Загородный 64",
                  amount: "11194.44",
                  bonus_added: "2238.89",
                  bonus_spent: "8620.56",
                },
                {
                  restis_id: "same-source-id",
                  occurred_at: "2026-09-04T20:06:22+03:00",
                  restaurant: "Загородный 64",
                  amount: "805.56",
                  bonus_added: "161.11",
                  bonus_spent: "0.00",
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
  const history = result.records[0]?.history ?? [];

  assert.equal(history.length, 2);
  assert.equal(history[0]?.restisId, "same-source-id");
  assert.equal(history[1]?.restisId, "same-source-id");
  assert.notEqual(history[0]?.eventId, history[1]?.eventId);
});

test("Anti-Fraud loyalty gateway still rejects an exact duplicate history row", async () => {
  const row = {
    restis_id: "same-source-id",
    occurred_at: "2026-09-04T20:06:22+03:00",
    restaurant: "Загородный 64",
    amount: "11194.44",
    bonus_added: "2238.89",
    bonus_spent: "8620.56",
  };
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
                visits: 2,
                amount: "22388.88",
                bonus_added: "4477.78",
                bonus_spent: "17241.12",
              },
              history: [row, row],
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

  await assert.rejects(
    gateway.resolveLoyalty([120445], { includeHistory: true, historyDays: 60 }),
    /повторное идентичное событие history/,
  );
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
