import assert from "node:assert/strict";
import test from "node:test";
import {
  RestisAntiFraudGateway,
  parseVipHistoryXml,
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
test("VIP_HISTORY parser ignores non-risk fields and deduplicates equal core events", () => {
  const xml = [
    '<HIS ID="77" OPEN_DATE="2026-09-01T12:10:11" NAME_OBJECT="Test" AMOUNT="100" NOTE="A" />',
    '<HIS ID="77" OPEN_DATE="2026-09-01T12:10:11" NAME_OBJECT="Test" AMOUNT="999" NOTE="B" />',
    '<HIS ID="78" OPEN_DATE="2019-07-01T06:00:09.060" NAME_OBJECT="Old" GUESTS_COUNT="4" />',
  ].join("");

  const batch = parseVipHistoryXml(xml, "12345678", "+03:00");

  assert.equal(batch.rawRows, 3);
  assert.equal(batch.visits.length, 2);
  assert.deepEqual(batch.visits[0], {
    restisId: "77",
    cardNumber: "12345678",
    visitedAt: new Date("2026-09-01T09:10:11.000Z"),
    restaurant: "Test",
  });
  assert.equal(batch.visits[1]?.visitedAt.toISOString(), "2019-07-01T03:00:09.060Z");
  assert.equal(Object.hasOwn(batch.visits[0] as object, "amount"), false);
});

// Добавлено 03.09.2026 ИТ Директор Евразии
test("VIP_HISTORY parser rejects conflicting core data for the same RestIS ID", () => {
  const xml = [
    '<HIS ID="77" OPEN_DATE="2026-09-01T12:10:11" NAME_OBJECT="A" />',
    '<HIS ID="77" OPEN_DATE="2026-09-01T12:10:11" NAME_OBJECT="B" />',
  ].join("");

  assert.throws(() => parseVipHistoryXml(xml, "12345678"), /конфликтующие строки/);
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
        '<TODAY ID="77" CARD_NO="7000" OPEN_DATE="2026-09-04T04:19:18" NAME_OBJECT="Test" />',
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

// Добавлено 03.09.2026 ИТ Директор Евразии
test("RestIS gateway requests VIP_HISTORY only for the explicit card", async () => {
  let requestBody = "";

  const gateway = new RestisAntiFraudGateway({
    apiUrl: "https://api.evrasia.spb.ru/",
    username: "service-user",
    password: "service-password",
    timezoneOffset: "+03:00",
    fetchImpl: async (_input, init) => {
      requestBody = String(init?.body ?? "");
      return new Response(
        '<HIS ID="77" OPEN_DATE="2026-09-01T12:10:11" NAME_OBJECT="Test" AMOUNT="100" />',
        { status: 200 },
      );
    },
  });

  const batch = await gateway.fetchVipHistory("12345678", 10_000);

  assert.equal(requestBody, '<VIP_HISTORY pagesize="10000" Card="12345678" />');
  assert.equal(batch.rawRows, 1);
  assert.equal(batch.visits[0]?.cardNumber, "12345678");
});
