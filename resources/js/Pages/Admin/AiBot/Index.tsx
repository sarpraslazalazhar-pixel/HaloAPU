import React, { useEffect, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent } from '@/Components/ui/card';
import AiMarkdownContent from '@/Components/Ai/AiMarkdownContent';
import AiChartCard, { type AiChartSpec } from '@/Components/Ai/AiChartCard';
import AiSessionSidebar, { type AiConversationRecord } from '@/Components/Ai/AiSessionSidebar';
import AiBriefingModal, { type AiBriefingRecord } from '@/Components/Ai/AiBriefingModal';
import axios from 'axios';
import {
    AlertTriangle,
    Bot,
    CalendarClock,
    Check,
    Clock,
    Copy,
    FileDown,
    GitCompareArrows,
    Inbox,
    Info,
    MessageSquareText,
    PanelLeft,
    RefreshCw,
    Send,
    Shield,
    Sparkles,
    Star,
    User,
    Users,
    Wrench,
} from 'lucide-react';

interface AiScopeInfo {
    level: 'global' | 'unit' | 'personal' | string;
    label: string;
    unit_names: string[];
    can_view_team_operators: boolean;
}

interface QuickMetrics {
    total_tickets: number;
    open_tickets: number;
    in_progress: number;
    breached_active: number;
    avg_csat: number;
    scope?: AiScopeInfo;
}

interface AiMessageRecord {
    id: number;
    ai_conversation_id?: number;
    role: 'user' | 'model';
    content: string;
    is_fallback?: boolean;
    model_name?: string | null;
    tools_used?: string[] | null;
    charts?: AiChartSpec[] | null;
    created_at?: string | null;
}

interface ActiveConversationRecord extends AiConversationRecord {
    messages: AiMessageRecord[];
}

interface PageProps {
    hasApiKey: boolean;
    geminiModel: string;
    quickMetrics: QuickMetrics;
    scopeInfo: AiScopeInfo;
    sessions: AiConversationRecord[];
    activeConversation: ActiveConversationRecord | null;
    briefings: AiBriefingRecord[];
    unreadBriefingsCount: number;
}

interface AiApiErrorPayload {
    message?: string;
    error_code?: string;
    retry_after?: number;
}

interface ChatApiResponse {
    conversation: AiConversationRecord;
    user_message: AiMessageRecord;
    assistant_message: AiMessageRecord;
    reply: string;
    is_fallback: boolean;
    model_name: string;
    tools_used: string[];
    charts: AiChartSpec[];
}

interface SessionDetailResponse {
    conversation: AiConversationRecord;
    messages: AiMessageRecord[];
}

interface SessionCreateResponse {
    conversation: AiConversationRecord;
}

interface BriefingResponse {
    briefing: AiBriefingRecord;
}

interface SuggestionItem {
    title: string;
    icon: React.ComponentType<{ className?: string }>;
    prompt: string;
}

interface PeriodOption {
    value: string;
    label: string;
}

const PERIOD_OPTIONS: PeriodOption[] = [
    { value: 'all', label: 'Semua Periode' },
    { value: 'today', label: 'Hari Ini' },
    { value: '7d', label: '7 Hari Terakhir' },
    { value: '30d', label: '30 Hari Terakhir' },
    { value: 'this_month', label: 'Bulan Ini' },
    { value: 'last_month', label: 'Bulan Lalu' },
];

const SUGGESTION_CHIPS: SuggestionItem[] = [
    {
        title: 'Tiket Rawan SLA',
        icon: AlertTriangle,
        prompt: 'Tampilkan daftar tiket aktif yang rawan atau sudah melanggar SLA beserta prioritas dan batas waktunya.',
    },
    {
        title: 'Kinerja Petugas',
        icon: Users,
        prompt: 'Bagaimana ringkasan kinerja penyelesaian dan beban aktif petugas pada periode ini?',
    },
    {
        title: 'Bandingkan Bulan Ini vs Lalu',
        icon: GitCompareArrows,
        prompt: 'Bandingkan performa tiket, penyelesaian, pelanggaran SLA, dan CSAT bulan ini dibandingkan bulan lalu.',
    },
    {
        title: 'Analisis Keluhan CSAT',
        icon: MessageSquareText,
        prompt: 'Analisis skor kepuasan pemohon (CSAT), distribusi rating, serta keluhan atau ulasan rendah terbaru.',
    },
    {
        title: 'Jadwal Booking Minggu Ini',
        icon: CalendarClock,
        prompt: 'Tampilkan jadwal booking ruangan dan kendaraan untuk 7 hari ke depan beserta statusnya.',
    },
];

function formatToolLabel(toolName: string): string {
    switch (toolName) {
        case 'get_ticket_summary':
            return 'Ringkasan Tiket';
        case 'list_at_risk_sla_tickets':
            return 'Tiket Rawan SLA';
        case 'get_sla_compliance':
            return 'Kepatuhan SLA';
        case 'get_operator_performance':
            return 'Kinerja Petugas';
        case 'get_csat_analysis':
            return 'Analisis CSAT';
        case 'search_tickets':
            return 'Pencarian Tiket';
        case 'get_ticket_detail':
            return 'Detail Tiket';
        case 'get_booking_schedule':
            return 'Jadwal Booking';
        case 'compare_periods':
            return 'Perbandingan Periode';
        default:
            return toolName;
    }
}

function formatMessageTime(rawTimestamp: string | null | undefined): string {
    if (!rawTimestamp) {
        return new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    }

    const parsed = new Date(rawTimestamp);

    if (Number.isNaN(parsed.getTime())) {
        return rawTimestamp;
    }

    return parsed.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
}

function countUnreadBriefings(items: AiBriefingRecord[]): number {
    let count = 0;

    for (const item of items) {
        if (item.read_at === null) {
            count += 1;
        }
    }

    return count;
}

function upsertBriefingList(items: AiBriefingRecord[], updated: AiBriefingRecord): AiBriefingRecord[] {
    const next: AiBriefingRecord[] = [updated];

    for (const item of items) {
        if (item.id !== updated.id) {
            next.push(item);
        }
    }

    return next;
}

export default function AiBotHaloAPUPage({
    hasApiKey,
    geminiModel,
    quickMetrics,
    scopeInfo,
    sessions: initialSessions,
    activeConversation: initialActiveConversation,
    briefings: initialBriefings,
    unreadBriefingsCount: initialUnreadCount,
}: PageProps) {
    const [sessions, setSessions] = useState<AiConversationRecord[]>(initialSessions);

    const [activeConversation, setActiveConversation] = useState<AiConversationRecord | null>(
        initialActiveConversation
    );

    const [messages, setMessages] = useState<AiMessageRecord[]>(
        initialActiveConversation?.messages ?? []
    );

    const [briefings, setBriefings] = useState<AiBriefingRecord[]>(initialBriefings);
    const [unreadCount, setUnreadCount] = useState<number>(initialUnreadCount);

    const [input, setInput] = useState('');

    const [periodFilter, setPeriodFilter] = useState<string>(
        initialActiveConversation?.scope_filters?.period ?? 'all'
    );

    const [sending, setSending] = useState(false);
    const [copiedId, setCopiedId] = useState<number | null>(null);
    const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false);

    const [loadingSessionId, setLoadingSessionId] = useState<number | null>(null);
    const [creatingSession, setCreatingSession] = useState(false);
    const [loadingBriefingId, setLoadingBriefingId] = useState<number | null>(null);
    const [generatingBriefing, setGeneratingBriefing] = useState(false);
    const [briefingError, setBriefingError] = useState<string | null>(null);

    const [selectedBriefing, setSelectedBriefing] = useState<AiBriefingRecord | null>(null);
    const [briefingModalOpen, setBriefingModalOpen] = useState(false);

    const messagesEndRef = useRef<HTMLDivElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const openedBriefingFromUrlRef = useRef(false);

    useEffect(() => {
        setBriefings(initialBriefings);
        setUnreadCount(initialUnreadCount);
    }, [initialBriefings, initialUnreadCount]);

    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages, sending]);

    useEffect(() => {
        if (textareaRef.current) {
            textareaRef.current.style.height = 'auto';
            textareaRef.current.style.height = `${Math.min(textareaRef.current.scrollHeight, 140)}px`;
        }
    }, [input]);

    const openBriefingById = async (briefingId: number) => {
        if (loadingBriefingId !== null) {
            return;
        }

        setLoadingBriefingId(briefingId);
        setBriefingError(null);

        try {
            const response = await axios.get<BriefingResponse>(`/admin/ai/briefings/${briefingId}`);
            const fetched = response.data.briefing;

            setSelectedBriefing(fetched);
            setBriefingModalOpen(true);

            setBriefings((prev) => {
                const updatedList = upsertBriefingList(prev, fetched);
                setUnreadCount(countUnreadBriefings(updatedList));

                return updatedList;
            });
        } catch (err) {
            if (axios.isAxiosError<AiApiErrorPayload>(err)) {
                setBriefingError(err.response?.data?.message ?? 'Gagal memuat detail briefing AI.');
            } else {
                setBriefingError('Gagal memuat detail briefing AI.');
            }
        } finally {
            setLoadingBriefingId(null);
        }
    };

    useEffect(() => {
        if (openedBriefingFromUrlRef.current) {
            return;
        }

        openedBriefingFromUrlRef.current = true;
        const params = new URLSearchParams(window.location.search);
        const briefingParam = params.get('briefing');

        if (!briefingParam) {
            return;
        }

        const parsedId = Number.parseInt(briefingParam, 10);

        if (Number.isFinite(parsedId) && parsedId > 0) {
            openBriefingById(parsedId);
        }
    }, []);

    const handleCreateSession = async () => {
        if (creatingSession) {
            return;
        }

        setCreatingSession(true);

        try {
            const response = await axios.post<SessionCreateResponse>('/admin/ai/sessions', {
                title: 'Percakapan Baru',
                scope_filters: periodFilter ? { period: periodFilter } : null,
            });

            const created = response.data.conversation;

            setSessions((prev) => {
                const next: AiConversationRecord[] = [created];

                for (const item of prev) {
                    if (item.id !== created.id) {
                        next.push(item);
                    }
                }

                return next;
            });
            setActiveConversation(created);
            setMessages([]);
        } catch (err) {
            console.error('Failed to create AI session', err);
        } finally {
            setCreatingSession(false);
        }
    };

    const handleSelectSession = async (sessionId: number) => {
        if (loadingSessionId === sessionId || activeConversation?.id === sessionId) {
            return;
        }

        setLoadingSessionId(sessionId);

        try {
            const response = await axios.get<SessionDetailResponse>(`/admin/ai/sessions/${sessionId}`);
            const loadedConversation = response.data.conversation;
            const loadedMessages = response.data.messages ?? [];

            setActiveConversation(loadedConversation);
            setMessages(loadedMessages);

            if (loadedConversation.scope_filters?.period) {
                setPeriodFilter(loadedConversation.scope_filters.period);
            }
        } catch (err) {
            console.error('Failed to load AI session', err);
        } finally {
            setLoadingSessionId(null);
        }
    };

    const handleRenameSession = async (sessionId: number, newTitle: string) => {
        const response = await axios.patch<SessionCreateResponse>(`/admin/ai/sessions/${sessionId}`, {
            title: newTitle,
        });

        const updated = response.data.conversation;

        setSessions((prev) =>
            prev.map((item) => (item.id === sessionId ? updated : item))
        );

        if (activeConversation?.id === sessionId) {
            setActiveConversation(updated);
        }
    };

    const handleDeleteSession = async (sessionId: number) => {
        await axios.delete(`/admin/ai/sessions/${sessionId}`);

        const remaining: AiConversationRecord[] = [];

        for (const item of sessions) {
            if (item.id !== sessionId) {
                remaining.push(item);
            }
        }

        setSessions(remaining);

        if (activeConversation?.id === sessionId) {
            if (remaining.length > 0) {
                await handleSelectSession(remaining[0].id);
            } else {
                setActiveConversation(null);
                setMessages([]);
            }
        }
    };

    const handleGenerateBriefing = async (periodType: 'daily' | 'weekly') => {
        if (generatingBriefing) {
            return;
        }

        setGeneratingBriefing(true);
        setBriefingError(null);

        try {
            const response = await axios.post<BriefingResponse>('/admin/ai/briefings/generate', {
                period_type: periodType,
            });

            const generated = response.data.briefing;

            setBriefings((prev) => {
                const updatedList = upsertBriefingList(prev, generated);
                setUnreadCount(countUnreadBriefings(updatedList));

                return updatedList;
            });

            router.reload({ only: ['briefings', 'unreadBriefingsCount'] });
        } catch (err) {
            if (axios.isAxiosError<AiApiErrorPayload>(err)) {
                setBriefingError(
                    err.response?.data?.message ?? 'Gagal menyusun briefing AI. Pastikan endpoint briefing aktif.'
                );
            } else {
                setBriefingError('Gagal menyusun briefing AI.');
            }
        } finally {
            setGeneratingBriefing(false);
        }
    };

    const handleSendMessage = async (textToSend?: string) => {
        const query = (textToSend ?? input).trim();

        if (!query || sending) {
            return;
        }

        const tempUserMsg: AiMessageRecord = {
            id: -Date.now(),
            role: 'user',
            content: query,
            created_at: new Date().toISOString(),
        };

        setMessages((prev) => [...prev, tempUserMsg]);

        if (!textToSend) {
            setInput('');
        }

        setSending(true);

        try {
            const response = await axios.post<ChatApiResponse>('/admin/ai/chat', {
                message: query,
                conversation_id: activeConversation?.id ?? null,
                period_filter: periodFilter || 'all',
            });

            const payload = response.data;
            const updatedConversation = payload.conversation;

            setActiveConversation(updatedConversation);
            setSessions((prev) => {
                const next: AiConversationRecord[] = [updatedConversation];

                for (const item of prev) {
                    if (item.id !== updatedConversation.id) {
                        next.push(item);
                    }
                }

                return next;
            });

            const persistedUser = payload.user_message;

            const persistedAssistant: AiMessageRecord = {
                ...payload.assistant_message,
                charts: payload.charts ?? payload.assistant_message.charts ?? [],
                tools_used: payload.tools_used ?? payload.assistant_message.tools_used ?? [],
            };

            setMessages((prev) => {
                const cleaned: AiMessageRecord[] = [];

                for (const item of prev) {
                    if (item.id !== tempUserMsg.id) {
                        cleaned.push(item);
                    }
                }

                cleaned.push(persistedUser, persistedAssistant);

                return cleaned;
            });
        } catch (err) {
            let errorText =
                'Terjadi kendala saat menghubungi server AI. Silakan periksa koneksi atau coba beberapa saat lagi.';

            if (axios.isAxiosError<AiApiErrorPayload>(err)) {
                if (err.response?.status === 429) {
                    errorText =
                        err.response?.data?.message ??
                        'Terlalu banyak permintaan AI dalam 1 menit. Silakan tunggu beberapa detik sebelum mencoba lagi.';
                } else if (err.response?.data?.message) {
                    errorText = err.response.data.message;
                }
            }

            const errorMsg: AiMessageRecord = {
                id: -(Date.now() + 1),
                role: 'model',
                content: `⚠️ **Permintaan Tidak Dapat Diproses**\n\n${errorText}`,
                is_fallback: true,
                created_at: new Date().toISOString(),
            };

            setMessages((prev) => [...prev, errorMsg]);
        } finally {
            setSending(false);
        }
    };

    const handleCopy = (content: string, id: number) => {
        navigator.clipboard.writeText(content);
        setCopiedId(id);
        setTimeout(() => setCopiedId(null), 2000);
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSendMessage();
        }
    };

    const handleFollowUpFromBriefing = (prompt: string) => {
        setInput(prompt);
        setTimeout(() => {
            textareaRef.current?.focus();
        }, 80);
    };

    const canExportSessionPdf = activeConversation !== null && messages.length > 0;

    return (
        <AdminLayout title="HaloAPU AI">
            <Head title="HaloAPU AI" />

            <div className="mx-auto max-w-7xl space-y-4 pb-4">
                {/* Header Card */}
                <div className="flex flex-col justify-between gap-3 rounded-2xl border border-zinc-200/80 bg-white p-4 shadow-2xs sm:p-5 lg:flex-row lg:items-center">
                    <div className="flex items-start gap-3 sm:items-center">
                        <button
                            type="button"
                            onClick={() => setMobileSidebarOpen(true)}
                            title="Buka Riwayat & Briefing AI"
                            className="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-50 text-zinc-700 hover:bg-sky-50 hover:text-sky-600 lg:hidden"
                        >
                            <PanelLeft className="h-5 w-5" />
                            {unreadCount > 0 && (
                                <span className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-sky-600 px-1 text-[10px] font-bold text-white">
                                    {unreadCount}
                                </span>
                            )}
                        </button>

                        <div className="hidden h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-sky-200/70 bg-sky-500/10 text-sky-600 shadow-2xs lg:flex">
                            <Bot className="h-6 w-6" />
                        </div>

                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-lg font-bold tracking-tight text-zinc-900 md:text-xl">
                                    HaloAPU AI
                                </h1>

                                <Badge className="flex items-center gap-1 border-sky-200 bg-sky-50 text-[10px] font-semibold text-sky-700">
                                    <Shield className="h-3 w-3" />
                                    {scopeInfo.label}
                                </Badge>

                                {hasApiKey ? (
                                    <Badge className="flex items-center gap-1 border-emerald-200 bg-emerald-50 text-[10px] font-medium text-emerald-700">
                                        <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500" />
                                        {geminiModel} Terhubung
                                    </Badge>
                                ) : (
                                    <Badge className="border-amber-200 bg-amber-50 text-[10px] font-medium text-amber-700">
                                        Mode Analitik Lokal
                                    </Badge>
                                )}
                            </div>

                            <p className="mt-0.5 text-xs text-zinc-500">
                                Asisten analitik operasional untuk tiket, kepatuhan SLA, kinerja petugas, booking aset, dan survei CSAT.
                            </p>
                        </div>
                    </div>

                    {/* Period Filter & Export Session Controls */}
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="flex items-center gap-1.5 rounded-xl border border-zinc-200 bg-zinc-50/70 px-2.5 py-1.5">
                            <span className="text-[11px] font-medium text-zinc-500">Periode:</span>
                            <select
                                value={periodFilter}
                                onChange={(e) => setPeriodFilter(e.target.value)}
                                aria-label="Filter periode analisis AI"
                                className="border-0 bg-transparent pr-5 text-xs font-semibold text-zinc-800 focus:ring-0 focus:outline-none"
                            >
                                {PERIOD_OPTIONS.map((opt) => (
                                    <option key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {canExportSessionPdf && (
                            <a
                                href={`/admin/ai/sessions/${activeConversation.id}/export-pdf`}
                                className="inline-flex items-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 shadow-2xs transition-colors hover:border-sky-300 hover:bg-sky-50 hover:text-sky-700"
                            >
                                <FileDown className="h-3.5 w-3.5 text-sky-600" />
                                <span>Ekspor PDF Sesi</span>
                            </a>
                        )}
                    </div>
                </div>

                {/* 5 Quick Stat Cards */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                    <Card className="border-zinc-200/80 bg-white shadow-2xs">
                        <CardContent className="flex items-center justify-between p-3.5">
                            <div>
                                <p className="text-[11px] font-medium tracking-wider text-zinc-500 uppercase">
                                    Total Tiket
                                </p>
                                <p className="mt-0.5 text-xl font-bold text-zinc-900">
                                    {quickMetrics.total_tickets}
                                </p>
                            </div>
                            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                                <Inbox className="h-4 w-4" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="border-zinc-200/80 bg-white shadow-2xs">
                        <CardContent className="flex items-center justify-between p-3.5">
                            <div>
                                <p className="text-[11px] font-medium tracking-wider text-zinc-500 uppercase">
                                    Menunggu (Open)
                                </p>
                                <p className="mt-0.5 text-xl font-bold text-amber-600">
                                    {quickMetrics.open_tickets}
                                </p>
                            </div>
                            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                                <Clock className="h-4 w-4" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="border-zinc-200/80 bg-white shadow-2xs">
                        <CardContent className="flex items-center justify-between p-3.5">
                            <div>
                                <p className="text-[11px] font-medium tracking-wider text-zinc-500 uppercase">
                                    Diproses
                                </p>
                                <p className="mt-0.5 text-xl font-bold text-sky-600">
                                    {quickMetrics.in_progress}
                                </p>
                            </div>
                            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-50 text-sky-600">
                                <RefreshCw className="h-4 w-4" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="border-zinc-200/80 bg-white shadow-2xs">
                        <CardContent className="flex items-center justify-between p-3.5">
                            <div>
                                <p className="text-[11px] font-medium tracking-wider text-zinc-500 uppercase">
                                    Rawan / Breach SLA
                                </p>
                                <p className="mt-0.5 text-xl font-bold text-rose-600">
                                    {quickMetrics.breached_active}
                                </p>
                            </div>
                            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 text-rose-600">
                                <AlertTriangle className="h-4 w-4" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="col-span-2 border-zinc-200/80 bg-white shadow-2xs md:col-span-1">
                        <CardContent className="flex items-center justify-between p-3.5">
                            <div>
                                <p className="text-[11px] font-medium tracking-wider text-zinc-500 uppercase">
                                    Skor CSAT
                                </p>
                                <p className="mt-0.5 text-xl font-bold text-yellow-500">
                                    {quickMetrics.avg_csat} / 5.0
                                </p>
                            </div>
                            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-yellow-50 text-yellow-500">
                                <Star className="h-4 w-4 fill-yellow-400/30" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Main Workspace: Sidebar + Chat Box */}
                <div className="flex h-[calc(100vh-310px)] min-h-[540px] flex-col gap-4 lg:flex-row">
                    <AiSessionSidebar
                        sessions={sessions}
                        activeConversationId={activeConversation?.id ?? null}
                        briefings={briefings}
                        unreadBriefingsCount={unreadCount}
                        loadingSessionId={loadingSessionId}
                        creatingSession={creatingSession}
                        loadingBriefingId={loadingBriefingId}
                        generatingBriefing={generatingBriefing}
                        briefingError={briefingError}
                        mobileOpen={mobileSidebarOpen}
                        onMobileOpenChange={setMobileSidebarOpen}
                        onCreateSession={handleCreateSession}
                        onSelectSession={handleSelectSession}
                        onRenameSession={handleRenameSession}
                        onDeleteSession={handleDeleteSession}
                        onOpenBriefing={openBriefingById}
                        onGenerateBriefing={handleGenerateBriefing}
                    />

                    {/* Main Chat Card */}
                    <div className="flex min-w-0 flex-1 flex-col overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-xs">
                        {/* Chat Session Header Bar */}
                        <div className="flex items-center justify-between gap-3 border-b border-zinc-100 bg-zinc-50/50 px-4 py-2.5">
                            <div className="flex min-w-0 items-center gap-2">
                                <Bot className="h-4 w-4 shrink-0 text-sky-600" />
                                <span className="truncate text-xs font-semibold text-zinc-800 sm:text-sm">
                                    {activeConversation ? activeConversation.title : 'Sesi Percakapan Baru'}
                                </span>
                                {periodFilter && periodFilter !== 'all' && (
                                    <Badge className="hidden border-zinc-200 bg-white text-[10px] font-medium text-zinc-600 sm:inline-flex">
                                        {PERIOD_OPTIONS.find((p) => p.value === periodFilter)?.label ?? periodFilter}
                                    </Badge>
                                )}
                            </div>

                            {canExportSessionPdf && (
                                <a
                                    href={`/admin/ai/sessions/${activeConversation.id}/export-pdf`}
                                    className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-[11px] font-medium text-zinc-600 transition-colors hover:border-sky-300 hover:bg-sky-50 hover:text-sky-700"
                                >
                                    <FileDown className="h-3.5 w-3.5 text-sky-600" />
                                    <span className="hidden sm:inline">Ekspor PDF</span>
                                </a>
                            )}
                        </div>

                        {/* Chat Messages Body */}
                        <div className="flex-1 space-y-4 overflow-y-auto bg-zinc-50/40 p-4 sm:p-6">
                            {messages.length === 0 ? (
                                <div className="mx-auto flex max-w-2xl flex-col items-center justify-center py-6 text-center">
                                    <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl border border-sky-200 bg-sky-50 text-sky-600 shadow-2xs">
                                        <Sparkles className="h-6 w-6" />
                                    </div>
                                    <h2 className="text-base font-bold text-zinc-900 md:text-lg">
                                        Selamat datang di HaloAPU AI
                                    </h2>
                                    <p className="mt-1.5 max-w-xl text-xs leading-relaxed text-zinc-600 md:text-sm">
                                        {hasApiKey
                                            ? `Mesin AI (${geminiModel}) terhubung langsung ke basis data HaloAPU dengan cakupan akses ${scopeInfo.label}.`
                                            : `Berjalan dalam Mode Analitik Lokal dengan cakupan akses ${scopeInfo.label}.`}{' '}
                                        Tanyakan status tiket, pelanggaran SLA, performa petugas, tren CSAT, atau jadwal booking aset.
                                    </p>

                                    <div className="mt-5 grid w-full grid-cols-1 gap-2 sm:grid-cols-2">
                                        {SUGGESTION_CHIPS.slice(0, 4).map((chip, idx) => {
                                            const ChipIcon = chip.icon;

                                            return (
                                                <button
                                                    key={idx}
                                                    type="button"
                                                    disabled={sending}
                                                    onClick={() => handleSendMessage(chip.prompt)}
                                                    className="flex items-start gap-2.5 rounded-xl border border-zinc-200/80 bg-white p-3 text-left shadow-2xs transition-all hover:border-sky-300 hover:bg-sky-50/50 disabled:opacity-50"
                                                >
                                                    <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-600">
                                                        <ChipIcon className="h-4 w-4" />
                                                    </div>
                                                    <div>
                                                        <p className="text-xs font-semibold text-zinc-900">
                                                            {chip.title}
                                                        </p>
                                                        <p className="mt-0.5 line-clamp-2 text-[11px] text-zinc-500">
                                                            {chip.prompt}
                                                        </p>
                                                    </div>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            ) : (
                                messages.map((msg) => (
                                    <div
                                        key={msg.id}
                                        className={`flex gap-3 ${
                                            msg.role === 'user' ? 'justify-end' : 'justify-start'
                                        }`}
                                    >
                                        {msg.role === 'model' && (
                                            <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-sky-200 bg-sky-50 text-sky-600 shadow-2xs">
                                                <Bot className="h-4 w-4" />
                                            </div>
                                        )}

                                        <div className="group relative max-w-[90%] sm:max-w-[82%]">
                                            <div
                                                className={`rounded-2xl px-4 py-3 text-sm shadow-2xs ${
                                                    msg.role === 'user'
                                                        ? 'rounded-tr-xs bg-sky-600 text-white shadow-sky-600/10'
                                                        : 'rounded-tl-xs border border-zinc-200 bg-white text-zinc-800'
                                                }`}
                                            >
                                                {/* Sender & Timestamp Header */}
                                                <div className="mb-1 flex items-center justify-between gap-4 text-[11px] opacity-75">
                                                    <span className="font-semibold">
                                                        {msg.role === 'user' ? 'Kamu (Admin)' : 'HaloAPU AI'}
                                                    </span>
                                                    <span>{formatMessageTime(msg.created_at)}</span>
                                                </div>

                                                {/* Message Content */}
                                                {msg.role === 'user' ? (
                                                    <p className="text-sm leading-relaxed whitespace-pre-wrap text-white">
                                                        {msg.content}
                                                    </p>
                                                ) : (
                                                    <div className="text-zinc-800">
                                                        <AiMarkdownContent content={msg.content} />

                                                        {Array.isArray(msg.charts) && msg.charts.length > 0 && (
                                                            <div className="mt-3 space-y-2.5">
                                                                {msg.charts.map((chart, cIdx) => (
                                                                    <AiChartCard
                                                                        key={`${msg.id}-chart-${chart.id}-${cIdx}`}
                                                                        chart={chart}
                                                                    />
                                                                ))}
                                                            </div>
                                                        )}

                                                        {Array.isArray(msg.tools_used) && msg.tools_used.length > 0 && (
                                                            <div className="mt-3 flex flex-wrap items-center gap-1.5">
                                                                <span className="inline-flex items-center gap-1 text-[10px] font-medium text-zinc-400">
                                                                    <Wrench className="h-3 w-3" />
                                                                    Sumber data:
                                                                </span>
                                                                {msg.tools_used.map((toolName, tIdx) => (
                                                                    <Badge
                                                                        key={`${msg.id}-tool-${tIdx}`}
                                                                        className="border-zinc-200 bg-zinc-50 px-1.5 py-0 text-[10px] font-medium text-zinc-600"
                                                                    >
                                                                        {formatToolLabel(toolName)}
                                                                    </Badge>
                                                                ))}
                                                            </div>
                                                        )}
                                                    </div>
                                                )}

                                                {/* AI Response Footer */}
                                                {msg.role === 'model' && (
                                                    <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-2 text-xs text-zinc-400">
                                                        <div className="flex items-center gap-1.5 text-[11px]">
                                                            {msg.is_fallback ? (
                                                                <span className="inline-flex items-center gap-1 text-amber-600">
                                                                    <Info className="h-3 w-3" />
                                                                    Database Internal
                                                                </span>
                                                            ) : (
                                                                <span className="inline-flex items-center gap-1 font-medium text-sky-600">
                                                                    <Sparkles className="h-3 w-3" />
                                                                    {msg.model_name ?? 'Google Gemini AI'}
                                                                </span>
                                                            )}
                                                        </div>

                                                        <div className="flex items-center gap-1.5">
                                                            <button
                                                                type="button"
                                                                onClick={() => handleCopy(msg.content, msg.id)}
                                                                className="inline-flex items-center gap-1 rounded-md border border-transparent px-2 py-1 text-[11px] text-zinc-500 transition hover:border-zinc-200 hover:bg-zinc-100 hover:text-zinc-800"
                                                                title="Salin isi laporan"
                                                            >
                                                                {copiedId === msg.id ? (
                                                                    <>
                                                                        <Check className="h-3 w-3 text-emerald-600" />
                                                                        <span className="font-medium text-emerald-600">
                                                                            Tersalin
                                                                        </span>
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        <Copy className="h-3 w-3" />
                                                                        <span>Salin</span>
                                                                    </>
                                                                )}
                                                            </button>

                                                            {activeConversation !== null && msg.id > 0 && (
                                                                <a
                                                                    href={`/admin/ai/sessions/${activeConversation.id}/export-pdf?message_id=${msg.id}`}
                                                                    className="inline-flex items-center gap-1 rounded-md border border-transparent px-2 py-1 text-[11px] text-zinc-500 transition hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700"
                                                                    title="Unduh jawaban ini sebagai PDF"
                                                                >
                                                                    <FileDown className="h-3 w-3" />
                                                                    <span>Unduh PDF</span>
                                                                </a>
                                                            )}
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        {msg.role === 'user' && (
                                            <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-zinc-300 bg-zinc-200 text-xs font-bold text-zinc-700">
                                                <User className="h-4 w-4 text-zinc-600" />
                                            </div>
                                        )}
                                    </div>
                                ))
                            )}

                            {/* Typing / Loading Animation */}
                            {sending && (
                                <div className="flex justify-start gap-3">
                                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-sky-200 bg-sky-50 text-sky-600 shadow-2xs">
                                        <Bot className="h-4 w-4" />
                                    </div>
                                    <div className="flex items-center gap-2 rounded-2xl rounded-tl-xs border border-zinc-200 bg-white px-4 py-3 text-xs text-zinc-500 shadow-2xs">
                                        <span className="flex items-center gap-1">
                                            <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-sky-500 [animation-delay:-0.3s]" />
                                            <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-sky-500 [animation-delay:-0.15s]" />
                                            <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-sky-500" />
                                        </span>
                                        <span>AI sedang menganalisis data ter-scope HaloAPU...</span>
                                    </div>
                                </div>
                            )}

                            <div ref={messagesEndRef} />
                        </div>

                        {/* Quick Suggestion Chips */}
                        <div className="border-t border-zinc-100 bg-white px-4 py-2.5">
                            <div className="no-scrollbar flex items-center gap-2 overflow-x-auto py-0.5">
                                <span className="flex shrink-0 items-center gap-1 text-[11px] font-semibold tracking-wider text-zinc-400 uppercase">
                                    <Sparkles className="h-3 w-3 text-sky-600" />
                                    Saran Cepat:
                                </span>

                                {SUGGESTION_CHIPS.map((item, idx) => {
                                    const Icon = item.icon;

                                    return (
                                        <button
                                            key={idx}
                                            type="button"
                                            disabled={sending}
                                            onClick={() => handleSendMessage(item.prompt)}
                                            className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-medium whitespace-nowrap text-zinc-700 shadow-2xs transition-all hover:border-sky-300 hover:bg-sky-50 hover:text-sky-700 active:scale-95 disabled:opacity-50"
                                        >
                                            <Icon className="h-3.5 w-3.5 text-sky-600" />
                                            <span>{item.title}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Chat Input Section */}
                        <div className="border-t border-zinc-200/80 bg-white p-3 sm:p-4">
                            <div className="flex items-end gap-2 rounded-xl border border-zinc-200 bg-zinc-50/80 p-2 transition-all focus-within:border-sky-500 focus-within:bg-white focus-within:ring-2 focus-within:ring-sky-100">
                                <textarea
                                    ref={textareaRef}
                                    value={input}
                                    onChange={(e) => setInput(e.target.value)}
                                    onKeyDown={handleKeyDown}
                                    disabled={sending}
                                    rows={1}
                                    placeholder="Tanyakan data tiket, kepatuhan SLA, kinerja petugas, CSAT, atau jadwal booking..."
                                    className="max-h-32 flex-1 resize-none overflow-y-auto border-0 bg-transparent px-2 py-1 text-xs leading-relaxed text-zinc-900 placeholder:text-zinc-400 focus:ring-0 focus:outline-none sm:text-sm"
                                />

                                <Button
                                    type="button"
                                    onClick={() => handleSendMessage()}
                                    disabled={!input.trim() || sending}
                                    size="sm"
                                    className="h-9 w-9 shrink-0 rounded-lg bg-sky-600 p-0 text-white shadow-xs hover:bg-sky-700"
                                >
                                    <Send className="h-4 w-4" />
                                </Button>
                            </div>

                            <div className="mt-2 flex items-center justify-between px-1 text-[11px] text-zinc-400">
                                <div className="flex items-center gap-1.5">
                                    <Shield className="h-3 w-3 text-sky-600" />
                                    <span>Cakupan Akses: {scopeInfo.label}</span>
                                </div>
                                <span className="hidden sm:inline">Shift + Enter untuk baris baru</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <AiBriefingModal
                briefing={selectedBriefing}
                open={briefingModalOpen}
                onOpenChange={setBriefingModalOpen}
                onAskFollowUp={handleFollowUpFromBriefing}
            />
        </AdminLayout>
    );
}
