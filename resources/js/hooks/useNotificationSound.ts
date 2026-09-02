import { useState, useEffect, useCallback, useRef } from 'react';
import toast from 'react-hot-toast';

interface UseNotificationSoundOptions {
  soundUrl?: string;
  enabled?: boolean;
}

interface UseNotificationSoundReturn {
  isMuted: boolean;
  toggleMute: () => void;
  setMuted: (muted: boolean) => void;
  lastPlayedAt: Date | null;
  play: () => void;
}

// Global audio instance so we don't create multiple overlapping players
let globalAudioInstance: HTMLAudioElement | null = null;
let currentSoundUrl: string = '';

/**
 * Hook untuk memutar suara notifikasi saat ada event penting:
 * 1. Tiket baru masuk (untuk admin)
 *
 * Suara hanya diputar jika:
 * - User belum mute
 * - Browser sudah interact (autoplay policy)
 */
export function useNotificationSound({
  soundUrl = '/sounds/ting-ting-ting.wav',
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

  // Inisialisasi Audio element
  useEffect(() => {
    if (typeof window !== 'undefined' && soundUrl) {
      if (!globalAudioInstance || currentSoundUrl !== soundUrl) {
        globalAudioInstance = new Audio(soundUrl);
        globalAudioInstance.preload = 'auto';
        globalAudioInstance.volume = 1.0;
        currentSoundUrl = soundUrl;
      }
    }
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

  const play = useCallback(() => {
    if (isMuted || !enabled) return;
    
    if (globalAudioInstance) {
      // Force reload if needed, but normally just reset time
      globalAudioInstance.currentTime = 0;
      globalAudioInstance.play().then(() => {
        setLastPlayedAt(new Date());
      }).catch(err => {
        console.error('Audio play failed:', err);
        toast.error('Browser memblokir suara notifikasi. Klik halaman ini agar suara bisa diputar.', {
            id: 'autoplay-error',
            duration: 4000
        });
      });
    }
  }, [isMuted, enabled]);

  return {
    isMuted,
    toggleMute,
    setMuted: setIsMuted,
    lastPlayedAt,
    play,
  };
}
