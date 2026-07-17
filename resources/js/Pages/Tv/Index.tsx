import React, { useEffect, useState, useRef } from 'react';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Clock, Calendar, CheckCircle, Ticket as TicketIcon, Activity, AlertCircle, User, ArrowRight, Play } from 'lucide-react';
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

const statCards = [
    {
        label: 'Total Hari Ini',
        key: 'total_hari_ini' as const,
        icon: TicketIcon,
        borderColor: 'border-b-blue-500',
        iconBg: 'bg-blue-50 text-blue-600',
        valueColor: 'text-blue-600',
        gradient: 'from-blue-500/10 via-blue-400/5 to-transparent',
    },
    {
        label: 'Menunggu',
        key: 'menunggu' as const,
        icon: AlertCircle,
        borderColor: 'border-b-yellow-500',
        iconBg: 'bg-yellow-50 text-yellow-600',
        valueColor: 'text-yellow-600',
        gradient: 'from-yellow-500/10 via-yellow-400/5 to-transparent',
    },
    {
        label: 'Diproses',
        key: 'diproses' as const,
        icon: Activity,
        borderColor: 'border-b-purple-500',
        iconBg: 'bg-purple-50 text-purple-600',
        valueColor: 'text-purple-600',
        gradient: 'from-purple-500/10 via-purple-400/5 to-transparent',
    },
    {
        label: 'Selesai',
        key: 'selesai' as const,
        icon: CheckCircle,
        borderColor: 'border-b-green-500',
        iconBg: 'bg-green-50 text-green-600',
        valueColor: 'text-green-600',
        gradient: 'from-green-500/10 via-green-400/5 to-transparent',
    },
];

export default function TvDashboard({ stats: initialStats, recentTickets: initialRecentTickets, upcomingBookings: initialUpcomingBookings, dailyChartData, units, notificationSound, logoPath }: TvDashboardProps) {
    const [currentTime, setCurrentTime] = useState(new Date());
    const [hasInteracted, setHasInteracted] = useState(false);
    const [data, setData] = useState({ stats: initialStats, recent_tickets: initialRecentTickets, upcoming_bookings: initialUpcomingBookings });
    const [latestTicket, setLatestTicket] = useState<any>(null);
    const previousTicketsRef = useRef<any[]>(initialRecentTickets);
    const tableRef = useRef<HTMLDivElement>(null);
    const audioContextRef = useRef<AudioContext | null>(null);
    const audioBufferRef = useRef<AudioBuffer | null>(null);
    
    // Missing state & refs restored
    const prevLatestTicketIdRef = useRef<number | null>(null);
    const prevTicketsRef = useRef<any[]>([]);
    const [newTicketIds, setNewTicketIds] = useState<Set<number>>(new Set());

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

    const fetchDashboardData = useCallback(async () => {
        try {
            const response = await axios.get(route('tv.index'), {
                headers: { Accept: 'application/json' }
            });
            const newData = response.data;

            if (previousTicketsRef.current) {
                const newTickets = newData.recent_tickets.filter((ticket: any) =>
                    !previousTicketsRef.current.some((prev: any) => prev.id === ticket.id)
                );

                if (newTickets.length > 0) {
                    setLatestTicket(newTickets[0]);
                    
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
                            toast.error('Gagal memutar suara notifikasi. Pastikan browser mengizinkan pemutaran audio.', {
                                id: 'audio-error'
                            });
                        }
                    }
                }
            }

            previousTicketsRef.current = newData.recent_tickets;
            setData(newData);
        } catch (error) {
            console.error('Error fetching TV dashboard data:', error);
        }
    }, []);

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

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'Menunggu': return 'bg-yellow-100 text-yellow-800 border-yellow-300';
            case 'Diproses': return 'bg-blue-100 text-blue-800 border-blue-300';
            case 'Selesai': return 'bg-green-100 text-green-800 border-green-300';
            default: return 'bg-slate-100 text-slate-700 border-slate-300';
        }
    };

    if (!hasInteracted) {
        return (
            <div className="min-h-screen bg-slate-900 flex flex-col items-center justify-center text-white p-6 relative overflow-hidden">
                <div className="absolute inset-0 bg-blue-900/20 blur-3xl rounded-full w-96 h-96 top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2" />
                
                <div className="relative z-10 flex flex-col items-center max-w-lg text-center">
                    <div className="w-24 h-24 bg-blue-600/20 rounded-3xl flex items-center justify-center mb-8 shadow-[0_0_50px_rgba(37,99,235,0.3)]">
                        <Activity className="w-12 h-12 text-blue-500 animate-pulse" />
                    </div>
                    
                    <h1 className="text-4xl md:text-5xl font-bold mb-4 tracking-tight">Live Dashboard</h1>
                    <p className="text-slate-400 mb-10 text-lg md:text-xl leading-relaxed">
                        Dashboard pasif ini membutuhkan interaksi pertama kali untuk mengizinkan browser memutar suara notifikasi otomatis.
                    </p>
                    
                    <button
                        onClick={() => {
                            setHasInteracted(true);
                            // Trik untuk me-unlock audio context di browser modern
                            if (audioContextRef.current && audioContextRef.current.state === 'suspended') {
                                audioContextRef.current.resume();
                            }
                        }}
                        className="group relative inline-flex items-center justify-center gap-3 px-8 py-4 font-bold text-white transition-all duration-200 bg-blue-600 border border-transparent rounded-2xl hover:bg-blue-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-600 focus:ring-offset-slate-900"
                    >
                        <Play className="w-6 h-6 fill-white" />
                        Mulai Dashboard
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-100 text-slate-900 font-sans flex flex-col overflow-hidden">
            <Head title="Live Dashboard Operasional" />
            <Toaster
                position="bottom-right"
                toastOptions={{
                    duration: 4000,
                    style: {
                        background: '#1e293b',
                        color: '#f8fafc',
                        fontSize: '1rem',
                        borderRadius: '0.75rem',
                        padding: '0.75rem 1rem',
                    },
                    success: {
                        iconTheme: {
                            primary: '#22c55e',
                            secondary: '#f0fdf4',
                        },
                    },
                }}
            />

            <header className="bg-white/90 backdrop-blur-md border-b border-slate-200 px-6 py-4 flex justify-between items-center shadow-sm">
                <div className="flex items-center">
                    {logoPath ? (
                        <img
                            id="displayBannerImg"
                            src={`/storage/${logoPath}`}
                            alt="Banner"
                            className="h-[120px] w-auto object-contain"
                        />
                    ) : (
                        <div className="w-16 h-16 bg-primary rounded-2xl flex items-center justify-center shadow-lg shadow-primary/25">
                            <Activity className="w-9 h-9 text-white" />
                        </div>
                    )}
                </div>
                <div className="text-right">
                    <div className="text-5xl md:text-6xl font-mono font-bold tracking-wider text-primary">
                        {format(currentTime, 'HH:mm:ss')}
                    </div>
                    <div className="text-base md:text-lg text-slate-500 uppercase tracking-widest font-medium mt-1">
                        {format(currentTime, 'EEEE, dd MMMM yyyy', { locale: localeID })}
                    </div>
                </div>
            </header>

            <main className="flex-1 p-6 flex flex-col gap-6 overflow-hidden">
                <div className="grid grid-cols-4 gap-5">
                    {statCards.map(({ label, key, icon: Icon, borderColor, valueColor, iconBg, gradient }) => (
                        <Card
                            key={label}
                            className={`bg-gradient-to-br from-white to-slate-50 border-slate-200 shadow-md border-b-4 ${borderColor} overflow-hidden relative`}
                        >
                            <div
                                className={`absolute top-0 right-0 -mr-6 -mt-6 w-40 h-40 bg-gradient-to-br ${gradient} rounded-full blur-3xl`}
                            />
                            <CardContent className="p-6 relative z-10">
                                <div className="flex justify-between items-start">
                                    <div>
                                        <p className="text-slate-500 text-lg font-semibold tracking-wide">
                                            {label}
                                        </p>
                                        <h3
                                            className={`text-7xl md:text-8xl font-bold tracking-tight mt-2 ${valueColor}`}
                                        >
                                            {stats[key]}
                                        </h3>
                                    </div>
                                    <div className={`p-5 rounded-2xl ${iconBg} shadow-sm`}>
                                        <Icon className="w-11 h-11" />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="flex-1 grid grid-cols-3 gap-6 overflow-hidden">
                    <Card className="col-span-2 bg-white border-slate-200 shadow-md flex flex-col overflow-hidden">
                        <div className="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-gradient-to-r from-white to-slate-50">
                            <h2 className="text-3xl font-bold flex items-center gap-3 text-slate-800">
                                <TicketIcon className="w-7 h-7 text-primary" />
                                Tiket Terbaru
                            </h2>
                            <div className="flex items-center gap-2 text-sm text-slate-500 font-medium">
                                <span className="relative flex h-3 w-3">
                                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75" />
                                    <span className="relative inline-flex rounded-full h-3 w-3 bg-green-500" />
                                </span>
                                Live Feed
                            </div>
                        </div>
                        <div ref={tableRef} className="flex-1 overflow-auto">
                            <table className="w-full text-left text-xl">
                                <thead className="bg-slate-100 text-slate-600 sticky top-0 backdrop-blur-sm shadow-sm z-10">
                                    <tr>
                                        <th className="px-8 py-5 font-semibold uppercase tracking-wider text-sm">
                                            Tiket
                                        </th>
                                        <th className="px-8 py-5 font-semibold uppercase tracking-wider text-sm">
                                            Pengaju
                                        </th>
                                        <th className="px-8 py-5 font-semibold uppercase tracking-wider text-sm">
                                            Unit Tujuan
                                        </th>
                                        <th className="px-8 py-5 font-semibold uppercase tracking-wider text-sm">
                                            Layanan
                                        </th>
                                        <th className="px-8 py-5 font-semibold uppercase tracking-wider text-sm">
                                            Status
                                        </th>
                                        <th className="px-8 py-5 font-semibold uppercase tracking-wider text-sm text-right">
                                            Waktu
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {recentTickets.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="px-8 py-16 text-center text-slate-500 text-2xl font-medium"
                                            >
                                                Belum ada tiket hari ini
                                            </td>
                                        </tr>
                                    ) : (
                                        recentTickets.map((ticket, index) => {
                                            const isNew = newTicketIds.has(ticket.id);
                                            return (
                                                <tr
                                                    key={ticket.id}
                                                    className={`transition-all duration-700 ${
                                                        isNew
                                                            ? 'bg-yellow-50/80'
                                                            : index % 2 === 0
                                                              ? 'bg-white'
                                                              : 'bg-slate-50/40'
                                                    } hover:bg-slate-50`}
                                                >
                                                    <td className="px-8 py-6 font-mono font-bold text-slate-900 text-xl tracking-tight">
                                                        {ticket.ticket_number}
                                                    </td>
                                                    <td className="px-8 py-6 font-semibold text-slate-700">
                                                        {ticket.user?.name}
                                                    </td>
                                                    <td className="px-8 py-6 text-slate-600">
                                                        {ticket.unit?.nama_unit}
                                                    </td>
                                                    <td className="px-8 py-6 text-slate-600">
                                                        {ticket.sub_unit?.nama_layanan}
                                                    </td>
                                                    <td className="px-8 py-6">
                                                        <Badge
                                                            className={`px-4 py-2 text-base font-semibold border rounded-lg ${getStatusColor(ticket.status)}`}
                                                        >
                                                            {ticket.status}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-8 py-6 text-right text-slate-500 text-lg font-medium tabular-nums">
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

                    <Card className="col-span-1 bg-white border-slate-200 shadow-md flex flex-col overflow-hidden">
                        <div className="px-6 py-4 border-b border-slate-200 bg-gradient-to-r from-white to-slate-50">
                            <h2 className="text-3xl font-bold flex items-center gap-3 text-slate-800">
                                <Calendar className="w-7 h-7 text-indigo-500" />
                                Jadwal Booking
                            </h2>
                        </div>
                        <div className="flex-1 overflow-auto p-5 space-y-5">
                            {upcomingBookings.length === 0 ? (
                                <div className="text-center text-slate-500 text-xl py-16 font-medium">
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
                                            className={`p-5 rounded-xl border ${
                                                isToday
                                                    ? 'bg-gradient-to-r from-indigo-50 to-white border-indigo-200 border-l-4 border-l-indigo-500'
                                                    : 'bg-gradient-to-r from-white to-slate-50/80 border-slate-200 border-l-4 border-l-slate-300'
                                            } shadow-sm`}
                                        >
                                            <div className="flex justify-between items-start mb-3">
                                                <Badge
                                                    className={
                                                        isToday
                                                            ? 'bg-indigo-500 text-white border-indigo-500 text-sm px-3 py-1'
                                                            : 'text-slate-600 border-slate-300 text-sm px-3 py-1'
                                                    }
                                                >
                                                    {isToday
                                                        ? 'HARI INI'
                                                        : format(new Date(booking.tanggal_mulai), 'dd MMM yyyy', {
                                                              locale: localeID,
                                                          })}
                                                </Badge>
                                                <span className="text-sm font-mono font-semibold text-slate-500">
                                                    {booking.ticket?.ticket_number}
                                                </span>
                                            </div>
                                            <h3 className="text-2xl font-bold text-slate-900 mb-2">
                                                {booking.nama_aset}
                                            </h3>
                                            <p className="text-slate-600 font-medium text-lg mb-4 flex items-center gap-2">
                                                <User className="w-5 h-5 text-slate-400" />
                                                {booking.ticket?.user?.name || '-'}
                                            </p>
                                            <div className="flex items-center gap-3 text-base font-medium text-slate-600 bg-slate-100/80 p-3 rounded-xl border border-slate-200">
                                                <Clock className="w-5 h-5 text-slate-400 shrink-0" />
                                                <span className="font-mono font-semibold tracking-tight">
                                                    {format(new Date(booking.tanggal_mulai), 'HH:mm')}
                                                </span>
                                                <ArrowRight className="w-4 h-4 text-slate-300" />
                                                <span className="font-mono font-semibold tracking-tight">
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

                <div className="flex-1 shrink-0 overflow-hidden mb-6">
                    <Card className="h-full bg-white border-slate-200 shadow-md flex flex-col">
                        <div className="px-6 py-4 border-b border-slate-200 bg-gradient-to-r from-white to-slate-50">
                            <h2 className="text-3xl font-bold flex items-center gap-3 text-slate-800">
                                <Activity className="w-7 h-7 text-green-500" />
                                Grafik Harian Tiket (30 Hari)
                            </h2>
                        </div>
                        <div className="p-4 flex-1">
                            {dailyChartData?.length > 0 ? (
                                <ReactECharts option={{
                                    tooltip: { trigger: 'axis' },
                                    legend: { bottom: 0, textStyle: { fontSize: 16 } },
                                    grid: { left: '2%', right: '2%', bottom: '15%', top: '10%', containLabel: true },
                                    xAxis: { type: 'category', data: dailyChartData.map((d: any) => d.date), axisLabel: { fontSize: 14 } },
                                    yAxis: { type: 'value', axisLabel: { fontSize: 14 } },
                                    series: units.map((u: any) => ({
                                        name: u.nama_unit,
                                        type: 'line',
                                        smooth: true,
                                        symbolSize: 8,
                                        lineStyle: { width: 4 },
                                        data: dailyChartData.map((d: any) => d[u.nama_unit] || 0)
                                    }))
                                }} style={{ height: '100%', width: '100%', minHeight: 300 }} />
                            ) : (
                                <div className="h-full flex items-center justify-center text-slate-500 text-xl">
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
