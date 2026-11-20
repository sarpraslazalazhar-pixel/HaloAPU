import { useState, useEffect, useCallback, useRef, type ComponentProps } from 'react';
import axios from 'axios';
import { Head } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import ConversationList, { type ConversationItem } from '@/Components/Chat/ConversationList';
import ChatWindow, { type ChatMessage } from '@/Components/Chat/ChatWindow';
import { useChatSound } from '@/hooks/useChatSound';
import { subscribe, type ChannelHandlers, type ChatEvents, type OnlineUser } from '@/lib/realtime';
import toast from 'react-hot-toast';
import { motion, AnimatePresence } from 'framer-motion';

type ConversationDetail = NonNullable<ComponentProps<typeof ChatWindow>['conversation']>;

interface UserChatPageProps {
  conversations: ConversationItem[];
  activeConversationId: number | null;
  activeConversation: ConversationDetail | null;
  activeMessages: ChatMessage[];
  currentUser: { id: number; name: string };
}

export default function UserChatIndex({
  conversations,
  activeConversationId,
  activeConversation,
  activeMessages,
  currentUser,
}: UserChatPageProps) {
  const userId = currentUser.id;

  const [items, setItems] = useState<ConversationItem[]>(conversations);
  const [activeId, setActiveId] = useState<number | null>(activeConversationId);
  const [currentConv, setCurrentConv] = useState<ConversationDetail | null>(activeConversation);
  const [messages, setMessages] = useState<ChatMessage[]>(activeMessages);
  const [isLoading, setIsLoading] = useState(false);
  const [isMobile, setIsMobile] = useState(false);
  const [onlineUsers, setOnlineUsers] = useState<Array<{ id: number; name: string; type: string }>>([]);
  const [typingByConversation, setTypingByConversation] = useState<Record<number, string>>({});

  const activeIdRef = useRef(activeId);
  activeIdRef.current = activeId;

  const { playSound } = useChatSound();

  useEffect(() => {
    setItems(conversations);
  }, [conversations]);

  useEffect(() => {
    const check = () => setIsMobile(window.innerWidth < 768);
    check();
    window.addEventListener('resize', check);

    return () => window.removeEventListener('resize', check);
  }, []);

  // Presence channel for online operator indicator
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

  const syncConversationFromMessage = useCallback(
    (msg: ChatMessage, isFromActiveChat: boolean) => {
      const isSelf = msg.sender_id === userId && Boolean(msg.sender_type?.includes('User'));
      const snippet = msg.body || (msg.attachments?.length ? '[Lampiran]' : '[Pesan]');
      const timestamp = msg.created_at || new Date().toISOString();

      setItems((prev) => {
        const index = prev.findIndex((c) => c.id === msg.conversation_id);

        if (index === -1) {
          const newItem: ConversationItem = {
            id: msg.conversation_id,
            type: 'ticket',
            title: msg.ticket ? (msg.ticket.chat_label || msg.ticket.nama_layanan || 'Diskusi Tiket') : 'Diskusi Tiket',
            subtitle: msg.ticket?.judul || msg.ticket?.nama_layanan || 'Diskusi Tiket',
            ticket_id: msg.ticket?.id ?? null,
            ticket: msg.ticket ?? null,
            last_message: snippet,
            last_message_at: timestamp,
            last_message_sender_id: msg.sender_id,
            last_message_sender_type: msg.sender_type,
            is_last_message_read: isFromActiveChat,
            unread_count: isFromActiveChat || isSelf ? 0 : 1,
          };

          return [newItem, ...prev];
        }

        const updated: ConversationItem = {
          ...prev[index],
          last_message: snippet,
          last_message_at: timestamp,
          last_message_sender_id: msg.sender_id,
          last_message_sender_type: msg.sender_type,
          is_last_message_read: isFromActiveChat,
          unread_count: isFromActiveChat || isSelf ? 0 : (prev[index].unread_count || 0) + 1,
        };

        return [updated, ...prev.filter((_, i) => i !== index)];
      });
    },
    [userId]
  );

  const handleSelectConversation = useCallback(
    async (id: number) => {
      if (id === activeIdRef.current) return;

      const unreadToClear = items.find((c) => c.id === id)?.unread_count || 0;

      setActiveId(id);
      setItems((prev) => prev.map((c) => (c.id === id ? { ...c, unread_count: 0 } : c)));

      if (unreadToClear > 0) {
        window.dispatchEvent(new CustomEvent('chat:unread-cleared', { detail: { count: unreadToClear } }));
      }

      window.history.pushState({ activeId: id }, '', `/chat?active=${id}`);

      setIsLoading(true);

      try {
        const res = await axios.get(`/chat/conversations/${id}`);
        setCurrentConv(res.data.conversation);
        setMessages(res.data.messages || []);
      } catch {
        toast.error('Gagal memuat pesan percakapan');
      } finally {
        setIsLoading(false);
      }
    },
    [items]
  );

  // Live inbox updates across all user's ticket rooms
  useEffect(() => {
    if (!userId) return;

    const handlers = {
      '.ChatMessageSent': ({ messageData: msg }) => {
        if (!msg) return;

        const isSelf = msg.sender_id === userId && Boolean(msg.sender_type?.includes('User'));
        const isActive = activeIdRef.current === msg.conversation_id;

        syncConversationFromMessage(msg, isActive);

        if (!isActive && !isSelf) {
          playSound();

          if (document.hidden) {
            toast.success(`Pesan Baru dari ${msg.sender_name}`, { id: `chat-notif-${msg.id}` });
          }
        }
      },
      '.ChatMessageRead': ({ conversationId }) => {
        if (conversationId) {
          setItems((prev) =>
            prev.map((c) => (c.id === conversationId ? { ...c, is_last_message_read: true } : c))
          );
        }
      },
      '.UserTypingStatus': ({ userId: typistId, conversationId, isTyping, userName }) => {
        if (typistId !== userId && conversationId) {
          setTypingByConversation((prev) => {
            const next = { ...prev };

            if (isTyping) {
              next[conversationId] = userName;
            } else {
              delete next[conversationId];
            }

            return next;
          });
        }
      },
    } satisfies ChannelHandlers<ChatEvents>;

    return subscribe<ChatEvents>(`App.Models.User.${userId}`, handlers);
  }, [userId, playSound, syncConversationFromMessage]);

  const handleBack = useCallback(() => {
    setActiveId(null);
    setCurrentConv(null);
    setMessages([]);
    window.history.pushState({}, '', '/chat');
  }, []);

  return (
    <UserLayout title="Chat" hideBottomNav={isMobile && activeId !== null}>
      <Head title="Pesan Tiket" />

      <div className="h-[calc(100vh-130px)] bg-white border border-zinc-200 rounded-2xl overflow-hidden shadow-xs grid grid-cols-1 md:grid-cols-[320px_1fr] lg:grid-cols-[360px_1fr] min-h-0 relative">
        <div className={`flex-col min-h-0 h-full w-full md:flex ${isMobile && activeId ? 'hidden' : 'flex'}`}>
          <ConversationList
            conversations={items}
            activeId={activeId}
            onSelect={handleSelectConversation}
            onlineUsers={onlineUsers}
            typingByConversation={typingByConversation}
            currentUserId={userId}
            currentUserType="user"
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
                currentUser={{ id: userId, name: currentUser.name, type: 'user' }}
                isAdmin={false}
                isLoading={isLoading}
                onlineUsers={onlineUsers}
                onBack={isMobile ? handleBack : undefined}
                onMessageSent={(msg) => syncConversationFromMessage(msg, true)}
                onMessageReceived={(msg) => syncConversationFromMessage(msg, true)}
              />
            </motion.div>
          )}
        </AnimatePresence>
      </div>
    </UserLayout>
  );
}
