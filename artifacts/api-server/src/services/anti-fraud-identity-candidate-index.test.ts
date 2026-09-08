import assert from "node:assert/strict";
import test from "node:test";

process.env.DATABASE_URL ??= "postgresql://test:test@127.0.0.1:5432/test";

const { buildIdentityCandidatePairs } = await import("./anti-fraud-identity-candidate-index");
const { evaluateIdentityPair } = await import("./anti-fraud-identity-similarity-service");

type Account = {
  bitrixUserId: number;
  displayName: string | null;
  phone: string | null;
  email: string | null;
};

const linkKey = (left: number, right: number): string =>
  left < right ? `${left}:${right}` : `${right}:${left}`;

const exhaustiveLinks = (accounts: Account[]): string[] => {
  const links: string[] = [];
  for (let leftIndex = 0; leftIndex < accounts.length; leftIndex += 1) {
    for (let rightIndex = leftIndex + 1; rightIndex < accounts.length; rightIndex += 1) {
      const left = accounts[leftIndex]!;
      const right = accounts[rightIndex]!;
      if (evaluateIdentityPair(left, right, false)) {
        links.push(linkKey(left.bitrixUserId, right.bitrixUserId));
      }
    }
  }
  return links.sort();
};

const indexedLinks = (accounts: Account[]): string[] => {
  const byId = new Map(accounts.map((account) => [account.bitrixUserId, account]));
  return buildIdentityCandidatePairs(accounts)
    .filter(([leftId, rightId]) => {
      const left = byId.get(leftId);
      const right = byId.get(rightId);
      return Boolean(left && right && evaluateIdentityPair(left, right, false));
    })
    .map(([leftId, rightId]) => linkKey(leftId, rightId))
    .sort();
};

test("indexed candidate generation preserves exhaustive identity-link semantics", () => {
  const accounts: Account[] = [
    { bitrixUserId: 1, displayName: "Иван", phone: "79678517424", email: "ivanovivan@yandex.ru" },
    { bitrixUserId: 2, displayName: "Иван", phone: "78678517424", email: "ivanovovan@yandex.kz" },
    { bitrixUserId: 3, displayName: "Пётр", phone: "79990000001", email: "ivanovivan@yandex.com" },
    { bitrixUserId: 4, displayName: "Анна", phone: "78880000002", email: "anna.petrovna@mail.ru" },
    { bitrixUserId: 5, displayName: "Анна", phone: "78880000003", email: "annapetrovna@icloud.com" },
    { bitrixUserId: 6, displayName: "Олег", phone: "70000000000", email: "olegabcdef@gmail.com" },
    { bitrixUserId: 7, displayName: "Олег", phone: "70000000000", email: "olegabcdef@gmail.com" },
    { bitrixUserId: 8, displayName: "Игорь", phone: null, email: "abcdefg@example.com" },
    { bitrixUserId: 9, displayName: "Игорь", phone: null, email: "abcdefgh@example.net" },
  ];

  assert.deepEqual(indexedLinks(accounts), exhaustiveLinks(accounts));
});

test("same-provider insertion and deletion candidates are not missed", () => {
  const accounts: Account[] = [
    { bitrixUserId: 11, displayName: "Иван", phone: null, email: "ivanovivan@yandex.ru" },
    { bitrixUserId: 12, displayName: "Иван", phone: null, email: "ivanovivana@yandex.com" },
    { bitrixUserId: 13, displayName: "Иван", phone: null, email: "ivanoviva@yandex.kz" },
  ];

  assert.deepEqual(indexedLinks(accounts), exhaustiveLinks(accounts));
});

test("large unrelated fleet does not materialize all possible account pairs", () => {
  const accounts: Account[] = Array.from({ length: 6119 }, (_, index) => {
    const id = index + 10000;
    return {
      bitrixUserId: id,
      displayName: `User ${id}`,
      phone: null,
      email: `unique${id}@provider${id}.example`,
    };
  });

  const pairs = buildIdentityCandidatePairs(accounts);
  const exhaustivePairCount = accounts.length * (accounts.length - 1) / 2;

  assert.equal(exhaustivePairCount, 18_718_021);
  assert.equal(pairs.length, 0);
});
