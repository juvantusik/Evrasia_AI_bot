import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";

export type LegalEntityVerificationStatus = "VERIFIED" | "NEEDS_REVIEW" | "UNVERIFIED";

export type LegalEntityMasterRecord = {
  id: string;
  name: string;
  fullName: string | null;
  inn: string | null;
  kpp: string | null;
  ogrn: string | null;
  legalAddress: string | null;
  actualAddress: string | null;
  postalAddress: string | null;
  generalDirector: string | null;
  source: string | null;
  verificationStatus: LegalEntityVerificationStatus;
  notes: string | null;
  active: boolean;
};

export type LegalEntityOperatorAccount = {
  id: string;
  legalEntityId: string;
  operator: string;
  accountNumber: string;
  contractNumber: string | null;
  isPrimary: boolean;
  verificationStatus: LegalEntityVerificationStatus;
};

export type LegalEntityBankAccount = {
  id: string;
  legalEntityId: string;
  bankName: string | null;
  bik: string | null;
  accountNumber: string;
  correspondentAccount: string | null;
  isPrimary: boolean;
  verificationStatus: LegalEntityVerificationStatus;
};

export type LegalEntityMasterInput = Omit<LegalEntityMasterRecord, "id" | "active">;

const normalizeNullable = (value: unknown): string | null => {
  if (typeof value !== "string") return null;
  const trimmed = value.trim();
  return trimmed || null;
};

const parseVerificationStatus = (value: unknown): LegalEntityVerificationStatus => {
  if (value === "VERIFIED" || value === "NEEDS_REVIEW" || value === "UNVERIFIED") return value;
  return "UNVERIFIED";
};

const parseLegalEntityInput = (input: Record<string, unknown>): LegalEntityMasterInput => {
  const name = typeof input.name === "string" ? input.name.trim() : "";
  if (!name) throw new Error("Название юридического лица обязательно.");

  return {
    name,
    fullName: normalizeNullable(input.fullName),
    inn: normalizeNullable(input.inn),
    kpp: normalizeNullable(input.kpp),
    ogrn: normalizeNullable(input.ogrn),
    legalAddress: normalizeNullable(input.legalAddress),
    actualAddress: normalizeNullable(input.actualAddress),
    postalAddress: normalizeNullable(input.postalAddress),
    generalDirector: normalizeNullable(input.generalDirector),
    source: normalizeNullable(input.source),
    verificationStatus: parseVerificationStatus(input.verificationStatus),
    notes: normalizeNullable(input.notes),
  };
};

const legalEntitySelect = `
  SELECT id,
         name,
         full_name AS "fullName",
         inn,
         kpp,
         ogrn,
         legal_address AS "legalAddress",
         actual_address AS "actualAddress",
         postal_address AS "postalAddress",
         general_director AS "generalDirector",
         source,
         verification_status AS "verificationStatus",
         notes,
         active
  FROM corporate_legal_entities
`;

const getLegalEntity = async (id: string, includeInactive = false): Promise<LegalEntityMasterRecord | null> => {
  const result = await pool.query<LegalEntityMasterRecord>(
    `${legalEntitySelect} WHERE id = $1 ${includeInactive ? "" : "AND active = true"} LIMIT 1`,
    [id],
  );
  return result.rows[0] ?? null;
};

const writeAudit = async (
  client: { query: (text: string, values?: unknown[]) => Promise<unknown> },
  actor: string,
  entityId: string,
  action: "ADD" | "UPDATE" | "DELETE",
  beforeState: LegalEntityMasterRecord | null,
  afterState: LegalEntityMasterRecord | null,
): Promise<void> => {
  await client.query(
    `INSERT INTO corporate_directory_web_audit
      (id, entity_type, entity_id, action, actor, before_state, after_state)
     VALUES ($1, 'LEGAL_ENTITY', $2, $3, $4, $5, $6)`,
    [
      randomUUID(),
      entityId,
      action,
      actor,
      beforeState ? JSON.stringify(beforeState) : null,
      afterState ? JSON.stringify(afterState) : null,
    ],
  );
};

const assertNoDuplicate = async (
  input: LegalEntityMasterInput,
  exceptId: string | null,
): Promise<void> => {
  const values: unknown[] = [input.name, input.inn];
  let exclusion = "";
  if (exceptId) {
    values.push(exceptId);
    exclusion = "AND id <> $3";
  }
  const duplicate = await pool.query(
    `SELECT id
     FROM corporate_legal_entities
     WHERE active = true
       AND lower(trim(name)) = lower(trim($1))
       AND inn IS NOT DISTINCT FROM $2
       ${exclusion}
     LIMIT 1`,
    values,
  );
  if (duplicate.rowCount) {
    throw new Error("Юридическое лицо с таким названием и ИНН уже есть в справочнике.");
  }
};

export const listLegalEntityMaster = async (): Promise<LegalEntityMasterRecord[]> => {
  const result = await pool.query<LegalEntityMasterRecord>(
    `${legalEntitySelect} WHERE active = true ORDER BY name, inn NULLS LAST`,
  );
  return result.rows;
};

export const listLegalEntityOperatorAccounts = async (
  legalEntityId?: string,
): Promise<LegalEntityOperatorAccount[]> => {
  const values: unknown[] = [];
  const where = legalEntityId ? "AND legal_entity_id = $1" : "";
  if (legalEntityId) values.push(legalEntityId);
  const result = await pool.query<LegalEntityOperatorAccount>(
    `SELECT id,
            legal_entity_id AS "legalEntityId",
            operator,
            account_number AS "accountNumber",
            contract_number AS "contractNumber",
            is_primary AS "isPrimary",
            verification_status AS "verificationStatus"
     FROM corporate_legal_entity_operator_accounts
     WHERE active = true ${where}
     ORDER BY operator, is_primary DESC, account_number`,
    values,
  );
  return result.rows;
};

export const listLegalEntityBankAccounts = async (
  legalEntityId: string,
): Promise<LegalEntityBankAccount[]> => {
  const result = await pool.query<LegalEntityBankAccount>(
    `SELECT id,
            legal_entity_id AS "legalEntityId",
            bank_name AS "bankName",
            bik,
            account_number AS "accountNumber",
            correspondent_account AS "correspondentAccount",
            is_primary AS "isPrimary",
            verification_status AS "verificationStatus"
     FROM corporate_legal_entity_bank_accounts
     WHERE active = true AND legal_entity_id = $1
     ORDER BY is_primary DESC, bank_name NULLS LAST, account_number`,
    [legalEntityId],
  );
  return result.rows;
};

export const addLegalEntityMaster = async (
  actor: string,
  rawInput: Record<string, unknown>,
): Promise<LegalEntityMasterRecord> => {
  const input = parseLegalEntityInput(rawInput);
  await assertNoDuplicate(input, null);
  const id = randomUUID();
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    await client.query(
      `INSERT INTO corporate_legal_entities
        (id, name, full_name, inn, kpp, ogrn, legal_address, actual_address, postal_address,
         general_director, source, verification_status, notes, active)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,true)`,
      [
        id,
        input.name,
        input.fullName,
        input.inn,
        input.kpp,
        input.ogrn,
        input.legalAddress,
        input.actualAddress,
        input.postalAddress,
        input.generalDirector,
        input.source,
        input.verificationStatus,
        input.notes,
      ],
    );
    const after = await client.query<LegalEntityMasterRecord>(
      `${legalEntitySelect} WHERE id = $1 LIMIT 1`,
      [id],
    );
    const record = after.rows[0]!;
    await writeAudit(client, actor, id, "ADD", null, record);
    await client.query("COMMIT");
    return record;
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
};

export const updateLegalEntityMaster = async (
  actor: string,
  id: string,
  rawInput: Record<string, unknown>,
): Promise<LegalEntityMasterRecord> => {
  const before = await getLegalEntity(id);
  if (!before) throw new Error("Юридическое лицо не найдено или уже архивировано.");
  const input = parseLegalEntityInput(rawInput);
  await assertNoDuplicate(input, id);

  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    await client.query(
      `UPDATE corporate_legal_entities
       SET name = $2,
           full_name = $3,
           inn = $4,
           kpp = $5,
           ogrn = $6,
           legal_address = $7,
           actual_address = $8,
           postal_address = $9,
           general_director = $10,
           source = $11,
           verification_status = $12,
           notes = $13,
           updated_at = now()
       WHERE id = $1 AND active = true`,
      [
        id,
        input.name,
        input.fullName,
        input.inn,
        input.kpp,
        input.ogrn,
        input.legalAddress,
        input.actualAddress,
        input.postalAddress,
        input.generalDirector,
        input.source,
        input.verificationStatus,
        input.notes,
      ],
    );
    const afterResult = await client.query<LegalEntityMasterRecord>(
      `${legalEntitySelect} WHERE id = $1 LIMIT 1`,
      [id],
    );
    const after = afterResult.rows[0]!;
    await writeAudit(client, actor, id, "UPDATE", before, after);
    await client.query("COMMIT");
    return after;
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
};

export const archiveLegalEntityMaster = async (
  actor: string,
  id: string,
): Promise<LegalEntityMasterRecord> => {
  const before = await getLegalEntity(id);
  if (!before) throw new Error("Юридическое лицо не найдено или уже архивировано.");

  const references = await pool.query<{ phoneCount: string; restaurantCount: string }>(
    `SELECT
       (SELECT count(*)::text FROM corporate_phone_directory WHERE active = true AND legal_entity_id = $1) AS "phoneCount",
       (SELECT count(*)::text FROM corporate_directory_restaurants WHERE active = true AND legal_entity_id = $1) AS "restaurantCount"`,
    [id],
  );
  const phoneCount = Number(references.rows[0]?.phoneCount ?? 0);
  const restaurantCount = Number(references.rows[0]?.restaurantCount ?? 0);
  if (phoneCount > 0 || restaurantCount > 0) {
    throw new Error(
      `Организация используется в активном справочнике: номеров ${phoneCount}, ресторанов ${restaurantCount}. Сначала переназначьте или закройте эти связи.`,
    );
  }

  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    await client.query(
      `UPDATE corporate_legal_entities SET active = false, updated_at = now() WHERE id = $1`,
      [id],
    );
    const afterResult = await client.query<LegalEntityMasterRecord>(
      `${legalEntitySelect} WHERE id = $1 LIMIT 1`,
      [id],
    );
    const after = afterResult.rows[0]!;
    await writeAudit(client, actor, id, "DELETE", before, after);
    await client.query("COMMIT");
    return after;
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
};

export const synchronizeLegalEntityReferences = async (): Promise<void> => {
  await pool.query(`
    UPDATE corporate_phone_directory p
    SET legal_entity_id = le.id
    FROM corporate_legal_entities le
    WHERE p.legal_entity_id IS NULL
      AND le.active = true
      AND lower(trim(p.legal_entity)) = lower(trim(le.name))
      AND p.inn IS NOT DISTINCT FROM le.inn
  `);

  await pool.query(`
    WITH unambiguous AS (
      SELECT lower(trim(name)) AS normalized_name, min(id) AS id
      FROM corporate_legal_entities
      WHERE active = true
      GROUP BY lower(trim(name))
      HAVING count(*) = 1
    )
    UPDATE corporate_directory_restaurants r
    SET legal_entity_id = u.id
    FROM unambiguous u
    WHERE r.legal_entity_id IS NULL
      AND lower(trim(r.legal_entity)) = u.normalized_name
  `);

  await pool.query(`
    INSERT INTO corporate_legal_entity_operator_accounts
      (id, legal_entity_id, operator, account_number, is_primary, source, verification_status)
    SELECT
      'op-' || md5(legal_entity_id || '|' || operator || '|' || account_number),
      legal_entity_id,
      operator,
      account_number,
      false,
      'corporate_phone_directory',
      'UNVERIFIED'
    FROM corporate_phone_directory
    WHERE active = true
      AND legal_entity_id IS NOT NULL
      AND account_number IS NOT NULL
      AND trim(account_number) <> ''
    GROUP BY legal_entity_id, operator, account_number
    ON CONFLICT (id) DO NOTHING
  `);
};