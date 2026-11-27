import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';
import { subscribe, type TicketEvents, type TicketLogEntry } from '@/lib/realtime';

interface TicketPollResponse {
  status: string;
  assigned_admin_id: number | null;
  updated_at?: string | null;
  logs: TicketLogEntry[];
}

interface UseTicketRealtimeOptions {
  ticketId?: number | null;
  initialLogs?: TicketLogEntry[];
  initialStatus?: string | null;
  initialAssignedAdminId?: number | null;
  pollUrl?: string | null;
  enabled?: boolean;
  subscribeChannel?: boolean;
  onMetaChanged?: () => void;
}

const POLL_INTERVAL_MS = 3000;

function computeLogsSignature(logs: TicketLogEntry[]): string {
  return [...logs]
    .sort((a, b) => a.id - b.id)
    .map(
      (item) =>
        `${item.id}|${item.catatan ?? ''}|${Boolean(item.is_edited)}|${item.edited_at ?? ''}|${item.attachments?.length ?? 0}`
    )
    .join(';;');
}

function isWebSocketChannelReady(isSubscribed: boolean): boolean {
  return Boolean(window.Echo?.socketId()) && isSubscribed;
}

export function useTicketRealtime({
  ticketId,
  initialLogs,
  initialStatus = null,
  initialAssignedAdminId = null,
  pollUrl = null,
  enabled = true,
  subscribeChannel = true,
  onMetaChanged,
}: UseTicketRealtimeOptions) {
  const [liveLogs, setLiveLogs] = useState<TicketLogEntry[]>(() => initialLogs ?? []);

  const liveLogsRef = useRef<TicketLogEntry[]>(liveLogs);
  liveLogsRef.current = liveLogs;

  const statusRef = useRef<string | null>(initialStatus);
  statusRef.current = initialStatus;

  const assignedAdminIdRef = useRef<number | null>(initialAssignedAdminId);
  assignedAdminIdRef.current = initialAssignedAdminId;

  const onMetaChangedRef = useRef(onMetaChanged);
  onMetaChangedRef.current = onMetaChanged;

  const pollEpochRef = useRef(0);
  const isSubscribedRef = useRef(false);
  const inFlightRef = useRef(false);
  const pollAbortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    pollEpochRef.current += 1;
    setLiveLogs(initialLogs ?? []);
  }, [initialLogs]);

  const applyEditedLog = useCallback((updatedLog: TicketLogEntry) => {
    pollEpochRef.current += 1;
    setLiveLogs((prev) => prev.map((item) => (item.id === updatedLog.id ? { ...item, ...updatedLog } : item)));
  }, []);

  const pollOnce = useCallback(async () => {
    if (!enabled || !ticketId || !pollUrl || inFlightRef.current) {
      return;
    }

    if (typeof document !== 'undefined' && document.hidden) {
      return;
    }

    const requestEpoch = pollEpochRef.current;

    if (pollAbortRef.current) {
      pollAbortRef.current.abort();
    }

    const controller = new AbortController();

    pollAbortRef.current = controller;
    inFlightRef.current = true;

    try {
      const response = await axios.get<TicketPollResponse>(pollUrl, {
        signal: controller.signal,
      });

      if (controller.signal.aborted || requestEpoch !== pollEpochRef.current) {
        return;
      }

      const nextLogs = response.data.logs ?? [];
      const nextStatus = response.data.status;
      const nextAssignedAdminId = response.data.assigned_admin_id ?? null;

      const currentLogs = liveLogsRef.current;
      const logsChanged = computeLogsSignature(currentLogs) !== computeLogsSignature(nextLogs);

      const previousIds = new Set(currentLogs.map((item) => item.id));

      const hasNewSystemLog = nextLogs.some(
        (item) => !previousIds.has(item.id) && (item.aksi || '').toLowerCase() !== 'balasan'
      );

      const statusChanged = statusRef.current !== null && nextStatus !== statusRef.current;
      const assigneeChanged = (assignedAdminIdRef.current ?? null) !== nextAssignedAdminId;

      if (logsChanged) {
        setLiveLogs(nextLogs);
      }

      if (statusChanged || assigneeChanged || hasNewSystemLog) {
        statusRef.current = nextStatus;
        assignedAdminIdRef.current = nextAssignedAdminId;
        onMetaChangedRef.current?.();
      }
    } catch {
      // Aborted or transient network/server poll failure; next interval or Echo event recovers cleanly.
    } finally {
      if (pollAbortRef.current === controller) {
        pollAbortRef.current = null;
      }

      inFlightRef.current = false;
    }
  }, [enabled, ticketId, pollUrl]);

  useEffect(() => {
    if (!enabled || !ticketId) {
      isSubscribedRef.current = false;

      return;
    }

    isSubscribedRef.current = false;

    const unsubscribe = subscribeChannel
      ? subscribe<TicketEvents>(`ticket.${ticketId}`, {
          '.pusher:subscription_succeeded': () => {
            isSubscribedRef.current = true;
            void pollOnce();
          },
          '.pusher:subscription_error': () => {
            isSubscribedRef.current = false;
          },
          '.TicketMessagePosted': ({ log }) => {
            if (!log) return;

            pollEpochRef.current += 1;
            setLiveLogs((prev) => (prev.some((item) => item.id === log.id) ? prev : [...prev, log]));

            if ((log.aksi || '').toLowerCase() !== 'balasan') {
              onMetaChangedRef.current?.();
            }
          },
          '.TicketMessageEdited': ({ log }) => {
            if (!log) return;

            pollEpochRef.current += 1;
            setLiveLogs((prev) => prev.map((item) => (item.id === log.id ? { ...item, ...log } : item)));
          },
        })
      : () => {};

    const intervalId = window.setInterval(() => {
      if (typeof document !== 'undefined' && document.hidden) {
        return;
      }

      if (inFlightRef.current || (subscribeChannel && isWebSocketChannelReady(isSubscribedRef.current))) {
        return;
      }

      void pollOnce();
    }, POLL_INTERVAL_MS);

    const handleVisibilityChange = () => {
      if (typeof document !== 'undefined' && !document.hidden) {
        void pollOnce();
      }
    };

    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      unsubscribe();
      window.clearInterval(intervalId);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
      isSubscribedRef.current = false;

      if (pollAbortRef.current) {
        pollAbortRef.current.abort();
        pollAbortRef.current = null;
      }
    };
  }, [enabled, subscribeChannel, ticketId, pollOnce]);

  return {
    liveLogs,
    applyEditedLog,
  };
}
