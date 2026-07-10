import React from 'react';
import { Badge } from '@/Components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/Components/ui/tooltip';

interface SlaBadgeProps {
    currentTier: number;
    isBreached: boolean;
    deadline: string | null;
    respondedAt: string | null;
    resolvedAt: string | null;
    pausedAt: string | null;
    totalPausedMinutes: number;
}

export default function SlaBadge({
    currentTier,
    isBreached,
    deadline,
    respondedAt,
    resolvedAt,
    pausedAt,
    totalPausedMinutes,
}: SlaBadgeProps) {
    const getStatus = () => {
        if (resolvedAt) return { label: 'Selesai', color: 'bg-gray-500 text-white' };
        if (isBreached) return { label: 'Breach', color: 'bg-red-600 text-white' };
        if (currentTier >= 3) return { label: 'Tier 3', color: 'bg-red-500 text-white' };
        if (currentTier === 2) return { label: 'Tier 2', color: 'bg-orange-500 text-white' };
        if (currentTier === 1) return { label: 'Tier 1', color: 'bg-yellow-500 text-black' };

        if (deadline) {
            const now = new Date();
            const dl = new Date(deadline);
            const remaining = dl.getTime() - now.getTime();

            if (remaining <= 0) {
                return { label: 'Breach', color: 'bg-red-600 text-white' };
            }
        }

        return { label: 'Aman', color: 'bg-green-500 text-white' };
    };

    const status = getStatus();

    const formatSisaWaktu = () => {
        if (!deadline || resolvedAt) return '-';
        const now = new Date();
        const dl = new Date(deadline);
        const diffMs = dl.getTime() - now.getTime();

        if (diffMs <= 0) return 'Melewati deadline';

        const diffMinutes = Math.floor(diffMs / 60000);
        const hours = Math.floor(diffMinutes / 60);
        const minutes = diffMinutes % 60;

        if (hours > 0) return `${hours} jam ${minutes} menit`;
        return `${minutes} menit`;
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
                    <p><strong>Tier:</strong> {currentTier}</p>
                    {pausedAt && <p><strong>Sedang Paused</strong></p>}
                    {totalPausedMinutes > 0 && (
                        <p><strong>Total Paused:</strong> {totalPausedMinutes} menit</p>
                    )}
                    {respondedAt && (
                        <p><strong>Direspon:</strong> {new Date(respondedAt).toLocaleString('id-ID')}</p>
                    )}
                    {resolvedAt && (
                        <p><strong>Diselesaikan:</strong> {new Date(resolvedAt).toLocaleString('id-ID')}</p>
                    )}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
