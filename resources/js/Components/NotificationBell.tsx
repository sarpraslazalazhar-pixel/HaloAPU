import React, { useState, useEffect, useCallback, useRef } from 'react';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Bell, Check, Clock, ExternalLink, Volume2, VolumeX } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { NotificationItem } from '@/types';
import { useNotificationSound } from '@/hooks/useNotificationSound';

export default function NotificationBell() {
    const [unreadCount, setUnreadCount] = useState(0);
    const [notifications, setNotifications] = useState<NotificationItem[]>([]);
    const [isOpen, setIsOpen] = useState(false);
    const { isMuted, toggleMute } = useNotificationSound();
    const previousCountRef = useRef<number | null>(null);
    const audioRef = useRef<HTMLAudioElement | null>(null);

    const { appConfig } = usePage<any>().props;

    // Inisialisasi audio (menggunakan fetch & blob untuk bypass Internet Download Manager, dengan fallback)
    useEffect(() => {
        if (typeof window !== 'undefined') {
            const cacheBuster = appConfig?.notification_sound_path ? `?v=${encodeURIComponent(appConfig.notification_sound_path)}` : '';
            const soundUrl = route('system.notification-sound') + cacheBuster;
            
            // Coba fetch blob dulu (bypass IDM)
            fetch(soundUrl)
                .then(res => {
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    return res.arrayBuffer();
                })
                .then(buffer => {
                    const blob = new Blob([buffer], { type: 'audio/mpeg' });
                    const objectUrl = URL.createObjectURL(blob);
                    const audio = new Audio(objectUrl);
                    audio.volume = 0.7;
                    // Test apakah audio bisa di-load
                    audio.addEventListener('canplaythrough', () => {
                        audioRef.current = audio;
                        console.log('Audio loaded via blob successfully');
                    }, { once: true });
                    audio.addEventListener('error', () => {
                        console.warn('Blob audio gagal, fallback ke URL langsung');
                        URL.revokeObjectURL(objectUrl);
                        initDirectAudio(soundUrl);
                    }, { once: true });
                    audio.load();
                })
                .catch(err => {
                    console.warn("Fetch blob gagal, fallback ke URL langsung:", err);
                    initDirectAudio(soundUrl);
                });

            function initDirectAudio(url: string) {
                const audio = new Audio(url);
                audio.volume = 0.7;
                audio.preload = 'auto';
                audio.addEventListener('canplaythrough', () => {
                    audioRef.current = audio;
                    console.log('Audio loaded via direct URL');
                }, { once: true });
                audio.addEventListener('error', (e) => {
                    console.error('Direct audio juga gagal:', e);
                    // Last resort: coba file default langsung
                    const fallback = new Audio('/sounds/ting-ting-ting.mp3');
                    fallback.volume = 0.7;
                    fallback.preload = 'auto';
                    audioRef.current = fallback;
                    console.log('Using fallback default sound');
                }, { once: true });
                audio.load();
            }
        }
    }, [appConfig?.notification_sound_path]);

    const playNotificationSound = useCallback(() => {
        if (!audioRef.current) {
            console.warn('Audio belum siap, mencoba inisialisasi ulang...');
            const fallback = new Audio('/sounds/ting-ting-ting.mp3');
            fallback.volume = 0.7;
            audioRef.current = fallback;
        }
        
        const audio = audioRef.current;
        audio.currentTime = 0;
        
        const playPromise = audio.play();
        if (playPromise !== undefined) {
            playPromise.catch((error) => {
                console.log('Audio play failed:', error);
                if (error.name === 'NotAllowedError') {
                    toast('Browser memblokir suara notifikasi. Klik halaman ini agar suara bisa diputar.', {
                        icon: '⚠️',
                        id: 'audio-blocked-toast',
                        duration: 6000,
                    });
                } else {
                    // Coba fallback terakhir
                    try {
                        const emergency = new Audio('/sounds/ting-ting-ting.mp3');
                        emergency.volume = 0.7;
                        emergency.play().catch(() => {
                            toast.error('Gagal memutar suara notifikasi. Pastikan file suara tersedia.', {
                                id: 'audio-error-toast',
                            });
                        });
                        audioRef.current = emergency;
                    } catch {
                        toast.error('Gagal memutar suara notifikasi. Pastikan file suara tersedia.', {
                            id: 'audio-error-toast',
                        });
                    }
                }
            });
        }
    }, []);

    const handleToggleMute = useCallback(() => {
        toggleMute();
        // Jika isMuted sebelumnya true, berarti sekarang akan jadi false (unmuted)
        // Kita test play suara sekalian me-unlock audio context dari browser
        if (isMuted) {
            playNotificationSound();
            toast.success('Suara notifikasi diaktifkan', { id: 'unmuted-toast' });
        } else {
            toast('Suara notifikasi dimatikan', { icon: '🔇', id: 'muted-toast' });
        }
    }, [isMuted, toggleMute, playNotificationSound]);

    const fetchUnreadCount = useCallback(async () => {
        try {
            const response = await axios.get(route('admin.notifications.unread-count'));
            const newCount = response.data.unread_count;

            // Play sound jika count naik & tidak muted
            if (!isMuted && previousCountRef.current !== null && newCount > previousCountRef.current) {
                playNotificationSound();
            }

            previousCountRef.current = newCount;
            setUnreadCount(newCount);
        } catch (error) {
            console.error('Gagal fetch unread count:', error);
        }
    }, [isMuted]);

    // Poll setiap 15 detik untuk jumlah notifikasi belum dibaca
    useEffect(() => {
        const interval = setInterval(() => {
            fetchUnreadCount();
        }, 15000);
        return () => clearInterval(interval);
    }, [fetchUnreadCount]);

    const fetchRecentNotifications = useCallback(async () => {
        try {
            const response = await axios.get(route('admin.notifications.index'), {
                params: { per_page: 10 },
                headers: { Accept: 'application/json' }
            });
            setNotifications(response.data.notifications?.data || []);
        } catch (error) {
            console.error('Gagal fetch notifications:', error);
        }
    }, []);

    // Fetch notifikasi terbaru saat dropdown dibuka
    useEffect(() => {
        if (isOpen) {
            fetchRecentNotifications();
        }
    }, [isOpen, fetchRecentNotifications]);

    // Fetch unread count saat mount
    useEffect(() => {
        fetchUnreadCount();
    }, [fetchUnreadCount]);

    const handleMarkAsRead = async (id: string) => {
        try {
            await axios.patch(route('admin.notifications.read', { id }));
            setNotifications(prev =>
                prev.map(n => n.id === id ? { ...n, read_at: new Date().toISOString() } : n)
            );
            setUnreadCount(prev => Math.max(0, prev - 1));
        } catch (error) {
            console.error('Gagal mark as read:', error);
        }
    };

    const handleSnooze = async (id: string, minutes: number) => {
        try {
            await axios.patch(route('admin.notifications.snooze', { id }), {
                snooze_minutes: minutes,
            });
            fetchRecentNotifications();
        } catch (error) {
            console.error('Gagal snooze:', error);
        }
    };

    const handleMarkAllRead = async () => {
        try {
            await axios.post(route('admin.notifications.mark-all-read'));
            setNotifications(prev =>
                prev.map(n => ({ ...n, read_at: n.read_at || new Date().toISOString() }))
            );
            setUnreadCount(0);
        } catch (error) {
            console.error('Gagal mark all read:', error);
        }
    };

    const formatTimeAgo = (dateStr: string): string => {
        const date = new Date(dateStr);
        const now = new Date();
        const diffMs = now.getTime() - date.getTime();
        const diffMinutes = Math.floor(diffMs / 60000);

        if (diffMinutes < 1) return 'Baru saja';
        if (diffMinutes < 60) return `${diffMinutes} menit lalu`;
        const diffHours = Math.floor(diffMinutes / 60);
        if (diffHours < 24) return `${diffHours} jam lalu`;
        const diffDays = Math.floor(diffHours / 24);
        return `${diffDays} hari lalu`;
    };

    return (
        <div className="flex items-center gap-1">
            <Button variant="ghost" size="icon" onClick={handleToggleMute} title={isMuted ? 'Aktifkan suara' : 'Matikan suara'}>
                {isMuted ? <VolumeX className="h-4 w-4 text-muted-foreground" /> : <Volume2 className="h-4 w-4" />}
            </Button>

            <div className="relative">
                <Button variant="ghost" size="icon" className="relative" onClick={() => setIsOpen(!isOpen)}>
                    <Bell className="h-5 w-5" />
                    {unreadCount > 0 && (
                        <Badge
                            variant="destructive"
                            className="absolute -top-1 -right-1 h-5 min-w-[20px] flex items-center justify-center text-xs p-0 px-1"
                        >
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </Badge>
                    )}
                </Button>

                {isOpen && (
                    <div className="absolute right-0 mt-2 w-96 bg-background border rounded-md shadow-lg z-50 overflow-hidden flex flex-col">
                        <div className="flex items-center justify-between p-4 border-b">
                            <h3 className="font-semibold">Notifikasi</h3>
                            {unreadCount > 0 && (
                                <Button variant="ghost" size="sm" onClick={handleMarkAllRead}>
                                    <Check className="h-4 w-4 mr-1" />
                                    Tandai semua dibaca
                                </Button>
                            )}
                        </div>

                        <div className="max-h-[400px] overflow-y-auto">
                        {notifications.length === 0 ? (
                            <div className="p-8 text-center text-muted-foreground">
                                Tidak ada notifikasi
                            </div>
                        ) : (
                            notifications.map(notification => (
                                <div
                                    key={notification.id}
                                    className={`
                                        p-4 border-b cursor-pointer hover:bg-accent/50 transition-colors
                                        ${!notification.read_at ? 'bg-blue-50 dark:bg-blue-950/20' : ''}
                                    `}
                                    onClick={() => {
                                        if (!notification.read_at) {
                                            handleMarkAsRead(notification.id);
                                        }
                                        if (notification.data.aksi_url) {
                                            window.location.href = notification.data.aksi_url;
                                        }
                                    }}
                                >
                                    <div className="flex items-start gap-3">
                                        {!notification.read_at && (
                                            <div className="w-2 h-2 rounded-full bg-blue-500 mt-2 flex-shrink-0" />
                                        )}

                                        <div className="flex-1 min-w-0">
                                            <p className="text-sm font-medium truncate">
                                                {notification.data.judul}
                                            </p>
                                            <p className="text-sm text-muted-foreground line-clamp-2 mt-1">
                                                {notification.data.pesan}
                                            </p>
                                            <p className="text-xs text-muted-foreground mt-1">
                                                {formatTimeAgo(notification.created_at)}
                                            </p>
                                        </div>

                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                render={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-8 w-8 flex-shrink-0"
                                                        onClick={(e) => e.stopPropagation()}
                                                    />
                                                }
                                            >
                                                <Clock className="h-4 w-4" />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem onClick={(e) => { e.stopPropagation(); handleSnooze(notification.id, 15); }}>
                                                    Snooze 15 menit
                                                </DropdownMenuItem>
                                                <DropdownMenuItem onClick={(e) => { e.stopPropagation(); handleSnooze(notification.id, 30); }}>
                                                    Snooze 30 menit
                                                </DropdownMenuItem>
                                                <DropdownMenuItem onClick={(e) => { e.stopPropagation(); handleSnooze(notification.id, 60); }}>
                                                    Snooze 1 jam
                                                </DropdownMenuItem>
                                                <DropdownMenuItem onClick={(e) => { e.stopPropagation(); handleSnooze(notification.id, 1440); }}>
                                                    Snooze 1 hari
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </div>
                            ))
                        )}
                        </div>

                        <div className="p-3 border-t text-center bg-muted/50">
                            <a
                                href={route('admin.notifications.index')}
                                className="text-sm text-blue-500 hover:underline inline-flex items-center gap-1"
                            >
                                Lihat semua notifikasi
                                <ExternalLink className="h-3 w-3" />
                            </a>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
