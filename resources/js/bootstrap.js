import axios from 'axios';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.axios.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error?.response?.status === 419) {
      sessionStorage.setItem(
        'session_expired_alert',
        'Sesi Anda telah berakhir karena batas waktu tidak aktif (3 jam). Silakan login kembali.'
      );
      window.location.reload();
    }

    return Promise.reject(error);
  }
);

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const PusherClass = Pusher?.default || Pusher;

window.Pusher = PusherClass;

const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;

if (pusherKey) {
  try {
    window.Echo = new Echo({
      broadcaster: 'pusher',
      key: pusherKey,
      cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
      wsHost: import.meta.env.VITE_PUSHER_HOST ? import.meta.env.VITE_PUSHER_HOST : `ws-${import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1'}.pusher.com`,
      wsPort: import.meta.env.VITE_PUSHER_PORT ?? 80,
      wssPort: import.meta.env.VITE_PUSHER_PORT ?? 443,
      forceTLS: (import.meta.env.VITE_PUSHER_SCHEME ?? 'https') === 'https',
      enabledTransports: ['ws', 'wss'],
    });
  } catch (e) {
    console.warn('Echo initialization skipped / failed:', e);
    window.Echo = null;
  }
} else {
  window.Echo = null;
}


