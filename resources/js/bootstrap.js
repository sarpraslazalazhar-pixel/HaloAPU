import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import toast from 'react-hot-toast';
import {
  COOKIE_BLOCKED_ERROR_MESSAGE,
  handleSessionExpiredReload,
} from './utils/sessionRecovery';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.axios.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error?.response?.status === 419) {
      const reloaded = handleSessionExpiredReload();

      if (!reloaded) {
        toast.error(COOKIE_BLOCKED_ERROR_MESSAGE, {
          id: 'session-cookie-error',
          duration: 6000,
        });
      }
    }

    return Promise.reject(error);
  }
);

window.Pusher = Pusher;

window.Echo = null;

// The server renders `window.__realtime` from the broadcast config, so realtime
// needs no VITE_* build-time variables. Vite does not expand `${VAR}` in .env,
// so a build-time value could bake a literal placeholder into the bundle.
const realtime = window.__realtime;

if (realtime?.key) {
  const options = {
    broadcaster: realtime.broadcaster,
    key: realtime.key,
    forceTLS: (realtime.scheme ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
  };

  if (realtime.broadcaster === 'reverb') {
    options.wsHost = realtime.host ?? window.location.hostname;
    options.wsPort = Number(realtime.port ?? 8080);
    options.wssPort = Number(realtime.port ?? 443);
  } else {
    options.cluster = realtime.cluster ?? 'mt1';
  }

  window.Echo = new Echo(options);

  // Echo's bundled axios interceptor never registers in ESM builds (it reads an
  // undeclared module-scope `axios`), so `->toOthers()` would echo to this tab.
  axios.interceptors.request.use((config) => {
    const socketId = window.Echo?.socketId();

    if (socketId) {
      config.headers['X-Socket-Id'] = socketId;
    }

    return config;
  });
}
