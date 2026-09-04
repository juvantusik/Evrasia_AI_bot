// Добавлено 03.09.2026 ИТ Директор Евразии
// Защита от разрушительного reconcile: успешный HTTP-ответ с пустым или явно усечённым
// snapshot не должен массово удалять текущие user-device связи из PostgreSQL.
export const assertTrustedDeviceLinkSnapshotSafe = (
  existingLinks: number,
  fetchedLinks: number,
): void => {
  if (
    !Number.isSafeInteger(existingLinks) ||
    existingLinks < 0 ||
    !Number.isSafeInteger(fetchedLinks) ||
    fetchedLinks < 0
  ) {
    throw new Error("Trusted Device snapshot guard получил некорректные счётчики");
  }

  if (existingLinks === 0) {
    return;
  }

  if (fetchedLinks === 0) {
    throw new Error(
      `Trusted Device links snapshot отклонён: existing=${existingLinks}; fetched=0`,
    );
  }

  if (fetchedLinks * 2 < existingLinks) {
    throw new Error(
      `Trusted Device links snapshot отклонён как подозрительно усечённый: existing=${existingLinks}; fetched=${fetchedLinks}`,
    );
  }
};
