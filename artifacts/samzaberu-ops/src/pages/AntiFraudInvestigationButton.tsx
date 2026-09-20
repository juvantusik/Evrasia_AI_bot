import { useState } from 'react';
import { createPortal } from 'react-dom';
import { SearchCheck, X } from 'lucide-react';
import './anti-fraud-investigation.css';

type Investigation = {
  investigationId: string;
  bitrixUserId: number;
  source: string;
  reason: string | null;
  status: string;
};

type Props = {
  disabled?: boolean;
  onAccepted?: () => void | Promise<void>;
};

const postInvestigation = async (input: {
  phone: string;
  source: string;
  reason: string | null;
}): Promise<Investigation> => {
  const response = await fetch('/api/anti-fraud/investigations', {
    method: 'POST',
    headers: { accept: 'application/json', 'content-type': 'application/json' },
    body: JSON.stringify(input),
  });
  let payload: any = null;
  try { payload = await response.json(); } catch { /* optional */ }
  if (!response.ok) {
    throw new Error(
      typeof payload?.error === 'string'
        ? payload.error
        : 'Не удалось добавить аккаунт на проверку.',
    );
  }
  if (!payload?.investigation || typeof payload.investigation.investigationId !== 'string') {
    throw new Error('Anti-Fraud вернул некорректный ответ при добавлении проверки.');
  }
  return payload.investigation as Investigation;
};

export default function AntiFraudInvestigationButton({ disabled = false, onAccepted }: Props) {
  const [open, setOpen] = useState(false);
  const [phone, setPhone] = useState('');
  const [source, setSource] = useState('Авито');
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [accepted, setAccepted] = useState<Investigation | null>(null);

  const reset = () => {
    setPhone('');
    setSource('Авито');
    setReason('');
    setError('');
    setAccepted(null);
  };

  const close = () => {
    if (submitting) return;
    setOpen(false);
    reset();
  };

  const submit = async () => {
    if (!phone.trim()) {
      setError('Введите номер телефона.');
      return;
    }
    if (!source.trim()) {
      setError('Укажите источник проверки.');
      return;
    }

    setSubmitting(true);
    setError('');
    try {
      const investigation = await postInvestigation({
        phone: phone.trim(),
        source: source.trim(),
        reason: reason.trim() || null,
      });
      setAccepted(investigation);
      await onAccepted?.();
    } catch (submitError) {
      setError(
        submitError instanceof Error
          ? submitError.message
          : 'Не удалось добавить аккаунт на проверку.',
      );
    } finally {
      setSubmitting(false);
    }
  };

  const dialog = open && typeof document !== 'undefined' ? createPortal(
    <div className="af-investigation-overlay" role="presentation" onMouseDown={(event) => {
      if (event.target === event.currentTarget) close();
    }}>
      <section
        className="af-investigation-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="af-investigation-title"
      >
        <div className="af-investigation-head">
          <div>
            <strong id="af-investigation-title">Добавить на проверку</strong>
            <span>Ручная Anti-Fraud проверка по номеру телефона</span>
          </div>
          <button
            type="button"
            className="af-investigation-close"
            onClick={close}
            disabled={submitting}
            aria-label="Закрыть"
          >
            <X size={18} />
          </button>
        </div>

        {accepted ? (
          <div className="af-investigation-success">
            <strong>Проверка добавлена</strong>
            <span>
              ID {accepted.bitrixUserId} · источник: {accepted.source}. Система загрузит
              60-дневную историю и выполнит обычный Anti-Fraud scoring.
            </span>
            <small>Автоматическая блокировка не выполняется.</small>
            <div className="af-investigation-actions">
              <button type="button" className="af-investigation-primary" onClick={close}>
                Готово
              </button>
            </div>
          </div>
        ) : (
          <>
            <label className="af-investigation-field">
              <span>Телефон</span>
              <input
                type="tel"
                inputMode="tel"
                autoComplete="off"
                placeholder="+7 999 123-45-67"
                value={phone}
                onChange={(event) => setPhone(event.target.value)}
                disabled={submitting}
              />
              <small>USER_ID определяется сервером. При неоднозначном номере проверка не создаётся.</small>
            </label>

            <label className="af-investigation-field">
              <span>Источник</span>
              <input
                type="text"
                maxLength={80}
                value={source}
                onChange={(event) => setSource(event.target.value)}
                disabled={submitting}
              />
              <small>Например: Авито. Источник хранится отдельно от автоматических сигналов риска.</small>
            </label>

            <label className="af-investigation-field">
              <span>Комментарий <em>необязательно</em></span>
              <textarea
                maxLength={500}
                rows={3}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                disabled={submitting}
                placeholder="Что именно вызвало подозрение"
              />
            </label>

            {error ? <div className="af-investigation-error">{error}</div> : null}

            <div className="af-investigation-note">
              Добавление на проверку не блокирует аккаунт и не подменяет автоматические причины Risk.
            </div>

            <div className="af-investigation-actions">
              <button type="button" className="af-investigation-cancel" onClick={close} disabled={submitting}>
                Отмена
              </button>
              <button
                type="button"
                className="af-investigation-primary"
                onClick={() => void submit()}
                disabled={submitting || !phone.trim() || !source.trim()}
              >
                {submitting ? 'Добавляем…' : 'Добавить на проверку'}
              </button>
            </div>
          </>
        )}
      </section>
    </div>,
    document.body,
  ) : null;

  return <>
    <button
      className="af-investigation-button"
      type="button"
      onClick={() => { reset(); setOpen(true); }}
      disabled={disabled}
    >
      <SearchCheck size={17} /> Добавить на проверку
    </button>
    {dialog}
  </>;
}
