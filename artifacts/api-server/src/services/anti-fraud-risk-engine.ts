import { randomUUID } from "node:crypto";
import { pool } from "@workspace/db";
import { enrichRestisHistoryForHighRiskUserOnce } from "./anti-fraud-restis-history-enricher";
import { syncBitrixAccountsOnce } from "./anti-fraud-bitrix-account-collector";
import {
  DEFAULT_ANTI_FRAUD_BONUS_BALANCE_THRESHOLD,
  getAntiFraudSettings,
} from "./anti-fraud-settings-service";

const SOURCE = "anti_fraud_risk_scoring";
const LOCK_NAME = "anti_fraud_risk_scoring";
const CALCULATION_VERSION = "v1.3";
const DEFAULT_HISTORY_THRESHOLD = 50;
const DEFAULT_MAX_HISTORY_USERS = 10;
const MAX_HISTORY_USERS = 50;
const BONUS_BALANCE_RISK = 50;

// Добавлено 03.09.2026 ИТ Директор Евразии
export type AntiFraudRiskSignals = {
  bitrixUserId: number;
  maxAccountsOnDevice: number;
  sharedDeviceCount: number;
  linkedAccountCount: number;
  fastestSwitchSeconds: number | null;
  fastSwitchCount5m: number;
  repeatedPairDeviceCount: number;
  duplicatePhoneAccounts: number;
  duplicateEmailAccounts: number;
  nearbyLinkedVisitPairs: number;
  maxVisitsPerDay60d: number;
  highVisitDays60d: number;
  longestHighVisitSequence2d: number;
  maxDistinctRestaurantsOnHighVisitDay: number;
  activeCardCount: number;
  bitrixActive: boolean;
  historyEnriched: boolean;
  // Обновлено 05.09.2026 ИТ Директор Евразии
  // NUMERIC(14,2) из RestIS TotalSum. В JS используется только для сравнения risk threshold.
  bonusBalance: number | null;
};

export type AntiFraudRiskReason = {
  code: string;
  score: number;
  details: string;
};

export type AntiFraudRiskScore = {
  bitrixUserId: number;
  deviceRisk: number;
  linkedAccountRisk: number;
  identitySimilarityRisk: number;
  visitBehaviorRisk: number;
  historicalBehaviorRisk: number;
  overallRisk: number;
  riskLevel: "low" | "medium" | "high" | "critical";
  historyGate: boolean;
  historyEnriched: boolean;
  reasons: AntiFraudRiskReason[];
};

export type AntiFraudRiskAnalysisOptions = {
  refreshAccounts?: boolean;
  autoHistory?: boolean;
  historyThreshold?: number;
  maxHistoryUsers?: number;
  bonusBalanceThreshold?: number;
};

export type AntiFraudRiskAnalysisResult = {
  runId: string;
  scoredAccounts: number;
  mediumAccounts: number;
  highAccounts: number;
  criticalAccounts: number;
  historyGateAccounts: number;
  historyEligibleAccounts: number;
  historyAttemptedAccounts: number;
  historySuccessfulAccounts: number;
  historyFailedAccounts: number;
  refreshedAccounts: number;
  topRisk: Array<{
    bitrixUserId: number;
    overallRisk: number;
    riskLevel: string;
  }>;
};

type SignalRow = {
  bitrix_user_id: number;
  max_accounts_on_device: string | number | null;
  shared_device_count: string | number | null;
  linked_account_count: string | number | null;
  fastest_switch_seconds: string | number | null;
  fast_switch_count_5m: string | number | null;
  repeated_pair_device_count: string | number | null;
  duplicate_phone_accounts: string | number | null;
  duplicate_email_accounts: string | number | null;
  nearby_linked_visit_pairs: string | number | null;
  max_visits_per_day_60d: string | number | null;
  high_visit_days_60d: string | number | null;
  longest_high_visit_sequence_2d: string | number | null;
  max_distinct_restaurants_on_high_visit_day: string | number | null;
  active_card_count: string | number | null;
  bitrix_active: boolean | null;
  history_enriched: boolean | null;
  bonus_balance: string | number | null;
};

const clamp = (value: number): number => Math.max(0, Math.min(100, Math.round(value)));

const boundedInteger = (
  value: number,
  fallback: number,
  minimum: number,
  maximum: number,
): number => {
  if (!Number.isInteger(value)) return fallback;
  return Math.max(minimum, Math.min(maximum, value));
};

const nonNegativeNumber = (value: number, fallback: number): number =>
  Number.isFinite(value) && value >= 0 ? Math.round(value * 100) / 100 : fallback;

const toCount = (value: string | number | null): number => {
  const number = Number(value ?? 0);
  return Number.isFinite(number) && number > 0 ? Math.floor(number) : 0;
};

const toNullableNonNegativeNumber = (value: string | number | null): number | null => {
  if (value === null) return null;
  const number = Number(value);
  return Number.isFinite(number) && number >= 0 ? number : null;
};

const riskLevelFor = (score: number): AntiFraudRiskScore["riskLevel"] => {
  if (score >= 75) return "critical";
  if (score >= 50) return "high";
  if (score >= 25) return "medium";
  return "low";
};

// Добавлено 03.09.2026 ИТ Директор Евразии
// v1 использует только объяснимые признаки. Уже два аккаунта на одном устройстве считаются
// достаточным multiaccount-сигналом для history gate: базовый breakdown 40 за общее устройство
// + 10 за один связанный аккаунт = итоговые 50. Посещения считаются по всем ресторанам вместе:
// одинаковые и разные рестораны одинаково входят в суточную частоту; 3+ посещения за ресторанный
// день являются самостоятельным risk gate.
// Обновлено 05.09.2026: остаток RestIS TotalSum строго выше настроенного порога даёт +50
// и запускает history gate. Значение по умолчанию остаётся 40000.00.
export const scoreAntiFraudSignals = (
  signals: AntiFraudRiskSignals,
  historyThreshold = DEFAULT_HISTORY_THRESHOLD,
  bonusBalanceThreshold = DEFAULT_ANTI_FRAUD_BONUS_BALANCE_THRESHOLD,
): AntiFraudRiskScore => {
  const reasons: AntiFraudRiskReason[] = [];
  let deviceRisk = 0;
  let linkedAccountRisk = 0;
  let identitySimilarityRisk = 0;
  let visitBehaviorRisk = 0;
  let historicalBehaviorRisk = 0;
  const effectiveBonusBalanceThreshold = nonNegativeNumber(
    bonusBalanceThreshold,
    DEFAULT_ANTI_FRAUD_BONUS_BALANCE_THRESHOLD,
  );

  if (signals.maxAccountsOnDevice >= 4) {
    deviceRisk += 45;
    reasons.push({
      code: "shared_device_accounts",
      score: 45,
      details: `max_accounts=${signals.maxAccountsOnDevice}; shared_devices=${signals.sharedDeviceCount}`,
    });
  } else if (signals.maxAccountsOnDevice === 3) {
    deviceRisk += 35;
    reasons.push({
      code: "shared_device_accounts",
      score: 35,
      details: `max_accounts=3; shared_devices=${signals.sharedDeviceCount}`,
    });
  } else if (signals.maxAccountsOnDevice === 2) {
    deviceRisk += 40;
    reasons.push({
      code: "shared_device_accounts",
      score: 40,
      details: `max_accounts=2; shared_devices=${signals.sharedDeviceCount}`,
    });
  }

  if (signals.fastestSwitchSeconds !== null) {
    let switchScore = 0;
    if (signals.fastestSwitchSeconds <= 30) switchScore = 35;
    else if (signals.fastestSwitchSeconds <= 120) switchScore = 30;
    else if (signals.fastestSwitchSeconds <= 300) switchScore = 15;

    if (switchScore > 0) {
      deviceRisk += switchScore;
      reasons.push({
        code: "fast_account_switch",
        score: switchScore,
        details: `fastest_seconds=${signals.fastestSwitchSeconds}; switches_under_5m=${signals.fastSwitchCount5m}`,
      });
    }
  }

  if (signals.fastSwitchCount5m >= 5) {
    deviceRisk += 20;
    reasons.push({
      code: "repeated_fast_switches",
      score: 20,
      details: `switches_under_5m=${signals.fastSwitchCount5m}`,
    });
  } else if (signals.fastSwitchCount5m >= 3) {
    deviceRisk += 15;
    reasons.push({
      code: "repeated_fast_switches",
      score: 15,
      details: `switches_under_5m=${signals.fastSwitchCount5m}`,
    });
  } else if (signals.fastSwitchCount5m >= 1) {
    deviceRisk += 5;
  }

  if (signals.sharedDeviceCount >= 2) {
    const score = signals.sharedDeviceCount >= 3 ? 15 : 10;
    deviceRisk += score;
    reasons.push({
      code: "multiple_shared_devices",
      score,
      details: `shared_devices=${signals.sharedDeviceCount}`,
    });
  }

  if (signals.linkedAccountCount >= 3) {
    linkedAccountRisk += 30;
  } else if (signals.linkedAccountCount === 2) {
    linkedAccountRisk += 20;
  } else if (signals.linkedAccountCount === 1) {
    linkedAccountRisk += 10;
  }

  if (signals.linkedAccountCount > 0) {
    reasons.push({
      code: "linked_accounts",
      score: linkedAccountRisk,
      details: `linked_accounts=${signals.linkedAccountCount}`,
    });
  }

  if (signals.repeatedPairDeviceCount >= 2) {
    const score = signals.repeatedPairDeviceCount >= 3 ? 40 : 30;
    linkedAccountRisk += score;
    reasons.push({
      code: "repeated_device_pair",
      score,
      details: `max_devices_for_same_pair=${signals.repeatedPairDeviceCount}`,
    });
  }

  if (signals.duplicatePhoneAccounts > 0) {
    identitySimilarityRisk += 35;
    reasons.push({
      code: "duplicate_phone_identity",
      score: 35,
      details: `matching_other_accounts=${signals.duplicatePhoneAccounts}`,
    });
  }

  if (signals.duplicateEmailAccounts > 0) {
    identitySimilarityRisk += 25;
    reasons.push({
      code: "duplicate_email_identity",
      score: 25,
      details: `matching_other_accounts=${signals.duplicateEmailAccounts}`,
    });
  }

  if (signals.duplicatePhoneAccounts > 0 && signals.duplicateEmailAccounts > 0) {
    identitySimilarityRisk += 15;
  }

  if (signals.nearbyLinkedVisitPairs >= 5) {
    visitBehaviorRisk += 50;
  } else if (signals.nearbyLinkedVisitPairs >= 3) {
    visitBehaviorRisk += 35;
  } else if (signals.nearbyLinkedVisitPairs >= 1) {
    visitBehaviorRisk += 20;
  }

  if (signals.nearbyLinkedVisitPairs > 0) {
    reasons.push({
      code: "linked_visit_proximity",
      score: visitBehaviorRisk,
      details: `same_restaurant_pairs_under_15m=${signals.nearbyLinkedVisitPairs}`,
    });
  }

  // Добавлено 03.09.2026 ИТ Директор Евразии
  // Суточная частота считается независимо от ресторана. Поэтому 3 посещения одного ресторана и
  // 3 посещения трёх разных ресторанов одинаково являются первичным сигналом для адресной истории.
  let dailyFrequencyScore = 0;
  if (signals.maxVisitsPerDay60d >= 5) dailyFrequencyScore = 70;
  else if (signals.maxVisitsPerDay60d === 4) dailyFrequencyScore = 60;
  else if (signals.maxVisitsPerDay60d === 3) dailyFrequencyScore = 50;

  if (dailyFrequencyScore > 0) {
    visitBehaviorRisk += dailyFrequencyScore;
    reasons.push({
      code: "high_daily_visit_frequency",
      score: dailyFrequencyScore,
      details:
        `max_visits_per_day=${signals.maxVisitsPerDay60d}; ` +
        `high_visit_days_60d=${signals.highVisitDays60d}; ` +
        `max_distinct_restaurants=${signals.maxDistinctRestaurantsOnHighVisitDay}`,
    });
  }

  // Добавлено 03.09.2026 ИТ Директор Евразии
  // Последовательность считается непрерывной, пока между днями с 3+ посещениями не более двух суток.
  // Это покрывает бизнес-условие «каждый день или через день».
  let repeatedFrequencyScore = 0;
  if (signals.longestHighVisitSequence2d >= 5) repeatedFrequencyScore = 50;
  else if (signals.longestHighVisitSequence2d >= 3) repeatedFrequencyScore = 35;
  else if (signals.longestHighVisitSequence2d >= 2) repeatedFrequencyScore = 15;

  if (repeatedFrequencyScore > 0) {
    visitBehaviorRisk += repeatedFrequencyScore;
    reasons.push({
      code: "repeated_high_visit_days",
      score: repeatedFrequencyScore,
      details:
        `sequence_days=${signals.longestHighVisitSequence2d}; ` +
        `high_visit_days_60d=${signals.highVisitDays60d}; max_gap_days=2`,
    });
  }

  if (
    signals.bonusBalance !== null &&
    signals.bonusBalance > effectiveBonusBalanceThreshold
  ) {
    historicalBehaviorRisk += BONUS_BALANCE_RISK;
    reasons.push({
      code: "high_bonus_balance",
      score: BONUS_BALANCE_RISK,
      details:
        `bonus_balance=${signals.bonusBalance.toFixed(2)}; ` +
        `threshold=${effectiveBonusBalanceThreshold.toFixed(2)}; history_window_days=60`,
    });
  }

  deviceRisk = clamp(deviceRisk);
  linkedAccountRisk = clamp(linkedAccountRisk);
  identitySimilarityRisk = clamp(identitySimilarityRisk);
  visitBehaviorRisk = clamp(visitBehaviorRisk);
  historicalBehaviorRisk = clamp(historicalBehaviorRisk);

  const overallRisk = clamp(
    deviceRisk +
      linkedAccountRisk +
      identitySimilarityRisk +
      visitBehaviorRisk +
      historicalBehaviorRisk,
  );
  const threshold = boundedInteger(historyThreshold, DEFAULT_HISTORY_THRESHOLD, 1, 100);

  return {
    bitrixUserId: signals.bitrixUserId,
    deviceRisk,
    linkedAccountRisk,
    identitySimilarityRisk,
    visitBehaviorRisk,
    historicalBehaviorRisk,
    overallRisk,
    riskLevel: riskLevelFor(overallRisk),
    historyGate: overallRisk >= threshold,
    historyEnriched: signals.historyEnriched,
    reasons,
  };
};

const SIGNAL_SQL = `
WITH candidates AS (
  SELECT bitrix_user_id FROM anti_fraud_accounts
  UNION
  SELECT bitrix_user_id FROM anti_fraud_cards WHERE bitrix_user_id IS NOT NULL
  UNION
  SELECT bitrix_user_id FROM anti_fraud_device_links
  UNION
  SELECT bitrix_user_id FROM anti_fraud_device_events
  UNION
  SELECT bitrix_user_id FROM anti_fraud_visits WHERE bitrix_user_id IS NOT NULL
),
device_counts AS (
  SELECT device_hash, count(DISTINCT bitrix_user_id)::int AS account_count
  FROM anti_fraud_device_links
  GROUP BY device_hash
),
user_device AS (
  SELECT
    l.bitrix_user_id,
    COALESCE(max(dc.account_count) FILTER (WHERE dc.account_count > 1), 0)::int AS max_accounts_on_device,
    count(DISTINCT l.device_hash) FILTER (WHERE dc.account_count > 1)::int AS shared_device_count
  FROM anti_fraud_device_links l
  JOIN device_counts dc USING (device_hash)
  GROUP BY l.bitrix_user_id
),
linked_accounts AS (
  SELECT
    a.bitrix_user_id,
    count(DISTINCT b.bitrix_user_id)::int AS linked_account_count
  FROM anti_fraud_device_links a
  JOIN anti_fraud_device_links b
    ON b.device_hash = a.device_hash
   AND b.bitrix_user_id <> a.bitrix_user_id
  GROUP BY a.bitrix_user_id
),
pair_devices AS (
  SELECT
    a.bitrix_user_id AS left_user,
    b.bitrix_user_id AS right_user,
    count(DISTINCT a.device_hash)::int AS device_count
  FROM anti_fraud_device_links a
  JOIN anti_fraud_device_links b
    ON b.device_hash = a.device_hash
   AND a.bitrix_user_id < b.bitrix_user_id
  GROUP BY a.bitrix_user_id, b.bitrix_user_id
),
pair_by_user AS (
  SELECT bitrix_user_id, max(device_count)::int AS repeated_pair_device_count
  FROM (
    SELECT left_user AS bitrix_user_id, device_count FROM pair_devices
    UNION ALL
    SELECT right_user AS bitrix_user_id, device_count FROM pair_devices
  ) x
  GROUP BY bitrix_user_id
),
ordered_events AS (
  SELECT
    device_hash,
    bitrix_user_id,
    occurred_at,
    lag(bitrix_user_id) OVER (
      PARTITION BY device_hash
      ORDER BY occurred_at, source_event_id::bigint
    ) AS previous_user,
    lag(occurred_at) OVER (
      PARTITION BY device_hash
      ORDER BY occurred_at, source_event_id::bigint
    ) AS previous_time
  FROM anti_fraud_device_events
),
switches AS (
  SELECT
    device_hash,
    previous_user,
    bitrix_user_id,
    extract(epoch FROM occurred_at - previous_time)::int AS seconds
  FROM ordered_events
  WHERE previous_user IS NOT NULL
    AND previous_user <> bitrix_user_id
    AND occurred_at >= previous_time
    AND occurred_at - previous_time <= interval '5 minutes'
),
switch_users AS (
  SELECT previous_user AS bitrix_user_id, seconds FROM switches
  UNION ALL
  SELECT bitrix_user_id, seconds FROM switches
),
switch_agg AS (
  SELECT
    bitrix_user_id,
    min(seconds)::int AS fastest_switch_seconds,
    count(*)::int AS fast_switch_count_5m
  FROM switch_users
  GROUP BY bitrix_user_id
),
phone_groups AS (
  SELECT phone_normalized, count(DISTINCT bitrix_user_id)::int AS account_count
  FROM anti_fraud_accounts
  WHERE bitrix_active IS TRUE
    AND phone_normalized IS NOT NULL
  GROUP BY phone_normalized
  HAVING count(DISTINCT bitrix_user_id) > 1
),
email_groups AS (
  SELECT email_normalized, count(DISTINCT bitrix_user_id)::int AS account_count
  FROM anti_fraud_accounts
  WHERE bitrix_active IS TRUE
    AND email_normalized IS NOT NULL
  GROUP BY email_normalized
  HAVING count(DISTINCT bitrix_user_id) > 1
),
identity_agg AS (
  SELECT
    a.bitrix_user_id,
    COALESCE(pg.account_count - 1, 0)::int AS duplicate_phone_accounts,
    COALESCE(eg.account_count - 1, 0)::int AS duplicate_email_accounts
  FROM anti_fraud_accounts a
  LEFT JOIN phone_groups pg ON pg.phone_normalized = a.phone_normalized
  LEFT JOIN email_groups eg ON eg.email_normalized = a.email_normalized
),
linked_pairs AS (
  SELECT DISTINCT left_user, right_user
  FROM pair_devices
),
active_visits AS (
  SELECT v.restis_id, v.bitrix_user_id, v.visited_at, v.restaurant
  FROM anti_fraud_visits v
  LEFT JOIN anti_fraud_cards c ON c.id = v.card_id
  WHERE v.bitrix_user_id IS NOT NULL
    AND v.visited_at >= now() - interval '60 days'
    AND (
      v.loyalty_verified IS TRUE
      OR (v.card_id IS NOT NULL AND c.is_active IS TRUE)
    )
),
near_visit_pairs AS (
  SELECT
    p.left_user,
    p.right_user,
    count(*)::int AS pair_count
  FROM linked_pairs p
  JOIN active_visits v1 ON v1.bitrix_user_id = p.left_user
  JOIN active_visits v2
    ON v2.bitrix_user_id = p.right_user
   AND v2.restaurant = v1.restaurant
   AND abs(extract(epoch FROM v2.visited_at - v1.visited_at)) <= 900
  GROUP BY p.left_user, p.right_user
),
near_visit_by_user AS (
  SELECT bitrix_user_id, sum(pair_count)::int AS nearby_linked_visit_pairs
  FROM (
    SELECT left_user AS bitrix_user_id, pair_count FROM near_visit_pairs
    UNION ALL
    SELECT right_user AS bitrix_user_id, pair_count FROM near_visit_pairs
  ) x
  GROUP BY bitrix_user_id
),
-- Добавлено 03.09.2026 ИТ Директор Евразии
-- Restaurant day фиксируем в Europe/Moscow, потому что RestIS operational timestamps работают в +03.
daily_visit_counts AS (
  SELECT
    bitrix_user_id,
    (visited_at AT TIME ZONE 'Europe/Moscow')::date AS visit_day,
    count(*)::int AS visit_count,
    count(DISTINCT restaurant)::int AS distinct_restaurants
  FROM active_visits
  GROUP BY bitrix_user_id, (visited_at AT TIME ZONE 'Europe/Moscow')::date
),
high_visit_days AS (
  SELECT *
  FROM daily_visit_counts
  WHERE visit_count >= 3
),
high_visit_with_previous AS (
  SELECT
    bitrix_user_id,
    visit_day,
    visit_count,
    distinct_restaurants,
    lag(visit_day) OVER (
      PARTITION BY bitrix_user_id
      ORDER BY visit_day
    ) AS previous_day
  FROM high_visit_days
),
high_visit_marked AS (
  SELECT
    *,
    CASE
      WHEN previous_day IS NULL OR visit_day - previous_day > 2 THEN 1
      ELSE 0
    END AS new_sequence
  FROM high_visit_with_previous
),
high_visit_grouped AS (
  SELECT
    *,
    sum(new_sequence) OVER (
      PARTITION BY bitrix_user_id
      ORDER BY visit_day
      ROWS UNBOUNDED PRECEDING
    ) AS sequence_id
  FROM high_visit_marked
),
high_visit_sequences AS (
  SELECT
    bitrix_user_id,
    sequence_id,
    count(*)::int AS sequence_days
  FROM high_visit_grouped
  GROUP BY bitrix_user_id, sequence_id
),
visit_frequency_agg AS (
  SELECT
    bitrix_user_id,
    max(visit_count)::int AS max_visits_per_day_60d,
    count(*) FILTER (WHERE visit_count >= 3)::int AS high_visit_days_60d,
    COALESCE(
      max(distinct_restaurants) FILTER (WHERE visit_count >= 3),
      0
    )::int AS max_distinct_restaurants_on_high_visit_day
  FROM daily_visit_counts
  GROUP BY bitrix_user_id
),
visit_sequence_agg AS (
  SELECT
    bitrix_user_id,
    max(sequence_days)::int AS longest_high_visit_sequence_2d
  FROM high_visit_sequences
  GROUP BY bitrix_user_id
),
card_agg AS (
  SELECT
    bitrix_user_id,
    count(*) FILTER (WHERE is_active IS TRUE)::int AS active_card_count
  FROM anti_fraud_cards
  WHERE bitrix_user_id IS NOT NULL
  GROUP BY bitrix_user_id
)
SELECT
  c.bitrix_user_id,
  COALESCE(ud.max_accounts_on_device, 0) AS max_accounts_on_device,
  COALESCE(ud.shared_device_count, 0) AS shared_device_count,
  COALESCE(la.linked_account_count, 0) AS linked_account_count,
  sa.fastest_switch_seconds,
  COALESCE(sa.fast_switch_count_5m, 0) AS fast_switch_count_5m,
  COALESCE(pu.repeated_pair_device_count, 0) AS repeated_pair_device_count,
  COALESCE(ia.duplicate_phone_accounts, 0) AS duplicate_phone_accounts,
  COALESCE(ia.duplicate_email_accounts, 0) AS duplicate_email_accounts,
  COALESCE(nv.nearby_linked_visit_pairs, 0) AS nearby_linked_visit_pairs,
  COALESCE(vf.max_visits_per_day_60d, 0) AS max_visits_per_day_60d,
  COALESCE(vf.high_visit_days_60d, 0) AS high_visit_days_60d,
  COALESCE(vs.longest_high_visit_sequence_2d, 0) AS longest_high_visit_sequence_2d,
  COALESCE(vf.max_distinct_restaurants_on_high_visit_day, 0)
    AS max_distinct_restaurants_on_high_visit_day,
  COALESCE(ca.active_card_count, 0) AS active_card_count,
  COALESCE(a.bitrix_active, false) AS bitrix_active,
  CASE
    WHEN a.loyalty_history_loaded_from IS NOT NULL
      AND a.loyalty_history_loaded_until IS NOT NULL
      AND a.loyalty_history_loaded_at IS NOT NULL
      AND a.loyalty_history_loaded_from <= now() - interval '59 days'
      AND a.loyalty_history_loaded_until >= now() - interval '24 hours'
      AND a.loyalty_history_loaded_at >= now() - interval '24 hours'
    THEN true
    ELSE false
  END AS history_enriched,
  a.bonus_balance
FROM candidates c
LEFT JOIN user_device ud ON ud.bitrix_user_id = c.bitrix_user_id
LEFT JOIN linked_accounts la ON la.bitrix_user_id = c.bitrix_user_id
LEFT JOIN pair_by_user pu ON pu.bitrix_user_id = c.bitrix_user_id
LEFT JOIN switch_agg sa ON sa.bitrix_user_id = c.bitrix_user_id
LEFT JOIN identity_agg ia ON ia.bitrix_user_id = c.bitrix_user_id
LEFT JOIN near_visit_by_user nv ON nv.bitrix_user_id = c.bitrix_user_id
LEFT JOIN visit_frequency_agg vf ON vf.bitrix_user_id = c.bitrix_user_id
LEFT JOIN visit_sequence_agg vs ON vs.bitrix_user_id = c.bitrix_user_id
LEFT JOIN card_agg ca ON ca.bitrix_user_id = c.bitrix_user_id
LEFT JOIN anti_fraud_accounts a ON a.bitrix_user_id = c.bitrix_user_id
WHERE c.bitrix_user_id > 0
ORDER BY c.bitrix_user_id
`;

const loadRiskSignals = async (): Promise<AntiFraudRiskSignals[]> => {
  const result = await pool.query<SignalRow>(SIGNAL_SQL);
  return result.rows.map((row) => ({
    bitrixUserId: Number(row.bitrix_user_id),
    maxAccountsOnDevice: toCount(row.max_accounts_on_device),
    sharedDeviceCount: toCount(row.shared_device_count),
    linkedAccountCount: toCount(row.linked_account_count),
    fastestSwitchSeconds:
      row.fastest_switch_seconds === null ? null : toCount(row.fastest_switch_seconds),
    fastSwitchCount5m: toCount(row.fast_switch_count_5m),
    repeatedPairDeviceCount: toCount(row.repeated_pair_device_count),
    duplicatePhoneAccounts: toCount(row.duplicate_phone_accounts),
    duplicateEmailAccounts: toCount(row.duplicate_email_accounts),
    nearbyLinkedVisitPairs: toCount(row.nearby_linked_visit_pairs),
    maxVisitsPerDay60d: toCount(row.max_visits_per_day_60d),
    highVisitDays60d: toCount(row.high_visit_days_60d),
    longestHighVisitSequence2d: toCount(row.longest_high_visit_sequence_2d),
    maxDistinctRestaurantsOnHighVisitDay: toCount(
      row.max_distinct_restaurants_on_high_visit_day,
    ),
    activeCardCount: toCount(row.active_card_count),
    bitrixActive: row.bitrix_active === true,
    historyEnriched: row.history_enriched === true,
    bonusBalance: toNullableNonNegativeNumber(row.bonus_balance),
  }));
};

const persistScores = async (scores: AntiFraudRiskScore[], runId: string): Promise<void> => {
  const client = await pool.connect();
  try {
    await client.query("BEGIN");

    for (const score of scores) {
      await client.query(
        `INSERT INTO anti_fraud_risk_scores (
           bitrix_user_id, calculation_version, device_risk, linked_account_risk,
           identity_similarity_risk, visit_behavior_risk, historical_behavior_risk,
           overall_risk, risk_level, history_gate, history_enriched, computed_at
         )
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,now())
         ON CONFLICT (bitrix_user_id) DO UPDATE SET
           calculation_version = EXCLUDED.calculation_version,
           device_risk = EXCLUDED.device_risk,
           linked_account_risk = EXCLUDED.linked_account_risk,
           identity_similarity_risk = EXCLUDED.identity_similarity_risk,
           visit_behavior_risk = EXCLUDED.visit_behavior_risk,
           historical_behavior_risk = EXCLUDED.historical_behavior_risk,
           overall_risk = EXCLUDED.overall_risk,
           risk_level = EXCLUDED.risk_level,
           history_gate = EXCLUDED.history_gate,
           history_enriched = EXCLUDED.history_enriched,
           computed_at = now()`,
        [
          score.bitrixUserId,
          CALCULATION_VERSION,
          score.deviceRisk,
          score.linkedAccountRisk,
          score.identitySimilarityRisk,
          score.visitBehaviorRisk,
          score.historicalBehaviorRisk,
          score.overallRisk,
          score.riskLevel,
          score.historyGate,
          score.historyEnriched,
        ],
      );

      await client.query(
        `DELETE FROM anti_fraud_risk_reasons WHERE bitrix_user_id = $1`,
        [score.bitrixUserId],
      );

      for (const reason of score.reasons) {
        await client.query(
          `INSERT INTO anti_fraud_risk_reasons
             (bitrix_user_id, reason_code, score, details, computed_at)
           VALUES ($1,$2,$3,$4,now())`,
          [score.bitrixUserId, reason.code, reason.score, reason.details],
        );
      }
    }

    await client.query(
      `DELETE FROM anti_fraud_risk_scores
       WHERE bitrix_user_id <> ALL($1::int[])`,
      [scores.map((score) => score.bitrixUserId)],
    );
    await client.query(
      `DELETE FROM anti_fraud_risk_reasons
       WHERE bitrix_user_id <> ALL($1::int[])`,
      [scores.map((score) => score.bitrixUserId)],
    );

    await client.query(
      `UPDATE anti_fraud_sync_runs
       SET status = 'success', finished_at = now(), records_fetched = $2,
           records_written = $3, records_resolved = $4, error = NULL
       WHERE run_id = $1`,
      [runId, scores.length, scores.length, scores.filter((score) => score.historyGate).length],
    );

    await client.query("COMMIT");
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
};

const calculateAndPersist = async (
  runId: string,
  historyThreshold: number,
  bonusBalanceThreshold: number,
): Promise<AntiFraudRiskScore[]> => {
  const signals = await loadRiskSignals();
  const scores = signals.map((signal) =>
    scoreAntiFraudSignals(signal, historyThreshold, bonusBalanceThreshold),
  );
  await persistScores(scores, runId);
  return scores;
};

// Обновлено 05.09.2026 ИТ Директор Евразии
// Оркестратор обновляет account identity, считает risk gate и при необходимости вызывает
// защищённую loyalty history по USER_ID. Наличие карты в anti_fraud_cards больше не является
// обязательным условием: активную RESTIS_STATE=113 карту определяет site-side endpoint.
// Автоматической блокировки или изменения Bitrix ACTIVE в v1.7 здесь нет.
export const analyzeAntiFraudOnce = async (
  options: AntiFraudRiskAnalysisOptions = {},
): Promise<AntiFraudRiskAnalysisResult> => {
  const refreshAccounts = options.refreshAccounts !== false;
  const autoHistory = options.autoHistory === true;
  const historyThreshold = boundedInteger(
    Number(
      options.historyThreshold ??
        process.env.ANTI_FRAUD_HISTORY_RISK_THRESHOLD ??
        DEFAULT_HISTORY_THRESHOLD,
    ),
    DEFAULT_HISTORY_THRESHOLD,
    1,
    100,
  );
  const maxHistoryUsers = boundedInteger(
    Number(
      options.maxHistoryUsers ??
        process.env.ANTI_FRAUD_MAX_HISTORY_USERS_PER_RUN ??
        DEFAULT_MAX_HISTORY_USERS,
    ),
    DEFAULT_MAX_HISTORY_USERS,
    1,
    MAX_HISTORY_USERS,
  );
  const bonusBalanceThreshold =
    options.bonusBalanceThreshold === undefined
      ? (await getAntiFraudSettings()).bonusBalanceThreshold
      : nonNegativeNumber(
          Number(options.bonusBalanceThreshold),
          DEFAULT_ANTI_FRAUD_BONUS_BALANCE_THRESHOLD,
        );

  let refreshedAccounts = 0;
  if (refreshAccounts) {
    const accountSync = await syncBitrixAccountsOnce();
    refreshedAccounts = accountSync.updatedAccounts;
  }

  const runId = randomUUID();
  const lockClient = await pool.connect();
  let lockAcquired = false;

  try {
    const lock = await lockClient.query<{ locked: boolean }>(
      "SELECT pg_try_advisory_lock(hashtext($1)) AS locked",
      [LOCK_NAME],
    );
    lockAcquired = lock.rows[0]?.locked === true;
    if (!lockAcquired) {
      throw new Error("Anti-Fraud risk scoring уже выполняется другим процессом");
    }

    await lockClient.query(
      `INSERT INTO anti_fraud_sync_runs (run_id, source, status, started_at)
       VALUES ($1,$2,'running',now())`,
      [runId, SOURCE],
    );

    let scores = await calculateAndPersist(
      runId,
      historyThreshold,
      bonusBalanceThreshold,
    );

    const signalByUser = new Map(
      (await loadRiskSignals()).map((signal) => [signal.bitrixUserId, signal] as const),
    );
    const eligible = scores
      .filter((score) => {
        const signal = signalByUser.get(score.bitrixUserId);
        return Boolean(
          score.historyGate &&
            signal?.bitrixActive === true &&
            signal?.historyEnriched !== true,
        );
      })
      .sort((a, b) => b.overallRisk - a.overallRisk || a.bitrixUserId - b.bitrixUserId);

    let historyAttemptedAccounts = 0;
    let historySuccessfulAccounts = 0;
    let historyFailedAccounts = 0;

    if (autoHistory) {
      for (const score of eligible.slice(0, maxHistoryUsers)) {
        historyAttemptedAccounts += 1;
        try {
          await enrichRestisHistoryForHighRiskUserOnce({
            bitrixUserId: score.bitrixUserId,
            riskGateConfirmed: true,
            lookbackDays: 60,
          });
          historySuccessfulAccounts += 1;
        } catch {
          // Один недоступный protected loyalty history не должен отменять scoring остальных аккаунтов.
          historyFailedAccounts += 1;
        }
      }

      if (historyAttemptedAccounts > 0) {
        scores = await calculateAndPersist(
          runId,
          historyThreshold,
          bonusBalanceThreshold,
        );
      }
    }

    const topRisk = [...scores]
      .sort((a, b) => b.overallRisk - a.overallRisk || a.bitrixUserId - b.bitrixUserId)
      .slice(0, 20)
      .map((score) => ({
        bitrixUserId: score.bitrixUserId,
        overallRisk: score.overallRisk,
        riskLevel: score.riskLevel,
      }));

    return {
      runId,
      scoredAccounts: scores.length,
      mediumAccounts: scores.filter((score) => score.riskLevel === "medium").length,
      highAccounts: scores.filter((score) => score.riskLevel === "high").length,
      criticalAccounts: scores.filter((score) => score.riskLevel === "critical").length,
      historyGateAccounts: scores.filter((score) => score.historyGate).length,
      historyEligibleAccounts: eligible.length,
      historyAttemptedAccounts,
      historySuccessfulAccounts,
      historyFailedAccounts,
      refreshedAccounts,
      topRisk,
    };
  } catch (error) {
    try {
      await lockClient.query(
        `UPDATE anti_fraud_sync_runs
         SET status='failed', finished_at=now(), error=$2
         WHERE run_id=$1`,
        [
          runId,
          (error instanceof Error ? error.message : "Неизвестная ошибка risk scoring").slice(
            0,
            2000,
          ),
        ],
      );
    } catch {
      // Не скрываем исходную ошибку scoring.
    }
    throw error;
  } finally {
    if (lockAcquired) {
      try {
        await lockClient.query("SELECT pg_advisory_unlock(hashtext($1))", [LOCK_NAME]);
      } catch {
        // Соединение освобождается ниже.
      }
    }
    lockClient.release();
  }
};
