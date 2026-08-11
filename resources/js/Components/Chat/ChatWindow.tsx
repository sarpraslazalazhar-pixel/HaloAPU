import React, { useState, useEffect, useRef, useCallback } from 'react';
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
  Image as ImageIcon,
  Edit2,
  Trash2,
  CornerUpLeft,
  Clock,
  User as UserIcon,
  ChevronDown,
  Loader2,
  AlertCircle,
  ExternalLink,
  MessageSquare,
  Copy,
  MoreVertical,
  ChevronLeft,
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
import { useChatSound } from '@/hooks/useChatSound';
import { format, isToday, isYesterday } from 'date-fns';
import { motion, AnimatePresence } from 'framer-motion';

export interface ChatMessage {
  id: number;
  conversation_id: number;
  sender_type: string;
  sender_id: number;
  sender_name: string;
  sender_avatar?: string | null;
  ticket?: {
    id: number;
    formatted_id: string;
    judul?: string;
    status?: string;
    priority?: string;
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
    id: number;
    user?: {
      id: number;
      name: string;
      email?: string;
      avatar?: string | null;
      last_seen_at?: string | null;
    } | null;
    ticket_id?: number | null;
    ticket?: {
      id: number;
      formatted_id: string;
      judul?: string;
      status?: string;
    } | null;
    title: string;
    subtitle?: string;
  } | null;
  initialMessages: ChatMessage[];
  currentUser: {
    id: number;
    name?: string;
    username?: string;
    type: 'user' | 'admin';
  };
  isAdmin?: boolean;
  onBack?: () => void;
}

const COMMON_EMOJIS = ['😊', '👍', '🙏', '🚀', '✅', '⚠️', '📄', '❤️', '👏', '🔥', '💡', '❓', '💬', '🎉'];

export function ChatWindow({
  conversation,
  initialMessages,
  currentUser,
  isAdmin = false,
  onBack,
}: ChatWindowProps) {
  const [messages, setMessages] = useState<ChatMessage[]>(initialMessages);
  const [inputText, setInputText] = useState('');
  const [selectedTicket, setSelectedTicket] = useState<any | null>(null);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [replyingTo, setReplyingTo] = useState<ChatMessage | null>(null);
  const [editingMessage, setEditingMessage] = useState<ChatMessage | null>(null);
  const [isSending, setIsSending] = useState(false);

  const [isTyping, setIsTyping] = useState(false);
  const [typingUser, setTypingUser] = useState<string | null>(null);

  const [showEmojiPicker, setShowEmojiPicker] = useState(false);
  const [showTicketModal, setShowTicketModal] = useState(false);
  const [ticketsList, setTicketsList] = useState<any[]>([]);
  const [loadingTickets, setLoadingTickets] = useState(false);

  const [previewImage, setPreviewImage] = useState<string | null>(null);

  const messagesEndRef = useRef<HTMLDivElement | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const typingTimeoutRef = useRef<any>(null);

  const { playSound } = useChatSound();

  // Scroll to bottom
  const scrollToBottom = useCallback((smooth = true) => {
    messagesEndRef.current?.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto' });
  }, []);

  useEffect(() => {
    setMessages(initialMessages);
    scrollToBottom(false);
  }, [initialMessages, conversation?.id, scrollToBottom]);

  // Handle Realtime Echo Listener
  useEffect(() => {
    if (!conversation?.id || !(window as any).Echo) return;

    const channelName = `chat.conversation.${conversation.id}`;
    const echoChannel = (window as any).Echo.private(channelName);

    // Join Echo Channel
    echoChannel
      .listen('.ChatMessageSent', (e: any) => {
        const newMsg: ChatMessage = e.messageData;

        setMessages((prev) => {
          if (prev.some((m) => m.id === newMsg.id)) return prev;
          return [...prev, newMsg];
        });

        // If not sent by current user, play sound & toast
        const isSelf = newMsg.sender_id === currentUser.id &&
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
              ? route('admin.chat.read', { conversation: conversation.id })
              : route('chat.read', { conversation: conversation.id });
            axios.post(readUrl).catch(() => {});
          }
        }

        scrollToBottom(true);
      })
      .listen('.ChatMessageEdited', (e: any) => {
        setMessages((prev) =>
          prev.map((m) =>
            m.id === e.messageId ? { ...m, body: e.body, is_edited: true } : m
          )
        );
      })
      .listen('.ChatMessageDeleted', (e: any) => {
        setMessages((prev) => prev.filter((m) => m.id !== e.messageId));
      })
      .listen('.ChatMessageRead', (e: any) => {
        setMessages((prev) =>
          prev.map((m) => {
            const alreadyRead = m.reads?.some((r) => {
              const sameUser = r.user_id === e.userId;
              const sameType =
                (r.user_type?.includes('User') && e.userType?.includes('User')) ||
                (r.user_type?.includes('Admin') && e.userType?.includes('Admin'));
              return sameUser && sameType;
            });
            if (alreadyRead) return m;
            return {
              ...m,
              reads: [
                ...(m.reads || []),
                { user_type: e.userType, user_id: e.userId, read_at: e.readAt },
              ],
            };
          })
        );
      })
      .listen('.UserTypingStatus', (e: any) => {
        if (e.userId !== currentUser.id) {
          if (e.isTyping) {
            setTypingUser(e.userName);
          } else {
            setTypingUser(null);
          }
        }
      });

    return () => {
      (window as any).Echo.leave(channelName);
    };
  }, [conversation?.id, currentUser, playSound, scrollToBottom]);

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
    setShowTicketModal(true);
    fetchTickets();
  };

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
    if (selectedTicket) formData.append('ticket_id', selectedTicket.id);
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

  if (!conversation) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center bg-zinc-50 p-8 text-center text-zinc-400">
        <MessageSquare className="h-12 w-12 text-zinc-300 mb-3" />
        <p className="text-sm font-semibold text-zinc-700">Pilih Percakapan</p>
        <p className="text-xs text-zinc-500 max-w-sm mt-1">
          Pilih salah satu ruang chat di sebelah kiri untuk mulai berkirim pesan realtime.
        </p>
      </div>
    );
  }

  return (
    <div className="flex-1 flex flex-col h-full min-h-0 bg-zinc-50/50 relative overflow-hidden">
      {/* 1. Header */}
      <motion.div 
        initial={{ y: -20, opacity: 0 }}
        animate={{ y: 0, opacity: 1 }}
        className="h-14 px-4 bg-white/70 backdrop-blur-md border-b border-zinc-200/60 flex items-center justify-between shrink-0 shadow-sm z-10"
      >
        <div className="flex items-center gap-3 min-w-0">
          {onBack && (
            <button onClick={onBack} className="p-1.5 -ml-2 rounded-xl text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 transition-colors md:hidden">
              <ChevronLeft className="h-5 w-5" />
            </button>
          )}
          <div className="relative">
            <div className="h-9 w-9 rounded-full bg-sky-100 border border-sky-200 overflow-hidden flex items-center justify-center text-sky-700 font-bold text-sm shrink-0">
              {conversation.user?.avatar ? (
                <img src={conversation.user.avatar} alt="Avatar" className="h-full w-full object-cover" />
              ) : conversation.subtitle === 'Grup Publik' || conversation.title === 'Forum Bantuan Halo APU' ? (
                <MessageSquare className="h-5 w-5 text-sky-600" />
              ) : (
                <img
                  src={`https://ui-avatars.com/api/?name=${encodeURIComponent(conversation.title || 'Chat')}&background=0284c7&color=fff`}
                  alt="Avatar"
                  className="h-full w-full object-cover"
                />
              )}
            </div>
            <span className="absolute bottom-0 right-0 h-2.5 w-2.5 rounded-full bg-emerald-500 border-2 border-white" />
          </div>

          <div className="min-w-0">
            <h3 className="text-xs font-bold text-zinc-900 truncate">
              {conversation.title}
            </h3>
            {typingUser ? (
              <p className="text-[11px] text-sky-600 font-semibold animate-pulse">
                {typingUser} sedang mengetik...
              </p>
            ) : (
              <p className="text-[11px] text-zinc-500 truncate">
                {conversation.subtitle || (conversation.user?.last_seen_at ? 'Aktif baru saja' : 'Online')}
              </p>
            )}
          </div>
        </div>

        {conversation.ticket && (
          <a
            href={isAdmin ? `/admin/tiket/${conversation.ticket.id}` : `/tiket/${conversation.ticket.id}`}
            target="_blank"
            rel="noopener noreferrer"
            className="text-xs text-sky-600 hover:text-sky-700 font-semibold flex items-center gap-1 bg-sky-50 hover:bg-sky-100 px-2.5 py-1 rounded-lg border border-sky-200 transition-colors"
          >
            <span>Detail Tiket</span>
            <ExternalLink className="h-3 w-3" />
          </a>
        )}
      </motion.div>

      {/* 2. Messages Body */}
      <div className="flex-1 overflow-y-auto p-4 space-y-3 scroll-smooth min-h-0 custom-scrollbar">
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
                  <div className="h-7 w-7 rounded-full bg-sky-100 border border-sky-200 overflow-hidden flex items-center justify-center shrink-0 text-sky-700 text-xs font-semibold">
                    {msg.sender_avatar || (msg.sender as any)?.avatar_path ? (
                      <img
                        src={msg.sender_avatar || ('/storage/' + (msg.sender as any).avatar_path)}
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
                      : 'bg-white text-zinc-800 border border-zinc-100/80 rounded-bl-sm'
                  }`}
                >
                  {/* Sender Name */}
                  {!isSelf && (
                    <p className="text-[11px] font-bold text-sky-700 mb-1">
                      {msg.sender_name}
                    </p>
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

                  {/* Attached Ticket Reference Card */}
                  {msg.ticket && (
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

                        return (
                          <a
                            key={att.id}
                            href={isAdmin ? route('admin.chat.download', { attachment: att.id }) : route('chat.download', { attachment: att.id })}
                            download
                            className={`flex items-center gap-2.5 p-2 rounded-xl text-xs transition-colors border ${
                              isSelf
                                ? 'bg-sky-700/50 border-white/20 text-white hover:bg-sky-700'
                                : 'bg-zinc-50 border-zinc-200 text-zinc-800 hover:bg-zinc-100'
                            }`}
                          >
                            <FileText className="h-4 w-4 shrink-0 text-sky-400" />
                            <div className="min-w-0 flex-1">
                              <p className="font-medium truncate text-xs">{att.file_name}</p>
                              <p className="text-[10px] opacity-75">
                                {(att.file_size / 1024).toFixed(0)} KB
                              </p>
                            </div>
                            <Download className="h-3.5 w-3.5 shrink-0 opacity-80" />
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
        <div ref={messagesEndRef} />
      </div>

      {/* 3. Footer Input Area */}
      <motion.div 
        initial={{ y: 20, opacity: 0 }}
        animate={{ y: 0, opacity: 1 }}
        className="p-3 bg-white/80 backdrop-blur-md border-t border-zinc-200/80 shrink-0"
      >
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
      </motion.div>

      {/* Ticket Selection Modal */}
      <Dialog open={showTicketModal} onOpenChange={setShowTicketModal}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="text-base flex items-center gap-2">
              <TicketIcon className="h-5 w-5 text-sky-600" />
              <span>Pilih Tiket untuk Dilampirkan</span>
            </DialogTitle>
          </DialogHeader>

          <div className="max-h-80 overflow-y-auto divide-y divide-zinc-100">
            {loadingTickets ? (
              <div className="p-6 text-center text-xs text-zinc-400">
                <Loader2 className="h-6 w-6 animate-spin mx-auto mb-2 text-sky-600" />
                Memuat daftar tiket...
              </div>
            ) : ticketsList.length === 0 ? (
              <div className="p-6 text-center text-xs text-zinc-400">
                Tidak ada tiket ditemukan
              </div>
            ) : (
              ticketsList.map((ticket) => (
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
