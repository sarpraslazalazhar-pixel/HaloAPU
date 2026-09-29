import { useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';

export function useIdleTimer(logoutUrl: string, timeout = 30 * 60 * 1000) { // Default 30 mins
  const lastActiveRef = useRef<number>(Date.now());

  useEffect(() => {
    const handleActivity = () => {
      lastActiveRef.current = Date.now();
    };

    const interval = setInterval(() => {
      if (Date.now() - lastActiveRef.current >= timeout) {
        router.post(logoutUrl);
      }
    }, 15000); // Check idle every 15s instead of resetting timers on every mouse event

    const events = ['mousedown', 'keydown', 'scroll', 'touchstart'];
    events.forEach((event) => {
      window.addEventListener(event, handleActivity, { passive: true });
    });

    return () => {
      clearInterval(interval);
      events.forEach((event) => {
        window.removeEventListener(event, handleActivity);
      });
    };
  }, [logoutUrl, timeout]);
}
