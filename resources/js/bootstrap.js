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

window.Pusher = Pusher;

window.Echo = new Echo({
  broadcaster: 'pusher',
  key: import.meta.env.VITE_PUSHER_APP_KEY,
  cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
  forceTLS: true
});

