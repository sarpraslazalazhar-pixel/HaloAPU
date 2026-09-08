import React from 'react';
import { 
  Clock, User, FilePlus, UserCheck, PlayCircle, PauseCircle, 
  CheckCircle2, CheckCheck, XCircle, AlertTriangle, AlertCircle, 
  RefreshCw, MessageSquare, Paperclip, HelpCircle 
} from 'lucide-react';
import { formatDateId } from '@/lib/utils';
import { TicketAttachmentList } from './TicketAttachmentList';

interface TimelineLog {
  id: number;
  aksi: string;
  catatan?: string | null;
  timestamp: string;
  admin?: { username: string; name?: string } | null;
  attachments?: any[];
}

interface TicketTimelineProps {
  logs: TimelineLog[];
  downloadRoute?: string;
}

interface ActionConfig {
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  badgeClass: string;
  nodeBg: string;
}

const actionConfigMap: Record<string, ActionConfig> = {
  dibuat: {
    label: 'Tiket Dibuat',
    icon: FilePlus,
    badgeClass: 'bg-slate-100 text-slate-700 border-slate-200',
    nodeBg: 'bg-slate-500',
  },
  open: {
    label: 'Tiket Terbuka (Baru)',
    icon: Clock,
    badgeClass: 'bg-blue-100 text-blue-700 border-blue-200',
    nodeBg: 'bg-blue-500',
  },
  assign_operator: {
    label: 'Penugasan Operator',
    icon: UserCheck,
    badgeClass: 'bg-amber-100 text-amber-800 border-amber-200',
    nodeBg: 'bg-amber-500',
  },
  on_proses: {
    label: 'Tiket Diproses',
    icon: PlayCircle,
    badgeClass: 'bg-sky-100 text-sky-800 border-sky-200',
    nodeBg: 'bg-sky-500',
  },
  pending: {
    label: 'Tiket Ditunda',
    icon: PauseCircle,
    badgeClass: 'bg-orange-100 text-orange-800 border-orange-200',
    nodeBg: 'bg-orange-500',
  },
  solve: {
    label: 'Tiket Diselesaikan',
    icon: CheckCircle2,
    badgeClass: 'bg-emerald-100 text-emerald-800 border-emerald-200',
    nodeBg: 'bg-emerald-500',
  },
  accepted: {
    label: 'Hasil Tiket Diterima',
    icon: CheckCheck,
    badgeClass: 'bg-green-100 text-green-800 border-green-200',
    nodeBg: 'bg-green-500',
  },
  reject: {
    label: 'Tiket Ditolak',
    icon: XCircle,
    badgeClass: 'bg-rose-100 text-rose-800 border-rose-200',
    nodeBg: 'bg-rose-500',
  },
  dibatalkan: {
    label: 'Tiket Dibatalkan',
    icon: AlertTriangle,
    badgeClass: 'bg-gray-100 text-gray-700 border-gray-200',
    nodeBg: 'bg-gray-500',
  },
  need_revision: {
    label: 'Menunggu Revisi',
    icon: AlertCircle,
    badgeClass: 'bg-purple-100 text-purple-800 border-purple-200',
    nodeBg: 'bg-purple-500',
  },
  revisi: {
    label: 'Permintaan Revisi',
    icon: RefreshCw,
    badgeClass: 'bg-purple-100 text-purple-800 border-purple-200',
    nodeBg: 'bg-purple-500',
  },
  balasan: {
    label: 'Balasan / Informasi Tambahan',
    icon: MessageSquare,
    badgeClass: 'bg-indigo-100 text-indigo-800 border-indigo-200',
    nodeBg: 'bg-indigo-500',
  },
};

const getActionConfig = (aksi: string): ActionConfig => {
  const key = (aksi || '').toLowerCase();
  if (actionConfigMap[key]) {
    return actionConfigMap[key];
  }
  return {
    label: key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
    icon: HelpCircle,
    badgeClass: 'bg-slate-100 text-slate-700 border-slate-200',
    nodeBg: 'bg-slate-500',
  };
};

export function TicketTimeline({ logs, downloadRoute = 'tiket.download' }: TicketTimelineProps) {
  if (!logs?.length) return null;

  return (
    <div className="relative pl-6 sm:pl-8 space-y-6 before:absolute before:left-3 sm:before:left-4 before:top-3 before:bottom-3 before:w-0.5 before:bg-slate-200">
      {logs.map((log) => {
        const config = getActionConfig(log.aksi);
        const Icon = config.icon;

        return (
          <div key={log.id} className="relative group">
            {/* Timeline Node */}
            <div className={`absolute -left-6 sm:-left-8 top-1.5 w-6 h-6 rounded-full flex items-center justify-center text-white ring-4 ring-white shadow-sm ${config.nodeBg}`}>
              <Icon className="w-3.5 h-3.5" />
            </div>

            {/* Content Box */}
            <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-sm group-hover:border-slate-300 transition-colors">
              <div className="flex flex-wrap items-center justify-between gap-2 mb-2 pb-2 border-b border-slate-100">
                <div className="flex flex-wrap items-center gap-2">
                  <span className={`px-2.5 py-0.5 rounded-full text-xs font-semibold border ${config.badgeClass}`}>
                    {config.label}
                  </span>
                  {log.admin && (
                    <span className="text-xs text-slate-600 flex items-center gap-1 bg-slate-50 px-2 py-0.5 rounded border border-slate-200">
                      <User className="w-3 h-3 text-slate-400" />
                      <span>{log.admin.name || log.admin.username}</span>
                    </span>
                  )}
                </div>
                <div className="flex items-center gap-1.5 text-xs text-slate-400">
                  <Clock className="w-3.5 h-3.5" />
                  <span>{formatDateId(log.timestamp)}</span>
                </div>
              </div>

              {log.catatan && (
                <div className="text-sm text-slate-700 bg-slate-50/70 rounded-lg p-3 border border-slate-100 whitespace-pre-wrap leading-relaxed">
                  <p className="font-semibold text-xs text-slate-500 mb-1">Catatan:</p>
                  <p>{log.catatan}</p>
                </div>
              )}

              {log.attachments && log.attachments.length > 0 && (
                <div className="mt-3 pt-3 border-t border-slate-100">
                  <p className="text-xs font-semibold text-slate-600 mb-2 flex items-center gap-1.5">
                    <Paperclip className="w-3.5 h-3.5 text-blue-600" />
                    <span>Lampiran ({log.attachments.length})</span>
                  </p>
                  <TicketAttachmentList attachments={log.attachments} downloadRoute={downloadRoute} grouped={false} />
                </div>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}
