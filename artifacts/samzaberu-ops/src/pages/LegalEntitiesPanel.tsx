import { useMemo, useState } from 'react';
import { Pencil, Plus, Trash2, X } from 'lucide-react';
import { blankLegalEntity, type LegalEntityMaster } from './legal-entity-types';

type Props = {
  records: LegalEntityMaster[];
  search: string;
  editorHeaders: () => Record<string, string> | null;
  onReload: () => Promise<void>;
  onError: (message: string) => void;
};

const clean = (value: string | null | undefined): string =>
  (value ?? '').toLocaleLowerCase('ru-RU').replace(/ё/g, 'е').trim();

const readError = async (response: Response): Promise<string> => {
  try {
    const data = (await response.json()) as { error?: string };
    return data.error || `HTTP ${response.status}`;
  } catch {
    return `HTTP ${response.status}`;
  }
};

function LegalEntityEditor({
  record,
  onClose,
  onSave,
}: {
  record: LegalEntityMaster;
  onClose: () => void;
  onSave: (record: LegalEntityMaster) => Promise<void>;
}) {
  const [draft, setDraft] = useState(record);
  const [saving, setSaving] = useState(false);
  const update = <K extends keyof LegalEntityMaster>(key: K, value: LegalEntityMaster[K]) =>
    setDraft((row) => ({ ...row, [key]: value }));

  return (
    <div className="directory-modal-backdrop" onMouseDown={onClose}>
      <section className="directory-editor" onMouseDown={(event) => event.stopPropagation()}>
        <button className="directory-modal-close" type="button" onClick={onClose}><X size={20} /></button>
        <h2>{record.id ? 'Редактирование организации' : 'Добавление организации'}</h2>
        <div className="directory-form-grid">
          <label className="wide"><span>Короткое название</span><input value={draft.name} onChange={(e) => update('name', e.target.value)} placeholder="ООО «Евразия-Светлановская»" /></label>
          <label className="wide"><span>Полное наименование</span><input value={draft.fullName ?? ''} onChange={(e) => update('fullName', e.target.value)} /></label>
          <label><span>ИНН</span><input value={draft.inn ?? ''} onChange={(e) => update('inn', e.target.value)} /></label>
          <label><span>КПП</span><input value={draft.kpp ?? ''} onChange={(e) => update('kpp', e.target.value)} /></label>
          <label><span>ОГРН</span><input value={draft.ogrn ?? ''} onChange={(e) => update('ogrn', e.target.value)} /></label>
          <label><span>Статус проверки</span><select value={draft.verificationStatus} onChange={(e) => update('verificationStatus', e.target.value as LegalEntityMaster['verificationStatus'])}><option value="UNVERIFIED">Не проверено</option><option value="NEEDS_REVIEW">Требует проверки</option><option value="VERIFIED">Проверено</option></select></label>
          <label className="wide"><span>Генеральный директор</span><input value={draft.generalDirector ?? ''} onChange={(e) => update('generalDirector', e.target.value)} /></label>
          <label className="wide"><span>Юридический адрес</span><input value={draft.legalAddress ?? ''} onChange={(e) => update('legalAddress', e.target.value)} /></label>
          <label className="wide"><span>Фактический адрес</span><input value={draft.actualAddress ?? ''} onChange={(e) => update('actualAddress', e.target.value)} /></label>
          <label className="wide"><span>Почтовый адрес</span><input value={draft.postalAddress ?? ''} onChange={(e) => update('postalAddress', e.target.value)} /></label>
          <label><span>Источник</span><input value={draft.source ?? ''} onChange={(e) => update('source', e.target.value)} /></label>
          <label className="wide"><span>Примечание</span><textarea value={draft.notes ?? ''} onChange={(e) => update('notes', e.target.value)} rows={3} /></label>
        </div>
        <div className="directory-editor-actions">
          <button type="button" onClick={onClose}>Отмена</button>
          <button className="primary" disabled={saving} type="button" onClick={async () => { setSaving(true); try { await onSave(draft); } finally { setSaving(false); } }}>{saving ? 'Сохраняю…' : 'Сохранить'}</button>
        </div>
      </section>
    </div>
  );
}

export function LegalEntitiesPanel({ records, search, editorHeaders, onReload, onError }: Props) {
  const [editor, setEditor] = useState<LegalEntityMaster | null>(null);
  const needle = clean(search);
  const filtered = useMemo(() => records.filter((record) => !needle || [
    record.name,
    record.fullName,
    record.inn,
    record.kpp,
    record.ogrn,
    record.generalDirector,
    record.legalAddress,
    record.actualAddress,
  ].some((value) => clean(value).includes(needle))), [records, needle]);

  const save = async (draft: LegalEntityMaster) => {
    const headers = editorHeaders();
    if (!headers) return;
    const isNew = !draft.id;
    const response = await fetch(
      isNew ? '/api/phonebook/legal-entities' : `/api/phonebook/legal-entities/${encodeURIComponent(draft.id)}`,
      { method: isNew ? 'POST' : 'PUT', headers, body: JSON.stringify(draft) },
    );
    if (!response.ok) { onError(await readError(response)); return; }
    setEditor(null);
    await onReload();
  };

  const archive = async (record: LegalEntityMaster) => {
    if (!window.confirm(`Убрать ${record.name}${record.inn ? ` · ИНН ${record.inn}` : ''} из активного справочника? История и связи сохранятся.`)) return;
    const headers = editorHeaders();
    if (!headers) return;
    const response = await fetch(`/api/phonebook/legal-entities/${encodeURIComponent(record.id)}`, { method: 'DELETE', headers });
    if (!response.ok) { onError(await readError(response)); return; }
    await onReload();
  };

  return (
    <>
      <section className="directory-group">
        <div className="directory-group-title"><strong>Реквизиты юридических лиц</strong><span>{filtered.length}</span></div>
        <div className="directory-table-scroll"><table className="directory-table">
          <thead><tr><th>Юридическое лицо</th><th>ИНН / КПП</th><th>ОГРН</th><th>Генеральный директор</th><th>Фактический адрес</th><th>Статус</th><th /></tr></thead>
          <tbody>{filtered.map((record) => <tr key={record.id}>
            <td><strong>{record.name}</strong>{record.fullName && record.fullName !== record.name ? <small>{record.fullName}</small> : null}</td>
            <td>{record.inn || '—'}{record.kpp ? <small>КПП {record.kpp}</small> : null}</td>
            <td>{record.ogrn || '—'}</td>
            <td>{record.generalDirector || '—'}</td>
            <td>{record.actualAddress || '—'}</td>
            <td>{record.verificationStatus === 'VERIFIED' ? 'Проверено' : record.verificationStatus === 'NEEDS_REVIEW' ? 'Требует проверки' : 'Не проверено'}</td>
            <td className="directory-actions"><button className="directory-icon-button" type="button" title="Редактировать" onClick={() => setEditor(record)}><Pencil size={16} /></button><button className="directory-icon-button danger" type="button" title="Архивировать" onClick={() => void archive(record)}><Trash2 size={16} /></button></td>
          </tr>)}</tbody>
        </table></div>
        {filtered.length === 0 ? <div className="directory-empty">Организации не найдены.</div> : null}
      </section>
      <button className="directory-primary-button" type="button" onClick={() => setEditor(blankLegalEntity())}><Plus size={17} /> Добавить организацию</button>
      {editor && <LegalEntityEditor record={editor} onClose={() => setEditor(null)} onSave={save} />}
    </>
  );
}
