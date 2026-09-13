import { useEffect, useMemo, useState } from 'react';
import {
  Building2,
  Columns3,
  History,
  Landmark,
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
  type DirectoryRestaurantSeed,
} from '../data/directory-preview-data';
import { LegalEntitiesPanel } from './LegalEntitiesPanel';
import type { LegalEntityMaster, LegalEntityOperatorAccount } from './legal-entity-types';

type Operator = 'MEGAFON' | 'T2';
type PhoneMode = 'AUTO' | 'PERSONAL' | 'NONE';
type Tab = 'restaurants' | 'requisites' | 'megafon' | 't2' | 'history';
type T2Section = 'employees' | 'm2m' | 'city';
type MegafonColumn = 'cityPhone' | 'federalPhone' | 'legalEntity' | 'inn' | 'accountNumber' | 'restaurantName' | 'lineType' | 'subscriberName';

type CorporatePhone = {
  id: string;
  phone: string;
  cityPhone: string | null;
  federalPhone: string | null;
  operator: Operator;
  legalEntityId: string | null;
  legalEntity: string;
  inn: string | null;
  accountNumber: string | null;
  restaurantName: string | null;
  lineType: string | null;
  subscriberName: string | null;
};

type EditableRestaurant = DirectoryRestaurantSeed & {
  legalEntityId: string | null;
  actualPhoneMode: PhoneMode;
  actualPersonalPhone: string;
  generalPhoneMode: PhoneMode;
  generalPersonalPhone: string;
};

type AuditRecord = {
  id: string;
  entityType: 'PHONE' | 'RESTAURANT' | 'LEGAL_ENTITY';
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
  cityPhone: '',
  federalPhone: '',
  operator,
  legalEntityId: null,
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

const phoneDigits = (phone: string | null | undefined): string => (phone ?? '').replace(/\D/g, '');

const cityPhoneOf = (record: CorporatePhone): string => {
  if (record.cityPhone) return record.cityPhone;
  return phoneDigits(record.phone).startsWith('7812') ? record.phone : '';
};

const federalPhoneOf = (record: CorporatePhone): string => {
  if (record.federalPhone) return record.federalPhone;
  const type = clean(record.lineType);
  return (record.operator === 'MEGAFON' || type.includes('федерал'))
    && record.phone
    && !phoneDigits(record.phone).startsWith('7812')
    ? record.phone
    : '';
};

const phoneForDisplay = (record: CorporatePhone): string =>
  cityPhoneOf(record) || federalPhoneOf(record) || record.phone;

const t2SectionOf = (record: CorporatePhone): T2Section => {
  const type = clean(record.lineType);
  if (
    type.includes('pos')
    || type.includes('интернет sim')
    || type.includes('m2m')
    || type.includes('м2м')
    || type.includes('телемат')
    || type.includes('передач')
  ) return 'm2m';
  if (
    type.includes('город')
    || type.includes('федерал')
    || Boolean(record.cityPhone)
    || Boolean(record.federalPhone)
    || phoneDigits(record.phone).startsWith('7812')
  ) return 'city';
  return 'employees';
};

const MEGAFON_COLUMNS: Array<{ key: MegafonColumn; label: string }> = [
  { key: 'cityPhone', label: 'Городской номер' },
  { key: 'federalPhone', label: 'Федеральный номер' },
  { key: 'legalEntity', label: 'ООО' },
  { key: 'inn', label: 'ИНН' },
  { key: 'accountNumber', label: 'Лицевой счёт' },
  { key: 'restaurantName', label: 'Ресторан / адрес' },
  { key: 'lineType', label: 'Тип' },
  { key: 'subscriberName', label: 'Абонент' },
];

const DEFAULT_MEGAFON_COLUMNS: MegafonColumn[] = [
  'cityPhone', 'legalEntity', 'inn', 'accountNumber', 'restaurantName', 'lineType', 'subscriberName',
];

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

const sameLegalEntity = (a: string, b: string): boolean => {
  const left = compactLegalEntity(a);
  const right = compactLegalEntity(b);
  return Boolean(left && right && (left === right || left.includes(right) || right.includes(left)));
};

const employeeTypePriority = (record: CorporatePhone): number => {
  const type = clean(record.lineType);
  if (type === 'сотрудник') return 2;
  if (type.includes('временн')) return 1;
  return 0;
};

const findEmployee = (name: string, legalEntity: string, phones: CorporatePhone[]): MatchResult => {
  const scored = phones
    .filter((item) => item.operator === 'T2' && item.subscriberName && employeeTypePriority(item) > 0)
    .map((record) => ({
      record,
      score: employeeScore(name, record.subscriberName ?? ''),
      typePriority: employeeTypePriority(record),
      sameEntity: sameLegalEntity(record.legalEntity, legalEntity),
    }))
    .filter((item) => item.score >= 72)
    .sort((a, b) => b.score - a.score || b.typePriority - a.typePriority);
  if (!scored.length) return { status: 'NONE' };
  const local = scored.filter((item) => item.sameEntity);
  const candidates = local.length ? local : scored;
  const top = candidates[0]!.score;
  const topType = candidates.filter((item) => item.score === top)[0]!.typePriority;
  const rows = candidates.filter((item) => item.score === top && item.typePriority === topType);
  if (new Set(rows.map((item) => phoneForDisplay(item.record))).size > 1) return { status: 'AMBIGUOUS' };
  return { status: 'FOUND', record: rows[0]!.record };
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

const chooseRestaurantPhone = (restaurant: EditableRestaurant, pool: CorporatePhone[]): MatchResult => {
  const scored = pool
    .map((record) => ({ record, score: addressScore(restaurant.address, record.restaurantName) }))
    .sort((a, b) => b.score - a.score);
  if (scored.length && scored[0]!.score >= 40) {
    const best = scored[0]!.score;
    const rows = scored.filter((item) => item.score === best);
    if (new Set(rows.map((item) => phoneForDisplay(item.record))).size === 1) return { status: 'FOUND', record: rows[0]!.record };
    return { status: 'AMBIGUOUS' };
  }
  if (pool.length === 1) return { status: 'FOUND', record: pool[0]! };
  if (pool.length > 1) return { status: 'AMBIGUOUS' };
  return { status: 'NONE' };
};

const findRestaurantPhone = (restaurant: EditableRestaurant, phones: CorporatePhone[]): MatchResult => {
  const megafon = phones.filter((item) => {
    if (item.operator !== 'MEGAFON' || !cityPhoneOf(item)) return false;
    return sameLegalEntity(item.legalEntity, restaurant.legalEntity);
  });
  const preferredMegafon = megafon.filter((item) => {
    const type = clean(item.lineType);
    return type.includes('входящ') || type.includes('город') || type.includes('ресторан');
  });
  const megafonMatch = chooseRestaurantPhone(restaurant, preferredMegafon.length ? preferredMegafon : megafon);
  if (megafonMatch.status !== 'NONE') return megafonMatch;

  const t2City = phones.filter((item) =>
    item.operator === 'T2'
    && t2SectionOf(item) === 'city'
    && sameLegalEntity(item.legalEntity, restaurant.legalEntity));
  return chooseRestaurantPhone(restaurant, t2City);
};

const phonePresentation = (match: MatchResult, mode: PhoneMode, personal: string) => {
  if (mode === 'PERSONAL') return { phone: personal, label: 'Личный', kind: 'personal' };
  if (mode === 'NONE') return { phone: '', label: 'Не указан', kind: 'none' };
  if (match.status === 'FOUND') return { phone: match.record.phone, label: 'Корп.', kind: 'corporate' };
  if (match.status === 'AMBIGUOUS') return { phone: '', label: 'Требуется выбор', kind: 'warning' };
  return { phone: '', label: 'Не указан', kind: 'none' };
};

const restaurantPhonePresentation = (match: MatchResult) => {
  if (match.status === 'FOUND') {
    return {
      phone: cityPhoneOf(match.record) || phoneForDisplay(match.record),
      label: match.record.operator === 'MEGAFON' ? 'МегаФон' : 'T2',
      kind: 'corporate',
    };
  }
  if (match.status === 'AMBIGUOUS') return { phone: '', label: 'Требуется выбор', kind: 'warning' };
  return { phone: '', label: 'Не указан', kind: 'none' };
};

const renderMegafonValue = (record: CorporatePhone, column: MegafonColumn): string => {
  if (column === 'cityPhone') return cityPhoneOf(record) ? formatPhone(cityPhoneOf(record)) : '—';
  if (column === 'federalPhone') return federalPhoneOf(record) ? formatPhone(federalPhoneOf(record)) : '—';
  return record[column] || '—';
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
  let token = sessionStorage.getItem('phonebook-write-token') ?? '';
  let actor = sessionStorage.getItem('phonebook-actor') ?? '';
  if (!token) token = window.prompt('Ключ редактора корпоративного справочника')?.trim() ?? '';
  if (!token) return null;
  if (!actor) actor = window.prompt('Ваше имя для истории изменений')?.trim() ?? '';
  if (!actor) return null;
  sessionStorage.setItem('phonebook-write-token', token);
  sessionStorage.setItem('phonebook-actor', actor);
  return {
    'content-type': 'application/json',
    'x-phonebook-write-token': token,
    'x-phonebook-actor': actor,
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
  legalEntities,
  operatorAccounts,
  onClose,
  onSave,
}: {
  record: CorporatePhone;
  legalEntities: LegalEntityMaster[];
  operatorAccounts: LegalEntityOperatorAccount[];
  onClose: () => void;
  onSave: (record: CorporatePhone) => Promise<void>;
}) {
  const [draft, setDraft] = useState(record);
  const [saving, setSaving] = useState(false);
  const update = (key: keyof CorporatePhone, value: string | null) => setDraft((row) => ({ ...row, [key]: value }));
  const usesNumberAliases = draft.operator === 'MEGAFON' || t2SectionOf(draft) === 'city';
  const accountChoices = operatorAccounts.filter((account) => account.legalEntityId === draft.legalEntityId && account.operator === draft.operator);
  const selectLegalEntity = (id: string) => {
    const entity = legalEntities.find((item) => item.id === id) ?? null;
    const firstAccount = entity
      ? operatorAccounts.find((account) => account.legalEntityId === entity.id && account.operator === draft.operator && account.isPrimary)
        ?? operatorAccounts.find((account) => account.legalEntityId === entity.id && account.operator === draft.operator)
      : null;
    setDraft((row) => ({
      ...row,
      legalEntityId: entity?.id ?? null,
      legalEntity: entity?.name ?? '',
      inn: entity?.inn ?? '',
      accountNumber: firstAccount?.accountNumber ?? '',
    }));
  };
  return (
    <div className="directory-modal-backdrop" onMouseDown={onClose}>
      <section className="directory-editor" onMouseDown={(event) => event.stopPropagation()}>
        <button className="directory-modal-close" type="button" onClick={onClose}><X size={20} /></button>
        <h2>{record.id ? 'Редактирование номера' : 'Добавление номера'}</h2>
        <div className="directory-form-grid">
          <label><span>Оператор</span><select value={draft.operator} onChange={(e) => setDraft((row) => ({ ...row, operator: e.target.value as Operator, accountNumber: '' }))}><option value="MEGAFON">МегаФон</option><option value="T2">T2</option></select></label>
          {usesNumberAliases ? <>
            <label><span>Городской номер</span><input value={draft.cityPhone ?? ''} onChange={(e) => update('cityPhone', e.target.value)} placeholder="+7 812 900-00-00" /></label>
            <label><span>Федеральный (мобильный) номер</span><input value={draft.federalPhone ?? ''} onChange={(e) => update('federalPhone', e.target.value)} placeholder="+7 952 123-45-67" /></label>
          </> : <label><span>Номер телефона</span><input value={draft.phone} onChange={(e) => update('phone', e.target.value)} placeholder="+7 921 123-45-67" /></label>}
          <label className="wide"><span>Юридическое лицо</span><select value={draft.legalEntityId ?? ''} onChange={(e) => selectLegalEntity(e.target.value)}><option value="">Выберите организацию</option>{legalEntities.map((entity) => <option key={entity.id} value={entity.id}>{entity.name}{entity.inn ? ` · ИНН ${entity.inn}` : ''}</option>)}</select></label>
          <label><span>ИНН</span><input readOnly value={draft.inn ?? ''} /></label>
          <label><span>Лицевой счёт</span>{accountChoices.length ? <select value={draft.accountNumber ?? ''} onChange={(e) => update('accountNumber', e.target.value)}><option value="">Не выбран</option>{accountChoices.map((account) => <option key={account.id} value={account.accountNumber}>{account.accountNumber}{account.contractNumber ? ` · договор ${account.contractNumber}` : ''}</option>)}</select> : <input value={draft.accountNumber ?? ''} onChange={(e) => update('accountNumber', e.target.value)} placeholder="Нет master-счёта — временный ввод" />}</label>
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
  legalEntities,
  onClose,
  onSave,
}: {
  restaurant: EditableRestaurant;
  legalEntities: LegalEntityMaster[];
  onClose: () => void;
  onSave: (restaurant: EditableRestaurant) => Promise<void>;
}) {
  const [draft, setDraft] = useState(restaurant);
  const [saving, setSaving] = useState(false);
  const update = <K extends keyof EditableRestaurant>(key: K, value: EditableRestaurant[K]) =>
    setDraft((row) => ({ ...row, [key]: value }));
  const selectLegalEntity = (id: string) => {
    const entity = legalEntities.find((item) => item.id === id) ?? null;
    setDraft((row) => ({
      ...row,
      legalEntityId: entity?.id ?? null,
      legalEntity: entity?.name ?? '',
      generalDirector: entity?.generalDirector ?? '',
    }));
  };
  return (
    <div className="directory-modal-backdrop" onMouseDown={onClose}>
      <section className="directory-editor" onMouseDown={(event) => event.stopPropagation()}>
        <button className="directory-modal-close" type="button" onClick={onClose}><X size={20} /></button>
        <h2>Редактирование ресторана</h2><p>{draft.address}</p>
        <div className="directory-form-grid">
          <label><span>ОУ</span><select value={draft.ou} onChange={(e) => update('ou', e.target.value)}>{DIRECTORY_OU_ORDER.map((ou) => <option key={ou}>{ou}</option>)}</select></label>
          <label className="wide"><span>Юридическое лицо</span><select value={draft.legalEntityId ?? ''} onChange={(e) => selectLegalEntity(e.target.value)}><option value="">Выберите организацию</option>{legalEntities.map((entity) => <option key={entity.id} value={entity.id}>{entity.name}{entity.inn ? ` · ИНН ${entity.inn}` : ''}</option>)}</select></label>
          <label className="wide"><span>Короткий адрес ресторана</span><input value={draft.address} onChange={(e) => update('address', e.target.value)} /><small>Компактный display-адрес сохраняется отдельно от официального фактического адреса ЮЛ.</small></label>
          <label className="wide"><span>Фактический директор</span><input value={draft.actualDirector} onChange={(e) => update('actualDirector', e.target.value)} /></label>
          <label><span>Номер директора</span><select value={draft.actualPhoneMode} onChange={(e) => update('actualPhoneMode', e.target.value as PhoneMode)}><option value="AUTO">Корпоративный / авто</option><option value="PERSONAL">Личный</option><option value="NONE">Не указан</option></select></label>
          <label><span>Личный номер</span><input disabled={draft.actualPhoneMode !== 'PERSONAL'} value={draft.actualPersonalPhone} onChange={(e) => update('actualPersonalPhone', e.target.value)} /></label>
          <label className="wide"><span>Генеральный директор</span><input readOnly value={draft.generalDirector} /><small>Берётся из master-карточки юридического лица.</small></label>
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
  const [t2Section, setT2Section] = useState<T2Section>('employees');
  const [megafonColumns, setMegafonColumns] = useState<Set<MegafonColumn>>(() => new Set(DEFAULT_MEGAFON_COLUMNS));
  const [phones, setPhones] = useState<CorporatePhone[]>([]);
  const [persistedRestaurants, setPersistedRestaurants] = useState<EditableRestaurant[]>([]);
  const [legalEntities, setLegalEntities] = useState<LegalEntityMaster[]>([]);
  const [operatorAccounts, setOperatorAccounts] = useState<LegalEntityOperatorAccount[]>([]);
  const [audit, setAudit] = useState<AuditRecord[]>([]);
  const [search, setSearch] = useState('');
  const [ouFilter, setOuFilter] = useState('ALL');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [restaurantEditorId, setRestaurantEditorId] = useState<string | null>(null);
  const [phoneEditor, setPhoneEditor] = useState<CorporatePhone | null>(null);

  const restaurants = persistedRestaurants;

  const loadAll = async () => {
    setLoading(true); setError('');
    try {
      const [phonesResponse, restaurantsResponse, legalEntitiesResponse, operatorAccountsResponse, auditResponse] = await Promise.all([
        fetch('/api/phonebook/phones'),
        fetch('/api/phonebook/restaurants'),
        fetch('/api/phonebook/legal-entities'),
        fetch('/api/phonebook/legal-entity-operator-accounts'),
        fetch('/api/phonebook/audit?limit=100'),
      ]);
      if (!phonesResponse.ok) throw new Error(await readError(phonesResponse));
      if (!restaurantsResponse.ok) throw new Error(await readError(restaurantsResponse));
      if (!legalEntitiesResponse.ok) throw new Error(await readError(legalEntitiesResponse));
      if (!operatorAccountsResponse.ok) throw new Error(await readError(operatorAccountsResponse));
      if (!auditResponse.ok) throw new Error(await readError(auditResponse));
      const phoneData = await phonesResponse.json() as { records: CorporatePhone[] };
      const restaurantData = await restaurantsResponse.json() as { records: EditableRestaurant[] };
      const legalEntityData = await legalEntitiesResponse.json() as { records: LegalEntityMaster[] };
      const operatorAccountData = await operatorAccountsResponse.json() as { records: LegalEntityOperatorAccount[] };
      const auditData = await auditResponse.json() as { records: AuditRecord[] };
      setPhones(phoneData.records ?? []);
      setPersistedRestaurants(restaurantData.records ?? []);
      setLegalEntities(legalEntityData.records ?? []);
      setOperatorAccounts(operatorAccountData.records ?? []);
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
    return phones.filter((row) => {
      if (row.operator !== operator) return false;
      if (operator === 'T2' && t2SectionOf(row) !== t2Section) return false;
      return !needle || [row.phone, row.cityPhone, row.federalPhone, row.legalEntity, row.inn, row.accountNumber, row.restaurantName, row.lineType, row.subscriberName]
        .some((value) => clean(value).includes(needle));
    });
  }, [phones, search, tab, t2Section]);

  const t2Counts = useMemo(() => {
    const counts: Record<T2Section, number> = { employees: 0, m2m: 0, city: 0 };
    phones.filter((row) => row.operator === 'T2').forEach((row) => { counts[t2SectionOf(row)] += 1; });
    return counts;
  }, [phones]);

  const toggleMegafonColumn = (column: MegafonColumn) => {
    setMegafonColumns((current) => {
      const next = new Set(current);
      if (next.has(column)) { if (next.size > 1) next.delete(column); } else next.add(column);
      return next;
    });
  };

  const groups = DIRECTORY_OU_ORDER.map((ou) => ({ ou, rows: filteredRestaurants.filter((row) => row.ou === ou) }));
  const activeRestaurant = restaurantEditorId ? restaurants.find((row) => row.id === restaurantEditorId) ?? null : null;

  const savePhone = async (draft: CorporatePhone) => {
    const headers = editorHeaders();
    if (!headers) return;
    const isNew = !draft.id;
    const response = await fetch(isNew ? '/api/phonebook/phones' : `/api/phonebook/phones/${encodeURIComponent(draft.id)}`, { method: isNew ? 'POST' : 'PUT', headers, body: JSON.stringify(draft) });
    if (!response.ok) { setError(await readError(response)); return; }
    setPhoneEditor(null);
    await loadAll();
  };

  const deletePhone = async (row: CorporatePhone) => {
    if (!window.confirm(`Удалить ${formatPhone(phoneForDisplay(row))} из справочника?`)) return;
    const headers = editorHeaders();
    if (!headers) return;
    const response = await fetch(`/api/phonebook/phones/${encodeURIComponent(row.id)}`, { method: 'DELETE', headers });
    if (!response.ok) { setError(await readError(response)); return; }
    await loadAll();
  };

  const saveRestaurant = async (draft: EditableRestaurant) => {
    const headers = editorHeaders();
    if (!headers) return;
    const response = await fetch(`/api/phonebook/restaurants/${encodeURIComponent(draft.id)}`, { method: 'PUT', headers, body: JSON.stringify(draft) });
    if (!response.ok) { setError(await readError(response)); return; }
    setRestaurantEditorId(null);
    await loadAll();
  };

  const activateEditor = () => {
    sessionStorage.removeItem('phonebook-write-token');
    sessionStorage.removeItem('phonebook-actor');
    editorHeaders();
  };

  return (
    <div className="directory-shell">
      <header className="directory-topbar">
        <div className="directory-brand"><Building2 size={21} /><div><strong>Евразия · Корпоративный справочник</strong><span>v1.8 · master-data / документы</span></div></div>
        <button className="directory-secondary-button" type="button" onClick={activateEditor}><ShieldCheck size={17} /> Режим редактора</button>
      </header>

      <main className="directory-main">
        <div className="directory-title-row">
          <div><h1>Корпоративный справочник</h1><p>Рестораны, юридические реквизиты и корпоративная связь из единого master-data контура.</p></div>
          <button className="directory-secondary-button" type="button" onClick={() => void loadAll()}><RefreshCw size={17} /> Обновить</button>
        </div>

        <div className="directory-tabs">
          <button className={tab === 'restaurants' ? 'active' : ''} onClick={() => setTab('restaurants')}><Building2 size={17} /> Рестораны и директора</button>
          <button className={tab === 'requisites' ? 'active' : ''} onClick={() => setTab('requisites')}><Landmark size={17} /> Реквизиты</button>
          <button className={tab === 'megafon' ? 'active' : ''} onClick={() => setTab('megafon')}><Smartphone size={17} /> МегаФон</button>
          <button className={tab === 't2' ? 'active' : ''} onClick={() => setTab('t2')}><Smartphone size={17} /> T2</button>
          <button className={tab === 'history' ? 'active' : ''} onClick={() => setTab('history')}><History size={17} /> История</button>
        </div>

        {tab === 't2' && <div className="directory-subtabs" aria-label="Разделы T2">
          <button className={t2Section === 'employees' ? 'active' : ''} type="button" onClick={() => setT2Section('employees')}>Телефоны сотрудников <span>{t2Counts.employees}</span></button>
          <button className={t2Section === 'm2m' ? 'active' : ''} type="button" onClick={() => setT2Section('m2m')}>M2M сим <span>{t2Counts.m2m}</span></button>
          <button className={t2Section === 'city' ? 'active' : ''} type="button" onClick={() => setT2Section('city')}>Городские номера <span>{t2Counts.city}</span></button>
        </div>}

        <div className="directory-toolbar">
          {tab !== 'history' && <label className="directory-search"><Search size={18} /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={tab === 'requisites' ? 'Поиск по ООО, ИНН, КПП, ОГРН, директору…' : 'Поиск по номеру, ООО, адресу, ФИО…'} /></label>}
          {tab === 'restaurants' && <select value={ouFilter} onChange={(e) => setOuFilter(e.target.value)}><option value="ALL">Все ОУ</option>{DIRECTORY_OU_ORDER.map((ou) => <option key={ou}>{ou}</option>)}</select>}
          {tab === 'megafon' && <details className="directory-columns"><summary><Columns3 size={17} /> Столбцы</summary><div className="directory-columns-menu"><strong>Показывать в таблице</strong>{MEGAFON_COLUMNS.map((column) => <label key={column.key}><input type="checkbox" checked={megafonColumns.has(column.key)} onChange={() => toggleMegafonColumn(column.key)} /><span>{column.label}</span></label>)}</div></details>}
          {(tab === 'megafon' || tab === 't2') && <button className="directory-primary-button" type="button" onClick={() => setPhoneEditor(blankPhone(tab === 'megafon' ? 'MEGAFON' : 'T2'))}><Plus size={17} /> Добавить номер</button>}
        </div>

        {error && <div className="directory-error">{error}<button type="button" onClick={() => setError('')}>×</button></div>}
        {loading && <div className="directory-empty">Загружаю данные…</div>}

        {!loading && tab === 'requisites' && <LegalEntitiesPanel records={legalEntities} search={search} editorHeaders={editorHeaders} onReload={loadAll} onError={setError} />}

        {!loading && tab === 'restaurants' && (
          <div className="directory-groups">
            {groups.filter((group) => group.rows.length).map((group) => (
              <section className="directory-group" key={group.ou}>
                <div className="directory-group-title"><strong>ОУ {group.ou}</strong><span>{group.rows.length}</span></div>
                <div className="directory-table-scroll"><table className="directory-table restaurants"><thead><tr><th>№</th><th>ООО / адрес</th><th>Номер ресторана</th><th>Фактический директор</th><th>Телефон директора</th><th>Генеральный директор</th><th>Телефон ген. директора</th><th>Почта</th><th /></tr></thead><tbody>
                  {group.rows.map((row) => {
                    const restaurantPhone = findRestaurantPhone(row, phones);
                    const restaurantNumber = restaurantPhonePresentation(restaurantPhone);
                    const actualMatch = findEmployee(row.actualDirector, row.legalEntity, phones);
                    const sameDirector = Boolean(clean(row.actualDirector) && clean(row.actualDirector) === clean(row.generalDirector));
                    const generalMatch = sameDirector ? actualMatch : findEmployee(row.generalDirector, row.legalEntity, phones);
                    const actual = phonePresentation(actualMatch, row.actualPhoneMode, row.actualPersonalPhone);
                    const general = phonePresentation(generalMatch, row.generalPhoneMode, row.generalPersonalPhone);
                    return <tr key={row.id}>
                      <td>{row.number}</td>
                      <td><strong>{row.legalEntity}</strong><small>{row.address}</small></td>
                      <td><PhoneBadge value={restaurantNumber} /></td>
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
            <div className="directory-group-title"><strong>{tab === 'megafon' ? 'МегаФон' : `T2 · ${t2Section === 'employees' ? 'Телефоны сотрудников' : t2Section === 'm2m' ? 'M2M сим' : 'Городские номера'}`}</strong><span>{phoneRows.length}</span></div>
            <div className="directory-table-scroll"><table className="directory-table">
              {tab === 'megafon' ? <>
                <thead><tr>{MEGAFON_COLUMNS.filter((column) => megafonColumns.has(column.key)).map((column) => <th key={column.key}>{column.label}</th>)}<th /></tr></thead>
                <tbody>{phoneRows.map((row) => <tr key={row.id}>{MEGAFON_COLUMNS.filter((column) => megafonColumns.has(column.key)).map((column) => <td key={column.key}>{column.key === 'cityPhone' || column.key === 'federalPhone' ? <strong>{renderMegafonValue(row, column.key)}</strong> : renderMegafonValue(row, column.key)}</td>)}<td className="directory-actions"><button className="directory-icon-button" type="button" title="Редактировать" onClick={() => setPhoneEditor(row)}><Pencil size={16} /></button><button className="directory-icon-button danger" type="button" title="Удалить" onClick={() => void deletePhone(row)}><Trash2 size={16} /></button></td></tr>)}</tbody>
              </> : <>
                <thead><tr>{t2Section === 'city' ? <><th>Городской номер</th><th>Федеральный (мобильный) номер</th></> : <th>Номер</th>}<th>ООО</th><th>ИНН</th><th>Лицевой счёт</th><th>Ресторан / адрес</th><th>Тип</th><th>Абонент</th><th /></tr></thead>
                <tbody>{phoneRows.map((row) => <tr key={row.id}>{t2Section === 'city' ? <><td><strong>{cityPhoneOf(row) ? formatPhone(cityPhoneOf(row)) : '—'}</strong></td><td><strong>{federalPhoneOf(row) ? formatPhone(federalPhoneOf(row)) : '—'}</strong></td></> : <td><strong>{formatPhone(row.phone)}</strong></td>}<td>{row.legalEntity}</td><td>{row.inn || '—'}</td><td>{row.accountNumber || '—'}</td><td>{row.restaurantName || '—'}</td><td>{row.lineType || '—'}</td><td>{row.subscriberName || '—'}</td><td className="directory-actions"><button className="directory-icon-button" type="button" title="Редактировать" onClick={() => setPhoneEditor(row)}><Pencil size={16} /></button><button className="directory-icon-button danger" type="button" title="Удалить" onClick={() => void deletePhone(row)}><Trash2 size={16} /></button></td></tr>)}</tbody>
              </>}
            </table></div>
          </section>
        )}

        {!loading && tab === 'history' && (
          <section className="directory-group">
            <div className="directory-group-title"><strong>История изменений</strong><span>{audit.length}</span></div>
            {audit.length === 0 ? <div className="directory-empty">Изменений через веб-интерфейс пока нет.</div> : <div className="directory-audit-list">{audit.map((item) => <article key={item.id}><div><b>{item.action === 'ADD' ? 'Добавлено' : item.action === 'DELETE' ? 'Архивировано / удалено' : 'Изменено'}</b><span>{item.entityType === 'PHONE' ? 'Номер' : item.entityType === 'RESTAURANT' ? 'Ресторан' : 'Юридическое лицо'} · {item.entityId}</span></div><div><strong>{item.actor}</strong><time>{new Date(item.createdAt).toLocaleString('ru-RU')}</time></div></article>)}</div>}
          </section>
        )}
      </main>

      {activeRestaurant && <RestaurantEditor restaurant={activeRestaurant} legalEntities={legalEntities} onClose={() => setRestaurantEditorId(null)} onSave={saveRestaurant} />}
      {phoneEditor && <PhoneEditor record={phoneEditor} legalEntities={legalEntities} operatorAccounts={operatorAccounts} onClose={() => setPhoneEditor(null)} onSave={savePhone} />}
    </div>
  );
}
