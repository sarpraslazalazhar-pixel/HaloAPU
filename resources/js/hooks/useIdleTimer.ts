import { useEffect, useRef } from 'react';
import axios from 'axios';
import {
  isSessionReloadInProgress,
  setSessionExpiredAlert,
} from '../utils/sessionRecovery';

const IDLE_EXPIRED_MESSAGE =
  'Sesi kamu telah berakhir karena batas waktu tidak aktif. Silakan login kembali.';

export function useIdleTimer(logoutUrl: string, timeout = 30 * 60 * 1000, enabled = true) {
  const lastActiveRef = useRef<number>(Date.now());
  const hasLoggedOutRef = useRef<boolean>(false);

  useEffect(() => {
    if (!enabled) {
      return;
    }

    lastActiveRef.current = Date.now();
    hasLoggedOutRef.current = false;

    const loginUrl = logoutUrl.startsWith('/admin') ? '/admin/login' : '/login';

    const redirectToLoginWithAlert = () => {
      setSessionExpiredAlert(IDLE_EXPIRED_MESSAGE);

      window.location.assign(loginUrl);
    };

    const handleActivity = () => {
      if (hasLoggedOutRef.current) {
        return;
      }

      lastActiveRef.current = Date.now();
    };

    const checkAndLogoutIfIdle = () => {
      if (hasLoggedOutRef.current) {
        return;
      }

      if (Date.now() - lastActiveRef.current >= timeout) {
        hasLoggedOutRef.current = true;

        if (!window.axios) {
          redirectToLoginWithAlert();

          return;
        }

        window.axios
          .post(logoutUrl, {}, { timeout: 5000 })
          .then(() => {
            redirectToLoginWithAlert();
          })
          .catch((error) => {
            if (
              axios.isAxiosError(error) &&
              error.response?.status === 419 &&
              isSessionReloadInProgress()
            ) {
              return;
            }

            redirectToLoginWithAlert();
          });
      }
    };

    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        checkAndLogoutIfIdle();
      }
    };

    const interval = setInterval(checkAndLogoutIfIdle, 15000);
    const events: string[] = ['mousedown', 'keydown', 'scroll', 'touchstart'];

    events.forEach((event) => {
      window.addEventListener(event, handleActivity, { passive: true });
    });

    document.addEventListener('visibilitychange', handleVisibilityChange);
    window.addEventListener('focus', checkAndLogoutIfIdle);

    return () => {
      clearInterval(interval);

      events.forEach((event) => {
        window.removeEventListener(event, handleActivity);
      });

      document.removeEventListener('visibilitychange', handleVisibilityChange);
      window.removeEventListener('focus', checkAndLogoutIfIdle);
    };
  }, [logoutUrl, timeout, enabled]);
}
