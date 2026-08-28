import { useEffect, useMemo, useState } from 'react';
import {
  Building2,
  History,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  ShieldCheck,
  Smartphone,
  Trash2,
  UserRound,
  X,
} from 'lucide-react';
import {
  DIRECTORY_OU_ORDER,
  directoryRestaurantSeed,
  type DirectoryRestaurantSeed,
} from '../data/directory-preview-data';

type Operator = 'MEGAFON' | 'T2';
type PhoneMode = 'AUTO' | 'PERSONAL' | 'NONE';
type Tab = 'restaurants' | 'megafon' | 't2' | 'history';

type CorporatePhone = {
  id: string;
  phone: string;
  operator: Operator;
  legalEntity: string;
  inn: string | null;
  accountNumber: string | null;
  restaurantName: string | null;
  lineType: string | null;
  subscriberName: string | null;
};

type EditableRestaurant = DirectoryRestaurantSeed & {
  actualPhoneMode: PhoneMode;
  actualPersonalPhone: string;
  generalPhoneMode: PhoneMode;
  generalPersonalPhone: string;
};

type AuditRecord = {
  id: string;
  entityType: 'PHONE' | 'RESTAURANT';
  entityId: string;
  action: 'ADD' | 'UPDATE' | 'DELETE';
  actor: string;
  beforeState: string | null;
  afterState: string | null;
  createdAt: string;
};

type MatchResult =
  | { status: 'FOUND'; record: CorporatePhone }
  | { status: 'AMBIGUOUS' }
  | { status: 'NONE' };

const blankPhone = (operator: Operator): CorporatePhone => ({
  id: '',
  phone: '',
  operator,
  legalEntity: '',
  inn: '',
  accountNumber: '',
  restaurantName: '',
  lineType: '',
  subscriberName: '',
});

const clean = (value: string | null | undefined): string =>
  (value ?? '')
    .toLocaleLowerCase('ru-RU')
    .replace(/ё/g, 'е')
    .replace(/[«»"'().,;:\-\/\\]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

const compactLegalEntity = (value: string | null | undefined): string => {
  const legalForms = new Set(['ооо', 'зао', 'пао', 'оао', 'ао']);
  return clean(value)
    .split(' ')
    .filter((token) => token && !legalForms.has(token))
    .join(' ');
};

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
  if (a.length >= 2 && b.length >= 2 && a[1]?.[0] === b[1]?.[0]) {
    if (a.length >= 3 && b.length >= 3 && a[2]?.[0] === b[2]?.[0]) return 78;
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
  const top = scored[0]!.score;
  const rows = scored.filter((item) => item.score === top);
  if (new Set(rows.map((item) => item.record.phone)).size > 1) return { status: 'AMBIGUOUS' };
  return { status: 'FOUND', record: rows[0]!.record };
};

const sameLegalEntity = (a: string, b: string): boolean => {
  const left = compactLegalEntity(a);
  const right = compactLegalEntity(b);
  return Boolean(left && right && (left === right || left.includes(right) || right.includes(left)));
};

const addressScore = (address: string, value: string | null): number => {
  const a = clean(address);
  const b = clean(value);
  if (!a || !b) return 0;
  if (a === b) return 100;
  if (a.includes(b) || b.includes(a)) return 88;
  const aNums = a.match(/\d+/g) ?? [];
  const bNums = b.match(/\d+/g) ?? [];
  const sameNumber = aNums.some((number) => bNums.includes(number));
  const words = a.split(' ').filter((word) => word.length > 4 && !/^\d+$/.test(word));
  return (sameNumber ? 40 : 0) + Math.min(words.filter((word) => b.includes(word)).length * 12, 48);
};

const findRestaurantPhone = (restaurant: EditableRestaurant, phones: CorporatePhone[]): MatchResult => {
  const megafon = phones.filter(
    (item) => item.operator === 'MEGAFON' && sameLegalEntity(item.legalEntity, restaurant.legalEntity),
  );
  const preferred = megafon.filter((item) => {
    const type = clean(item.lineType);
    return type.includes('входящ') || type.includes('ресторан');
  });
  const pool = preferred.length ? preferred : megafon;
  const scored = pool
    .map((record) => ({ record, score: addressScore(restaurant.address, record.restaurantName) }))
    .sort((a, b) => b.score - a.score);
  if (scored.length && scored[0]!.score >= 40) {
    const best = scored[0]!.score;
    const rows = scored.filter((item) => item.score === best);
    if (new Set(rows.map((item) => item.record.phone)).size === 1) return { status: 'FOUND', record: rows[0]!.record };
    return { status: 'AMBIGUOUS' };
  }
  if (pool.length === 1) return { status: 'FOUND', record: pool[0]! };
  if (pool.length > 1) return { status: 'AMBIGUOUS' };
  const t2 = phones.filter((item) => {
    if (item.operator !== 'T2' || !sameLegalEntity(item.legalEntity, restaurant.legalEntity)) return false;
    const type = clean(item.lineType);
    return type.includes('город') || type.includes('федерал') || type.includes('ресторан');
  });
  if (t2.length === 1) return { status: 'FOUND', record: t2[0]! };
  if (t2.length > 1) return { status: 'AMBIGUOUS' };
  return { status: 'NONE' };
};

const phonePresentation = (match: MatchResult, mode: PhoneMode, personal: string) => {
  if (mode === 'PERSONAL') return { phone: personal, label: 'Личный', kind: 'personal' };
  if (mode === 'NONE') return { phone: '', label: 'Не указан', kind: 'none' };
  if (match.status === 'FOUND') return { phone: match.record.phone, label: 'Корп.', kind: 'corporate' };
  if (match.status === 'AMBIGUOUS') return { phone: '', label: 'Требуется выбор', kind: 'warning' };
  return { phone: '', label: 'Не указан', kind: 'none' };
};

const readError = async (response: Response): Promise<string> => {
  try {
    const data = (await response.json()) as { error?: string };
    return data.error || `HTTP ${response.status}`;
  } catch {
    return `HTTP ${response.status}`;
  }
};

const editorHeaders = (): Record<string, string> | null => {
  let token = sessionStorage.getItem('directory-write-token') ?? '';
  let actor = sessionStorage.getItem('directory-actor') ?? '';
  if (!token) token = window.prompt('Ключ редактора корпоративного справочника')?.trim() ?? '';
  if (!token) return null;
  if (!actor) actor = window.prompt('Ваше имя для истории изменений')?.trim() ?? '';
  if (!actor) return null;
  sessionStorage.setItem('directory-write-token', token);
  sessionStorage.setItem('directory-actor', actor);
  return {
    'content-type': 'application/json',
    'x-directory-write-token': token,
    'x-directory-actor': actor,
  };
};

function PhoneBadge({ value }: { value: ReturnType<typeof phonePresentation> }) {
  return (
    <div className="directory-phone-value">
      <span>{value.phone ? formatPhone(value.phone) : '—'}</span>
      <b className={`directory-badge ${value.kind}`}>{value.label}</b>
    </div>
  );
}

function PhoneEditor({
  record,
  onClose,
  onSave,
}: {
  record: CorporatePhone;
  onClose: () => void;
  onSave: (record: CorporatePhone) => Promise<void>;
}) {
  const [draft, setDraft] = useState(record);
  const [saving, setSaving] = useState(false);
  const update = (key: keyof CorporatePhone, value: string) => setDraft((row) => ({ ...row, [key]: value }));
  return (
    <div className="directory-modal-backdrop" onMouseDown={onClose}>
      <section className="directory-editor" onMouseDown={(event) => event.stopPropagation()}>
        <button className="directory-modal-close" type="button" onClick={onClose}><X size={20} /></button>
        <h2>{record.id ? 'Редактирование номера' : 'Добавление номера'}</h2>
        <div className="directory-form-grid">
          <label><span>Оператор</span><select value={draft.operator} onChange={(e) => update('operator', e.target.value)}><option value="MEGAFON">МегаФон</option><option value="T2">T2</option></select></label>
          <label><span>Номер телефона</span><input value={draft.phone} onChange={(e) => update('phone', e.target.value)} placeholder="+7 921 123-45-67" /></label>
          <label className="wide"><span>Юридическое лицо</span><input value={draft.legalEntity} onChange={(e) => update('legalEntity', e.target.value)} /></label>
          <label><span>ИНН</span><input value={draft.inn ?? ''} onChange={(e) => update('inn', e.target.value)} /></label>
          <label><span>Лицевой счёт</span><input value={draft.accountNumber ?? ''} onChange={(e) => update('accountNumber', e.target.value)} /></label>
          <label className="wide"><span>Ресторан / адрес</span><input value={draft.restaurantName ?? ''} onChange={(e) => update('restaurantName', e.target.value)} /></label>
          <label><span>Тип линии</span><input value={draft.lineType ?? ''} onChange={(e) => update('lineType', e.target.value)} /></label>
          <label><span>Абонент</span><input value={draft.subscriberName ?? ''} onChange={(e) => update('subscriberName', e.target.value)} /></label>
        </div>
        <div className="directory-editor-actions">
          <button type="button" onClick={onClose}>Отмена</button>
          <button className="primary" disabled={saving} type="button" onClick={async () => { setSaving(true); try { await onSave(draft); } finally { setSaving(false); } }}>{saving ? 'Сохраняю…' : 'Сохранить'}</button>
        </div>
      </section>
    </div>
  );
}

function RestaurantEditor({
  restaurant,
  onClose,
  onSave,
}: {
  restaurant: EditableRestaurant;
  onClose: () => void;
  onSave: (restaurant: EditableRestaurant) => Promise<void>;
}) {
  const [draft, setDraft] = useState(restaurant);
  const [saving, setSaving] = useState(false);
  const update = <K extends keyof EditableRestaurant>(key: K, value: EditableRestaurant[K]) =>
    setDraft((row) => ({ ...row, [key]: value }));
  return (
    <div className="directory-modal-backdrop" onMouseDown={onClose}>
      <section className="directory-editor" onMouseDown={(event) => event.stopPropagation()}>
        <button className="directory-modal-close" type="button" onClick={onClose}><X size={20} /></button>
        <h2>Редактирование ресторана</h2><p>{draft.address}</p>
        <div className="directory-form-grid">
          <label><span>ОУ</span><select value={draft.ou} onChange={(e) => update('ou', e.target.value)}>{DIRECTORY_OU_ORDER.map((ou) => <option key={ou}>{ou}</option>)}</select></label>
          <label className="wide"><span>Юридическое лицо</span><input value={draft.legalEntity} onChange={(e) => update('legalEntity', e.target.value)} /></label>
          <label className="wide"><span>Адрес</span><input value={draft.address} onChange={(e) => update('address', e.target.value)} /></label>
          <label className="wide"><span>Фактический директор</span><input value={draft.actualDirector} onChange={(e) => update('actualDirector', e.target.value)} /></label>
          <label><span>Номер директора</span><select value={draft.actualPhoneMode} onChange={(e) => update('actualPhoneMode', e.target.value as PhoneMode)}><option value="AUTO">Корпоративный / авто</option><option value="PERSONAL">Личный</option><option value="NONE">Не указан</option></select></label>
          <label><span>Личный номер</span><input disabled={draft.actualPhoneMode !== 'PERSONAL'} value={draft.actualPersonalPhone} onChange={(e) => update('actualPersonalPhone', e.target.value)} /></label>
          <label className="wide"><span>Генеральный директор</span><input value={draft.generalDirector} onChange={(e) => update('generalDirector', e.target.value)} /></label>
          <label><span>Номер ген. директора</span><select value={draft.generalPhoneMode} onChange={(e) => update('generalPhoneMode', e.target.value as PhoneMode)}><option value="AUTO">Корпоративный / авто</option><option value="PERSONAL">Личный</option><option value="NONE">Не указан</option></select></label>
          <label><span>Личный номер</span><input disabled={draft.generalPhoneMode !== 'PERSONAL'} value={draft.generalPersonalPhone} onChange={(e) => update('generalPersonalPhone', e.target.value)} /></label>
          <label className="wide"><span>Корпоративная почта</span><input value={draft.email} onChange={(e) => update('email', e.target.value)} /></label>
        </div>
        <div className="directory-editor-actions">
          <button type="button" onClick={onClose}>Отмена</button>
          <button className="primary" disabled={saving} type="button" onClick={async () => { setSaving(true); try { await onSave(draft); } finally { setSaving(false); } }}>{saving ? 'Сохраняю…' : 'Сохранить'}</button>
        </div>
      </section>
    </div>
  );
}

export function DirectoryPage() {
  const [tab, setTab] = useState<Tab>('restaurants');
  const [phones, setPhones] = useState<CorporatePhone[]>([]);
  const [persistedRestaurants, setPersistedRestaurants] = useState<EditableRestaurant[]>([]);
  const [audit, setAudit] = useState<AuditRecord[]>([]);
  const [search, setSearch] = useState('');
  const [ouFilter, setOuFilter] = useState('ALL');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [restaurantEditorId, setRestaurantEditorId] = useState<string | null>(null);
  const [phoneEditor, setPhoneEditor] = useState<CorporatePhone | null>(null);

  const restaurants = useMemo<EditableRestaurant[]>(() => {
    const persisted = new Map(persistedRestaurants.map((row) => [row.id, row]));
    return directoryRestaurantSeed.map((seed) => persisted.get(seed.id) ?? {
      ...seed,
      actualPhoneMode: 'AUTO',
      actualPersonalPhone: '',
      generalPhoneMode: 'AUTO',
      generalPersonalPhone: '',
    });
  }, [persistedRestaurants]);

  const loadAll = async () => {
    setLoading(true); setError('');
    try {
      const [phonesResponse, restaurantsResponse, auditResponse] = await Promise.all([
        fetch('/api/directory/phones'),
        fetch('/api/directory/restaurants'),
        fetch('/api/directory/audit?limit=100'),
      ]);
      if (!phonesResponse.ok) throw new Error(await readError(phonesResponse));
      if (!restaurantsResponse.ok) throw new Error(await readError(restaurantsResponse));
      if (!auditResponse.ok) throw new Error(await readError(auditResponse));
      const phoneData = await phonesResponse.json() as { records: CorporatePhone[] };
      const restaurantData = await restaurantsResponse.json() as { records: EditableRestaurant[] };
      const auditData = await auditResponse.json() as { records: AuditRecord[] };
      setPhones(phoneData.records ?? []);
      setPersistedRestaurants(restaurantData.records ?? []);
      setAudit(auditData.records ?? []);
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : 'Не удалось загрузить справочник');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void loadAll(); }, []);

  const filteredRestaurants = useMemo(() => {
    const needle = clean(search);
    return restaurants.filter((row) => {
      if (ouFilter !== 'ALL' && row.ou !== ouFilter) return false;
      if (!needle) return true;
      return [row.legalEntity, row.address, row.actualDirector, row.generalDirector, row.email, row.ou]
        .some((value) => clean(value).includes(needle));
    });
  }, [restaurants, search, ouFilter]);

  const phoneRows = useMemo(() => {
    const operator: Operator = tab === 'megafon' ? 'MEGAFON' : 'T2';
    const needle = clean(search);
    return phones.filter((row) => row.operator === operator && (!needle || [
      row.phone, row.legalEntity, row.inn, row.accountNumber, row.restaurantName, row.lineType, row.subscriberName,
    ].some((value) => clean(value).includes(needle))));
  }, [phones, search, tab]);

  const groups = DIRECTORY_OU_ORDER.map((ou) => ({ ou, rows: filteredRestaurants.filter((row) => row.ou === ou) }));
  const activeRestaurant = restaurantEditorId ? restaurants.find((row) => row.id === restaurantEditorId) ?? null : null;

  const savePhone = async (draft: CorporatePhone) => {
    const headers = editorHeaders();
    if (!headers) return;
    const isNew = !draft.id;
    const response = await fetch(isNew ? '/api/directory/phones' : `/api/directory/phones/${encodeURIComponent(draft.id)}`, {
      method: isNew ? 'POST' : 'PUT',
      headers,
      body: JSON.stringify(draft),
    });
    if (!response.ok) { setError(await readError(response)); return; }
    setPhoneEditor(null);
    await loadAll();
  };

  const deletePhone = async (row: CorporatePhone) => {
    if (!window.confirm(`Удалить ${formatPhone(row.phone)} из справочника?`)) return;
    const headers = editorHeaders();
    if (!headers) return;
    const response = await fetch(`/api/directory/phones/${encodeURIComponent(row.id)}`, { method: 'DELETE', headers });
    if (!response.ok) { setError(await readError(response)); return; }
    await loadAll();
  };

  const saveRestaurant = async (draft: EditableRestaurant) => {
    const headers = editorHeaders();
    if (!headers) return;
    const response = await fetch(`/api/directory/restaurants/${encodeURIComponent(draft.id)}`, {
      method: 'PUT', headers, body: JSON.stringify(draft),
    });
    if (!response.ok) { setError(await readError(response)); return; }
    setRestaurantEditorId(null);
    await loadAll();
  };

  const activateEditor = () => {
    sessionStorage.removeItem('directory-write-token');
    sessionStorage.removeItem('directory-actor');
    editorHeaders();
  };

  return (
    <div className="directory-shell">
      <header className="directory-topbar">
        <div className="directory-brand"><Building2 size={21} /><div><strong>Евразия · Корпоративный справочник</strong><span>v1.6.9 · отдельный веб-модуль</span></div></div>
        <button className="directory-secondary-button" type="button" onClick={activateEditor}><ShieldCheck size={17} /> Режим редактора</button>
      </header>

      <main className="directory-main">
        <div className="directory-title-row">
          <div><h1>Корпоративная связь</h1><p>Рестораны, директора и номера МегаФон / T2 из единого справочника.</p></div>
          <button className="directory-secondary-button" type="button" onClick={() => void loadAll()}><RefreshCw size={17} /> Обновить</button>
        </div>

        <div className="directory-tabs">
          <button className={tab === 'restaurants' ? 'active' : ''} onClick={() => setTab('restaurants')}><Building2 size={17} /> Рестораны и директора</button>
          <button className={tab === 'megafon' ? 'active' : ''} onClick={() => setTab('megafon')}><Smartphone size={17} /> МегаФон</button>
          <button className={tab === 't2' ? 'active' : ''} onClick={() => setTab('t2')}><Smartphone size={17} /> T2</button>
          <button className={tab === 'history' ? 'active' : ''} onClick={() => setTab('history')}><History size={17} /> История</button>
        </div>

        <div className="directory-toolbar">
          {tab !== 'history' && <label className="directory-search"><Search size={18} /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Поиск по номеру, ООО, адресу, ФИО…" /></label>}
          {tab === 'restaurants' && <select value={ouFilter} onChange={(e) => setOuFilter(e.target.value)}><option value="ALL">Все ОУ</option>{DIRECTORY_OU_ORDER.map((ou) => <option key={ou}>{ou}</option>)}</select>}
          {(tab === 'megafon' || tab === 't2') && <button className="directory-primary-button" type="button" onClick={() => setPhoneEditor(blankPhone(tab === 'megafon' ? 'MEGAFON' : 'T2'))}><Plus size={17} /> Добавить номер</button>}
        </div>

        {error && <div className="directory-error">{error}<button type="button" onClick={() => setError('')}>×</button></div>}
        {loading && <div className="directory-empty">Загружаю данные…</div>}

        {!loading && tab === 'restaurants' && (
          <div className="directory-groups">
            {groups.filter((group) => group.rows.length).map((group) => (
              <section className="directory-group" key={group.ou}>
                <div className="directory-group-title"><strong>ОУ {group.ou}</strong><span>{group.rows.length}</span></div>
                <div className="directory-table-scroll"><table className="directory-table restaurants"><thead><tr><th>№</th><th>ООО / адрес</th><th>Фактический директор</th><th>Телефон директора</th><th>Генеральный директор</th><th>Телефон ген. директора</th><th>Почта</th><th /></tr></thead><tbody>
                  {group.rows.map((row) => {
                    const restaurantPhone = findRestaurantPhone(row, phones);
                    const actual = phonePresentation(findEmployee(row.actualDirector, phones), row.actualPhoneMode, row.actualPersonalPhone);
                    const general = phonePresentation(findEmployee(row.generalDirector, phones), row.generalPhoneMode, row.generalPersonalPhone);
                    return <tr key={row.id}>
                      <td>{row.number}</td>
                      <td><strong>{row.legalEntity}</strong><small>{row.address}</small>{restaurantPhone.status === 'FOUND' && <small>Ресторан: {formatPhone(restaurantPhone.record.phone)}</small>}</td>
                      <td><UserRound size={15} /> {row.actualDirector || '—'}</td>
                      <td><PhoneBadge value={actual} /></td>
                      <td>{row.generalDirector || '—'}</td>
                      <td><PhoneBadge value={general} /></td>
                      <td>{row.email || '—'}</td>
                      <td><button className="directory-icon-button" type="button" title="Редактировать" onClick={() => setRestaurantEditorId(row.id)}><Pencil size={16} /></button></td>
                    </tr>;
                  })}
                </tbody></table></div>
              </section>
            ))}
          </div>
        )}

        {!loading && (tab === 'megafon' || tab === 't2') && (
          <section className="directory-group">
            <div className="directory-group-title"><strong>{tab === 'megafon' ? 'МегаФон' : 'T2'}</strong><span>{phoneRows.length}</span></div>
            <div className="directory-table-scroll"><table className="directory-table"><thead><tr><th>Номер</th><th>ООО</th><th>ИНН</th><th>Лицевой счёт</th><th>Ресторан / адрес</th><th>Тип</th><th>Абонент</th><th /></tr></thead><tbody>
              {phoneRows.map((row) => <tr key={row.id}>
                <td><strong>{formatPhone(row.phone)}</strong></td><td>{row.legalEntity}</td><td>{row.inn || '—'}</td><td>{row.accountNumber || '—'}</td><td>{row.restaurantName || '—'}</td><td>{row.lineType || '—'}</td><td>{row.subscriberName || '—'}</td>
                <td className="directory-actions"><button className="directory-icon-button" type="button" title="Редактировать" onClick={() => setPhoneEditor(row)}><Pencil size={16} /></button><button className="directory-icon-button danger" type="button" title="Удалить" onClick={() => void deletePhone(row)}><Trash2 size={16} /></button></td>
              </tr>)}
            </tbody></table></div>
          </section>
        )}

        {!loading && tab === 'history' && (
          <section className="directory-group">
            <div className="directory-group-title"><strong>История изменений</strong><span>{audit.length}</span></div>
            {audit.length === 0 ? <div className="directory-empty">Изменений через веб-интерфейс пока нет.</div> : <div className="directory-audit-list">{audit.map((item) => <article key={item.id}><div><b>{item.action === 'ADD' ? 'Добавлено' : item.action === 'DELETE' ? 'Удалено' : 'Изменено'}</b><span>{item.entityType === 'PHONE' ? 'Номер' : 'Ресторан'} · {item.entityId}</span></div><div><strong>{item.actor}</strong><time>{new Date(item.createdAt).toLocaleString('ru-RU')}</time></div></article>)}</div>}
          </section>
        )}
      </main>

      {activeRestaurant && <RestaurantEditor restaurant={activeRestaurant} onClose={() => setRestaurantEditorId(null)} onSave={saveRestaurant} />}
      {phoneEditor && <PhoneEditor record={phoneEditor} onClose={() => setPhoneEditor(null)} onSave={savePhone} />}
    </div>
  );
}
