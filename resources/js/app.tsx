import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import toast from 'react-hot-toast';
import {
  COOKIE_BLOCKED_ERROR_MESSAGE,
  extractRateLimitMessage,
  handleSessionExpiredReload,
} from './utils/sessionRecovery';

const rawAppName = import.meta.env.VITE_APP_NAME;

const appName =
  rawAppName && !rawAppName.includes('${') && rawAppName.trim() !== ''
    ? rawAppName.trim()
    : 'Halo APU';

// Auto-handle CSRF / Session Timeout (419) and Rate Limiting (429)
router.on('httpException', (event) => {
  const response = event.detail.response;
  const status = response.status;

  if (status === 419) {
    event.preventDefault();

    const reloaded = handleSessionExpiredReload();

    if (!reloaded) {
      toast.error(COOKIE_BLOCKED_ERROR_MESSAGE, {
        id: 'session-cookie-error',
        duration: 6000,
      });
    }

    return;
  }

  if (status === 429) {
    event.preventDefault();

    toast.error(extractRateLimitMessage(response), {
      id: 'rate-limit-toast',
      duration: 5000,
    });
  }
});

// Bersihkan inline background dari body saat navigasi SPA (misal setelah login)
router.on('navigate', () => {
  if (typeof document !== 'undefined') {
    document.body.style.backgroundImage = '';
    document.body.classList.remove('bg-cover', 'bg-center');
  }
});

createInertiaApp({
  title: (title) => {
    if (!title) return appName;

    if (title.includes(appName)) return title;

    return `${title} - ${appName}`;
  },
  // SAFETY: resolvePageComponent returns Promise<unknown>; Inertia's resolve callback requires the broader type, and the runtime value is always the correct React component.
  resolve: (name) =>
    resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx')) as any,
  setup({ el, App, props }: any) {
    const root = createRoot(el!);

    root.render(<App {...props} />);
  },
  progress: {
    color: '#2563eb',
  },
});
