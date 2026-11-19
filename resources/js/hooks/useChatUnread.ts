import { useState, useEffect, useRef } from 'react';
import { router, usePage } from '@inertiajs/react';
import { useChatSound } from './useChatSound';
import { subscribe, type ChannelHandlers, type ChatEvents } from '@/lib/realtime';

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
    if (props.unread_chat_count !== undefined && Number.isFinite(props.unread_chat_count)) {
      setUnreadCount(Number(props.unread_chat_count));
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
    if (!user?.id) return;

    const handleIncomingMessage = ({ messageData: msg }: ChatEvents['.ChatMessageSent']) => {
      if (!msg) return;

      const currentUser = userRef.current;

      const isSelf =
        Number(msg.sender_id) === Number(currentUser?.id) &&
        ((isAdminRef.current && msg.sender_type?.includes('Admin')) ||
          (!isAdminRef.current && msg.sender_type?.includes('User')));

      if (isSelf) return;

      // Check if user is currently looking at this active chat page
      const currentPath = urlRef.current || '';

      const isChatPage = isAdminRef.current
        ? currentPath.startsWith('/admin/chat')
        : currentPath.startsWith('/chat');

      const params = new URLSearchParams(window.location.search);
      const activeParam = params.get('active');
      const ticketParam = params.get('ticket') || params.get('ticket_id');

      const matchesActiveConv = activeParam !== null && activeParam === String(msg.conversation_id);
      const matchesTicketParam =
        ticketParam !== null && msg.ticket?.id !== undefined && ticketParam === String(msg.ticket.id);

      const isViewingThisChat =
        isChatPage && (matchesActiveConv || matchesTicketParam) && !document.hidden;

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
                : msg.ticket?.id
                  ? `/chat?ticket=${msg.ticket.id}`
                  : '/chat';

              router.visit(targetUrl);
            };
          } catch (err) {
            console.error('Failed to trigger notification:', err);
          }
        }
      }
    };

    const handleMessageRead = ({ userId, readCount }: ChatEvents['.ChatMessageRead']) => {
      if (Number(userId) === Number(userRef.current?.id)) {
        const decrement = readCount > 0 ? readCount : 1;
        setUnreadCount((prev) => Math.max(0, prev - decrement));
      }
    };

    const handlers = {
      '.ChatMessageSent': handleIncomingMessage,
      '.ChatMessageRead': handleMessageRead,
    } satisfies ChannelHandlers<ChatEvents>;

    const channelName = isAdmin
      ? `App.Models.Admin.${user.id}`
      : `App.Models.User.${user.id}`;

    const leavePrivate = subscribe<ChatEvents>(channelName, handlers);

    return () => {
      leavePrivate();
    };
  }, [user?.id, isAdmin, playSound, faviconUrl]);

  // Listen to local custom event when chat messages are marked as read within Chat page
  useEffect(() => {
    const handleUnreadCleared = (e: Event) => {
      if (e instanceof CustomEvent) {
        const count = Number(e.detail?.count);
        if (Number.isFinite(count) && count > 0) {
          setUnreadCount((prev) => Math.max(0, prev - count));
          return;
        }
      }

      router.reload({ only: ['unread_chat_count'] });
    };

    window.addEventListener('chat:unread-cleared', handleUnreadCleared);

    return () => window.removeEventListener('chat:unread-cleared', handleUnreadCleared);
  }, []);

  return { unreadCount, setUnreadCount };
}
