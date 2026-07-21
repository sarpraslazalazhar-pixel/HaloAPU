import React from 'react';
import { cn } from '@/lib/utils';

const statusStyles: Record<string, string> = {
    open: 'bg-blue-100 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-400',
    on_proses: 'bg-yellow-100 text-yellow-700 border-yellow-200 dark:bg-yellow-900/30 dark:text-yellow-400',
    pending: 'bg-gray-100 text-gray-700 border-gray-200 dark:bg-gray-800 dark:text-gray-400',
    solve: 'bg-green-100 text-green-700 border-green-200 dark:bg-green-900/30 dark:text-green-400',
    reject: 'bg-red-100 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-400',
    dibatalkan: 'bg-red-100 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-400',
};

const statusLabels: Record<string, string> = {
    open: 'Baru',
    on_proses: 'Diproses',
    pending: 'Tertunda',
    solve: 'Selesai',
    reject: 'Ditolak',
    dibatalkan: 'Dibatalkan',
};

interface StatusBadgeProps {
    status: string;
    className?: string;
}

export function StatusBadge({ status, className }: StatusBadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border',
                statusStyles[status] || 'bg-gray-100 text-gray-700 border-gray-200',
                className
            )}
        >
            {statusLabels[status] || status}
        </span>
    );
}
