<?php

namespace App\Http\Controllers\Api;

use App\Events\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Chat/inbox API for the Flutter app.
 *
 * Reads the same `conversations`/`messages` tables as the web chat, so a direct
 * chat started in the browser is visible on mobile and vice versa. A message
 * may reference a ticket (chat about that ticket) or none (direct chat).
 */
class ChatController extends Controller
{
    /** Inbox: every conversation this actor participates in. */
    public function index(Request $request)
    {
        $actor = $request->user();
        $isAdmin = $actor instanceof Admin;

        $query = Conversation::with([
            'user:id,name,username,email,avatar_path,last_seen_at',
            'adminOne:id,name,username,email,avatar_path,last_seen_at',
            'latestMessage.sender',
            'latestMessage.attachments',
        ])->where(function ($base) use ($actor, $isAdmin) {
            $base->where('type', 'public_global');

            if ($isAdmin) {
                $base->orWhere(function ($q) use ($actor) {
                    $q->where('type', 'admin_bot_reminder')->where('admin_one_id', $actor->id);
                })->orWhere(function ($q) use ($actor) {
                    $q->where('type', 'admin_direct')
                        ->where(fn ($q2) => $q2->where('admin_one_id', $actor->id)->orWhere('admin_two_id', $actor->id));
                })->orWhere(function ($q) use ($actor) {
                    $q->where('type', 'user_admin_direct')->where('admin_one_id', $actor->id);
                });
            } else {
                $base->orWhere(function ($q) use ($actor) {
                    $q->where('type', 'user_admin_direct')->where('user_id', $actor->id);
                });
            }
        });

        $conversations = $query->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->limit((int) $request->query('limit', 50))
            ->get();

        $items = $conversations->map(function (Conversation $conv) use ($actor, $isAdmin) {
            $unread = $this->unreadQuery($conv, $actor, $isAdmin)->count();
            $latest = $conv->latestMessage;

            // The other party, from this actor's point of view.
            if ($isAdmin) {
                $peerName = $conv->user
                    ? ($conv->user->name ?? $conv->user->username)
                    : ($conv->type === 'public_global' ? 'Forum Bantuan' : 'Pengguna');
                $peerAvatar = $conv->user?->avatar_path ? '/storage/' . $conv->user->avatar_path : null;
            } else {
                $peerName = $conv->adminOne
                    ? ($conv->adminOne->name ?? $conv->adminOne->username)
                    : ($conv->type === 'public_global' ? 'Forum Bantuan' : 'Admin');
                $peerAvatar = $conv->adminOne?->avatar_path ? '/storage/' . $conv->adminOne->avatar_path : null;
            }

            $ticket = $latest?->ticket_id ? Ticket::find($latest->ticket_id) : null;

            return [
                'id' => $conv->id,
                'type' => $conv->type,
                'peer_name' => $peerName,
                'peer_avatar' => $peerAvatar,
                'ticket' => $ticket ? [
                    'id' => $ticket->id,
                    'formatted_id' => $ticket->formatted_id,
                    'judul' => $ticket->judul,
                    'status' => $ticket->status,
                ] : null,
                'last_message' => $latest
                    ? ($latest->body ?: ($latest->attachments->isNotEmpty() ? '[Lampiran]' : '[Pesan]'))
                    : 'Belum ada pesan',
                'last_message_at' => $conv->last_message_at?->toIso8601String(),
                'last_sender_id' => $latest?->sender_id,
                'last_sender_type' => $latest?->sender_type,
                'unread_count' => $unread,
            ];
        });

        return response()->json([
            'conversations' => $items,
            'total_unread' => $items->sum('unread_count'),
        ]);
    }

    /** Message history for one conversation; marks it read for this actor. */
    public function show(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        $actor = $request->user();
        $isAdmin = $actor instanceof Admin;

        $this->markRead($conversation, $actor, $isAdmin);

        $messages = $conversation->messages()
            ->with(['sender', 'ticket:id,sub_unit_id,user_id,status,priority', 'attachments', 'replyTo.sender', 'reads'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Message $msg) => $this->presentMessage($msg));

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'type' => $conversation->type,
            ],
            'messages' => $messages,
        ]);
    }

    /** Send a message from mobile (direct chat, or chat about a ticket). */
    public function store(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        $actor = $request->user();
        $isAdmin = $actor instanceof Admin;

        $validated = $request->validate([
            'body' => 'nullable|string|max:5000',
            'ticket_id' => 'nullable|exists:tickets,id',
            'reply_to_message_id' => 'nullable|exists:messages,id',
            'attachment' => 'nullable|file|max:3072|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,zip',
        ]);

        if (empty($validated['body']) && ! $request->hasFile('attachment')) {
            return response()->json(['message' => 'Pesan tidak boleh kosong.'], 422);
        }

        $ticketId = $validated['ticket_id'] ?? null;

        if ($ticketId && ! $isAdmin) {
            // A user may only attach their own ticket.
            $owns = Ticket::whereKey($ticketId)->where('user_id', $actor->id)->exists();

            if (! $owns) {
                return response()->json(['message' => 'Tiket tidak ditemukan.'], 403);
            }
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => $isAdmin ? Admin::class : User::class,
            'sender_id' => $actor->id,
            'ticket_id' => $ticketId,
            'reply_to_message_id' => $validated['reply_to_message_id'] ?? null,
            'body' => $validated['body'] ?? null,
        ]);

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $file->store('chat_attachments', 'public');

            ChatAttachment::create([
                'message_id' => $message->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
        }

        MessageRead::firstOrCreate([
            'message_id' => $message->id,
            'user_type' => $isAdmin ? Admin::class : User::class,
            'user_id' => $actor->id,
        ], ['read_at' => now()]);

        $conversation->update(['last_message_at' => now()]);

        // Same event the web chat listens to, so both sides update instantly.
        broadcast(new ChatMessageSent($message))->toOthers();

        // Mirror into the ticket timeline when this message is about a ticket.
        if ($ticketId) {
            \App\Services\TicketChatBridgeService::syncChatMessageToTicket($message->load('attachments'));
        }

        return response()->json([
            'message' => $this->presentMessage($message->load(['sender', 'attachments', 'ticket', 'replyTo'])),
        ], 201);
    }

    /** Read receipts for a conversation. */
    public function read(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        $count = $this->markRead($conversation, $request->user(), $request->user() instanceof Admin);

        return response()->json(['marked' => $count]);
    }

    /** Attachments the mobile app can download (token-authenticated). */
    public function downloadAttachment(Request $request, ChatAttachment $attachment)
    {
        $message = $attachment->message;

        if (! $message || ! $message->conversation) {
            abort(404, 'File tidak ditemukan');
        }

        Gate::authorize('view', $message->conversation);

        if (! Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->file_name);
    }

    protected function unreadQuery(Conversation $conversation, object $actor, bool $isAdmin)
    {
        $type = $isAdmin ? Admin::class : User::class;

        return Message::where('conversation_id', $conversation->id)
            ->where(fn ($q) => $q->where('sender_type', '!=', $type)->orWhere('sender_id', '!=', $actor->id))
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_type', $type)->where('user_id', $actor->id));
    }

    protected function markRead(Conversation $conversation, object $actor, bool $isAdmin): int
    {
        $type = $isAdmin ? Admin::class : User::class;
        $unread = $this->unreadQuery($conversation, $actor, $isAdmin)->get();

        foreach ($unread as $message) {
            MessageRead::firstOrCreate([
                'message_id' => $message->id,
                'user_type' => $type,
                'user_id' => $actor->id,
            ], ['read_at' => now()]);
        }

        return $unread->count();
    }

    protected function presentMessage(Message $msg): array
    {
        return [
            'id' => $msg->id,
            'conversation_id' => $msg->conversation_id,
            'sender_type' => $msg->sender_type,
            'sender_id' => $msg->sender_id,
            'sender_name' => $msg->sender ? ($msg->sender->name ?? $msg->sender->username) : 'Sistem',
            'sender_avatar' => $msg->sender && ! empty($msg->sender->avatar_path)
                ? '/storage/' . $msg->sender->avatar_path
                : null,
            'ticket_id' => $msg->ticket_id,
            'ticket' => $msg->ticket ? [
                'id' => $msg->ticket->id,
                'formatted_id' => $msg->ticket->formatted_id,
                'judul' => $msg->ticket->judul,
                'status' => $msg->ticket->status,
            ] : null,
            'reply_to_message_id' => $msg->reply_to_message_id,
            'reply_to' => $msg->replyTo ? [
                'id' => $msg->replyTo->id,
                'body' => $msg->replyTo->body,
                'sender_name' => $msg->replyTo->sender ? ($msg->replyTo->sender->name ?? $msg->replyTo->sender->username) : 'User',
            ] : null,
            'body' => $msg->body,
            'is_edited' => (bool) $msg->is_edited,
            'attachments' => $msg->attachments->map(fn ($att) => [
                'id' => $att->id,
                'file_name' => $att->file_name,
                'file_path' => '/storage/' . $att->file_path,
                'file_type' => $att->file_type,
                'file_size' => $att->file_size,
            ]),
            'reads' => $msg->reads->map(fn ($r) => [
                'user_type' => $r->user_type,
                'user_id' => $r->user_id,
                'read_at' => $r->read_at?->toIso8601String(),
            ]),
            'created_at' => $msg->created_at?->toIso8601String(),
        ];
    }
}
