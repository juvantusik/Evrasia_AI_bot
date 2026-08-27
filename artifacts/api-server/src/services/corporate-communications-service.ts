import {
  corporatePhoneDirectoryTable,
  db,
  type CorporatePhoneDirectoryRecord,
} from "@workspace/db";
import { and, eq } from "drizzle-orm";

export type CorporateOperator = "MEGAFON" | "T2";

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

export const findCorporatePhone = async (
  input: string,
): Promise<CorporatePhoneDirectoryRecord | null> => {
  const phone = normalizeRussianPhone(input);
  if (!phone) return null;

  const record = await db.query.corporatePhoneDirectoryTable.findFirst({
    where: and(
      eq(corporatePhoneDirectoryTable.phone, phone),
      eq(corporatePhoneDirectoryTable.active, true),
    ),
  });
  return record ?? null;
};

export const operatorLabel = (operator: string): string => {
  if (operator === "MEGAFON") return "МегаФон";
  if (operator === "T2") return "T2";
  return operator;
};

export const formatCorporateCard = (record: CorporatePhoneDirectoryRecord): string => {
  const restaurant = record.restaurantName ? `\nРесторан: ${record.restaurantName}` : "";
  return [
    `📱 Номер: ${formatPhone(record.phone)}`,
    `Оператор: ${operatorLabel(record.operator)}`,
    `ООО: ${record.legalEntity}`,
    `ИНН: ${record.inn}`,
    `Лицевой счёт: ${record.accountNumber}${restaurant}`,
  ].join("\n");
};

export const formatProblemMessage = (
  record: CorporatePhoneDirectoryRecord,
  problem: string,
): string =>
  [
    "Добрый день.",
    "",
    `ООО: ${record.legalEntity}`,
    `ИНН: ${record.inn}`,
    `Лицевой счёт: ${record.accountNumber}`,
    `Номер телефона: ${formatPhone(record.phone)}`,
    "",
    `Проблема: ${problem.trim()}`,
    "",
    "Прошу проверить причину и помочь с устранением.",
  ].join("\n");
