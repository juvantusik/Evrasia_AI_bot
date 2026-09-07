import assert from "node:assert/strict";
import test from "node:test";

process.env.DATABASE_URL ??= "postgresql://test:test@127.0.0.1:5432/test";

const { evaluateIdentityPair } = await import("./anti-fraud-identity-similarity-service");

test("cross-provider local-part match plus similar phone produces corroborated combo risk", () => {
  const link = evaluateIdentityPair(
    {
      bitrixUserId: 2587274,
      displayName: "Данил",
      phone: "79990000001",
      email: "daniltairov05@icloud.com",
    },
    {
      bitrixUserId: 2587285,
      displayName: "Данил",
      phone: "79990000002",
      email: "danil.tairov.05@mail.ru",
    },
    false,
  );

  assert.ok(link);
  assert.equal(link.similarEmail, true);
  assert.equal(link.similarPhone, true);
  assert.equal(link.sameName, true);
  assert.equal(link.corroborated, true);
  assert.equal(link.riskScore, 50);
  assert.match(link.emailDetails ?? "", /cross_provider_same_normalized_local/);
});

test("cross-provider local-part match is not corroborated by same name alone", () => {
  const link = evaluateIdentityPair(
    {
      bitrixUserId: 701,
      displayName: "Данил",
      phone: "79990000001",
      email: "daniltairov05@icloud.com",
    },
    {
      bitrixUserId: 702,
      displayName: "Данил",
      phone: "78880000002",
      email: "danil.tairov.05@mail.ru",
    },
    false,
  );

  assert.ok(link);
  assert.equal(link.similarEmail, true);
  assert.equal(link.similarPhone, false);
  assert.equal(link.sameName, true);
  assert.equal(link.corroborated, false);
  assert.equal(link.riskScore, 0);
});
