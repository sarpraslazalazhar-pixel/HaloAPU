import { useState, useRef, useCallback } from 'react';
import axios from 'axios';

export function useDependentDropdown(baseUrl: string) {
  const [options, setOptions] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const abortControllerRef = useRef<AbortController | null>(null);

  const load = useCallback(async (parentId: string | number) => {
    // Batalkan request sebelumnya yang masih berjalan
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }

    if (!parentId) {
      setOptions([]);
      setLoading(false);
      return;
    }

    const controller = new AbortController();
    abortControllerRef.current = controller;

    setLoading(true);
    setOptions([]); // Kosongkan langsung agar UI tidak menampilkan data lama
    try {
      const decodedUrl = decodeURIComponent(baseUrl);
      const url = decodedUrl.replace(/\{[^}]+\}/, String(parentId));
      const { data } = await axios.get(url, { signal: controller.signal });
      // Hanya update state jika request ini belum dibatalkan
      if (!controller.signal.aborted) {
        setOptions(data);
      }
    } catch (err: any) {
      // Jangan kosongkan options jika error karena abort (request dibatalkan oleh kita sendiri)
      if (axios.isCancel(err) || err?.name === 'AbortError' || err?.name === 'CanceledError') {
        return;
      }
      setOptions([]);
    } finally {
      // Hanya hentikan loading jika ini masih controller yang aktif
      if (!controller.signal.aborted) {
        setLoading(false);
      }
    }
  }, [baseUrl]);

  return { options, loading, load };
}
