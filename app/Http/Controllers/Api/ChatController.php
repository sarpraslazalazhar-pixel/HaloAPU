<?php

namespace App\Http\Controllers\Api;

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
use Illuminate\Support\Facades\DB;
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

        // One source of truth for both clients; the mobile payload keeps its
        // own field names (peer_name/peer_avatar) so the Flutter app is unchanged.
        $items = collect(\App\Services\ChatInboxService::items($actor, (int) $request->query('limit', 50)))
            ->map(fn (array $conv) => [
                'id' => $conv['id'],
                'type' => $conv['type'],
                'ticket_id' => $conv['ticket_id'],
                'peer_name' => $conv['type'] === 'ticket'
                    ? $conv['title']
                    : ($conv['title'] ?? ($isAdmin ? 'Pengguna' : 'Admin')),
                'peer_avatar' => $conv['user']['avatar'] ?? null,
                'ticket' => $conv['ticket'],
                'last_message' => $conv['last_message'],
                'last_message_at' => $conv['last_message_at'],
                'last_sender_id' => $conv['last_sender_id'],
                'last_sender_type' => $conv['last_sender_type'],
                'unread_count' => $conv['unread_count'],
            ]);

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
            ->with(['sender', 'ticket:id,sub_unit_id,user_id,status,priority', 'ticket.subUnit:id,nama_layanan', 'attachments', 'replyTo.sender', 'reads'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Message $msg) => $this->presentMessage($msg));

        $ticket = $conversation->ticket;

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'type' => $conversation->type,
                'ticket_id' => $conversation->ticket_id,
                'title' => $ticket ? $ticket->chatLabel() : null,
                'ticket' => $ticket ? [
                    'id' => $ticket->id,
                    'formatted_id' => $ticket->formatted_id,
                    'judul' => $ticket->judul,
                    'chat_label' => $ticket->chatLabel(),
                    'created_at' => $ticket->created_at?->toIso8601String(),
                    'nama_layanan' => $ticket->subUnit?->nama_layanan,
                    'status' => $ticket->status,
                ] : null,
            ],
            'messages' => $messages,
        ]);
    }

    /** Open (creating on first use) the per-ticket room from the mobile app. */
    public function ticketRoom(Request $request, Ticket $ticket)
    {
        $actor = $request->user();

        if ($actor instanceof Admin) {
            abort_unless($ticket->isAccessibleBy($actor), 403, 'Akses ditolak.');
        } else {
            abort_unless($ticket->isOwnedByUser($actor), 403, 'Akses ditolak.');
        }

        $conversation = \App\Services\ChatInboxService::ensureTicketConversation($ticket);

        return $this->show($request, $conversation);
    }

    /** Send a message from mobile (direct chat, or chat about a ticket). */
    public function store(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        if ($conversation->type === 'ticket' && $conversation->ticket) {
            $status = strtolower((string) $conversation->ticket->status);
            if (in_array($status, ['solve', 'selesai', 'reject', 'dibatalkan'], true)) {
                return response()->json(['message' => 'Tiket ini sudah ditutup. Tidak dapat mengirim pesan baru.'], 422);
            }
        }

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

        // A ticket room always stays on its own ticket, whatever the client sends.
        if ($conversation->type === 'ticket') {
            $ticketId = $conversation->ticket_id;
        }

        if ($ticketId && ! $isAdmin) {
            // A user may only attach their own ticket.
            $owns = Ticket::whereKey($ticketId)->where('user_id', $actor->id)->exists();

            if (! $owns) {
                return response()->json(['message' => 'Tiket tidak ditemukan.'], 403);
            }
        }

        $message = DB::transaction(function () use ($conversation, $actor, $isAdmin, $ticketId, $validated, $request) {
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

            // Mirror into the ticket timeline when this message is about a ticket.
            if ($ticketId) {
                \App\Services\TicketChatBridgeService::syncChatMessageToTicket($message->load('attachments'));
            }

            return $message;
        });

        // Same event the web chat listens to, so both sides update instantly.
        \App\Services\TicketChatBridgeService::afterCommit(function () use ($message): void {
            \App\Services\TicketChatBridgeService::emitChatMessage($message);
            \App\Services\TicketChatBridgeService::notifyChatMessageRecipients($message);
        });

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
        return \App\Services\ChatInboxService::unreadQuery($conversation, $actor, $isAdmin);
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
                'chat_label' => $msg->ticket->chatLabel(),
                'created_at' => $msg->ticket->created_at?->toIso8601String(),
                'nama_layanan' => $msg->ticket->subUnit?->nama_layanan,
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
