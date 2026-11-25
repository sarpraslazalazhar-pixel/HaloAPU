import React, { useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/Components/ui/sheet';
import type { AiBriefingRecord } from '@/Components/Ai/AiBriefingModal';
import {
    Calendar,
    Check,
    Loader2,
    MessageSquare,
    Newspaper,
    Pencil,
    Plus,
    Sparkles,
    Trash2,
    X,
} from 'lucide-react';

export interface AiConversationScopeFilters {
    period?: string | null;
}

export interface AiConversationRecord {
    id: number;
    admin_id: number;
    title: string;
    scope_filters: AiConversationScopeFilters | null;
    last_message_at: string | null;
    created_at?: string | null;
    updated_at?: string | null;
}

export interface AiSessionSidebarProps {
    sessions: AiConversationRecord[];
    activeConversationId: number | null;
    briefings: AiBriefingRecord[];
    unreadBriefingsCount: number;
    loadingSessionId: number | null;
    creatingSession: boolean;
    loadingBriefingId: number | null;
    generatingBriefing: boolean;
    briefingError: string | null;
    mobileOpen: boolean;
    onMobileOpenChange: (open: boolean) => void;
    onCreateSession: () => Promise<void> | void;
    onSelectSession: (sessionId: number) => Promise<void> | void;
    onRenameSession: (sessionId: number, newTitle: string) => Promise<void> | void;
    onDeleteSession: (sessionId: number) => Promise<void> | void;
    onOpenBriefing: (briefingId: number) => Promise<void> | void;
    onGenerateBriefing: (periodType: 'daily' | 'weekly') => Promise<void> | void;
}

function formatShortTimestamp(rawTimestamp: string | null | undefined): string {
    if (!rawTimestamp) {
        return '-';
    }

    const parsed = new Date(rawTimestamp);

    if (Number.isNaN(parsed.getTime())) {
        return rawTimestamp;
    }

    return parsed.toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatShortDate(rawDate: string): string {
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

export default function AiSessionSidebar({
    sessions,
    activeConversationId,
    briefings,
    unreadBriefingsCount,
    loadingSessionId,
    creatingSession,
    loadingBriefingId,
    generatingBriefing,
    briefingError,
    mobileOpen,
    onMobileOpenChange,
    onCreateSession,
    onSelectSession,
    onRenameSession,
    onDeleteSession,
    onOpenBriefing,
    onGenerateBriefing,
}: AiSessionSidebarProps) {
    const [activeTab, setActiveTab] = useState<'sessions' | 'briefings'>('sessions');
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editingTitle, setEditingTitle] = useState('');
    const [savingRename, setSavingRename] = useState(false);
    const [briefingPeriod, setBriefingPeriod] = useState<'daily' | 'weekly'>('daily');

    const startRename = (session: AiConversationRecord) => {
        setEditingId(session.id);
        setEditingTitle(session.title);
    };

    const cancelRename = () => {
        setEditingId(null);
        setEditingTitle('');
    };

    const submitRename = async (sessionId: number) => {
        const trimmed = editingTitle.trim();

        if (!trimmed || savingRename) {
            return;
        }

        setSavingRename(true);

        try {
            await onRenameSession(sessionId, trimmed);
            setEditingId(null);
            setEditingTitle('');
        } finally {
            setSavingRename(false);
        }
    };

    const handleDelete = async (session: AiConversationRecord) => {
        if (window.confirm(`Hapus sesi "${session.title}" beserta seluruh riwayat pesannya?`)) {
            await onDeleteSession(session.id);
        }
    };

    const handleSelectSession = async (sessionId: number) => {
        onMobileOpenChange(false);
        await onSelectSession(sessionId);
    };

    const handleCreateSession = async () => {
        onMobileOpenChange(false);
        await onCreateSession();
    };

    const handleOpenBriefing = async (briefingId: number) => {
        onMobileOpenChange(false);
        await onOpenBriefing(briefingId);
    };

    const renderSidebarContent = () => (
        <div className="flex h-full flex-col bg-white">
            {/* Two Compact Tabs */}
            <div className="border-b border-zinc-200/80 p-2.5">
                <div className="grid grid-cols-2 gap-1 rounded-xl bg-zinc-100 p-1">
                    <button
                        type="button"
                        onClick={() => setActiveTab('sessions')}
                        className={`flex items-center justify-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition-all ${
                            activeTab === 'sessions'
                                ? 'bg-white text-zinc-900 shadow-2xs'
                                : 'text-zinc-500 hover:text-zinc-800'
                        }`}
                    >
                        <MessageSquare className="h-3.5 w-3.5 text-sky-600" />
                        <span>Sesi Chat</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => setActiveTab('briefings')}
                        className={`flex items-center justify-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition-all ${
                            activeTab === 'briefings'
                                ? 'bg-white text-zinc-900 shadow-2xs'
                                : 'text-zinc-500 hover:text-zinc-800'
                        }`}
                    >
                        <Newspaper className="h-3.5 w-3.5 text-sky-600" />
                        <span>Briefing AI</span>
                        {unreadBriefingsCount > 0 && (
                            <span className="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-sky-600 px-1 text-[10px] font-bold text-white">
                                {unreadBriefingsCount}
                            </span>
                        )}
                    </button>
                </div>
            </div>

            {activeTab === 'sessions' ? (
                <div className="flex min-h-0 flex-1 flex-col">
                    <div className="border-b border-zinc-100 p-3">
                        <Button
                            type="button"
                            size="sm"
                            disabled={creatingSession}
                            onClick={handleCreateSession}
                            className="w-full justify-center bg-sky-600 text-xs font-semibold text-white shadow-2xs hover:bg-sky-700"
                        >
                            {creatingSession ? (
                                <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                            ) : (
                                <Plus className="mr-1.5 h-3.5 w-3.5" />
                            )}
                            <span>Percakapan Baru</span>
                        </Button>
                    </div>

                    <div className="flex-1 space-y-1.5 overflow-y-auto p-2.5">
                        {sessions.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-zinc-200 bg-zinc-50/70 px-3 py-6 text-center">
                                <MessageSquare className="mx-auto mb-2 h-5 w-5 text-zinc-400" />
                                <p className="text-xs font-medium text-zinc-600">Belum ada sesi tersimpan</p>
                                <p className="mt-0.5 text-[11px] text-zinc-400">
                                    Mulai chat atau klik Percakapan Baru.
                                </p>
                            </div>
                        ) : (
                            sessions.map((session) => {
                                const isActive = activeConversationId === session.id;
                                const isLoading = loadingSessionId === session.id;
                                const isEditing = editingId === session.id;

                                if (isEditing) {
                                    return (
                                        <div
                                            key={session.id}
                                            className="rounded-xl border border-sky-300 bg-sky-50/60 p-2"
                                        >
                                            <input
                                                type="text"
                                                value={editingTitle}
                                                onChange={(e) => setEditingTitle(e.target.value)}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter') {
                                                        e.preventDefault();
                                                        submitRename(session.id);
                                                    } else if (e.key === 'Escape') {
                                                        cancelRename();
                                                    }
                                                }}
                                                maxLength={160}
                                                autoFocus
                                                className="w-full rounded-lg border border-sky-300 bg-white px-2.5 py-1.5 text-xs text-zinc-900 focus:border-sky-500 focus:outline-none"
                                            />
                                            <div className="mt-1.5 flex items-center justify-end gap-1">
                                                <button
                                                    type="button"
                                                    onClick={cancelRename}
                                                    disabled={savingRename}
                                                    className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] text-zinc-600 hover:bg-zinc-200/70"
                                                >
                                                    <X className="h-3 w-3" />
                                                    <span>Batal</span>
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => submitRename(session.id)}
                                                    disabled={savingRename || !editingTitle.trim()}
                                                    className="inline-flex items-center gap-1 rounded-md bg-sky-600 px-2 py-1 text-[11px] font-medium text-white hover:bg-sky-700 disabled:opacity-50"
                                                >
                                                    <Check className="h-3 w-3" />
                                                    <span>Simpan</span>
                                                </button>
                                            </div>
                                        </div>
                                    );
                                }

                                return (
                                    <div
                                        key={session.id}
                                        className={`group flex items-start justify-between gap-2 rounded-xl border p-2.5 transition-all ${
                                            isActive
                                                ? 'border-sky-300 bg-sky-50/80 shadow-2xs'
                                                : 'border-transparent bg-white hover:border-zinc-200 hover:bg-zinc-50'
                                        }`}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => handleSelectSession(session.id)}
                                            className="min-w-0 flex-1 text-left"
                                        >
                                            <div className="flex items-center gap-1.5">
                                                {isLoading && (
                                                    <Loader2 className="h-3 w-3 shrink-0 animate-spin text-sky-600" />
                                                )}
                                                <p
                                                    className={`truncate text-xs font-semibold ${
                                                        isActive ? 'text-sky-900' : 'text-zinc-800'
                                                    }`}
                                                >
                                                    {session.title}
                                                </p>
                                            </div>
                                            <p className="mt-0.5 text-[10px] text-zinc-400">
                                                {formatShortTimestamp(session.last_message_at ?? session.created_at)}
                                            </p>
                                        </button>

                                        <div className="flex shrink-0 items-center gap-0.5 opacity-90 lg:opacity-0 lg:group-hover:opacity-100 transition-opacity">
                                            <button
                                                type="button"
                                                onClick={() => startRename(session)}
                                                title="Ganti judul sesi"
                                                className="rounded-md p-1 text-zinc-400 hover:bg-zinc-200/70 hover:text-zinc-700"
                                            >
                                                <Pencil className="h-3 w-3" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => handleDelete(session)}
                                                title="Hapus sesi"
                                                className="rounded-md p-1 text-zinc-400 hover:bg-red-50 hover:text-red-600"
                                            >
                                                <Trash2 className="h-3 w-3" />
                                            </button>
                                        </div>
                                    </div>
                                );
                            })
                        )}
                    </div>
                </div>
            ) : (
                <div className="flex min-h-0 flex-1 flex-col">
                    <div className="space-y-2 border-b border-zinc-100 p-3">
                        <div className="grid grid-cols-2 gap-1 rounded-lg bg-zinc-100 p-0.5">
                            <button
                                type="button"
                                onClick={() => setBriefingPeriod('daily')}
                                className={`rounded-md py-1 text-[11px] font-medium transition-colors ${
                                    briefingPeriod === 'daily'
                                        ? 'bg-white text-zinc-900 shadow-2xs'
                                        : 'text-zinc-500 hover:text-zinc-800'
                                }`}
                            >
                                Harian
                            </button>
                            <button
                                type="button"
                                onClick={() => setBriefingPeriod('weekly')}
                                className={`rounded-md py-1 text-[11px] font-medium transition-colors ${
                                    briefingPeriod === 'weekly'
                                        ? 'bg-white text-zinc-900 shadow-2xs'
                                        : 'text-zinc-500 hover:text-zinc-800'
                                }`}
                            >
                                Mingguan
                            </button>
                        </div>

                        <Button
                            type="button"
                            size="sm"
                            disabled={generatingBriefing}
                            onClick={() => onGenerateBriefing(briefingPeriod)}
                            className="w-full justify-center bg-sky-600 text-xs font-semibold text-white shadow-2xs hover:bg-sky-700"
                        >
                            {generatingBriefing ? (
                                <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                            ) : (
                                <Sparkles className="mr-1.5 h-3.5 w-3.5" />
                            )}
                            <span>{generatingBriefing ? 'Menyusun Briefing...' : 'Buat Briefing Sekarang'}</span>
                        </Button>

                        {briefingError && (
                            <p className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-[11px] text-red-700">
                                {briefingError}
                            </p>
                        )}
                    </div>

                    <div className="flex-1 space-y-2 overflow-y-auto p-2.5">
                        {briefings.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-zinc-200 bg-zinc-50/70 px-3 py-6 text-center">
                                <Newspaper className="mx-auto mb-2 h-5 w-5 text-zinc-400" />
                                <p className="text-xs font-medium text-zinc-600">Belum ada briefing AI</p>
                                <p className="mt-0.5 text-[11px] text-zinc-400">
                                    Klik tombol di atas untuk menyusun ringkasan operasional.
                                </p>
                            </div>
                        ) : (
                            briefings.map((item) => {
                                const isUnread = item.read_at === null;
                                const isLoading = loadingBriefingId === item.id;
                                const isWeekly = item.period_type === 'weekly';

                                return (
                                    <button
                                        key={item.id}
                                        type="button"
                                        onClick={() => handleOpenBriefing(item.id)}
                                        className={`w-full rounded-xl border p-2.5 text-left transition-all ${
                                            isUnread
                                                ? 'border-sky-200 bg-sky-50/50 hover:border-sky-300 hover:bg-sky-50'
                                                : 'border-zinc-200/70 bg-white hover:border-zinc-300 hover:bg-zinc-50'
                                        }`}
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <div className="flex items-center gap-1.5">
                                                {isUnread && (
                                                    <span
                                                        className="h-2 w-2 shrink-0 rounded-full bg-sky-500"
                                                        title="Belum dibaca"
                                                    />
                                                )}
                                                <Badge
                                                    className={
                                                        isWeekly
                                                            ? 'border-indigo-200 bg-indigo-50 px-1.5 py-0 text-[10px] text-indigo-700'
                                                            : 'border-sky-200 bg-sky-50 px-1.5 py-0 text-[10px] text-sky-700'
                                                    }
                                                >
                                                    {isWeekly ? 'Mingguan' : 'Harian'}
                                                </Badge>
                                            </div>
                                            <span className="flex items-center gap-1 text-[10px] text-zinc-400">
                                                {isLoading ? (
                                                    <Loader2 className="h-3 w-3 animate-spin text-sky-600" />
                                                ) : (
                                                    <Calendar className="h-3 w-3" />
                                                )}
                                                {formatShortDate(item.briefing_date)}
                                            </span>
                                        </div>

                                        <p className="mt-1.5 line-clamp-2 text-xs font-semibold text-zinc-800">
                                            {item.title}
                                        </p>

                                        <p className="mt-0.5 truncate text-[10px] text-zinc-500">{item.scope_label}</p>
                                    </button>
                                );
                            })
                        )}
                    </div>
                </div>
            )}
        </div>
    );

    return (
        <>
            {/* Desktop Sidebar */}
            <aside className="hidden lg:flex lg:w-72 shrink-0 flex-col overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-2xs">
                {renderSidebarContent()}
            </aside>

            {/* Mobile Drawer */}
            <Sheet open={mobileOpen} onOpenChange={onMobileOpenChange}>
                <SheetContent side="left" className="w-[86vw] max-w-xs bg-white p-0 gap-0">
                    <SheetHeader className="border-b border-zinc-200/80 px-4 py-3">
                        <SheetTitle className="text-sm font-bold text-zinc-900">
                            Riwayat & Briefing AI
                        </SheetTitle>
                    </SheetHeader>
                    <div className="min-h-0 flex-1 overflow-hidden">{renderSidebarContent()}</div>
                </SheetContent>
            </Sheet>
        </>
    );
}
