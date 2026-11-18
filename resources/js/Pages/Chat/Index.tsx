import { useState, useEffect, useCallback, useRef, type ComponentProps } from 'react';
import axios from 'axios';
import { Head } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import ConversationList, { ConversationItem } from '@/Components/Chat/ConversationList';
import ChatWindow, { ChatMessage } from '@/Components/Chat/ChatWindow';
import { useChatSound } from '@/hooks/useChatSound';
import { subscribe, type ChatEvents } from '@/lib/realtime';
import toast from 'react-hot-toast';
import { motion, AnimatePresence } from 'framer-motion';

// The room shape the window already declares; restating it here would drift.
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

  const [convList, setConvList] = useState<ConversationItem[]>(conversations);
  const [activeId, setActiveId] = useState<number | null>(activeConversationId);
  const [currentConv, setCurrentConv] = useState<ConversationDetail | null>(activeConversation);
  const [messages, setMessages] = useState<ChatMessage[]>(activeMessages);
  const [isLoading, setIsLoading] = useState(false);
  const [isMobile, setIsMobile] = useState(false);

  const activeIdRef = useRef(activeId);
  activeIdRef.current = activeId;

  const { playSound } = useChatSound();

  useEffect(() => {
    setConvList(conversations);
  }, [conversations]);

  useEffect(() => {
    const check = () => setIsMobile(window.innerWidth < 768);
    check();
    window.addEventListener('resize', check);

    return () => window.removeEventListener('resize', check);
  }, []);

  const handleSelectConversation = useCallback(async (id: number) => {
    if (id === activeIdRef.current) return;

    const unreadToClear = convList.find((c) => c.id === id)?.unread_count || 0;

    setActiveId(id);
    setConvList((prev) => prev.map((c) => (c.id === id ? { ...c, unread_count: 0 } : c)));

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
  }, [convList]);

  // Live inbox: any room can raise its badge, no reload needed.
  useEffect(() => {
    if (!userId) return;

    return subscribe<ChatEvents>(`App.Models.User.${userId}`, {
      '.ChatMessageSent': ({ messageData: msg }) => {
        if (!msg) return;

        const isSelf = msg.sender_id === userId && msg.sender_type?.includes('User');
        const isActive = activeIdRef.current === msg.conversation_id;

        setConvList((prev) => {
          const snippet = msg.body || (msg.attachments?.length ? '[Lampiran]' : '[Pesan]');
          const index = prev.findIndex((c) => c.id === msg.conversation_id);

          if (index === -1) {
            return [
              {
                id: msg.conversation_id,
                type: 'ticket',
                title: msg.ticket ? `Tiket #${msg.ticket.formatted_id}` : 'Percakapan',
                subtitle: msg.ticket?.judul ?? '',
                last_message: snippet,
                last_message_at: msg.created_at,
                last_message_sender_id: msg.sender_id,
                last_message_sender_type: msg.sender_type,
                is_last_message_read: isActive,
                unread_count: isActive || isSelf ? 0 : 1,
              },
              ...prev,
            ];
          }

          const updated = {
            ...prev[index],
            last_message: snippet,
            last_message_at: msg.created_at,
            last_message_sender_id: msg.sender_id,
            last_message_sender_type: msg.sender_type,
            is_last_message_read: isActive,
            unread_count: isActive || isSelf ? 0 : (prev[index].unread_count || 0) + 1,
          };

          return [updated, ...prev.filter((_, i) => i !== index)];
        });

        if (!isActive && !isSelf) {
          playSound();

          if (document.hidden) {
            toast.success(`Pesan Baru dari ${msg.sender_name}`, { id: `chat-notif-${msg.id}` });
          }
        }
      },
    });
  }, [userId, playSound]);

  const handleBack = useCallback(() => {
    setActiveId(null);
    setCurrentConv(null);
    setMessages([]);
    window.history.pushState({}, '', '/chat');
  }, []);

  return (
    <UserLayout title="Chat" hideBottomNav={isMobile && activeId !== null}>
      <Head title="Chat" />

      <div className="h-[calc(100vh-130px)] bg-white border border-zinc-200 rounded-2xl overflow-hidden shadow-xs grid grid-cols-1 md:grid-cols-[320px_1fr] lg:grid-cols-[360px_1fr] min-h-0 relative">
        <div className={`flex-col min-h-0 h-full w-full md:flex ${isMobile && activeId ? 'hidden' : 'flex'}`}>
          <ConversationList
            conversations={convList}
            activeId={activeId}
            onSelect={handleSelectConversation}
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
                onBack={isMobile ? handleBack : undefined}
              />
            </motion.div>
          )}
        </AnimatePresence>
      </div>
    </UserLayout>
  );
}
