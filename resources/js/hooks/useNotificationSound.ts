import { useState, useEffect, useCallback, useRef } from 'react';

interface UseNotificationSoundOptions {
 soundUrl?: string;
 pollIntervalMs?: number;
 enabled?: boolean;
}

interface UseNotificationSoundReturn {
 isMuted: boolean;
 toggleMute: () => void;
 setMuted: (muted: boolean) => void;
 lastPlayedAt: Date | null;
}

/**
 * Hook untuk memutar suara notifikasi saat ada event penting:
 * 1. Tiket baru masuk (untuk admin)
 * 2. SLA Tier 3 breach
 *
 * Suara hanya diputar jika:
 * - User belum mute
 * - Ada perubahan unread count (naik)
 * - Browser sudah interact (autoplay policy)
 */
export function useNotificationSound({
 soundUrl = '/sounds/ting-ting-ting.mp3',
 enabled = true,
}: UseNotificationSoundOptions = {}): UseNotificationSoundReturn {
 const [isMuted, setIsMuted] = useState<boolean>(() => {
 if (typeof window !== 'undefined') {
 const saved = localStorage.getItem('notification_sound_muted');
 return saved === 'true';
 }
 return false;
 });

 const [lastPlayedAt, setLastPlayedAt] = useState<Date | null>(null);
 const audioRef = useRef<HTMLAudioElement | null>(null);

 // Inisialisasi Audio element
 useEffect(() => {
 if (typeof window !== 'undefined') {
 audioRef.current = new Audio(soundUrl);
 audioRef.current.volume = 0.7;
 }

 return () => {
 if (audioRef.current) {
 audioRef.current.pause();
 audioRef.current = null;
 }
 };
 }, [soundUrl]);

 // Simpan preferensi mute ke localStorage
 useEffect(() => {
 if (typeof window !== 'undefined') {
 localStorage.setItem('notification_sound_muted', isMuted.toString());
 }
 }, [isMuted]);

 const toggleMute = useCallback(() => {
 setIsMuted(prev => !prev);
 }, []);

 return {
 isMuted,
 toggleMute,
 setMuted: setIsMuted,
 lastPlayedAt,
 };
}
