import React, { useState, useEffect, useCallback } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ConversationList, { ConversationItem } from '@/Components/Chat/ConversationList';
import ChatWindow, { ChatMessage } from '@/Components/Chat/ChatWindow';
import { useChatSound } from '@/hooks/useChatSound';
import toast from 'react-hot-toast';
import { motion, AnimatePresence } from 'framer-motion';

interface AdminChatPageProps {
  conversations: ConversationItem[];
  activeConversationId: number | null;
  activeConversation: any;
  activeMessages: ChatMessage[];
}

export default function AdminChatIndex({
  conversations,
  activeConversationId,
  activeConversation,
  activeMessages,
}: AdminChatPageProps) {
  const { auth } = usePage<any>().props;
  const admin = auth.admin || auth.user;

  const [convList, setConvList] = useState<ConversationItem[]>(conversations);
  const [activeId, setActiveId] = useState<number | null>(() => {
    if (typeof window !== 'undefined') {
      const params = new URLSearchParams(window.location.search);
      const activeParam = params.get('active');
      if (activeParam) return parseInt(activeParam, 10);
      if (window.innerWidth < 768) return null;
    }
    return activeConversationId;
  });
  const [currentConv, setCurrentConv] = useState<any>(() => {
    if (typeof window !== 'undefined' && window.innerWidth < 768 && !new URLSearchParams(window.location.search).has('active')) {
      return null;
    }
    return activeConversation;
  });
  const [messages, setMessages] = useState<ChatMessage[]>(activeMessages);

  const { playSound } = useChatSound();

  // Sync props to state when Inertia partial reload or page props change
  useEffect(() => {
    setConvList(conversations);
  }, [conversations]);

  useEffect(() => {
    const isMobileInitial = typeof window !== 'undefined' && window.innerWidth < 768;
    const hasActiveParam = typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('active');
    
    if (isMobileInitial && !hasActiveParam) {
      setActiveId(null);
      setCurrentConv(null);
    } else {
      setActiveId(activeConversationId);
      setCurrentConv(activeConversation);
      setMessages(activeMessages);
    }
  }, [activeConversationId, activeConversation, activeMessages]);

  const [onlineUsers, setOnlineUsers] = useState<Array<{ id: number; name: string; type: string }>>([]);
  const [typingMap, setTypingMap] = useState<Record<number, string>>({});

  // Presence channel to track real-time online / offline users
  useEffect(() => {
    if (!(window as any).Echo) return;

    try {
      const presence = (window as any).Echo.join('chat.presence')
        .here((users: any[]) => {
          setOnlineUsers(users || []);
        })
        .joining((user: any) => {
          if (user) {
            setOnlineUsers((prev) => [...prev.filter((u) => !(u.id === user.id && u.type === user.type)), user]);
          }
        })
        .leaving((user: any) => {
          if (user) {
            setOnlineUsers((prev) => prev.filter((u) => !(u.id === user.id && u.type === user.type)));
          }
        });

      return () => {
        (window as any).Echo.leave('chat.presence');
      };
    } catch {
      // ignore
    }
  }, []);

  const updateConversationOnMessage = useCallback((msg: ChatMessage, isFromActiveChat: boolean) => {
    setConvList((prevList) => {
      const snippet = msg.body || (msg.attachments && msg.attachments.length > 0 ? '[Lampiran]' : (msg.ticket ? '[Tiket]' : 'Pesan baru'));
      const timestamp = msg.created_at || new Date().toISOString();
      const isSelf = msg.sender_id === admin.id && msg.sender_type.includes('Admin');

      const index = prevList.findIndex((c) => c.id === msg.conversation_id);

      if (index !== -1) {
        const updated = { ...prevList[index] };
        updated.last_message = snippet;
        updated.last_message_at = timestamp;
        updated.last_message_sender_id = msg.sender_id;
        updated.last_message_sender_type = msg.sender_type;
        updated.is_last_message_read = isFromActiveChat;

        if (!isFromActiveChat && !isSelf) {
          updated.unread_count = (updated.unread_count || 0) + 1;
        }

        const remaining = prevList.filter((_, i) => i !== index);
        return [updated, ...remaining];
      } else {
        const newConvItem: ConversationItem = {
          id: msg.conversation_id,
          title: msg.sender_name || 'Pengguna',
          subtitle: msg.sender_type.includes('Admin') ? 'Admin' : 'User (Privat)',
          last_message: snippet,
          last_message_at: timestamp,
          last_message_sender_id: msg.sender_id,
          last_message_sender_type: msg.sender_type,
          is_last_message_read: isFromActiveChat,
          unread_count: isFromActiveChat || isSelf ? 0 : 1,
        };
        return [newConvItem, ...prevList];
      }
    });
  }, [admin?.id]);

  // Global Echo listener for admin's personal channel and public global chat
  useEffect(() => {
    if (!admin?.id || !(window as any).Echo) return;

    const adminChannelName = `App.Models.Admin.${admin.id}`;
    const publicChannelName = `chat.public_global`;

    const adminChannel = (window as any).Echo.private(adminChannelName);
    const publicChannel = (window as any).Echo.private(publicChannelName);

    const handleIncomingMessage = (e: any) => {
      const newMsg: ChatMessage = e.messageData;
      if (!newMsg) return;

      const isCurrentActive = activeId === newMsg.conversation_id;
      updateConversationOnMessage(newMsg, isCurrentActive);

      const isSelf = newMsg.sender_id === admin.id && newMsg.sender_type.includes('Admin');
      if (!isCurrentActive && !isSelf) {
        playSound();
        if (document.hidden) {
          toast.success(`Pesan Baru dari ${newMsg.sender_name}`, { id: `chat-notif-${newMsg.id}` });
        }
      }
    };

    const handleMessageRead = (e: any) => {
      if (e.conversationId) {
        setConvList((prev) =>
          prev.map((c) => (c.id === e.conversationId ? { ...c, is_last_message_read: true } : c))
        );
      }
    };

    const handleTypingStatus = (e: any) => {
      if (e.userId !== admin.id && e.conversationId) {
        setTypingMap((prev) => {
          const updated = { ...prev };
          if (e.isTyping) {
            updated[e.conversationId] = e.userName;
          } else {
            delete updated[e.conversationId];
          }
          return updated;
        });
      }
    };

    adminChannel.listen('.ChatMessageSent', handleIncomingMessage);
    publicChannel.listen('.ChatMessageSent', handleIncomingMessage);
    adminChannel.listen('.ChatMessageRead', handleMessageRead);
    publicChannel.listen('.ChatMessageRead', handleMessageRead);
    adminChannel.listen('.UserTypingStatus', handleTypingStatus);
    publicChannel.listen('.UserTypingStatus', handleTypingStatus);

    return () => {
      (window as any).Echo.leave(adminChannelName);
      (window as any).Echo.leave(publicChannelName);
    };
  }, [admin?.id, activeId, playSound, updateConversationOnMessage]);

  const [isLoadingMessages, setIsLoadingMessages] = useState(false);

  const handleSelectConversation = async (id: number) => {
    if (id === activeId && currentConv) return;

    setActiveId(id);
    setConvList((prev) =>
      prev.map((c) => (c.id === id ? { ...c, unread_count: 0 } : c))
    );

    window.history.pushState({ activeId: id }, '', `/admin/chat?active=${id}`);

    setIsLoadingMessages(true);
    try {
      const res = await (window as any).axios.get(`/admin/chat/conversations/${id}`);
      setCurrentConv(res.data.conversation);
      setMessages(res.data.messages || []);
    } catch {
      toast.error('Gagal memuat pesan percakapan');
    } finally {
      setIsLoadingMessages(false);
    }
  };

  // Support browser Back/Forward buttons without reload
  useEffect(() => {
    const handlePopState = () => {
      const params = new URLSearchParams(window.location.search);
      const active = params.get('active');
      if (active) {
        const parsedId = parseInt(active, 10);
        if (parsedId && parsedId !== activeId) {
          handleSelectConversation(parsedId);
        }
      } else {
        setActiveId(null);
        setCurrentConv(null);
      }
    };

    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, [activeId]);

  const currentUser = {
    id: admin.id,
    name: admin.name || admin.username,
    type: 'admin' as const,
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
    window.history.pushState({}, '', '/admin/chat');
  };

  return (
    <AdminLayout title="Manajemen Chat & Pesan Realtime" hideBottomNav={isMobile && activeId !== null}>
      <Head title="Pesan & Chat Realtime" />

      <div className="h-[calc(100vh-130px)] md:min-h-[450px] bg-white border border-zinc-200 rounded-2xl overflow-hidden shadow-xs grid grid-cols-1 md:grid-cols-[320px_1fr] lg:grid-cols-[360px_1fr] min-h-0 relative">
        <div className={`md:flex flex-col min-h-0 h-full w-full ${isMobile && activeId ? 'hidden' : 'flex'}`}>
          <ConversationList
            conversations={convList}
            activeId={activeId}
            onSelect={handleSelectConversation}
            isAdmin={true}
            onlineUsers={onlineUsers}
            typingMap={typingMap}
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
                currentUser={currentUser}
                isAdmin={true}
                isLoading={isLoadingMessages}
                onlineUsers={onlineUsers}
                onBack={isMobile ? handleBackToList : undefined}
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
