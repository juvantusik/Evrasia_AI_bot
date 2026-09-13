import { createHash, randomUUID } from "node:crypto";
import {
  corporateLegalEntitiesTable,
  corporatePhoneAuditTable,
  corporatePhoneDirectoryTable,
  db,
  type CorporateLegalEntity,
  type CorporatePhoneDirectoryRecord,
} from "@workspace/db";
import { and, asc, eq, ilike, or } from "drizzle-orm";
import {
  corporatePhoneDirectory as bundledCorporatePhoneDirectory,
  type CorporatePhoneRecord,
} from "../data/corporate-phone-directory";
import { canEditCorporateDirectory } from "./bot-access-service";
import { getBotSetting, setBotSetting } from "./bot-settings-service";
import {
  normalizeRussianPhone,
  replaceCorporatePhoneDirectory,
} from "./corporate-communications-v2";

const DIRECTORY_SEED_SETTING = "corporate_directory_seed_v1";
const SEED_BATCH_SIZE = 100;

export type CorporateDirectoryAdminRecord = {
  id: string;
  phone: string;
  operator: "MEGAFON" | "T2";
  legalEntity: string;
  inn: string | null;
  accountNumber: string | null;
  restaurantName: string | null;
  lineType: string | null;
  subscriberName: string | null;
};

export type CorporatePhoneMutationInput = Omit<CorporateDirectoryAdminRecord, "id">;

const stableId = (prefix: string, value: string): string =>
  `${prefix}-${createHash("sha1").update(value).digest("hex").slice(0, 24)}`;

const normalizeNullable = (value: string | null | undefined): string | null => {
  const trimmed = value?.trim();
  return trimmed ? trimmed : null;
};

const toAdminRecord = (row: CorporatePhoneDirectoryRecord): CorporateDirectoryAdminRecord | null => {
  if (row.operator !== "MEGAFON" && row.operator !== "T2") return null;
  return {
    id: row.id,
    phone: row.phone,
    operator: row.operator,
    legalEntity: row.legalEntity,
    inn: row.inn,
    accountNumber: row.accountNumber,
    restaurantName: row.restaurantName,
    lineType: row.lineType,
    subscriberName: row.subscriberName,
  };
};

const toPublicRecord = (record: CorporateDirectoryAdminRecord): CorporatePhoneRecord => ({
  phone: record.phone,
  operator: record.operator,
  legalEntity: record.legalEntity,
  inn: record.inn,
  accountNumber: record.accountNumber,
  restaurantName: record.restaurantName,
  lineType: record.lineType,
  subscriberName: record.subscriberName,
});

const assertEditor = (telegramUserId: string): void => {
  if (!canEditCorporateDirectory(telegramUserId)) {
    throw new Error("Недостаточно прав для изменения корпоративного справочника.");
  }
};

const normalizeMutationInput = (input: CorporatePhoneMutationInput): CorporatePhoneMutationInput => {
  const phone = normalizeRussianPhone(input.phone);
  if (!phone) throw new Error("Не удалось распознать российский номер телефона.");
  if (input.operator !== "MEGAFON" && input.operator !== "T2") {
    throw new Error("Поддерживаются только операторы МегаФон и T2.");
  }
  const legalEntity = input.legalEntity.trim();
  if (!legalEntity) throw new Error("Юридическое лицо обязательно.");
  return {
    phone,
    operator: input.operator,
    legalEntity,
    inn: normalizeNullable(input.inn),
    accountNumber: normalizeNullable(input.accountNumber),
    restaurantName: normalizeNullable(input.restaurantName),
    lineType: normalizeNullable(input.lineType),
    subscriberName: normalizeNullable(input.subscriberName),
  };
};

const auditState = (record: CorporateDirectoryAdminRecord | null): string | null =>
  record ? JSON.stringify(record) : null;

export const refreshCorporatePhoneDirectoryCache = async (): Promise<void> => {
  const rows = await db
    .select()
    .from(corporatePhoneDirectoryTable)
    .where(eq(corporatePhoneDirectoryTable.active, true))
    .orderBy(asc(corporatePhoneDirectoryTable.phone));
  const records = rows.map(toAdminRecord).filter((record): record is CorporateDirectoryAdminRecord => Boolean(record));
  replaceCorporatePhoneDirectory(records.map(toPublicRecord));
};

const buildLegalEntitySeed = (): Array<{ id: string; name: string; inn: string | null }> => {
  const entities = new Map<string, { id: string; name: string; inn: string | null }>();
  for (const record of bundledCorporatePhoneDirectory) {
    const name = record.legalEntity.trim();
    if (!name) continue;
    const inn = normalizeNullable(record.inn);
    const key = inn ? `inn:${inn}` : `name:${name.toLocaleLowerCase("ru-RU")}`;
    if (!entities.has(key)) {
      entities.set(key, {
        id: stableId("le", key),
        name,
        inn,
      });
    }
  }
  return [...entities.values()].sort((a, b) => a.name.localeCompare(b.name, "ru"));
};

const buildPhoneSeed = (): Array<CorporatePhoneDirectoryRecord> =>
  bundledCorporatePhoneDirectory.map((record, index) => ({
    id: stableId(
      "phone",
      `${index}|${record.phone}|${record.operator}|${record.legalEntity}|${record.accountNumber ?? ""}|${record.restaurantName ?? ""}|${record.lineType ?? ""}|${record.subscriberName ?? ""}`,
    ),
    phone: record.phone,
    operator: record.operator,
    legalEntity: record.legalEntity,
    inn: record.inn,
    accountNumber: record.accountNumber,
    restaurantName: record.restaurantName,
    lineType: record.lineType,
    subscriberName: record.subscriberName,
    active: true,
    createdAt: new Date(),
    updatedAt: new Date(),
  }));

export const initializeCorporatePhoneDirectory = async (): Promise<void> => {
  const seedCompleted = await getBotSetting(DIRECTORY_SEED_SETTING);
  if (seedCompleted !== "1") {
    const phoneSeed = buildPhoneSeed();
    for (let offset = 0; offset < phoneSeed.length; offset += SEED_BATCH_SIZE) {
      await db
        .insert(corporatePhoneDirectoryTable)
        .values(phoneSeed.slice(offset, offset + SEED_BATCH_SIZE))
        .onConflictDoNothing({ target: corporatePhoneDirectoryTable.id });
    }

    const legalEntities = buildLegalEntitySeed();
    if (legalEntities.length > 0) {
      await db
        .insert(corporateLegalEntitiesTable)
        .values(legalEntities)
        .onConflictDoNothing({ target: corporateLegalEntitiesTable.id });
    }
    await setBotSetting(DIRECTORY_SEED_SETTING, "1");
  }

  await refreshCorporatePhoneDirectoryCache();
};

export const listCorporateLegalEntities = async (): Promise<CorporateLegalEntity[]> =>
  db
    .select()
    .from(corporateLegalEntitiesTable)
    .where(eq(corporateLegalEntitiesTable.active, true))
    .orderBy(asc(corporateLegalEntitiesTable.name));

export const getCorporateLegalEntity = async (id: string): Promise<CorporateLegalEntity | null> =>
  (await db.query.corporateLegalEntitiesTable.findFirst({
    where: eq(corporateLegalEntitiesTable.id, id),
  })) ?? null;

export const listKnownAccountNumbers = async (
  operator: "MEGAFON" | "T2",
  legalEntity: string,
): Promise<string[]> => {
  const rows = await db
    .select({ accountNumber: corporatePhoneDirectoryTable.accountNumber })
    .from(corporatePhoneDirectoryTable)
    .where(
      and(
        eq(corporatePhoneDirectoryTable.active, true),
        eq(corporatePhoneDirectoryTable.operator, operator),
        eq(corporatePhoneDirectoryTable.legalEntity, legalEntity),
      ),
    );
  return [...new Set(rows.map((row) => normalizeNullable(row.accountNumber)).filter((value): value is string => Boolean(value)))].sort();
};

export const findCorporateDirectoryRecords = async (query: string): Promise<CorporateDirectoryAdminRecord[]> => {
  const trimmed = query.trim();
  if (!trimmed) return [];
  const normalizedPhone = normalizeRussianPhone(trimmed);
  const rows = normalizedPhone
    ? await db
        .select()
        .from(corporatePhoneDirectoryTable)
        .where(
          and(
            eq(corporatePhoneDirectoryTable.active, true),
            eq(corporatePhoneDirectoryTable.phone, normalizedPhone),
          ),
        )
        .limit(20)
    : await db
        .select()
        .from(corporatePhoneDirectoryTable)
        .where(
          and(
            eq(corporatePhoneDirectoryTable.active, true),
            or(
              ilike(corporatePhoneDirectoryTable.subscriberName, `%${trimmed}%`),
              ilike(corporatePhoneDirectoryTable.legalEntity, `%${trimmed}%`),
              ilike(corporatePhoneDirectoryTable.restaurantName, `%${trimmed}%`),
            ),
          ),
        )
        .orderBy(asc(corporatePhoneDirectoryTable.phone))
        .limit(20);
  return rows.map(toAdminRecord).filter((record): record is CorporateDirectoryAdminRecord => Boolean(record));
};

export const getCorporateDirectoryRecord = async (id: string): Promise<CorporateDirectoryAdminRecord | null> => {
  const row = await db.query.corporatePhoneDirectoryTable.findFirst({
    where: and(
      eq(corporatePhoneDirectoryTable.id, id),
      eq(corporatePhoneDirectoryTable.active, true),
    ),
  });
  return row ? toAdminRecord(row) : null;
};

export const addCorporateDirectoryRecord = async (
  adminTelegramUserId: string,
  input: CorporatePhoneMutationInput,
): Promise<CorporateDirectoryAdminRecord> => {
  assertEditor(adminTelegramUserId);
  const normalized = normalizeMutationInput(input);
  const duplicate = await db.query.corporatePhoneDirectoryTable.findFirst({
    where: and(
      eq(corporatePhoneDirectoryTable.active, true),
      eq(corporatePhoneDirectoryTable.phone, normalized.phone),
    ),
  });
  if (duplicate) {
    throw new Error("Этот номер уже есть в справочнике. Используйте «Изменить данные» вместо добавления второй карточки.");
  }

  const record: CorporateDirectoryAdminRecord = { id: randomUUID(), ...normalized };
  await db.transaction(async (tx) => {
    await tx.insert(corporatePhoneDirectoryTable).values({ ...record, active: true });
    await tx.insert(corporatePhoneAuditTable).values({
      id: randomUUID(),
      action: "ADD",
      adminTelegramUserId,
      phoneRecordId: record.id,
      phone: record.phone,
      beforeState: null,
      afterState: auditState(record),
    });
  });
  await refreshCorporatePhoneDirectoryCache();
  return record;
};

export const updateCorporateDirectoryRecord = async (
  adminTelegramUserId: string,
  recordId: string,
  input: CorporatePhoneMutationInput,
): Promise<CorporateDirectoryAdminRecord> => {
  assertEditor(adminTelegramUserId);
  const before = await getCorporateDirectoryRecord(recordId);
  if (!before) throw new Error("Запись не найдена или уже удалена.");
  const normalized = normalizeMutationInput(input);

  if (normalized.phone !== before.phone) {
    const duplicate = await db.query.corporatePhoneDirectoryTable.findFirst({
      where: and(
        eq(corporatePhoneDirectoryTable.active, true),
        eq(corporatePhoneDirectoryTable.phone, normalized.phone),
      ),
    });
    if (duplicate && duplicate.id !== recordId) {
      throw new Error("Номер уже используется другой карточкой справочника.");
    }
  }

  const after: CorporateDirectoryAdminRecord = { id: recordId, ...normalized };
  await db.transaction(async (tx) => {
    await tx
      .update(corporatePhoneDirectoryTable)
      .set({
        phone: after.phone,
        operator: after.operator,
        legalEntity: after.legalEntity,
        inn: after.inn,
        accountNumber: after.accountNumber,
        restaurantName: after.restaurantName,
        lineType: after.lineType,
        subscriberName: after.subscriberName,
        updatedAt: new Date(),
      })
      .where(eq(corporatePhoneDirectoryTable.id, recordId));
    await tx.insert(corporatePhoneAuditTable).values({
      id: randomUUID(),
      action: "UPDATE",
      adminTelegramUserId,
      phoneRecordId: recordId,
      phone: after.phone,
      beforeState: auditState(before),
      afterState: auditState(after),
    });
  });
  await refreshCorporatePhoneDirectoryCache();
  return after;
};

export const deleteCorporateDirectoryRecord = async (
  adminTelegramUserId: string,
  recordId: string,
): Promise<CorporateDirectoryAdminRecord> => {
  assertEditor(adminTelegramUserId);
  const before = await getCorporateDirectoryRecord(recordId);
  if (!before) throw new Error("Запись не найдена или уже удалена.");

  await db.transaction(async (tx) => {
    await tx.delete(corporatePhoneDirectoryTable).where(eq(corporatePhoneDirectoryTable.id, recordId));
    await tx.insert(corporatePhoneAuditTable).values({
      id: randomUUID(),
      action: "DELETE",
      adminTelegramUserId,
      phoneRecordId: recordId,
      phone: before.phone,
      beforeState: auditState(before),
      afterState: null,
    });
  });
  await refreshCorporatePhoneDirectoryCache();
  return before;
};
