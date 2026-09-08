type IdentityCandidateAccount = {
  bitrixUserId: number;
  phone: string | null;
  email: string | null;
};

type PhoneBucketEntry = {
  bitrixUserId: number;
  phone: string;
};

type EmailBucketEntry = {
  bitrixUserId: number;
  full: string;
  provider: string;
};

const pairKey = (left: number, right: number): string =>
  left < right ? `${left}:${right}` : `${right}:${left}`;

const parseEmail = (value: string | null): {
  full: string;
  local: string;
  provider: string;
} | null => {
  const full = String(value ?? "").trim().toLowerCase();
  const at = full.lastIndexOf("@");
  if (at <= 0 || at === full.length - 1) return null;

  const local = full.slice(0, at);
  const labels = full.slice(at + 1).split(".").filter(Boolean);
  if (local.length < 1 || labels.length < 2) return null;

  const provider = labels.at(-2) ?? "";
  if (!provider) return null;
  return { full, local, provider };
};

const normalizeCrossProviderLocal = (value: string): string =>
  value.toLowerCase().replace(/[._-]/g, "");

const pushBucket = <T>(buckets: Map<string, T[]>, key: string, value: T): void => {
  const current = buckets.get(key);
  if (current) current.push(value);
  else buckets.set(key, [value]);
};

const addPair = (pairs: Set<string>, left: number, right: number): void => {
  if (left === right) return;
  pairs.add(pairKey(left, right));
};

// Добавлено 08.09.2026 ИТ Директор Евразии
// Candidate generation intentionally mirrors the existing similarity rules without
// evaluating every active account against every other active account. Exact rules
// remain in evaluateIdentityPair(); this index may produce false positives, which are
// filtered there, but it must not miss any one-digit phone / <=1-edit same-provider
// email / exact normalized cross-provider local-part candidate.
export const buildIdentityCandidatePairs = (
  accounts: IdentityCandidateAccount[],
): Array<[number, number]> => {
  const phoneBuckets = new Map<string, PhoneBucketEntry[]>();
  const sameProviderEmailBuckets = new Map<string, EmailBucketEntry[]>();
  const crossProviderEmailBuckets = new Map<string, EmailBucketEntry[]>();

  for (const account of accounts) {
    const bitrixUserId = Number(account.bitrixUserId);
    if (!Number.isInteger(bitrixUserId) || bitrixUserId <= 0) continue;

    const phone = String(account.phone ?? "").replace(/\D/g, "");
    if (phone.length >= 7) {
      const signatures = new Set<string>();
      for (let index = 0; index < phone.length; index += 1) {
        signatures.add(
          `${phone.length}:${index}:${phone.slice(0, index)}*${phone.slice(index + 1)}`,
        );
      }
      for (const signature of signatures) {
        pushBucket(phoneBuckets, signature, { bitrixUserId, phone });
      }
    }

    const email = parseEmail(account.email);
    if (!email) continue;

    if (email.local.length >= 6) {
      const signatures = new Set<string>([email.local]);
      for (let index = 0; index < email.local.length; index += 1) {
        signatures.add(email.local.slice(0, index) + email.local.slice(index + 1));
      }
      for (const signature of signatures) {
        pushBucket(
          sameProviderEmailBuckets,
          `${email.provider}:${signature}`,
          { bitrixUserId, full: email.full, provider: email.provider },
        );
      }
    }

    const normalizedLocal = normalizeCrossProviderLocal(email.local);
    if (normalizedLocal.length >= 6) {
      pushBucket(
        crossProviderEmailBuckets,
        normalizedLocal,
        { bitrixUserId, full: email.full, provider: email.provider },
      );
    }
  }

  const pairs = new Set<string>();

  for (const bucket of phoneBuckets.values()) {
    for (let leftIndex = 0; leftIndex < bucket.length; leftIndex += 1) {
      const left = bucket[leftIndex]!;
      for (let rightIndex = leftIndex + 1; rightIndex < bucket.length; rightIndex += 1) {
        const right = bucket[rightIndex]!;
        if (left.phone === right.phone) continue;
        addPair(pairs, left.bitrixUserId, right.bitrixUserId);
      }
    }
  }

  for (const bucket of sameProviderEmailBuckets.values()) {
    for (let leftIndex = 0; leftIndex < bucket.length; leftIndex += 1) {
      const left = bucket[leftIndex]!;
      for (let rightIndex = leftIndex + 1; rightIndex < bucket.length; rightIndex += 1) {
        const right = bucket[rightIndex]!;
        if (left.full === right.full) continue;
        addPair(pairs, left.bitrixUserId, right.bitrixUserId);
      }
    }
  }

  for (const bucket of crossProviderEmailBuckets.values()) {
    for (let leftIndex = 0; leftIndex < bucket.length; leftIndex += 1) {
      const left = bucket[leftIndex]!;
      for (let rightIndex = leftIndex + 1; rightIndex < bucket.length; rightIndex += 1) {
        const right = bucket[rightIndex]!;
        if (left.provider === right.provider) continue;
        addPair(pairs, left.bitrixUserId, right.bitrixUserId);
      }
    }
  }

  return [...pairs]
    .map((key) => key.split(":").map(Number) as [number, number])
    .sort((left, right) => left[0] - right[0] || left[1] - right[1]);
};
