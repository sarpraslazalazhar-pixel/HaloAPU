<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageDeleted;
use App\Events\ChatMessageEdited;
use App\Events\ChatMessageRead;
use App\Events\ChatMessageSent;
use App\Events\UserTypingStatus;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ChatController extends Controller
{
    /**
     * Same inbox as the mobile app: forum, direct chat, and one room per ticket.
     * `?ticket=N` (from a ticket page) or `?active=N` preselects a room.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login');
        }

        $user->update(['last_seen_at' => now()]);

        $activeConversationId = $request->query('active');

        if (!$activeConversationId && $request->filled('ticket')) {
            $ticketId = (int) $request->query('ticket');
            $ticket = $user->tickets()->find($ticketId);

            if (!$ticket) {
                abort(403, 'Kamu tidak memiliki akses ke tiket ini.');
            }

            // Room may be brand new; resolve it before listing the inbox.
            $activeConversationId = $this->conversationForTicket($ticket)->id;
        }

        $activeConversation = null;
        $activeMessages = [];

        if ($activeConversationId) {
            $conversation = Conversation::find($activeConversationId);

            if ($conversation && Gate::allows('view', $conversation)) {
                $this->markConversationRead($conversation, $user);

                $activeConversation = \App\Services\ChatInboxService::present($conversation, $user, false);
                $activeMessages = $this->serializeMessages(
                    Message::where('conversation_id', $conversation->id)
                        ->with([
                            'sender',
                            'ticket:id,sub_unit_id,user_id,status,priority',
                            'ticket.subUnit:id,nama_layanan',
                            'attachments',
                            'replyTo.sender',
                            'replyTo.attachments',
                            'reads',
                        ])
                        ->orderBy('created_at')
                        ->get()
                );
            }
        }

        return Inertia::render('Chat/Index', [
            'conversations' => \App\Services\ChatInboxService::items($user),
            'activeConversationId' => $activeConversation['id'] ?? null,
            'activeConversation' => $activeConversation,
            'activeMessages' => $activeMessages,
            'currentUser' => [
                'id' => $user->id,
                'name' => $user->name ?? $user->username,
            ],
        ]);
    }

    /** Tickets the composer may tag; scoped to the signed-in user. */
    public function userTickets()
    {
        $user = Auth::user();

        $tickets = Ticket::where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (Ticket $t) => [
                'id' => $t->id,
                'formatted_id' => $t->formatted_id,
                'judul' => $t->judul,
                'status' => $t->status,
                'priority' => $t->priority,
            ]);

        return response()->json(['tickets' => $tickets]);
    }

    /** Room for one ticket; created on first open so the room always exists. */
    public function ticketRoom(Request $request, Ticket $ticket)
    {
        $user = Auth::user();

        abort_unless((int) $ticket->user_id === (int) $user->id, 403, 'Kamu tidak memiliki akses ke tiket ini.');

        $conversation = $this->conversationForTicket($ticket);

        $messages = Message::where('conversation_id', $conversation->id)
            ->with([
                'sender',
                'ticket:id,sub_unit_id,user_id,status,priority',
                'ticket.subUnit:id,nama_layanan',
                'attachments',
                'replyTo.sender',
                'replyTo.attachments',
                'reads',
            ])
            ->orderBy('created_at')
            ->get();

        $this->markConversationRead($conversation, $user);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'ticket_id' => $ticket->id,
                'title' => 'Tiket #' . $ticket->formatted_id,
                'subtitle' => $ticket->subUnit?->nama_layanan ?? 'Diskusi Tiket',
                'ticket' => [
                    'id' => $ticket->id,
                    'formatted_id' => $ticket->formatted_id,
                    'judul' => $ticket->judul,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                ],
            ],
            'messages' => $this->serializeMessages($messages),
        ]);
    }

    /** The room that owns a ticket's chat, created on first visit. */
    private function conversationForTicket(Ticket $ticket): Conversation
    {
        return Conversation::firstOrCreate(
            ['type' => 'ticket', 'ticket_id' => $ticket->id],
            [
                'user_id' => $ticket->user_id,
                'admin_one_id' => $ticket->assigned_admin_id,
                'last_message_at' => now(),
            ]
        );
    }

    private function markConversationRead(Conversation $conversation, User $user): void
    {
        $unread = Message::where('conversation_id', $conversation->id)
            ->where(fn ($q) => $q->where('sender_type', '!=', User::class)->orWhere('sender_id', '!=', $user->id))
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_type', User::class)->where('user_id', $user->id))
            ->get();

        foreach ($unread as $message) {
            MessageRead::firstOrCreate([
                'message_id' => $message->id,
                'user_type' => User::class,
                'user_id' => $user->id,
            ], ['read_at' => now()]);
        }

        if ($unread->isNotEmpty()) {
            \Illuminate\Support\Facades\Cache::forget("unread_chat_user_{$user->id}");
            broadcast(new ChatMessageRead($conversation->id, User::class, $user->id, $unread->count()));
        }
    }

    /** Shape messages exactly like the web chat client expects. */
    private function serializeMessages($messages): array
    {
        return $messages->map(fn (Message $msg) => [
            'id' => $msg->id,
            'conversation_id' => $msg->conversation_id,
            'sender_type' => $msg->sender_type,
            'sender_id' => $msg->sender_id,
            'sender_name' => $msg->sender ? ($msg->sender->name ?? $msg->sender->username) : 'Sistem',
            'sender_avatar' => $msg->sender && !empty($msg->sender->avatar_path) ? '/storage/' . $msg->sender->avatar_path : null,
            'ticket' => $msg->ticket ? [
                'id' => $msg->ticket->id,
                'formatted_id' => $msg->ticket->formatted_id,
                'judul' => $msg->ticket->judul,
                'status' => $msg->ticket->status,
                'priority' => $msg->ticket->priority,
            ] : null,
            'reply_to_message_id' => $msg->reply_to_message_id,
            'reply_to' => $msg->replyTo ? [
                'id' => $msg->replyTo->id,
                'body' => $msg->replyTo->body,
                'sender_name' => $msg->replyTo->sender ? ($msg->replyTo->sender->name ?? $msg->replyTo->sender->username) : 'User',
            ] : null,
            'body' => $msg->body,
            'is_edited' => $msg->is_edited,
            'attachments' => $msg->attachments->map(fn ($att) => [
                'id' => $att->id,
                'file_name' => $att->file_name,
                'file_path' => '/storage/' . $att->file_path,
                'file_type' => $att->file_type,
                'file_size' => $att->file_size,
            ])->all(),
            'reads' => $msg->reads->map(fn ($r) => [
                'user_type' => $r->user_type,
                'user_id' => $r->user_id,
                'read_at' => $r->read_at?->toIso8601String(),
            ])->all(),
            'created_at' => $msg->created_at->toIso8601String(),
        ])->all();
    }

    public function storeMessage(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);
        $user = Auth::user();

        $request->validate([
            'body' => 'nullable|string',
            'ticket_id' => 'nullable|exists:tickets,id',
            'reply_to_message_id' => 'nullable|exists:messages,id',
            'attachment' => 'nullable|file|max:3072|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,zip',
        ], [
            'attachment.max' => 'Ukuran file lampiran maksimal 3 MB.',
            'attachment.mimes' => 'Format file lampiran tidak diizinkan.',
        ]);

        if (empty($request->body) && empty($request->ticket_id) && !$request->hasFile('attachment')) {
            return response()->json(['error' => 'Pesan tidak boleh kosong.'], 422);
        }

        $ticketId = $request->ticket_id;
        if (!$ticketId && $conversation->type === 'ticket') {
            $ticketId = $conversation->ticket_id;
        }
        if (!$ticketId && $request->filled('reply_to_message_id')) {
            $parent = Message::find($request->reply_to_message_id);
            if ($parent && $parent->ticket_id) {
                $ticketId = $parent->ticket_id;
            }
        }
        if (!$ticketId) {
            $ticketId = Message::where('conversation_id', $conversation->id)
                ->whereNotNull('ticket_id')
                ->latest('id')
                ->value('ticket_id')
                ?: Ticket::where('user_id', $user->id)
                    ->whereIn('status', ['open', 'on_proses', 'pending', 'need_revision'])
                    ->latest('updated_at')
                    ->value('id')
                ?: Ticket::where('user_id', $user->id)
                    ->latest('id')
                    ->value('id');
        }
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => User::class,
            'sender_id' => $user->id,
            'ticket_id' => $ticketId,
            'reply_to_message_id' => $request->reply_to_message_id,
            'body' => $request->body,
        ]);

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $file->store('chat_attachments', 'public');

            ChatAttachment::create([
                'message_id' => $message->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
            ]);
        }

        MessageRead::create([
            'message_id' => $message->id,
            'user_type' => User::class,
            'user_id' => $user->id,
            'read_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        broadcast(new ChatMessageSent($message))->toOthers();

        // Bridge to Ticket Timeline (Mobile & Web)
        \App\Services\TicketChatBridgeService::syncChatMessageToTicket($message->load('attachments'));

        return response()->json(['success' => true, 'message' => $message->load(['sender', 'attachments', 'ticket', 'replyTo'])]);
    }

    public function updateMessage(Request $request, Message $message)
    {
        $user = Auth::user();
        if ($message->sender_type !== User::class || (int) $message->sender_id !== (int) $user->id) {
            abort(403);
        }

        $hasBeenRead = MessageRead::where('message_id', $message->id)
            ->where('user_type', '!=', User::class)
            ->exists();

        if ($hasBeenRead) {
            return response()->json(['error' => 'Pesan yang sudah dibaca tidak dapat diedit.'], 422);
        }

        $request->validate(['body' => 'required|string']);

        $message->update([
            'body' => $request->body,
            'is_edited' => true,
            'edited_at' => now(),
        ]);

        broadcast(new ChatMessageEdited($message))->toOthers();

        return response()->json(['success' => true]);
    }

    public function destroyMessage(Message $message)
    {
        $user = Auth::user();
        if ($message->sender_type !== User::class || (int) $message->sender_id !== (int) $user->id) {
            abort(403);
        }

        $conversationId = $message->conversation_id;
        $messageId = $message->id;
        $message->delete();

        broadcast(new ChatMessageDeleted($messageId, $conversationId))->toOthers();

        return response()->json(['success' => true]);
    }

    public function markAsRead(Conversation $conversation)
    {
        Gate::authorize('view', $conversation);
        $user = Auth::user();

        $unreadMessages = Message::where('conversation_id', $conversation->id)
            ->where(function ($sq) use ($user) {
                $sq->where('sender_type', '!=', User::class)
                   ->orWhere('sender_id', '!=', $user->id);
            })
            ->whereDoesntHave('reads', function ($q) use ($user) {
                $q->where('user_type', User::class)->where('user_id', $user->id);
            })
            ->get();

        foreach ($unreadMessages as $uMsg) {
            MessageRead::firstOrCreate([
                'message_id' => $uMsg->id,
                'user_type' => User::class,
                'user_id' => $user->id,
            ], [
                'read_at' => now(),
            ]);
        }

        if ($unreadMessages->isNotEmpty()) {
            \Illuminate\Support\Facades\Cache::forget("unread_chat_user_{$user->id}");
            broadcast(new ChatMessageRead($conversation->id, User::class, $user->id, $unreadMessages->count()));
        }

        return response()->json(['success' => true]);
    }

    public function typing(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);
        $user = Auth::user();
        $isTyping = (bool) $request->input('is_typing', false);

        broadcast(new UserTypingStatus(
            $conversation->id,
            User::class,
            $user->id,
            $user->name ?? $user->username,
            $isTyping
        ))->toOthers();

        return response()->json(['success' => true]);
    }

    public function show(Conversation $conversation)
    {
        Gate::authorize('view', $conversation);
        $user = Auth::user();

        // Mark as read
        $unreadMessages = Message::where('conversation_id', $conversation->id)
            ->where(function ($sq) use ($user) {
                $sq->where('sender_type', '!=', User::class)
                   ->orWhere('sender_id', '!=', $user->id);
            })
            ->whereDoesntHave('reads', function ($q) use ($user) {
                $q->where('user_type', User::class)->where('user_id', $user->id);
            })
            ->get();

        foreach ($unreadMessages as $uMsg) {
            MessageRead::firstOrCreate([
                'message_id' => $uMsg->id,
                'user_type' => User::class,
                'user_id' => $user->id,
            ], [
                'read_at' => now(),
            ]);
        }

        if ($unreadMessages->isNotEmpty()) {
            \Illuminate\Support\Facades\Cache::forget("unread_chat_user_{$user->id}");
            broadcast(new ChatMessageRead($conversation->id, User::class, $user->id, $unreadMessages->count()));
        }

        $conversationData = \App\Services\ChatInboxService::present($conversation, $user, false);

        $rawMessages = Message::where('conversation_id', $conversation->id)
            ->with([
                'sender',
                'ticket:id,sub_unit_id,user_id,status,priority',
                'ticket.subUnit:id,nama_layanan',
                'attachments',
                'replyTo.sender',
                'replyTo.attachments',
                'reads',
            ])
            ->orderBy('created_at', 'asc')
            ->get();

        $messages = $rawMessages->map(function ($msg) {
            return [
                'id' => $msg->id,
                'conversation_id' => $msg->conversation_id,
                'sender_type' => $msg->sender_type,
                'sender_id' => $msg->sender_id,
                'sender_name' => $msg->sender ? ($msg->sender->name ?? $msg->sender->username) : 'Sistem',
                'sender_avatar' => $msg->sender && !empty($msg->sender->avatar_path) ? '/storage/' . $msg->sender->avatar_path : null,
                'ticket' => $msg->ticket ? [
                    'id' => $msg->ticket->id,
                    'formatted_id' => $msg->ticket->formatted_id,
                    'judul' => $msg->ticket->judul,
                    'status' => $msg->ticket->status,
                    'priority' => $msg->ticket->priority,
                ] : null,
                'reply_to_message_id' => $msg->reply_to_message_id,
                'reply_to' => $msg->replyTo ? [
                    'id' => $msg->replyTo->id,
                    'body' => $msg->replyTo->body,
                    'sender_name' => $msg->replyTo->sender ? ($msg->replyTo->sender->name ?? $msg->replyTo->sender->username) : 'User',
                ] : null,
                'body' => $msg->body,
                'is_edited' => $msg->is_edited,
                'attachments' => $msg->attachments->map(fn($att) => [
                    'id' => $att->id,
                    'file_name' => $att->file_name,
                    'file_path' => '/storage/' . $att->file_path,
                    'file_type' => $att->file_type,
                    'file_size' => $att->file_size,
                ]),
                'reads' => $msg->reads->map(fn($r) => [
                    'user_type' => $r->user_type,
                    'user_id' => $r->user_id,
                    'read_at' => $r->read_at ? $r->read_at->toIso8601String() : null,
                ]),
                'created_at' => $msg->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'conversation' => $conversationData,
            'messages' => $messages,
        ]);
    }

    public function downloadAttachment(ChatAttachment $attachment)
    {
        $message = $attachment->message;
        if (!$message || !$message->conversation) {
            abort(404, 'File tidak ditemukan');
        }

        \Illuminate\Support\Facades\Gate::authorize('view', $message->conversation);

        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->file_name);
    }
}
