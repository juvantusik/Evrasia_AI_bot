import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import { corporatePhoneDirectory } from "../data/corporate-phone-directory";
import { directoryRestaurantSeed } from "../../../samzaberu-ops/src/data/directory-preview-data";

export type DirectoryPhoneRecord = {
  id: string;
  phone: string;
  cityPhone: string | null;
  federalPhone: string | null;
  operator: "MEGAFON" | "T2";
  legalEntityId: string | null;
  legalEntity: string;
  inn: string | null;
  accountNumber: string | null;
  restaurantName: string | null;
  lineType: string | null;
  subscriberName: string | null;
};

export type DirectoryPhoneInput = Omit<DirectoryPhoneRecord, "id">;

export type DirectoryRestaurantRecord = {
  id: string;
  number: number;
  ou: string;
  legalEntityId: string | null;
  legalEntity: string;
  address: string;
  actualDirector: string;
  actualPhoneMode: "AUTO" | "PERSONAL" | "NONE";
  actualPersonalPhone: string;
  generalDirector: string;
  generalPhoneMode: "AUTO" | "PERSONAL" | "NONE";
  generalPersonalPhone: string;
  email: string;
};

export type DirectoryAuditRecord = {
  id: string;
  entityType: "PHONE" | "RESTAURANT" | "LEGAL_ENTITY";
  entityId: string;
  action: "ADD" | "UPDATE" | "DELETE";
  actor: string;
  beforeState: string | null;
  afterState: string | null;
  createdAt: Date;
};

type DirectoryDbClient = {
  query: (...args: any[]) => Promise<any>;
};

type T2SourcePair = {
  cityPhone: string | null;
  federalPhone: string | null;
};

type ResolvedLegalEntity = {
  id: string;
  name: string;
  inn: string | null;
  generalDirector: string | null;
};

const T2_CITY_PAIR_MIGRATION = "t2-city-federal-pairs-v1";

const t2SourcePairs: T2SourcePair[] = (() => {
  const pairs = new Map<string, T2SourcePair>();
  for (const record of corporatePhoneDirectory) {
    if (record.operator !== "T2") continue;
    if (record.lineType !== "Городской номер" && record.lineType !== "Федеральный номер") continue;
    const key = `${record.legalEntity.trim().toLocaleLowerCase("ru-RU")}\u0000${record.inn ?? ""}`;
    const pair = pairs.get(key) ?? { cityPhone: null, federalPhone: null };
    if (record.lineType === "Городской номер") pair.cityPhone = record.phone;
    if (record.lineType === "Федеральный номер") pair.federalPhone = record.phone;
    pairs.set(key, pair);
  }
  return [...pairs.values()];
})();

const normalizeNullable = (value: unknown): string | null => {
  if (typeof value !== "string") return null;
  const trimmed = value.trim();
  return trimmed || null;
};

export const normalizeRussianPhone = (value: unknown): string | null => {
  if (typeof value !== "string") return null;
  const digits = value.replace(/\D/g, "");
  if (digits.length === 10 && digits.startsWith("9")) return `7${digits}`;
  if (digits.length === 11 && digits.startsWith("8")) return `7${digits.slice(1)}`;
  if (digits.length === 11 && digits.startsWith("7")) return digits;
  return null;
};

const parseOperator = (value: unknown): "MEGAFON" | "T2" => {
  if (value === "MEGAFON" || value === "T2") return value;
  throw new Error("Поддерживаются только операторы МегаФон и T2.");
};

const resolveLegalEntity = async (value: unknown): Promise<ResolvedLegalEntity | null> => {
  const id = typeof value === "string" ? value.trim() : "";
  if (!id) return null;
  const result = await pool.query<ResolvedLegalEntity>(
    `SELECT id, name, inn, general_director AS "generalDirector"
     FROM corporate_legal_entities
     WHERE id = $1 AND active = true
     LIMIT 1`,
    [id],
  );
  if (!result.rows[0]) throw new Error("Выбранное юридическое лицо не найдено или архивировано.");
  return result.rows[0];
};

const parsePhoneInput = async (input: Record<string, unknown>): Promise<DirectoryPhoneInput> => {
  const operator = parseOperator(input.operator);
  const cityPhone = normalizeRussianPhone(input.cityPhone);
  const federalPhone = normalizeRussianPhone(input.federalPhone);
  const legacyPhone = normalizeRussianPhone(input.phone);
  const phone = cityPhone ?? federalPhone ?? legacyPhone;
  if (!phone) throw new Error("Укажите хотя бы один корректный российский номер телефона.");

  const resolved = await resolveLegalEntity(input.legalEntityId);
  const legacyLegalEntity = typeof input.legalEntity === "string" ? input.legalEntity.trim() : "";
  const legalEntity = resolved?.name ?? legacyLegalEntity;
  if (!legalEntity) throw new Error("Юридическое лицо обязательно.");

  return {
    phone,
    cityPhone,
    federalPhone,
    operator,
    legalEntityId: resolved?.id ?? null,
    legalEntity,
    inn: resolved?.inn ?? normalizeNullable(input.inn),
    accountNumber: normalizeNullable(input.accountNumber),
    restaurantName: normalizeNullable(input.restaurantName),
    lineType: normalizeNullable(input.lineType),
    subscriberName: normalizeNullable(input.subscriberName),
  };
};

const parsePhoneMode = (value: unknown): "AUTO" | "PERSONAL" | "NONE" => {
  if (value === "AUTO" || value === "PERSONAL" || value === "NONE") return value;
  return "AUTO";
};

const parseRestaurantInput = async (input: Record<string, unknown>): Promise<DirectoryRestaurantRecord> => {
  const id = String(input.id ?? "").trim();
  const number = Number(input.number);
  const ou = String(input.ou ?? "").trim();
  const resolved = await resolveLegalEntity(input.legalEntityId);
  const legacyLegalEntity = String(input.legalEntity ?? "").trim();
  const legalEntity = resolved?.name ?? legacyLegalEntity;
  const address = String(input.address ?? "").trim();
  if (!id || !Number.isInteger(number) || number <= 0 || !ou || !legalEntity || !address) {
    throw new Error("Для ресторана обязательны ID, номер, ОУ, юридическое лицо и адрес.");
  }
  return {
    id,
    number,
    ou,
    legalEntityId: resolved?.id ?? null,
    legalEntity,
    address,
    actualDirector: String(input.actualDirector ?? "").trim(),
    actualPhoneMode: parsePhoneMode(input.actualPhoneMode),
    actualPersonalPhone: String(input.actualPersonalPhone ?? "").trim(),
    generalDirector: resolved?.generalDirector ?? String(input.generalDirector ?? "").trim(),
    generalPhoneMode: parsePhoneMode(input.generalPhoneMode),
    generalPersonalPhone: String(input.generalPersonalPhone ?? "").trim(),
    email: String(input.email ?? "").trim(),
  };
};

const seedDirectoryRestaurants = async (): Promise<void> => {
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    for (const restaurant of directoryRestaurantSeed) {
      await client.query(
        `INSERT INTO corporate_directory_restaurants
         (id, number, ou, legal_entity, address, actual_director, general_director, email, active)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,true)
         ON CONFLICT (id) DO NOTHING`,
        [restaurant.id, restaurant.number, restaurant.ou, restaurant.legalEntity, restaurant.address, restaurant.actualDirector, restaurant.generalDirector, restaurant.email],
      );
    }
    await client.query("COMMIT");
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
};

const backfillT2CityPairs = async (): Promise<void> => {
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    const applied = await client.query(`SELECT 1 FROM corporate_directory_web_migrations WHERE id = $1 LIMIT 1`, [T2_CITY_PAIR_MIGRATION]);
    if (applied.rowCount) { await client.query("COMMIT"); return; }
    const legacy = await client.query(`SELECT 1 FROM corporate_phone_directory WHERE active = true AND operator = 'T2' AND line_type IN ('Городской номер', 'Федеральный номер') LIMIT 1`);
    if (!legacy.rowCount) { await client.query("COMMIT"); return; }

    for (const pair of t2SourcePairs) {
      const sourcePhones = [pair.cityPhone, pair.federalPhone].filter((phone): phone is string => Boolean(phone));
      const existing = await client.query<Pick<DirectoryPhoneRecord, "id" | "phone">>(`SELECT id, phone FROM corporate_phone_directory WHERE active = true AND operator = 'T2' AND phone = ANY($1::text[])`, [sourcePhones]);
      const cityRecord = pair.cityPhone ? existing.rows.find((record) => record.phone === pair.cityPhone) : undefined;
      const federalRecord = pair.federalPhone ? existing.rows.find((record) => record.phone === pair.federalPhone) : undefined;
      if (cityRecord) await client.query(`UPDATE corporate_phone_directory SET city_phone = coalesce(city_phone, $2), federal_phone = coalesce(federal_phone, $3) WHERE id = $1`, [cityRecord.id, pair.cityPhone, federalRecord ? pair.federalPhone : null]);
      if (federalRecord) await client.query(`UPDATE corporate_phone_directory SET federal_phone = coalesce(federal_phone, $2) WHERE id = $1`, [federalRecord.id, pair.federalPhone]);
    }

    await client.query(`INSERT INTO corporate_directory_web_migrations (id) VALUES ($1)`, [T2_CITY_PAIR_MIGRATION]);
    await client.query("COMMIT");
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
};

export const ensureDirectoryWebSchema = async (): Promise<void> => {
  await pool.query(`CREATE TABLE IF NOT EXISTS corporate_phone_directory (id text PRIMARY KEY, phone text NOT NULL, operator text NOT NULL, legal_entity text NOT NULL, inn text, account_number text, restaurant_name text, line_type text, subscriber_name text, active boolean NOT NULL DEFAULT true, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now())`);
  await pool.query(`ALTER TABLE corporate_phone_directory ADD COLUMN IF NOT EXISTS city_phone text`);
  await pool.query(`ALTER TABLE corporate_phone_directory ADD COLUMN IF NOT EXISTS federal_phone text`);
  await pool.query(`ALTER TABLE corporate_phone_directory ADD COLUMN IF NOT EXISTS legal_entity_id text`);
  await pool.query(`CREATE TABLE IF NOT EXISTS corporate_directory_web_migrations (id text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())`);
  await pool.query(`UPDATE corporate_phone_directory SET city_phone = phone WHERE operator = 'MEGAFON' AND city_phone IS NULL AND phone LIKE '7812%'`);
  await pool.query(`UPDATE corporate_phone_directory SET federal_phone = phone WHERE operator = 'MEGAFON' AND federal_phone IS NULL AND phone NOT LIKE '7812%'`);
  await backfillT2CityPairs();
  await pool.query(`CREATE TABLE IF NOT EXISTS corporate_directory_restaurants (id text PRIMARY KEY, number integer NOT NULL, ou text NOT NULL, legal_entity text NOT NULL, legal_entity_id text, address text NOT NULL, actual_director text NOT NULL DEFAULT '', actual_phone_mode text NOT NULL DEFAULT 'AUTO', actual_personal_phone text NOT NULL DEFAULT '', general_director text NOT NULL DEFAULT '', general_phone_mode text NOT NULL DEFAULT 'AUTO', general_personal_phone text NOT NULL DEFAULT '', email text NOT NULL DEFAULT '', active boolean NOT NULL DEFAULT true, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now())`);
  await pool.query(`ALTER TABLE corporate_directory_restaurants ADD COLUMN IF NOT EXISTS legal_entity_id text`);
  await pool.query(`CREATE TABLE IF NOT EXISTS corporate_directory_web_audit (id text PRIMARY KEY, entity_type text NOT NULL, entity_id text NOT NULL, action text NOT NULL, actor text NOT NULL, before_state text, after_state text, created_at timestamptz NOT NULL DEFAULT now())`);
  await seedDirectoryRestaurants();
};

const phoneSelect = `
  SELECT p.id,
         p.phone,
         p.city_phone AS "cityPhone",
         p.federal_phone AS "federalPhone",
         p.operator,
         p.legal_entity_id AS "legalEntityId",
         coalesce(le.name, p.legal_entity) AS "legalEntity",
         coalesce(le.inn, p.inn) AS inn,
         p.account_number AS "accountNumber",
         p.restaurant_name AS "restaurantName",
         p.line_type AS "lineType",
         p.subscriber_name AS "subscriberName"
  FROM corporate_phone_directory p
  LEFT JOIN corporate_legal_entities le
    ON le.id = p.legal_entity_id
   AND le.active = true
`;

export const listDirectoryPhones = async (): Promise<DirectoryPhoneRecord[]> => {
  const result = await pool.query<DirectoryPhoneRecord>(`${phoneSelect} WHERE p.active = true ORDER BY p.operator, p.phone, coalesce(le.name, p.legal_entity)`);
  const pairedFederalPhones = new Set(result.rows.filter((record) => record.operator === "T2" && record.cityPhone && record.federalPhone).map((record) => record.federalPhone));
  return result.rows.filter((record) => !(record.operator === "T2" && record.lineType === "Федеральный номер" && !record.cityPhone && pairedFederalPhones.has(record.phone)));
};

const getPhone = async (id: string): Promise<DirectoryPhoneRecord | null> => {
  const result = await pool.query<DirectoryPhoneRecord>(`${phoneSelect} WHERE p.id = $1 AND p.active = true LIMIT 1`, [id]);
  return result.rows[0] ?? null;
};

const writeAudit = async (client: { query: (text: string, values?: unknown[]) => Promise<unknown> }, input: Omit<DirectoryAuditRecord, "id" | "createdAt">): Promise<void> => {
  await client.query(`INSERT INTO corporate_directory_web_audit (id, entity_type, entity_id, action, actor, before_state, after_state) VALUES ($1, $2, $3, $4, $5, $6, $7)`, [randomUUID(), input.entityType, input.entityId, input.action, input.actor, input.beforeState, input.afterState]);
};

const findT2FederalAliasId = async (client: DirectoryDbClient, ownerId: string, federalPhone: string | null): Promise<string | null> => {
  if (!federalPhone) return null;
  const result = await client.query(`SELECT id FROM corporate_phone_directory WHERE id <> $1 AND active = true AND operator = 'T2' AND phone = $2 AND line_type = 'Федеральный номер' LIMIT 1`, [ownerId, federalPhone]);
  return (result.rows[0] as { id: string } | undefined)?.id ?? null;
};

const syncT2FederalAlias = async (client: DirectoryDbClient, ownerId: string, before: DirectoryPhoneRecord | null, after: DirectoryPhoneRecord | null): Promise<void> => {
  let aliasId = before?.operator === "T2" ? await findT2FederalAliasId(client, ownerId, before.federalPhone) : null;
  const needsAlias = Boolean(after?.operator === "T2" && after.cityPhone && after.federalPhone && after.cityPhone !== after.federalPhone);
  if (!needsAlias || !after?.federalPhone) { if (aliasId) await client.query(`DELETE FROM corporate_phone_directory WHERE id = $1`, [aliasId]); return; }
  aliasId ??= await findT2FederalAliasId(client, ownerId, after.federalPhone);
  if (!aliasId) aliasId = randomUUID();
  await client.query(`INSERT INTO corporate_phone_directory (id, phone, city_phone, federal_phone, operator, legal_entity_id, legal_entity, inn, account_number, restaurant_name, line_type, subscriber_name, active) VALUES ($1,$2,NULL,$2,'T2',$3,$4,$5,$6,$7,'Федеральный номер',$8,true) ON CONFLICT (id) DO UPDATE SET phone=excluded.phone, city_phone=NULL, federal_phone=excluded.federal_phone, operator='T2', legal_entity_id=excluded.legal_entity_id, legal_entity=excluded.legal_entity, inn=excluded.inn, account_number=excluded.account_number, restaurant_name=excluded.restaurant_name, line_type='Федеральный номер', subscriber_name=excluded.subscriber_name, active=true, updated_at=now()`, [aliasId, after.federalPhone, after.legalEntityId, after.legalEntity, after.inn, after.accountNumber, after.restaurantName, after.subscriberName]);
};

export const addDirectoryPhone = async (actor: string, raw: Record<string, unknown>): Promise<DirectoryPhoneRecord> => {
  const input = await parsePhoneInput(raw);
  const exact = await pool.query(`SELECT id FROM corporate_phone_directory WHERE active = true AND phone = $1 AND operator = $2 AND lower(legal_entity) = lower($3) AND coalesce(account_number, '') = coalesce($4, '') AND coalesce(subscriber_name, '') = coalesce($5, '') LIMIT 1`, [input.phone, input.operator, input.legalEntity, input.accountNumber, input.subscriberName]);
  if (exact.rowCount) throw new Error("Такая карточка номера уже существует.");
  const record: DirectoryPhoneRecord = { id: randomUUID(), ...input };
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    await client.query(`INSERT INTO corporate_phone_directory (id, phone, city_phone, federal_phone, operator, legal_entity_id, legal_entity, inn, account_number, restaurant_name, line_type, subscriber_name, active) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,true)`, [record.id, record.phone, record.cityPhone, record.federalPhone, record.operator, record.legalEntityId, record.legalEntity, record.inn, record.accountNumber, record.restaurantName, record.lineType, record.subscriberName]);
    await syncT2FederalAlias(client, record.id, null, record);
    await writeAudit(client, { entityType: "PHONE", entityId: record.id, action: "ADD", actor, beforeState: null, afterState: JSON.stringify(record) });
    await client.query("COMMIT");
  } catch (error) { await client.query("ROLLBACK"); throw error; } finally { client.release(); }
  return record;
};

export const updateDirectoryPhone = async (actor: string, id: string, raw: Record<string, unknown>): Promise<DirectoryPhoneRecord> => {
  const before = await getPhone(id);
  if (!before) throw new Error("Запись номера не найдена.");
  const input = await parsePhoneInput(raw);
  const after: DirectoryPhoneRecord = { id, ...input };
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    await client.query(`UPDATE corporate_phone_directory SET phone=$2, city_phone=$3, federal_phone=$4, operator=$5, legal_entity_id=$6, legal_entity=$7, inn=$8, account_number=$9, restaurant_name=$10, line_type=$11, subscriber_name=$12, updated_at=now() WHERE id=$1`, [id, after.phone, after.cityPhone, after.federalPhone, after.operator, after.legalEntityId, after.legalEntity, after.inn, after.accountNumber, after.restaurantName, after.lineType, after.subscriberName]);
    await syncT2FederalAlias(client, id, before, after);
    await writeAudit(client, { entityType: "PHONE", entityId: id, action: "UPDATE", actor, beforeState: JSON.stringify(before), afterState: JSON.stringify(after) });
    await client.query("COMMIT");
  } catch (error) { await client.query("ROLLBACK"); throw error; } finally { client.release(); }
  return after;
};

export const deleteDirectoryPhone = async (actor: string, id: string): Promise<DirectoryPhoneRecord> => {
  const before = await getPhone(id);
  if (!before) throw new Error("Запись номера не найдена.");
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    await syncT2FederalAlias(client, id, before, null);
    await client.query(`DELETE FROM corporate_phone_directory WHERE id=$1`, [id]);
    await writeAudit(client, { entityType: "PHONE", entityId: id, action: "DELETE", actor, beforeState: JSON.stringify(before), afterState: null });
    await client.query("COMMIT");
  } catch (error) { await client.query("ROLLBACK"); throw error; } finally { client.release(); }
  return before;
};

const restaurantSelect = `
  SELECT r.id,
         r.number,
         r.ou,
         r.legal_entity_id AS "legalEntityId",
         coalesce(le.name, r.legal_entity) AS "legalEntity",
         r.address,
         r.actual_director AS "actualDirector",
         r.actual_phone_mode AS "actualPhoneMode",
         r.actual_personal_phone AS "actualPersonalPhone",
         coalesce(le.general_director, r.general_director) AS "generalDirector",
         r.general_phone_mode AS "generalPhoneMode",
         r.general_personal_phone AS "generalPersonalPhone",
         r.email
  FROM corporate_directory_restaurants r
  LEFT JOIN corporate_legal_entities le
    ON le.id = r.legal_entity_id
   AND le.active = true
`;

export const listDirectoryRestaurants = async (): Promise<DirectoryRestaurantRecord[]> => {
  const result = await pool.query<DirectoryRestaurantRecord>(`${restaurantSelect} WHERE r.active = true ORDER BY r.number`);
  return result.rows;
};

const getRestaurant = async (id: string): Promise<DirectoryRestaurantRecord | null> => {
  const result = await pool.query<DirectoryRestaurantRecord>(`${restaurantSelect} WHERE r.id=$1 AND r.active=true LIMIT 1`, [id]);
  return result.rows[0] ?? null;
};

export const upsertDirectoryRestaurant = async (actor: string, raw: Record<string, unknown>): Promise<DirectoryRestaurantRecord> => {
  const after = await parseRestaurantInput(raw);
  const before = await getRestaurant(after.id);
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    await client.query(`INSERT INTO corporate_directory_restaurants (id, number, ou, legal_entity_id, legal_entity, address, actual_director, actual_phone_mode, actual_personal_phone, general_director, general_phone_mode, general_personal_phone, email, active) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,true) ON CONFLICT (id) DO UPDATE SET number=excluded.number, ou=excluded.ou, legal_entity_id=excluded.legal_entity_id, legal_entity=excluded.legal_entity, address=excluded.address, actual_director=excluded.actual_director, actual_phone_mode=excluded.actual_phone_mode, actual_personal_phone=excluded.actual_personal_phone, general_director=excluded.general_director, general_phone_mode=excluded.general_phone_mode, general_personal_phone=excluded.general_personal_phone, email=excluded.email, active=true, updated_at=now()`, [after.id, after.number, after.ou, after.legalEntityId, after.legalEntity, after.address, after.actualDirector, after.actualPhoneMode, after.actualPersonalPhone, after.generalDirector, after.generalPhoneMode, after.generalPersonalPhone, after.email]);
    await writeAudit(client, { entityType: "RESTAURANT", entityId: after.id, action: before ? "UPDATE" : "ADD", actor, beforeState: before ? JSON.stringify(before) : null, afterState: JSON.stringify(after) });
    await client.query("COMMIT");
  } catch (error) { await client.query("ROLLBACK"); throw error; } finally { client.release(); }
  return after;
};

export const listDirectoryAudit = async (limit = 100): Promise<DirectoryAuditRecord[]> => {
  const safeLimit = Math.max(1, Math.min(500, Math.trunc(limit) || 100));
  const result = await pool.query<DirectoryAuditRecord>(`SELECT id, entity_type AS "entityType", entity_id AS "entityId", action, actor, before_state AS "beforeState", after_state AS "afterState", created_at AS "createdAt" FROM corporate_directory_web_audit ORDER BY created_at DESC LIMIT $1`, [safeLimit]);
  return result.rows;
};