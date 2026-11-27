// Shared Echo channel subscriptions and broadcast payload shapes.
//
// Several components legitimately listen on the same private channel
// (`App.Models.User.{id}`, `chat.public_global`, `App.Models.Admin.{id}`).
// `Echo.leave()` from any one of them unsubscribes everybody, so reference-count
// the channel and only leave when the last consumer unbinds.

import type { ChatMessage } from '@/Components/Chat/ChatWindow';

type Handler = (payload: never) => void;

const refs = new Map<string, number>();

/** Event name to handler map for a channel's payload contract. */
export type ChannelHandlers<Events> = { [K in keyof Events]?: (payload: Events[K]) => void };

/**
 * Subscribe to a private channel with a map of event name to typed handler.
 * `Events` names the payload contract for each event the caller listens to.
 * Returns a cleanup that unbinds only this consumer's handlers.
 */
export function subscribe<Events>(name: string, handlers: ChannelHandlers<Events>): () => void {
  const echo = window.Echo;

  if (!echo) return () => {};

  const channel = echo.private(name);

  // SAFETY: EchoChannel.listen takes `(payload: never) => void`, so every
  // concrete handler type is accepted by design; the map keys are event names.
  const entries = Object.entries(handlers) as Array<[string, Handler]>;

  for (const [event, handler] of entries) {
    channel.listen(event, handler);
  }

  refs.set(name, (refs.get(name) ?? 0) + 1);

  return () => {
    for (const [event, handler] of entries) {
      channel.stopListening(event, handler);
    }

    const remaining = (refs.get(name) ?? 1) - 1;

    if (remaining > 0) {
      refs.set(name, remaining);

      return;
    }

    refs.delete(name);
    echo.leave(name);
  };
}

export interface ChatMessageSentPayload {
  messageData: ChatMessage;
}

export interface ChatMessageEditedPayload {
  messageId: number;
  body: string;
}

export interface ChatMessageDeletedPayload {
  messageId: number;
  conversationId: number;
}

export interface ChatMessageReadPayload {
  conversationId: number;
  userType: string;
  userId: number;
  readCount: number;
  readAt: string;
}

export interface UserTypingStatusPayload {
  conversationId: number;
  userType: string;
  userId: number;
  userName: string;
  isTyping: boolean;
}

export interface BroadcastNotification {
  id?: string;
  type?: string;
  title?: string;
  message?: string;
  url?: string;
  ticket_id?: number;
}

export interface OnlineUser {
  id: number;
  name: string;
  type: string;
}

/** A single entry in a ticket's timeline (reply, status change, assignment). */
export interface TicketLogEntry {
  id: number;
  ticket_id: number;
  admin_id: number | null;
  aksi: string;
  catatan: string | null;
  is_edited?: boolean;
  edited_at?: string | null;
  timestamp: string;
  admin?: { id: number; name?: string | null; username: string } | null;
  attachments?: Array<{
    id: number;
    original_name?: string | null;
    file_name?: string | null;
    file_path: string;
    mime_type?: string | null;
    file_size?: number | null;
  }>;
}

/** Payload of a ticket timeline broadcast (public event properties). */
export interface TicketMessagePostedPayload {
  log: TicketLogEntry;
  ticketId: number;
}

/** Payload of a ticket comment edit broadcast. */
export interface TicketMessageEditedPayload {
  log: TicketLogEntry;
  ticketId: number;
}

/** Events published on `ticket.{id}`. */
export interface TicketEvents {
  '.TicketMessagePosted': TicketMessagePostedPayload;
  '.TicketMessageEdited': TicketMessageEditedPayload;
  '.pusher:subscription_succeeded': void;
  '.pusher:subscription_error': unknown;
}

/** Events published on `chat.conversation.{id}` and the per-user channels. */
export interface ChatEvents {
  '.ChatMessageSent': ChatMessageSentPayload;
  '.ChatMessageEdited': ChatMessageEditedPayload;
  '.ChatMessageDeleted': ChatMessageDeletedPayload;
  '.ChatMessageRead': ChatMessageReadPayload;
  '.UserTypingStatus': UserTypingStatusPayload;
}

/** Laravel's broadcast notification event. */
export interface NotificationEvents {
  '.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated': BroadcastNotification;
}
