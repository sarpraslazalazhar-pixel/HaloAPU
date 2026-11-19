import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowDown, ArrowUp, ListOrdered, Loader2, RefreshCw, UsersRound } from 'lucide-react';
import { StatusBadge } from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import { subscribe } from '@/lib/realtime';

export interface ActiveTicket {
  id: number;
  formatted_id: string;
  service: string;
  status: string;
  urgent: boolean;
  position: number | null;
}

export interface ActiveTicketPayload {
  active: ActiveTicket | null;
  queue: ActiveTicket[];
}

interface Props {
  adminId: number;
  initialPayload: ActiveTicketPayload | null;
}

interface ActiveTicketEvents {
  '.ActiveAssignedTicketChanged': Record<string, never>;
  '.pusher:subscription_succeeded': Record<string, never>;
}

const EMPTY: ActiveTicketPayload = { active: null, queue: [] };

export default function ActiveAssignedTicket({ adminId, initialPayload }: Props) {
  const { auth } = usePage<any>().props;
  const canSetUrgent = Boolean(auth?.permissions?.includes('akses-assign-operator'));

  const [payload, setPayload] = useState<ActiveTicketPayload>(initialPayload ?? EMPTY);
  const [open, setOpen] = useState(false);
  const [stale, setStale] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const inFlight = useRef<AbortController | null>(null);
  const mounted = useRef(true);

  const refresh = useCallback(async () => {
    inFlight.current?.abort();
    const controller = new AbortController();
    inFlight.current = controller;

    try {
      const response = await fetch(route('admin.tiket.aktif'), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      });

      if (!mounted.current) return;

      // A login redirect or a revoked session answers HTML, not JSON.
      if (!response.ok || !(response.headers.get('content-type') ?? '').includes('application/json')) {
        if (response.status === 401 || response.status === 403) setPayload(EMPTY);
        setStale(true);
        return;
      }

      const body = await response.json();
      if (!mounted.current) return;

      setPayload({ active: body.data?.active ?? null, queue: body.data?.queue ?? [] });
      setStale(false);
    } catch (error) {
      if (!mounted.current || (error as Error)?.name === 'AbortError') return;
      setStale(true);
    }
  }, []);

  useEffect(() => {
    mounted.current = true;

    const unsubscribe = subscribe<ActiveTicketEvents>(`App.Models.Admin.${adminId}`, {
      '.ActiveAssignedTicketChanged': () => void refresh(),
      '.pusher:subscription_succeeded': () => void refresh(),
    });

    const onVisible = () => {
      if (document.visibilityState === 'visible') void refresh();
    };

    document.addEventListener('visibilitychange', onVisible);
    void refresh();

    // Shared hosting (cPanel) has no Reverb, so fall back to a slow poll.
    // 20s keeps the card usable without hammering a small PHP worker pool.
    const poll = window.Echo ? null : window.setInterval(() => {
      if (!document.hidden) void refresh();
    }, 20000);

    return () => {
      mounted.current = false;
      inFlight.current?.abort();
      if (poll !== null) window.clearInterval(poll);
      document.removeEventListener('visibilitychange', onVisible);
      unsubscribe();
    };
  }, [adminId, refresh]);

  const move = (ticket: ActiveTicket, direction: 'up' | 'down') => {
    setBusyId(ticket.id);
    router.patch(
      route('admin.tiket.queue-move', ticket.id),
      { direction },
      {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => void refresh(),
        onFinish: () => setBusyId(null),
      },
    );
  };

  const setUrgent = (ticket: ActiveTicket, urgent: boolean) => {
    setBusyId(ticket.id);
    router.patch(
      route('admin.tiket.priority', ticket.id),
      { priority: urgent ? 'Urgen' : 'Sedang' },
      {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => void refresh(),
        onFinish: () => setBusyId(null),
      },
    );
  };

  const total = (payload.active ? 1 : 0) + payload.queue.length;

  if (total === 0 && !stale) return null;

  const rows = payload.active ? [payload.active, ...payload.queue] : payload.queue;

  return (
    <div className="fixed right-4 bottom-[calc(80px+env(safe-area-inset-bottom,0px))] z-40 flex flex-col items-end gap-2 md:bottom-6">
      {open && total > 0 && (
        <div className="w-[min(22rem,calc(100vw-2rem))] rounded-xl bg-card p-3 text-sm shadow-lg ring-1 ring-foreground/10">
          <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold text-muted-foreground">
            <ListOrdered className="size-3.5" /> Antrean Pekerjaan Anda
          </p>
          <ul className="flex max-h-[50vh] flex-col gap-2 overflow-y-auto overscroll-contain">
            {rows.map((ticket) => (
              <li key={ticket.id} className="rounded-lg border p-2">
                <div className="flex items-center justify-between gap-2">
                  <span className="font-mono text-sm font-semibold">#TKT-{ticket.formatted_id}</span>
                  <StatusBadge status={ticket.status} />
                </div>
                <p className="truncate text-xs text-muted-foreground">{ticket.service}</p>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                  <span className="text-xs font-medium text-sky-700">
                    {ticket.position === null ? 'Sedang dikerjakan' : `Antrean ke-${ticket.position}`}
                  </span>
                  {ticket.urgent && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700">
                      <AlertTriangle className="size-3" /> Urgen
                    </span>
                  )}
                </div>
                <div className="mt-2 flex items-center gap-2">
                  <Link
                    href={route('admin.tiket.show', ticket.id)}
                    className="inline-flex min-h-11 items-center rounded-sm text-xs font-medium text-sky-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    Buka tiket
                  </Link>
                  {ticket.position !== null && (
                    <div className="flex items-center gap-1">
                      <Button
                        variant="outline"
                        size="icon-sm"
                        aria-label={`Naikkan antrean #TKT-${ticket.formatted_id}`}
                        disabled={busyId === ticket.id || ticket.position <= 1}
                        onClick={() => move(ticket, 'up')}
                      >
                        <ArrowUp className="size-3.5" />
                      </Button>
                      <Button
                        variant="outline"
                        size="icon-sm"
                        aria-label={`Turunkan antrean #TKT-${ticket.formatted_id}`}
                        disabled={busyId === ticket.id || ticket.position >= payload.queue.length}
                        onClick={() => move(ticket, 'down')}
                      >
                        <ArrowDown className="size-3.5" />
                      </Button>
                    </div>
                  )}
                  {canSetUrgent && (
                    <Button
                      variant={ticket.urgent ? 'outline' : 'secondary'}
                      size="sm"
                      className="ml-auto"
                      disabled={busyId === ticket.id}
                      onClick={() => setUrgent(ticket, !ticket.urgent)}
                    >
                      {busyId === ticket.id && <Loader2 className="size-3.5 animate-spin" />}
                      {ticket.urgent ? 'Batalkan urgen' : 'Tandai urgen'}
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
          {stale && <p className="mt-2 text-xs text-amber-700">Antrean belum tersinkron.</p>}
        </div>
      )}

      <div className="flex items-center gap-2">
        {stale && (
          <Button variant="outline" size="icon-sm" onClick={() => void refresh()} aria-label="Muat ulang antrean">
            <RefreshCw className="size-3.5" />
          </Button>
        )}
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          aria-expanded={open}
          aria-label={`Antrean pekerjaan, ${total} tiket`}
          className={cn(
            'relative flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg',
            'transition-transform hover:scale-105 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
          )}
        >
          <UsersRound className="size-6" aria-hidden="true" />
          <span className="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1 text-[11px] font-bold leading-none text-destructive-foreground ring-2 ring-background">
            {total > 99 ? '99+' : total}
          </span>
        </button>
      </div>

      <span className="sr-only" role="status" aria-live="polite">
        {payload.active
          ? `Tiket aktif #TKT-${payload.active.formatted_id}. ${payload.queue.length} tiket menunggu giliran.`
          : `${payload.queue.length} tiket menunggu giliran.`}
      </span>
    </div>
  );
}
