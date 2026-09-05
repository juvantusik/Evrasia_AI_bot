import React from 'react';
import { createRoot } from 'react-dom/client';
import AntiFraudPage from './pages/AntiFraudPage';
import './pages/anti-fraud-web.css';
import './pages/anti-fraud-web-readable.css';

// Добавлено 03.09.2026 ИТ Директор Евразии
createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <AntiFraudPage />
  </React.StrictMode>,
);
