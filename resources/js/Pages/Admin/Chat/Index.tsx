import React, { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ConversationList, { ConversationItem } from '@/Components/Chat/ConversationList';
import ChatWindow, { ChatMessage } from '@/Components/Chat/ChatWindow';

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

  const [activeId, setActiveId] = useState<number | null>(activeConversationId);
  const [currentConv, setCurrentConv] = useState<any>(activeConversation);
  const [messages, setMessages] = useState<ChatMessage[]>(activeMessages);

  const handleSelectConversation = (id: number) => {
    setActiveId(id);
    window.location.href = `/admin/chat?active=${id}`;
  };

  const currentUser = {
    id: admin.id,
    name: admin.name || admin.username,
    type: 'admin' as const,
  };

  return (
    <AdminLayout title="Manajemen Chat & Pesan Realtime">
      <Head title="Pesan & Chat Realtime" />

      <div className="h-[calc(100vh-140px)] min-h-[500px] bg-white border border-zinc-200 rounded-2xl overflow-hidden shadow-xs grid grid-cols-1 md:grid-cols-[320px_1fr] lg:grid-cols-[360px_1fr]">
        <ConversationList
          conversations={conversations}
          activeId={activeId}
          onSelect={handleSelectConversation}
          isAdmin={true}
        />

        <ChatWindow
          conversation={currentConv}
          initialMessages={messages}
          currentUser={currentUser}
          isAdmin={true}
        />
      </div>
    </AdminLayout>
  );
}
