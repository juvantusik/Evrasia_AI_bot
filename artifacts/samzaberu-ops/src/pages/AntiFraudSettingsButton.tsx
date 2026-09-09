import { useState } from 'react';
import { createPortal } from 'react-dom';
import { Settings, X } from 'lucide-react';
import './anti-fraud-settings.css';

type AntiFraudSettings = {
  bonusBalanceThreshold: number;
  defaultBonusBalanceThreshold: number;
};

type Props = {
  disabled?: boolean;
};

const loadSettings = async (): Promise<AntiFraudSettings> => {
  const response = await fetch('/api/anti-fraud/settings', { headers: { accept: 'application/json' } });
  if (!response.ok) {
    let message = 'Не удалось загрузить настройки Anti-Fraud.';
    try {
      const body = await response.json();
      if (typeof body?.error === 'string') message = body.error;
    } catch { /* optional */ }
    throw new Error(message);
  }
  return response.json() as Promise<AntiFraudSettings>;
};

const saveSettings = async (bonusBalanceThreshold: number): Promise<AntiFraudSettings> => {
  const response = await fetch('/api/anti-fraud/settings', {
    method: 'POST',
    headers: { accept: 'application/json', 'content-type': 'application/json' },
    body: JSON.stringify({ bonusBalanceThreshold }),
  });
  if (!response.ok) {
    let message = 'Не удалось сохранить настройки Anti-Fraud.';
    try {
      const body = await response.json();
      if (typeof body?.error === 'string') message = body.error;
    } catch { /* optional */ }
    throw new Error(message);
  }
  return response.json() as Promise<AntiFraudSettings>;
};

const formatThreshold = (value: number): string =>
  new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(value);

export default function AntiFraudSettingsButton({ disabled = false }: Props) {
  const [open, setOpen] = useState(false);
  const [settings, setSettings] = useState<AntiFraudSettings | null>(null);
  const [draft, setDraft] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const openSettings = async () => {
    setOpen(true);
    setError('');
    setNotice('');
    setLoading(true);
    try {
      const loaded = await loadSettings();
      setSettings(loaded);
      setDraft(String(loaded.bonusBalanceThreshold));
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : 'Не удалось загрузить настройки Anti-Fraud.');
    } finally {
      setLoading(false);
    }
  };

  const save = async () => {
    const value = Number(draft.replace(',', '.'));
    if (!Number.isFinite(value) || value < 0) {
      setError('Введите неотрицательное число бонусов.');
      return;
    }

    setSaving(true);
    setError('');
    setNotice('');
    try {
      const saved = await saveSettings(value);
      setSettings(saved);
      setDraft(String(saved.bonusBalanceThreshold));
      setNotice('Сохранено. Новый порог применится при следующем обновлении Anti-Fraud.');
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : 'Не удалось сохранить настройки Anti-Fraud.');
    } finally {
      setSaving(false);
    }
  };

  const dialog = open && typeof document !== 'undefined' ? createPortal(
    <div className="af-settings-overlay" role="presentation" onMouseDown={(event) => {
      if (event.target === event.currentTarget && !saving) setOpen(false);
    }}>
      <section className="af-settings-dialog" role="dialog" aria-modal="true" aria-labelledby="af-settings-title">
        <div className="af-settings-head">
          <div><strong id="af-settings-title">Настройка Anti-Fraud</strong><span>Параметры дополнительной проверки аккаунтов</span></div>
          <button type="button" className="af-settings-close" onClick={() => setOpen(false)} disabled={saving} aria-label="Закрыть"><X size={18} /></button>
        </div>

        {loading ? <div className="af-settings-loading">Загружаем настройки…</div> : <>
          <label className="af-settings-field">
            <span>Порог бонусного баланса</span>
            <input
              type="number"
              min="0"
              step="1000"
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              disabled={saving}
            />
            <small>Аккаунт с балансом <b>выше</b> этого значения получает +50 к риску и запускает адресную проверку истории за 60 дней.</small>
          </label>

          <div className="af-settings-current">
            <span>Текущее значение</span>
            <strong>{settings ? formatThreshold(settings.bonusBalanceThreshold) : '—'} бонусов</strong>
            {settings && settings.bonusBalanceThreshold === settings.defaultBonusBalanceThreshold
              ? <small>Значение по умолчанию</small>
              : <small>Изменено вручную · по умолчанию {settings ? formatThreshold(settings.defaultBonusBalanceThreshold) : '40 000'}</small>}
          </div>

          {error ? <div className="af-settings-error">{error}</div> : null}
          {notice ? <div className="af-settings-notice">{notice}</div> : null}

          <div className="af-settings-actions">
            <button type="button" className="af-settings-cancel" onClick={() => setOpen(false)} disabled={saving}>Закрыть</button>
            <button type="button" className="af-settings-save" onClick={() => void save()} disabled={saving || !draft.trim()}>{saving ? 'Сохраняем…' : 'Сохранить'}</button>
          </div>
        </>}
      </section>
    </div>,
    document.body,
  ) : null;

  return <>
    <button className="af-settings-button" type="button" onClick={() => void openSettings()} disabled={disabled}>
      <Settings size={17} /> Настройка
    </button>
    {dialog}
  </>;
}
