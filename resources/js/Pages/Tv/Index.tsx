import React, { useEffect, useState, useRef } from 'react';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import {
    Clock, Calendar, CheckCircle, Ticket as TicketIcon,
    Activity, AlertCircle, User, ArrowRight, Play
} from 'lucide-react';
import { format } from 'date-fns';
import { id as localeID } from 'date-fns/locale';
import { Toaster, toast } from 'react-hot-toast';

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

const statCards = [
    {
        label: 'Tiket Baru',
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

export default function TvDashboard({ stats, recentTickets, upcomingBookings, notificationSound, logoPath }: TvDashboardProps) {
    const [currentTime, setCurrentTime] = useState(new Date());
    const [hasInteracted, setHasInteracted] = useState(false);
    const audioContextRef = useRef<AudioContext | null>(null);
    const audioBufferRef = useRef<AudioBuffer | null>(null);
    const prevLatestTicketIdRef = useRef<number | null>(null);
    const prevTicketsRef = useRef<any[]>([]);

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
                        recentTickets
                            .filter(t => added.has(t.id))
                            .forEach(t => {
                                toast.success(
                                    `Tiket baru: ${t.formatted_id} - ${t.user?.name || '-'}`,
                                    { id: `tv-ticket-${t.id}`, duration: 4000 }
                                );
                            });
                    }
                }
            }
            prevLatestTicketIdRef.current = currentLatestId;
            prevTicketsRef.current = recentTickets;
        }
    }, [recentTickets, hasInteracted]);

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

    return (
        <div className="h-screen bg-gradient-to-br from-slate-50 via-white to-blue-50/30 text-slate-900 font-sans flex flex-col overflow-hidden">
            <Head title="Live Dashboard Operasional" />
            <Toaster
                position="top-center"
                toastOptions={{
                    duration: 4000,
                    style: {
                        background: '#1e293b',
                        color: '#f8fafc',
                        fontSize: '1.25rem',
                        borderRadius: '1rem',
                        padding: '1rem 1.5rem',
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
                    <div className="hidden sm:flex items-center gap-3">
                        <span className="relative flex h-4 w-4">
                            <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 animate-ping opacity-75" />
                            <span className="relative inline-flex rounded-full h-4 w-4 bg-emerald-500" />
                        </span>
                        <span className="text-lg font-bold text-slate-400 uppercase tracking-wider">Live</span>
                    </div>
                </div>
                <div className="text-right">
                    <div className="text-5xl md:text-7xl font-mono font-bold tracking-widest text-slate-800 tabular-nums">
                        {format(currentTime, 'HH:mm:ss')}
                    </div>
                    <div className="text-xl md:text-2xl text-slate-400 uppercase tracking-widest font-bold mt-2">
                        {format(currentTime, 'EEEE, dd MMMM yyyy', { locale: localeID })}
                    </div>
                </div>
            </header>

            <main className="flex-1 p-4 md:p-6 flex flex-col gap-4 overflow-hidden">
                <div className="grid grid-cols-4 gap-4 lg:gap-6 shrink-0">
                    {statCards.map(({ label, key, icon: Icon, accent, lightBg }) => (
                        <Card
                            key={label}
                            className="border-0 shadow-lg shadow-slate-200/50 bg-white overflow-hidden relative group"
                        >
                            <div
                                className="absolute top-0 left-0 right-0 h-2"
                                style={{ backgroundColor: accent }}
                            />
                            <CardContent className="p-4 md:p-6 relative flex flex-col md:flex-row items-center md:justify-between gap-4">
                                <div className="space-y-1 text-center md:text-left order-2 md:order-1">
                                    <p className="text-slate-500 text-sm md:text-base font-bold tracking-widest uppercase">
                                        {label}
                                    </p>
                                    <h3
                                        className="text-4xl md:text-6xl font-black tracking-tighter tabular-nums"
                                        style={{ color: accent }}
                                    >
                                        {stats[key]}
                                    </h3>
                                </div>
                                <div className={`p-4 md:p-5 rounded-2xl ${lightBg} shadow-inner ring-1 ring-slate-200/50 order-1 md:order-2 shrink-0`}>
                                    <Icon className="w-8 h-8 md:w-12 md:h-12" style={{ color: accent }} />
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="flex-1 flex flex-col min-h-0">
                    <Card className="border-0 shadow-lg shadow-slate-200/50 bg-white flex flex-col overflow-hidden h-full">
                        <div className="px-8 py-6 border-b border-slate-100 flex items-center justify-between">
                            <h2 className="text-3xl md:text-4xl font-extrabold text-slate-800 flex items-center gap-4">
                                <Calendar className="w-8 h-8 md:w-10 md:h-10 text-[#00a2e8]" />
                                Jadwal Booking
                            </h2>
                        </div>
                        <div className="flex-1 overflow-auto p-6 md:p-8 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            {upcomingBookings.length === 0 ? (
                                <div className="col-span-full flex items-center justify-center h-full">
                                    <span className="text-slate-400 text-2xl font-semibold">Tidak ada jadwal booking mendatang</span>
                                </div>
                            ) : (
                                upcomingBookings.map(booking => {
                                    const now = currentTime.getTime();
                                    const startTime = new Date(booking.tanggal_mulai).getTime();
                                    const endTime = new Date(booking.tanggal_selesai).getTime();
                                    const isToday = new Date(booking.tanggal_mulai).toDateString() === currentTime.toDateString();
                                    const isOngoing = now >= startTime && now <= endTime;
                                    const isFuture = now < startTime;
                                    const isPendingApproval = booking.status === 'open';
                                    
                                    let badgeText = '';
                                    let badgeStyle = '';
                                    let cardStyle = '';

                                    if (isOngoing) {
                                        badgeText = 'Sedang Dipakai';
                                        badgeStyle = 'bg-rose-500 text-white shadow-md shadow-rose-200 animate-pulse border-rose-500 text-sm px-4 py-1.5 font-bold tracking-wider uppercase';
                                        cardStyle = 'bg-gradient-to-br from-rose-50 to-white border-rose-300 shadow-md shadow-rose-100/50 ring-1 ring-rose-200';
                                    } else if (isFuture && isPendingApproval) {
                                        badgeText = 'Menunggu Persetujuan';
                                        badgeStyle = 'bg-sky-500 text-white text-sm px-4 py-1.5 font-bold tracking-wider uppercase shadow-md shadow-sky-200 border-sky-500';
                                        cardStyle = 'bg-gradient-to-br from-sky-50 to-white border-sky-200 shadow-md shadow-sky-100/50';
                                    } else if (isFuture) {
                                        if (isToday) {
                                            badgeText = 'Terjadwal Hari Ini';
                                            badgeStyle = 'bg-[#00a2e8] text-white text-sm px-4 py-1.5 font-bold tracking-wider uppercase shadow-md shadow-blue-200 border-[#00a2e8]';
                                            cardStyle = 'bg-gradient-to-br from-blue-50 to-white border-blue-200 shadow-md shadow-blue-100/50';
                                        } else {
                                            badgeText = `Dipesan: ${format(new Date(booking.tanggal_mulai), 'dd MMM', { locale: localeID })}`;
                                            badgeStyle = 'bg-amber-500 text-white border-amber-500 text-sm px-4 py-1.5 font-bold shadow-md shadow-amber-200';
                                            cardStyle = 'bg-gradient-to-br from-amber-50 to-white border-amber-200 shadow-sm';
                                        }
                                    } else {
                                        badgeText = 'Selesai';
                                        badgeStyle = 'text-slate-400 border-slate-200 border text-sm px-4 py-1.5 font-bold bg-slate-50';
                                        cardStyle = 'bg-slate-50 border-slate-200 opacity-60';
                                    }

                                    return (
                                        <div
                                            key={booking.id}
                                            className={`p-6 md:p-8 rounded-2xl border transition-all flex flex-col justify-between ${cardStyle}`}
                                        >
                                            <div>
                                                <div className="flex justify-between items-start mb-4">
                                                    <Badge variant="outline" className={badgeStyle}>
                                                        {badgeText}
                                                    </Badge>
                                                    <span className="text-lg font-mono font-bold text-slate-400">
                                                        {booking.ticket?.formatted_id}
                                                    </span>
                                                </div>
                                                <h3 className="text-2xl md:text-3xl font-extrabold text-slate-900 mb-3 line-clamp-2">
                                                    {booking.nama_aset}
                                                </h3>
                                                <p className="text-slate-500 font-semibold text-lg md:text-xl mb-6 flex items-center gap-3">
                                                    <User className="w-5 h-5 md:w-6 md:h-6 text-slate-400" />
                                                    {booking.ticket?.user?.name || '-'}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-3 md:gap-4 text-base md:text-lg font-bold text-slate-500 bg-slate-50/80 p-4 md:p-5 rounded-xl border border-slate-100 justify-center mt-auto flex-wrap">
                                                <Calendar className="w-5 h-5 md:w-6 md:h-6 text-slate-400 shrink-0" />
                                                {new Date(booking.tanggal_mulai).toDateString() === new Date(booking.tanggal_selesai).toDateString() ? (
                                                    <>
                                                        <span className="font-mono tracking-tight text-slate-700">
                                                            {format(new Date(booking.tanggal_mulai), 'dd MMM yyyy', { locale: localeID })}
                                                        </span>
                                                        <span className="text-slate-300 mx-1 md:mx-2">|</span>
                                                        <Clock className="w-5 h-5 md:w-6 md:h-6 text-slate-400 shrink-0" />
                                                        <span className="font-mono tracking-tight text-slate-700">
                                                            {format(new Date(booking.tanggal_mulai), 'HH:mm')}
                                                        </span>
                                                        <ArrowRight className="w-4 h-4 md:w-5 md:h-5 text-slate-300" />
                                                        <span className="font-mono tracking-tight text-slate-700">
                                                            {format(new Date(booking.tanggal_selesai), 'HH:mm')}
                                                        </span>
                                                    </>
                                                ) : (
                                                    <>
                                                        <span className="font-mono tracking-tight text-slate-700">
                                                            {format(new Date(booking.tanggal_mulai), 'dd MMM', { locale: localeID })} {format(new Date(booking.tanggal_mulai), 'HH:mm')}
                                                        </span>
                                                        <ArrowRight className="w-4 h-4 md:w-5 md:h-5 text-slate-300 mx-1" />
                                                        <span className="font-mono tracking-tight text-slate-700">
                                                            {format(new Date(booking.tanggal_selesai), 'dd MMM', { locale: localeID })} {format(new Date(booking.tanggal_selesai), 'HH:mm')}
                                                        </span>
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })
                            )}
                        </div>
                    </Card>
                </div>
            </main>
        </div>
    );
}
