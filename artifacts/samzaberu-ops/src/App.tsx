import { type ReactNode, useState } from 'react';
import { Link, Route, Switch, useLocation } from 'wouter';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import {
  AlertTriangle,
  ArrowLeft,
  CalendarClock,
  CheckCircle2,
  ChevronRight,
  ClipboardList,
  Clock3,
  LockKeyhole,
  MapPin,
  Menu,
  MessageSquareText,
  Play,
  Power,
  ShieldCheck,
  SquareTerminal,
  XCircle,
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

type Flow = 'menu' | 'restaurant' | 'duration' | 'confirmation' | 'custom' | 'enableConfirmation';
type Duration = '30 минут' | '1 час' | '2 часа' | 'До 22:00 (МСК)' | 'custom';

const moscowParts = (date = new Date()) => {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Europe/Moscow',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(date);
  const value = (type: string) => Number(parts.find((item) => item.type === type)?.value);
  return { year: value('year'), month: value('month'), day: value('day'), hour: value('hour'), minute: value('minute') };
};

const moscowDateKey = (date = new Date()): string => {
  const parts = moscowParts(date);
  return `${parts.year}-${String(parts.month).padStart(2, '0')}-${String(parts.day).padStart(2, '0')}`;
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

const nextMoscowQuarterHour = (): string | null => {
  const parts = moscowParts();
  const nextQuarter = (Math.floor(parts.minute / 15) + 1) * 15;
  if (parts.hour === 23 && nextQuarter >= 60) return null;
  const hour = parts.hour + Math.floor(nextQuarter / 60);
  const minute = nextQuarter % 60;
  return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
};

const tomorrowMoscowDateKey = (): string => {
  const parts = moscowParts();
  const tomorrow = new Date(Date.UTC(parts.year, parts.month - 1, parts.day + 1));
  return `${tomorrow.getUTCFullYear()}-${String(tomorrow.getUTCMonth() + 1).padStart(2, '0')}-${String(tomorrow.getUTCDate()).padStart(2, '0')}`;
};

const customDurationText = (until: Date): string => {
  const totalMinutes = Math.max(0, Math.floor((until.getTime() - Date.now()) / 60000));
  const days = Math.floor(totalMinutes / (24 * 60));
  const hours = Math.floor((totalMinutes % (24 * 60)) / 60);
  const minutes = totalMinutes % 60;
  const parts: string[] = [];
  if (days) parts.push(`${days} д.`);
  if (hours) parts.push(`${hours} ч.`);
  if (minutes || parts.length === 0) parts.push(`${minutes} мин.`);
  return parts.join(' ');
};

const formatMoscow = (date: string | Date | null): string => {
  if (!date) return '—';
  return new Intl.DateTimeFormat('ru-RU', {
    timeZone: 'Europe/Moscow',
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(date));
};

const endFor = (duration: Exclude<Duration, 'custom'>): Date => {
  const base = new Date();
  if (duration === '30 минут') return new Date(base.getTime() + 30 * 60 * 1000);
  if (duration === '1 час') return new Date(base.getTime() + 60 * 60 * 1000);
  if (duration === '2 часа') return new Date(base.getTime() + 2 * 60 * 60 * 1000);
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Europe/Moscow',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(base);
  const part = (type: string) => Number(parts.find((item) => item.type === type)?.value);
  const candidate = new Date(Date.UTC(part('year'), part('month') - 1, part('day'), 19, 0, 0));
  if (candidate <= base) candidate.setUTCDate(candidate.getUTCDate() + 1);
  return candidate;
};

const statusText = (restaurant: Restaurant): string =>
  restaurant.isRunning ? 'Работает' : `Остановлен до ${formatMoscow(restaurant.stopUntil)}`;

function AppShell({ children }: { children: ReactNode }) {
  const [location] = useLocation();
  const navigation = [
    { href: '/', label: 'Управление', icon: SquareTerminal },
    { href: '/journal', label: 'Журнал запросов', icon: ClipboardList },
    { href: '/access', label: 'Доступы', icon: ShieldCheck },
  ];

  return (
    <div className="app-shell">
      <aside className="side-rail">
        <Link href="/" className="brand-link" data-testid="link-home-brand">
          <span className="brand-mark" aria-hidden="true"><i /><i /><i /></span>
          <strong>Inside</strong>
        </Link>
        <nav className="side-nav" aria-label="Навигация">
          {navigation.map((item) => {
            const Icon = item.icon;
            const active = location === item.href;
            return (
              <Link
                href={item.href}
                className={`nav-link ${active ? 'active' : ''}`}
                data-testid={`link-${item.href === '/' ? 'overview' : item.href.slice(1)}`}
                key={item.href}
              >
                <span className="nav-icon"><Icon size={18} /></span>
                {item.label}
              </Link>
            );
          })}
        </nav>
        <div className="rail-note">
          <span className="rail-avatar">ПТ</span>
          <span><strong>Полный доступ</strong><small>59 ресторанов</small></span>
        </div>
      </aside>
      <main className="main-content">{children}</main>
    </div>
  );
}

function PageHeader({
  eyebrow,
  title,
  subtitle,
  quickAction,
}: {
  eyebrow: string;
  title: string;
  subtitle: string;
  quickAction?: ReactNode;
}) {
  return (
    <header className="page-header">
      <div>
        <p className="eyebrow">{eyebrow}</p>
        <h1>{title}</h1>
        <p className="page-subtitle">{subtitle}</p>
      </div>
      <div className="header-side">
        <div className="operator-chip" data-testid="status-operator">
          <span className="avatar">ПТ</span>
          <span>
            <strong>Приёмочный доступ</strong>
            <small>Telegram ID: {operatorId}</small>
          </span>
        </div>
        {quickAction}
      </div>
    </header>
  );
}

function Overview() {
  const summary = useGetSamzaberuSummary({ operatorId });
  const restaurantsRequest = useListRestaurants({ operatorId });
  const createRequest = useCreateSamzaberuRequest({
    mutation: {
      onSuccess: () => {
        void queryClient.invalidateQueries();
      },
    },
  });
  const restaurants = summary.data?.restaurants ?? restaurantsRequest.data ?? fallbackRestaurants;
  const [flow, setFlow] = useState<Flow>('menu');
  const [actionMode, setActionMode] = useState<'stop' | 'enable'>('stop');
  const [selectedRestaurant, setSelectedRestaurant] = useState<Restaurant | null>(null);
  const [duration, setDuration] = useState<Duration | null>(null);
  const [customDate, setCustomDate] = useState('');
  const [customTime, setCustomTime] = useState('');
  const [customError, setCustomError] = useState('');
  const [testNotice, setTestNotice] = useState('');

  const resetFlow = () => {
    setFlow('menu');
    setSelectedRestaurant(null);
    setDuration(null);
    setCustomDate('');
    setCustomTime('');
    setCustomError('');
  };

  const startStop = () => {
    setFlow('restaurant');
    setActionMode('stop');
    setDuration(null);
    setTestNotice('');
  };

  const startEnable = () => {
    setFlow('restaurant');
    setActionMode('enable');
    setDuration(null);
    setTestNotice('');
  };

  const selectRestaurant = (restaurant: Restaurant) => {
    setSelectedRestaurant(restaurant);
    setFlow(actionMode === 'enable' ? 'enableConfirmation' : 'duration');
  };

  const selectDuration = (value: Duration) => {
    setDuration(value);
    setFlow('confirmation');
  };

  const openCustomDateTime = () => {
    setDuration('custom');
    setCustomDate(nextMoscowQuarterHour() ? moscowDateKey() : tomorrowMoscowDateKey());
    setCustomTime('');
    setCustomError('');
    setFlow('custom');
  };

  const customEndAt = fromMoscowLocal(customDate, customTime);

  const continueWithCustomDateTime = () => {
    if (!customDate || !customTime || !customEndAt) {
      setCustomError('Выберите дату и время окончания.');
      return;
    }
    const [, minutes] = customTime.split(':').map(Number);
    if (![0, 15, 30, 45].includes(minutes)) {
      setCustomError('Для остановки доступны минуты 00, 15, 30 или 45.');
      return;
    }
    if (customEndAt.getTime() <= Date.now()) {
      setCustomError('Выберите будущую дату и время по Москве.');
      return;
    }
    setCustomError('');
    setFlow('confirmation');
  };

  const confirmTestStop = async () => {
    if (!selectedRestaurant || !duration) return;
    const endAt = duration === 'custom' ? customEndAt : endFor(duration);
    if (!endAt || endAt.getTime() <= Date.now()) {
      setCustomError('Выбранное время уже прошло. Укажите новый срок.');
      setFlow(duration === 'custom' ? 'custom' : 'duration');
      return;
    }
    try {
      const request = await createRequest.mutateAsync({
        data: {
          action: 'STOP',
          restaurantId: selectedRestaurant.id,
          operatorId,
          targetUntil: endAt.toISOString(),
          confirmation: true,
        },
      });
      if (request.status !== 'COMPLETED_AUTO') {
        setCustomError(`Остановка не подтверждена: ${request.status}.`);
        return;
      }
      setTestNotice(
        `Остановка применена в Bitrix: «${request.restaurantName}» приостановлен до ${formatMoscow(request.targetUntil)} МСК.`,
      );
      resetFlow();
    } catch (error) {
      setCustomError(error instanceof Error ? error.message : 'Не удалось остановить ресторан.');
    }
  };

  const confirmTestEnable = async () => {
    if (!selectedRestaurant) return;
    try {
      const request = await createRequest.mutateAsync({
        data: {
          action: 'ENABLE',
          restaurantId: selectedRestaurant.id,
          operatorId,
          targetUntil: null,
          confirmation: true,
        },
      });
      if (request.status !== 'COMPLETED_AUTO') {
        setCustomError(`Включение не подтверждено: ${request.status}.`);
        return;
      }
      setTestNotice(
        `Включение применено в Bitrix: «${request.restaurantName}». Ресторан снова работает.`,
      );
      resetFlow();
    } catch (error) {
      setCustomError(error instanceof Error ? error.message : 'Не удалось включить ресторан.');
    }
  };

  const editEnable = () => {
    setSelectedRestaurant(null);
    setFlow('restaurant');
  };

  const editStop = () => {
    setCustomError('');
    setFlow(duration === 'custom' ? 'custom' : 'duration');
  };

  const confirmationEndAt = duration === 'custom' ? customEndAt : duration ? endFor(duration) : null;
  const selectableRestaurants =
    actionMode === 'enable' ? restaurants.filter((restaurant) => !restaurant.isRunning) : restaurants;
  const currentMoscowDate = moscowDateKey();
  const minimumTimeToday = nextMoscowQuarterHour();
  const isTodayUnavailable = customDate === currentMoscowDate && !minimumTimeToday;

  const stopped = restaurants.filter((restaurant) => !restaurant.isRunning);
  const isLoading = summary.isLoading || restaurantsRequest.isLoading;

  return (
    <AppShell>
      <PageHeader
        eyebrow="INSIDE · ОПЕРАЦИИ"
        title="СамЗаберу"
        subtitle="Управление доступностью ресторанов. Полный доступ показывает весь справочник из 59 точек; ОУ видят только назначенные рестораны."
        quickAction={(
          <button className="mobile-quick-action" onClick={startStop} data-testid="button-quick-stop-samzaberu">
            <Power size={16} />
            <span>Остановить</span>
          </button>
        )}
      />

      <section className="stats-grid" aria-label="Сводка">
        <div className="stat-card" data-testid="status-restaurants-total">
          <span>Доступные рестораны</span>
          <strong>{summary.data?.totalRestaurants ?? restaurants.length}</strong>
          <small>Справочник доступных ресторанов</small>
        </div>
        <div className="stat-card success" data-testid="status-restaurants-running">
          <span>СамЗаберу работает</span>
          <strong>{summary.data?.runningCount ?? restaurants.filter((item) => item.isRunning).length}</strong>
          <small>Без действующего ограничения</small>
        </div>
        <div className="stat-card stop" data-testid="status-restaurants-stopped">
          <span>Приостановлен</span>
          <strong>{summary.data?.stoppedCount ?? stopped.length}</strong>
          <small>Есть действующее правило</small>
        </div>
      </section>

      <section className="workspace-grid">
        <div className="control-panel">
          <div className="panel-heading">
            <div>
              <p className="eyebrow">КНОПКИ МЕНЮ</p>
              <h2>{flow === 'menu' ? 'Что нужно сделать?' : actionMode === 'enable' ? 'Сценарий включения' : 'Сценарий остановки'}</h2>
            </div>
            {flow !== 'menu' ? (
              <button className="text-button" onClick={resetFlow} data-testid="button-reset-test-flow">
                <ArrowLeft size={16} />
                В меню
              </button>
            ) : null}
          </div>

          {testNotice ? <div className="test-success-banner" data-testid="text-test-stop-complete">{testNotice}</div> : null}

          {flow === 'menu' ? (
            <div className="action-stack">
              <button className="action-button stop-action" onClick={startStop} data-testid="button-stop-samzaberu">
                <span className="action-icon"><Power size={20} /></span>
                <span><strong>⛔ Остановить СамЗаберу</strong><small>Выбор ресторана и срока остановки</small></span>
                <ChevronRight size={20} />
              </button>
              <button
                className="action-button start-action"
                onClick={startEnable}
                data-testid="button-enable-samzaberu"
              >
                <span className="action-icon"><Play size={20} /></span>
                <span><strong>✅ Включить СамЗаберу</strong><small>Изменение персонального правила в Bitrix</small></span>
                <ChevronRight size={20} />
              </button>
              <button
                className="action-button neutral-action"
                onClick={() => document.getElementById('current-status')?.scrollIntoView({ behavior: 'smooth' })}
                data-testid="button-current-status"
              >
                <span className="action-icon"><ClipboardList size={20} /></span>
                <span><strong>📋 Текущий статус</strong><small>Доступные рестораны и активные ограничения</small></span>
                <ChevronRight size={20} />
              </button>
            </div>
          ) : null}

          {flow === 'restaurant' ? (
            <div className="flow-block">
              <div className="step-label"><span>1</span> {actionMode === 'enable' ? 'Выберите ресторан для включения' : 'Выберите ресторан'}</div>
              <p>
                {actionMode === 'enable'
                  ? 'Показаны только рестораны с действующей приостановкой.'
                  : 'После подтверждения Bitrix создаст новое персональное правило или обновит существующее.'}
              </p>
              {selectableRestaurants.length === 0 ? (
                <div className="empty-state compact-empty" data-testid="text-no-stopped-restaurants">
                  <CheckCircle2 size={26} />
                  <h3>Нет ресторанов для включения</h3>
                  <p>Сейчас все доступные рестораны работают.</p>
                </div>
              ) : null}
              <div className="restaurant-choice-list">
                {selectableRestaurants.map((restaurant) => (
                  <button
                    key={restaurant.id}
                    className="restaurant-choice"
                    onClick={() => selectRestaurant(restaurant)}
                    data-testid={`button-select-restaurant-${restaurant.id}`}
                  >
                    <span><strong>{restaurant.shortName}</strong><small>{restaurant.address}</small></span>
                    <ChevronRight size={18} />
                  </button>
                ))}
              </div>
            </div>
          ) : null}

          {flow === 'duration' && selectedRestaurant ? (
            <div className="flow-block">
              <div className="step-label"><span>2</span> Выберите срок</div>
              <p><strong>{selectedRestaurant.shortName}</strong> · {selectedRestaurant.address}</p>
              <div className="duration-grid">
                {(['30 минут', '1 час', '2 часа', 'До 22:00 (МСК)'] as Duration[]).map((value) => (
                  <button key={value} onClick={() => selectDuration(value)} data-testid={`button-duration-${value}`}>
                    <Clock3 size={18} />{value}
                  </button>
                ))}
                <button className="custom-duration" onClick={openCustomDateTime} data-testid="button-custom-date-time">
                  <CalendarClock size={18} />Выбрать дату и время
                </button>
              </div>
            </div>
          ) : null}

          {flow === 'custom' && selectedRestaurant ? (
            <div className="flow-block custom-date-time-panel" data-testid="panel-custom-date-time">
              <div className="step-label"><span>3</span> Дата и время окончания</div>
              <p><strong>{selectedRestaurant.shortName}</strong> · Укажите срок по часовому поясу Europe/Moscow.</p>
              <div className="date-time-fields">
                <label>
                  <span>Дата</span>
                  <input
                    type="date"
                    min={currentMoscowDate}
                    value={customDate}
                    onChange={(event) => {
                      setCustomDate(event.target.value);
                      setCustomError('');
                    }}
                    data-testid="input-custom-stop-date"
                  />
                </label>
                <label>
                  <span>Время (МСК)</span>
                  <input
                    type="time"
                    step="900"
                    min={customDate === currentMoscowDate ? minimumTimeToday ?? undefined : undefined}
                    disabled={isTodayUnavailable}
                    value={customTime}
                    onChange={(event) => {
                      setCustomTime(event.target.value);
                      setCustomError('');
                    }}
                    data-testid="input-custom-stop-time"
                  />
                </label>
              </div>
              <p className="form-hint">{isTodayUnavailable ? 'На сегодня подходящих интервалов уже нет — выберите следующий день.' : 'Доступны будущие дата и время; минуты — 00, 15, 30 или 45.'}</p>
              {customError ? <p className="form-error" role="alert">{customError}</p> : null}
              <div className="form-actions">
                <button className="secondary-button" onClick={() => setFlow('duration')} data-testid="button-back-to-durations">Назад</button>
                <button className="primary-button" onClick={continueWithCustomDateTime} data-testid="button-continue-custom-date-time">
                  Продолжить <ChevronRight size={16} />
                </button>
              </div>
            </div>
          ) : null}

          {flow === 'enableConfirmation' && selectedRestaurant ? (
            <div className="flow-block confirmation" data-testid="panel-enable-confirmation">
              <div className="step-label"><span>2</span> Подтверждение включения</div>
              <div className="confirmation-card">
                <span>Ресторан</span>
                <strong>{selectedRestaurant.name}</strong>
                <span>Действие</span>
                <strong>Включить СамЗаберу</strong>
              </div>
              <p>После подтверждения персональные правила остановки ресторана будут отключены в Bitrix. Групповые правила не изменяются.</p>
              <div className="form-actions">
                <button className="secondary-button" onClick={editEnable} data-testid="button-edit-test-enable">Изменить</button>
                <button className="secondary-button" onClick={resetFlow} data-testid="button-cancel-test-enable">Отмена</button>
                <button className="primary-button" onClick={() => void confirmTestEnable()} disabled={createRequest.isPending} data-testid="button-confirm-test-enable">
                  <CheckCircle2 size={18} />Подтвердить включение
                </button>
              </div>
            </div>
          ) : null}

          {flow === 'confirmation' && selectedRestaurant && duration && confirmationEndAt ? (
            <div className="flow-block confirmation" data-testid="panel-confirmation">
              <div className="step-label"><span>4</span> Подтверждение</div>
              <div className="confirmation-card">
                <span>Ресторан</span>
                <strong>{selectedRestaurant.name}</strong>
                <span>Окончание остановки</span>
                <strong>{formatMoscow(confirmationEndAt)} (МСК)</strong>
                {duration === 'custom' ? <><span>Продолжительность</span><strong>{customDurationText(confirmationEndAt)}</strong></> : null}
              </div>
              <p>После подтверждения правило остановки будет создано или обновлено в Bitrix.</p>
              {customError ? <p className="form-error" role="alert">{customError}</p> : null}
              <div className="form-actions">
                <button className="secondary-button" onClick={editStop} data-testid="button-edit-test-stop">Изменить</button>
                <button className="secondary-button" onClick={resetFlow} data-testid="button-cancel-test-stop">Отмена</button>
                <button className="primary-button" onClick={confirmTestStop} data-testid="button-confirm-test-stop">
                  <CheckCircle2 size={18} />Подтвердить остановку
                </button>
              </div>
            </div>
          ) : null}
        </div>

        <aside className="bot-note">
          <div className="bot-note-icon"><MessageSquareText size={21} /></div>
          <p className="eyebrow">TELEGRAM BOT</p>
          <h2>Бот подключён к секрету окружения</h2>
          <ul>
            <li><CheckCircle2 size={16} />/start показывает кнопочное меню</li>
            <li><CheckCircle2 size={16} />/id сообщает Telegram user ID</li>
            <li><CheckCircle2 size={16} />Свободный текст направляется к кнопкам</li>
          </ul>
          <div className="integration-banner"><ShieldCheck size={16} />Реальный Bitrix API подключён</div>
        </aside>
      </section>

      {!(flow === 'restaurant' && actionMode === 'enable') ? <section className="status-section" id="current-status">
        <div className="section-heading">
          <div>
            <p className="eyebrow">ТЕКУЩИЙ СТАТУС</p>
            <h2>Рестораны операционного управляющего</h2>
          </div>
          {isLoading ? <span className="loading-label">Обновляем данные…</span> : <span className="source-label">Источник: журнал операций бота</span>}
        </div>
        <div className="restaurant-table">
          {restaurants.map((restaurant) => (
            <article className="restaurant-row" key={restaurant.id} data-testid={`card-restaurant-${restaurant.id}`}>
              <span className={`status-orb ${restaurant.isRunning ? 'running' : 'stopped'}`} />
              <div className="restaurant-meta">
                <strong>{restaurant.shortName}</strong>
                <span><MapPin size={14} />{restaurant.address}</span>
              </div>
              <div className={`restaurant-state ${restaurant.isRunning ? 'good' : 'paused'}`} data-testid={`status-restaurant-${restaurant.id}`}>
                {restaurant.isRunning ? <CheckCircle2 size={16} /> : <XCircle size={16} />}
                {statusText(restaurant)}
              </div>
            </article>
          ))}
        </div>
      </section> : null}
    </AppShell>
  );
}

function Journal() {
  const requests = useListSamzaberuRequests({ operatorId });
  const rows = requests.data ?? [];
  return (
    <AppShell>
      <PageHeader eyebrow="АУДИТ" title="Журнал запросов" subtitle="Цепочка состояний и будущие аварийные эскалации." />
      <section className="journal-card">
        <div className="journal-head"><ClipboardList size={19} /><span>Запросы текущего операционного управляющего</span></div>
        {rows.length === 0 ? (
          <div className="empty-state journal-empty" data-testid="text-empty-request-journal">
            <ClipboardList size={28} />
            <h3>Запросов ещё нет</h3>
            <p>После первого действия здесь появятся REQUESTED, PROCESSING, COMPLETED_AUTO, ESCALATED и COMPLETED_MANUAL.</p>
          </div>
        ) : (
          rows.map((request: SamzaberuRequest) => (
            <div className="journal-row" key={request.id} data-testid={`row-request-${request.id}`}>
              <span className={`request-status ${request.status.toLowerCase()}`}>{request.status}</span>
              <div><strong>{request.restaurantName}</strong><small>{request.message}</small></div>
              <time>{formatMoscow(request.requestedAt)}</time>
            </div>
          ))
        )}
      </section>
    </AppShell>
  );
}

function Access() {
  const restaurants = useListRestaurants({ operatorId });
  const access = useGetSamzaberuAccess();
  const items = restaurants.data ?? fallbackRestaurants;
  return (
    <AppShell>
      <PageHeader eyebrow="INSIDE · ДОСТУПЫ" title="Закреплённые рестораны" subtitle="Полный доступ видит весь справочник; персональный доступ ОУ — только назначенные рестораны." />
      <section className="access-assignments" data-testid="section-configured-access">
        <div className="section-heading">
          <div>
            <p className="eyebrow">НАСТРОЙКА ДОСТУПОВ</p>
            <h2>Операционные управляющие</h2>
          </div>
          <span className="source-label">Telegram ID не отображается</span>
        </div>
        <div className="access-assignment-list">
          {(access.data?.operators ?? []).map((entry) => (
            <article className="access-assignment-row" key={entry.operatorId} data-testid={`access-operator-${entry.operatorId}`}>
              <div>
                <strong>{entry.operatorName}</strong>
                <small>{entry.accessMode === 'full' ? 'Полный приёмочный доступ' : 'Доступ только к назначенным ресторанам'}</small>
              </div>
              <span className={entry.configured ? 'access-configured' : 'access-pending'}>
                {entry.configured ? 'Настроен' : 'Не настроен'}
              </span>
              <strong className="access-count">{entry.restaurantCount} ресторанов</strong>
            </article>
          ))}
        </div>
      </section>
      <section className="access-grid">
        <div className="access-card">
          <LockKeyhole size={20} />
          <h2>Только ОУ</h2>
          <p>Директора не получают доступ к управлению. Бот выдаёт меню только Telegram user ID из разрешённого списка.</p>
        </div>
        <div className="access-card">
          <Menu size={20} />
          <h2>Кнопочный сценарий</h2>
          <p>Свободный текст не интерпретируется. Для любых действий используются пункты меню и кнопки выбора.</p>
        </div>
        <div className="access-card">
          <AlertTriangle size={20} />
          <h2>Аварийный маршрут</h2>
          <p>При невозможности подтвердить действие запрос будет готов к отправке ответственному и в рабочую группу.</p>
        </div>
      </section>
      <section className="status-section compact">
        <div className="section-heading"><div><p className="eyebrow">СПРАВОЧНИК</p><h2>59 ресторанов и назначенные ОУ</h2></div><span className="source-label">Полный доступ ко всем точкам</span></div>
        <div className="restaurant-table">
          {items.map((restaurant) => (
            <article className="restaurant-row" key={restaurant.id} data-testid={`card-access-restaurant-${restaurant.id}`}>
              <span className="status-orb access" />
              <div className="restaurant-meta"><strong>{restaurant.shortName}</strong><span><MapPin size={14} />{restaurant.address}</span></div>
              <span className="source-label">{restaurant.operatorName}</span>
            </article>
          ))}
        </div>
      </section>
    </AppShell>
  );
}

function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <Switch>
        <Route path="/" component={Overview} />
        <Route path="/journal" component={Journal} />
        <Route path="/access" component={Access} />
        <Route>
          <Overview />
        </Route>
      </Switch>
    </QueryClientProvider>
  );
}

export default App;