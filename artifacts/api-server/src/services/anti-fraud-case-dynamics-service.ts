import { pool } from "@workspace/db";
import { listAntiFraudCases, type AntiFraudCase } from "./anti-fraud-case-service";

// Добавлено 05.09.2026 ИТ Директор Евразии
// Динамика кейса отделена от Risk 0..100. Внутренний evidenceScore нужен только,
// чтобы увидеть усиление уже насыщенного critical-кейса; наружу он не является Risk.
export type AntiFraudCaseTrend = "new" | "strengthened" | "unchanged" | "weakened";

export type AntiFraudCaseMetricChange = {
  before: number | null;
  after: number;
  delta: number | null;
};

export type AntiFraudCaseDynamics = {
  caseId: string;
  trend: AntiFraudCaseTrend;
  changedAt: string;
  previousChangedAt: string | null;
  evidenceScore: number;
  metrics: {
    risk: AntiFraudCaseMetricChange;
    accounts: AntiFraudCaseMetricChange;
    devices: AntiFraudCaseMetricChange;
    reasons: AntiFraudCaseMetricChange;
  };
  // Для совместимости текущего web-клиента addedAccountIds теперь означает
  // USER_ID, впервые появившиеся в web Anti-Fraud менее 24 часов назад.
  addedAccountIds: number[];
  // Точная историческая дельта последнего изменившегося состояния кейса сохранена отдельно.
  forensicAddedAccountIds: number[];
  removedAccountIds: number[];
  addedReasonCodes: string[];
  removedReasonCodes: string[];
  recentAccountIds: number[];
};

type Snapshot = {
  caseId: string;
  fingerprint: string;
  evidenceScore: number;
  overallRisk: number;
  accountCount: number;
  deviceCount: number;
  reasonCount: number;
  accountIds: number[];
  reasonCodes: string[];
};

type StateRow = {
  case_id: string;
  current_evidence_score: number;
  current_overall_risk: number;
  current_account_count: number;
  current_device_count: number;
  current_reason_count: number;
  current_account_ids: string;
  current_reason_codes: string;
  current_changed_at: Date | string;
  previous_evidence_score: number | null;
  previous_overall_risk: number | null;
  previous_account_count: number | null;
  previous_device_count: number | null;
  previous_reason_count: number | null;
  previous_account_ids: string | null;
  previous_reason_codes: string | null;
  previous_changed_at: Date | string | null;
  observed_runs: number;
};

export type AntiFraudCaseLineageSnapshot = {
  caseId: string;
  accountIds: number[];
};

export type AntiFraudCaseLineageRename = {
  fromCaseId: string;
  toCaseId: string;
};

const uniqueSortedNumbers = (values: number[]): number[] =>
  [...new Set(values.filter((value) => Number.isInteger(value) && value > 0))].sort((a, b) => a - b);

const uniqueSortedStrings = (values: string[]): string[] =>
  [...new Set(values.map((value) => value.trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b));

const iso = (value: Date | string): string => {
  const date = value instanceof Date ? value : new Date(value);
  return Number.isNaN(date.getTime()) ? new Date(0).toISOString() : date.toISOString();
};

const nullableIso = (value: Date | string | null): string | null => (value ? iso(value) : null);

const parseNumberCsv = (value: string | null): number[] =>
  uniqueSortedNumbers(
    String(value ?? "")
      .split(",")
      .map((item) => Number(item.trim()))
      .filter((item) => Number.isInteger(item) && item > 0),
  );

const parseStringCsv = (value: string | null): string[] =>
  uniqueSortedStrings(String(value ?? "").split(","));

const differenceNumbers = (left: number[], right: number[]): number[] => {
  const rightSet = new Set(right);
  return left.filter((value) => !rightSet.has(value));
};

const differenceStrings = (left: string[], right: string[]): string[] => {
  const rightSet = new Set(right);
  return left.filter((value) => !rightSet.has(value));
};

const metric = (before: number | null, after: number): AntiFraudCaseMetricChange => ({
  before,
  after,
  delta: before === null ? null : after - before,
});

const isStrictSubset = (left: number[], right: number[]): boolean => {
  const leftIds = uniqueSortedNumbers(left);
  const rightIds = new Set(uniqueSortedNumbers(right));
  return leftIds.length > 0 && leftIds.length < rightIds.size && leftIds.every((id) => rightIds.has(id));
};

export const resolveAntiFraudCaseLineageRenames = (
  currentSnapshots: AntiFraudCaseLineageSnapshot[],
  persistedSnapshots: AntiFraudCaseLineageSnapshot[],
): AntiFraudCaseLineageRename[] => {
  const currentCaseIds = new Set(currentSnapshots.map((item) => item.caseId));
  const persistedById = new Map(persistedSnapshots.map((item) => [item.caseId, item]));
  const consumed = new Set<string>();
  const renames: AntiFraudCaseLineageRename[] = [];

  for (const current of currentSnapshots) {
    if (persistedById.has(current.caseId)) continue;

    const candidates = persistedSnapshots.filter(
      (persisted) =>
        !currentCaseIds.has(persisted.caseId) &&
        !consumed.has(persisted.caseId) &&
        isStrictSubset(persisted.accountIds, current.accountIds),
    );

    if (candidates.length !== 1) continue;

    consumed.add(candidates[0].caseId);
    renames.push({ fromCaseId: candidates[0].caseId, toCaseId: current.caseId });
  }

  return renames;
};

const snapshotForCase = (item: AntiFraudCase): Snapshot => {
  const accountIds = uniqueSortedNumbers(item.accounts.map((account) => account.bitrixUserId));
  const reasonCodes = uniqueSortedStrings(
    item.accounts.flatMap((account) => account.reasons.map((reason) => reason.code)),
  );

  const evidenceScore = item.accounts.reduce((caseTotal, account) => {
    const reasonTotal = account.reasons.reduce(
      (sum, reason) => sum + Math.max(0, Math.round(Number(reason.score) || 0)),
      0,
    );
    return caseTotal + Math.max(account.overallRisk, reasonTotal);
  }, 0);

  const deviceCount = item.devices.length;
  const reasonCount = reasonCodes.length;
  const fingerprint = JSON.stringify([
    evidenceScore,
    item.overallRisk,
    accountIds,
    deviceCount,
    reasonCodes,
  ]);

  return {
    caseId: item.caseId,
    fingerprint,
    evidenceScore,
    overallRisk: item.overallRisk,
    accountCount: item.accountCount,
    deviceCount,
    reasonCount,
    accountIds,
    reasonCodes,
  };
};

const reconcileSnapshotCaseLineage = async (snapshots: Snapshot[]): Promise<Set<string>> => {
  const renamedCaseIds = new Set<string>();
  if (!snapshots.length) return renamedCaseIds;

  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    await client.query("SELECT pg_advisory_xact_lock(hashtext('anti_fraud_case_lineage'))");

    const result = await client.query<{ case_id: string; current_account_ids: string }>(
      `SELECT case_id, current_account_ids
       FROM anti_fraud_case_state
       WHERE last_seen_at >= now() - interval '1 hour'
       FOR UPDATE`,
    );

    const renames = resolveAntiFraudCaseLineageRenames(
      snapshots.map((snapshot) => ({ caseId: snapshot.caseId, accountIds: snapshot.accountIds })),
      result.rows.map((row) => ({
        caseId: row.case_id,
        accountIds: parseNumberCsv(row.current_account_ids),
      })),
    );

    for (const rename of renames) {
      const updated = await client.query(
        `UPDATE anti_fraud_case_state old_state
         SET case_id = $2
         WHERE old_state.case_id = $1
           AND NOT EXISTS (
             SELECT 1 FROM anti_fraud_case_state new_state WHERE new_state.case_id = $2
           )`,
        [rename.fromCaseId, rename.toCaseId],
      );
      if ((updated.rowCount ?? 0) === 1) renamedCaseIds.add(rename.toCaseId);
    }

    await client.query("COMMIT");
    return renamedCaseIds;
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
};

const persistSnapshots = async (snapshots: Snapshot[], bootstrapOnly: boolean): Promise<void> => {
  if (!snapshots.length) return;
  const client = await pool.connect();

  try {
    await client.query("BEGIN");

    for (const snapshot of snapshots) {
      const values = [
        snapshot.caseId,
        snapshot.fingerprint,
        snapshot.evidenceScore,
        snapshot.overallRisk,
        snapshot.accountCount,
        snapshot.deviceCount,
        snapshot.reasonCount,
        snapshot.accountIds.join(","),
        snapshot.reasonCodes.join(","),
      ];

      if (bootstrapOnly) {
        await client.query(
          `INSERT INTO anti_fraud_case_state (
             case_id, current_fingerprint, current_evidence_score, current_overall_risk,
             current_account_count, current_device_count, current_reason_count,
             current_account_ids, current_reason_codes, current_changed_at,
             first_seen_at, last_seen_at, observed_runs
           )
           VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,now(),now(),now(),1)
           ON CONFLICT (case_id) DO NOTHING`,
          values,
        );
        continue;
      }

      await client.query(
        `INSERT INTO anti_fraud_case_state (
           case_id, current_fingerprint, current_evidence_score, current_overall_risk,
           current_account_count, current_device_count, current_reason_count,
           current_account_ids, current_reason_codes, current_changed_at,
           first_seen_at, last_seen_at, observed_runs
         )
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,now(),now(),now(),1)
         ON CONFLICT (case_id) DO UPDATE SET
           previous_evidence_score = CASE
             WHEN anti_fraud_case_state.current_fingerprint IS DISTINCT FROM EXCLUDED.current_fingerprint
               THEN anti_fraud_case_state.current_evidence_score
             ELSE anti_fraud_case_state.previous_evidence_score
           END,
           previous_overall_risk = CASE
             WHEN anti_fraud_case_state.current_fingerprint IS DISTINCT FROM EXCLUDED.current_fingerprint
               THEN anti_fraud_case_state.current_overall_risk
             ELSE anti_fraud_case_state.previous_overall_risk
           END,
           previous_account_count = CASE
             WHEN anti_fraud_case_state.current_fingerprint IS DISTINCT FROM EXCLUDED.current_fingerprint
               THEN anti_fraud_case_state.current_account_count
             ELSE anti_fraud_case_state.previous_account_count
           END,
           previous_device_count = CASE
             WHEN anti_fraud_case_state.current_fingerprint IS DISTINCT FROM EXCLUDED.current_fingerprint
               THEN anti_fraud_case_state.current_device_count
             ELSE anti_fraud_case_state.previous_device_count
           END,
           previous_reason_count = CASE
             WHEN anti_fraud_case_state.current_fingerprint IS DISTINCT FROM EXCLUDED.current_fingerprint
               THEN anti_fraud_case_state.current_reason_count
             ELSE anti_fraud_case_state.previous_reason_count
           END,
           previous_account_ids = CASE
             WHEN anti_fraud_case_state.current_fingerprint IS DISTINCT FROM EXCLUDED.current_fingerprint
               THEN anti_fraud_case_state.current_account_ids
             ELSE anti_fraud_case_state.previous_account_ids
           END,
           previous_reason_codes = CASE
             WHEN anti_fraud_case_state.current_fingerprint IS DISTINCT FROM EXCLUDED.current_fingerprint
               THEN anti_fraud_case_state.current_reason_codes
             ELSE anti_fraud_case_state.previous_reason_codes
           END,
           previous_changed_at = CASE
             WHEN anti_fraud_case_state.current_fingerprint IS DISTINCT FROM EXCLUDED.current_fingerprint
               THEN anti_fraud_case_state.current_changed_at
             ELSE anti_fraud_case_state.previous_changed_at
           END,
           current_fingerprint = EXCLUDED.current_fingerprint,
           current_evidence_score = EXCLUDED.current_evidence_score,
           current_overall_risk = EXCLUDED.current_overall_risk,
           current_account_count = EXCLUDED.current_account_count,
           current_device_count = EXCLUDED.current_device_count,
           current_reason_count = EXCLUDED.current_reason_count,
           current_account_ids = EXCLUDED.current_account_ids,
           current_reason_codes = EXCLUDED.current_reason_codes,
           current_changed_at = CASE
             WHEN anti_fraud_case_state.current_fingerprint IS DISTINCT FROM EXCLUDED.current_fingerprint
               THEN now()
             ELSE anti_fraud_case_state.current_changed_at
           END,
           last_seen_at = now(),
           observed_runs = anti_fraud_case_state.observed_runs + 1`,
        values,
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

const persistWebVisibleAccountFirstSeen = async (snapshots: Snapshot[]): Promise<void> => {
  const accountIds = uniqueSortedNumbers(snapshots.flatMap((snapshot) => snapshot.accountIds));
  if (!accountIds.length) return;

  await pool.query(
    `INSERT INTO anti_fraud_web_account_state (bitrix_user_id, first_seen_at)
     SELECT unnest($1::int[]), now()
     ON CONFLICT (bitrix_user_id) DO NOTHING`,
    [accountIds],
  );
};

export const captureAntiFraudCaseDynamics = async (): Promise<void> => {
  const cases = await listAntiFraudCases();
  const snapshots = cases.map(snapshotForCase);
  await reconcileSnapshotCaseLineage(snapshots);
  await persistSnapshots(snapshots, false);
  await persistWebVisibleAccountFirstSeen(snapshots);
};

const trendFor = (
  row: StateRow,
  currentAccounts: number[],
  previousAccounts: number[],
  currentReasons: string[],
  previousReasons: string[],
): AntiFraudCaseTrend => {
  const hasPrevious = row.previous_account_count !== null;
  if (!hasPrevious) return Number(row.observed_runs) <= 1 ? "new" : "unchanged";

  const addedAccounts = differenceNumbers(currentAccounts, previousAccounts);
  const addedReasons = differenceStrings(currentReasons, previousReasons);
  const removedAccounts = differenceNumbers(previousAccounts, currentAccounts);
  const removedReasons = differenceStrings(previousReasons, currentReasons);

  if (
    Number(row.current_evidence_score) > Number(row.previous_evidence_score ?? 0)
    || Number(row.current_device_count) > Number(row.previous_device_count ?? 0)
    || Number(row.current_reason_count) > Number(row.previous_reason_count ?? 0)
    || addedAccounts.length > 0
    || addedReasons.length > 0
  ) {
    return "strengthened";
  }

  if (
    Number(row.current_evidence_score) < Number(row.previous_evidence_score ?? 0)
    || Number(row.current_device_count) < Number(row.previous_device_count ?? 0)
    || Number(row.current_reason_count) < Number(row.previous_reason_count ?? 0)
    || removedAccounts.length > 0
    || removedReasons.length > 0
  ) {
    return "weakened";
  }

  return "unchanged";
};

export const listAntiFraudCaseDynamics = async (): Promise<AntiFraudCaseDynamics[]> => {
  const cases = await listAntiFraudCases();
  const snapshots = cases.map(snapshotForCase);
  const renamedCaseIds = await reconcileSnapshotCaseLineage(snapshots);

  if (renamedCaseIds.size > 0) {
    await persistSnapshots(
      snapshots.filter((snapshot) => renamedCaseIds.has(snapshot.caseId)),
      false,
    );
  }
  await persistSnapshots(
    snapshots.filter((snapshot) => !renamedCaseIds.has(snapshot.caseId)),
    true,
  );

  const caseIds = snapshots.map((snapshot) => snapshot.caseId);
  if (!caseIds.length) return [];

  const allCurrentAccountIds = uniqueSortedNumbers(snapshots.flatMap((snapshot) => snapshot.accountIds));
  const [result, recentResult] = await Promise.all([
    pool.query<StateRow>(
      `SELECT
         case_id,
         current_evidence_score,
         current_overall_risk,
         current_account_count,
         current_device_count,
         current_reason_count,
         current_account_ids,
         current_reason_codes,
         current_changed_at,
         previous_evidence_score,
         previous_overall_risk,
         previous_account_count,
         previous_device_count,
         previous_reason_count,
         previous_account_ids,
         previous_reason_codes,
         previous_changed_at,
         observed_runs
       FROM anti_fraud_case_state
       WHERE case_id = ANY($1::text[])`,
      [caseIds],
    ),
    pool.query<{ bitrix_user_id: number }>(
      `SELECT bitrix_user_id
       FROM anti_fraud_web_account_state
       WHERE first_seen_at >= now() - interval '24 hours'
         AND bitrix_user_id = ANY($1::int[])`,
      [allCurrentAccountIds],
    ),
  ]);

  const recentAccountIds = new Set(recentResult.rows.map((row) => Number(row.bitrix_user_id)));

  return result.rows.map((row) => {
    const currentAccounts = parseNumberCsv(row.current_account_ids);
    const previousAccounts = parseNumberCsv(row.previous_account_ids);
    const currentReasons = parseStringCsv(row.current_reason_codes);
    const previousReasons = parseStringCsv(row.previous_reason_codes);
    const hasPrevious = row.previous_account_count !== null;
    const unchangedWithoutPrevious = !hasPrevious && Number(row.observed_runs) > 1;

    const beforeRisk = hasPrevious
      ? Number(row.previous_overall_risk)
      : unchangedWithoutPrevious
        ? Number(row.current_overall_risk)
        : null;
    const beforeAccounts = hasPrevious
      ? Number(row.previous_account_count)
      : unchangedWithoutPrevious
        ? Number(row.current_account_count)
        : null;
    const beforeDevices = hasPrevious
      ? Number(row.previous_device_count)
      : unchangedWithoutPrevious
        ? Number(row.current_device_count)
        : null;
    const beforeReasons = hasPrevious
      ? Number(row.previous_reason_count)
      : unchangedWithoutPrevious
        ? Number(row.current_reason_count)
        : null;

    const forensicAddedAccountIds = hasPrevious
      ? differenceNumbers(currentAccounts, previousAccounts)
      : [];
    const recentForCase = currentAccounts.filter((id) => recentAccountIds.has(id));

    return {
      caseId: row.case_id,
      trend: trendFor(row, currentAccounts, previousAccounts, currentReasons, previousReasons),
      changedAt: iso(row.current_changed_at),
      previousChangedAt: nullableIso(row.previous_changed_at),
      evidenceScore: Number(row.current_evidence_score),
      metrics: {
        risk: metric(beforeRisk, Number(row.current_overall_risk)),
        accounts: metric(beforeAccounts, Number(row.current_account_count)),
        devices: metric(beforeDevices, Number(row.current_device_count)),
        reasons: metric(beforeReasons, Number(row.current_reason_count)),
      },
      addedAccountIds: recentForCase,
      forensicAddedAccountIds,
      removedAccountIds: hasPrevious ? differenceNumbers(previousAccounts, currentAccounts) : [],
      addedReasonCodes: hasPrevious ? differenceStrings(currentReasons, previousReasons) : [],
      removedReasonCodes: hasPrevious ? differenceStrings(previousReasons, currentReasons) : [],
      recentAccountIds: recentForCase,
    };
  });
};
