import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import {
  BitrixAntiFraudDeviceGateway,
  type TrustedDeviceEventRecord,
  type TrustedDeviceLinkRecord,
} from "./bitrix-antifraud-device-gateway";
import { assertTrustedDeviceLinkSnapshotSafe } from "./trusted-device-snapshot-guard";

const SOURCE = "trusted_device_export";
const LOCK_NAME = "anti_fraud_trusted_device_export";
const MAX_PAGES = 100_000;

type CollectorOptions = {
  gateway?: BitrixAntiFraudDeviceGateway;
};

export type AntiFraudTrustedDeviceCollectorResult = {
  runId: string;
  fetchedLinks: number;
  syncedLinks: number;
  removedLinks: number;
  fetchedEvents: number;
  writtenEvents: number;
  observedAccounts: number;
  eventCursor: string | null;
};

type SyncCursor = {
  events: string | null;
};

const safeErrorMessage = (error: unknown): string => {
  const message = error instanceof Error ? error.message : "Неизвестная ошибка Trusted Device collector";
  return message.slice(0, 2000);
};

const sourceIdGreater = (next: string, previous: string | null): boolean => {
  if (previous === null) return true;
  try {
    return BigInt(next) > BigInt(previous);
  } catch {
    return false;
  }
};

const parseCursor = (raw: string | null): SyncCursor => {
  if (!raw) return { events: null };
  try {
    const parsed = JSON.parse(raw) as Record<string, unknown>;
    const value = parsed.events;
    if (value === null || value === undefined || value === "") {
      return { events: null };
    }
    if (typeof value !== "string" || !/^[1-9][0-9]*$/.test(value)) {
      throw new Error("invalid cursor");
    }
    return { events: value };
  } catch {
    throw new Error("Trusted Device sync cursor повреждён; автоматический сброс запрещён");
  }
};

const sameInstant = (left: Date | null, right: Date | null): boolean => {
  if (left === null || right === null) return left === right;
  return left.getTime() === right.getTime();
};

const assertEventMatches = (
  existing: {
    bitrix_user_id: number;
    device_hash: string;
    event_type: string;
    auth_method: string | null;
    client_type: string | null;
    occurred_at: Date;
  },
  incoming: TrustedDeviceEventRecord,
): void => {
  const matches =
    Number(existing.bitrix_user_id) === incoming.bitrixUserId &&
    existing.device_hash === incoming.deviceHash &&
    existing.event_type === incoming.eventType &&
    existing.auth_method === incoming.authMethod &&
    existing.client_type === incoming.clientType &&
    sameInstant(existing.occurred_at, incoming.occurredAt);

  if (!matches) {
    throw new Error("Trusted Device source_event_id повторно пришёл с другими core-данными");
  }
};

// Добавлено 03.09.2026 ИТ Директор Евразии
// Текущие user-device связи синхронизируются полным snapshot, потому что auth-event поток может быть неполным.
// Auth events загружаются инкрементально по source ID; IP и PII в этот поток не входят.
export const syncTrustedDeviceOnce = async (
  options: CollectorOptions = {},
): Promise<AntiFraudTrustedDeviceCollectorResult> => {
  const gateway = options.gateway ?? new BitrixAntiFraudDeviceGateway();
  const runId = randomUUID();
  const client = await pool.connect();
  let lockAcquired = false;
  let transactionOpen = false;
  let runCreated = false;

  try {
    const lockResult = await client.query<{ locked: boolean }>(
      "SELECT pg_try_advisory_lock(hashtext($1)) AS locked",
      [LOCK_NAME],
    );
    lockAcquired = lockResult.rows[0]?.locked === true;
    if (!lockAcquired) {
      throw new Error("Синхронизация Trusted Device уже выполняется другим процессом");
    }

    await client.query(
      `INSERT INTO anti_fraud_sync_runs (run_id, source, status, started_at)
       VALUES ($1, $2, 'running', now())`,
      [runId, SOURCE],
    );
    runCreated = true;

    const stateResult = await client.query<{ cursor: string | null }>(
      `SELECT cursor
       FROM anti_fraud_sync_state
       WHERE source = $1`,
      [SOURCE],
    );
    const initialCursor = parseCursor(stateResult.rows[0]?.cursor ?? null);

    await client.query(
      `INSERT INTO anti_fraud_sync_state (source, cursor, last_started_at, last_error, updated_at)
       VALUES ($1, $2, now(), NULL, now())
       ON CONFLICT (source) DO UPDATE SET
         last_started_at = EXCLUDED.last_started_at,
         last_error = NULL,
         updated_at = now()`,
      [SOURCE, JSON.stringify(initialCursor)],
    );

    const links = new Map<string, TrustedDeviceLinkRecord>();
    let linkCursor: string | null = null;

    for (let pageNo = 0; pageNo < MAX_PAGES; pageNo += 1) {
      const page = await gateway.fetchLinksPage(linkCursor);
      for (const record of page.records) {
        if (links.has(record.sourceLinkId)) {
          throw new Error("Trusted Device links snapshot содержит повторный source_link_id");
        }
        links.set(record.sourceLinkId, record);
      }

      if (!page.hasMore) break;
      if (!page.nextCursor || !sourceIdGreater(page.nextCursor, linkCursor)) {
        throw new Error("Trusted Device links pagination не продвинула cursor");
      }
      linkCursor = page.nextCursor;

      if (pageNo === MAX_PAGES - 1) {
        throw new Error("Trusted Device links pagination превысила безопасный предел страниц");
      }
    }

    // Добавлено 03.09.2026 ИТ Директор Евразии
    // До destructive reconcile сравниваем полный полученный snapshot с локальным объёмом.
    // Пустой snapshot или внезапное сокращение более чем вдвое считаем ошибкой источника/пагинации.
    const existingLinksResult = await client.query<{ link_count: string }>(
      `SELECT count(*)::bigint AS link_count
       FROM anti_fraud_device_links`,
    );
    const existingLinks = Number(existingLinksResult.rows[0]?.link_count ?? 0);
    if (!Number.isSafeInteger(existingLinks) || existingLinks < 0) {
      throw new Error("Не удалось безопасно определить количество текущих Trusted Device links");
    }
    assertTrustedDeviceLinkSnapshotSafe(existingLinks, links.size);

    const events = new Map<string, TrustedDeviceEventRecord>();
    let eventCursor = initialCursor.events;

    for (let pageNo = 0; pageNo < MAX_PAGES; pageNo += 1) {
      const page = await gateway.fetchEventsPage(eventCursor);
      for (const record of page.records) {
        if (events.has(record.sourceEventId)) {
          throw new Error("Trusted Device events page-set содержит повторный source_event_id");
        }
        events.set(record.sourceEventId, record);
      }

      if (page.nextCursor !== null) {
        if (!sourceIdGreater(page.nextCursor, eventCursor)) {
          throw new Error("Trusted Device events pagination не продвинула cursor");
        }
        eventCursor = page.nextCursor;
      }

      if (!page.hasMore) break;
      if (!page.nextCursor) {
        throw new Error("Trusted Device events pagination не вернула cursor");
      }

      if (pageNo === MAX_PAGES - 1) {
        throw new Error("Trusted Device events pagination превысила безопасный предел страниц");
      }
    }

    await client.query("BEGIN");
    transactionOpen = true;

    let syncedLinks = 0;
    const observedAccounts = new Set<number>();

    for (const record of links.values()) {
      observedAccounts.add(record.bitrixUserId);
      await client.query(
        `INSERT INTO anti_fraud_device_links (
           source_link_id,
           bitrix_user_id,
           device_hash,
           status,
           client_type,
           created_at,
           last_seen_at,
           updated_at,
           seen_run_id,
           synced_at
         )
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,now())
         ON CONFLICT (source_link_id) DO UPDATE SET
           bitrix_user_id = EXCLUDED.bitrix_user_id,
           device_hash = EXCLUDED.device_hash,
           status = EXCLUDED.status,
           client_type = EXCLUDED.client_type,
           created_at = EXCLUDED.created_at,
           last_seen_at = EXCLUDED.last_seen_at,
           updated_at = EXCLUDED.updated_at,
           seen_run_id = EXCLUDED.seen_run_id,
           synced_at = now()`,
        [
          record.sourceLinkId,
          record.bitrixUserId,
          record.deviceHash,
          record.status,
          record.clientType,
          record.createdAt,
          record.lastSeenAt,
          record.updatedAt,
          runId,
        ],
      );
      syncedLinks += 1;
    }

    const removed = await client.query(
      `DELETE FROM anti_fraud_device_links
       WHERE seen_run_id <> $1`,
      [runId],
    );
    const removedLinks = removed.rowCount ?? 0;

    let writtenEvents = 0;

    for (const record of events.values()) {
      observedAccounts.add(record.bitrixUserId);

      const existing = await client.query<{
        bitrix_user_id: number;
        device_hash: string;
        event_type: string;
        auth_method: string | null;
        client_type: string | null;
        occurred_at: Date;
      }>(
        `SELECT bitrix_user_id, device_hash, event_type, auth_method, client_type, occurred_at
         FROM anti_fraud_device_events
         WHERE source_event_id = $1`,
        [record.sourceEventId],
      );

      if (existing.rowCount) {
        assertEventMatches(existing.rows[0]!, record);
        continue;
      }

      const inserted = await client.query(
        `INSERT INTO anti_fraud_device_events (
           source_event_id,
           bitrix_user_id,
           device_hash,
           event_type,
           auth_method,
           client_type,
           occurred_at,
           synced_at
         )
         VALUES ($1,$2,$3,$4,$5,$6,$7,now())
         ON CONFLICT (source_event_id) DO NOTHING
         RETURNING source_event_id`,
        [
          record.sourceEventId,
          record.bitrixUserId,
          record.deviceHash,
          record.eventType,
          record.authMethod,
          record.clientType,
          record.occurredAt,
        ],
      );
      writtenEvents += inserted.rowCount ?? 0;
    }

    const nextCursor: SyncCursor = { events: eventCursor };
    const fetchedLinks = links.size;
    const fetchedEvents = events.size;
    const recordsFetched = fetchedLinks + fetchedEvents;
    const recordsWritten = syncedLinks + removedLinks + writtenEvents;

    await client.query(
      `UPDATE anti_fraud_sync_runs
       SET status = 'success', finished_at = now(), records_fetched = $2,
           records_written = $3, records_resolved = $4, error = NULL
       WHERE run_id = $1`,
      [runId, recordsFetched, recordsWritten, observedAccounts.size],
    );

    await client.query(
      `UPDATE anti_fraud_sync_state
       SET cursor = $2, last_succeeded_at = now(), last_error = NULL,
           records_fetched = $3, records_written = $4, records_resolved = $5,
           updated_at = now()
       WHERE source = $1`,
      [
        SOURCE,
        JSON.stringify(nextCursor),
        recordsFetched,
        recordsWritten,
        observedAccounts.size,
      ],
    );

    await client.query("COMMIT");
    transactionOpen = false;

    return {
      runId,
      fetchedLinks,
      syncedLinks,
      removedLinks,
      fetchedEvents,
      writtenEvents,
      observedAccounts: observedAccounts.size,
      eventCursor,
    };
  } catch (error) {
    if (transactionOpen) {
      await client.query("ROLLBACK");
      transactionOpen = false;
    }

    const message = safeErrorMessage(error);
    if (runCreated) {
      try {
        await client.query(
          `UPDATE anti_fraud_sync_runs
           SET status = 'failed', finished_at = now(), error = $2
           WHERE run_id = $1`,
          [runId, message],
        );
        await client.query(
          `UPDATE anti_fraud_sync_state
           SET last_error = $2, updated_at = now()
           WHERE source = $1`,
          [SOURCE, message],
        );
      } catch {
        // Ошибка фиксации статуса не должна скрывать исходную ошибку Trusted Device collector.
      }
    }
    throw error;
  } finally {
    if (lockAcquired) {
      try {
        await client.query("SELECT pg_advisory_unlock(hashtext($1))", [LOCK_NAME]);
      } catch {
        // Соединение освобождается ниже.
      }
    }
    client.release();
  }
};
