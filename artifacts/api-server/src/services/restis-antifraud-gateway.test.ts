import assert from "node:assert/strict";
import test from "node:test";
import {
  RestisAntiFraudGateway,
  parseVipTodayXml,
} from "./restis-antifraud-gateway";

// Добавлено 03.09.2026 ИТ Директор Евразии
test("VIP_TODAY parser keeps only Anti-Fraud visit fields and deduplicates RestIS ID", () => {
  const xml = [
    '<TODAY LINE="1" ID="41333971" CARD_NO="66612345670" OPEN_DATE="2026-09-04T04:19:18" AMOUNT="1000" NAME_OBJECT="Кронверкский 13" ADD_BONUS="10" PAYED_BONUS="0" />',
    '<TODAY LINE="2" ID="41333971" CARD_NO="66612345670" OPEN_DATE="2026-09-04T04:19:18" AMOUNT="1000" NAME_OBJECT="Кронверкский 13" ADD_BONUS="10" PAYED_BONUS="0" />',
    '<TODAY LINE="3" ID="41333972" CARD_NO="66612345671" OPEN_DATE="2026-09-04T05:20:00" NAME_OBJECT="Большая Конюшенная 10" />',
  ].join("");

  const batch = parseVipTodayXml(xml, "+03:00");

  assert.equal(batch.rawRows, 3);
  assert.equal(batch.visits.length, 2);
  assert.deepEqual(batch.visits[0], {
    restisId: "41333971",
    cardNumber: "66612345670",
    visitedAt: new Date("2026-09-04T01:19:18.000Z"),
    restaurant: "Кронверкский 13",
  });
  assert.equal(Object.hasOwn(batch.visits[0] as object, "amount"), false);
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("VIP_TODAY parser rejects conflicting duplicates", () => {
  const xml = [
    '<TODAY ID="1" CARD_NO="100" OPEN_DATE="2026-09-04T04:00:00" NAME_OBJECT="A" />',
    '<TODAY ID="1" CARD_NO="100" OPEN_DATE="2026-09-04T04:01:00" NAME_OBJECT="A" />',
  ].join("");

  assert.throws(() => parseVipTodayXml(xml), /конфликтующие строки/);
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("VIP_TODAY parser rejects rows without required visit identity", () => {
  assert.throws(
    () =>
      parseVipTodayXml(
        '<TODAY ID="1" CARD_NO="100" OPEN_DATE="2026-09-04T04:00:00" />',
      ),
    /обязательных/,
  );
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("RestIS gateway uses Basic auth without exposing credentials in request body", async () => {
  let authorization = "";
  let requestBody = "";

  const gateway = new RestisAntiFraudGateway({
    apiUrl: "https://api.evrasia.spb.ru/",
    username: "service-user",
    password: "service-password",
    timezoneOffset: "+03:00",
    fetchImpl: async (_input, init) => {
      authorization = new Headers(init?.headers).get("Authorization") ?? "";
      requestBody = String(init?.body ?? "");
      return new Response(
        '<TODAY ID="77" CARD_NO="700" OPEN_DATE="2026-09-04T04:19:18" NAME_OBJECT="Test" />',
        { status: 200 },
      );
    },
  });

  const batch = await gateway.fetchVipToday(500);

  assert.equal(
    authorization,
    `Basic ${Buffer.from("service-user:service-password").toString("base64")}`,
  );
  assert.equal(requestBody, '<VIP_TODAY pagesize="500" />');
  assert.equal(requestBody.includes("service-password"), false);
  assert.equal(batch.visits.length, 1);
});
