import { type ReactNode, useMemo, useState } from 'react';
import { Link, Route, Switch, useLocation } from 'wouter';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import {
  AlertCircle,
  CheckCircle2,
  ClipboardList,
  Clock3,
  LockKeyhole,
  MapPin,
  PauseCircle,
  PlayCircle,
  RefreshCw,
  Search,
  ShieldCheck,
  X,
} from 'lucide-react';
import {
  useCreateSamzaberuRequest,
  useGetSamzaberuAccess,
  useGetSamzaberuSummary,
  useListRestaurants,
  useListSamzaberuRequests,
} from '@workspace/api-client-react';
import type { Restaurant, SamzaberuRequest } from '@workspace/api-client-react';

const operatorId = '2103479066';
const queryClient = new QueryClient();
const fallbackRestaurants: Restaurant[] = [];

type PendingAction = { action: 'STOP' | 'ENABLE'; restaurant: Restaurant };
type Duration = '30 минут' | '1 час' | '2 часа' | 'До конца дня' | 'Своя дата';

const durationOptions: Duration[] = ['30 минут', '1 час', '2 часа', 'До конца дня', 'Своя дата'];

const moscowParts = (date = new Date()) => {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Europe/Moscow', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(date);
  const value = (type: string) => Number(parts.find((item) => item.type === type)?.value);
  return { year: value('year'), month: value('month'), day: value('day'), hour: value('hour'), minute: value('minute') };
};

const moscowDateKey = (date = new Date()): string => {
  const parts = moscowParts(date);
  return `${parts.year}-${String(parts.month).padStart(2, '0')}-${String(parts.day).padStart(2, '0')}`;
};

const tomorrowMoscowDateKey = (): string => {
  const parts = moscowParts();
  const tomorrow = new Date(Date.UTC(parts.year, parts.month - 1, parts.day + 1));
  return `${tomorrow.getUTCFullYear()}-${String(tomorrow.getUTCMonth() + 1).padStart(2, '0')}-${String(tomorrow.getUTCDate()).padStart(2, '0')}`;
};

const nextMoscowQuarterHour = (): string | null => {
  const parts = moscowParts();
  const nextQuarter = (Math.floor(parts.minute / 15) + 1) * 15;
  if (parts.hour === 23 && nextQuarter >= 60) return null;
  const hour = parts.hour + Math.floor(nextQuarter / 60);
  const minute = nextQuarter % 60;
  return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
};

const fromMoscowLocal = (dateValue: string, timeValue: string): Date | null => {
  const dateMatch = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateValue);
  const timeMatch = /^(\d{2}):(\d{2})$/.exec(timeValue);
  if (!dateMatch || !timeMatch) return null;
  const [, year, month, day] = dateMatch.map(Number);
  const [, hour, minute] = timeMatch.map(Number);
  if (hour > 23 || minute > 59) return null;
  return new Date(Date.UTC(year, month - 1, day, hour - 3, minute, 0));
};

const endFor = (duration: Exclude<Duration, 'Своя дата'>): Date => {
  const base = new Date();
  if (duration === '30 минут') return new Date(base.getTime() + 30 * 60 * 1000);
  if (duration === '1 час') return new Date(base.getTime() + 60 * 60 * 1000);
  if (duration === '2 часа') return new Date(base.getTime() + 2 * 60 * 60 * 1000);
  const parts = moscowParts(base);
  const endOfDay = new Date(Date.UTC(parts.year, parts.month - 1, parts.day, 19, 0, 0));
  if (endOfDay <= base) endOfDay.setUTCDate(endOfDay.getUTCDate() + 1);
  return endOfDay;
};

const formatMoscow = (date: string | Date | null): string => {
  if (!date) return '—';
  return new Intl.DateTimeFormat('ru-RU', {
    timeZone: 'Europe/Moscow', day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  }).format(new Date(date));
};

const requestStatusLabel = (status: SamzaberuRequest['status']): string => {
  const labels: Record<string, string> = {
    REQUESTED: 'Зарегистрирован', PROCESSING: 'Выполняется', COMPLETED_AUTO: 'Выполнен',
    ESCALATED: 'Передан ответственному', COMPLETED_MANUAL: 'Выполнен вручную', CANCELLED: 'Отменён',
  };
  return labels[status] ?? status;
};

function AppShell({ children }: { children: ReactNode }) {
  const [location] = useLocation();
  const navigation = [
    { href: '/', label: 'СамЗаберу', icon: PlayCircle },
    { href: '/journal', label: 'Журнал', icon: ClipboardList },
    { href: '/access', label: 'Доступы', icon: ShieldCheck },
  ];
  return (
    <div className="app-shell">
      <header className="app-bar">
        <Link href="/" className="brand-link"><span className="brand-mark"><PlayCircle size={19} /></span><strong>СамЗаберу</strong></Link>
        <nav className="app-nav" aria-label="Навигация">
          {navigation.map((item) => {
            const Icon = item.icon;
            return <Link key={item.href} href={item.href} className={location === item.href ? 'active' : ''}><Icon size={18} /><span>{item.label}</span></Link>;
          })}
        </nav>
      </header>
      <main className="main-content">{children}</main>
    </div>
  );
}

function Overview() {
  const summary = useGetSamzaberuSummary({ operatorId });
  const restaurantsRequest = useListRestaurants({ operatorId });
  const access = useGetSamzaberuAccess();
  const createRequest = useCreateSamzaberuRequest();
  const restaurants = summary.data?.restaurants ?? restaurantsRequest.data ?? fallbackRestaurants;
  const currentAccess = access.data?.operators.find((item) => item.operatorId === operatorId);
  const [search, setSearch] = useState('');
  const [pending, setPending] = useState<PendingAction | null>(null);
  const [duration, setDuration] = useState<Duration>('До конца дня');
  const [targetUntil, setTargetUntil] = useState(() => endFor('До конца дня').toISOString());
  const [customDate, setCustomDate] = useState('');
  const [customTime, setCustomTime] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [notice, setNotice] = useState('');
  const [actionError, setActionError] = useState('');

  const visibleRestaurants = useMemo(() => {
    const normalized = search.trim().toLocaleLowerCase('ru-RU');
    if (!normalized) return restaurants;
    return restaurants.filter((restaurant) =>
      [restaurant.name, restaurant.shortName, restaurant.address].some((value) => value.toLocaleLowerCase('ru-RU').includes(normalized)),
    );
  }, [restaurants, search]);

  const openAction = (restaurant: Restaurant) => {
    setPending({ action: restaurant.isRunning ? 'STOP' : 'ENABLE', restaurant });
    setDuration('До конца дня');
    setTargetUntil(endFor('До конца дня').toISOString());
    setCustomDate(nextMoscowQuarterHour() ? moscowDateKey() : tomorrowMoscowDateKey());
    setCustomTime(''); setConfirmed(false); setActionError(''); setNotice('');
  };

  const closeAction = () => {
    if (createRequest.isPending) return;
    setPending(null); setConfirmed(false); setActionError('');
  };

  const selectDuration = (value: Duration) => {
    setDuration(value); setActionError('');
    if (value !== 'Своя дата') setTargetUntil(endFor(value).toISOString());
  };

  const customEndAt = fromMoscowLocal(customDate, customTime);
  const selectedEndAt = duration === 'Своя дата' ? customEndAt : new Date(targetUntil);
  const currentMoscowDate = moscowDateKey();
  const minimumTimeToday = nextMoscowQuarterHour();
  const refresh = async () => { await queryClient.invalidateQueries(); };

  const submitAction = async () => {
    if (!pending || !confirmed) return;
    const stopUntil = pending.action === 'STOP' ? selectedEndAt : null;
    if (pending.action === 'STOP' && (!stopUntil || stopUntil.getTime() <= Date.now())) {
      setActionError('Выберите будущую дату и время окончания.'); return;
    }
    if (duration === 'Своя дата') {
      const minute = Number(customTime.split(':')[1]);
      if (![0, 15, 30, 45].includes(minute)) { setActionError('Доступны минуты 00, 15, 30 или 45.'); return; }
    }
    try {
      const request = await createRequest.mutateAsync({ data: {
        action: pending.action, restaurantId: pending.restaurant.id, operatorId,
        targetUntil: stopUntil?.toISOString() ?? null, confirmation: true,
      } });
      const prefix = pending.action === 'STOP' ? 'Остановка' : 'Включение';
      setNotice(request.status === 'COMPLETED_AUTO' ? `${prefix} выполнено: ${request.restaurantName}` : `${prefix} зарегистрировано: ${request.message}`);
      setPending(null); setConfirmed(false); await refresh();
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Не удалось изменить статус ресторана.');
    }
  };

  const isLoading = summary.isLoading || restaurantsRequest.isLoading;
  const loadError = summary.error || restaurantsRequest.error;

  return (
    <AppShell>
      <div className="title-row">
        <div><h1>СамЗаберу</h1><p>Управление доступностью ресторанов</p></div>
        <button className="refresh-button" type="button" onClick={() => void refresh()} disabled={isLoading}><RefreshCw size={18} /><span>Обновить</span></button>
      </div>
      {loadError ? <div className="alert error" role="alert"><AlertCircle size={20} />Не удалось загрузить данные.</div> : null}
      {actionError && !pending ? <div className="alert error">{actionError}</div> : null}
      {notice ? <div className="alert success" role="status"><CheckCircle2 size={20} />{notice}</div> : null}

      <section className="summary-grid" aria-label="Сводка">
        <article className="summary-card"><span>Доступно</span><strong>{summary.data?.totalRestaurants ?? restaurants.length}</strong></article>
        <article className="summary-card running"><span>Работают</span><strong>{summary.data?.runningCount ?? restaurants.filter((item) => item.isRunning).length}</strong></article>
        <article className="summary-card stopped"><span>Остановлены</span><strong>{summary.data?.stoppedCount ?? restaurants.filter((item) => !item.isRunning).length}</strong></article>
        <article className="summary-card"><span>В обработке</span><strong>{summary.data?.pendingCount ?? 0}</strong></article>
      </section>

      <div className="operator-context"><strong>{summary.data?.operatorName ?? 'Операционный управляющий'}</strong><span>{currentAccess?.accessMode === 'assigned' ? 'Только закреплённые рестораны' : 'Все рестораны'}</span></div>
      <label className="search-field"><Search size={20} /><input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Найти ресторан или адрес" /></label>

      <section className="restaurant-section">
        <div className="section-heading"><h2>Рестораны</h2><span>{visibleRestaurants.length}</span></div>
        {isLoading && !restaurants.length ? <div className="empty-state">Загружаем рестораны…</div> : null}
        <div className="restaurant-grid">
          {visibleRestaurants.map((restaurant) => (
            <article className="restaurant-card" key={restaurant.id}>
              <div>
                <div className={restaurant.isRunning ? 'status-pill running' : 'status-pill stopped'}>
                  {restaurant.isRunning ? <PlayCircle size={18} /> : <PauseCircle size={18} />}<span>{restaurant.isRunning ? 'Работает' : 'Остановлен'}</span>
                </div>
                <h3>{restaurant.shortName}</h3><p>{restaurant.address}</p>
                {!restaurant.isRunning ? <div className="stop-until"><Clock3 size={17} />до {formatMoscow(restaurant.stopUntil)}</div> : null}
              </div>
              <button className={restaurant.isRunning ? 'restaurant-action stop' : 'restaurant-action enable'} type="button" onClick={() => openAction(restaurant)}>{restaurant.isRunning ? 'Остановить' : 'Включить'}</button>
            </article>
          ))}
        </div>
        {!isLoading && visibleRestaurants.length === 0 ? <div className="empty-state">По вашему запросу рестораны не найдены.</div> : null}
      </section>

      <section className="journal-preview">
        <div className="section-heading"><h2>Последние действия</h2></div>
        {summary.data?.recentRequests?.length ? <div className="journal-list">
          {summary.data.recentRequests.map((request) => <article className="journal-row" key={request.id}><div><strong>{request.restaurantName}</strong><span>{request.action === 'STOP' ? 'Остановка' : 'Включение'} · {formatMoscow(request.requestedAt)}</span></div><span className="request-status">{requestStatusLabel(request.status)}</span></article>)}
        </div> : <div className="empty-state">Действий пока нет.</div>}
      </section>

      {pending ? <div className="modal-backdrop" role="presentation" onMouseDown={closeAction}>
        <section className="action-modal" role="dialog" aria-modal="true" onMouseDown={(event) => event.stopPropagation()}>
          <button className="modal-close" type="button" onClick={closeAction} aria-label="Закрыть"><X size={23} /></button>
          <div className={pending.action === 'STOP' ? 'modal-icon stop' : 'modal-icon enable'}>{pending.action === 'STOP' ? <PauseCircle size={48} /> : <PlayCircle size={48} />}</div>
          <h2>{pending.action === 'STOP' ? 'Остановить СамЗаберу?' : 'Включить СамЗаберу?'}</h2>
          <strong className="modal-restaurant">{pending.restaurant.shortName}</strong><p className="modal-address">{pending.restaurant.address}</p>
          {pending.action === 'STOP' ? <div className="stop-timing">
            <span className="field-label">Остановить на</span>
            <div className="duration-grid">{durationOptions.map((value) => <button key={value} className={duration === value ? 'active' : ''} type="button" onClick={() => selectDuration(value)}>{value}</button>)}</div>
            {duration === 'Своя дата' ? <div className="date-time-fields">
              <label><span>Дата</span><input type="date" min={currentMoscowDate} value={customDate} onChange={(event) => { setCustomDate(event.target.value); setActionError(''); }} /></label>
              <label><span>Время (МСК)</span><input type="time" step="900" min={customDate === currentMoscowDate ? minimumTimeToday ?? undefined : undefined} value={customTime} onChange={(event) => { setCustomTime(event.target.value); setActionError(''); }} /></label>
            </div> : <p className="selected-until">До {formatMoscow(selectedEndAt)}</p>}
          </div> : null}
          {actionError ? <p className="modal-error" role="alert">{actionError}</p> : null}
          <label className="confirmation-box"><input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} /><span>Подтверждаю изменение статуса ресторана</span></label>
          <div className="modal-actions"><button className="cancel-button" type="button" onClick={closeAction} disabled={createRequest.isPending}>Отмена</button><button className={pending.action === 'STOP' ? 'confirm-button stop' : 'confirm-button enable'} type="button" onClick={() => void submitAction()} disabled={!confirmed || createRequest.isPending || (pending.action === 'STOP' && !selectedEndAt)}>{createRequest.isPending ? 'Выполняем…' : 'Подтвердить'}</button></div>
        </section>
      </div> : null}
    </AppShell>
  );
}

function Journal() {
  const requests = useListSamzaberuRequests({ operatorId });
  const rows = requests.data ?? [];
  return <AppShell><div className="title-row"><div><h1>Журнал действий</h1><p>История остановок и включений ресторанов</p></div></div>{rows.length ? <div className="journal-list page-card">{rows.map((request) => <article className="journal-row" key={request.id}><div><strong>{request.restaurantName}</strong><span>{request.message} · {formatMoscow(request.requestedAt)}</span></div><span className="request-status">{requestStatusLabel(request.status)}</span></article>)}</div> : <div className="empty-state">Действий пока нет.</div>}</AppShell>;
}

function Access() {
  const restaurants = useListRestaurants({ operatorId });
  const access = useGetSamzaberuAccess();
  return <AppShell><div className="title-row"><div><h1>Доступы</h1><p>Операционные управляющие и закреплённые рестораны</p></div></div><section className="access-grid">{(access.data?.operators ?? []).map((entry) => <article className="access-card" key={entry.operatorId}><LockKeyhole size={22} /><div><strong>{entry.operatorName}</strong><span>{entry.accessMode === 'full' ? 'Все рестораны' : 'Только закреплённые рестораны'}</span></div><b>{entry.restaurantCount}</b></article>)}</section><section className="restaurant-section"><div className="section-heading"><h2>Доступные рестораны</h2><span>{restaurants.data?.length ?? 0}</span></div><div className="restaurant-grid compact">{(restaurants.data ?? []).map((restaurant) => <article className="restaurant-card" key={restaurant.id}><div className="status-pill access"><MapPin size={17} /><span>{restaurant.shortName}</span></div><p>{restaurant.address}</p></article>)}</div></section></AppShell>;
}

function App() {
  return <QueryClientProvider client={queryClient}><Switch><Route path="/" component={Overview} /><Route path="/journal" component={Journal} /><Route path="/access" component={Access} /><Route><Overview /></Route></Switch></QueryClientProvider>;
}

export default App;
