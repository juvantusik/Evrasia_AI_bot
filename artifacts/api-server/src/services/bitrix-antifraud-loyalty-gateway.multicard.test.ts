import assert from "node:assert/strict";
import test from "node:test";
import { BitrixAntiFraudLoyaltyGateway } from "./bitrix-antifraud-loyalty-gateway";

// Добавлено 05.09.2026 ИТ Директор Евразии
// Несколько активных карт остаются anomaly-state, но site-side /api/Balance по Phone
// возвращает текущий TotalSum аккаунта. Номера карт в API не раскрываются.
test("Anti-Fraud loyalty gateway accepts account balance for multiple active cards", async () => {
  const gateway = new BitrixAntiFraudLoyaltyGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          records: [
            {
              bitrix_user_id: 737384,
              active_card_found: false,
              active_card_count: 10,
              card_status_id: null,
              card_status: null,
              card_type: null,
              discount_percent: 15,
              bonus_balance: "733.75",
              total_spend: "1000.00",
              today_sum: "0.00",
              history_summary: null,
              history: null,
              issue: "multiple_active_cards",
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

  const result = await gateway.resolveLoyalty([737384]);
  const record = result.records[0];

  assert.equal(record?.activeCardFound, false);
  assert.equal(record?.activeCardCount, 10);
  assert.equal(record?.issue, "multiple_active_cards");
  assert.equal(record?.bonusBalance, "733.75");
  assert.equal(record?.discountPercent, 15);
});
