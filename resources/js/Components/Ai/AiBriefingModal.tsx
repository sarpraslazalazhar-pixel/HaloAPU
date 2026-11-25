import React, { useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import AiMarkdownContent from '@/Components/Ai/AiMarkdownContent';
import {
    AlertTriangle,
    Calendar,
    Check,
    CheckCircle2,
    Copy,
    Inbox,
    MessageSquarePlus,
    Shield,
    Sparkles,
    Star,
} from 'lucide-react';

export interface AiBriefingAtRiskTicket {
    formatted_id?: string;
    layanan?: string;
    status?: string;
}

export interface AiBriefingTopService {
    nama_layanan?: string;
    total?: number;
}

export interface AiBriefingSnapshot {
    period_label?: string;
    new_tickets?: number;
    solved_tickets?: number;
    active_open?: number;
    active_on_progress?: number;
    active_pending?: number;
    stale_pending_gt_2_days?: number;
    sla_at_risk_count?: number;
    sla_breached_count?: number;
    csat_avg?: number;
    csat_count?: number;
    top_at_risk_tickets?: AiBriefingAtRiskTicket[];
    top_services?: AiBriefingTopService[];
}

export interface AiBriefingRecord {
    id: number;
    admin_id: number;
    period_type: string;
    briefing_date: string;
    scope_label: string;
    title: string;
    summary_markdown: string;
    metrics_snapshot: AiBriefingSnapshot | null;
    is_fallback: boolean;
    read_at: string | null;
    created_at?: string | null;
}

export interface AiBriefingModalProps {
    briefing: AiBriefingRecord | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onAskFollowUp: (prompt: string) => void;
}

function formatBriefingDate(rawDate: string): string {
    if (!rawDate) {
        return '-';
    }

    const parsed = new Date(rawDate);

    if (Number.isNaN(parsed.getTime())) {
        return rawDate;
    }

    return parsed.toLocaleDateString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

export default function AiBriefingModal({
    briefing,
    open,
    onOpenChange,
    onAskFollowUp,
}: AiBriefingModalProps) {
    const [copied, setCopied] = useState(false);

    if (briefing === null) {
        return null;
    }

    const snapshot = briefing.metrics_snapshot;
    const isWeekly = briefing.period_type === 'weekly';

    const activeTotal = snapshot
        ? (snapshot.active_open ?? 0) + (snapshot.active_on_progress ?? 0) + (snapshot.active_pending ?? 0)
        : 0;

    const slaAttentionCount = snapshot
        ? (snapshot.sla_at_risk_count ?? 0) + (snapshot.sla_breached_count ?? 0)
        : 0;

    const handleCopy = () => {
        navigator.clipboard.writeText(briefing.summary_markdown);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const handleFollowUp = () => {
        const dateLabel = formatBriefingDate(briefing.briefing_date);
        const prompt = `Tindak lanjuti briefing "${briefing.title}" (${dateLabel}): jelaskan tiket prioritas yang perlu segera ditangani dan langkah konkretnya.`;

        onOpenChange(false);
        onAskFollowUp(prompt);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="bg-white border-zinc-200 sm:max-w-2xl">
                <DialogHeader>
                    <div className="flex flex-wrap items-center gap-1.5 pr-6">
                        <Badge
                            className={
                                isWeekly
                                    ? 'border-indigo-200 bg-indigo-50 text-[10px] font-semibold text-indigo-700'
                                    : 'border-sky-200 bg-sky-50 text-[10px] font-semibold text-sky-700'
                            }
                        >
                            {isWeekly ? 'Briefing Mingguan' : 'Briefing Harian'}
                        </Badge>
                        <Badge className="border-zinc-200 bg-zinc-50 text-[10px] font-medium text-zinc-600 flex items-center gap-1">
                            <Calendar className="h-3 w-3 text-zinc-400" />
                            {formatBriefingDate(briefing.briefing_date)}
                        </Badge>
                        <Badge className="border-zinc-200 bg-zinc-50 text-[10px] font-medium text-zinc-600 flex items-center gap-1">
                            <Shield className="h-3 w-3 text-sky-600" />
                            {briefing.scope_label}
                        </Badge>
                        {briefing.is_fallback && (
                            <Badge className="border-amber-200 bg-amber-50 text-[10px] font-medium text-amber-700">
                                Ringkasan Lokal
                            </Badge>
                        )}
                    </div>

                    <DialogTitle className="mt-1 flex items-center gap-2 text-base font-bold text-zinc-900 md:text-lg">
                        <Sparkles className="h-4 w-4 shrink-0 text-sky-600" />
                        <span>{briefing.title}</span>
                    </DialogTitle>

                    <DialogDescription className="text-xs text-zinc-500">
                        {snapshot?.period_label
                            ? `Ringkasan operasional otomatis untuk periode ${snapshot.period_label}.`
                            : 'Ringkasan insight operasional HaloAPU sesuai cakupan akses Anda.'}
                    </DialogDescription>
                </DialogHeader>

                {snapshot && (
                    <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                        <div className="rounded-xl border border-zinc-200/80 bg-zinc-50/70 p-2.5">
                            <div className="flex items-center justify-between text-[11px] text-zinc-500">
                                <span>Tiket Baru</span>
                                <Inbox className="h-3.5 w-3.5 text-sky-600" />
                            </div>
                            <p className="mt-1 text-lg font-bold text-zinc-900">{snapshot.new_tickets ?? 0}</p>
                        </div>

                        <div className="rounded-xl border border-zinc-200/80 bg-zinc-50/70 p-2.5">
                            <div className="flex items-center justify-between text-[11px] text-zinc-500">
                                <span>Selesai</span>
                                <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
                            </div>
                            <p className="mt-1 text-lg font-bold text-emerald-700">{snapshot.solved_tickets ?? 0}</p>
                        </div>

                        <div className="rounded-xl border border-zinc-200/80 bg-zinc-50/70 p-2.5">
                            <div className="flex items-center justify-between text-[11px] text-zinc-500">
                                <span>Rawan / Breach SLA</span>
                                <AlertTriangle className="h-3.5 w-3.5 text-amber-600" />
                            </div>
                            <p className="mt-1 text-lg font-bold text-amber-700">
                                {slaAttentionCount}
                                <span className="ml-1 text-[10px] font-normal text-zinc-500">
                                    (aktif: {activeTotal})
                                </span>
                            </p>
                        </div>

                        <div className="rounded-xl border border-zinc-200/80 bg-zinc-50/70 p-2.5">
                            <div className="flex items-center justify-between text-[11px] text-zinc-500">
                                <span>Skor CSAT</span>
                                <Star className="h-3.5 w-3.5 text-yellow-500" />
                            </div>
                            <p className="mt-1 text-lg font-bold text-zinc-900">
                                {snapshot.csat_avg ?? 0}
                                <span className="ml-1 text-[10px] font-normal text-zinc-500">
                                    / 5 ({snapshot.csat_count ?? 0})
                                </span>
                            </p>
                        </div>
                    </div>
                )}

                <div className="max-h-[46vh] overflow-y-auto rounded-xl border border-zinc-200/80 bg-zinc-50/40 p-4">
                    <AiMarkdownContent content={briefing.summary_markdown} />
                </div>

                <DialogFooter className="bg-zinc-50/80 border-zinc-200">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={handleCopy}
                        className="border-zinc-200 text-zinc-700 hover:bg-zinc-100"
                    >
                        {copied ? (
                            <>
                                <Check className="mr-1.5 h-3.5 w-3.5 text-emerald-600" />
                                <span className="text-emerald-700">Tersalin</span>
                            </>
                        ) : (
                            <>
                                <Copy className="mr-1.5 h-3.5 w-3.5" />
                                <span>Salin</span>
                            </>
                        )}
                    </Button>

                    <Button
                        type="button"
                        size="sm"
                        onClick={handleFollowUp}
                        className="bg-sky-600 text-white hover:bg-sky-700"
                    >
                        <MessageSquarePlus className="mr-1.5 h-3.5 w-3.5" />
                        <span>Tanyakan Lanjutan ke AI</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
