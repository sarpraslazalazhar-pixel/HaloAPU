import React, { useEffect, useState, useRef } from 'react';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import {
    Clock, Calendar, CheckCircle, Ticket as TicketIcon,
    Activity, AlertCircle, User, ArrowRight, Play,
    TrendingUp, BarChart3
} from 'lucide-react';
import { format } from 'date-fns';
import { id as localeID } from 'date-fns/locale';
import { Toaster, toast } from 'react-hot-toast';
import ReactECharts from 'echarts-for-react';

interface TvDashboardProps {
    stats: {
        total_hari_ini: number;
        menunggu: number;
        diproses: number;
        selesai: number;
    };
    recentTickets: any[];
    upcomingBookings: any[];
    dailyChartData: any[];
    units: any[];
    notificationSound: string | null;
    logoPath: string | null;
}

const ACCENT = {
    total: '#00a2e8',
    menunggu: '#f59e0b',
    diproses: '#8b5cf6',
    selesai: '#10b981',
};

const CHART_COLORS = ['#00a2e8', '#f59e0b', '#8b5cf6', '#10b981', '#ef4444', '#ec4899', '#14b8a6', '#f97316'];

const statCards = [
    {
        label: 'Total Hari Ini',
        key: 'total_hari_ini' as const,
        icon: TicketIcon,
        accent: ACCENT.total,
        lightBg: 'bg-blue-50',
    },
    {
        label: 'Menunggu',
        key: 'menunggu' as const,
        icon: AlertCircle,
        accent: ACCENT.menunggu,
        lightBg: 'bg-amber-50',
    },
    {
        label: 'Diproses',
        key: 'diproses' as const,
        icon: Activity,
        accent: ACCENT.diproses,
        lightBg: 'bg-violet-50',
    },
    {
        label: 'Selesai',
        key: 'selesai' as const,
        icon: CheckCircle,
        accent: ACCENT.selesai,
        lightBg: 'bg-emerald-50',
    },
];

export default function TvDashboard({ stats, recentTickets, upcomingBookings, dailyChartData, units, notificationSound, logoPath }: TvDashboardProps) {
    const [currentTime, setCurrentTime] = useState(new Date());
    const [hasInteracted, setHasInteracted] = useState(false);
    const audioContextRef = useRef<AudioContext | null>(null);
    const audioBufferRef = useRef<AudioBuffer | null>(null);
    const prevLatestTicketIdRef = useRef<number | null>(null);
    const prevTicketsRef = useRef<any[]>([]);
    const [newTicketIds, setNewTicketIds] = useState<Set<number>>(new Set());
    const tableRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (typeof window !== 'undefined') {
            const cacheBuster = notificationSound ? `?v=${encodeURIComponent(notificationSound)}` : '';
            const soundUrl = route('system.notification-sound') + cacheBuster;
            const AudioContextClass = window.AudioContext || (window as any).webkitAudioContext;
            audioContextRef.current = new AudioContextClass();
            fetch(soundUrl)
                .then(res => res.arrayBuffer())
                .then(buffer => audioContextRef.current?.decodeAudioData(buffer))
                .then(decodedData => {
                    if (decodedData) audioBufferRef.current = decodedData;
                })
                .catch(err => console.error("Gagal memuat file audio:", err));
        }
    }, [notificationSound]);

    useEffect(() => {
        const timer = setInterval(() => setCurrentTime(new Date()), 1000);
        return () => clearInterval(timer);
    }, []);

    useEffect(() => {
        const poll = setInterval(() => {
            router.reload({ only: ['stats', 'recentTickets', 'upcomingBookings', 'dailyChartData'] });
        }, 15000);
        return () => clearInterval(poll);
    }, []);

    useEffect(() => {
        if (recentTickets && recentTickets.length > 0) {
            const currentLatestId = recentTickets[0].id;
            if (prevLatestTicketIdRef.current !== null && currentLatestId > prevLatestTicketIdRef.current) {
                if (hasInteracted) {
                    if (audioContextRef.current && audioBufferRef.current) {
                        try {
                            if (audioContextRef.current.state === 'suspended') {
                                audioContextRef.current.resume();
                            }
                            const source = audioContextRef.current.createBufferSource();
                            source.buffer = audioBufferRef.current;
                            const gainNode = audioContextRef.current.createGain();
                            gainNode.gain.value = 1.0;
                            source.connect(gainNode);
                            gainNode.connect(audioContextRef.current.destination);
                            source.start(0);
                        } catch (e) {
                            console.error('Web Audio API error:', e);
                        }
                    }
                }
                if (prevTicketsRef.current.length > 0) {
                    const prevIds = new Set(prevTicketsRef.current.map((t: any) => t.id));
                    const added = new Set(
                        recentTickets.map(t => t.id).filter(id => !prevIds.has(id))
                    );
                    if (added.size > 0) {
                        setNewTicketIds(added);
                        recentTickets
                            .filter(t => added.has(t.id))
                            .forEach(t => {
                                toast.success(
                                    `Tiket baru: ${t.ticket_number} - ${t.user?.name || '-'}`,
                                    { id: `tv-ticket-${t.id}`, duration: 4000 }
                                );
                            });
                        setTimeout(() => setNewTicketIds(new Set()), 4000);
                    }
                }
            }
            prevLatestTicketIdRef.current = currentLatestId;
            prevTicketsRef.current = recentTickets;
        }
    }, [recentTickets]);

    useEffect(() => {
        const el = tableRef.current;
        if (!el) return;
        let rafId: number;
        let startTime: number | null = null;
        const duration = 10000;
        const step = (timestamp: number) => {
            if (!startTime) startTime = timestamp;
            const elapsed = timestamp - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const maxScroll = el.scrollHeight - el.clientHeight;
            if (maxScroll <= 0) return;
            el.scrollTop = progress * maxScroll;
            if (progress < 1) {
                rafId = requestAnimationFrame(step);
            } else {
                setTimeout(() => {
                    el.scrollTo({ top: 0, behavior: 'smooth' });
                }, 4000);
            }
        };
        const delayId = setTimeout(() => {
            startTime = null;
            rafId = requestAnimationFrame(step);
        }, 5000);
        return () => {
            clearTimeout(delayId);
            if (rafId) cancelAnimationFrame(rafId);
        };
    }, [recentTickets]);

    const getStatusBadge = (status: string) => {
        const map: Record<string, { variant: "default" | "secondary" | "outline" | "destructive" | "ghost" | "link"; className: string }> = {
            'Menunggu': { variant: 'outline', className: 'bg-amber-50 text-amber-700 border-amber-200' },
            'Diproses': { variant: 'outline', className: 'bg-blue-50 text-blue-700 border-blue-200' },
            'Eskalasi': { variant: 'destructive', className: '' },
            'Menunggu Konfirmasi': { variant: 'outline', className: 'bg-orange-50 text-orange-700 border-orange-200' },
            'Selesai': { variant: 'outline', className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
        };
        return map[status] || { variant: 'outline' as const, className: 'bg-slate-50 text-slate-600 border-slate-200' };
    };

    if (!hasInteracted) {
        return (
            <div className="min-h-screen bg-gradient-to-br from-sky-50 via-white to-blue-50 flex flex-col items-center justify-center text-slate-900 p-6 relative overflow-hidden">
                <div className="absolute inset-0 overflow-hidden">
                    <div className="absolute -top-40 -right-40 w-[500px] h-[500px] rounded-full bg-gradient-to-br from-blue-200/40 to-sky-100/20 blur-3xl" />
                    <div className="absolute -bottom-40 -left-40 w-[500px] h-[500px] rounded-full bg-gradient-to-tr from-cyan-200/30 to-blue-100/10 blur-3xl" />
                </div>
                <div className="relative z-10 flex flex-col items-center max-w-2xl text-center">
                    <div className="w-28 h-28 bg-white rounded-3xl flex items-center justify-center mb-8 shadow-xl shadow-blue-200/50 ring-1 ring-blue-100">
                        <Activity className="w-14 h-14 text-[#00a2e8]" />
                    </div>
                    <h1 className="text-5xl md:text-6xl font-bold mb-4 tracking-tight text-slate-900">
                        Live Dashboard
                    </h1>
                    <p className="text-slate-500 mb-10 text-xl md:text-2xl leading-relaxed max-w-lg">
                        Klik tombol di bawah untuk mengaktifkan notifikasi suara dan memulai dashboard.
                    </p>
                    <button
                        onClick={() => {
                            setHasInteracted(true);
                            if (audioContextRef.current && audioContextRef.current.state === 'suspended') {
                                audioContextRef.current.resume();
                            }
                        }}
                        className="group relative inline-flex items-center justify-center gap-3 px-10 py-5 font-bold text-white text-lg transition-all duration-200 bg-[#00a2e8] rounded-2xl hover:bg-[#0081b8] shadow-lg shadow-blue-200/50 active:scale-[0.98]"
                    >
                        <Play className="w-6 h-6 fill-white" />
                        Mulai Dashboard
                    </button>
                </div>
            </div>
        );
    }

    const chartOption = {
        tooltip: {
            trigger: 'axis' as const,
            backgroundColor: 'rgba(255,255,255,0.95)',
            borderColor: '#e2e8f0',
            borderWidth: 1,
            textStyle: { fontSize: 14, color: '#1e293b' },
        },
        legend: {
            bottom: 0,
            textStyle: { fontSize: 14, color: '#64748b' },
            icon: 'roundRect',
            itemWidth: 16,
            itemHeight: 8,
        },
        grid: { left: '2%', right: '2%', bottom: '18%', top: '8%', containLabel: true },
        xAxis: {
            type: 'category' as const,
            data: dailyChartData.map((d: any) => {
                const parts = d.date.split('-');
                return `${parts[2]}/${parts[1]}`;
            }),
            axisLabel: { fontSize: 14, color: '#94a3b8' },
            axisLine: { lineStyle: { color: '#e2e8f0' } },
            axisTick: { show: false },
        },
        yAxis: {
            type: 'value' as const,
            axisLabel: { fontSize: 14, color: '#94a3b8' },
            splitLine: { lineStyle: { color: '#f1f5f9', type: 'dashed' as const } },
        },
        series: units.map((u: any, i: number) => ({
            name: u.nama_unit,
            type: 'line' as const,
            smooth: true,
            symbol: 'circle' as const,
            symbolSize: 8,
            lineStyle: { width: 3 },
            itemStyle: { color: CHART_COLORS[i % CHART_COLORS.length] },
            areaStyle: {
                color: {
                    type: 'linear' as const,
                    x: 0, y: 0, x2: 0, y2: 1,
                    colorStops: [
                        { offset: 0, color: CHART_COLORS[i % CHART_COLORS.length] + '30' },
                        { offset: 1, color: CHART_COLORS[i % CHART_COLORS.length] + '05' },
                    ],
                },
            },
            data: dailyChartData.map((d: any) => d[u.nama_unit] || 0),
        })),
    };

    return (
        <div className="min-h-screen bg-gradient-to-br from-slate-50 via-white to-blue-50/30 text-slate-900 font-sans flex flex-col overflow-hidden">
            <Head title="Live Dashboard Operasional" />
            <Toaster
                position="top-right"
                toastOptions={{
                    duration: 4000,
                    style: {
                        background: '#1e293b',
                        color: '#f8fafc',
                        fontSize: '1rem',
                        borderRadius: '1rem',
                        padding: '0.75rem 1.25rem',
                    },
                }}
            />

            <header className="bg-white/80 backdrop-blur-xl border-b border-slate-200/60 px-8 py-5 flex justify-between items-center shadow-sm">
                <div className="flex items-center gap-6">
                    {logoPath ? (
                        <img
                            src={`/storage/${logoPath}`}
                            alt="Banner"
                            className="h-20 w-auto object-contain"
                        />
                    ) : (
                        <div className="w-16 h-16 bg-gradient-to-br from-[#00a2e8] to-[#0081b8] rounded-2xl flex items-center justify-center shadow-lg shadow-blue-200/50">
                            <Activity className="w-9 h-9 text-white" />
                        </div>
                    )}
                    <div className="hidden sm:block h-10 w-px bg-slate-200" />
                    <div className="hidden sm:flex items-center gap-2">
                        <span className="relative flex h-3 w-3">
                            <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 animate-ping opacity-75" />
                            <span className="relative inline-flex rounded-full h-3 w-3 bg-emerald-500" />
                        </span>
                        <span className="text-sm font-medium text-slate-400 uppercase tracking-wider">Live</span>
                    </div>
                </div>
                <div className="text-right">
                    <div className="text-5xl md:text-6xl font-mono font-bold tracking-widest text-slate-800 tabular-nums">
                        {format(currentTime, 'HH:mm:ss')}
                    </div>
                    <div className="text-base md:text-lg text-slate-400 uppercase tracking-widest font-medium mt-1">
                        {format(currentTime, 'EEEE, dd MMMM yyyy', { locale: localeID })}
                    </div>
                </div>
            </header>

            <main className="flex-1 p-6 md:p-8 flex flex-col gap-6 overflow-hidden">
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">
                    {statCards.map(({ label, key, icon: Icon, accent, lightBg }) => (
                        <Card
                            key={label}
                            className="border-0 shadow-md shadow-slate-200/50 bg-white overflow-hidden relative group"
                        >
                            <div
                                className="absolute top-0 left-0 right-0 h-1.5"
                                style={{ backgroundColor: accent }}
                            />
                            <CardContent className="p-6 md:p-8 relative">
                                <div className="flex items-start justify-between">
                                    <div className="space-y-2">
                                        <p className="text-slate-400 text-sm font-semibold tracking-wider uppercase">
                                            {label}
                                        </p>
                                        <h3
                                            className="text-5xl md:text-6xl lg:text-7xl font-bold tracking-tight tabular-nums"
                                            style={{ color: accent }}
                                        >
                                            {stats[key]}
                                        </h3>
                                    </div>
                                    <div className={`p-4 md:p-5 rounded-2xl ${lightBg} shadow-sm ring-1 ring-slate-200/50`}>
                                        <Icon className="w-8 h-8 md:w-10 md:h-10" style={{ color: accent }} />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="flex-1 grid grid-cols-1 lg:grid-cols-3 gap-6 overflow-hidden min-h-0">
                    <Card className="lg:col-span-2 border-0 shadow-md shadow-slate-200/50 bg-white flex flex-col overflow-hidden">
                        <div className="px-6 md:px-8 py-5 flex justify-between items-center border-b border-slate-100">
                            <h2 className="text-2xl md:text-3xl font-bold text-slate-800 flex items-center gap-3">
                                <TicketIcon className="w-6 h-6 md:w-7 md:h-7 text-[#00a2e8]" />
                                Tiket Terbaru
                            </h2>
                            <div className="flex items-center gap-2 text-sm text-slate-400 font-medium">
                                <span className="relative flex h-2.5 w-2.5">
                                    <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 animate-ping opacity-75" />
                                    <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500" />
                                </span>
                                Live
                            </div>
                        </div>
                        <div ref={tableRef} className="flex-1 overflow-auto">
                            <table className="w-full text-left">
                                <thead className="bg-slate-50/80 sticky top-0 z-10">
                                    <tr>
                                        {['Tiket', 'Pengaju', 'Unit Tujuan', 'Layanan', 'Status', 'Waktu'].map(h => (
                                            <th key={h} className="px-6 md:px-8 py-4 font-semibold text-xs uppercase tracking-widest text-slate-400">
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {recentTickets.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="px-8 py-20 text-center text-slate-400 text-xl font-medium">
                                                Belum ada tiket hari ini
                                            </td>
                                        </tr>
                                    ) : (
                                        recentTickets.map((ticket, index) => {
                                            const isNew = newTicketIds.has(ticket.id);
                                            const badge = getStatusBadge(ticket.status);
                                            return (
                                                <tr
                                                    key={ticket.id}
                                                    className={`transition-all duration-700 ${
                                                        isNew
                                                            ? 'bg-amber-50/60'
                                                            : index % 2 === 0
                                                                ? 'bg-white'
                                                                : 'bg-slate-50/30'
                                                    } hover:bg-slate-50/50`}
                                                >
                                                    <td className="px-6 md:px-8 py-5 font-mono font-bold text-slate-900 text-base md:text-lg tracking-tight">
                                                        {ticket.ticket_number}
                                                    </td>
                                                    <td className="px-6 md:px-8 py-5 font-semibold text-slate-700 text-base md:text-lg">
                                                        {ticket.user?.name}
                                                    </td>
                                                    <td className="px-6 md:px-8 py-5 text-slate-500 text-base md:text-lg">
                                                        {ticket.unit?.nama_unit}
                                                    </td>
                                                    <td className="px-6 md:px-8 py-5 text-slate-500 text-base md:text-lg">
                                                        {ticket.sub_unit?.nama_layanan}
                                                    </td>
                                                    <td className="px-6 md:px-8 py-5">
                                                        <Badge
                                                            variant={badge.variant}
                                                            className={`px-3 md:px-4 py-1.5 text-sm md:text-base font-semibold rounded-full ${badge.className}`}
                                                        >
                                                            {ticket.status}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-6 md:px-8 py-5 text-right text-slate-400 text-base md:text-lg font-medium tabular-nums">
                                                        {format(new Date(ticket.created_at), 'HH:mm')}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </Card>

                    <Card className="border-0 shadow-md shadow-slate-200/50 bg-white flex flex-col overflow-hidden">
                        <div className="px-6 md:px-8 py-5 border-b border-slate-100">
                            <h2 className="text-2xl md:text-3xl font-bold text-slate-800 flex items-center gap-3">
                                <Calendar className="w-6 h-6 md:w-7 md:h-7 text-[#00a2e8]" />
                                Jadwal Booking
                            </h2>
                        </div>
                        <div className="flex-1 overflow-auto p-5 md:p-6 space-y-4">
                            {upcomingBookings.length === 0 ? (
                                <div className="text-center text-slate-400 text-lg py-16 font-medium">
                                    Tidak ada jadwal booking mendatang
                                </div>
                            ) : (
                                upcomingBookings.map(booking => {
                                    const isToday =
                                        new Date(booking.tanggal_mulai).toDateString() ===
                                        currentTime.toDateString();
                                    return (
                                        <div
                                            key={booking.id}
                                            className={`p-5 md:p-6 rounded-xl border transition-all ${
                                                isToday
                                                    ? 'bg-gradient-to-r from-blue-50/80 to-white border-blue-200 shadow-sm shadow-blue-100/50'
                                                    : 'bg-white border-slate-200 hover:border-slate-300 shadow-sm'
                                            }`}
                                        >
                                            <div className="flex justify-between items-start mb-3">
                                                <Badge
                                                    variant={isToday ? 'default' : 'outline'}
                                                    className={
                                                        isToday
                                                            ? 'bg-[#00a2e8] text-white text-xs px-3 py-1 font-semibold tracking-wider uppercase'
                                                            : 'text-slate-400 border-slate-200 text-xs px-3 py-1 font-semibold'
                                                    }
                                                >
                                                    {isToday
                                                        ? 'Hari Ini'
                                                        : format(new Date(booking.tanggal_mulai), 'dd MMM', {
                                                              locale: localeID,
                                                          })}
                                                </Badge>
                                                <span className="text-sm font-mono font-semibold text-slate-400">
                                                    {booking.ticket?.ticket_number}
                                                </span>
                                            </div>
                                            <h3 className="text-xl md:text-2xl font-bold text-slate-900 mb-2">
                                                {booking.nama_aset}
                                            </h3>
                                            <p className="text-slate-500 font-medium text-base md:text-lg mb-4 flex items-center gap-2">
                                                <User className="w-4 h-4 md:w-5 md:h-5 text-slate-400" />
                                                {booking.ticket?.user?.name || '-'}
                                            </p>
                                            <div className="flex items-center gap-2 md:gap-3 text-sm md:text-base font-medium text-slate-500 bg-slate-50/80 p-3 md:p-4 rounded-xl border border-slate-100">
                                                <Clock className="w-4 h-4 md:w-5 md:h-5 text-slate-400 shrink-0" />
                                                <span className="font-mono font-semibold tracking-tight text-slate-700">
                                                    {format(new Date(booking.tanggal_mulai), 'HH:mm')}
                                                </span>
                                                <ArrowRight className="w-3 h-3 md:w-4 md:h-4 text-slate-300" />
                                                <span className="font-mono font-semibold tracking-tight text-slate-700">
                                                    {format(new Date(booking.tanggal_selesai), 'HH:mm')}
                                                </span>
                                            </div>
                                        </div>
                                    );
                                })
                            )}
                        </div>
                    </Card>
                </div>

                <div className="shrink-0">
                    <Card className="border-0 shadow-md shadow-slate-200/50 bg-white flex flex-col">
                        <div className="px-6 md:px-8 py-5 border-b border-slate-100 flex items-center justify-between">
                            <h2 className="text-2xl md:text-3xl font-bold text-slate-800 flex items-center gap-3">
                                <TrendingUp className="w-6 h-6 md:w-7 md:h-7 text-[#00a2e8]" />
                                Grafik Harian Tiket (7 Hari)
                            </h2>
                            <BarChart3 className="w-5 h-5 text-slate-300 hidden sm:block" />
                        </div>
                        <div className="p-4 md:p-6">
                            {dailyChartData?.length > 0 ? (
                                <ReactECharts option={chartOption} style={{ height: 280, width: '100%' }} />
                            ) : (
                                <div className="h-[280px] flex items-center justify-center text-slate-400 text-lg font-medium">
                                    Belum ada data harian
                                </div>
                            )}
                        </div>
                    </Card>
                </div>
            </main>
        </div>
    );
}
