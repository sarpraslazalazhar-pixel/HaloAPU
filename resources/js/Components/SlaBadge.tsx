import React from 'react';
import { Badge } from '@/Components/ui/badge';
import {
 Tooltip,
 TooltipContent,
 TooltipProvider,
 TooltipTrigger,
} from '@/Components/ui/tooltip';
import { formatDateId } from '@/lib/utils';

interface SlaBadgeProps {
 priority?: string;
 isBreached: boolean;
 deadline: string | null;
 resolutionStartsAt?: string | null;
 resolutionBaseDeadline?: string | null;
 respondedAt: string | null;
 resolvedAt: string | null;
 pausedAt: string | null;
 totalPausedMinutes: number;
 ticketStatus?: string;
}

export default function SlaBadge({
 priority,
 isBreached,
 deadline,
 resolutionStartsAt,
 resolutionBaseDeadline,
 respondedAt,
 resolvedAt,
 pausedAt,
 totalPausedMinutes,
 ticketStatus,
}: SlaBadgeProps) {
 const tStatus = ticketStatus?.toLowerCase();
 const isPaused = pausedAt != null || tStatus === 'pending';
 const now = Math.floor(Date.now() / 1000) * 1000;
 const deadlineTime = deadline ? Date.parse(deadline) : null;
 const startTime = resolutionStartsAt ? Date.parse(resolutionStartsAt) : null;
 const hasNotStarted = startTime !== null && now < startTime;
 const pauseMinutes = resolutionBaseDeadline
 ? (deadlineTime === null ? 0 : Math.max(0, Math.floor((deadlineTime - Date.parse(resolutionBaseDeadline)) / 60000)))
 : totalPausedMinutes;

 const getStatus = () => {
 if (tStatus === 'dibatalkan' || tStatus === 'batal') return { label: 'Batal', color: 'bg-gray-500 text-white' };
 if (resolvedAt) return { label: 'Selesai', color: 'bg-[#0d6efd] text-white' };
 if (isBreached) return { label: 'Pelanggaran', color: 'bg-red-600 text-white' };
 if (isPaused) {
 return tStatus === 'pending'
 ? { label: 'Menunggu', color: 'bg-slate-100 text-slate-700' }
 : { label: 'Dijeda', color: 'bg-amber-100 text-amber-700' };
 }
 if (hasNotStarted) return { label: 'Belum Dimulai', color: 'bg-gray-500 text-white' };
 if (deadlineTime !== null && now > deadlineTime) return { label: 'Pelanggaran', color: 'bg-red-600 text-white' };
 return { label: 'Aman', color: 'bg-green-500 text-white' };
 };

 const status = getStatus();

 const formatSisaWaktu = () => {
 if (deadlineTime === null || resolvedAt || (isPaused && !pausedAt)) return '-';
 const reference = pausedAt ? Date.parse(pausedAt) : hasNotStarted ? startTime : now;
 const diffMs = deadlineTime - reference;

 if (!isPaused && diffMs < 0) return 'Melewati deadline';

 const diffMinutes = Math.floor(Math.max(0, diffMs) / 60000);
 const hours = Math.floor(diffMinutes / 60);
 const minutes = diffMinutes % 60;

 return hours > 0 ? `${hours} jam ${minutes} menit` : `${minutes} menit`;
 };

 return (
 <TooltipProvider>
 <Tooltip>
 <TooltipTrigger>
 <Badge className={`${status.color} cursor-default`}>
 {status.label}
 </Badge>
 </TooltipTrigger>
 <TooltipContent className="text-sm space-y-1">
 <p><strong>Status SLA:</strong> {status.label}</p>
 <p><strong>Sisa Waktu:</strong> {formatSisaWaktu()}</p>
 {priority && <p><strong>Prioritas:</strong> {priority}</p>}
 {resolutionStartsAt && <p><strong>Mulai:</strong> {formatDateId(resolutionStartsAt)}</p>}
 {(resolutionBaseDeadline || pauseMinutes > 0) && (
 <p><strong>{tStatus === 'pending' ? 'Menunggu giliran' : 'Total Dijeda'}:</strong> {pauseMinutes} menit</p>
 )}
 {respondedAt && (
 <p><strong>Direspon:</strong> {formatDateId(respondedAt)}</p>
 )}
 {resolvedAt && (
 <p><strong>Diselesaikan:</strong> {formatDateId(resolvedAt)}</p>
 )}
 </TooltipContent>
 </Tooltip>
 </TooltipProvider>
 );
}
