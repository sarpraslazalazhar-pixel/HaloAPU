import { z } from 'zod';

export const SESSION_EXPIRED_ALERT_KEY = 'session_expired_alert';

const LAST_419_RELOAD_AT_KEY = 'last_419_reload_at';

const RELOAD_LOOP_GUARD_MS = 5000;

export const COOKIE_BLOCKED_ERROR_MESSAGE =
  'Sesi atau cookie browser tidak dapat dimuat. Pastikan cookie diizinkan lalu muat ulang halaman.';

let isReloading = false;

export interface RateLimitHeaders {
  'retry-after'?: string | number;
  'Retry-After'?: string | number;
}

export interface RateLimitResponse {
  status?: number;
  data?: object | string;
  headers?: RateLimitHeaders;
}

const rateLimitPayloadSchema = z.object({
  message: z.string().optional(),
});

export function isSessionReloadInProgress(): boolean {
  return isReloading;
}

export function setSessionExpiredAlert(message?: string): void {
  if (typeof window === 'undefined') {
    return;
  }

  try {
    window.sessionStorage.setItem(
      SESSION_EXPIRED_ALERT_KEY,
      message ?? getSessionExpiredMessage(window.location.pathname)
    );
  } catch {
    // Ignore storage errors in restricted browser contexts.
  }
}

export function isLoginPathname(pathname: string): boolean {
  return pathname === '/login' || pathname === '/admin/login';
}

export function getSessionExpiredMessage(pathname: string): string {
  if (isLoginPathname(pathname)) {
    return 'Halaman login kedaluwarsa. Halaman dimuat ulang agar Anda bisa langsung login kembali.';
  }

  return 'Sesi kamu telah berakhir karena batas waktu tidak aktif. Silakan login kembali.';
}

/**
 * Reloads once on HTTP 419 while guarding against concurrent 419s and
 * tight reload loops (e.g. when browser cookies are blocked).
 */
export function handleSessionExpiredReload(): boolean {
  if (typeof window === 'undefined') {
    return false;
  }

  if (isReloading) {
    return true;
  }

  try {
    const now = Date.now();
    const lastReloadRaw = window.sessionStorage.getItem(LAST_419_RELOAD_AT_KEY);
    const lastReloadAt = lastReloadRaw ? Number(lastReloadRaw) : 0;

    if (
      Number.isFinite(lastReloadAt) &&
      lastReloadAt > 0 &&
      now - lastReloadAt < RELOAD_LOOP_GUARD_MS
    ) {
      return false;
    }

    isReloading = true;

    window.sessionStorage.setItem(LAST_419_RELOAD_AT_KEY, String(now));

    window.sessionStorage.setItem(
      SESSION_EXPIRED_ALERT_KEY,
      getSessionExpiredMessage(window.location.pathname)
    );

    window.location.reload();

    return true;
  } catch {
    return false;
  }
}

export function consumeSessionExpiredAlert(): string | null {
  if (typeof window === 'undefined') {
    return null;
  }

  try {
    const alertMessage = window.sessionStorage.getItem(SESSION_EXPIRED_ALERT_KEY);

    if (alertMessage) {
      window.sessionStorage.removeItem(SESSION_EXPIRED_ALERT_KEY);
    }

    return alertMessage;
  } catch {
    return null;
  }
}

/**
 * Mobile Safari/Chrome can restore a login page from bfcache with a stale CSRF token.
 * Reloading on persisted pageshow guarantees a fresh CSRF cookie and meta token.
 */
export function registerBfcacheReload(): () => void {
  if (typeof window === 'undefined') {
    return () => {};
  }

  const handlePageShow = (event: PageTransitionEvent) => {
    if (event.persisted) {
      window.location.reload();
    }
  };

  window.addEventListener('pageshow', handlePageShow);

  return () => {
    window.removeEventListener('pageshow', handlePageShow);
  };
}

export function extractRateLimitMessage(response?: RateLimitResponse): string {
  const parsed = rateLimitPayloadSchema.safeParse(response?.data);

  const dataMessage = parsed.success ? (parsed.data.message?.trim() ?? '') : '';

  if (dataMessage !== '') {
    return dataMessage;
  }

  const rawRetryAfter =
    response?.headers?.['retry-after'] ?? response?.headers?.['Retry-After'];

  const retrySeconds = Number(rawRetryAfter);

  if (Number.isFinite(retrySeconds) && retrySeconds > 0) {
    return `Terlalu banyak percobaan. Silakan coba lagi dalam ${Math.ceil(retrySeconds)} detik.`;
  }

  return 'Terlalu banyak percobaan. Silakan tunggu beberapa saat sebelum mencoba lagi.';
}
