import assert from "node:assert/strict";
import test from "node:test";
import { BitrixAntiFraudLoyaltyGateway } from "./bitrix-antifraud-loyalty-gateway";

// Добавлено 05.09.2026 ИТ Директор Евразии
// Реальный protected loyalty endpoint вернул отрицательный bonus_balance у активных карт.
// Текущий бонусный остаток может быть отрицательным, но остальные денежные поля сохраняют
// строгий неотрицательный контракт.
test("Anti-Fraud loyalty gateway accepts a negative current bonus balance", async () => {
  const gateway = new BitrixAntiFraudLoyaltyGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          records: [
            {
              bitrix_user_id: 1,
              active_card_found: true,
              active_card_count: 1,
              card_status_id: 113,
              card_status: "Активна",
              card_type: 28,
              discount_percent: 20,
              bonus_balance: "-125.40",
              total_spend: "1000.00",
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

  const result = await gateway.resolveLoyalty([1]);
  assert.equal(result.records[0]?.bonusBalance, "-125.40");
});

test("Anti-Fraud loyalty gateway still rejects negative total spend", async () => {
  const gateway = new BitrixAntiFraudLoyaltyGateway({
    token: "x".repeat(64),
    fetchImpl: async () =>
      new Response(
        JSON.stringify({
          ok: true,
          records: [
            {
              bitrix_user_id: 1,
              active_card_found: true,
              active_card_count: 1,
              card_status_id: 113,
              card_status: "Активна",
              card_type: 28,
              discount_percent: 20,
              bonus_balance: "-125.40",
              total_spend: "-1.00",
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

  await assert.rejects(gateway.resolveLoyalty([1]), /некорректное поле total_spend/);
});
