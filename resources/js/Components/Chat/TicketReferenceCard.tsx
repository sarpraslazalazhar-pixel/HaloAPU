import React from 'react';
import { Ticket as TicketIcon, ExternalLink } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';

interface TicketData {
  id: number;
  formatted_id: string;
  judul?: string;
  status?: string;
  priority?: string;
}

interface TicketReferenceCardProps {
  ticket: TicketData;
  isAdmin?: boolean;
  isSelf?: boolean;
}

export function TicketReferenceCard({ ticket, isAdmin = false, isSelf = false }: TicketReferenceCardProps) {
  const detailUrl = isAdmin ? `/admin/tiket/${ticket.id}` : `/tiket/${ticket.id}`;

  const getStatusBadge = (status?: string) => {
    switch (status) {
      case 'MENUNGGU':
        return <Badge variant="outline" className="bg-amber-50 text-amber-700 border-amber-200 text-[10px]">Menunggu</Badge>;
      case 'DIPROSES':
        return <Badge variant="outline" className="bg-blue-50 text-blue-700 border-blue-200 text-[10px]">Diproses</Badge>;
      case 'SELESAI':
        return <Badge variant="outline" className="bg-emerald-50 text-emerald-700 border-emerald-200 text-[10px]">Selesai</Badge>;
      case 'DITOLAK':
      case 'DIBATALKAN':
        return <Badge variant="outline" className="bg-rose-50 text-rose-700 border-rose-200 text-[10px]">{status}</Badge>;
      default:
        return <Badge variant="outline" className="text-[10px]">{status || 'Tiket'}</Badge>;
    }
  };

  return (
    <a
      href={detailUrl}
      target="_blank"
      rel="noopener noreferrer"
      className={`group block my-1.5 p-3 rounded-xl border transition-all duration-200 shadow-xs max-w-sm ${
        isSelf 
          ? 'bg-white/10 border-white/20 hover:bg-white/20' 
          : 'bg-zinc-50 border-zinc-200 hover:bg-zinc-100'
      }`}
    >
      <div className={`flex items-center justify-between gap-2 border-b pb-2 mb-2 ${isSelf ? 'border-white/20' : 'border-zinc-200'}`}>
        <div className="flex items-center gap-1.5 min-w-0">
          <TicketIcon className={`h-4 w-4 shrink-0 ${isSelf ? 'text-sky-100' : 'text-sky-600'}`} />
          <span className={`text-xs font-bold truncate ${isSelf ? 'text-white' : 'text-zinc-800'}`}>
            Tiket #{ticket.formatted_id || ticket.id}
          </span>
        </div>
        {getStatusBadge(ticket.status)}
      </div>

      <p className={`text-xs font-medium line-clamp-2 leading-relaxed mb-2 ${isSelf ? 'text-sky-50' : 'text-zinc-600'}`}>
        {ticket.judul || `Detail Tiket #${ticket.formatted_id}`}
      </p>

      <div className={`flex items-center justify-between text-[11px] font-semibold pt-1 border-t ${
        isSelf ? 'border-white/10 text-sky-100' : 'border-zinc-200/50 text-sky-700'
      }`}>
        <span>Buka Detail Tiket</span>
        <ExternalLink className="h-3.5 w-3.5 group-hover:translate-x-0.5 transition-transform" />
      </div>
    </a>
  );
}

export default TicketReferenceCard;
