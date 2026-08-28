import { createRoot } from 'react-dom/client';

import App from './App';
import { ErrorBoundary } from '@/components/error-boundary';
import { DirectoryPreview } from './pages/DirectoryPreview';

import './index.css';

const content = window.location.pathname.startsWith('/directory') ? <DirectoryPreview /> : <App />;

createRoot(document.getElementById('root')!, {
  // Keeps caught errors off reportError(), which would raise the dev overlay.
  onCaughtError: (error, errorInfo) => {
    console.error(error, errorInfo.componentStack);
  },
}).render(
  <ErrorBoundary>
    {content}
  </ErrorBoundary>,
);
