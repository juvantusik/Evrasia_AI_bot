import { createRoot } from 'react-dom/client';
import { ErrorBoundary } from '@/components/error-boundary';
import { DirectoryPage } from './pages/DirectoryPage';
import './index.css';
import './pages/directory-web.css';

createRoot(document.getElementById('root')!, {
  onCaughtError: (error, errorInfo) => {
    console.error(error, errorInfo.componentStack);
  },
}).render(
  <ErrorBoundary>
    <DirectoryPage />
  </ErrorBoundary>,
);
