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
import { motion, AnimatePresence } from 'framer-motion';
import { popoverVariants } from '@/lib/animationConfig';
import { NotificationItem } from '@/types';
import { useNotificationSound } from '@/hooks/useNotificationSound';

export default function NotificationBell() {
  const [unreadCount, setUnreadCount] = useState(0);
  const [notifications, setNotifications] = useState<NotificationItem[]>([]);
  const { auth, appConfig } = usePage<any>().props;
  const currentUser = auth?.admin || auth?.user;
  const prefix = auth?.admin ? 'admin.notifications' : 'notifications';
  const notifySoundEnabled = currentUser?.notify_sound !== false;
  const notifyInappEnabled = currentUser?.notify_inapp !== false;

  const soundUrl = appConfig?.notification_sound_path
    ? `/system/notification-sound?v=${encodeURIComponent(appConfig.notification_sound_path)}`
    : '/sounds/ting-ting-ting.wav';

  const [isOpen, setIsOpen] = useState(false);
  const { isMuted, toggleMute, play } = useNotificationSound({ soundUrl, enabled: notifySoundEnabled });
  const isMutedRef = useRef(isMuted);
  const previousCountRef = useRef<number | null>(null);

  // Sync isMuted state to ref agar tidak stale di dalam interval/callback
  useEffect(() => {
    isMutedRef.current = isMuted;
  }, [isMuted]);

  const playNotificationSound = useCallback(() => {
    if (!isMutedRef.current && notifySoundEnabled) {
      play();
    }
  }, [play, notifySoundEnabled]);

  const handleToggleMute = useCallback(() => {
    toggleMute();
    if (isMuted) {
      playNotificationSound();
      toast.success('Suara notifikasi diaktifkan', { id: 'unmuted-toast' });
    } else {
      toast('Suara notifikasi dimatikan', { icon: '🔇', id: 'muted-toast' });
    }
  }, [isMuted, toggleMute, playNotificationSound]);

  const fetchUnreadCount = useCallback(async () => {
    try {
      const response = await axios.get(route(`${prefix}.unread-count`));
      const newCount = response.data.unread_count;

      if (previousCountRef.current !== null && newCount > previousCountRef.current) {
        if (!isMutedRef.current) {
          playNotificationSound();
        }
        
        if ('Notification' in window && Notification.permission === 'granted') {
          axios.get(route(`${prefix}.index`), { params: { per_page: 1 } })
            .then(res => {
              const latest = res.data.notifications?.data?.[0];
              if (latest && !latest.read_at) {
                const title = latest.data.title || latest.data.judul || 'Notifikasi Baru';
                const body = latest.data.message || latest.data.pesan || 'Anda memiliki notifikasi baru';
                
                toast.success(title, { id: `poll-notif-${latest.id || Date.now()}` });

                const notification = new Notification(title, {
                  body: body,
                  icon: '/logo.png'
                });
                
                notification.onclick = function() {
                  window.focus();
                  if (latest.data.url || latest.data.aksi_url) {
                    window.location.href = latest.data.url || latest.data.aksi_url;
                  }
                  this.close();
                };
              }
            })
            .catch(e => console.error('Gagal fetch detail notifikasi untuk browser push', e));
        }
      }

      previousCountRef.current = newCount;
      setUnreadCount(newCount);
    } catch (error) {
      console.error('Gagal fetch unread count:', error);
    }
  }, [playNotificationSound, prefix]);

  useEffect(() => {
    fetchUnreadCount();
    const interval = setInterval(() => {
      fetchUnreadCount();
    }, 15000);
    return () => clearInterval(interval);
  }, [fetchUnreadCount]);

  const fetchRecentNotifications = useCallback(async () => {
    try {
      const response = await axios.get(route(`${prefix}.index`), {
        params: { per_page: 10 },
        headers: { Accept: 'application/json' }
      });
      setNotifications(response.data.notifications?.data || []);
    } catch (error) {
      console.error('Gagal fetch notifications:', error);
    }
  }, [prefix]);

  useEffect(() => {
    if (isOpen) {
      fetchRecentNotifications();
    }
  }, [isOpen, fetchRecentNotifications]);

  useEffect(() => {
    fetchUnreadCount();
    
    if ('Notification' in window && Notification.permission !== 'granted' && Notification.permission !== 'denied') {
      Notification.requestPermission();
    }
  }, [fetchUnreadCount]);

  const handleMarkAsRead = async (id: string) => {
    try {
      await axios.patch(route(`${prefix}.read`, { id }));
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
      await axios.patch(route(`${prefix}.snooze`, { id }), {
        snooze_minutes: minutes,
      });
      fetchRecentNotifications();
    } catch (error) {
      console.error('Gagal snooze:', error);
    }
  };

  const handleMarkAllRead = async () => {
    try {
      await axios.post(route(`${prefix}.mark-all-read`));
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
        <motion.div whileTap={{ scale: 0.92 }} whileHover={{ scale: 1.05 }}>
          <Button variant="ghost" size="icon" className="relative" onClick={() => setIsOpen(!isOpen)}>
            <Bell className="h-5 w-5" />
            {unreadCount > 0 && (
              <motion.span
                initial={{ scale: 0 }}
                animate={{ scale: 1 }}
                transition={{ type: "spring", stiffness: 500, damping: 20 }}
                className="absolute -top-1 -right-1 flex h-5 min-w-[20px] items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white px-1 shadow-sm"
              >
                {unreadCount > 99 ? '99+' : unreadCount}
              </motion.span>
            )}
          </Button>
        </motion.div>

        <AnimatePresence>
          {isOpen && (
            <motion.div
              variants={popoverVariants}
              initial="initial"
              animate="animate"
              exit="exit"
              className="absolute right-0 mt-2 w-96 bg-background border rounded-2xl shadow-2xl z-[100] overflow-hidden flex flex-col"
            >
              <div className="flex items-center justify-between p-4 border-b bg-muted/30">
                <h3 className="font-semibold text-sm">Notifikasi</h3>
                {unreadCount > 0 && (
                  <Button variant="ghost" size="sm" onClick={handleMarkAllRead} className="text-xs h-8">
                    <Check className="h-3.5 w-3.5 mr-1 text-sky-600" />
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
                        ${!notification.read_at ? 'bg-blue-50 ' : ''}
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
                            {notification.data.title || notification.data.judul}
                          </p>
                          <p className="text-sm text-muted-foreground line-clamp-2 mt-1">
                            {notification.data.message || notification.data.pesan}
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
                  href={route(`${prefix}.index`)}
                  className="text-sm text-blue-500 hover:underline inline-flex items-center gap-1"
                >
                  Lihat semua notifikasi
                  <ExternalLink className="h-3 w-3" />
                </a>
              </div>
            </motion.div>
          )}
        </AnimatePresence>
      </div>
    </div>
  );
}
