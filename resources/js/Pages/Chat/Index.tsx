import React, { useState, useEffect } from 'react';
import { Head, usePage } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import ConversationList, { ConversationItem } from '@/Components/Chat/ConversationList';
import ChatWindow, { ChatMessage } from '@/Components/Chat/ChatWindow';
import { motion, AnimatePresence } from 'framer-motion';

interface UserChatPageProps {
  conversations: ConversationItem[];
  activeConversationId: number | null;
  activeConversation: any;
  activeMessages: ChatMessage[];
}

export default function UserChatIndex({
  conversations,
  activeConversationId,
  activeConversation,
  activeMessages,
}: UserChatPageProps) {
  const { auth } = usePage<any>().props;
  const user = auth.user;

  const [activeId, setActiveId] = useState<number | null>(activeConversationId);
  const [currentConv, setCurrentConv] = useState<any>(activeConversation);
  const [messages, setMessages] = useState<ChatMessage[]>(activeMessages);

  const handleSelectConversation = (id: number) => {
    setActiveId(id);
    window.location.href = `/chat?active=${id}`;
  };

  const currentUser = {
    id: user.id,
    name: user.name || user.username,
    type: 'user' as const,
  };

  const [isMobile, setIsMobile] = useState(false);
  useEffect(() => {
    const checkMobile = () => setIsMobile(window.innerWidth < 768);
    checkMobile();
    window.addEventListener('resize', checkMobile);
    return () => window.removeEventListener('resize', checkMobile);
  }, []);

  const handleBackToList = () => {
    setActiveId(null);
    setCurrentConv(null);
    window.history.pushState({}, '', '/chat');
  };

  return (
    <UserLayout title="Pesan & Chat Realtime" hideBottomNav={isMobile && activeId !== null}>
      <Head title="Pesan & Chat Realtime" />

      <div className="h-[calc(100vh-130px)] md:min-h-[450px] bg-white border border-zinc-200 rounded-2xl overflow-hidden shadow-xs grid grid-cols-1 md:grid-cols-[320px_1fr] lg:grid-cols-[360px_1fr] min-h-0 relative">
        <div className={`md:flex flex-col min-h-0 h-full w-full ${isMobile && activeId ? 'hidden' : 'flex'}`}>
          <ConversationList
            conversations={conversations}
            activeId={activeId}
            onSelect={handleSelectConversation}
            isAdmin={false}
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
                currentUser={currentUser}
                isAdmin={false}
                onBack={isMobile ? handleBackToList : undefined}
              />
            </motion.div>
          )}
        </AnimatePresence>
      </div>
    </UserLayout>
  );
}
