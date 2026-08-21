import { useState, useEffect, useCallback, useRef } from 'react';
import { router, usePage } from '@inertiajs/react';
import { useChatSound } from './useChatSound';

interface UseChatUnreadOptions {
  user: any;
  isAdmin: boolean;
  pageTitle?: string;
  systemName?: string;
  faviconUrl?: string;
}

export function useChatUnread({
  user,
  isAdmin,
  pageTitle,
  systemName = 'Halo APU',
  faviconUrl,
}: UseChatUnreadOptions) {
  const { props, url } = usePage<any>();
  const initialUnreadCount = props.unread_chat_count ?? 0;

  const [unreadCount, setUnreadCount] = useState<number>(initialUnreadCount);
  const { playSound } = useChatSound();

  const userRef = useRef(user);
  userRef.current = user;

  const isAdminRef = useRef(isAdmin);
  isAdminRef.current = isAdmin;

  const urlRef = useRef(url);
  urlRef.current = url;

  // Sync with Inertia props update (e.g. after page visit)
  useEffect(() => {
    if (typeof props.unread_chat_count === 'number') {
      setUnreadCount(props.unread_chat_count);
    }
  }, [props.unread_chat_count]);

  // Update browser document.title dynamically
  useEffect(() => {
    const baseTitle = pageTitle ? `${pageTitle} - ${systemName}` : systemName;
    if (unreadCount > 0) {
      document.title = `(${unreadCount}) ${baseTitle}`;
    } else {
      document.title = baseTitle;
    }
  }, [unreadCount, pageTitle, systemName]);

  // Global Echo Realtime Listeners across ALL pages (Dashboard, Tickets, Chat, etc.)
  useEffect(() => {
    if (!user?.id || !(window as any).Echo) return;

    const privateChannelName = isAdmin
      ? `App.Models.Admin.${user.id}`
      : `App.Models.User.${user.id}`;
    const publicChannelName = `chat.public_global`;

    const privateChannel = (window as any).Echo.private(privateChannelName);
    const publicChannel = (window as any).Echo.private(publicChannelName);

    const handleIncomingMessage = (e: any) => {
      const msg = e.messageData;
      if (!msg) return;

      const currentUser = userRef.current;
      const isSelf =
        msg.sender_id === currentUser?.id &&
        ((isAdminRef.current && msg.sender_type?.includes('Admin')) ||
          (!isAdminRef.current && msg.sender_type?.includes('User')));

      if (isSelf) return;

      // Check if user is currently looking at this active chat page
      const currentPath = urlRef.current || '';
      const isChatPage = isAdminRef.current
        ? currentPath.startsWith('/admin/chat')
        : currentPath.startsWith('/chat');
      const params = new URLSearchParams(window.location.search);
      const currentActiveId = params.get('active');
      const isViewingThisChat =
        isChatPage &&
        currentActiveId === String(msg.conversation_id) &&
        !document.hidden;

      if (!isViewingThisChat) {
        setUnreadCount((prev) => prev + 1);

        // Play chat sound across any page
        if (currentUser?.notify_sound !== false) {
          playSound();
        }

        // Native Desktop Browser Notification
        if (
          currentUser?.notify_browser !== false &&
          'Notification' in window &&
          Notification.permission === 'granted'
        ) {
          const senderName = msg.sender_name || 'Bot Pengingat Halo APU';
          const snippet =
            msg.body ||
            (msg.ticket
              ? `[Tiket #${msg.ticket.formatted_id}] ${msg.ticket.judul || 'Detail Tiket'}`
              : msg.attachments?.length
              ? '[Lampiran file]'
              : 'Pesan Baru');

          try {
            const notif = new Notification(senderName, {
              body: snippet,
              icon: faviconUrl || '/favicon.ico',
              badge: faviconUrl || '/favicon.ico',
              tag: `chat-msg-${msg.id}`,
            });

            notif.onclick = () => {
              window.focus();
              const targetUrl = isAdminRef.current
                ? `/admin/chat?active=${msg.conversation_id}`
                : `/chat?active=${msg.conversation_id}`;
              router.visit(targetUrl);
            };
          } catch (err) {
            console.error('Failed to trigger notification:', err);
          }
        }
      }
    };

    const handleMessageRead = (e: any) => {
      if (e.userId === user.id) {
        setUnreadCount((prev) => Math.max(0, prev - 1));
      }
    };

    privateChannel.listen('.ChatMessageSent', handleIncomingMessage);
    privateChannel.listen('.ChatMessageRead', handleMessageRead);

    publicChannel.listen('.ChatMessageSent', handleIncomingMessage);
    publicChannel.listen('.ChatMessageRead', handleMessageRead);

    return () => {
      (window as any).Echo.leave(privateChannelName);
      (window as any).Echo.leave(publicChannelName);
    };
  }, [user?.id, isAdmin, playSound, faviconUrl]);

  return { unreadCount, setUnreadCount };
}
