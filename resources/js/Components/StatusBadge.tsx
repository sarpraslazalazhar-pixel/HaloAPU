import React from 'react';
import { cn } from '@/lib/utils';

const statusStyles: Record<string, string> = {
    open: 'bg-blue-100 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-400',
    on_proses: 'bg-yellow-100 text-yellow-700 border-yellow-200 dark:bg-yellow-900/30 dark:text-yellow-400',
    pending: 'bg-gray-100 text-gray-700 border-gray-200 dark:bg-gray-800 dark:text-gray-400',
    solve: 'bg-[#0d6efd] text-white border-[#0d6efd] dark:bg-[#0d6efd]/80',
    selesai: 'bg-[#0d6efd] text-white border-[#0d6efd] dark:bg-[#0d6efd]/80',
    reject: 'bg-red-100 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-400',
    dibatalkan: 'bg-red-100 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-400',
    waiting_approval: 'bg-cyan-100 text-cyan-700 border-cyan-200 dark:bg-cyan-900/30 dark:text-cyan-400',
    need_revision: 'bg-fuchsia-100 text-fuchsia-700 border-fuchsia-200 dark:bg-fuchsia-900/30 dark:text-fuchsia-400',
};

const statusLabels: Record<string, string> = {
    open: 'Baru',
    on_proses: 'Diproses',
    pending: 'Tertunda',
    solve: 'Selesai',
    selesai: 'Selesai',
    reject: 'Ditolak',
    dibatalkan: 'Dibatalkan',
    waiting_approval: 'Menunggu Review',
    need_revision: 'Butuh Revisi',
};

interface StatusBadgeProps {
    status: string;
    className?: string;
}

export function StatusBadge({ status, className }: StatusBadgeProps) {
    const normalizedStatus = status?.toLowerCase() || '';
    return (
        <span
            className={cn(
                'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border',
                statusStyles[normalizedStatus] || statusStyles[status] || 'bg-gray-100 text-gray-700 border-gray-200',
                className
            )}
        >
            {statusLabels[normalizedStatus] || statusLabels[status] || status}
        </span>
    );
}
