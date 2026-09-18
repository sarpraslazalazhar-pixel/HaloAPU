import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const appName = import.meta.env.VITE_APP_NAME || 'Halo APU';

// Auto-handle CSRF / Session Timeout (419 Token Mismatch)
// SAFETY: Inertia router emits 'invalid' event for non-Inertia HTTP responses such as 419 token mismatch.
router.on('invalid' as any, (event: any) => {
  if (event?.detail?.response?.status === 419) {
    event.preventDefault();
    sessionStorage.setItem(
      'session_expired_alert',
      'Sesi Anda telah berakhir karena batas waktu tidak aktif (3 jam). Silakan login kembali.'
    );
    window.location.reload();
  }
});

createInertiaApp({
 title: (title) =>`${title} - ${appName}`,
 // SAFETY: resolvePageComponent returns Promise<unknown>; Inertia's resolve callback requires the broader type, and the runtime value is always the correct React component.
 resolve: (name) =>
 resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx')) as any,
 setup({ el, App, props }: any) {
 const root = createRoot(el!);
 root.render(
 <App {...props} />
 );
 },
 progress: {
 color: '#2563eb',
 },
});
