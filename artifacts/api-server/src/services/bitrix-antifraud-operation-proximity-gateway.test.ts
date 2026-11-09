import assert from "node:assert/strict";
import test from "node:test";
import {
  BitrixAntiFraudOperationProximityGateway,
  parseAntiFraudOperationProximitySnapshot,
} from "./bitrix-antifraud-operation-proximity-gateway";

const example = () => ({
  ok: true,
  evidence: "positive_bonus_operations_not_receipt_closure",
  generated_at: "2026-11-09T11:00:00+00:00",
  window_from: "2026-11-02T00:00:00+03:00",
  window_until: "2026-11-09T00:00:00+03:00",
  source_operations: 44903,
  candidate_cards: 1,
  candidate_pairs: 1,
  receipt_close_confirmed: false,
  risk_score_changed: false,
  cases: [{
    id: "OP-001",
    restaurant_1: 167,
    bill_1: 961405,
    operation_1: "2026-11-02T15:33:44+03:00",
    restaurant_2: 62,
    bill_2: 742989,
    operation_2: "2026-11-02T15:44:49+03:00",
    gap_seconds: 665,
  }],
});

test("parses verified production JSON contract and omits raw card identifiers", () => {
  const source = example();
  const body = {
    ...source,
    CardNumber: "9999000011112222",
    cases: [{ ...source.cases[0], CardNumber: "9999000011112222" }],
  };
  const data = parseAntiFraudOperationProximitySnapshot(body);
  assert.equal(data.candidatePairs, 1);
  assert.equal(data.candidateCards, 1);
  assert.equal(data.cases[0].gapSeconds, 665);
  assert.equal(data.cases[0].restaurant1, 167);
  assert.equal(data.cases[0].bill2, 742989);
  assert.equal(data.evidence, "positive_bonus_operations_not_receipt_closure");
  assert.equal(data.receiptCloseConfirmed, false);
  assert.equal(JSON.stringify(data).includes("9999000011112222"), false);
});

test("rejects changed evidence, gaps over 30 minutes and same restaurant", () => {
  assert.throws(() => parseAntiFraudOperationProximitySnapshot({
    ...example(), evidence: "confirmed_receipt_close",
  }), /invalid evidence/);
  const badGap = example();
  badGap.cases[0].gap_seconds = 1801;
  assert.throws(() => parseAntiFraudOperationProximitySnapshot(badGap), /invalid proximity/);
  const badRestaurant = example();
  badRestaurant.cases[0].restaurant_2 = 167;
  assert.throws(() => parseAntiFraudOperationProximitySnapshot(badRestaurant), /invalid proximity/);
});

test("rejects inconsistent counts and duplicate case ids", () => {
  assert.throws(() => parseAntiFraudOperationProximitySnapshot({
    ...example(), candidate_pairs: 2,
  }), /invalid cases/);
  const duplicate = example();
  duplicate.cases.push({ ...duplicate.cases[0] });
  duplicate.candidate_pairs = 2;
  assert.throws(() => parseAntiFraudOperationProximitySnapshot(duplicate), /invalid case_id/);
});

test("server gateway reads protected snapshot with existing token and GET", async () => {
  let method = "";
  let tokenSent = "";
  const gateway = new BitrixAntiFraudOperationProximityGateway({
    token: "x".repeat(40),
    fetchImpl: async (_url, init) => {
      method = init?.method ?? "";
      tokenSent = (init?.headers as Record<string, string>)["X-Anti-Fraud-Token"];
      return new Response(JSON.stringify(example()), { status: 200 });
    },
  });
  const data = await gateway.getSnapshot();
  assert.equal(method, "GET");
  assert.equal(tokenSent, "x".repeat(40));
  assert.equal(data.candidatePairs, 1);
});

test("HTTP failures do not include response body or token", async () => {
  const gateway = new BitrixAntiFraudOperationProximityGateway({
    token: "s".repeat(40),
    fetchImpl: async () => new Response("SECRET_SHOULD_NOT_BE_EMITTED", { status: 503 }),
  });
  await assert.rejects(gateway.getSnapshot(), (err: unknown) =>
    err instanceof Error &&
    err.message.includes("503") &&
    !err.message.includes("SECRET_SHOULD_NOT_BE_EMITTED") &&
    !err.message.includes("s".repeat(40)),
  );
});

test("accepts resolved owner details but never returns a raw card number", () => {
  const source = example();
  const enriched = {
    ...source,
    cases: [{
      ...source.cases[0],
      owner_status: "resolved",
      bitrix_user_id: 123456,
      owner_name: "Тестовый Владелец",
      owner_phone: "+79990001122",
      card_last4: "2211",
      CardNumber: "9999000011112211",
    }],
  };
  const data = parseAntiFraudOperationProximitySnapshot(enriched);
  assert.deepEqual({
    status: data.cases[0].ownerStatus,
    userId: data.cases[0].bitrixUserId,
    name: data.cases[0].ownerName,
    phone: data.cases[0].ownerPhone,
    last4: data.cases[0].cardLast4,
  }, {
    status: "resolved", userId: 123456, name: "Тестовый Владелец",
    phone: "+79990001122", last4: "2211",
  });
  assert.equal(JSON.stringify(data).includes("9999000011112211"), false);
});

test("rejects fabricated resolved ownership without a Bitrix user id", () => {
  const source = example();
  assert.throws(() => parseAntiFraudOperationProximitySnapshot({
    ...source, cases: [{ ...source.cases[0], owner_status: "resolved" }],
  }), /resolved_owner/);
});

test("unresolved owner remains explicit without inventing a name or phone", () => {
  const source = example();
  const actual = parseAntiFraudOperationProximitySnapshot({
    ...source, cases: [{ ...source.cases[0], owner_status: "unresolved", card_last4: "1234" }],
  });
  assert.equal(actual.cases[0].ownerStatus, "unresolved");
  assert.equal(actual.cases[0].ownerName, null);
  assert.equal(actual.cases[0].ownerPhone, null);
  assert.equal(actual.cases[0].cardLast4, "1234");
});
