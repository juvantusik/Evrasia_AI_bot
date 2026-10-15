import { useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  ChevronDown,
  ChevronRight,
  Clock3,
  Fingerprint,
  Link2,
  LockKeyhole,
  Mail,
  RefreshCw,
  Search,
  ShieldAlert,
  Smartphone,
  UnlockKeyhole,
  Users,
  Utensils,
} from 'lucide-react';
import AntiFraudSettingsButton from './AntiFraudSettingsButton';
import AntiFraudInvestigationButton from './AntiFraudInvestigationButton';
import './anti-fraud-ui-next.css';

type RiskLevel = 'low' | 'medium' | 'high' | 'critical';
type CaseSignal = 'multiaccount' | 'phone' | 'email' | 'visits' | 'fast_switch' | 'linked_visits' | 'bonus_balance' | 'operator_investigation';
type CaseTrend = 'new' | 'strengthened' | 'unchanged' | 'weakened';
type ConsentSource = 'signup' | 'account_gate' | 'other';
type Reason = { code: string; score: number; details: string };

type Account = {
  bitrixUserId: number;
  displayName: string | null;
  phoneMasked: string | null;
  emailMasked: string | null;
  bonusBalance: number | null;
  loyaltyActiveCardCount: number | null;
  loyaltyIssue: string | null;
  loyaltySyncedAt: string | null;
  loyaltyHistoryLoadedFrom?: string | null;
  loyaltyHistoryLoadedUntil?: string | null;
  loyaltyHistoryLoadedAt?: string | null;
  operatorInvestigationHistoryCompletedAt?: string | null;
  operatorInvestigationCompletedAt?: string | null;
  operatorHistoryWindowFrom?: string | null;
  operatorHistoryWindowUntil?: string | null;
  historyPhysicalVisits?: number;
  historyVisitDays?: number;
  historyRestaurantCount?: number;
  historyFirstVisitAt?: string | null;
  historyLastVisitAt?: string | null;
  historyDailyVisits?: Array<{ day: string; visits: number; restaurants: number }>;
  bitrixActive: boolean;
  bitrixBlocked?: boolean;
  bitrixBlockReason?: string | null;
  blockedAt?: string | null;
  offerAccepted?: boolean | null;
  offerAcceptedAt?: string | null;
  offerSource?: ConsentSource | null;
  pdAccepted?: boolean | null;
  pdAcceptedAt?: string | null;
  pdSource?: ConsentSource | null;
  overallRisk: number;
  riskLevel: RiskLevel;
  deviceRisk: number;
  linkedAccountRisk: number;
  identitySimilarityRisk: number;
  visitBehaviorRisk: number;
  historicalBehaviorRisk: number;
  historyEnriched: boolean;
  computedAt: string;
  reasons: Reason[];
  operatorWatched: boolean;
  operatorInvestigationId?: string | null;
  operatorSource?: string | null;
  operatorReason?: string | null;
  operatorInvestigationStatus?: string | null;
  operatorInvestigationRequestedAt?: string | null;
};

type Device = {
  devicePrefix: string;
  accountCount: number;
  userIds: number[];
  lastSeenAt: string | null;
  clientTypes: string[];
};

type CaseDevice = { devicePrefix: string; userIds: number[]; lastSeenAt: string | null };
type IdentityMatch = { type: 'phone' | 'email' | 'similar_phone' | 'similar_email'; userIds: number[] };
type MetricChange = { before: number | null; after: number; delta: number | null };

type CaseDynamics = {
  caseId: string;
  trend: CaseTrend;
  changedAt: string;
  previousChangedAt: string | null;
  evidenceScore: number;
  metrics: { risk: MetricChange; accounts: MetricChange; devices: MetricChange; reasons: MetricChange };
  addedAccountIds: number[];
  removedAccountIds: number[];
  addedReasonCodes: string[];
  removedReasonCodes: string[];
};

type InvestigationCase = {
  caseId: string;
  overallRisk: number;
  riskLevel: RiskLevel;
  accountCount: number;
  accounts: Account[];
  signals: CaseSignal[];
  devices: CaseDevice[];
  trustedDevices?: CaseDevice[];
  identityMatches: IdentityMatch[];
  updatedAt: string;
  groupBonusBalance?: number | null;
  groupBonusKnownAccounts?: number;
  groupBonusTotalAccounts?: number;
  dynamics?: CaseDynamics;
};

type Summary = {
  scoredAccounts: number;
  criticalAccounts: number;
  highAccounts: number;
  mediumAccounts: number;
  lowAccounts: number;
  historyGateAccounts: number;
  historyEnrichedAccounts: number;
  devices: number;
  sharedDevices: number;
  accountsOnSharedDevices: number;
  trustedDeviceHashes: number;
  updatedAt: string | null;
};

type SchedulerStatus = {
  enabled: boolean;
  running: boolean;
  intervalMinutes: number;
  lastStartedAt: string | null;
  lastFinishedAt: string | null;
  lastSucceededAt: string | null;
  lastStatus: 'idle' | 'running' | 'success' | 'partial' | 'failed';
  lastError: string | null;
  nextRunAt: string | null;
};

type SimilarGroup = {
  matchType: 'phone' | 'email';
  accountCount: number;
  accounts: Array<{ bitrixUserId: number; displayName: string | null; overallRisk: number; riskLevel: string }>;
};

type ActionResult = {
  ok: boolean;
  requested: number;
  resolved: number;
  unresolved: number[];
  records: Array<{ bitrixUserId: number; success: boolean; changed: boolean; result: string }>;
};

const signalMeta: Record<CaseSignal, { label: string; className: string }> = {
  multiaccount: { label: 'Мультиаккаунт', className: 'tag-purple' },
  phone: { label: 'Совпадение телефона', className: 'tag-blue' },
  email: { label: 'Совпадение email', className: 'tag-blue' },
  visits: { label: 'Высокая частота посещений', className: 'tag-orange' },
  fast_switch: { label: 'Быстрые переключения', className: 'tag-red' },
  linked_visits: { label: 'Связанные посещения', className: 'tag-orange' },
  bonus_balance: { label: 'Высокий остаток бонусов', className: 'tag-orange' },
  operator_investigation: { label: 'Ручная проверка', className: 'tag-blue' },
};

const reasonLabels: Record<string, string> = {
  shared_device_accounts: 'Мультиаккаунт', fast_account_switch: 'Быстрое переключение аккаунтов',
  repeated_fast_switches: 'Повторные быстрые переключения', multiple_shared_devices: 'Несколько общих устройств',
  linked_accounts: 'Связанные аккаунты', repeated_device_pair: 'Одна связка на нескольких устройствах',
  duplicate_phone_identity: 'Совпадение телефона', duplicate_email_identity: 'Совпадение email',
  similar_phone_identity: 'Похожий номер телефона', similar_email_identity: 'Похожий email',
  similar_identity_combo: 'Похожий телефон и email', linked_visit_proximity: 'Близкие посещения связанных аккаунтов',
  high_daily_visit_frequency: 'Высокая частота посещений', repeated_high_visit_days: 'Регулярный паттерн посещений',
  persistent_checkin_frequency: 'Подтверждённая регулярная частота чекинов',
  high_bonus_balance: 'Высокий остаток бонусов', similar_identity_corroborated: 'Подтверждённая похожая идентичность',
};

const trendMeta: Record<CaseTrend, { label: string; symbol: string }> = {
  new: { label: 'Новый', symbol: '●' }, strengthened: { label: 'Усилился', symbol: '↑' },
  unchanged: { label: 'Без изменений', symbol: '—' }, weakened: { label: 'Ослаб', symbol: '↓' },
};

const fetchJson = async <T,>(url: string): Promise<T> => {
  const response = await fetch(url, { headers: { accept: 'application/json' } });
  if (!response.ok) {
    let message = 'Не удалось загрузить данные Anti-Fraud.';
    try { const body = await response.json(); if (typeof body?.error === 'string') message = body.error; } catch { /* optional */ }
    throw new Error(message);
  }
  return response.json() as Promise<T>;
};

const postJson = async <T,>(url: string, body?: unknown): Promise<T> => {
  const response = await fetch(url, {
    method: 'POST',
    headers: { accept: 'application/json', ...(body === undefined ? {} : { 'content-type': 'application/json' }) },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (!response.ok) {
    let message = 'Не удалось выполнить действие Anti-Fraud.';
    try { const payload = await response.json(); if (typeof payload?.error === 'string') message = payload.error; } catch { /* optional */ }
    throw new Error(message);
  }
  return response.json() as Promise<T>;
};

const wait = (milliseconds: number) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const waitForRefreshCompletion = async (): Promise<SchedulerStatus> => {
  for (let attempt = 0; attempt < 180; attempt += 1) {
    const scheduler = await fetchJson<SchedulerStatus>('/api/anti-fraud/scheduler');
    if (!scheduler.running) return scheduler;
    await wait(2_000);
  }
  throw new Error('Обновление Anti-Fraud выполняется слишком долго. Проверьте состояние через несколько минут.');
};

const formatDate = (value: string | null | undefined) => {
  if (!value) return '—';
  return new Intl.DateTimeFormat('ru-RU', {
    timeZone: 'Europe/Moscow', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
  }).format(new Date(value));
};
const formatPoints = (value: number | null | undefined) => value == null ? '—' : new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(value);
const formatHistoryDay = (value: string) => {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  return match ? `${match[3]}.${match[2]}` : value;
};

const investigationStatusLabel = (value: string | null | undefined): string => {
  if (value === 'pending') return 'Ожидает проверки';
  if (value === 'processing') return 'Проверка выполняется';
  if (value === 'history_ready') return 'История загружена, идёт расчёт';
  if (value === 'ready') return 'Проверка завершена';
  if (value === 'failed') return 'Ошибка проверки';
  return value || 'Статус неизвестен';
};

const consentSourceLabel = (value: ConsentSource | null | undefined): string =>
  value === 'signup' ? 'Регистрация' : value === 'account_gate' ? 'ЛК' : value === 'other' ? 'Другой источник' : '';

const consentBadgeState = (value: boolean | null | undefined): 'accepted' | 'missing' | 'unknown' =>
  value === true ? 'accepted' : value === false ? 'missing' : 'unknown';

const consentBadgeSymbol = (value: boolean | null | undefined): string =>
  value === true ? '✓' : value === false ? '—' : '?';

const consentTitle = (
  label: string,
  accepted: boolean | null | undefined,
  acceptedAt: string | null | undefined,
  source: ConsentSource | null | undefined,
): string => {
  if (accepted === null || accepted === undefined) return `${label}: ещё не синхронизировано`;
  if (!accepted) return `${label}: актуального согласия нет`;
  return `${label}: подтверждено · ${consentSourceLabel(source) || 'источник неизвестен'} · ${formatDate(acceptedAt)}`;
};

const ConsentSnapshot = ({ account }: { account: Account }) => {
  const sameSource = account.offerSource && account.offerSource === account.pdSource ? account.offerSource : null;
  const sameTime = account.offerAcceptedAt && account.offerAcceptedAt === account.pdAcceptedAt ? account.offerAcceptedAt : null;
  const acceptedSomewhere = account.offerAccepted === true || account.pdAccepted === true;
  const meta = sameSource || sameTime
    ? [sameSource ? consentSourceLabel(sameSource) : '', sameTime ? formatDate(sameTime).replace(',', '') : ''].filter(Boolean).join(' · ')
    : acceptedSomewhere && (account.offerSource || account.pdSource || account.offerAcceptedAt || account.pdAcceptedAt)
      ? 'раздельные события'
      : '';

  return <span className="consent-strip" aria-label="Статус актуальных согласий">
    <span className={`consent-badge ${consentBadgeState(account.offerAccepted)}`} title={consentTitle('Оферта', account.offerAccepted, account.offerAcceptedAt, account.offerSource)}>Оферта {consentBadgeSymbol(account.offerAccepted)}</span>
    <span className={`consent-badge ${consentBadgeState(account.pdAccepted)}`} title={consentTitle('ПД', account.pdAccepted, account.pdAcceptedAt, account.pdSource)}>ПД {consentBadgeSymbol(account.pdAccepted)}</span>
    {meta ? <span className="consent-meta" title={`${consentTitle('Оферта', account.offerAccepted, account.offerAcceptedAt, account.offerSource)}; ${consentTitle('ПД', account.pdAccepted, account.pdAcceptedAt, account.pdSource)}`}>{meta}</span> : null}
  </span>;
};

const OperatorInvestigationSummary = ({
  account,
  devices,
  identityMatches,
}: {
  account: Account;
  devices: CaseDevice[];
  identityMatches: IdentityMatch[];
}) => {
  if (!account.operatorInvestigationId) return null;

  const accountDevices = devices.filter((device) => device.userIds.includes(account.bitrixUserId));
  const linkedAccountIds = [...new Set([
    ...accountDevices.flatMap((device) => device.userIds),
    ...identityMatches
      .filter((match) => match.userIds.includes(account.bitrixUserId))
      .flatMap((match) => match.userIds),
  ])]
    .filter((id) => id !== account.bitrixUserId)
    .sort((a, b) => a - b);

  // PR #65 physical-history snapshot is authoritative for operator-visible visits.
  // Do not gate this UI on legacy loyaltyHistoryLoadedAt: multi-card accounts can have
  // a complete targeted Check-in snapshot while loyalty history is intentionally unavailable.
  const historyCovered = Boolean(
    account.operatorInvestigationHistoryCompletedAt
      && account.operatorHistoryWindowFrom
      && account.operatorHistoryWindowUntil,
  );
  const dailyVisits = account.historyDailyVisits ?? [];
  const completion = account.operatorInvestigationCompletedAt
    ?? account.operatorInvestigationHistoryCompletedAt;

  return <div className="operator-investigation-info">
    <div className="operator-investigation-heading">
      <strong>Ручная проверка · {account.operatorSource || 'источник не указан'}</strong>
      <em className={account.operatorInvestigationStatus === 'failed' ? 'failed' : 'ready'}>
        {investigationStatusLabel(account.operatorInvestigationStatus)}
      </em>
    </div>
    <span>
      {account.operatorReason || 'Комментарий не указан'}
      {account.operatorInvestigationRequestedAt ? ` · добавлено ${formatDate(account.operatorInvestigationRequestedAt)}` : ''}
      {completion ? ` · завершено ${formatDate(completion)}` : ''}
    </span>

    {historyCovered ? <div className="operator-history-result">
      <div className="operator-history-head">
        <div>
          <strong>60-дневная история проверена ✓</strong>
          <span>
            период {formatDate(account.operatorHistoryWindowFrom)} — {formatDate(account.operatorHistoryWindowUntil)}
            {account.loyaltyHistoryLoadedAt ? ` · загружено ${formatDate(account.loyaltyHistoryLoadedAt)}` : ''}
          </span>
        </div>
      </div>

      <div className="operator-history-metrics">
        <span><b>{account.historyPhysicalVisits ?? 0}</b><small>физ. посещений</small></span>
        <span><b>{account.historyVisitDays ?? 0}</b><small>дней посещений</small></span>
        <span><b>{account.historyRestaurantCount ?? 0}</b><small>ресторанов</small></span>
      </div>

      {(account.historyFirstVisitAt || account.historyLastVisitAt) ? <div className="operator-history-range">
        События: {account.historyFirstVisitAt ? formatDate(account.historyFirstVisitAt) : '—'}
        {' — '}
        {account.historyLastVisitAt ? formatDate(account.historyLastVisitAt) : '—'}
      </div> : <div className="operator-history-range">За период физических посещений не найдено.</div>}

      {dailyVisits.length ? <details className="operator-history-days">
        <summary>Посещения по дням ({dailyVisits.length})</summary>
        <div>
          {dailyVisits.map((day) => <span key={day.day}>
            <b>{formatHistoryDay(day.day)}</b>
            <small>{day.visits} посещ. · {day.restaurants} рест.</small>
          </span>)}
        </div>
      </details> : null}
    </div> : account.loyaltyIssue === 'no_active_card'
      ? <div className="operator-history-result warning"><strong>60-дневная история не загружена</strong><span>У аккаунта нет активной карты.</span></div>
      : account.operatorInvestigationStatus === 'ready'
        ? <div className="operator-history-result warning"><strong>Проверка завершена без подтверждённого покрытия истории</strong><span>Нужно проверить enrichment этого аккаунта.</span></div>
        : null}

    {(accountDevices.length > 0 || linkedAccountIds.length > 0) ? <div className="operator-investigation-relations">
      {accountDevices.map((device) => <span key={device.devicePrefix}>
        <Smartphone size={14} />
        ID устройства: <b>{device.devicePrefix}…</b>
      </span>)}
      {linkedAccountIds.length ? <span>
        <Users size={14} />
        Связанные аккаунты: <b>{linkedAccountIds.map((id) => `ID ${id}`).join(', ')}</b>
      </span> : null}
    </div> : null}

    <div className="operator-risk-caption">Risk по категориям</div>
  </div>;
};

const loyaltyText = (account: Account): string => {
  const count = account.loyaltyActiveCardCount;
  if (count == null) {
    return account.bonusBalance == null
      ? 'Карты: не загружено · Бонусы: —'
      : `Карты: не загружено · Бонусы: ${formatPoints(account.bonusBalance)}`;
  }
  if (count === 0) return account.loyaltyIssue === 'no_active_card' ? 'Активных карт: 0 · Бонусы: нет активной карты' : 'Активных карт: 0 · Бонусы: —';
  if (account.bonusBalance === null) return `Активных карт: ${count} · Бонусы: баланс недоступен`;
  if (count > 1) return `⚠ Активных карт: ${count} · Бонусы: ${formatPoints(account.bonusBalance)} суммарно`;
  return `Активных карт: 1 · Бонусы: ${formatPoints(account.bonusBalance)}`;
};

const accountStatus = (account: Account) => account.bitrixBlocked ? 'Заблокирован' : account.bitrixActive ? 'Активен' : 'Неактивен';
const isOperationalAccount = (account: Account) => account.bitrixActive && !account.bitrixBlocked;
const levelLabel: Record<RiskLevel, string> = { low: 'Низкий', medium: 'Средний', high: 'Высокий', critical: 'Критический' };
const identityMatchLabel = (match: IdentityMatch): string => match.type === 'phone' ? 'Совпадает телефон' : match.type === 'email' ? 'Совпадает email' : match.type === 'similar_phone' ? 'Похожие номера телефонов' : 'Похожие email';
const metricText = (label: string, change: MetricChange) => change.before === null ? `${label}: ${change.after}` : `${label}: ${change.before} → ${change.after}`;
const parseDetails = (details: string) => details.split(';').map((part) => part.trim()).filter(Boolean)
  .map((part) => part.replace('max_visits_per_day=', 'макс. посещений за день: ')
    .replace('high_visit_days_60d=', 'дней с 3+ посещениями: ').replace('max_distinct_restaurants=', 'макс. ресторанов за день: ')
    .replace('sequence_days=', 'длина серии: ').replace('max_gap_days=', 'макс. интервал между днями, дней: ')
    .replace('fastest_seconds=', 'самое быстрое переключение: ')
    .replace('switches_under_5m=', 'переключений до 5 минут: ').replace('max_accounts=', 'аккаунтов на устройстве: ')
    .replace('shared_devices=', 'общих устройств: ').replace('max_devices_for_same_pair=', 'макс. общих устройств для одной пары аккаунтов: ')
    .replace('linked_accounts=', 'связанных аккаунтов: ').replace('matching_other_accounts=', 'совпадающих других аккаунтов: ')
    .replace('corroborated_similar_phone_links=', 'подтверждённых связей по похожему номеру: ')
    .replace('corroborated_similar_email_links=', 'подтверждённых связей по похожему email: ')
    .replace('similar_phone_links=', 'связей по похожему номеру: ').replace('similar_email_links=', 'связей по похожему email: ')
    .replace('same_restaurant_pairs_under_15m=', 'пар посещений до 15 минут: ').replace('bonus_balance=', 'остаток бонусов: ')
    .replace('threshold=', 'порог: ').replace('history_window_days=', 'проверка истории, дней: ')
    .replace('days_2plus_7d=', 'дней с 3 чекинами за 7 дней: ')
    .replace('days_3plus_60d=', 'дней с 3+ чекинами за 60 дней: ')
    .replace('source=checkin_scout', 'источник: Check-in Scout'));

export default function AntiFraudPage() {
  const [summary, setSummary] = useState<Summary | null>(null);
  const [cases, setCases] = useState<InvestigationCase[]>([]);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [devices, setDevices] = useState<Device[]>([]);
  const [similar, setSimilar] = useState<SimilarGroup[]>([]);
  const [tab, setTab] = useState<'cases' | 'devices' | 'accounts' | 'recommendations'>('cases');
  const [query, setQuery] = useState('');
  const [level, setLevel] = useState<'all' | RiskLevel>('all');
  const [signal, setSignal] = useState<'all' | CaseSignal>('all');
  const [showBlocked, setShowBlocked] = useState(false);
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [actingIds, setActingIds] = useState<Set<number>>(new Set());
  const [error, setError] = useState('');

  const load = async () => {
    setLoading(true); setError('');
    try {
      const [summaryData, caseData, accountData, deviceData, similarData, dynamicsData] = await Promise.all([
        fetchJson<Summary>('/api/anti-fraud/summary'), fetchJson<{ records: InvestigationCase[] }>('/api/anti-fraud/cases'),
        fetchJson<{ records: Account[] }>('/api/anti-fraud/accounts?limit=500'), fetchJson<{ records: Device[] }>('/api/anti-fraud/devices?limit=500'),
        fetchJson<{ records: SimilarGroup[] }>('/api/anti-fraud/similar-accounts?limit=300'), fetchJson<{ records: CaseDynamics[] }>('/api/anti-fraud/case-dynamics'),
      ]);
      const dynamicsByCase = new Map(dynamicsData.records.map((item) => [item.caseId, item] as const));
      const normalizedCases = caseData.records.map((item) => {
        const hasBonusSignal = item.accounts.some((account) => account.reasons.some((reason) => reason.code === 'high_bonus_balance'));
        return { ...item, signals: hasBonusSignal && !item.signals.includes('bonus_balance') ? [...item.signals, 'bonus_balance' as CaseSignal] : item.signals, dynamics: dynamicsByCase.get(item.caseId) };
      });
      normalizedCases.sort((a, b) => b.overallRisk - a.overallRisk || (b.dynamics?.evidenceScore ?? 0) - (a.dynamics?.evidenceScore ?? 0) || b.accountCount - a.accountCount || a.caseId.localeCompare(b.caseId));
      setSummary(summaryData); setCases(normalizedCases); setAccounts(accountData.records); setDevices(deviceData.records); setSimilar(similarData.records);
    } catch (loadError) { setError(loadError instanceof Error ? loadError.message : 'Не удалось загрузить Anti-Fraud.'); }
    finally { setLoading(false); }
  };

  const refreshNow = async () => {
    setRefreshing(true); setError('');
    try {
      await postJson<{ ok: boolean; accepted: boolean; scheduler: SchedulerStatus }>('/api/anti-fraud/refresh');
      const scheduler = await waitForRefreshCompletion();
      if (scheduler.lastStatus === 'failed') throw new Error(scheduler.lastError ?? 'Обновление Anti-Fraud завершилось ошибкой.');
      await load();
      if (scheduler.lastStatus === 'partial') setError(`Обновление завершено частично: ${scheduler.lastError ?? 'часть источников временно недоступна.'}`);
    } catch (refreshError) { setError(refreshError instanceof Error ? refreshError.message : 'Не удалось обновить Anti-Fraud.'); }
    finally { setRefreshing(false); }
  };

  const runAccountAction = async (mode: 'block' | 'unblock', userIds: number[], caseId: string) => {
    const ids = [...new Set(userIds)];
    if (!ids.length) return;
    const verb = mode === 'block' ? 'Заблокировать' : 'Разблокировать и активировать';
    const target = ids.length === 1 ? `аккаунт ID ${ids[0]}` : `${ids.length} аккаунта(ов) кейса`;
    if (!window.confirm(`${verb} ${target}?`)) return;
    setActingIds((current) => new Set([...current, ...ids])); setError('');
    try {
      const result = await postJson<ActionResult>(`/api/anti-fraud/${mode}`, { userIds: ids, caseId });
      await load();
      if (!result.ok) {
        const failed = result.records.filter((record) => !record.success).map((record) => `ID ${record.bitrixUserId}`).concat(result.unresolved.map((id) => `ID ${id}`));
        setError(`Операция выполнена частично${failed.length ? `: ${failed.join(', ')}` : '.'}`);
      }
    } catch (actionError) { setError(actionError instanceof Error ? actionError.message : 'Не удалось выполнить действие.'); }
    finally { setActingIds((current) => { const next = new Set(current); ids.forEach((id) => next.delete(id)); return next; }); }
  };

  useEffect(() => { void load(); }, []);

  const filteredCases = useMemo(() => {
    const normalized = query.trim().toLocaleLowerCase('ru-RU');
    return cases.filter((item) => {
      const visibleAccounts = showBlocked ? item.accounts : item.accounts.filter(isOperationalAccount);
      if (!visibleAccounts.length) return false;
      if (level !== 'all' && item.riskLevel !== level) return false;
      if (signal !== 'all' && !item.signals.includes(signal)) return false;
      if (!normalized) return true;
      return item.caseId.toLocaleLowerCase('ru-RU').includes(normalized) || visibleAccounts.some((account) =>
        String(account.bitrixUserId).includes(normalized) || (account.displayName ?? '').toLocaleLowerCase('ru-RU').includes(normalized)
        || (account.phoneMasked ?? '').toLocaleLowerCase('ru-RU').includes(normalized) || (account.emailMasked ?? '').toLocaleLowerCase('ru-RU').includes(normalized));
    });
  }, [cases, level, query, signal, showBlocked]);

  const toggleCase = (caseId: string) => setExpanded((current) => { const next = new Set(current); if (next.has(caseId)) next.delete(caseId); else next.add(caseId); return next; });
  const riskAccounts = accounts.filter((account) => (account.overallRisk > 0 || account.operatorWatched) && (showBlocked || isOperationalAccount(account)));
  const similarPhone = similar.filter((group) => group.matchType === 'phone').length;
  const similarEmail = similar.filter((group) => group.matchType === 'email').length;
  const busy = loading || refreshing || actingIds.size > 0;

  return (
    <div className="af-page">
      <header className="af-header">
        <div className="af-brand"><span className="af-brand-icon"><ShieldAlert size={22} /></span><div><strong>Anti-Fraud</strong><span>Евразия AI Bot</span></div></div>
        <div className="af-header-actions"><AntiFraudInvestigationButton disabled={busy} onAccepted={load} /><AntiFraudSettingsButton disabled={busy} /><button className="af-refresh" type="button" onClick={() => void refreshNow()} disabled={busy}><RefreshCw size={17} className={refreshing ? 'spin' : ''} /> {refreshing ? 'Обновляем…' : 'Обновить сейчас'}</button></div>
      </header>

      <main className="af-content">
        <section className="af-title-row"><div><h1>Подозрительная активность</h1><p>Кейсы объединяются по устройствам и совпадающим контактам. Поведенческие сигналы и высокий остаток бонусов усиливают риск конкретного аккаунта.</p></div><div className="af-updated"><Clock3 size={16} /> {summary?.updatedAt ? `Расчёт ${formatDate(summary.updatedAt)}` : 'Нет расчёта'}</div></section>
        {error ? <div className="af-error"><AlertTriangle size={19} />{error}</div> : null}

        <section className="af-summary-grid">
          <article><span>Требуют внимания</span><strong>{(summary?.criticalAccounts ?? 0) + (summary?.highAccounts ?? 0)}</strong><small>High + Critical · без заблокированных и неактивных</small></article>
          <article className="critical-card"><span>Критический риск</span><strong>{summary?.criticalAccounts ?? '—'}</strong><small>75–100 · без заблокированных и неактивных</small></article>
          <article className="high-card"><span>Высокий риск</span><strong>{summary?.highAccounts ?? '—'}</strong><small>50–74 · без заблокированных и неактивных</small></article>
          <article><span>Общие устройства</span><strong>{summary?.sharedDevices ?? '—'}</strong><small>{summary?.accountsOnSharedDevices ?? 0} активных незаблокированных аккаунтов</small></article>
          <article className="trusted-device-card"><span>Trusted Device</span><strong>{summary?.trustedDeviceHashes ?? '—'}</strong><small>уникальных накопленных идентификаторов</small></article>
        </section>

        <nav className="af-tabs" aria-label="Разделы Anti-Fraud">
          <button className={tab === 'cases' ? 'active' : ''} onClick={() => setTab('cases')}>Риски <span>{filteredCases.length}</span></button>
          <button className={tab === 'devices' ? 'active' : ''} onClick={() => setTab('devices')}>Устройства <span>{devices.length}</span></button>
          <button className={tab === 'accounts' ? 'active' : ''} onClick={() => setTab('accounts')}>Аккаунты <span>{riskAccounts.length}</span></button>
          <button className={tab === 'recommendations' ? 'active' : ''} onClick={() => setTab('recommendations')}>Рекомендации</button>
        </nav>

        {tab === 'cases' ? <>
          <section className="af-toolbar">
            <label className="af-search"><Search size={18} /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="ID, имя, телефон или email" /></label>
            <select value={level} onChange={(event) => setLevel(event.target.value as typeof level)}><option value="all">Все уровни</option><option value="critical">Critical</option><option value="high">High</option><option value="medium">Medium</option></select>
            <select value={signal} onChange={(event) => setSignal(event.target.value as typeof signal)}><option value="all">Все причины</option>{Object.entries(signalMeta).map(([key, meta]) => <option key={key} value={key}>{meta.label}</option>)}</select>
            <label className="af-blocked-toggle"><input type="checkbox" checked={showBlocked} onChange={(event) => setShowBlocked(event.target.checked)} /><span>Показать заблокированных и неактивных</span></label>
          </section>

          <section className="af-case-list">
            <div className="af-case-head"><span>Риск</span><span>Кейс</span><span>Причины</span><span>Аккаунты</span><span>Последний сигнал</span><span /></div>
            {filteredCases.map((item) => {
              const isExpanded = expanded.has(item.caseId);
              const visibleAccounts = showBlocked ? item.accounts : item.accounts.filter(isOperationalAccount);
              const hiddenExcluded = item.accounts.length - visibleAccounts.length;
              const primary = visibleAccounts[0] ?? item.accounts[0];
              const title = item.accountCount > 1 ? 'Группа аккаунтов' : (primary?.displayName || `Аккаунт ${primary?.bitrixUserId ?? ''}`);
              const dynamics = item.dynamics; const trend = dynamics ? trendMeta[dynamics.trend] : null;
              const hasNewAccounts = (dynamics?.addedAccountIds.length ?? 0) > 0;
              const blockable = item.accounts.filter(isOperationalAccount).map((account) => account.bitrixUserId);
              const caseTrustedDevices = item.trustedDevices ?? item.devices;
              return <article className={`af-case ${isExpanded ? 'expanded' : ''}`} key={item.caseId}>
                <button className="af-case-row" type="button" onClick={() => toggleCase(item.caseId)}>
                  <span className={`risk-pill ${item.riskLevel}`}>{item.overallRisk}<small>{levelLabel[item.riskLevel]}</small></span>
                  <span className="case-title"><span className="case-title-line"><strong>{title}</strong>{hasNewAccounts ? <em className="case-trend trend-new">● Новый</em> : null}{trend ? <em className={`case-trend trend-${dynamics?.trend}`}>{trend.symbol} {trend.label}</em> : null}</span><small>{item.caseId}{item.accountCount === 1 && primary ? ` · ID ${primary.bitrixUserId}` : ''}</small></span>
                  <span className="case-tags">{item.signals.map((itemSignal) => <em className={signalMeta[itemSignal].className} key={itemSignal}>{signalMeta[itemSignal].label}</em>)}</span>
                  <span className="case-count"><Users size={16} />{visibleAccounts.length}{hiddenExcluded > 0 ? <small>+{hiddenExcluded} скрыт.</small> : null}</span>
                  <span className="case-time">{formatDate(item.updatedAt)}</span><span>{isExpanded ? <ChevronDown size={20} /> : <ChevronRight size={20} />}</span>
                </button>

                {isExpanded ? <div className="af-case-details">
                  {dynamics ? <div className={`case-dynamics-card trend-${dynamics.trend}`}>
                    <div className="case-dynamics-head"><div><strong>{trend?.symbol} {trend?.label}</strong><span>{dynamics.trend === 'new' ? `Первое наблюдение: ${formatDate(dynamics.changedAt)}` : `Последнее изменение: ${formatDate(dynamics.changedAt)}`}</span></div><small>Risk остаётся в шкале 0–100; здесь показано, что изменилось в самом кейсе.</small></div>
                    <div className="case-dynamics-metrics"><span>{metricText('Risk', dynamics.metrics.risk)}</span><span>{metricText('Аккаунты', dynamics.metrics.accounts)}</span><span>{metricText('Общие устройства', dynamics.metrics.devices)}</span><span>{metricText('Признаки', dynamics.metrics.reasons)}</span></div>
                    {dynamics.addedAccountIds.length || dynamics.removedAccountIds.length || dynamics.addedReasonCodes.length || dynamics.removedReasonCodes.length ? (
                      <div className="case-dynamics-diff">
                        {dynamics.addedAccountIds.length ? <span><b>Добавлены аккаунты:</b> {dynamics.addedAccountIds.map((id) => `ID ${id}`).join(', ')}</span> : null}
                        {dynamics.removedAccountIds.length ? <span><b>Ушли аккаунты:</b> {dynamics.removedAccountIds.map((id) => `ID ${id}`).join(', ')}</span> : null}
                        {dynamics.addedReasonCodes.length ? <span><b>Добавлены признаки:</b> {dynamics.addedReasonCodes.map((code) => reasonLabels[code] ?? code).join(', ')}</span> : null}
                        {dynamics.removedReasonCodes.length ? <span><b>Исчезли признаки:</b> {dynamics.removedReasonCodes.map((code) => reasonLabels[code] ?? code).join(', ')}</span> : null}
                      </div>
                    ) : <div className="case-dynamics-diff"><span>{dynamics.trend === 'new' ? 'Сравнение появится после следующего расчёта Anti-Fraud.' : 'С момента последнего изменившегося состояния новых признаков не обнаружено.'}</span></div>}
                  </div> : null}

                  <div className="case-action-bar">
                    <div><strong>Бонусы группы: {formatPoints(item.groupBonusBalance)}</strong><span>данные {item.groupBonusKnownAccounts ?? 0} из {item.groupBonusTotalAccounts ?? item.accounts.length} · учитываются все аккаунты группы</span></div>
                    {blockable.length > 1 ? <button className="af-danger-button" type="button" disabled={busy} onClick={() => void runAccountAction('block', blockable, item.caseId)}><LockKeyhole size={16} /> Заблокировать активных ({blockable.length})</button> : null}
                  </div>

                  <div className="detail-column account-column"><h3>Аккаунты кейса</h3>
                    {visibleAccounts.map((account) => <div className={`account-card ${account.bitrixBlocked ? 'blocked' : ''}`} key={account.bitrixUserId}>
                      <div className="account-top"><div><div className="account-name-line"><strong>{account.displayName || 'Без имени'}{account.operatorInvestigationId ? <em className="operator-watch-badge">Проверка{account.operatorSource ? `: ${account.operatorSource}` : ''}</em> : account.operatorWatched ? <em className="operator-watch-badge">Наблюдение</em> : null}{dynamics?.addedAccountIds.includes(account.bitrixUserId) ? <em className="account-new-badge">Новый</em> : null}</strong><ConsentSnapshot account={account} /></div><span>ID {account.bitrixUserId} · <b className={`account-status ${account.bitrixBlocked ? 'blocked' : account.bitrixActive ? 'active' : 'inactive'}`}>{accountStatus(account)}</b></span></div><b className={account.riskLevel}>{account.overallRisk}</b></div>
                      <div className="account-contact"><span>{account.phoneMasked ?? 'телефон —'}</span><span>{account.emailMasked ?? 'email —'}</span><span>{loyaltyText(account)}</span></div>
                      <OperatorInvestigationSummary account={account} devices={caseTrustedDevices} identityMatches={item.identityMatches} />
                      {account.bitrixBlocked ? <div className="block-info"><strong>Дата блокировки: {account.blockedAt ? formatDate(account.blockedAt) : 'неизвестна'}</strong><span>{account.bitrixBlockReason || 'Основание блокировки не указано'}</span></div> : null}
                      <div className="risk-bars"><span>Устройства <b>{account.deviceRisk}</b></span><span>Связи <b>{account.linkedAccountRisk}</b></span><span>Контакты <b>{account.identitySimilarityRisk}</b></span><span>Посещения <b>{account.visitBehaviorRisk}</b></span><span>История/бонусы <b>{account.historicalBehaviorRisk}</b></span></div>
                      <div className="account-actions">{account.bitrixBlocked
                        ? <button className="af-unblock-button" type="button" disabled={busy || actingIds.has(account.bitrixUserId)} onClick={() => void runAccountAction('unblock', [account.bitrixUserId], item.caseId)}><UnlockKeyhole size={15} /> Разблокировать</button>
                        : <button className="af-danger-button compact" type="button" disabled={busy || actingIds.has(account.bitrixUserId)} onClick={() => void runAccountAction('block', [account.bitrixUserId], item.caseId)}><LockKeyhole size={15} /> Заблокировать</button>}
                      </div>
                      <div className="reason-list">{account.reasons.map((reason) => <div key={reason.code}><strong>{reasonLabels[reason.code] ?? reason.code}</strong><span>+{reason.score}</span><small>{parseDetails(reason.details).join(' · ')}</small></div>)}</div>
                    </div>)}
                  </div>

                  <div className="detail-column links-column"><h3>Устройства и связи</h3>
                    {caseTrustedDevices.map((device) => {
                      const shared = device.userIds.length > 1;
                      return <div className={`link-card ${shared ? '' : 'solo'}`} key={device.devicePrefix}><Smartphone size={18} /><div><strong>{shared ? 'Общее устройство' : 'Устройство'} {device.devicePrefix}…</strong><span>Аккаунтов на устройстве: {device.userIds.length}{device.userIds.length ? ` · ${device.userIds.map((id) => `ID ${id}`).join(' ↔ ')}` : ''}</span><small>Последняя активность: {formatDate(device.lastSeenAt)}</small></div></div>;
                    })}
                    {item.identityMatches.map((match, index) => <div className="link-card" key={`${match.type}-${index}`}>{match.type === 'phone' || match.type === 'similar_phone' ? <Link2 size={18} /> : <Mail size={18} />}<div><strong>{identityMatchLabel(match)}</strong><span>{match.userIds.map((id) => `ID ${id}`).join(' ↔ ')}</span></div></div>)}
                    {item.accountCount === 1 && item.signals.includes('visits') && caseTrustedDevices.length === 0 ? <div className="link-card solo"><Utensils size={18} /><div><strong>Trusted Device не найден</strong><span>У аккаунта нет синхронизированного Trusted Device; связующих признаков с другими аккаунтами не найдено.</span></div></div> : null}
                    {item.accountCount === 1 && item.signals.includes('bonus_balance') ? <div className="link-card solo"><AlertTriangle size={18} /><div><strong>Высокий остаток бонусов</strong><span>Баланс выше настроенного порога запускает проверку истории за 60 дней.</span></div></div> : null}
                  </div>
                </div> : null}
              </article>;
            })}
            {!loading && filteredCases.length === 0 ? <div className="af-empty">По выбранным фильтрам кейсов нет.</div> : null}
          </section>
        </> : null}

        {tab === 'devices' ? <section className="af-panel"><div className="panel-title"><Smartphone /><div><h2>Общие устройства</h2><p>Только устройства с двумя и более активными незаблокированными аккаунтами.</p></div></div><div className="simple-table"><div className="simple-head"><span>Устройство</span><span>Аккаунты</span><span>Тип</span><span>Последняя активность</span></div>{devices.map((device) => <div className="simple-row" key={device.devicePrefix}><strong>{device.devicePrefix}…</strong><span>{device.userIds.join(', ')}</span><span>{device.clientTypes.join(', ') || '—'}</span><span>{formatDate(device.lastSeenAt)}</span></div>)}</div></section> : null}

        {tab === 'accounts' ? <section className="af-panel"><div className="panel-title"><Fingerprint /><div><h2>Аккаунты с риском и наблюдением</h2><p>{showBlocked ? 'Показаны также заблокированные и неактивные аккаунты.' : 'Наблюдаемые аккаунты показываются даже при Risk 0; заблокированные и неактивные скрыты.'}</p></div></div><div className="simple-table accounts-table"><div className="simple-head"><span>Аккаунт</span><span>Risk</span><span>Устройства</span><span>Связи</span><span>Контакты</span><span>Посещения</span></div>{riskAccounts.map((account) => <div className="simple-row" key={account.bitrixUserId}><strong><span className="account-name-line"><span>{account.displayName || `ID ${account.bitrixUserId}`}{account.operatorInvestigationId ? <em className="operator-watch-badge">Проверка{account.operatorSource ? `: ${account.operatorSource}` : ''}</em> : account.operatorWatched ? <em className="operator-watch-badge">Наблюдение</em> : null}</span><ConsentSnapshot account={account} /></span><small>ID {account.bitrixUserId} · {accountStatus(account)} · {account.phoneMasked ?? 'телефон —'} · {account.emailMasked ?? 'email —'} · {loyaltyText(account)}</small></strong><span className={`score-text ${account.riskLevel}`}>{account.overallRisk}</span><span>{account.deviceRisk}</span><span>{account.linkedAccountRisk}</span><span>{account.identitySimilarityRisk}</span><span>{account.visitBehaviorRisk}</span></div>)}</div></section> : null}

        {tab === 'recommendations' ? <section className="af-panel recommendation-panel"><div className="panel-title"><ShieldAlert /><div><h2>Рекомендации</h2><p>Risk остаётся advisory: блокировка выполняется только вручную после проверки кейса.</p></div></div><div className="recommendation-grid"><article><strong>{summary?.historyGateAccounts ?? 0}</strong><span>активных незаблокированных аккаунтов достигли history gate</span></article><article><strong>{summary?.historyEnrichedAccounts ?? 0}</strong><span>обогащены 60-дневной историей</span></article><article><strong>{similarPhone}</strong><span>групп с совпадающим телефоном</span></article><article><strong>{similarEmail}</strong><span>групп с совпадающим email</span></article></div><div className="recommendation-note"><AlertTriangle size={20} /><div><strong>Ручное решение</strong><p>Перед блокировкой откройте кейс и проверьте связующие признаки, контакты, бонусы и поведенческие причины. Заблокированные и неактивные аккаунты по умолчанию скрыты, но доступны через переключатель.</p></div></div></section> : null}
      </main>
    </div>
  );
}
