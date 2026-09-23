import type {
  BitrixAntiFraudCheckinRecord,
  BitrixAntiFraudCheckinResult,
} from "./bitrix-antifraud-checkin-gateway";

export type AntiFraudOperatorPhysicalHistoryRow = {
  physicalEventId: string;
  bitrixUserId: number;
  occurredAt: Date;
  restaurant: string;
};

export type AntiFraudOperatorPhysicalHistorySnapshot = {
  from: Date;
  to: Date;
  records: AntiFraudOperatorPhysicalHistoryRow[];
  unresolvedCardCount: number;
};

export const buildAntiFraudOperatorPhysicalHistorySnapshot = (
  history: BitrixAntiFraudCheckinResult,
  expectedBitrixUserId: number,
): AntiFraudOperatorPhysicalHistorySnapshot => {
  const userId = Number(expectedBitrixUserId);
  if (!Number.isInteger(userId) || userId <= 0) {
    throw new Error("USER_ID ручной физической истории должен быть положительным integer");
  }

  const unresolvedCardCount = Number(history.unresolvedCardCount ?? 0);
  if (!Number.isInteger(unresolvedCardCount) || unresolvedCardCount < 0) {
    throw new Error("Физическая история содержит некорректный unresolved_card_count");
  }
  if (unresolvedCardCount > 0) {
    throw new Error("Физическая история не разрешила все карты USER_ID");
  }

  const seen = new Set<string>();
  const records = history.records.map((record: BitrixAntiFraudCheckinRecord) => {
    if (Number(record.bitrixUserId) !== userId) {
      throw new Error("Физическая история содержит USER_ID вне операторского расследования");
    }

    const physicalEventId = String(record.sourceRestisId ?? "").trim();
    if (!physicalEventId || seen.has(physicalEventId)) {
      throw new Error("Физическая история содержит некорректный или повторный event id");
    }
    seen.add(physicalEventId);

    const occurredAt = new Date(record.occurredAt);
    if (Number.isNaN(occurredAt.getTime())) {
      throw new Error("Физическая история содержит некорректное время события");
    }

    const restaurant = String(record.restaurant ?? "").trim();
    if (!restaurant) {
      throw new Error("Физическая история содержит пустой ресторан");
    }

    return {
      physicalEventId,
      bitrixUserId: userId,
      occurredAt,
      restaurant,
    };
  });

  const from = new Date(history.from);
  const to = new Date(history.to);
  if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime()) || from.getTime() > to.getTime()) {
    throw new Error("Физическая история содержит некорректное окно");
  }

  return {
    from,
    to,
    records,
    unresolvedCardCount,
  };
};
