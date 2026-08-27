import {
  corporatePhoneDirectory as bundledCorporatePhoneDirectory,
  type CorporatePhoneRecord,
} from "../data/corporate-phone-directory";

let currentDirectory: CorporatePhoneRecord[] = [];
let byPhone = new Map<string, CorporatePhoneRecord[]>();

export const DIRECTORY_STATS = {
  total: 0,
  megafon: 0,
  t2: 0,
  ambiguousPhones: 0,
};

const rebuildDirectoryIndex = (records: CorporatePhoneRecord[]): void => {
  currentDirectory = records.map((record) => ({ ...record }));
  byPhone = new Map<string, CorporatePhoneRecord[]>();
  for (const record of currentDirectory) {
    const bucket = byPhone.get(record.phone) ?? [];
    bucket.push(record);
    byPhone.set(record.phone, bucket);
  }

  DIRECTORY_STATS.total = currentDirectory.length;
  DIRECTORY_STATS.megafon = currentDirectory.filter((record) => record.operator === "MEGAFON").length;
  DIRECTORY_STATS.t2 = currentDirectory.filter((record) => record.operator === "T2").length;
  DIRECTORY_STATS.ambiguousPhones = [...byPhone.values()].filter((recordsForPhone) => recordsForPhone.length > 1).length;
};

export const replaceCorporatePhoneDirectory = (records: CorporatePhoneRecord[]): void => {
  rebuildDirectoryIndex(records);
};

export const getCorporatePhoneDirectorySnapshot = (): CorporatePhoneRecord[] =>
  currentDirectory.map((record) => ({ ...record }));

rebuildDirectoryIndex(bundledCorporatePhoneDirectory);

export const normalizeRussianPhone = (value: string): string | null => {
  const digits = value.replace(/\D/g, "");
  if (digits.length === 10) return `7${digits}`;
  if (digits.length !== 11) return null;
  if (digits.startsWith("8")) return `7${digits.slice(1)}`;
  if (digits.startsWith("7")) return digits;
  return null;
};

export const formatPhone = (normalizedPhone: string): string => {
  if (!/^7\d{10}$/.test(normalizedPhone)) return normalizedPhone;
  return `+7 ${normalizedPhone.slice(1, 4)} ${normalizedPhone.slice(4, 7)}-${normalizedPhone.slice(7, 9)}-${normalizedPhone.slice(9, 11)}`;
};

export const operatorLabel = (operator: string): string =>
  operator === "MEGAFON" ? "МегаФон" : operator === "T2" ? "T2" : operator;

export const findCorporatePhones = (input: string): CorporatePhoneRecord[] => {
  const normalized = normalizeRussianPhone(input);
  if (!normalized) return [];
  return byPhone.get(normalized) ?? [];
};

const valueOrDash = (value: string | null | undefined): string =>
  value?.trim() ? value.trim() : "—";

export const formatCorporateCard = (record: CorporatePhoneRecord): string => {
  const lines = [
    `📱 Номер: ${formatPhone(record.phone)}`,
    `Оператор: ${operatorLabel(record.operator)}`,
    `ООО: ${valueOrDash(record.legalEntity)}`,
    `ИНН: ${valueOrDash(record.inn)}`,
    `Лицевой счёт: ${valueOrDash(record.accountNumber)}`,
  ];
  if (record.restaurantName) lines.push(`Ресторан: ${record.restaurantName}`);
  if (record.lineType) lines.push(`Назначение: ${record.lineType}`);
  if (record.subscriberName) lines.push(`Абонент: ${record.subscriberName}`);
  return lines.join("\n");
};

export const formatChoiceLabel = (record: CorporatePhoneRecord, index: number): string => {
  const details = [record.legalEntity, record.lineType, record.subscriberName]
    .filter(Boolean)
    .join(" · ");
  return `${index + 1}. ${details || formatPhone(record.phone)}`;
};

export const formatProblemMessage = (
  record: CorporatePhoneRecord,
  problem: string,
): string =>
  [
    "Добрый день.",
    "",
    `ООО: ${valueOrDash(record.legalEntity)}`,
    `ИНН: ${valueOrDash(record.inn)}`,
    `Лицевой счёт: ${valueOrDash(record.accountNumber)}`,
    `Номер телефона: ${formatPhone(record.phone)}`,
    "",
    `Проблема: ${problem.trim()}`,
    "",
    "Прошу проверить причину и помочь с устранением.",
  ].join("\n");

export type { CorporatePhoneRecord };
