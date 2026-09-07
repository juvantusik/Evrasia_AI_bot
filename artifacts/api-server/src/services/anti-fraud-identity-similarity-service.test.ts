import assert from "node:assert/strict";
import test from "node:test";

process.env.DATABASE_URL ??= "postgresql://test:test@127.0.0.1:5432/test";

const {
  differsByAtMostOneEdit,
  phonesDifferByOneDigit,
  evaluateIdentityPair,
} = await import("./anti-fraud-identity-similarity-service");

test("same Yandex local-part across TLDs opens candidate and same name corroborates it", () => {
  const link = evaluateIdentityPair(
    {
      bitrixUserId: 101,
      displayName: "Иван",
      phone: "79990000001",
      email: "ivanovivan@yandex.ru",
    },
    {
      bitrixUserId: 102,
      displayName: "Иван",
      phone: "78880000002",
      email: "ivanovivan@yandex.com",
    },
    false,
  );

  assert.ok(link);
  assert.equal(link.similarEmail, true);
  assert.equal(link.sameName, true);
  assert.equal(link.corroborated, true);
  assert.equal(link.riskScore, 15);
});

test("one-character Yandex local-part change is detected and same name corroborates it", () => {
  const link = evaluateIdentityPair(
    {
      bitrixUserId: 201,
      displayName: "Иван",
      phone: "79990000001",
      email: "ivanovivan@yandex.ru",
    },
    {
      bitrixUserId: 202,
      displayName: "Иван",
      phone: "78880000002",
      email: "ivanovovan@yandex.kz",
    },
    false,
  );

  assert.ok(link);
  assert.equal(link.similarEmail, true);
  assert.equal(link.corroborated, true);
  assert.match(link.emailDetails ?? "", /local_edit_distance_1/);
});

test("similar email without another independent signal stays uncorroborated", () => {
  const link = evaluateIdentityPair(
    {
      bitrixUserId: 301,
      displayName: "Иван",
      phone: "79990000001",
      email: "ivanovivan@yandex.ru",
    },
    {
      bitrixUserId: 302,
      displayName: "Пётр",
      phone: "78880000002",
      email: "ivanovivan@yandex.com",
    },
    false,
  );

  assert.ok(link);
  assert.equal(link.similarEmail, true);
  assert.equal(link.corroborated, false);
  assert.equal(link.riskScore, 0);
});

test("phone differing by exactly one digit opens candidate and same name corroborates it", () => {
  const link = evaluateIdentityPair(
    {
      bitrixUserId: 401,
      displayName: "Иван",
      phone: "79678517424",
      email: "first@example.com",
    },
    {
      bitrixUserId: 402,
      displayName: "Иван",
      phone: "78678517424",
      email: "other@example.net",
    },
    false,
  );

  assert.ok(link);
  assert.equal(link.similarPhone, true);
  assert.equal(link.sameName, true);
  assert.equal(link.corroborated, true);
  assert.equal(link.riskScore, 20);
});

test("similar email and one-digit phone corroborate each other without relying on visits", () => {
  const link = evaluateIdentityPair(
    {
      bitrixUserId: 501,
      displayName: "Иван",
      phone: "79678517424",
      email: "ivanovivan@yandex.ru",
    },
    {
      bitrixUserId: 502,
      displayName: "Не совпадает",
      phone: "78678517424",
      email: "ivanovovan@yandex.kz",
    },
    false,
  );

  assert.ok(link);
  assert.equal(link.similarEmail, true);
  assert.equal(link.similarPhone, true);
  assert.equal(link.sameName, false);
  assert.equal(link.corroborated, true);
  assert.equal(link.riskScore, 50);
});

test("phone comparison requires exactly one differing digit", () => {
  assert.equal(phonesDifferByOneDigit("79678517424", "78678517424"), true);
  assert.equal(phonesDifferByOneDigit("79678517424", "79678517424"), false);
  assert.equal(phonesDifferByOneDigit("79678517424", "78678517434"), false);
});

test("local-part edit helper accepts substitution insertion and deletion by one", () => {
  assert.equal(differsByAtMostOneEdit("ivanovivan", "ivanovovan"), true);
  assert.equal(differsByAtMostOneEdit("ivanovivan", "ivanovivana"), true);
  assert.equal(differsByAtMostOneEdit("ivanovivan", "ivanoviva"), true);
  assert.equal(differsByAtMostOneEdit("ivanovivan", "petrovpetr"), false);
});
