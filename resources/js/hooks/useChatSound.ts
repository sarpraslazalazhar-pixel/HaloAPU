import { useCallback, useRef } from 'react';

interface UseChatSoundOptions {
  soundUrl?: string;
}

export function useChatSound({ soundUrl }: UseChatSoundOptions = {}) {
  const audioRef = useRef<HTMLAudioElement | null>(null);

  const playDefaultChime = useCallback(() => {
    try {
      const AudioContextClass = window.AudioContext || (window as any).webkitAudioContext;
      if (!AudioContextClass) return;
      const ctx = new AudioContextClass();

      // Dual tone notification chime (E5 -> B5)
      const now = ctx.currentTime;
      const osc1 = ctx.createOscillator();
      const osc2 = ctx.createOscillator();
      const gain = ctx.createGain();

      osc1.type = 'sine';
      osc2.type = 'sine';

      // Tone 1: 659.25 Hz (E5)
      osc1.frequency.setValueAtTime(659.25, now);
      // Tone 2: 987.77 Hz (B5)
      osc2.frequency.setValueAtTime(987.77, now + 0.08);

      gain.gain.setValueAtTime(0.2, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);

      osc1.connect(gain);
      osc2.connect(gain);
      gain.connect(ctx.destination);

      osc1.start(now);
      osc1.stop(now + 0.12);
      osc2.start(now + 0.08);
      osc2.stop(now + 0.35);
    } catch (e) {
      console.warn('Audio Context failed to play chime', e);
    }
  }, []);

  const playSound = useCallback(() => {
    if (soundUrl) {
      if (!audioRef.current) {
        audioRef.current = new Audio(soundUrl);
      }
      audioRef.current.play().catch(() => {
        // Fallback to programmatic chime if audio element playback blocked
        playDefaultChime();
      });
    } else {
      playDefaultChime();
    }
  }, [soundUrl, playDefaultChime]);

  return { playSound };
}
