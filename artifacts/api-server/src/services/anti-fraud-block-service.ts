import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import {
  BitrixAntiFraudBlockGateway,
  type BitrixAntiFraudBlockRecord,
} from "./bitrix-antifraud-block-gateway";

const SOURCE = "anti_fraud_web";
const MAX_CASE_ID_LENGTH = 160;

type Options = {
  gateway?: BitrixAntiFraudBlockGateway;
};

export type AntiFraudBlockActionRecord = {
  bitrixUserId: number;
  success: boolean;
  result: BitrixAntiFraudBlockRecord["result"];
  alreadyBlocked: boolean;
  changed: boolean;
  bitrixActive: boolean | null;
  bitrixBlocked: boolean | null;
  bitrixBlockReason: string | null;
};

export type AntiFraudBlockActionResult = {
  operationId: string;
  ok: boolean;
  requested: number;
  resolved: number;
  unresolved: number[];
  records: AntiFraudBlockActionRecord[];
};

const normalizeCaseId = (value: unknown): string | null => {
  if (value === undefined || value === null || value === "") return null;
  if (typeof value !== "string") throw new Error("Некорректный caseId");
  const normalized = value.trim();
  if (!normalized || normalized.length > MAX_CASE_ID_LENGTH || /[\u0000-\u001F\u007F]/.test(normalized)) {
    throw new Error("Некорректный caseId");
  }
  return normalized;
};

const persistAccountState = async (
  bitrixUserId: number,
  active: boolean,
  blocked: boolean,
  reason: string | null,
): Promise<void> => {
  await pool.query(
    `UPDATE anti_fraud_accounts
     SET bitrix_active = $2,
         bitrix_blocked = $3,
         bitrix_block_reason = $4,
         last_synced_at = now()
     WHERE bitrix_user_id = $1`,
    [bitrixUserId, active, blocked, reason],
  );
};

const writeAudit = async (
  operationId: string,
  caseId: string | null,
  record: BitrixAntiFraudBlockRecord,
): Promise<void> => {
  await pool.query(
    `INSERT INTO anti_fraud_block_audit (
       operation_id,
       case_id,
       bitrix_user_id,
       source,
       before_active,
       before_blocked,
       after_active,
       after_blocked,
       result,
       success
     ) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)`,
    [
      operationId,
      caseId,
      record.bitrixUserId,
      SOURCE,
      record.before.active,
      record.before.blocked,
      record.after?.active ?? null,
      record.after?.blocked ?? null,
      record.result,
      record.success,
    ],
  );
};

export const blockAntiFraudAccounts = async (
  userIds: number[],
  caseIdValue?: unknown,
  options: Options = {},
): Promise<AntiFraudBlockActionResult> => {
  const caseId = normalizeCaseId(caseIdValue);
  const operationId = randomUUID();
  const gateway = options.gateway ?? new BitrixAntiFraudBlockGateway();
  const result = await gateway.blockAccounts(userIds);

  for (const record of result.records) {
    await writeAudit(operationId, caseId, record);

    const effective = record.after ?? record.before;
    if (record.success) {
      await persistAccountState(
        record.bitrixUserId,
        effective.active,
        effective.blocked,
        effective.blockReason,
      );
    }
  }

  const records: AntiFraudBlockActionRecord[] = result.records.map((record) => {
    const effective = record.after ?? record.before;
    return {
      bitrixUserId: record.bitrixUserId,
      success: record.success,
      result: record.result,
      alreadyBlocked: record.alreadyBlocked,
      changed: record.changed,
      bitrixActive: effective?.active ?? null,
      bitrixBlocked: effective?.blocked ?? null,
      bitrixBlockReason: effective?.blockReason ?? null,
    };
  });

  return {
    operationId,
    ok: result.unresolved.length === 0 && records.every((record) => record.success),
    requested: result.requested,
    resolved: result.resolved,
    unresolved: result.unresolved,
    records,
  };
};
