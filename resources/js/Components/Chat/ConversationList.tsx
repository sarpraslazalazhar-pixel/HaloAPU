import React, { useState } from 'react';
import { Search, Filter, MessageSquare, User as UserIcon, Shield, CheckCircle2, Bot, Check, CheckCheck } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { format, isToday, isYesterday } from 'date-fns';
import { motion, AnimatePresence } from 'framer-motion';

export interface ConversationItem {
  id: number;
  is_bot?: boolean;
  type?: string;
  user?: {
    id?: number;
    name?: string;
    email?: string;
    avatar?: string | null;
    last_seen_at?: string | null;
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
}

interface ConversationListProps {
  conversations: ConversationItem[];
  activeId: number | null;
  onSelect: (id: number) => void;
  isAdmin?: boolean;
  onlineUsers?: Array<{ id: number; name: string; type: string }>;
  typingMap?: Record<number, string>;
  currentUserId?: number;
  currentUserType?: 'user' | 'admin';
}

export function ConversationList({
  conversations,
  activeId,
  onSelect,
  isAdmin = false,
  onlineUsers = [],
  typingMap = {},
  currentUserId,
  currentUserType,
}: ConversationListProps) {
  const [search, setSearch] = useState('');
  const [filterUnread, setFilterUnread] = useState(false);

  const filtered = conversations.filter((c) => {
    if (filterUnread && c.unread_count === 0) return false;
    if (!search.trim()) return true;

    const term = search.toLowerCase();
    return (
      c.title.toLowerCase().includes(term) ||
      (c.subtitle?.toLowerCase().includes(term) ?? false) ||
      c.last_message.toLowerCase().includes(term) ||
      (c.user?.name?.toLowerCase().includes(term) ?? false) ||
      (c.user?.email?.toLowerCase().includes(term) ?? false)
    );
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

  // ponytail: determine avatar icon based on subtitle keywords or bot status
  const getAvatarIcon = (item: ConversationItem) => {
    if (item.is_bot || item.type === 'admin_bot_reminder' || item.title.includes('Bot Pengingat')) {
      return <Bot className="h-5 w-5 text-indigo-600" />;
    }
    if (item.user?.avatar) return null; // use img
    if (item.subtitle === 'Grup Publik') return <MessageSquare className="h-5 w-5 text-sky-600" />;
    if (item.subtitle?.includes('Admin')) return <Shield className="h-5 w-5 text-sky-600" />;
    return <UserIcon className="h-5 w-5 text-sky-600" />;
  };

  return (
    <div className="flex flex-col h-full min-h-0 bg-white border-r border-zinc-200 overflow-hidden">
      {/* Header & Search */}
      <div className="p-3.5 border-b border-zinc-100 flex flex-col gap-2.5 bg-zinc-50/50 shrink-0">
        <div className="flex items-center justify-between">
          <h2 className="text-base font-bold text-zinc-900 flex items-center gap-2">
            <MessageSquare className="h-5 w-5 text-sky-600" />
            <span>Percakapan</span>
          </h2>

          <Button
            variant={filterUnread ? 'default' : 'outline'}
            size="sm"
            onClick={() => setFilterUnread(!filterUnread)}
            className={`h-7 px-2.5 text-xs rounded-lg gap-1.5 transition-colors ${
              filterUnread ? 'bg-sky-600 hover:bg-sky-700 text-white' : 'text-zinc-600'
            }`}
          >
            <Filter className="h-3 w-3" />
            <span>Belum dibaca</span>
          </Button>
        </div>

        <div className="relative">
          <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-zinc-400" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Cari percakapan..."
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
              className="p-8 text-center text-zinc-400 text-xs"
            >
              Tidak ada percakapan ditemukan
            </motion.div>
          ) : (
          filtered.map((item, index) => {
            const isActive = activeId === item.id;
            const isBotItem = item.is_bot || item.type === 'admin_bot_reminder' || item.title.includes('Bot Pengingat');
            const isPublicGroup = item.type === 'public_global' || item.subtitle === 'Grup Publik';

            // Determine if conversation counterpart is currently online
            const isOnline = isBotItem || isPublicGroup || (
              item.user?.id ? onlineUsers.some((u) => {
                const sameId = u.id === item.user?.id;
                const isTargetAdmin = item.subtitle?.includes('Admin') || item.type === 'admin_direct';
                const sameType = isTargetAdmin ? u.type === 'admin' : u.type === 'user';
                return sameId && sameType;
              }) : false
            );

            // Determine if someone is currently typing in this conversation
            const isTypingNow = typingMap && typingMap[item.id];

            // Determine if last message was sent by self
            const isLastSentBySelf = currentUserId && item.last_message_sender_id === currentUserId && (
              (currentUserType === 'user' && item.last_message_sender_type?.includes('User')) ||
              (currentUserType === 'admin' && item.last_message_sender_type?.includes('Admin'))
            );

            const avatarUrl = isBotItem ? null : (
              item.user?.avatar ||
              (item.subtitle !== 'Grup Publik' && item.title !== 'Forum Bantuan Halo APU'
                ? `https://ui-avatars.com/api/?name=${encodeURIComponent(item.title)}&background=0284c7&color=fff`
                : null)
            );
            const icon = getAvatarIcon(item);

            return (
              <motion.div
                layout="position"
                initial={{ opacity: 0, y: 15 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.95 }}
                transition={{ duration: 0.2, delay: index * 0.03, type: 'spring', bounce: 0 }}
                key={item.id}
                onClick={() => onSelect(item.id)}
                className="relative group block"
              >
                {isActive && (
                  <motion.div
                    layoutId="activeChatHighlight"
                    className="absolute inset-0 bg-sky-50 rounded-2xl border border-sky-100/50 shadow-sm"
                    initial={false}
                    transition={{ type: 'spring', bounce: 0.15, duration: 0.4 }}
                  />
                )}
                <div
                  className={`relative z-10 flex items-start gap-3 p-3 cursor-pointer rounded-2xl transition-all duration-200 ${
                    !isActive ? 'hover:bg-zinc-50/80 hover:shadow-sm hover:border hover:border-zinc-100/60 border border-transparent' : 'border border-transparent'
                  }`}
                >
                  {isActive && (
                    <motion.div 
                      layoutId="activeIndicator"
                      className="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-8 bg-sky-500 rounded-r-full" 
                    />
                  )}
                {/* Avatar with Presence Indicator */}
                <div className="relative shrink-0 mt-0.5">
                  <div className={`h-10 w-10 rounded-full border overflow-hidden flex items-center justify-center text-sm shadow-xs ${
                    isBotItem ? 'bg-indigo-50 border-indigo-200 text-indigo-700' : 'bg-white border-zinc-200/60 text-sky-700 font-semibold'
                  }`}>
                    {avatarUrl ? (
                      <img src={avatarUrl} alt="Avatar" className="h-full w-full object-cover" />
                    ) : icon}
                  </div>
                  {/* Presence Status Dot */}
                  <span
                    className={`absolute bottom-0 right-0 h-2.5 w-2.5 rounded-full border-2 border-white transition-colors duration-300 ${
                      isOnline ? 'bg-emerald-500 ring-1 ring-emerald-400' : 'bg-zinc-300'
                    }`}
                    title={isOnline ? 'Online' : 'Offline'}
                  />
                </div>

                {/* Content */}
                <div className="flex-1 min-w-0 py-0.5">
                  <div className="flex items-center justify-between gap-1 mb-0.5">
                    <div className="flex items-center gap-1.5 min-w-0">
                      <span className={`text-xs truncate ${item.unread_count > 0 ? 'font-bold text-zinc-900' : 'font-semibold text-zinc-800'}`}>
                        {item.title}
                      </span>
                      {isBotItem && (
                        <Badge className="bg-indigo-100 text-indigo-700 hover:bg-indigo-100 border-indigo-200 text-[9px] font-bold px-1.5 py-0 h-4 shrink-0">
                          BOT
                        </Badge>
                      )}
                    </div>
                    <span className="text-[10px] text-zinc-400 shrink-0 font-medium">
                      {formatTime(item.last_message_at)}
                    </span>
                  </div>

                  {item.subtitle && (
                    <div className="flex items-center gap-1.5 mb-1">
                      <p className={`text-[11px] font-medium truncate ${isBotItem ? 'text-indigo-600 font-semibold' : 'text-sky-700'}`}>
                        {item.subtitle}
                      </p>
                      {item.is_assigned && (
                        <span className="inline-flex items-center gap-0.5 px-1.5 py-0 rounded-full bg-emerald-100 text-emerald-700 text-[9px] font-bold shrink-0">
                          <CheckCircle2 className="h-2.5 w-2.5" />
                          Ditugaskan
                        </span>
                      )}
                    </div>
                  )}

                  <div className="flex items-center justify-between gap-2">
                    {isTypingNow ? (
                      <div className="flex items-center gap-1 text-sky-600 font-semibold text-xs italic animate-pulse min-w-0">
                        <span className="truncate">{isTypingNow} sedang mengetik</span>
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
                        <p className={`text-xs truncate ${item.unread_count > 0 ? 'font-semibold text-zinc-900' : 'text-zinc-500'}`}>
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
              </motion.div>
            );
          })
        )}
        </AnimatePresence>
      </div>
    </div>
  );
}

export default ConversationList;
