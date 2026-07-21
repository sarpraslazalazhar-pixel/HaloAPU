import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export function formatDateId(dateString: string | null | undefined): string {
    if (!dateString) return '-';
    try {
        const d = new Date(dateString);
        if (isNaN(d.getTime())) return '-';
        return d.toLocaleString('id-ID', { 
            day: '2-digit', 
            month: 'short', 
            year: 'numeric', 
            hour: '2-digit', 
            minute: '2-digit' 
        }).replace(/\./g, ':'); // Convert 16.21 to 16:21
    } catch {
        return '-';
    }
}

export function formatTicketId(id: string | number | null | undefined): string {
    if (!id) return '-';
    const str = String(id);
    if (str.length === 9) {
        return str.replace(/(\d{3})(\d{3})(\d{3})/, '$1-$2-$3');
    }
    return str;
}
