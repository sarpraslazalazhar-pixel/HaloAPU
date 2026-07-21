import React from 'react';
import { Clock, User } from 'lucide-react';
import { formatDateId } from '@/lib/utils';

import { TicketAttachmentList } from './TicketAttachmentList';

interface TimelineLog {
    id: number;
    aksi: string;
    catatan?: string | null;
    timestamp: string;
    admin?: { username: string } | null;
    attachments?: any[];
}

interface TicketTimelineProps {
    logs: TimelineLog[];
    downloadRoute?: string;
}

export function TicketTimeline({ logs, downloadRoute = 'tiket.download' }: TicketTimelineProps) {
    if (!logs?.length) return null;

    return (
        <div className="space-y-4 border-l-2 border-slate-200 ml-3 pl-4">
            {logs.map((log) => (
                <div key={log.id} className="relative">
                    <div className="absolute -left-[23px] top-1 w-3 h-3 bg-blue-500 rounded-full border-2 border-white" />
                    <div className="flex items-center gap-2 mb-1">
                        <p className="text-sm font-semibold capitalize">{log.aksi}</p>
                        {log.admin && (
                            <span className="text-xs text-slate-400 flex items-center gap-1">
                                <User className="w-3 h-3" /> {log.admin.username}
                            </span>
                        )}
                    </div>
                    {log.catatan && <p className="text-sm text-slate-600">{log.catatan}</p>}
                    
                    {log.attachments && log.attachments.length > 0 && (
                        <div className="mt-2">
                            <TicketAttachmentList attachments={log.attachments} downloadRoute={downloadRoute} />
                        </div>
                    )}

                    <p className="text-xs text-slate-400 mt-1">{formatDateId(log.timestamp)}</p>
                </div>
            ))}
        </div>
    );
}
