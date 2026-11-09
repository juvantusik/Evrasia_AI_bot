/**
 * Preliminary anti-fraud evidence: positive loyalty-card operations in DIFFERENT
 * restaurants close in time. This does NOT imply receipt closure or customer presence.
 *
 * Input contract for the future protected RESTISBONUS adapter:
 * - cardKey is an opaque, stable identity for the SAME actual loyalty card;
 *   Bitrix USER_ID is NOT sufficient.
 * - operationKey corresponds to a distinct dbo.RB_CARD_LOG.ID.
 * - receiptKey corresponds to a real (ID_OBJECT, ID_BILL) pair.
 * - occurredAt is DateWrite from a positive, non-rejected bonus operation.
 * - filter Bill_Sum > 0 and (REJECT = 0 or REJECT IS NULL) at the source.
 * No direct SQL Server access, writes, risk changes or account blocks here.
 */
export type AntiFraudPositiveCardOperation = {
  cardKey: string;
  operationKey: string;
  receiptKey: string;
  restaurantKey: string;
  occurredAt: Date;
};

export type AntiFraudCrossRestaurantOperationProximity = {
  cardKey: string;
  firstOperationKey: string;
  secondOperationKey: string;
  firstReceiptKey: string;
  secondReceiptKey: string;
  firstRestaurantKey: string;
  secondRestaurantKey: string;
  firstOccurredAt: Date;
  secondOccurredAt: Date;
  gapSeconds: number;
  evidence: "positive_bonus_operations_not_receipt_closure";
};

const populated = (value: string): boolean =>
  typeof value === "string" && value.trim().length > 0;

export const detectCrossRestaurantCardOperationProximity = (
  sourceOperations: readonly AntiFraudPositiveCardOperation[],
  maxGapSeconds = 1800,
): AntiFraudCrossRestaurantOperationProximity[] => {
  if (!Number.isInteger(maxGapSeconds) || maxGapSeconds <= 0 || maxGapSeconds > 3600) {
    throw new Error("Anti-Fraud operation proximity: invalid time window");
  }
  const grouped = new Map<string, AntiFraudPositiveCardOperation[]>();
  const distinctOperations = new Map<string, AntiFraudPositiveCardOperation>();

  for (const op of sourceOperations) {
    if (!op || !populated(op.cardKey) || !populated(op.operationKey) ||
        !populated(op.receiptKey) || !populated(op.restaurantKey) ||
        !(op.occurredAt instanceof Date) ||
        !Number.isFinite(op.occurredAt.getTime())) {
      throw new Error("Anti-Fraud operation proximity: incomplete source record");
    }

    const identity = JSON.stringify([op.cardKey, op.operationKey]);
    const prior = distinctOperations.get(identity);
    if (prior) {
      if (prior.receiptKey !== op.receiptKey ||
          prior.restaurantKey !== op.restaurantKey ||
          prior.occurredAt.getTime() !== op.occurredAt.getTime()) {
        throw new Error("Anti-Fraud operation proximity: conflicting operation identity");
      }
      continue;
    }
    distinctOperations.set(identity, op);
  }

  for (const op of distinctOperations.values()) {
    const group = grouped.get(op.cardKey) ?? [];
    group.push(op);
    grouped.set(op.cardKey, group);
  }

  const result: AntiFraudCrossRestaurantOperationProximity[] = [];
  const limitMs = maxGapSeconds * 1000;

  for (const [cardKey, group] of grouped) {
    group.sort((a, b) =>
      a.occurredAt.getTime() - b.occurredAt.getTime() ||
      a.operationKey.localeCompare(b.operationKey)
    );

    // One best (smallest-gap) piece of evidence for each physical receipt pair.
    const nearestByReceipts = new Map<string, AntiFraudCrossRestaurantOperationProximity>();
    for (let i = 0; i < group.length; i += 1) {
      const first = group[i];
      for (let j = i + 1; j < group.length; j += 1) {
        const second = group[j];
        const delta = second.occurredAt.getTime() - first.occurredAt.getTime();
        if (delta > limitMs) break;
        if (first.restaurantKey === second.restaurantKey) continue;
        // Receipt IDs may repeat between restaurants, so include restaurant in identity.
        const identity = JSON.stringify([
          first.restaurantKey, first.receiptKey,
          second.restaurantKey, second.receiptKey,
        ]);
        const candidate: AntiFraudCrossRestaurantOperationProximity = {
          cardKey,
          firstOperationKey: first.operationKey,
          secondOperationKey: second.operationKey,
          firstReceiptKey: first.receiptKey,
          secondReceiptKey: second.receiptKey,
          firstRestaurantKey: first.restaurantKey,
          secondRestaurantKey: second.restaurantKey,
          firstOccurredAt: new Date(first.occurredAt.getTime()),
          secondOccurredAt: new Date(second.occurredAt.getTime()),
          gapSeconds: Math.floor(delta / 1000),
          evidence: "positive_bonus_operations_not_receipt_closure",
        };
        const old = nearestByReceipts.get(identity);
        if (!old || candidate.gapSeconds < old.gapSeconds) {
          nearestByReceipts.set(identity, candidate);
        }
      }
    }
    result.push(...nearestByReceipts.values());
  }

  result.sort((a, b) =>
    a.gapSeconds - b.gapSeconds ||
    a.firstOccurredAt.getTime() - b.firstOccurredAt.getTime() ||
    a.cardKey.localeCompare(b.cardKey)
  );
  return result;
};
