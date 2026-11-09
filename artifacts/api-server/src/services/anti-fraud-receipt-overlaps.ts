/**
 * Deterministic overlap detector for two distinct receipts using the SAME loyalty card.
 *
 * Source contract:
 * - cardKey is an internal, stable, opaque card reference (never raw CARD_NO in UI/logs).
 * - receiptKey is a stable identifier of the physical receipt, NOT a bonus transaction ID.
 * - openedAt and closedAt are the actual receipt opening/closing timestamps.
 * - "ДатаВремя операции" / bonus event timestamp is NOT a confirmed closing timestamp.
 * - input is already normalized to one non-cancelled receipt per cardKey + receiptKey.
 *
 * This pure module does not read RestIS, write to the database, score users or block users.
 */
export type AntiFraudClosedReceipt = {
  cardKey: string;
  receiptKey: string;
  restaurantKey: string;
  restaurantName: string;
  openedAt: Date;
  closedAt: Date;
  closedAtEvidence: "confirmed_receipt_close";
  amount?: string | null;
};

export type AntiFraudOverlapReceipt = {
  receiptKey: string;
  restaurantKey: string;
  restaurantName: string;
  openedAt: Date;
  closedAt: Date;
  amount: string | null;
};

export type AntiFraudReceiptOverlap = {
  cardKey: string;
  first: AntiFraudOverlapReceipt;
  second: AntiFraudOverlapReceipt;
  restaurantScope: "same_restaurant" | "different_restaurants";
  overlapStartedAt: Date;
  overlapEndedAt: Date;
  overlapMilliseconds: number;
};

const validText = (text: string): boolean =>
  typeof text === "string" && text.trim().length > 0;

const asReceiptView = (r: AntiFraudClosedReceipt): AntiFraudOverlapReceipt => ({
  receiptKey: r.receiptKey,
  restaurantKey: r.restaurantKey,
  restaurantName: r.restaurantName,
  openedAt: new Date(r.openedAt.getTime()),
  closedAt: new Date(r.closedAt.getTime()),
  amount: r.amount ?? null,
});

const sameReceipt = (a: AntiFraudClosedReceipt, b: AntiFraudClosedReceipt): boolean =>
  a.restaurantKey === b.restaurantKey &&
  a.restaurantName === b.restaurantName &&
  a.openedAt.getTime() === b.openedAt.getTime() &&
  a.closedAt.getTime() === b.closedAt.getTime() &&
  (a.amount ?? null) === (b.amount ?? null);

const checkedReceipts = (receipts: readonly AntiFraudClosedReceipt[]): AntiFraudClosedReceipt[] => {
  const distinct = new Map<string, AntiFraudClosedReceipt>();

  for (const receipt of receipts) {
    if (
      !receipt ||
      !validText(receipt.cardKey) ||
      !validText(receipt.receiptKey) ||
      !validText(receipt.restaurantKey) ||
      !validText(receipt.restaurantName) ||
      !(receipt.openedAt instanceof Date) ||
      !(receipt.closedAt instanceof Date) ||
      !Number.isFinite(receipt.openedAt.getTime()) ||
      !Number.isFinite(receipt.closedAt.getTime()) ||
      receipt.openedAt.getTime() >= receipt.closedAt.getTime() ||
      receipt.closedAtEvidence !== "confirmed_receipt_close"
    ) {
      throw new Error("Anti-Fraud overlap: required verified card, receipt and close interval");
    }

    const identity = JSON.stringify([receipt.cardKey, receipt.receiptKey]);
    const previous = distinct.get(identity);
    if (previous) {
      if (!sameReceipt(previous, receipt)) {
        throw new Error("Anti-Fraud overlap: contradictory records for the same receipt");
      }
      continue;
    }
    distinct.set(identity, receipt);
  }

  return [...distinct.values()];
};

/**
 * Every returned pair has one cardKey, two distinct receiptKeys, and a strictly
 * positive time intersection. Different-restaurant pairs come first.
 */
export const detectAntiFraudReceiptOverlaps = (
  receipts: readonly AntiFraudClosedReceipt[],
): AntiFraudReceiptOverlap[] => {
  const byCard = new Map<string, AntiFraudClosedReceipt[]>();
  for (const receipt of checkedReceipts(receipts)) {
    const group = byCard.get(receipt.cardKey) ?? [];
    group.push(receipt);
    byCard.set(receipt.cardKey, group);
  }

  const overlaps: AntiFraudReceiptOverlap[] = [];
  for (const [cardKey, group] of byCard) {
    group.sort((a, b) =>
      a.openedAt.getTime() - b.openedAt.getTime() ||
      a.closedAt.getTime() - b.closedAt.getTime() ||
      a.receiptKey.localeCompare(b.receiptKey)
    );
    for (let i = 0; i < group.length; i += 1) {
      const first = group[i];
      for (let j = i + 1; j < group.length; j += 1) {
        const second = group[j];
        if (second.openedAt.getTime() >= first.closedAt.getTime()) break;
        const from = Math.max(first.openedAt.getTime(), second.openedAt.getTime());
        const until = Math.min(first.closedAt.getTime(), second.closedAt.getTime());
        if (until <= from) continue;

        overlaps.push({
          cardKey,
          first: asReceiptView(first),
          second: asReceiptView(second),
          restaurantScope: first.restaurantKey === second.restaurantKey
            ? "same_restaurant" : "different_restaurants",
          overlapStartedAt: new Date(from),
          overlapEndedAt: new Date(until),
          overlapMilliseconds: until - from,
        });
      }
    }
  }

  overlaps.sort((a, b) =>
    Number(a.restaurantScope === "same_restaurant") -
      Number(b.restaurantScope === "same_restaurant") ||
    b.overlapStartedAt.getTime() - a.overlapStartedAt.getTime() ||
    a.cardKey.localeCompare(b.cardKey) ||
    a.first.receiptKey.localeCompare(b.first.receiptKey) ||
    a.second.receiptKey.localeCompare(b.second.receiptKey)
  );
  return overlaps;
};
