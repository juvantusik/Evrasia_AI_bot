export type CheckinScoutObservation = {
  sourceRestisId: string;
  bitrixUserId: number;
  occurredAt: Date;
};

export type CheckinScoutDailySummary = {
  bitrixUserId: number;
  days: Array<{ day: string; checkins: number }>;
};

const moscowDay = (date: Date): string => {
  const parts = new Intl.DateTimeFormat("en", {
    timeZone: "Europe/Moscow",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(date);
  const values = new Map(parts.map((part) => [part.type, part.value]));
  const day = `${values.get("year")}-${values.get("month")}-${values.get("day")}`;
  if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) {
    throw new Error("Check-in Scout не смог определить московскую календарную дату");
  }
  return day;
};

// Добавлено 19.09.2026 ИТ Директор Евразии
// Чистое бизнес-правило без БД/HTTP: 1 чекин/сутки является нормой и отбрасывается.
// Persistent Scout candidate появляется только при 2+ уникальных чекинах в московские сутки.
export const summarizeCheckinScoutSnapshot = (
  records: CheckinScoutObservation[],
): CheckinScoutDailySummary[] => {
  const byUser = new Map<number, Map<string, Set<string>>>();

  for (const record of records) {
    const userId = Number(record.bitrixUserId);
    if (!Number.isInteger(userId) || userId <= 0) continue;
    const day = moscowDay(record.occurredAt);

    if (!byUser.has(userId)) byUser.set(userId, new Map());
    const days = byUser.get(userId)!;
    if (!days.has(day)) days.set(day, new Set());
    days.get(day)!.add(record.sourceRestisId);
  }

  const summaries: CheckinScoutDailySummary[] = [];
  for (const [bitrixUserId, days] of byUser) {
    const suspiciousDays = [...days.entries()]
      .map(([day, ids]) => ({ day, checkins: ids.size }))
      .filter((item) => item.checkins >= 2)
      .sort((a, b) => a.day.localeCompare(b.day));

    if (suspiciousDays.length) {
      summaries.push({ bitrixUserId, days: suspiciousDays });
    }
  }

  return summaries.sort((a, b) => a.bitrixUserId - b.bitrixUserId);
};
