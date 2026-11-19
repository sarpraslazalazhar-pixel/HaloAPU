import { useState } from 'react';
import {
  Search,
  Filter,
  MessageSquare,
  User as UserIcon,
  Shield,
  Ticket as TicketIcon,
  Bot,
  Check,
  CheckCheck,
} from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { format, isToday, isYesterday } from 'date-fns';
import { motion, AnimatePresence } from 'framer-motion';

export interface TicketRoomSummary {
  conversation_id: number;
  ticket_id: number;
  formatted_id: string;
  judul?: string | null;
  chat_label?: string | null;
  created_at?: string | null;
  nama_layanan?: string | null;
  nama_unit?: string | null;
  status?: string | null;
  priority?: string | null;
  requester?: string | null;
  handler?: string | null;
  unread_count: number;
  has_message?: boolean;
  last_message: string;
  last_message_at?: string | null;
  last_message_sender_id?: number | null;
  last_message_sender_type?: string | null;
  is_last_message_read?: boolean;
}

export interface ConversationItem {
  id: number;
  group_key?: string | null;
  is_bot?: boolean;
  type?: string;
  requester_type?: 'user' | 'admin' | string;
  is_self_requester?: boolean;
  user?: {
    id?: number;
    name?: string;
    email?: string;
    avatar?: string | null;
    last_seen_at?: string | null;
    entity_type?: 'user' | 'admin' | string;
  } | null;
  title: string;
  subtitle?: string;
  is_assigned?: boolean;
  last_message: string;
  last_message_at: string;
  last_message_sender_id?: number | null;
  last_message_sender_type?: string | null;
  is_last_message_read?: boolean;
  unread_count: number;
  ticket_id?: number | null;
  tickets_count?: number;
  conversation_ids?: number[];
  ticket_rooms?: TicketRoomSummary[];
  ticket?: {
    id: number;
    formatted_id: string;
    judul?: string;
    chat_label?: string | null;
    created_at?: string | null;
    nama_layanan?: string | null;
    nama_unit?: string | null;
    status?: string;
    priority?: string | null;
    requester?: string | null;
    handler?: string | null;
  } | null;
}

interface ConversationListProps {
  conversations: ConversationItem[];
  activeId: number | null;
  onSelect: (id: number) => void;
  isAdmin?: boolean;
  onlineUsers?: Array<{ id: number; name: string; type: string }>;
  typingByConversation?: Record<number, string>;
  currentUserId?: number;
  currentUserType?: 'user' | 'admin';
}

function statusMeta(status?: string | null) {
  const normalized = (status || '').toLowerCase();

  if (normalized === 'open') {
    return { label: 'Baru', className: 'bg-sky-50 text-sky-700 border-sky-200' };
  }

  if (normalized === 'on_proses') {
    return { label: 'Diproses', className: 'bg-blue-50 text-blue-700 border-blue-200' };
  }

  if (normalized === 'pending') {
    return { label: 'Tertunda', className: 'bg-amber-50 text-amber-700 border-amber-200' };
  }

  if (normalized === 'need_revision') {
    return { label: 'Perlu Revisi', className: 'bg-orange-50 text-orange-700 border-orange-200' };
  }

  if (normalized === 'solve' || normalized === 'selesai') {
    return { label: 'Selesai', className: 'bg-emerald-50 text-emerald-700 border-emerald-200' };
  }

  if (normalized === 'reject') {
    return { label: 'Ditolak', className: 'bg-rose-50 text-rose-700 border-rose-200' };
  }

  if (normalized === 'dibatalkan') {
    return { label: 'Dibatalkan', className: 'bg-zinc-100 text-zinc-600 border-zinc-200' };
  }

  return { label: status || 'Tiket', className: 'bg-zinc-50 text-zinc-600 border-zinc-200' };
}

function ticketSecondaryLabel(ticket?: ConversationItem['ticket']): string | null {
  if (!ticket) return null;

  const label = (ticket.chat_label || ticket.nama_layanan || '').trim();
  const judul = (ticket.judul || '').trim();

  if (!judul) return null;

  if (label !== '' && judul.toLowerCase() === label.toLowerCase()) return null;

  if (/^tiket\s*#/i.test(judul)) return null;

  return judul;
}

export function ConversationList({
  conversations,
  activeId,
  onSelect,
  isAdmin = false,
  onlineUsers = [],
  typingByConversation = {},
  currentUserId,
  currentUserType,
}: ConversationListProps) {
  const [search, setSearch] = useState('');
  const [filterUnread, setFilterUnread] = useState(false);
  const [adminScope, setAdminScope] = useState<'all' | 'incoming' | 'mine'>('all');

  const hasSelfSubmitted = isAdmin && conversations.some((c) => c.is_self_requester);

  const filtered = conversations.filter((c) => {
    if (filterUnread && c.unread_count === 0) return false;

    if (isAdmin && adminScope === 'incoming' && c.is_self_requester) return false;

    if (isAdmin && adminScope === 'mine' && !c.is_self_requester) return false;

    const rawTerm = search.trim();

    if (!rawTerm) return true;

    const term = rawTerm.toLowerCase();
    const cleanTerm = term.replace(/[#\-\s]/g, '');

    if (
      c.title.toLowerCase().includes(term) ||
      (c.subtitle?.toLowerCase().includes(term) ?? false) ||
      c.last_message.toLowerCase().includes(term) ||
      (c.user?.name?.toLowerCase().includes(term) ?? false) ||
      (c.user?.email?.toLowerCase().includes(term) ?? false) ||
      (c.ticket?.formatted_id?.toLowerCase().includes(term) ?? false) ||
      (cleanTerm.length > 0 && String(c.ticket?.id ?? '').includes(cleanTerm)) ||
      (c.ticket?.judul?.toLowerCase().includes(term) ?? false) ||
      (c.ticket?.nama_layanan?.toLowerCase().includes(term) ?? false)
    ) {
      return true;
    }

    if (c.ticket_rooms && c.ticket_rooms.length > 0) {
      return c.ticket_rooms.some((r) => {
        const roomCleanId = r.formatted_id.toLowerCase().replace(/[#\-\s]/g, '');

        return (
          r.formatted_id.toLowerCase().includes(term) ||
          (cleanTerm.length > 0 && roomCleanId.includes(cleanTerm)) ||
          (r.judul?.toLowerCase().includes(term) ?? false) ||
          (r.nama_layanan?.toLowerCase().includes(term) ?? false) ||
          r.last_message.toLowerCase().includes(term)
        );
      });
    }

    return false;
  });

  const formatTime = (dateStr?: string) => {
    if (!dateStr) return '';

    try {
      const d = new Date(dateStr);

      if (isToday(d)) return format(d, 'HH:mm');

      if (isYesterday(d)) return 'Kemarin';

      return format(d, 'dd/MM/yy');
    } catch {
      return '';
    }
  };

  const resolveConversationToOpen = (item: ConversationItem): number => {
    const rooms = item.ticket_rooms;

    if (!rooms || rooms.length === 0) {
      return item.id;
    }

    const rawTerm = search.trim().toLowerCase();

    if (rawTerm) {
      const cleanTerm = rawTerm.replace(/[#\-\s]/g, '');

      const matched = rooms.find((r) => {
        const roomCleanId = r.formatted_id.toLowerCase().replace(/[#\-\s]/g, '');

        return (
          r.formatted_id.toLowerCase().includes(rawTerm) ||
          (cleanTerm.length > 0 && roomCleanId.includes(cleanTerm)) ||
          (r.judul?.toLowerCase().includes(rawTerm) ?? false) ||
          r.last_message.toLowerCase().includes(rawTerm)
        );
      });

      if (matched) {
        return matched.conversation_id;
      }
    }

    const firstUnread = rooms.find((r) => r.unread_count > 0);

    if (firstUnread) {
      return firstUnread.conversation_id;
    }

    // No unread: prefer a ticket that is still running over a closed one.
    const closed = ['solve', 'selesai', 'reject', 'dibatalkan'];
    const running = rooms.filter((r) => !closed.includes((r.status || '').toLowerCase()));
    const pool = running.length > 0 ? running : rooms;

    return [...pool].sort(
      (a, b) =>
        new Date(b.last_message_at || b.created_at || 0).getTime() -
        new Date(a.last_message_at || a.created_at || 0).getTime()
    )[0].conversation_id;
  };

  const getAvatarIcon = (item: ConversationItem) => {
    if (item.is_bot || item.type === 'admin_bot_reminder') {
      return <Bot className="h-5 w-5 text-indigo-600" />;
    }

    if (!isAdmin && item.type === 'ticket') {
      return <TicketIcon className="h-5 w-5 text-sky-700" />;
    }

    if (item.user?.avatar) return null;

    if (item.is_self_requester) {
      return <TicketIcon className="h-5 w-5 text-teal-700" />;
    }

    if (item.requester_type === 'admin' || item.subtitle?.includes('Admin')) {
      return <Shield className="h-5 w-5 text-indigo-600" />;
    }

    return <UserIcon className="h-5 w-5 text-sky-600" />;
  };

  return (
    <div className="flex flex-col h-full min-h-0 bg-white border-r border-zinc-200 overflow-hidden">
      {/* Header & Search */}
      <div className="p-3.5 border-b border-zinc-100 flex flex-col gap-2.5 bg-zinc-50/50 shrink-0">
        <div className="flex items-center justify-between gap-2">
          <div className="min-w-0">
            <h2 className="text-base font-bold text-zinc-900 flex items-center gap-2">
              <MessageSquare className="h-5 w-5 text-sky-600 shrink-0" />
              <span className="truncate">{isAdmin ? 'Pesan Pemohon' : 'Pesan Tiket'}</span>
            </h2>
            <p className="text-[11px] text-zinc-500 truncate">
              {isAdmin
                ? 'Per pemohon tiket & tiket Anda'
                : 'Diskusi per tiket yang Anda ajukan'}
            </p>
          </div>

          <Button
            variant={filterUnread ? 'default' : 'outline'}
            size="sm"
            onClick={() => setFilterUnread(!filterUnread)}
            className={`h-7 px-2.5 text-xs rounded-lg gap-1.5 shrink-0 transition-colors ${
              filterUnread ? 'bg-sky-600 hover:bg-sky-700 text-white' : 'text-zinc-600'
            }`}
          >
            <Filter className="h-3 w-3" />
            <span>Belum dibaca</span>
          </Button>
        </div>

        {isAdmin && hasSelfSubmitted && (
          <div className="grid grid-cols-3 gap-1 p-1 bg-zinc-100 rounded-xl text-[11px] font-semibold">
            <button
              type="button"
              onClick={() => setAdminScope('all')}
              className={`py-1 rounded-lg transition-colors ${
                adminScope === 'all' ? 'bg-white text-sky-700 shadow-2xs' : 'text-zinc-600 hover:text-zinc-900'
              }`}
            >
              Semua
            </button>
            <button
              type="button"
              onClick={() => setAdminScope('incoming')}
              className={`py-1 rounded-lg transition-colors ${
                adminScope === 'incoming' ? 'bg-white text-sky-700 shadow-2xs' : 'text-zinc-600 hover:text-zinc-900'
              }`}
            >
              Pemohon
            </button>
            <button
              type="button"
              onClick={() => setAdminScope('mine')}
              className={`py-1 rounded-lg transition-colors ${
                adminScope === 'mine' ? 'bg-white text-teal-700 shadow-2xs' : 'text-zinc-600 hover:text-zinc-900'
              }`}
            >
              Tiket Saya
            </button>
          </div>
        )}

        <div className="relative">
          <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-zinc-400" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={isAdmin ? 'Cari pemohon, nomor tiket, pesan...' : 'Cari layanan, judul, atau pesan...'}
            className="pl-8 h-8 text-xs bg-white border-zinc-200 rounded-xl"
          />
        </div>
      </div>

      {/* List Content */}
      <div className="flex-1 overflow-y-auto overflow-x-hidden p-2 space-y-1 min-h-0 custom-scrollbar">
        <AnimatePresence mode="popLayout">
          {filtered.length === 0 ? (
            <motion.div
              initial={{ opacity: 0, scale: 0.95 }}
              animate={{ opacity: 1, scale: 1 }}
              exit={{ opacity: 0, scale: 0.95 }}
              className="p-8 text-center text-zinc-400 text-xs space-y-1"
            >
              <TicketIcon className="h-8 w-8 text-zinc-300 mx-auto mb-2 stroke-1" />
              <p className="font-semibold text-zinc-600">
                {conversations.length === 0 ? 'Belum ada percakapan tiket' : 'Tidak ada percakapan ditemukan'}
              </p>
              <p className="text-[11px] text-zinc-400">
                {isAdmin
                  ? 'Pemohon yang mengajukan tiket akan otomatis muncul di sini.'
                  : 'Tiket yang Anda ajukan akan otomatis muncul di sini.'}
              </p>
            </motion.div>
          ) : (
            filtered.map((item, index) => {
              const isActive =
                activeId !== null &&
                (item.conversation_ids && item.conversation_ids.length > 0
                  ? item.conversation_ids.includes(activeId)
                  : item.id === activeId);

              const isTicketCardForUser = !isAdmin && item.type === 'ticket';

              const targetEntityType =
                item.user?.entity_type || (item.requester_type === 'admin' ? 'admin' : 'user');

              const isOnline = item.user?.id
                ? onlineUsers.some((u) => u.id === item.user?.id && u.type === targetEntityType)
                : false;

              // Find typing indicator on this conversation or any ticket room in this requester group
              let typingName: string | undefined = typingByConversation[item.id];

              if (!typingName && item.conversation_ids) {
                const typingConvId = item.conversation_ids.find((cid) => Boolean(typingByConversation[cid]));

                if (typingConvId !== undefined) {
                  typingName = typingByConversation[typingConvId];
                }
              }

              const isLastSentBySelf =
                Boolean(currentUserId) &&
                item.last_message_sender_id === currentUserId &&
                ((currentUserType === 'user' && item.last_message_sender_type?.includes('User')) ||
                  (currentUserType === 'admin' && item.last_message_sender_type?.includes('Admin')));

              const avatarUrl = isTicketCardForUser
                ? null
                : item.user?.avatar ||
                  `https://ui-avatars.com/api/?name=${encodeURIComponent(item.user?.name || item.title)}&background=${
                    item.is_self_requester ? '0f766e' : item.requester_type === 'admin' ? '4f46e5' : '0284c7'
                  }&color=fff`;

              const icon = getAvatarIcon(item);
              const badgeInfo = item.ticket?.status ? statusMeta(item.ticket.status) : null;

              return (
                <motion.button
                  type="button"
                  layout="position"
                  initial={{ opacity: 0, y: 15 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, scale: 0.95 }}
                  transition={{ duration: 0.2, delay: Math.min(index * 0.02, 0.2), type: 'spring', bounce: 0 }}
                  key={item.group_key || item.id}
                  onClick={() => onSelect(resolveConversationToOpen(item))}
                  className="relative group block w-full text-left rounded-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:ring-offset-1"
                >
                  {isActive && (
                    <motion.div
                      layoutId="activeChatHighlight"
                      className="absolute inset-0 bg-sky-50 rounded-2xl border border-sky-100/70 shadow-xs"
                      initial={false}
                      transition={{ type: 'spring', bounce: 0.15, duration: 0.35 }}
                    />
                  )}
                  <div
                    className={`relative z-10 flex items-start gap-3 p-3 cursor-pointer rounded-2xl transition-all duration-200 ${
                      !isActive
                        ? 'hover:bg-zinc-50/80 hover:shadow-2xs hover:border-zinc-100/80 border border-transparent'
                        : 'border border-transparent'
                    }`}
                  >
                    {isActive && (
                      <motion.div
                        layoutId="activeIndicator"
                        className="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-8 bg-sky-500 rounded-r-full"
                      />
                    )}

                    {/* Avatar / Ticket Icon */}
                    <div className="relative shrink-0 mt-0.5">
                      <div
                        className={`h-10 w-10 rounded-full border overflow-hidden flex items-center justify-center text-sm shadow-2xs ${
                          isTicketCardForUser
                            ? 'bg-sky-50 border-sky-200/80 text-sky-700'
                            : 'bg-white border-zinc-200/60 text-sky-700 font-semibold'
                        }`}
                      >
                        {avatarUrl ? (
                          <img src={avatarUrl} alt="Avatar" className="h-full w-full object-cover" />
                        ) : (
                          icon
                        )}
                      </div>

                      {!isTicketCardForUser && (
                        <span
                          className={`absolute bottom-0 right-0 h-2.5 w-2.5 rounded-full border-2 border-white transition-colors duration-300 ${
                            isOnline ? 'bg-emerald-500 ring-1 ring-emerald-400' : 'bg-zinc-300'
                          }`}
                          title={isOnline ? 'Online' : 'Offline'}
                        />
                      )}
                    </div>

                    {/* Content */}
                    <div className="flex-1 min-w-0 py-0.5">
                      <div className="flex items-center justify-between gap-1.5 mb-0.5">
                        <div className="flex items-center gap-1.5 min-w-0">
                          <span
                            className={`text-xs truncate ${
                              item.unread_count > 0 ? 'font-bold text-zinc-900' : 'font-semibold text-zinc-800'
                            }`}
                          >
                            {item.title}
                          </span>

                          {isAdmin && item.is_self_requester && (
                            <Badge className="bg-teal-50 text-teal-700 hover:bg-teal-50 border border-teal-200 text-[9px] font-bold px-1.5 py-0 h-4 shrink-0">
                              Tiket Saya
                            </Badge>
                          )}

                          {isAdmin && !item.is_self_requester && item.requester_type === 'admin' && (
                            <Badge className="bg-indigo-50 text-indigo-700 hover:bg-indigo-50 border border-indigo-200 text-[9px] font-bold px-1.5 py-0 h-4 shrink-0">
                              Admin
                            </Badge>
                          )}

                          {isAdmin && (item.tickets_count ?? 0) > 1 && (
                            <Badge className="bg-sky-50 text-sky-700 hover:bg-sky-50 border border-sky-200 text-[9px] font-bold px-1.5 py-0 h-4 shrink-0">
                              {item.tickets_count} Tiket
                            </Badge>
                          )}

                          {!isAdmin && badgeInfo && (
                            <span
                              className={`text-[9px] font-bold px-1.5 py-0.5 rounded-md border shrink-0 ${badgeInfo.className}`}
                            >
                              {badgeInfo.label}
                            </span>
                          )}
                        </div>

                        <span className="text-[10px] text-zinc-400 shrink-0 font-medium">
                          {formatTime(item.last_message_at)}
                        </span>
                      </div>

                      {item.subtitle && (
                        <p className="text-[11px] font-medium text-sky-700 truncate mb-0.5">
                          {item.subtitle}
                        </p>
                      )}

                      {!isAdmin && item.ticket && (
                        <p className="text-[10px] text-zinc-500 truncate mb-1">
                          {ticketSecondaryLabel(item.ticket) ?? 'Layanan sama'}{' '}
                          {item.ticket.created_at && (
                            <span className="text-zinc-400">
                              · {format(new Date(item.ticket.created_at), 'dd/MM/yy HH:mm')}
                            </span>
                          )}
                          {' · '}
                          Ditangani:{' '}
                          <span className={`font-semibold ${item.ticket.handler ? 'text-zinc-700' : 'text-amber-600'}`}>
                            {item.ticket.handler ?? 'Menunggu petugas'}
                          </span>
                        </p>
                      )}

                      <div className="flex items-center justify-between gap-2">
                        {typingName ? (
                          <div className="flex items-center gap-1 text-sky-600 font-semibold text-xs italic animate-pulse min-w-0">
                            <span className="truncate">{typingName} sedang mengetik</span>
                            <span className="flex gap-0.5 items-center shrink-0">
                              <span className="w-1 h-1 bg-sky-500 rounded-full animate-bounce [animation-delay:-0.3s]" />
                              <span className="w-1 h-1 bg-sky-500 rounded-full animate-bounce [animation-delay:-0.15s]" />
                              <span className="w-1 h-1 bg-sky-500 rounded-full animate-bounce" />
                            </span>
                          </div>
                        ) : (
                          <div className="flex items-center gap-1 min-w-0">
                            {isLastSentBySelf && (
                              <span
                                className="shrink-0 inline-flex items-center"
                                title={item.is_last_message_read ? 'Sudah dibaca oleh penerima' : 'Pesan terkirim'}
                              >
                                {item.is_last_message_read ? (
                                  <CheckCheck className="h-3.5 w-3.5 text-sky-500 font-bold" />
                                ) : (
                                  <Check className="h-3.5 w-3.5 text-zinc-400" />
                                )}
                              </span>
                            )}
                            <p
                              className={`text-xs truncate ${
                                item.unread_count > 0 ? 'font-semibold text-zinc-900' : 'text-zinc-500'
                              }`}
                            >
                              {item.last_message}
                            </p>
                          </div>
                        )}

                        {item.unread_count > 0 && (
                          <Badge className="h-4 min-w-[16px] px-1 bg-sky-600 hover:bg-sky-600 text-[10px] font-bold text-white rounded-full flex items-center justify-center shrink-0">
                            {item.unread_count > 99 ? '99+' : item.unread_count}
                          </Badge>
                        )}
                      </div>
                    </div>
                  </div>
                </motion.button>
              );
            })
          )}
        </AnimatePresence>
      </div>
    </div>
  );
}

export default ConversationList;
