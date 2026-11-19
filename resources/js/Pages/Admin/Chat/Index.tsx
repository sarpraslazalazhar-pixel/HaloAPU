import { useState, useEffect, useCallback, useRef, useMemo, type ComponentProps } from 'react';
import axios from 'axios';
import { Head, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ConversationList, {
  type ConversationItem,
  type TicketRoomSummary,
} from '@/Components/Chat/ConversationList';
import ChatWindow, { type ChatMessage } from '@/Components/Chat/ChatWindow';
import { useChatSound } from '@/hooks/useChatSound';
import { subscribe, type ChannelHandlers, type ChatEvents, type OnlineUser } from '@/lib/realtime';
import toast from 'react-hot-toast';
import { motion, AnimatePresence } from 'framer-motion';

type ConversationDetail = NonNullable<ComponentProps<typeof ChatWindow>['conversation']>;

interface AdminIdentity {
  id: number;
  name?: string;
  username?: string;
}

interface SharedAuthProps {
  auth: {
    admin?: AdminIdentity | null;
    user?: AdminIdentity | null;
  };
  [key: string]: unknown;
}

interface AdminChatPageProps {
  conversations: ConversationItem[];
  activeConversationId: number | null;
  activeConversation: ConversationDetail | null;
  activeMessages: ChatMessage[];
  linkedTicket?: ChatMessage['ticket'] | null;
}

function normalizeActiveConversation(conv: ConversationDetail | null): ConversationDetail | null {
  if (!conv || !conv.id) return null;

  return conv;
}

export default function AdminChatIndex({
  conversations,
  activeConversationId,
  activeConversation,
  activeMessages,
  linkedTicket = null,
}: AdminChatPageProps) {
  const { auth } = usePage<SharedAuthProps>().props;
  const admin = auth.admin || auth.user || { id: 0, name: 'Admin' };

  const [items, setItems] = useState<ConversationItem[]>(conversations);

  const [activeId, setActiveId] = useState<number | null>(() => {
    const params = new URLSearchParams(window.location.search);
    const activeParam = params.get('active');

    if (activeParam) return parseInt(activeParam, 10);

    if (window.innerWidth < 768 && !params.has('ticket_id') && !params.has('user_id')) {
      return null;
    }

    return activeConversationId;
  });

  const [currentConv, setCurrentConv] = useState<ConversationDetail | null>(() => {
    const params = new URLSearchParams(window.location.search);

    if (window.innerWidth < 768 && !params.has('active') && !params.has('ticket_id') && !params.has('user_id')) {
      return null;
    }

    return normalizeActiveConversation(activeConversation);
  });

  const [messages, setMessages] = useState<ChatMessage[]>(activeMessages);

  const activeIdRef = useRef(activeId);
  activeIdRef.current = activeId;

  const { playSound } = useChatSound();

  useEffect(() => {
    setItems(conversations);
  }, [conversations]);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const isMobileInitial = window.innerWidth < 768;
    const hasDeepLink = params.has('active') || params.has('ticket_id') || params.has('user_id');

    if (isMobileInitial && !hasDeepLink) {
      setActiveId(null);
      setCurrentConv(null);
    } else {
      setActiveId(activeConversationId);
      setCurrentConv(normalizeActiveConversation(activeConversation));
      setMessages(activeMessages);
    }
  }, [activeConversationId, activeConversation, activeMessages]);

  const [onlineUsers, setOnlineUsers] = useState<Array<{ id: number; name: string; type: string }>>([]);
  const [typingByConversation, setTypingByConversation] = useState<Record<number, string>>({});

  // Presence channel for online users & admins
  useEffect(() => {
    const echo = window.Echo;

    if (!echo) return;

    const presence = echo.join('chat.presence');

    presence
      .here((members: OnlineUser[]) => {
        setOnlineUsers(members ?? []);
      })
      .joining((member: OnlineUser) => {
        if (member) {
          setOnlineUsers((prev) => [
            ...prev.filter((u) => !(u.id === member.id && u.type === member.type)),
            member,
          ]);
        }
      })
      .leaving((member: OnlineUser) => {
        if (member) {
          setOnlineUsers((prev) => prev.filter((u) => !(u.id === member.id && u.type === member.type)));
        }
      });

    return () => {
      echo.leave('chat.presence');
    };
  }, []);

  const updateConversationOnMessage = useCallback(
    (msg: ChatMessage, isFromActiveChat: boolean) => {
      const snippet =
        msg.body ||
        (msg.attachments && msg.attachments.length > 0
          ? '[Lampiran]'
          : msg.ticket
            ? '[Tiket]'
            : 'Pesan baru');
      const timestamp = msg.created_at || new Date().toISOString();
      const isSelf = msg.sender_id === admin.id && Boolean(msg.sender_type?.includes('Admin'));

      // Determine group key: if this admin submitted the ticket, group under `self_admin:{id}`
      const isSelfSubmittedTicket =
        msg.ticket?.created_by_admin_id !== undefined &&
        msg.ticket?.created_by_admin_id !== null &&
        Number(msg.ticket.created_by_admin_id) === Number(admin.id);

      const resolvedGroupKey = isSelfSubmittedTicket
        ? `self_admin:${admin.id}`
        : msg.requester_key || null;

      setItems((prev) => {
        const index = prev.findIndex((c) => {
          if (c.conversation_ids && c.conversation_ids.includes(msg.conversation_id)) {
            return true;
          }

          if (c.id === msg.conversation_id) {
            return true;
          }

          if (resolvedGroupKey && c.group_key === resolvedGroupKey) {
            return true;
          }

          return false;
        });

        if (index !== -1) {
          const existing = prev[index];
          const existingRooms = existing.ticket_rooms ? [...existing.ticket_rooms] : [];
          const roomIndex = existingRooms.findIndex((r) => r.conversation_id === msg.conversation_id);

          if (roomIndex !== -1) {
            const oldRoom = existingRooms[roomIndex];
            existingRooms[roomIndex] = {
              ...oldRoom,
              has_message: true,
              last_message: snippet,
              last_message_at: timestamp,
              last_message_sender_id: msg.sender_id,
              last_message_sender_type: msg.sender_type,
              is_last_message_read: isFromActiveChat,
              unread_count: isFromActiveChat || isSelf ? 0 : (oldRoom.unread_count || 0) + 1,
            };
          } else if (msg.ticket) {
            const newRoom: TicketRoomSummary = {
              conversation_id: msg.conversation_id,
              ticket_id: msg.ticket.id,
              formatted_id: msg.ticket.formatted_id,
              judul: msg.ticket.judul ?? 'Diskusi Tiket',
              nama_layanan: msg.ticket.nama_layanan ?? null,
              status: msg.ticket.status ?? 'open',
              priority: msg.ticket.priority ?? null,
              unread_count: isFromActiveChat || isSelf ? 0 : 1,
              has_message: true,
              last_message: snippet,
              last_message_at: timestamp,
              last_message_sender_id: msg.sender_id,
              last_message_sender_type: msg.sender_type,
              is_last_message_read: isFromActiveChat,
            };
            existingRooms.unshift(newRoom);
          }

          existingRooms.sort((a, b) =>
            String(b.last_message_at || '').localeCompare(String(a.last_message_at || ''))
          );

          const convIds = existingRooms.length > 0
            ? existingRooms.map((r) => r.conversation_id)
            : Array.from(new Set([...(existing.conversation_ids || [existing.id]), msg.conversation_id]));

          const totalUnread = existingRooms.length > 0
            ? existingRooms.reduce((sum, r) => sum + (r.unread_count || 0), 0)
            : isFromActiveChat || isSelf
              ? existing.unread_count
              : (existing.unread_count || 0) + 1;

          const count = existingRooms.length || existing.tickets_count || 1;
          const previewLabel =
            msg.ticket?.chat_label ||
            msg.ticket?.nama_layanan ||
            existing.ticket?.chat_label ||
            existing.ticket?.nama_layanan ||
            'Diskusi Tiket';
          const preview = count > 1 ? `[${previewLabel}] ${snippet}` : snippet;

          const firstUnreadRoom = existingRooms.find((r) => r.unread_count > 0);
          const nextPrimaryId = firstUnreadRoom ? firstUnreadRoom.conversation_id : msg.conversation_id;

          const updated: ConversationItem = {
            ...existing,
            id: nextPrimaryId,
            tickets_count: count,
            conversation_ids: convIds,
            ticket_rooms: existingRooms.length > 0 ? existingRooms : existing.ticket_rooms,
            last_message: preview,
            last_message_at: timestamp,
            last_message_sender_id: msg.sender_id,
            last_message_sender_type: msg.sender_type,
            is_last_message_read: isFromActiveChat,
            unread_count: totalUnread,
          };

          return [updated, ...prev.filter((_, i) => i !== index)];
        }

        const peerName = isSelfSubmittedTicket
          ? `${admin.name || admin.username || 'Admin'} (Tiket Saya)`
          : msg.requester_name || msg.sender_name || 'Pemohon';

        const reqType = isSelfSubmittedTicket
          ? 'admin'
          : msg.requester_type || (msg.sender_type?.includes('Admin') ? 'admin' : 'user');

        const formattedId = msg.ticket?.formatted_id || '';
        const roomLabel = msg.ticket?.chat_label || msg.ticket?.nama_layanan || 'Diskusi Tiket';
        const subtitle = isSelfSubmittedTicket
          ? `Tiket Saya · ${roomLabel}`
          : reqType === 'admin'
            ? `Admin Pemohon · ${roomLabel}`
            : `Pemohon · ${roomLabel}`;

        const newRoom: TicketRoomSummary | null = msg.ticket
          ? {
              conversation_id: msg.conversation_id,
              ticket_id: msg.ticket.id,
              formatted_id: msg.ticket.formatted_id,
              judul: msg.ticket.judul ?? 'Diskusi Tiket',
              chat_label: msg.ticket.chat_label ?? msg.ticket.nama_layanan ?? null,
              created_at: msg.ticket.created_at ?? null,
              nama_layanan: msg.ticket.nama_layanan ?? null,
              status: msg.ticket.status ?? 'open',
              priority: msg.ticket.priority ?? null,
              unread_count: isFromActiveChat || isSelf ? 0 : 1,
              has_message: true,
              last_message: snippet,
              last_message_at: timestamp,
              last_message_sender_id: msg.sender_id,
              last_message_sender_type: msg.sender_type,
              is_last_message_read: isFromActiveChat,
            }
          : null;

        const newItem: ConversationItem = {
          id: msg.conversation_id,
          group_key: resolvedGroupKey,
          type: 'ticket',
          requester_type: reqType,
          is_self_requester: isSelfSubmittedTicket,
          user: {
            id: msg.requester_id ?? msg.sender_id,
            name: msg.requester_name || msg.sender_name || 'Pemohon',
            avatar: msg.requester_avatar ?? msg.sender_avatar ?? null,
            entity_type: reqType,
          },
          title: peerName,
          subtitle,
          ticket_id: msg.ticket?.id ?? null,
          ticket: msg.ticket ?? null,
          tickets_count: 1,
          conversation_ids: [msg.conversation_id],
          ticket_rooms: newRoom ? [newRoom] : undefined,
          last_message: snippet,
          last_message_at: timestamp,
          last_message_sender_id: msg.sender_id,
          last_message_sender_type: msg.sender_type,
          is_last_message_read: isFromActiveChat,
          unread_count: isFromActiveChat || isSelf ? 0 : 1,
        };

        return [newItem, ...prev];
      });
    },
    [admin.id, admin.name, admin.username]
  );

  // Global Echo listener for admin's private channel
  useEffect(() => {
    if (!admin.id) return;

    const adminId = admin.id;

    const handleIncomingMessage = ({ messageData: newMsg }: ChatEvents['.ChatMessageSent']) => {
      if (!newMsg) return;

      const isCurrentActive = activeIdRef.current === newMsg.conversation_id;
      updateConversationOnMessage(newMsg, isCurrentActive);

      const isSelf = newMsg.sender_id === adminId && Boolean(newMsg.sender_type?.includes('Admin'));

      if (!isCurrentActive && !isSelf) {
        playSound();

        if (document.hidden) {
          toast.success(`Pesan Baru dari ${newMsg.sender_name}`, { id: `chat-notif-${newMsg.id}` });
        }
      }
    };

    const handleMessageRead = ({ conversationId }: ChatEvents['.ChatMessageRead']) => {
      if (conversationId) {
        setItems((prev) =>
          prev.map((c) => {
            if (c.id === conversationId || c.conversation_ids?.includes(conversationId)) {
              return { ...c, is_last_message_read: true };
            }

            return c;
          })
        );
      }
    };

    const handleTypingStatus = ({
      userId: typistId,
      conversationId,
      isTyping,
      userName,
    }: ChatEvents['.UserTypingStatus']) => {
      if (typistId !== adminId && conversationId) {
        setTypingByConversation((prev) => {
          const updated = { ...prev };

          if (isTyping) {
            updated[conversationId] = userName;
          } else {
            delete updated[conversationId];
          }

          return updated;
        });
      }
    };

    const handlers = {
      '.ChatMessageSent': handleIncomingMessage,
      '.ChatMessageRead': handleMessageRead,
      '.UserTypingStatus': handleTypingStatus,
    } satisfies ChannelHandlers<ChatEvents>;

    return subscribe<ChatEvents>(`App.Models.Admin.${adminId}`, handlers);
  }, [admin.id, playSound, updateConversationOnMessage]);

  const [isLoadingMessages, setIsLoadingMessages] = useState(false);

  const handleSelectConversation = useCallback(
    async (id: number) => {
      if (id === activeIdRef.current && currentConv) return;

      let unreadToClear = 0;

      for (const group of items) {
        if (group.ticket_rooms && group.ticket_rooms.length > 0) {
          const targetRoom = group.ticket_rooms.find((r) => r.conversation_id === id);

          if (targetRoom) {
            unreadToClear = targetRoom.unread_count || 0;
            break;
          }
        } else if (group.id === id) {
          unreadToClear = group.unread_count || 0;
          break;
        }
      }

      setActiveId(id);
      setItems((prev) =>
        prev.map((group) => {
          const belongs =
            group.id === id || Boolean(group.conversation_ids && group.conversation_ids.includes(id));

          if (!belongs) return group;

          if (group.ticket_rooms && group.ticket_rooms.length > 0) {
            const nextRooms = group.ticket_rooms.map((r) =>
              r.conversation_id === id ? { ...r, unread_count: 0 } : r
            );
            const nextTotal = nextRooms.reduce((sum, r) => sum + (r.unread_count || 0), 0);

            return {
              ...group,
              ticket_rooms: nextRooms,
              unread_count: nextTotal,
            };
          }

          return { ...group, unread_count: 0 };
        })
      );

      if (unreadToClear > 0) {
        window.dispatchEvent(new CustomEvent('chat:unread-cleared', { detail: { count: unreadToClear } }));
      }

      window.history.pushState({ activeId: id }, '', `/admin/chat?active=${id}`);

      setIsLoadingMessages(true);

      try {
        const res = await axios.get(`/admin/chat/conversations/${id}`);
        setCurrentConv(normalizeActiveConversation(res.data.conversation));
        setMessages(res.data.messages || []);
      } catch {
        toast.error('Gagal memuat pesan percakapan');
      } finally {
        setIsLoadingMessages(false);
      }
    },
    [items, currentConv]
  );

  // Browser Back/Forward support
  useEffect(() => {
    const handlePopState = () => {
      const params = new URLSearchParams(window.location.search);
      const active = params.get('active');

      if (active) {
        const parsedId = parseInt(active, 10);

        if (parsedId && parsedId !== activeIdRef.current) {
          handleSelectConversation(parsedId);
        }
      } else {
        setActiveId(null);
        setCurrentConv(null);
      }
    };

    window.addEventListener('popstate', handlePopState);

    return () => window.removeEventListener('popstate', handlePopState);
  }, [handleSelectConversation]);

  // Active requester's ticket rooms for the multi-ticket switcher inside ChatWindow
  const activeTicketRooms = useMemo(() => {
    if (!activeId) return undefined;

    const group = items.find(
      (c) => c.id === activeId || Boolean(c.conversation_ids && c.conversation_ids.includes(activeId))
    );

    return group?.ticket_rooms;
  }, [items, activeId]);

  const currentUser = {
    id: admin.id,
    name: admin.name || admin.username || 'Admin',
    type: 'admin' as const,
  };

  const [isMobile, setIsMobile] = useState(false);

  useEffect(() => {
    const checkMobile = () => setIsMobile(window.innerWidth < 768);
    checkMobile();
    window.addEventListener('resize', checkMobile);

    return () => window.removeEventListener('resize', checkMobile);
  }, []);

  const handleBack = () => {
    setActiveId(null);
    setCurrentConv(null);
    window.history.pushState({}, '', '/admin/chat');
  };

  return (
    <AdminLayout title="Manajemen Chat & Pesan Realtime" hideBottomNav={isMobile && activeId !== null}>
      <Head title="Pesan & Chat Realtime" />

      <div className="h-[calc(100vh-130px)] md:min-h-[450px] bg-white border border-zinc-200 rounded-2xl overflow-hidden shadow-xs grid grid-cols-1 md:grid-cols-[320px_1fr] lg:grid-cols-[360px_1fr] min-h-0 relative">
        <div className={`md:flex flex-col min-h-0 h-full w-full ${isMobile && activeId ? 'hidden' : 'flex'}`}>
          <ConversationList
            conversations={items}
            activeId={activeId}
            onSelect={handleSelectConversation}
            isAdmin={true}
            onlineUsers={onlineUsers}
            typingByConversation={typingByConversation}
            currentUserId={admin.id}
            currentUserType="admin"
          />
        </div>

        <AnimatePresence initial={false}>
          {(!isMobile || activeId) && (
            <motion.div
              initial={isMobile ? { x: '100%' } : false}
              animate={{ x: 0 }}
              exit={isMobile ? { x: '100%' } : undefined}
              transition={{ type: 'spring', bounce: 0, duration: 0.4 }}
              className={`absolute inset-0 z-10 bg-white md:static md:flex md:w-full md:h-full flex-col min-h-0 ${!activeId && isMobile ? 'hidden' : 'flex'}`}
            >
              <ChatWindow
                conversation={currentConv}
                initialMessages={messages}
                initialTicket={linkedTicket}
                ticketRooms={activeTicketRooms}
                onSelectTicketRoom={handleSelectConversation}
                currentUser={currentUser}
                isAdmin={true}
                isLoading={isLoadingMessages}
                onlineUsers={onlineUsers}
                onBack={isMobile ? handleBack : undefined}
                onMessageSent={(msg) => updateConversationOnMessage(msg, true)}
                onMessageReceived={(msg) => updateConversationOnMessage(msg, true)}
              />
            </motion.div>
          )}
        </AnimatePresence>
      </div>
    </AdminLayout>
  );
}
