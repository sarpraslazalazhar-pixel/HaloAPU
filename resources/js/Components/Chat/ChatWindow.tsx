import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import axios from 'axios';
import toast from 'react-hot-toast';
import Swal from 'sweetalert2';
import {
  Send,
  Paperclip,
  Ticket as TicketIcon,
  Smile,
  X,
  Check,
  CheckCheck,
  Download,
  FileText,
  Edit2,
  Trash2,
  CornerUpLeft,
  Loader2,
  ExternalLink,
  MessageSquare,
  Copy,
  ChevronLeft,
  ChevronDown,
  Search,
  Eye,
  Bot,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/Components/ui/dialog';
import TicketReferenceCard from './TicketReferenceCard';
import { AttachmentViewer } from '@/Components/AttachmentViewer';
import type { TicketRoomSummary } from './ConversationList';
import { useChatSound } from '@/hooks/useChatSound';
import { subscribe, type ChatEvents } from '@/lib/realtime';
import { format } from 'date-fns';
import { motion, AnimatePresence } from 'framer-motion';

function ticketStatusLabel(status?: string | null) {
  const s = (status || '').toLowerCase();

  if (s === 'open') {
    return { label: 'Baru', className: 'bg-sky-100 text-sky-800 border-sky-200' };
  }

  if (s === 'on_proses') {
    return { label: 'Diproses', className: 'bg-blue-100 text-blue-800 border-blue-200' };
  }

  if (s === 'pending') {
    return { label: 'Tertunda', className: 'bg-amber-100 text-amber-800 border-amber-200' };
  }

  if (s === 'need_revision') {
    return { label: 'Perlu Revisi', className: 'bg-orange-100 text-orange-800 border-orange-200' };
  }

  if (s === 'solve' || s === 'selesai') {
    return { label: 'Selesai', className: 'bg-emerald-100 text-emerald-800 border-emerald-200' };
  }

  if (s === 'reject') {
    return { label: 'Ditolak', className: 'bg-rose-100 text-rose-800 border-rose-200' };
  }

  if (s === 'dibatalkan') {
    return { label: 'Dibatalkan', className: 'bg-zinc-200 text-zinc-700 border-zinc-300' };
  }

  return { label: status || 'Aktif', className: 'bg-zinc-100 text-zinc-700 border-zinc-200' };
}

export interface ChatMessage {
  id: number;
  conversation_id: number;
  sender_type: string;
  sender_id: number;
  sender_name: string;
  sender_avatar?: string | null;
  sender?: { avatar_path?: string | null } | null;
  requester_key?: string | null;
  requester_type?: string | null;
  requester_id?: number | null;
  requester_name?: string | null;
  requester_avatar?: string | null;
  ticket?: {
    id: number;
    formatted_id: string;
    judul?: string;
    chat_label?: string | null;
    created_at?: string | null;
    nama_layanan?: string | null;
    nama_unit?: string | null;
    status?: string;
    priority?: string;
    created_by_admin_id?: number | null;
    requester?: string | null;
    handler?: string | null;
  } | null;
  reply_to_message_id?: number | null;
  reply_to?: {
    id: number;
    body?: string;
    sender_name?: string;
  } | null;
  body?: string;
  is_edited?: boolean;
  attachments?: Array<{
    id: number;
    file_name: string;
    file_path: string;
    file_type?: string;
    file_size: number;
  }>;
  reads?: Array<{
    user_type: string;
    user_id: number;
    read_at?: string;
  }>;
  created_at: string;
}

interface ChatWindowProps {
  conversation: {
    id: number | null;
    group_key?: string | null;
    requester_type?: string | null;
    is_self_requester?: boolean;
    user?: {
      id: number;
      name: string;
      email?: string;
      avatar?: string | null;
      last_seen_at?: string | null;
      entity_type?: string;
    } | null;
    ticket_id?: number | null;
    ticket?: {
      id: number;
      formatted_id: string;
      judul?: string;
      chat_label?: string | null;
      created_at?: string | null;
      nama_layanan?: string | null;
      nama_unit?: string | null;
      status?: string;
      priority?: string;
      requester?: string | null;
      handler?: string | null;
    } | null;
    title?: string;
    subtitle?: string;
    is_bot?: boolean;
    type?: string;
  } | null;
  initialMessages: ChatMessage[];
  initialTicket?: ChatMessage['ticket'] | null;
  ticketRooms?: TicketRoomSummary[];
  onSelectTicketRoom?: (conversationId: number) => void;
  currentUser: {
    id: number;
    name?: string;
    username?: string;
    type: 'user' | 'admin';
  };
  isAdmin?: boolean;
  onBack?: () => void;
  onMessageSent?: (message: ChatMessage) => void;
  onMessageReceived?: (message: ChatMessage) => void;
  isLoading?: boolean;
  onlineUsers?: Array<{ id: number; name: string; type: string }>;
}

const COMMON_EMOJIS = ['😊', '👍', '🙏', '🚀', '✅', '⚠️', '📄', '❤️', '👏', '🔥', '💡', '❓', '💬', '🎉'];

export function ChatWindow({
  conversation,
  initialMessages,
  initialTicket = null,
  ticketRooms,
  onSelectTicketRoom,
  currentUser,
  isAdmin = false,
  onBack,
  onMessageSent,
  onMessageReceived,
  isLoading = false,
  onlineUsers = [],
}: ChatWindowProps) {
  const [messages, setMessages] = useState<ChatMessage[]>(initialMessages);
  const [inputText, setInputText] = useState('');
  const [selectedTicket, setSelectedTicket] = useState<ChatMessage['ticket'] | null>(initialTicket ?? null);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [replyingTo, setReplyingTo] = useState<ChatMessage | null>(null);
  const [editingMessage, setEditingMessage] = useState<ChatMessage | null>(null);
  const [isSending, setIsSending] = useState(false);

  const [isTyping, setIsTyping] = useState(false);
  const [typingUser, setTypingUser] = useState<string | null>(null);

  const [showEmojiPicker, setShowEmojiPicker] = useState(false);
  const [showTicketModal, setShowTicketModal] = useState(false);
  const [ticketsList, setTicketsList] = useState<any[]>([]);
  const [ticketSearch, setTicketSearch] = useState('');
  const [loadingTickets, setLoadingTickets] = useState(false);

  const [previewImage, setPreviewImage] = useState<string | null>(null);
  const [showTicketSwitcher, setShowTicketSwitcher] = useState(false);
  const [ticketRoomSearch, setTicketRoomSearch] = useState('');

  const messagesEndRef = useRef<HTMLDivElement | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const typingTimeoutRef = useRef<any>(null);

  const { playSound } = useChatSound();

  // Keep the latest callback without forcing the Echo effect to resubscribe.
  const onMessageReceivedRef = useRef(onMessageReceived);
  onMessageReceivedRef.current = onMessageReceived;

  // Scroll to bottom
  const scrollToBottom = useCallback((smooth = true) => {
    messagesEndRef.current?.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto' });
  }, []);

  useEffect(() => {
    setMessages(initialMessages);
    scrollToBottom(false);
  }, [initialMessages, conversation?.id, scrollToBottom]);

  // Follow the room: arriving from /chat?ticket=N preselects that ticket, but a
  // ticket room already owns its destination so it never attaches another.
  useEffect(() => {
    setSelectedTicket(conversation?.ticket_id ? null : initialTicket);
  }, [initialTicket, conversation?.ticket_id]);

  // Handle Realtime Echo Listener
  useEffect(() => {
    if (!conversation?.id) return;

    const conversationId = conversation.id;

    return subscribe<ChatEvents>(`chat.conversation.${conversationId}`, {
      '.ChatMessageSent': ({ messageData: newMsg }) => {
        setMessages((prev) => {
          if (prev.some((m) => m.id === newMsg.id)) return prev;

          return [...prev, newMsg];
        });

        onMessageReceivedRef.current?.(newMsg);

        // If not sent by current user, play sound & toast
        const isSelf =
          newMsg.sender_id === currentUser.id &&
          ((currentUser.type === 'user' && newMsg.sender_type.includes('User')) ||
            (currentUser.type === 'admin' && newMsg.sender_type.includes('Admin')));

        if (!isSelf) {
          playSound();

          if (document.hidden) {
            toast.success(`Pesan Baru dari ${newMsg.sender_name}`, { id: `chat-notif-${newMsg.id}` });

            if ('Notification' in window && Notification.permission === 'granted') {
              new Notification(`Pesan Baru - ${newMsg.sender_name}`, {
                body: newMsg.body || '[Lampiran]',
                icon: '/logo.png',
              });
            }
          } else {
            // Window is active, auto-mark as read
            const readUrl = isAdmin
              ? route('admin.chat.read', { conversation: conversationId })
              : route('chat.read', { conversation: conversationId });

            axios.post(readUrl).catch(() => {});
          }
        }

        scrollToBottom(true);
      },
      '.ChatMessageEdited': ({ messageId, body }) => {
        setMessages((prev) =>
          prev.map((m) => (m.id === messageId ? { ...m, body, is_edited: true } : m))
        );
      },
      '.ChatMessageDeleted': ({ messageId }) => {
        setMessages((prev) => prev.filter((m) => m.id !== messageId));
      },
      '.ChatMessageRead': ({ userId, userType, readAt }) => {
        setMessages((prev) =>
          prev.map((m) => {
            const alreadyRead = m.reads?.some((r) => {
              const sameUser = r.user_id === userId;

              const sameType =
                (r.user_type?.includes('User') && userType?.includes('User')) ||
                (r.user_type?.includes('Admin') && userType?.includes('Admin'));

              return sameUser && sameType;
            });

            if (alreadyRead) return m;

            return {
              ...m,
              reads: [...(m.reads || []), { user_type: userType, user_id: userId, read_at: readAt }],
            };
          })
        );
      },
      '.UserTypingStatus': ({ userId, isTyping: typing, userName }) => {
        if (userId !== currentUser.id) {
          setTypingUser(typing ? userName : null);
        }
      },
    });
  }, [conversation?.id, currentUser.id, currentUser.type, isAdmin, playSound, scrollToBottom]);

  // Polling fallback: without a live socket a room would only refresh on
  // navigation. Ticket rooms poll the existing show endpoint while visible.
  useEffect(() => {
    if (!conversation?.id || !conversation.ticket_id) return;

    const conversationId = conversation.id;

    const showUrl = isAdmin
      ? route('admin.chat.conversations.show', { conversation: conversationId })
      : route('chat.conversations.show', { conversation: conversationId });

    let cancelled = false;
    let inFlight = false;

    const poll = async () => {
      if (cancelled || inFlight) return;

      if (typeof document !== 'undefined' && document.hidden) return;

      if (window.Echo?.socketId()) return;

      inFlight = true;

      try {
        const res = await axios.get(showUrl);
        const next = res.data?.messages;

        if (!cancelled && Array.isArray(next)) {
          // The server snapshot is canonical: ids replace, never duplicate.
          setMessages(next);
        }
      } catch {
        // Keep the current history; the next tick retries.
      } finally {
        inFlight = false;
      }
    };

    const timer = window.setInterval(poll, 10000);

    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [conversation?.id, conversation?.ticket_id, isAdmin]);

  // Handle typing status broadcast trigger
  const handleInputChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
    setInputText(e.target.value);

    if (!conversation?.id) return;

    if (!isTyping) {
      setIsTyping(true);

      const typingUrl = isAdmin
        ? route('admin.chat.typing', { conversation: conversation.id })
        : route('chat.typing', { conversation: conversation.id });

      axios.post(typingUrl, { is_typing: true }).catch(() => {});
    }

    if (typingTimeoutRef.current) clearTimeout(typingTimeoutRef.current);

    typingTimeoutRef.current = setTimeout(() => {
      setIsTyping(false);

      const typingUrl = isAdmin
        ? route('admin.chat.typing', { conversation: conversation.id })
        : route('chat.typing', { conversation: conversation.id });

      axios.post(typingUrl, { is_typing: false }).catch(() => {});
    }, 2000);
  };

  // Fetch tickets for attachment modal
  const fetchTickets = async () => {
    setLoadingTickets(true);

    try {
      const url = isAdmin
        ? route('admin.chat.tickets', { conversation_id: conversation?.id })
        : route('chat.tickets');

      const res = await axios.get(url);
      setTicketsList(res.data.tickets || []);
    } catch {
      toast.error('Gagal memuat daftar tiket');
    } finally {
      setLoadingTickets(false);
    }
  };

  const handleOpenTicketModal = () => {
    setTicketSearch('');
    setShowTicketModal(true);
    fetchTickets();
  };

  // Cari tiket berdasarkan nomor, judul, atau status.
  const filteredTickets = useMemo(() => {
    const term = ticketSearch.trim().toLowerCase();

    if (!term) return ticketsList;

    return ticketsList.filter((t) => {
      const haystack = [t.formatted_id, t.judul, t.status, t.priority]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

      return haystack.includes(term);
    });
  }, [ticketsList, ticketSearch]);

  // File selection & 3MB Validation
  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      const file = e.target.files[0];
      const maxBytes = 3 * 1024 * 1024; // 3 MB

      if (file.size > maxBytes) {
        toast.error(`Ukuran file ${file.name} melebihi batas 3 MB!`);
        e.target.value = '';

        return;
      }

      setSelectedFile(file);
    }
  };

  // Submit Send Message
  const handleSendMessage = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();

    if (!conversation?.id || isSending) return;

    if (editingMessage) {
      // Edit existing message
      try {
        const updateUrl = isAdmin
          ? route('admin.chat.messages.update', { message: editingMessage.id })
          : route('chat.messages.update', { message: editingMessage.id });

        await axios.put(updateUrl, { body: inputText });

        setMessages((prev) =>
          prev.map((m) =>
            m.id === editingMessage.id ? { ...m, body: inputText, is_edited: true } : m
          )
        );

        setEditingMessage(null);
        setInputText('');
        toast.success('Pesan diperbarui');
      } catch (err: any) {
        toast.error(err.response?.data?.error || 'Gagal memperbarui pesan');
      }

      return;
    }

    if (!inputText.trim() && !selectedTicket && !selectedFile) return;

    setIsSending(true);
    const formData = new FormData();

    if (inputText.trim()) formData.append('body', inputText.trim());

    if (selectedTicket && !conversation?.ticket_id) formData.append('ticket_id', String(selectedTicket.id));

    if (replyingTo) formData.append('reply_to_message_id', String(replyingTo.id));

    if (selectedFile) formData.append('attachment', selectedFile);

    try {
      const storeUrl = isAdmin
        ? route('admin.chat.messages.store', { conversation: conversation.id })
        : route('chat.messages.store', { conversation: conversation.id });

      const res = await axios.post(storeUrl, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });

      const newMsg = res.data.message;

      if (newMsg) {
        setMessages((prev) => [...prev, newMsg]);
        scrollToBottom(true);
        onMessageSent?.(newMsg);
      }

      setInputText('');
      setSelectedTicket(null);
      setSelectedFile(null);
      setReplyingTo(null);
      setShowEmojiPicker(false);
    } catch (err: any) {
      toast.error(err.response?.data?.error || 'Gagal mengirim pesan. Periksa koneksi.');
    } finally {
      setIsSending(false);
    }
  };

  const [contextMenu, setContextMenu] = useState<{
    x: number;
    y: number;
    message: ChatMessage;
  } | null>(null);

  // Close context menu on window click
  useEffect(() => {
    const handleClick = () => setContextMenu(null);
    window.addEventListener('click', handleClick);

    return () => window.removeEventListener('click', handleClick);
  }, []);

  const handleDeleteMessage = async (msgId: number) => {
    setContextMenu(null);

    const result = await Swal.fire({
      title: 'Hapus Pesan?',
      text: 'Pesan ini akan dihapus dari percakapan.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#ef4444',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Ya, Hapus!',
      cancelButtonText: 'Batal',
      customClass: {
        popup: 'rounded-2xl shadow-xl border border-zinc-100',
        confirmButton: 'rounded-xl text-xs font-semibold px-4 py-2 bg-red-600 hover:bg-red-700 text-white',
        cancelButton: 'rounded-xl text-xs font-semibold px-4 py-2 bg-zinc-100 hover:bg-zinc-200 text-zinc-700',
      },
    });

    if (!result.isConfirmed) return;

    try {
      const delUrl = isAdmin
        ? route('admin.chat.messages.destroy', { message: msgId })
        : route('chat.messages.destroy', { message: msgId });

      await axios.delete(delUrl);
      setMessages((prev) => prev.filter((m) => m.id !== msgId));
      toast.success('Pesan berhasil dihapus');
    } catch (err: any) {
      toast.error(err.response?.data?.error || 'Gagal menghapus pesan');
    }
  };

  const formatMessageTime = (dateStr: string) => {
    try {
      const d = new Date(dateStr);

      return format(d, 'HH:mm');
    } catch {
      return '';
    }
  };

  if (!conversation || !conversation.id) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center bg-zinc-50 p-8 text-center text-zinc-400">
        <MessageSquare className="h-12 w-12 text-zinc-300 mb-3" />
        <p className="text-sm font-semibold text-zinc-700">Pilih Percakapan</p>
        <p className="text-xs text-zinc-500 max-w-sm mt-1">
          {isAdmin
            ? 'Pilih salah satu pemohon tiket di sebelah kiri untuk membuka diskusi tiket.'
            : 'Pilih salah satu tiket yang Anda ajukan di sebelah kiri untuk mulai berkirim pesan.'}
        </p>
      </div>
    );
  }

  const normalizedTicketStatus = (conversation.ticket?.status || '').toLowerCase();

  const isTicketClosed =
    conversation.type === 'ticket' &&
    ['solve', 'selesai', 'reject', 'dibatalkan'].includes(normalizedTicketStatus);

  const currentTicketStatusMeta = conversation.ticket?.status
    ? ticketStatusLabel(conversation.ticket.status)
    : null;

  return (
    <div className="flex-1 flex flex-col h-full min-h-0 bg-zinc-50/50 relative overflow-hidden">
      {/* 1. Header */}
      <motion.div
        initial={{ y: -20, opacity: 0 }}
        animate={{ y: 0, opacity: 1 }}
        className="h-14 px-4 bg-white/90 backdrop-blur-md border-b border-zinc-200/70 flex items-center justify-between shrink-0 shadow-2xs z-10"
      >
        <div className="flex items-center gap-3 min-w-0">
          {onBack && (
            <button onClick={onBack} className="p-1.5 -ml-2 rounded-xl text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 transition-colors md:hidden">
              <ChevronLeft className="h-5 w-5" />
            </button>
          )}
          {/* Avatar with dynamic presence */}
          {(() => {
            const isBot = conversation.is_bot || conversation.type === 'admin_bot_reminder' || conversation.title?.includes('Bot Pengingat');
            const isTicketRoomForUser = !isAdmin && conversation.type === 'ticket';

            const targetEntityType =
              conversation.user?.entity_type || (conversation.requester_type === 'admin' ? 'admin' : 'user');

            const isOnline = isBot || (
              conversation.user?.id ? (onlineUsers || []).some((u) => {
                return u.id === conversation.user?.id && u.type === targetEntityType;
              }) : false
            );

            return (
              <>
                <div className="relative shrink-0">
                  <div className={`h-8 w-8 rounded-full border overflow-hidden flex items-center justify-center text-xs font-semibold ${
                    isBot
                      ? 'bg-indigo-50 border-indigo-200 text-indigo-700'
                      : isTicketRoomForUser
                        ? 'bg-sky-50 border-sky-200 text-sky-700'
                        : 'bg-white border-zinc-200 text-sky-700'
                  }`}>
                    {isBot ? (
                      <Bot className="h-5 w-5 text-indigo-600" />
                    ) : isTicketRoomForUser ? (
                      <TicketIcon className="h-4 w-4 text-sky-700" />
                    ) : conversation.user?.avatar ? (
                      <img src={conversation.user.avatar} alt="Avatar" className="h-full w-full object-cover" />
                    ) : (
                      <img
                        src={`https://ui-avatars.com/api/?name=${encodeURIComponent(conversation.user?.name || conversation.title || 'Chat')}&background=${
                          conversation.is_self_requester ? '0f766e' : conversation.requester_type === 'admin' ? '4f46e5' : '0284c7'
                        }&color=fff`}
                        alt="Avatar"
                        className="h-full w-full object-cover"
                      />
                    )}
                  </div>
                  {!isTicketRoomForUser && (
                    <span
                      className={`absolute bottom-0 right-0 h-2.5 w-2.5 rounded-full border-2 border-white transition-colors duration-300 ${
                        isOnline ? 'bg-emerald-500 ring-1 ring-emerald-400' : 'bg-zinc-300'
                      }`}
                      title={isOnline ? 'Online' : 'Offline'}
                    />
                  )}
                </div>

                <div className="min-w-0">
                  <div className="flex items-center gap-1.5 min-w-0">
                    <h3 className="text-xs font-bold text-zinc-900 truncate">
                      {conversation.ticket?.chat_label || conversation.title || 'Percakapan'}
                    </h3>
                    {isAdmin && conversation.is_self_requester && (
                      <Badge className="bg-teal-50 text-teal-700 hover:bg-teal-50 border border-teal-200 text-[9px] font-bold px-1.5 py-0 h-4 shrink-0">
                        Tiket Saya
                      </Badge>
                    )}
                    {isAdmin && !conversation.is_self_requester && conversation.requester_type === 'admin' && (
                      <Badge className="bg-indigo-50 text-indigo-700 hover:bg-indigo-50 border border-indigo-200 text-[9px] font-bold px-1.5 py-0 h-4 shrink-0">
                        Admin Pemohon
                      </Badge>
                    )}
                  </div>
                  {typingUser ? (
                    <div className="flex items-center gap-1 text-[11px] text-sky-600 font-semibold animate-pulse">
                      <span className="truncate">{typingUser} sedang mengetik</span>
                      <span className="flex gap-0.5 items-center shrink-0">
                        <span className="w-1 h-1 bg-sky-500 rounded-full animate-bounce [animation-delay:-0.3s]" />
                        <span className="w-1 h-1 bg-sky-500 rounded-full animate-bounce [animation-delay:-0.15s]" />
                        <span className="w-1 h-1 bg-sky-500 rounded-full animate-bounce" />
                      </span>
                    </div>
                  ) : isTicketRoomForUser ? (
                    <p className="text-[11px] text-zinc-500 truncate">
                      {conversation.ticket?.judul || conversation.subtitle || 'Diskusi Tiket'}
                    </p>
                  ) : isOnline ? (
                    <p className="text-[11px] text-emerald-600 font-semibold flex items-center gap-1.5 truncate">
                      <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse inline-block shrink-0" />
                      Online
                    </p>
                  ) : (
                    <p className="text-[11px] text-zinc-400 truncate">
                      Offline {conversation.user?.last_seen_at ? `• Terakhir dilihat ${formatMessageTime(conversation.user.last_seen_at)}` : ''}
                    </p>
                  )}
                </div>
              </>
            );
          })()}
        </div>

        {conversation.ticket && (
          <div className="flex items-center gap-2 shrink-0">
            <a
              href={isAdmin ? `/admin/tiket/${conversation.ticket.id}` : `/tiket/${conversation.ticket.id}`}
              className="text-xs text-sky-600 hover:text-sky-700 font-semibold flex items-center gap-1 bg-sky-50 hover:bg-sky-100 px-2.5 py-1 rounded-lg border border-sky-200 transition-colors"
            >
              <span>Detail Tiket</span>
              <ExternalLink className="h-3 w-3" />
            </a>
          </div>
        )}
      </motion.div>

      {/* 1b. Admin ticket picker: one requester may hold many ticket rooms. */}
      {isAdmin && ticketRooms && ticketRooms.length > 1 && (
        <div className="px-3 py-2 bg-zinc-50/90 border-b border-zinc-200/70 flex items-center gap-2 shrink-0">
          <button
            type="button"
            onClick={() => {
              setTicketRoomSearch('');
              setShowTicketSwitcher(true);
            }}
            className="inline-flex items-center gap-2 min-w-0 px-2.5 py-1.5 rounded-xl text-[11px] border bg-white text-zinc-700 border-zinc-200 hover:border-sky-300 hover:bg-sky-50/60 transition-colors"
          >
            <TicketIcon className="h-3.5 w-3.5 shrink-0 text-sky-600" />
            <span className="font-semibold text-zinc-800 shrink-0">Tiket ({ticketRooms.length})</span>
            <span className="text-zinc-300 shrink-0">|</span>
            <span className="truncate max-w-[220px] text-zinc-700">
              {conversation.ticket?.chat_label || conversation.ticket?.nama_layanan || 'Diskusi Tiket'}
            </span>
            {conversation.ticket?.created_at && (
              <span className="text-zinc-500 shrink-0">
                · {format(new Date(conversation.ticket.created_at), 'dd/MM/yy HH:mm')}
              </span>
            )}
            <ChevronDown className="h-3.5 w-3.5 shrink-0 text-zinc-500" />
          </button>
          <span className="hidden sm:block text-[10px] text-zinc-500 truncate">
            Pilih tiket untuk dibalas
          </span>
        </div>
      )}

      {/* 1c. Active Ticket Banner: says which ticket this room replies to. */}
      {conversation.ticket && (() => {
        const manyTickets = Boolean(ticketRooms && ticketRooms.length > 1);
        const otherTickets = ticketRooms ? ticketRooms.length - 1 : 0;
        const ticketName = conversation.ticket.chat_label || conversation.ticket.nama_layanan || 'Diskusi Tiket';

        return (
          <div
            className={`mx-3 mt-2.5 px-3 py-2 rounded-xl border flex items-center justify-between gap-2 shrink-0 ${
              manyTickets ? 'bg-amber-50/90 border-amber-200/80' : 'bg-sky-50/90 border-sky-200/80'
            }`}
          >
            <div className="flex items-center gap-2 min-w-0">
              <TicketIcon className={`h-4 w-4 shrink-0 ${manyTickets ? 'text-amber-700' : 'text-sky-700'}`} />
              <div className="min-w-0">
                <p className={`text-[11px] font-semibold ${manyTickets ? 'text-amber-800' : 'text-sky-800'}`}>
                  {manyTickets
                    ? `Pesan masuk ke tiket ini, bukan ${otherTickets} tiket lain pemohon`
                    : 'Pesan masuk ke tiket ini'}
                </p>
                <div className="flex items-center gap-1.5 flex-wrap">
                  <span className={`text-xs font-bold truncate ${manyTickets ? 'text-amber-950' : 'text-sky-900'}`}>
                    {ticketName}
                  </span>
                  {conversation.ticket.created_at && (
                    <span className={`text-[10px] font-medium truncate ${manyTickets ? 'text-amber-700' : 'text-sky-700'}`}>
                      · {format(new Date(conversation.ticket.created_at), 'dd/MM/yy HH:mm')}
                    </span>
                  )}
                </div>
                <p className={`text-[11px] truncate ${manyTickets ? 'text-amber-900/80' : 'text-sky-950/80'}`}>
                  {conversation.ticket.judul || 'Diskusi Tiket'}
                  {' · '}
                  <span className={manyTickets ? 'text-amber-700' : 'text-sky-700'}>
                    Ditangani: {conversation.ticket.handler ?? 'Menunggu petugas'}
                  </span>
                </p>
              </div>
            </div>

            {currentTicketStatusMeta && (
              <span
                className={`text-[10px] font-bold px-2 py-0.5 rounded-lg border shrink-0 ${currentTicketStatusMeta.className}`}
              >
                {currentTicketStatusMeta.label}
              </span>
            )}
          </div>
        );
      })()}

      {/* 2. Messages Body */}
      <div className="flex-1 overflow-y-auto p-4 space-y-3 scroll-smooth min-h-0 custom-scrollbar">
        {isLoading ? (
          <div className="h-full flex flex-col items-center justify-center text-center p-6 text-zinc-400 gap-2">
            <Loader2 className="h-6 w-6 animate-spin text-sky-600" />
            <p className="text-xs text-zinc-500 font-medium">Memuat percakapan...</p>
          </div>
        ) : (
          <AnimatePresence initial={false}>
          {messages.length === 0 ? (
            <motion.div 
              initial={{ opacity: 0, scale: 0.9 }}
              animate={{ opacity: 1, scale: 1 }}
              className="h-full flex flex-col items-center justify-center text-center p-6 text-zinc-400"
            >
              <MessageSquare className="h-10 w-10 text-zinc-300 mb-2" />
              <p className="text-xs font-medium text-zinc-600">Belum ada pesan</p>
              <p className="text-[11px] text-zinc-400 max-w-xs mt-0.5">
                Kirim pesan pertama untuk memulai percakapan realtime.
              </p>
            </motion.div>
          ) : (
          messages.map((msg) => {
            const isSelf = msg.sender_id === currentUser.id &&
              ((currentUser.type === 'user' && msg.sender_type.includes('User')) ||
               (currentUser.type === 'admin' && msg.sender_type.includes('Admin')));

            const isBotMsg = conversation.is_bot || conversation.type === 'admin_bot_reminder' || msg.sender_name?.includes('Bot Pengingat') || msg.sender_name === 'Sistem';

            const isReadByOthers = msg.reads && msg.reads.some((r) => {
              const isSender =
                r.user_id === msg.sender_id &&
                ((msg.sender_type?.includes('User') && r.user_type?.includes('User')) ||
                 (msg.sender_type?.includes('Admin') && r.user_type?.includes('Admin')));

              return !isSender;
            });

            return (
              <motion.div
                layout="position"
                initial={{ opacity: 0, scale: 0.8, y: 20, originX: isSelf ? 1 : 0 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                transition={{ type: 'spring', bounce: 0.3, duration: 0.4 }}
                key={msg.id}
                className={`flex items-end gap-2.5 group ${isSelf ? 'justify-end' : 'justify-start'}`}
              >
                {!isSelf && (
                  <div className={`h-7 w-7 rounded-full border overflow-hidden flex items-center justify-center shrink-0 text-xs font-semibold ${
                    isBotMsg ? 'bg-indigo-50 border-indigo-200 text-indigo-700' : 'bg-sky-100 border-sky-200 text-sky-700'
                  }`}>
                    {isBotMsg ? (
                      <Bot className="h-4 w-4 text-indigo-600" />
                    ) : msg.sender_avatar || msg.sender?.avatar_path ? (
                      <img
                        src={msg.sender_avatar || ('/storage/' + msg.sender?.avatar_path)}
                        alt={msg.sender_name}
                        className="h-full w-full object-cover"
                      />
                    ) : (
                      <img
                        src={`https://ui-avatars.com/api/?name=${encodeURIComponent(msg.sender_name || 'U')}&background=0284c7&color=fff`}
                        alt={msg.sender_name}
                        className="h-full w-full object-cover"
                      />
                    )}
                  </div>
                )}

                <div
                  onContextMenu={(e) => {
                    e.preventDefault();
                    setContextMenu({
                      x: e.clientX,
                      y: e.clientY,
                      message: msg,
                    });
                  }}
                  className={`relative max-w-[85%] md:max-w-[75%] rounded-[1.25rem] px-4 py-2.5 shadow-sm flex flex-col group/msg transition-all ${
                    isSelf
                      ? 'bg-sky-600 text-white rounded-br-sm'
                      : isBotMsg
                        ? 'bg-indigo-50/70 text-zinc-900 border border-indigo-100/90 rounded-bl-sm'
                        : 'bg-white text-zinc-800 border border-zinc-100/80 rounded-bl-sm'
                  }`}
                >
                  {/* Sender Name */}
                  {!isSelf && (
                    <div className="flex items-center gap-1 mb-1">
                      <p className={`text-[11px] font-bold ${isBotMsg ? 'text-indigo-700' : 'text-sky-700'}`}>
                        {isBotMsg ? 'Bot Pengingat Halo APU' : msg.sender_name}
                      </p>
                      {isBotMsg && (
                        <Badge className="bg-indigo-100 text-indigo-700 hover:bg-indigo-100 border-indigo-200 text-[8px] font-bold px-1 py-0 h-3.5 shrink-0">
                          BOT
                        </Badge>
                      )}
                    </div>
                  )}

                  {/* Quoted Reply */}
                  {msg.reply_to && (
                    <div
                      className={`px-3 py-2 rounded-xl text-xs mb-2.5 border-l-[3px] ${
                        isSelf
                          ? 'bg-sky-700/50 border-white/80 text-sky-50'
                          : 'bg-zinc-50 border-sky-500 text-zinc-600'
                      }`}
                    >
                      <p className={`font-bold text-[10px] mb-0.5 ${isSelf ? 'text-sky-200' : 'text-sky-700'}`}>
                        {msg.reply_to.sender_name}
                      </p>
                      <p className="truncate opacity-90">{msg.reply_to.body || '[Lampiran]'}</p>
                    </div>
                  )}

                  {/* The room header already names its own ticket, so repeating
                      that card inside every bubble is noise. A ticket attached
                      to a non-ticket room still needs its card. */}
                  {msg.ticket && msg.ticket.id !== conversation.ticket_id && (
                    <TicketReferenceCard ticket={msg.ticket} isAdmin={isAdmin} isSelf={isSelf} />
                  )}

                  {/* Message Body */}
                  {msg.body && (
                    <p className="text-[13px] whitespace-pre-wrap leading-relaxed break-words">
                      {msg.body}
                    </p>
                  )}

                  {/* Attachments */}
                  {msg.attachments && msg.attachments.length > 0 && (
                    <div className="mt-2 space-y-1.5">
                      {msg.attachments.map((att) => {
                        const isImg = att.file_type?.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp)$/i.test(att.file_name);
                        const isPdf = att.file_type === 'application/pdf' || /\.pdf$/i.test(att.file_name);

                        if (isImg) {
                          return (
                            <div key={att.id} className="relative rounded-xl overflow-hidden group/img border border-black/10">
                              <img
                                src={att.file_path}
                                alt={att.file_name}
                                className="max-h-56 w-full object-cover cursor-pointer hover:opacity-95 transition-opacity"
                                onClick={() => setPreviewImage(att.file_path)}
                              />
                            </div>
                          );
                        }

                        const row = (
                          <span
                            className={`flex items-center gap-2.5 p-2 rounded-xl text-xs transition-colors border w-full text-left ${
                              isSelf
                                ? 'bg-sky-700/50 border-white/20 text-white hover:bg-sky-700'
                                : 'bg-zinc-50 border-zinc-200 text-zinc-800 hover:bg-zinc-100'
                            }`}
                          >
                            <FileText className="h-4 w-4 shrink-0 text-sky-400" />
                            <span className="min-w-0 flex-1">
                              <span className="block font-medium truncate text-xs">{att.file_name}</span>
                              <span className="block text-[10px] opacity-75">
                                {(att.file_size / 1024).toFixed(0)} KB
                              </span>
                            </span>
                            {isPdf ? <Eye className="h-3.5 w-3.5 shrink-0 opacity-80" /> : <Download className="h-3.5 w-3.5 shrink-0 opacity-80" />}
                          </span>
                        );

                        if (isPdf) {
                          return (
                            <AttachmentViewer
                              key={att.id}
                              attachment={{ id: att.id, original_name: att.file_name, file_path: att.file_path }}
                              viewRoute={isAdmin ? 'admin.chat.view' : 'chat.view'}
                              downloadRoute={isAdmin ? 'admin.chat.download' : 'chat.download'}
                            >
                              <button type="button" className="w-full" title="Lihat PDF">
                                {row}
                              </button>
                            </AttachmentViewer>
                          );
                        }

                        return (
                          <a
                            key={att.id}
                            href={isAdmin ? route('admin.chat.download', { attachment: att.id }) : route('chat.download', { attachment: att.id })}
                            download
                          >
                            {row}
                          </a>
                        );
                      })}
                    </div>
                  )}

                  {/* Message Metadata (Timestamp & Read Checkmarks) */}
                  <div className="flex items-center justify-end gap-1 mt-1.5 text-[10px] opacity-70">
                    {msg.is_edited && <span className="italic mr-1 text-[9px]">Telah diedit</span>}
                    <span>{formatMessageTime(msg.created_at)}</span>

                    {isSelf && (
                      <span title={isReadByOthers ? 'Dibaca oleh penerima' : 'Terkirim'} className="ml-0.5 inline-flex items-center">
                        {isReadByOthers ? (
                          <CheckCheck className="h-3.5 w-3.5 text-emerald-300 font-bold" />
                        ) : (
                          <Check className="h-3.5 w-3.5 text-white/50" />
                        )}
                      </span>
                    )}
                  </div>

                  {/* Message Action Controls on Hover */}
                  <div
                    className={`absolute top-1/2 -translate-y-1/2 hidden group-hover/msg:flex items-center gap-0.5 bg-white border border-zinc-200 shadow-md rounded-xl p-1 z-20 transition-all ${
                      isSelf ? '-left-28' : '-right-20'
                    }`}
                  >
                    <button
                      onClick={(e) => { e.stopPropagation(); setReplyingTo(msg); }}
                      title="Balas"
                      className="p-1.5 hover:bg-zinc-100 rounded-lg text-zinc-500 hover:text-zinc-900 transition-colors"
                    >
                      <CornerUpLeft className="h-3.5 w-3.5" />
                    </button>
                    {msg.body && (
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          navigator.clipboard.writeText(msg.body || '');
                          toast.success('Teks pesan disalin');
                        }}
                        title="Salin Teks"
                        className="p-1.5 hover:bg-zinc-100 rounded-lg text-zinc-500 hover:text-zinc-900 transition-colors"
                      >
                        <Copy className="h-3.5 w-3.5" />
                      </button>
                    )}
                    {isSelf && !isReadByOthers && msg.body && (
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          setEditingMessage(msg);
                          setInputText(msg.body || '');
                        }}
                        title="Edit Pesan"
                        className="p-1.5 hover:bg-zinc-100 rounded-lg text-zinc-500 hover:text-sky-600 transition-colors"
                      >
                        <Edit2 className="h-3.5 w-3.5" />
                      </button>
                    )}
                    {(isSelf || isAdmin) && (
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          handleDeleteMessage(msg.id);
                        }}
                        title="Hapus Pesan"
                        className="p-1.5 hover:bg-red-50 text-zinc-500 hover:text-red-600 rounded-lg transition-colors"
                      >
                        <Trash2 className="h-3.5 w-3.5 text-red-500" />
                      </button>
                    )}
                  </div>
                </div>
              </motion.div>
            );
          })
        )}
        </AnimatePresence>
        )}
        {typingUser && (
          <motion.div
            initial={{ opacity: 0, y: 10, scale: 0.95 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 10, scale: 0.95 }}
            className="flex items-center gap-2 py-1.5 px-3.5 bg-white border border-zinc-200/80 rounded-2xl w-fit shadow-xs text-xs text-zinc-600 mb-1"
          >
            <span className="font-semibold text-zinc-800">{typingUser}</span>
            <span className="text-zinc-500 text-[11px]">sedang mengetik</span>
            <span className="flex gap-1 items-center ml-0.5">
              <span className="w-1.5 h-1.5 bg-sky-500 rounded-full animate-bounce [animation-delay:-0.3s]" />
              <span className="w-1.5 h-1.5 bg-sky-500 rounded-full animate-bounce [animation-delay:-0.15s]" />
              <span className="w-1.5 h-1.5 bg-sky-500 rounded-full animate-bounce" />
            </span>
          </motion.div>
        )}
        <div ref={messagesEndRef} />
      </div>

      {/* 3. Footer Input Area */}
      <motion.div
        initial={{ y: 20, opacity: 0 }}
        animate={{ y: 0, opacity: 1 }}
        className="p-3 bg-white/80 backdrop-blur-md border-t border-zinc-200/80 shrink-0"
      >
        {isTicketClosed && !editingMessage ? (
          <div className="text-center py-2.5 px-3 text-xs text-zinc-500 bg-zinc-50 rounded-xl border border-zinc-200">
            Tiket ini sudah ditutup ({currentTicketStatusMeta?.label || conversation.ticket?.status}). Tidak dapat mengirim pesan baru.
          </div>
        ) : (
          <>
        {/* Reply Bar */}
        {replyingTo && (
          <div className="flex items-center justify-between bg-sky-50 border-l-4 border-sky-600 px-3 py-1.5 rounded-r-lg mb-2 text-xs">
            <div className="min-w-0">
              <span className="font-bold text-sky-900">Membalas {replyingTo.sender_name}</span>
              <p className="text-zinc-600 truncate">{replyingTo.body || '[Lampiran]'}</p>
            </div>
            <button onClick={() => setReplyingTo(null)} className="text-zinc-400 hover:text-zinc-600">
              <X className="h-4 w-4" />
            </button>
          </div>
        )}

        {/* Edit Bar */}
        {editingMessage && (
          <div className="flex items-center justify-between bg-amber-50 border-l-4 border-amber-500 px-3 py-1.5 rounded-r-lg mb-2 text-xs">
            <div className="min-w-0">
              <span className="font-bold text-amber-900">Mengedit Pesan</span>
              <p className="text-zinc-600 truncate">{editingMessage.body}</p>
            </div>
            <button
              onClick={() => {
                setEditingMessage(null);
                setInputText('');
              }}
              className="text-zinc-400 hover:text-zinc-600"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        )}

        {/* Attached Ticket Chip */}
        {selectedTicket && (
          <div className="flex items-center gap-2 bg-sky-50 border border-sky-200 px-3 py-1.5 rounded-xl mb-2 text-xs text-sky-900">
            <TicketIcon className="h-4 w-4 text-sky-600 shrink-0" />
            <span className="font-medium truncate">
              Manggil Tiket #{selectedTicket.formatted_id} - {selectedTicket.judul}
            </span>
            <button onClick={() => setSelectedTicket(null)} className="ml-auto text-sky-700 hover:text-sky-900">
              <X className="h-4 w-4" />
            </button>
          </div>
        )}

        {/* Selected File Chip */}
        {selectedFile && (
          <div className="flex items-center gap-2 bg-emerald-50 border border-emerald-200 px-3 py-1.5 rounded-xl mb-2 text-xs text-emerald-900">
            <Paperclip className="h-4 w-4 text-emerald-600 shrink-0" />
            <span className="font-medium truncate">{selectedFile.name}</span>
            <span className="text-[10px] text-emerald-700 font-semibold">
              ({(selectedFile.size / 1024).toFixed(0)} KB / Max 3MB)
            </span>
            <button onClick={() => setSelectedFile(null)} className="ml-auto text-emerald-700 hover:text-emerald-900">
              <X className="h-4 w-4" />
            </button>
          </div>
        )}

        {/* Emoji Quick Bar Popup */}
        {showEmojiPicker && (
          <div className="flex flex-wrap gap-1.5 p-2 bg-zinc-50 border border-zinc-200 rounded-xl mb-2">
            {COMMON_EMOJIS.map((emoji) => (
              <button
                key={emoji}
                type="button"
                onClick={() => {
                  setInputText((prev) => prev + emoji);
                }}
                className="h-8 w-8 text-base flex items-center justify-center hover:bg-white rounded-lg transition-colors"
              >
                {emoji}
              </button>
            ))}
          </div>
        )}

        <form onSubmit={handleSendMessage} className="flex items-end gap-2">
          {/* File input hidden */}
          <input
            type="file"
            ref={fileInputRef}
            onChange={handleFileChange}
            className="hidden"
            accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip"
          />

          <div className="flex items-center gap-1 shrink-0">
            <Button
              type="button"
              variant="ghost"
              size="icon"
              onClick={() => fileInputRef.current?.click()}
              title="Lampirkan File (Max 3MB)"
              className="h-9 w-9 text-zinc-500 hover:text-sky-600 rounded-xl"
            >
              <Paperclip className="h-4 w-4" />
            </Button>

            {/* A ticket room already belongs to one ticket: attaching another is noise. */}
            {!conversation.ticket_id && (
              <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={handleOpenTicketModal}
                title="Lampirkan Tiket"
                className="h-9 w-9 text-zinc-500 hover:text-sky-600 rounded-xl"
              >
                <TicketIcon className="h-4 w-4" />
              </Button>
            )}

            <Button
              type="button"
              variant="ghost"
              size="icon"
              onClick={() => setShowEmojiPicker(!showEmojiPicker)}
              title="Emoji"
              className="h-9 w-9 text-zinc-500 hover:text-sky-600 rounded-xl"
            >
              <Smile className="h-4 w-4" />
            </Button>
          </div>

          <textarea
            value={inputText}
            onChange={handleInputChange}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                handleSendMessage();
              }
            }}
            placeholder="Tulis pesan... (Shift+Enter untuk baris baru)"
            rows={1}
            className="flex-1 min-h-[38px] max-h-28 p-2 text-xs rounded-xl border border-zinc-200 bg-zinc-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500 resize-none"
          />

          <Button
            type="submit"
            disabled={isSending || (!inputText.trim() && !selectedTicket && !selectedFile)}
            className="h-9 px-4 bg-sky-600 hover:bg-sky-700 text-white rounded-xl text-xs font-semibold gap-1.5 shrink-0"
          >
            {isSending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <>
                <span>Kirim</span>
                <Send className="h-3.5 w-3.5" />
              </>
            )}
          </Button>
        </form>
          </>
        )}
      </motion.div>

      {/* Ticket Room Switcher Modal */}
      <Dialog open={showTicketSwitcher} onOpenChange={setShowTicketSwitcher}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle className="text-base flex items-center gap-2">
              <TicketIcon className="h-5 w-5 text-sky-600" />
              <span>Pilih Tiket untuk Dibalas</span>
            </DialogTitle>
          </DialogHeader>

          <div className="relative">
            <Search className="h-4 w-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <Input
              autoFocus
              value={ticketRoomSearch}
              onChange={(e) => setTicketRoomSearch(e.target.value)}
              placeholder="Cari layanan, tanggal, judul, nomor tiket, atau pesan..."
              className="h-9 text-xs pl-9"
            />
          </div>

          <div className="max-h-[22rem] overflow-y-auto divide-y divide-zinc-100">
            {(() => {
              const term = ticketRoomSearch.trim().toLowerCase();
              const clean = term.replace(/[#\-\s]/g, '');

              const closed = ['solve', 'selesai', 'reject', 'dibatalkan'];

              const matches = (ticketRooms ?? [])
                .filter((room) => {
                  if (!term) return true;

                  const created = room.created_at
                    ? format(new Date(room.created_at), 'dd/MM/yy HH:mm').toLowerCase()
                    : '';

                  const haystack = [
                    room.chat_label,
                    room.nama_layanan,
                    room.nama_unit,
                    room.judul,
                    room.formatted_id,
                    room.requester,
                    room.handler,
                    room.last_message,
                    ticketStatusLabel(room.status).label,
                    created,
                  ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();

                  const cleanId = room.formatted_id.toLowerCase().replace(/[#\-\s]/g, '');

                  return haystack.includes(term) || (clean.length > 0 && cleanId.includes(clean));
                })
                .sort((a, b) => {
                  const aClosed = closed.includes((a.status || '').toLowerCase()) ? 1 : 0;
                  const bClosed = closed.includes((b.status || '').toLowerCase()) ? 1 : 0;

                  if (aClosed !== bClosed) return aClosed - bClosed;

                  return (
                    new Date(b.last_message_at || b.created_at || 0).getTime() -
                    new Date(a.last_message_at || a.created_at || 0).getTime()
                  );
                });

              if (matches.length === 0) {
                return (
                  <div className="p-6 text-center text-xs text-zinc-500">
                    Tidak ada tiket yang cocok dengan “{ticketRoomSearch.trim()}”.
                  </div>
                );
              }

              return matches.map((room) => {
                const isSelected = room.conversation_id === conversation.id;
                const roomStatus = ticketStatusLabel(room.status);
                const label = room.chat_label || room.nama_layanan || 'Diskusi Tiket';

                const isClosed = closed.includes((room.status || '').toLowerCase());

                const showTitle =
                  room.judul && room.judul.trim() !== '' && room.judul.trim().toLowerCase() !== label.toLowerCase();

                return (
                  <button
                    key={room.conversation_id}
                    type="button"
                    onClick={() => {
                      setShowTicketSwitcher(false);
                      onSelectTicketRoom?.(room.conversation_id);
                    }}
                    aria-current={isSelected ? 'true' : undefined}
                    className={`w-full text-left p-3 transition-colors flex items-start gap-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:ring-inset ${
                      isSelected ? 'bg-sky-50' : 'hover:bg-zinc-50'
                    }`}
                  >
                    <div
                      className={`h-8 w-8 rounded-xl border flex items-center justify-center shrink-0 ${
                        isSelected ? 'bg-sky-600 border-sky-600' : 'bg-white border-zinc-200'
                      }`}
                    >
                      <TicketIcon className={`h-4 w-4 ${isSelected ? 'text-white' : 'text-sky-600'}`} />
                    </div>

                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-1.5 flex-wrap">
                        <span className="text-xs font-bold text-zinc-900 truncate">{label}</span>
                        {room.created_at && (
                          <span className="text-[10px] text-zinc-500 shrink-0">
                            {format(new Date(room.created_at), 'dd MMM yyyy, HH:mm')}
                          </span>
                        )}
                        {isSelected && (
                          <span className="text-[9px] font-bold px-1.5 py-0 rounded-md bg-sky-600 text-white">
                            Sedang dibuka
                          </span>
                        )}
                      </div>

                      <p className="text-[11px] text-zinc-600 truncate mt-0.5">
                        #{room.formatted_id}
                        {room.nama_unit ? ` · ${room.nama_unit}` : ''}
                        {room.requester ? ` · ${room.requester}` : ''}
                        {showTitle ? ` · ${room.judul}` : ''}
                      </p>

                      <p className="text-[11px] text-zinc-500 truncate mt-0.5">
                        {room.handler ? `Ditangani: ${room.handler}` : 'Belum ditangani'}
                        {room.last_message ? ` · ${room.last_message}` : ''}
                      </p>
                    </div>

                    <div className="flex flex-col items-end gap-1 shrink-0">
                      <span className={`text-[9px] font-bold px-1.5 py-0 rounded-md border ${roomStatus.className}`}>
                        {roomStatus.label}
                      </span>
                      {room.unread_count > 0 && (
                        <span className="h-4 min-w-[16px] px-1 rounded-full bg-rose-500 text-white text-[9px] font-bold flex items-center justify-center">
                          {room.unread_count > 99 ? '99+' : room.unread_count}
                        </span>
                      )}
                      {isClosed && (
                        <span className="text-[9px] text-zinc-400">ditutup</span>
                      )}
                    </div>
                  </button>
                );
              });
            })()}
          </div>

          <p className="text-[11px] text-zinc-500">
            Pesan yang Anda kirim masuk ke tiket yang dipilih, bukan ke tiket lain pemohon.
          </p>
        </DialogContent>
      </Dialog>

      {/* Ticket Selection Modal */}
      <Dialog open={showTicketModal} onOpenChange={setShowTicketModal}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="text-base flex items-center gap-2">
              <TicketIcon className="h-5 w-5 text-sky-600" />
              <span>Pilih Tiket untuk Dilampirkan</span>
            </DialogTitle>
          </DialogHeader>

          <Input
            value={ticketSearch}
            onChange={(e) => setTicketSearch(e.target.value)}
            placeholder="Cari nomor tiket, judul, atau status..."
            className="h-9 text-xs"
          />

          <div className="max-h-72 overflow-y-auto divide-y divide-zinc-100">
            {loadingTickets ? (
              <div className="p-6 text-center text-xs text-zinc-400">
                <Loader2 className="h-6 w-6 animate-spin mx-auto mb-2 text-sky-600" />
                Memuat daftar tiket...
              </div>
            ) : filteredTickets.length === 0 ? (
              <div className="p-6 text-center text-xs text-zinc-400">
                {ticketsList.length === 0 ? 'Tidak ada tiket ditemukan' : 'Tidak ada tiket yang cocok'}
              </div>
            ) : (
              filteredTickets.map((ticket) => (
                <div
                  key={ticket.id}
                  onClick={() => {
                    setSelectedTicket(ticket);
                    setShowTicketModal(false);
                  }}
                  className="p-3 hover:bg-sky-50 cursor-pointer transition-colors flex items-center justify-between"
                >
                  <div className="min-w-0 pr-2">
                    <p className="text-xs font-bold text-zinc-900">
                      Tiket #{ticket.formatted_id}
                    </p>
                    <p className="text-xs text-zinc-600 truncate">{ticket.judul}</p>
                  </div>
                  {(() => {
                    const s = ticket.status?.toLowerCase() || '';

                    if (s === 'solve' || s === 'closed' || s === 'selesai') {
                      return <Badge className="bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] shrink-0 font-semibold">solve</Badge>;
                    }

                    if (s === 'open' || s === 'buka') {
                      return <Badge className="bg-sky-50 text-sky-700 border border-sky-200 text-[10px] shrink-0 font-semibold">open</Badge>;
                    }

                    if (s === 'need_revision' || s === 'revisi') {
                      return <Badge className="bg-orange-50 text-orange-700 border border-orange-200 text-[10px] shrink-0 font-semibold">need_revision</Badge>;
                    }

                    if (s === 'pending' || s === 'pending_user') {
                      return <Badge className="bg-amber-50 text-amber-700 border border-amber-200 text-[10px] shrink-0 font-semibold">pending</Badge>;
                    }

                    return <Badge variant="outline" className="text-[10px] shrink-0">{ticket.status}</Badge>;
                  })()}
                </div>
              ))
            )}
          </div>
        </DialogContent>
      </Dialog>

      {/* Image Full Preview Modal */}
      {previewImage && (
        <div
          className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4"
          onClick={() => setPreviewImage(null)}
        >
          <div className="relative max-w-4xl max-h-[90vh] overflow-hidden rounded-2xl">
            <img src={previewImage} alt="Preview" className="max-h-[85vh] object-contain" />
            <button
              onClick={() => setPreviewImage(null)}
              className="absolute top-3 right-3 bg-black/60 text-white p-2 rounded-full hover:bg-black"
            >
              <X className="h-5 w-5" />
            </button>
          </div>
        </div>
      )}

      {/* Right-Click Context Menu Popup */}
      {contextMenu && (
        <div
          style={{
            top: Math.min(contextMenu.y, (typeof window !== 'undefined' ? window.innerHeight : 600) - 180),
            left: Math.min(contextMenu.x, (typeof window !== 'undefined' ? window.innerWidth : 800) - 180),
          }}
          className="fixed z-50 bg-white/95 backdrop-blur-md border border-zinc-200/80 rounded-2xl shadow-xl p-1.5 min-w-[170px] text-xs font-medium text-zinc-700 space-y-0.5"
          onClick={(e) => e.stopPropagation()}
        >
          <button
            onClick={() => {
              setReplyingTo(contextMenu.message);
              setContextMenu(null);
            }}
            className="w-full flex items-center gap-2.5 px-3 py-2 hover:bg-sky-50 hover:text-sky-700 rounded-xl text-left transition-colors"
          >
            <CornerUpLeft className="h-4 w-4 text-sky-600" />
            <span>Balas Pesan</span>
          </button>

          {contextMenu.message.body && (
            <button
              onClick={() => {
                navigator.clipboard.writeText(contextMenu.message.body || '');
                toast.success('Teks pesan disalin');
                setContextMenu(null);
              }}
              className="w-full flex items-center gap-2.5 px-3 py-2 hover:bg-zinc-100 rounded-xl text-left transition-colors"
            >
              <Copy className="h-4 w-4 text-zinc-500" />
              <span>Salin Teks</span>
            </button>
          )}

          {contextMenu.message.sender_id === currentUser.id && contextMenu.message.body && (
            <button
              onClick={() => {
                setEditingMessage(contextMenu.message);
                setInputText(contextMenu.message.body || '');
                setContextMenu(null);
              }}
              className="w-full flex items-center gap-2.5 px-3 py-2 hover:bg-sky-50 hover:text-sky-700 rounded-xl text-left transition-colors"
            >
              <Edit2 className="h-4 w-4 text-sky-600" />
              <span>Edit Pesan</span>
            </button>
          )}

          {(contextMenu.message.sender_id === currentUser.id || isAdmin) && (
            <button
              onClick={() => {
                handleDeleteMessage(contextMenu.message.id);
              }}
              className="w-full flex items-center gap-2.5 px-3 py-2 hover:bg-red-50 text-red-600 rounded-xl text-left transition-colors border-t border-zinc-100 mt-1 pt-1.5"
            >
              <Trash2 className="h-4 w-4 text-red-500" />
              <span className="font-semibold">Hapus Pesan</span>
            </button>
          )}
        </div>
      )}
    </div>
  );
}

export default ChatWindow;
