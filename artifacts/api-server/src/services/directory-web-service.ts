import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import { directoryRestaurantSeed } from "../../../samzaberu-ops/src/data/directory-preview-data";

export type DirectoryPhoneRecord = {
  id: string;
  phone: string;
  cityPhone: string | null;
  federalPhone: string | null;
  operator: "MEGAFON" | "T2";
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
  entityType: "PHONE" | "RESTAURANT";
  entityId: string;
  action: "ADD" | "UPDATE" | "DELETE";
  actor: string;
  beforeState: string | null;
  afterState: string | null;
  createdAt: Date;
};

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

const parsePhoneInput = (input: Record<string, unknown>): DirectoryPhoneInput => {
  const operator = parseOperator(input.operator);
  const cityPhone = normalizeRussianPhone(input.cityPhone);
  const federalPhone = normalizeRussianPhone(input.federalPhone);
  const legacyPhone = normalizeRussianPhone(input.phone);
  const phone = operator === "MEGAFON"
    ? cityPhone ?? federalPhone ?? legacyPhone
    : legacyPhone ?? cityPhone ?? federalPhone;
  if (!phone) throw new Error("Укажите хотя бы один корректный российский номер телефона.");
  const legalEntity = typeof input.legalEntity === "string" ? input.legalEntity.trim() : "";
  if (!legalEntity) throw new Error("Юридическое лицо обязательно.");
  return {
    phone,
    cityPhone,
    federalPhone,
    operator,
    legalEntity,
    inn: normalizeNullable(input.inn),
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

const parseRestaurantInput = (input: Record<string, unknown>): DirectoryRestaurantRecord => {
  const id = String(input.id ?? "").trim();
  const number = Number(input.number);
  const ou = String(input.ou ?? "").trim();
  const legalEntity = String(input.legalEntity ?? "").trim();
  const address = String(input.address ?? "").trim();
  if (!id || !Number.isInteger(number) || number <= 0 || !ou || !legalEntity || !address) {
    throw new Error("Для ресторана обязательны ID, номер, ОУ, юридическое лицо и адрес.");
  }
  return {
    id,
    number,
    ou,
    legalEntity,
    address,
    actualDirector: String(input.actualDirector ?? "").trim(),
    actualPhoneMode: parsePhoneMode(input.actualPhoneMode),
    actualPersonalPhone: String(input.actualPersonalPhone ?? "").trim(),
    generalDirector: String(input.generalDirector ?? "").trim(),
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
        [
          restaurant.id,
          restaurant.number,
          restaurant.ou,
          restaurant.legalEntity,
          restaurant.address,
          restaurant.actualDirector,
          restaurant.generalDirector,
          restaurant.email,
        ],
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
export const ensureDirectoryWebSchema = async (): Promise<void> => {
  await pool.query(`
    CREATE TABLE IF NOT EXISTS corporate_phone_directory (
      id text PRIMARY KEY,
      phone text NOT NULL,
      operator text NOT NULL,
      legal_entity text NOT NULL,
      inn text,
      account_number text,
      restaurant_name text,
      line_type text,
      subscriber_name text,
      active boolean NOT NULL DEFAULT true,
      created_at timestamptz NOT NULL DEFAULT now(),
      updated_at timestamptz NOT NULL DEFAULT now()
    )
  `);
  await pool.query(`ALTER TABLE corporate_phone_directory ADD COLUMN IF NOT EXISTS city_phone text`);
  await pool.query(`ALTER TABLE corporate_phone_directory ADD COLUMN IF NOT EXISTS federal_phone text`);
  await pool.query(`
    UPDATE corporate_phone_directory
    SET city_phone = phone
    WHERE operator = 'MEGAFON' AND city_phone IS NULL AND phone LIKE '7812%'
  `);
  await pool.query(`
    UPDATE corporate_phone_directory
    SET federal_phone = phone
    WHERE operator = 'MEGAFON' AND federal_phone IS NULL AND phone NOT LIKE '7812%'
  `);
  await pool.query(`
    CREATE TABLE IF NOT EXISTS corporate_directory_restaurants (
      id text PRIMARY KEY,
      number integer NOT NULL,
      ou text NOT NULL,
      legal_entity text NOT NULL,
      address text NOT NULL,
      actual_director text NOT NULL DEFAULT '',
      actual_phone_mode text NOT NULL DEFAULT 'AUTO',
      actual_personal_phone text NOT NULL DEFAULT '',
      general_director text NOT NULL DEFAULT '',
      general_phone_mode text NOT NULL DEFAULT 'AUTO',
      general_personal_phone text NOT NULL DEFAULT '',
      email text NOT NULL DEFAULT '',
      active boolean NOT NULL DEFAULT true,
      created_at timestamptz NOT NULL DEFAULT now(),
      updated_at timestamptz NOT NULL DEFAULT now()
    )
  `);
  await pool.query(`
    CREATE TABLE IF NOT EXISTS corporate_directory_web_audit (
      id text PRIMARY KEY,
      entity_type text NOT NULL,
      entity_id text NOT NULL,
      action text NOT NULL,
      actor text NOT NULL,
      before_state text,
      after_state text,
      created_at timestamptz NOT NULL DEFAULT now()
    )
  `);
  await seedDirectoryRestaurants();
};

const phoneSelect = `
  SELECT id, phone, city_phone AS "cityPhone", federal_phone AS "federalPhone",
         operator, legal_entity AS "legalEntity", inn,
         account_number AS "accountNumber", restaurant_name AS "restaurantName",
         line_type AS "lineType", subscriber_name AS "subscriberName"
  FROM corporate_phone_directory
`;

export const listDirectoryPhones = async (): Promise<DirectoryPhoneRecord[]> => {
  const result = await pool.query<DirectoryPhoneRecord>(
    `${phoneSelect} WHERE active = true ORDER BY operator, phone, legal_entity`,
  );
  return result.rows;
};

const getPhone = async (id: string): Promise<DirectoryPhoneRecord | null> => {
  const result = await pool.query<DirectoryPhoneRecord>(`${phoneSelect} WHERE id = $1 AND active = true LIMIT 1`, [id]);
  return result.rows[0] ?? null;
};

const writeAudit = async (
  client: { query: (text: string, values?: unknown[]) => Promise<unknown> },
  input: Omit<DirectoryAuditRecord, "id" | "createdAt">,
): Promise<void> => {
  await client.query(
    `INSERT INTO corporate_directory_web_audit
      (id, entity_type, entity_id, action, actor, before_state, after_state)
     VALUES ($1, $2, $3, $4, $5, $6, $7)`,
    [randomUUID(), input.entityType, input.entityId, input.action, input.actor, input.beforeState, input.afterState],
  );
};

export const addDirectoryPhone = async (actor: string, raw: Record<string, unknown>): Promise<DirectoryPhoneRecord> => {
  const input = parsePhoneInput(raw);
  const exact = await pool.query(
    `SELECT id FROM corporate_phone_directory
     WHERE active = true AND phone = $1 AND operator = $2 AND lower(legal_entity) = lower($3)
       AND coalesce(account_number, '') = coalesce($4, '')
       AND coalesce(subscriber_name, '') = coalesce($5, '')
     LIMIT 1`,
    [input.phone, input.operator, input.legalEntity, input.accountNumber, input.subscriberName],
  );
  if (exact.rowCount) throw new Error("Такая карточка номера уже существует.");

  const record: DirectoryPhoneRecord = { id: randomUUID(), ...input };
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    await client.query(
      `INSERT INTO corporate_phone_directory
       (id, phone, city_phone, federal_phone, operator, legal_entity, inn, account_number, restaurant_name, line_type, subscriber_name, active)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,true)`,
      [record.id, record.phone, record.cityPhone, record.federalPhone, record.operator, record.legalEntity, record.inn, record.accountNumber, record.restaurantName, record.lineType, record.subscriberName],
    );
    await writeAudit(client, {
      entityType: "PHONE", entityId: record.id, action: "ADD", actor,
      beforeState: null, afterState: JSON.stringify(record),
    });
    await client.query("COMMIT");
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
  return record;
};

export const updateDirectoryPhone = async (actor: string, id: string, raw: Record<string, unknown>): Promise<DirectoryPhoneRecord> => {
  const before = await getPhone(id);
  if (!before) throw new Error("Запись номера не найдена.");
  const input = parsePhoneInput(raw);
  const after: DirectoryPhoneRecord = { id, ...input };
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    await client.query(
      `UPDATE corporate_phone_directory SET
       phone=$2, city_phone=$3, federal_phone=$4, operator=$5, legal_entity=$6, inn=$7, account_number=$8,
       restaurant_name=$9, line_type=$10, subscriber_name=$11, updated_at=now()
       WHERE id=$1`,
      [id, after.phone, after.cityPhone, after.federalPhone, after.operator, after.legalEntity, after.inn, after.accountNumber, after.restaurantName, after.lineType, after.subscriberName],
    );
    await writeAudit(client, {
      entityType: "PHONE", entityId: id, action: "UPDATE", actor,
      beforeState: JSON.stringify(before), afterState: JSON.stringify(after),
    });
    await client.query("COMMIT");
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
  return after;
};

export const deleteDirectoryPhone = async (actor: string, id: string): Promise<DirectoryPhoneRecord> => {
  const before = await getPhone(id);
  if (!before) throw new Error("Запись номера не найдена.");
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    await client.query(`DELETE FROM corporate_phone_directory WHERE id=$1`, [id]);
    await writeAudit(client, {
      entityType: "PHONE", entityId: id, action: "DELETE", actor,
      beforeState: JSON.stringify(before), afterState: null,
    });
    await client.query("COMMIT");
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
  return before;
};

export const listDirectoryRestaurants = async (): Promise<DirectoryRestaurantRecord[]> => {
  const result = await pool.query<DirectoryRestaurantRecord>(`
    SELECT id, number, ou, legal_entity AS "legalEntity", address,
           actual_director AS "actualDirector", actual_phone_mode AS "actualPhoneMode",
           actual_personal_phone AS "actualPersonalPhone", general_director AS "generalDirector",
           general_phone_mode AS "generalPhoneMode", general_personal_phone AS "generalPersonalPhone",
           email
    FROM corporate_directory_restaurants
    WHERE active = true
    ORDER BY number
  `);
  return result.rows;
};

const getRestaurant = async (id: string): Promise<DirectoryRestaurantRecord | null> => {
  const result = await pool.query<DirectoryRestaurantRecord>(`
    SELECT id, number, ou, legal_entity AS "legalEntity", address,
           actual_director AS "actualDirector", actual_phone_mode AS "actualPhoneMode",
           actual_personal_phone AS "actualPersonalPhone", general_director AS "generalDirector",
           general_phone_mode AS "generalPhoneMode", general_personal_phone AS "generalPersonalPhone", email
    FROM corporate_directory_restaurants WHERE id=$1 AND active=true LIMIT 1`, [id]);
  return result.rows[0] ?? null;
};

export const upsertDirectoryRestaurant = async (actor: string, raw: Record<string, unknown>): Promise<DirectoryRestaurantRecord> => {
  const after = parseRestaurantInput(raw);
  const before = await getRestaurant(after.id);
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    await client.query(
      `INSERT INTO corporate_directory_restaurants
       (id, number, ou, legal_entity, address, actual_director, actual_phone_mode, actual_personal_phone,
        general_director, general_phone_mode, general_personal_phone, email, active)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,true)
       ON CONFLICT (id) DO UPDATE SET
         number=excluded.number, ou=excluded.ou, legal_entity=excluded.legal_entity, address=excluded.address,
         actual_director=excluded.actual_director, actual_phone_mode=excluded.actual_phone_mode,
         actual_personal_phone=excluded.actual_personal_phone, general_director=excluded.general_director,
         general_phone_mode=excluded.general_phone_mode, general_personal_phone=excluded.general_personal_phone,
         email=excluded.email, active=true, updated_at=now()`,
      [after.id, after.number, after.ou, after.legalEntity, after.address, after.actualDirector, after.actualPhoneMode,
       after.actualPersonalPhone, after.generalDirector, after.generalPhoneMode, after.generalPersonalPhone, after.email],
    );
    await writeAudit(client, {
      entityType: "RESTAURANT", entityId: after.id, action: before ? "UPDATE" : "ADD", actor,
      beforeState: before ? JSON.stringify(before) : null, afterState: JSON.stringify(after),
    });
    await client.query("COMMIT");
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
  return after;
};

export const listDirectoryAudit = async (limit = 100): Promise<DirectoryAuditRecord[]> => {
  const safeLimit = Math.max(1, Math.min(500, Math.trunc(limit) || 100));
  const result = await pool.query<DirectoryAuditRecord>(`
    SELECT id, entity_type AS "entityType", entity_id AS "entityId", action, actor,
           before_state AS "beforeState", after_state AS "afterState", created_at AS "createdAt"
    FROM corporate_directory_web_audit
    ORDER BY created_at DESC
    LIMIT $1`, [safeLimit]);
  return result.rows;
};
