import { useEffect, useMemo, useState } from 'react';
import { Building2, Download, History, Pencil, PlayCircle, RefreshCw, Search, Smartphone, UserRound, X } from 'lucide-react';
import { DIRECTORY_OU_ORDER, directoryRestaurantSeed, type DirectoryRestaurantSeed } from '../data/directory-preview-data';
import './directory-preview.css';

type CorporatePhone = {
  id: string;
  phone: string;
  operator: 'MEGAFON' | 'T2';
  legalEntity: string;
  inn: string | null;
  accountNumber: string | null;
  restaurantName: string | null;
  lineType: string | null;
  subscriberName: string | null;
};

type PhoneMode = 'AUTO' | 'PERSONAL' | 'NONE';
type EditableRestaurant = DirectoryRestaurantSeed & {
  actualPhoneMode: PhoneMode;
  actualPersonalPhone: string;
  generalPhoneMode: PhoneMode;
  generalPersonalPhone: string;
};
type MatchResult = { status: 'FOUND'; record: CorporatePhone } | { status: 'AMBIGUOUS' } | { status: 'NONE' };
type Tab = 'restaurants' | 'megafon' | 't2';
type HistoryEntry = { id: string; at: Date; restaurant: string; text: string };

const clean = (value: string | null | undefined): string =>
  (value ?? '').toLocaleLowerCase('ru-RU').replace(/ё/g, 'е').replace(/[«»"'().,;:\-\/\\]/g, ' ').replace(/\s+/g, ' ').trim();

const compact = (value: string | null | undefined): string =>
  clean(value).replace(/\bооо\b/g, '').replace(/\bзао\b/g, '').replace(/\bпао\b/g, '').replace(/\bао\b/g, '').replace(/\s+/g, ' ').trim();

const formatPhone = (phone: string): string => {
  const digits = phone.replace(/\D/g, '');
  const normalized = digits.length === 11 && digits.startsWith('8') ? `7${digits.slice(1)}` : digits;
  if (normalized.length !== 11 || !normalized.startsWith('7')) return phone;
  return `+7 ${normalized.slice(1, 4)} ${normalized.slice(4, 7)}-${normalized.slice(7, 9)}-${normalized.slice(9, 11)}`;
};

const nameParts = (value: string): string[] => clean(value).split(' ').filter(Boolean);

const employeeScore = (target: string, candidate: string): number => {
  const a = nameParts(target);
  const b = nameParts(candidate);
  if (!a.length || !b.length || a[0] !== b[0]) return 0;
  if (clean(target) === clean(candidate)) return 100;
  if (a.length >= 2 && b.length >= 2 && a[1] === b[1]) {
    if (a.length >= 3 && b.length >= 3 && a[2] === b[2]) return 95;
    return 85;
  }
  if (a.length >= 2 && b.length >= 2 && a[1][0] === b[1][0]) {
    if (a.length >= 3 && b.length >= 3 && a[2][0] === b[2][0]) return 78;
    return 72;
  }
  return 55;
};

const findEmployee = (name: string, phones: CorporatePhone[]): MatchResult => {
  const scored = phones
    .filter((item) => item.operator === 'T2' && item.subscriberName)
    .map((record) => ({ record, score: employeeScore(name, record.subscriberName ?? '') }))
    .filter((item) => item.score >= 72)
    .sort((a, b) => b.score - a.score);
  if (!scored.length) return { status: 'NONE' };
  const top = scored[0].score;
  const topRows = scored.filter((item) => item.score === top);
  if (new Set(topRows.map((item) => item.record.phone)).size > 1) return { status: 'AMBIGUOUS' };
  return { status: 'FOUND', record: topRows[0].record };
};

const addressScore = (address: string, value: string | null): number => {
  const a = clean(address);
  const b = clean(value);
  if (!a || !b) return 0;
  if (a === b) return 100;
  if (a.includes(b) || b.includes(a)) return 88;
  const aNums = a.match(/\d+/g) ?? [];
  const bNums = b.match(/\d+/g) ?? [];
  const sameNumber = aNums.some((n) => bNums.includes(n));
  const words = a.split(' ').filter((word) => word.length > 4 && !/^\d+$/.test(word));
  const overlap = words.filter((word) => b.includes(word)).length;
  return (sameNumber ? 40 : 0) + Math.min(overlap * 12, 48);
};

const sameLegalEntity = (a: string, b: string): boolean => {
  const left = compact(a);
  const right = compact(b);
  return Boolean(left && right && (left === right || left.includes(right) || right.includes(left)));
};

const findRestaurantPhone = (restaurant: DirectoryRestaurantSeed, phones: CorporatePhone[]): MatchResult => {
  const megafon = phones.filter((item) => item.operator === 'MEGAFON' && sameLegalEntity(item.legalEntity, restaurant.legalEntity));
  const preferred = megafon.filter((item) => clean(item.lineType).includes('входящ') || clean(item.lineType).includes('ресторан'));
  const pool = preferred.length ? preferred : megafon;
  const scored = pool.map((record) => ({ record, score: addressScore(restaurant.address, record.restaurantName) })).sort((a, b) => b.score - a.score);
  if (scored.length && scored[0].score >= 40) {
    const best = scored[0].score;
    const bestRows = scored.filter((item) => item.score === best);
    if (new Set(bestRows.map((item) => item.record.phone)).size === 1) return { status: 'FOUND', record: bestRows[0].record };
    return { status: 'AMBIGUOUS' };
  }
  if (pool.length === 1) return { status: 'FOUND', record: pool[0] };
  if (pool.length > 1) return { status: 'AMBIGUOUS' };

  const t2 = phones.filter((item) => {
    if (item.operator !== 'T2' || !sameLegalEntity(item.legalEntity, restaurant.legalEntity)) return false;
    const type = clean(item.lineType);
    return type.includes('город') || type.includes('федерал') || type.includes('ресторан');
  });
  if (t2.length === 1) return { status: 'FOUND', record: t2[0] };
  if (t2.length > 1) return { status: 'AMBIGUOUS' };
  return { status: 'NONE' };
};

const matchLabel = (match: MatchResult, mode: PhoneMode, personal: string) => {
  if (mode === 'PERSONAL') return { phone: personal, label: 'Личный', kind: 'personal' };
  if (mode === 'NONE') return { phone: '', label: 'Не указан', kind: 'none' };
  if (match.status === 'FOUND') return { phone: match.record.phone, label: 'Корп.', kind: 'corporate' };
  if (match.status === 'AMBIGUOUS') return { phone: '', label: 'Требуется выбор', kind: 'warning' };
  return { phone: '', label: 'Не указан', kind: 'none' };
};

function PhoneCell({ value }: { value: ReturnType<typeof matchLabel> }) {
  return <div className="directory-phone"><span>{value.phone ? formatPhone(value.phone) : '—'}</span><b className={`directory-badge ${value.kind}`}>{value.label}</b></div>;
}

function RestaurantEditor({ restaurant, onClose, onSave }: { restaurant: EditableRestaurant; onClose: () => void; onSave: (next: EditableRestaurant) => void }) {
  const [draft, setDraft] = useState(restaurant);
  const update = <K extends keyof EditableRestaurant,>(key: K, value: EditableRestaurant[K]) => setDraft((prev) => ({ ...prev, [key]: value }));
  return <div className="directory-modal-backdrop" onMouseDown={onClose}>
    <section className="directory-editor" onMouseDown={(event) => event.stopPropagation()}>
      <button className="directory-modal-close" type="button" onClick={onClose} aria-label="Закрыть"><X size={22} /></button>
      <h2>Редактирование ресторана</h2><p>{draft.address}</p>
      <div className="directory-form-grid">
        <label><span>ОУ</span><select value={draft.ou} onChange={(e) => update('ou', e.target.value)}>{DIRECTORY_OU_ORDER.map((ou) => <option key={ou}>{ou}</option>)}</select></label>
        <label className="wide"><span>Юридическое лицо</span><input value={draft.legalEntity} onChange={(e) => update('legalEntity', e.target.value)} /></label>
        <label className="wide"><span>Адрес</span><input value={draft.address} onChange={(e) => update('address', e.target.value)} /></label>
        <label className="wide"><span>Фактический директор</span><input value={draft.actualDirector} onChange={(e) => update('actualDirector', e.target.value)} /></label>
        <label><span>Тип номера</span><select value={draft.actualPhoneMode} onChange={(e) => update('actualPhoneMode', e.target.value as PhoneMode)}><option value="AUTO">Корпоративный / авто</option><option value="PERSONAL">Личный</option><option value="NONE">Не указан</option></select></label>
        <label><span>Личный номер</span><input disabled={draft.actualPhoneMode !== 'PERSONAL'} value={draft.actualPersonalPhone} onChange={(e) => update('actualPersonalPhone', e.target.value)} placeholder="+7 ..." /></label>
        <label className="wide"><span>Генеральный директор</span><input value={draft.generalDirector} onChange={(e) => update('generalDirector', e.target.value)} /></label>
        <label><span>Тип номера</span><select value={draft.generalPhoneMode} onChange={(e) => update('generalPhoneMode', e.target.value as PhoneMode)}><option value="AUTO">Корпоративный / авто</option><option value="PERSONAL">Личный</option><option value="NONE">Не указан</option></select></label>
        <label><span>Личный номер</span><input disabled={draft.generalPhoneMode !== 'PERSONAL'} value={draft.generalPersonalPhone} onChange={(e) => update('generalPersonalPhone', e.target.value)} placeholder="+7 ..." /></label>
        <label className="wide"><span>Корпоративная почта</span><input value={draft.email} onChange={(e) => update('email', e.target.value)} /></label>
      </div>
      <div className="directory-editor-actions"><button type="button" onClick={onClose}>Отмена</button><button className="primary" type="button" onClick={() => onSave(draft)}>Применить в preview</button></div>
    </section>
  </div>;
}

export function DirectoryPreview() {
  const [tab, setTab] = useState<Tab>('restaurants');
  const [phones, setPhones] = useState<CorporatePhone[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [ouFilter, setOuFilter] = useState('ALL');
  const [editorId, setEditorId] = useState<string | null>(null);
  const [history, setHistory] = useState<HistoryEntry[]>([]);
  const [restaurants, setRestaurants] = useState<EditableRestaurant[]>(() => directoryRestaurantSeed.map((item) => ({ ...item, actualPhoneMode: 'AUTO', actualPersonalPhone: '', generalPhoneMode: 'AUTO', generalPersonalPhone: '' })));

  const loadPhones = async () => {
    setLoading(true); setError('');
    try {
      const response = await fetch('/api/directory/phones');
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json() as { records: CorporatePhone[] };
      setPhones(data.records ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Не удалось загрузить корпоративный справочник');
    } finally { setLoading(false); }
  };

  useEffect(() => { void loadPhones(); }, []);

  const filteredRestaurants = useMemo(() => {
    const needle = clean(search);
    return restaurants.filter((row) => {
      if (ouFilter !== 'ALL' && row.ou !== ouFilter) return false;
      if (!needle) return true;
      return [row.legalEntity, row.address, row.actualDirector, row.generalDirector, row.email, row.ou].some((value) => clean(value).includes(needle));
    });
  }, [restaurants, search, ouFilter]);

  const phoneRows = useMemo(() => {
    const operator = tab === 'megafon' ? 'MEGAFON' : 'T2';
    const needle = clean(search);
    return phones.filter((row) => row.operator === operator && (!needle || [row.phone, row.legalEntity, row.subscriberName, row.restaurantName, row.accountNumber, row.inn].some((value) => clean(value).includes(needle))));
  }, [phones, search, tab]);

  const grouped = DIRECTORY_OU_ORDER.map((ou) => ({ ou, rows: filteredRestaurants.filter((row) => row.ou === ou) }));
  const editor = editorId ? restaurants.find((row) => row.id === editorId) ?? null : null;

  const savePreview = (next: EditableRestaurant) => {
    const before = restaurants.find((row) => row.id === next.id);
    setRestaurants((rows) => rows.map((row) => row.id === next.id ? next : row));
    const changes: string[] = [];
    if (before?.ou !== next.ou) changes.push(`ОУ ${before?.ou} → ${next.ou}`);
    if (before?.actualPhoneMode !== next.actualPhoneMode || before?.actualPersonalPhone !== next.actualPersonalPhone) changes.push('номер фактического директора');
    if (before?.generalPhoneMode !== next.generalPhoneMode || before?.generalPersonalPhone !== next.generalPersonalPhone) changes.push('номер генерального директора');
    if (before?.actualDirector !== next.actualDirector) changes.push('фактический директор');
    if (before?.generalDirector !== next.generalDirector) changes.push('генеральный директор');
    if (before?.legalEntity !== next.legalEntity) changes.push('юридическое лицо');
    if (before?.address !== next.address) changes.push('адрес');
    if (before?.email !== next.email) changes.push('почта');
    setHistory((items) => [{ id: `${Date.now()}-${next.id}`, at: new Date(), restaurant: next.address, text: changes.length ? changes.join(' · ') : 'карточка изменена' }, ...items].slice(0, 20));
    setEditorId(null);
  };

  return <div className="directory-preview-shell">
    <header className="directory-preview-bar">
      <a className="directory-preview-brand" href="/"><span><PlayCircle size={19} /></span><strong>Евразия · IT</strong></a>
      <nav className="directory-preview-nav"><a href="/"><PlayCircle size={18} />СамЗаберу</a><a className="active" href="/directory"><Building2 size={18} />Справочник</a></nav>
    </header>
    <main className="directory-page">
      <div className="directory-title-row">
        <div><h1>Справочник ресторанов</h1><p>v1.6.9 · интерактивный preview на реальных данных</p></div>
        <div className="directory-top-actions"><button className="directory-refresh" type="button" onClick={() => void loadPhones()} disabled={loading}><RefreshCw size={18} />Обновить</button><button className="directory-export" type="button" disabled title="В полной v1.6.9 будет XLSX из актуальной БД"><Download size={18} />Выгрузить в Excel</button></div>
      </div>

      <div className="directory-preview-banner"><b>Безопасный preview:</b> корпоративные номера загружаются из PostgreSQL. Изменения ОУ, ФИО и личных номеров сейчас живут только до обновления страницы и не меняют production-БД.</div>
      {error ? <div className="directory-error">Не удалось получить корпоративный справочник: {error}</div> : null}

      <div className="directory-tabs">
        <button className={tab === 'restaurants' ? 'active' : ''} onClick={() => setTab('restaurants')}><Building2 size={18} />Рестораны и директора</button>
        <button className={tab === 'megafon' ? 'active' : ''} onClick={() => setTab('megafon')}><Smartphone size={18} />МегаФон</button>
        <button className={tab === 't2' ? 'active' : ''} onClick={() => setTab('t2')}><Smartphone size={18} />T2</button>
      </div>

      <div className="directory-toolbar">
        <label className="directory-search"><Search size={19} /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={tab === 'restaurants' ? 'Адрес, ООО, директор, почта…' : 'Номер, ООО, абонент, ресторан…'} /></label>
        {tab === 'restaurants' ? <select value={ouFilter} onChange={(e) => setOuFilter(e.target.value)}><option value="ALL">Все ОУ</option>{DIRECTORY_OU_ORDER.map((ou) => <option key={ou} value={ou}>ОУ {ou}</option>)}</select> : <span />}
        <span className="directory-count">{tab === 'restaurants' ? filteredRestaurants.length : phoneRows.length}</span>
      </div>

      {tab === 'restaurants' ? <div className="directory-layout">
        <div className="directory-groups">
          {grouped.map((group) => group.rows.length ? <section className="directory-group" key={group.ou}>
            <div className="directory-group-title"><strong>ОУ {group.ou}</strong><span>{group.rows.length} ресторанов</span></div>
            <div className="directory-table-wrap"><table className="directory-table"><thead><tr><th>№</th><th>ОУ</th><th>Юридическое лицо / адрес</th><th>Фактический директор</th><th>Номер</th><th>Корп. номер ресторана</th><th>Генеральный директор</th><th>Номер</th><th>Почта</th><th></th></tr></thead><tbody>
              {group.rows.map((row) => {
                const actual = matchLabel(findEmployee(row.actualDirector, phones), row.actualPhoneMode, row.actualPersonalPhone);
                const general = matchLabel(findEmployee(row.generalDirector, phones), row.generalPhoneMode, row.generalPersonalPhone);
                const restaurantMatch = findRestaurantPhone(row, phones);
                const restaurantPhone = restaurantMatch.status === 'FOUND' ? formatPhone(restaurantMatch.record.phone) : restaurantMatch.status === 'AMBIGUOUS' ? 'Требуется выбор' : '—';
                return <tr key={row.id}>
                  <td>{row.number}</td>
                  <td><select className="ou-inline" value={row.ou} onChange={(e) => savePreview({ ...row, ou: e.target.value })}>{DIRECTORY_OU_ORDER.map((ou) => <option key={ou}>{ou}</option>)}</select></td>
                  <td><strong>{row.legalEntity}</strong><span>{row.address}</span></td>
                  <td><div className="director-name"><UserRound size={15} />{row.actualDirector}</div></td>
                  <td><PhoneCell value={actual} /></td>
                  <td><span className={restaurantMatch.status === 'AMBIGUOUS' ? 'match-warning' : ''}>{restaurantPhone}</span></td>
                  <td><div className="director-name"><UserRound size={15} />{row.generalDirector}</div></td>
                  <td><PhoneCell value={general} /></td>
                  <td><a href={`mailto:${row.email}`}>{row.email || '—'}</a></td>
                  <td><button className="icon-action" type="button" onClick={() => setEditorId(row.id)} title="Редактировать"><Pencil size={17} /></button></td>
                </tr>;
              })}
            </tbody></table></div>
          </section> : null)}
        </div>
        <aside className="directory-history">
          <div className="directory-history-title"><History size={18} /><strong>Изменения preview</strong></div>
          {history.length ? history.map((entry) => <article key={entry.id}><b>{entry.restaurant}</b><span>{entry.text}</span><time>{entry.at.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}</time></article>) : <p>Изменений пока нет. Попробуй поменять ОУ или отредактировать строку.</p>}
        </aside>
      </div> : <div className="directory-table-wrap operator-table">
        <table className="directory-table"><thead><tr><th>Номер</th><th>Оператор</th><th>Юридическое лицо</th><th>ИНН</th><th>Абонент / ресторан</th><th>Тип линии</th><th>Лицевой счёт</th></tr></thead><tbody>
          {phoneRows.map((row) => <tr key={row.id}><td><strong>{formatPhone(row.phone)}</strong></td><td><b className={`directory-badge ${row.operator === 'MEGAFON' ? 'megafon' : 't2'}`}>{row.operator === 'MEGAFON' ? 'МегаФон' : 'T2'}</b></td><td>{row.legalEntity}</td><td>{row.inn ?? '—'}</td><td><strong>{row.subscriberName ?? row.restaurantName ?? '—'}</strong>{row.subscriberName && row.restaurantName ? <span>{row.restaurantName}</span> : null}</td><td>{row.lineType ?? '—'}</td><td>{row.accountNumber ?? '—'}</td></tr>)}
        </tbody></table>
        {loading ? <div className="directory-empty">Загружаем данные…</div> : null}
      </div>}

      {editor ? <RestaurantEditor restaurant={editor} onClose={() => setEditorId(null)} onSave={savePreview} /> : null}
    </main>
  </div>;
}
