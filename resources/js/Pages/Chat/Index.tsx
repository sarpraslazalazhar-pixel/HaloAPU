import React, { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import ConversationList, { ConversationItem } from '@/Components/Chat/ConversationList';
import ChatWindow, { ChatMessage } from '@/Components/Chat/ChatWindow';

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

  return (
    <UserLayout title="Pesan &amp; Chat Realtime">
      <Head title="Pesan &amp; Chat Realtime" />

      <div className="h-[calc(100vh-140px)] min-h-[500px] bg-white border border-zinc-200 rounded-2xl overflow-hidden shadow-xs grid grid-cols-1 md:grid-cols-[320px_1fr] lg:grid-cols-[360px_1fr]">
        <ConversationList
          conversations={conversations}
          activeId={activeId}
          onSelect={handleSelectConversation}
          isAdmin={false}
        />

        <ChatWindow
          conversation={currentConv}
          initialMessages={messages}
          currentUser={currentUser}
          isAdmin={false}
        />
      </div>
    </UserLayout>
  );
}
