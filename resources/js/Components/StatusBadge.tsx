import React from 'react';
import { cn } from '@/lib/utils';

const statusStyles = {
	open: 'bg-blue-100 text-blue-700 border-blue-200 ',
	on_proses: 'bg-yellow-100 text-yellow-700 border-yellow-200 ',
	pending: 'bg-gray-100 text-gray-700 border-gray-200 ',
	solve: 'bg-[#0d6efd] text-white border-[#0d6efd] ',
	selesai: 'bg-[#0d6efd] text-white border-[#0d6efd] ',
	reject: 'bg-red-100 text-red-700 border-red-200 ',
	dibatalkan: 'bg-red-100 text-red-700 border-red-200 ',
	waiting_approval: 'bg-cyan-100 text-cyan-700 border-cyan-200 ',
	need_revision: 'bg-fuchsia-100 text-fuchsia-700 border-fuchsia-200 ',
} satisfies Record<string, string>;

const statusLabels = {
	open: 'Baru',
	on_proses: 'Diproses',
	pending: 'Tertunda',
	solve: 'Selesai',
	selesai: 'Selesai',
	reject: 'Ditolak',
	dibatalkan: 'Dibatalkan',
	waiting_approval: 'Menunggu Review',
	need_revision: 'Butuh Revisi',
} satisfies Record<string, string>;

interface StatusBadgeProps {
	status: string;
	className?: string;
}

export function StatusBadge({ status, className }: StatusBadgeProps) {
	const normalizedStatus = status?.toLowerCase() || '';
	// SAFETY: Look up style using keyof; returns undefined if not found so fallback is used.
	const matchedStyle = statusStyles[normalizedStatus as keyof typeof statusStyles] ?? statusStyles[status as keyof typeof statusStyles];
	// SAFETY: Look up label using keyof; returns undefined if not found so fallback is used.
	const matchedLabel = statusLabels[normalizedStatus as keyof typeof statusLabels] ?? statusLabels[status as keyof typeof statusLabels];

	return (
		<span
			className={cn(
				'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border',
				matchedStyle || 'bg-gray-100 text-gray-700 border-gray-200',
				className
			)}
		>
			{matchedLabel || status}
		</span>
	);
}
