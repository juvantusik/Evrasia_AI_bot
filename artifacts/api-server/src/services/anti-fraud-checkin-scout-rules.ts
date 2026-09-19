export type CheckinScoutObservation = {
  sourceRestisId: string;
  bitrixUserId: number;
  occurredAt: Date;
};

export type CheckinScoutDailySummary = {
  bitrixUserId: number;
  days: Array<{ day: string; checkins: number }>;
};

export type CheckinScoutHistoryConfirmation = {
  days2Plus7d: number;
  days3Plus60d: number;
  confirmed: boolean;
};

export const CHECKIN_SCOUT_CONFIRM_2PLUS_DAYS_7D = 3;
export const CHECKIN_SCOUT_CONFIRM_3PLUS_DAYS_60D = 3;

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


// Добавлено 19.09.2026 ИТ Директор Евразии
// Deep-check подтверждается только по физическим чекинам COfflineOrderHl.
// 3 разных дня с 2+ за последние 7 московских дней ИЛИ
// 3 разных дня с 3+ за 60 дней => подтверждённая регулярная частота.
export const evaluateCheckinScoutPhysicalHistory = (
  records: CheckinScoutObservation[],
  now = new Date(),
): CheckinScoutHistoryConfirmation => {
  if (Number.isNaN(now.getTime())) {
    throw new Error("Check-in Scout получил некорректное время deep-check");
  }

  const userIds = new Set(
    records
      .map((record) => Number(record.bitrixUserId))
      .filter((value) => Number.isInteger(value) && value > 0),
  );
  if (userIds.size > 1) {
    throw new Error("Check-in Scout physical history должна относиться к одному USER_ID");
  }

  const summaries = summarizeCheckinScoutSnapshot(records);
  const suspiciousDays = summaries[0]?.days ?? [];

  const today = moscowDay(now);
  const sevenDayBoundaryDate = new Date(`${today}T00:00:00.000Z`);
  sevenDayBoundaryDate.setUTCDate(sevenDayBoundaryDate.getUTCDate() - 6);
  const sevenDayBoundary = sevenDayBoundaryDate.toISOString().slice(0, 10);

  const days2Plus7d = suspiciousDays.filter(
    (item) => item.day >= sevenDayBoundary && item.checkins >= 2,
  ).length;
  const days3Plus60d = suspiciousDays.filter((item) => item.checkins >= 3).length;

  return {
    days2Plus7d,
    days3Plus60d,
    confirmed:
      days2Plus7d >= CHECKIN_SCOUT_CONFIRM_2PLUS_DAYS_7D
      || days3Plus60d >= CHECKIN_SCOUT_CONFIRM_3PLUS_DAYS_60D,
  };
};
