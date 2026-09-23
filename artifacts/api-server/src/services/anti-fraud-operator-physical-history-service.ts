import { pool } from "@workspace/db";
import { BitrixAntiFraudCheckinGateway } from "./bitrix-antifraud-checkin-gateway";

import { buildAntiFraudOperatorPhysicalHistorySnapshot } from "./anti-fraud-operator-physical-history-rules";

// Добавлено 23.09.2026 ИТ Директор Евразии
// Targeted Check-in snapshot хранится отдельно от anti_fraud_visits.
// Поэтому ручная проверка не добавляет физические события в automatic risk telemetry.
export const refreshAntiFraudOperatorPhysicalHistoryOnce = async (input: {
  investigationId: string;
  bitrixUserId: number;
  lookbackDays?: number;
  gateway?: BitrixAntiFraudCheckinGateway;
}): Promise<{
  recordsWritten: number;
  from: Date;
  to: Date;
  unresolvedCardCount: number;
}> => {
  const investigationId = String(input.investigationId ?? "").trim();
  const bitrixUserId = Number(input.bitrixUserId);
  const lookbackDays = Number(input.lookbackDays ?? 60);

  if (!investigationId || investigationId.length > 100) {
    throw new Error("Некорректный investigation_id физической истории");
  }
  if (!Number.isInteger(bitrixUserId) || bitrixUserId <= 0) {
    throw new Error("USER_ID физической истории должен быть положительным integer");
  }
  if (!Number.isInteger(lookbackDays) || lookbackDays < 1 || lookbackDays > 60) {
    throw new Error("Окно физической истории должно быть от 1 до 60 дней");
  }

  const gateway = input.gateway ?? new BitrixAntiFraudCheckinGateway();
  const history = await gateway.fetchUserHistory(bitrixUserId, lookbackDays);
  const snapshot = buildAntiFraudOperatorPhysicalHistorySnapshot(history, bitrixUserId);
  const client = await pool.connect();

  try {
    await client.query("BEGIN");

    const authorization = await client.query(
      `SELECT 1
       FROM anti_fraud_operator_investigations
       WHERE investigation_id = $1
         AND bitrix_user_id = $2
         AND status IN ('processing','history_ready')
       FOR UPDATE`,
      [investigationId, bitrixUserId],
    );
    if (!authorization.rowCount) {
      throw new Error("Операторское расследование не авторизовало физическую историю");
    }

    await client.query(
      `DELETE FROM anti_fraud_operator_investigation_visits
       WHERE investigation_id = $1`,
      [investigationId],
    );

    if (snapshot.records.length) {
      await client.query(
        `INSERT INTO anti_fraud_operator_investigation_visits (
           investigation_id, physical_event_id, bitrix_user_id, occurred_at, restaurant
         )
         SELECT
           $1,
           rows.physical_event_id,
           $2,
           rows.occurred_at,
           rows.restaurant
         FROM unnest(
           $3::text[],
           $4::timestamptz[],
           $5::text[]
         ) AS rows(physical_event_id, occurred_at, restaurant)
         ON CONFLICT (investigation_id, physical_event_id) DO UPDATE SET
           bitrix_user_id = EXCLUDED.bitrix_user_id,
           occurred_at = EXCLUDED.occurred_at,
           restaurant = EXCLUDED.restaurant`,
        [
          investigationId,
          bitrixUserId,
          snapshot.records.map((record) => record.physicalEventId),
          snapshot.records.map((record) => record.occurredAt.toISOString()),
          snapshot.records.map((record) => record.restaurant),
        ],
      );
    }

    await client.query(
      `UPDATE anti_fraud_operator_investigations
       SET physical_history_from = $2,
           physical_history_until = $3,
           updated_at = now()
       WHERE investigation_id = $1`,
      [investigationId, snapshot.from, snapshot.to],
    );

    await client.query("COMMIT");
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }

  return {
    recordsWritten: snapshot.records.length,
    from: snapshot.from,
    to: snapshot.to,
    unresolvedCardCount: snapshot.unresolvedCardCount,
  };
};
