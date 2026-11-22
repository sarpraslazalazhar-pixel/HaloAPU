<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageDeleted;
use App\Events\ChatMessageEdited;
use App\Events\ChatMessageRead;
use App\Events\UserTypingStatus;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ChatInboxService;
use App\Services\TicketChatBridgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ChatController extends Controller
{
    /**
     * User chat inbox: one room per ticket submitted by the signed-in user.
     * `?ticket=N` (from a ticket page) or `?active=N` preselects a room.
     */
    public function index(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login');
        }

        $user->update(['last_seen_at' => now()]);

        $activeConversationId = $request->query('active');

        if (!$activeConversationId && $request->filled('ticket')) {
            $ticketId = (int) $request->query('ticket');
            $ticket = Ticket::ownedByUser($user)->find($ticketId);

            if (!$ticket) {
                abort(403, 'Kamu tidak memiliki akses ke tiket ini.');
            }

            $activeConversationId = ChatInboxService::ensureTicketConversation($ticket)->id;
        }

        // Sync all tickets submitted by this user so every ticket has a room in the sidebar.
        $conversations = ChatInboxService::userItems($user);

        $activeConversation = null;
        $activeMessages = [];

        if ($activeConversationId) {
            $conversation = Conversation::find($activeConversationId);

            if ($conversation && Gate::allows('view', $conversation)) {
                $this->markConversationRead($conversation, $user);

                $activeConversation = ChatInboxService::present($conversation, $user, false, 0);
                $activeMessages = $this->serializeMessages(
                    Message::where('conversation_id', $conversation->id)
                        ->with([
                            'sender',
                            'ticket:id,sub_unit_id,user_id,created_by_admin_id,form_data,status,priority',
                            'ticket.subUnit:id,nama_layanan',
                            'attachments',
                            'replyTo.sender',
                            'replyTo.attachments',
                            'reads',
                        ])
                        ->orderBy('created_at')
                        ->get()
                );

                // Reflect zeroed unread count in the sidebar list for the opened room.
                foreach ($conversations as &$item) {
                    if ((int) $item['id'] === (int) $conversation->id) {
                        $item['unread_count'] = 0;
                    }
                }
                unset($item);
            }
        }

        return Inertia::render('Chat/Index', [
            'conversations' => $conversations,
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
        /** @var User $user */
        $user = Auth::user();

        $tickets = Ticket::ownedByUser($user)
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
        /** @var User $user */
        $user = Auth::user();

        abort_unless($ticket->isOwnedByUser($user), 403, 'Kamu tidak memiliki akses ke tiket ini.');

        $conversation = ChatInboxService::ensureTicketConversation($ticket);

        $messages = Message::where('conversation_id', $conversation->id)
            ->with([
                'sender',
                'ticket:id,sub_unit_id,user_id,created_by_admin_id,form_data,status,priority',
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
            'conversation' => ChatInboxService::present($conversation, $user, false, 0),
            'messages' => $this->serializeMessages($messages),
        ]);
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
            Cache::forget("unread_chat_user_{$user->id}");

            try {
                broadcast(new ChatMessageRead($conversation->id, User::class, $user->id, $unread->count()));
            } catch (\Throwable $e) {
                // Read state is already stored; a dead broker must not fail the GET.
                \Log::warning('ChatMessageRead broadcast failed: '.$e->getMessage());
            }
        }
    }

    private function serializeMessage(Message $msg): array
    {
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
                'chat_label' => $msg->ticket->chatLabel(),
                'created_at' => $msg->ticket->created_at?->toIso8601String(),
                'nama_layanan' => $msg->ticket->subUnit?->nama_layanan,
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
            'is_edited' => (bool) $msg->is_edited,
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
        ];
    }

    /** Shape messages exactly like the web chat client expects. */
    private function serializeMessages($messages): array
    {
        return $messages->map(fn (Message $msg) => $this->serializeMessage($msg))->all();
    }

    public function storeMessage(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);
        /** @var User $user */
        $user = Auth::user();

        if ($conversation->type === 'ticket' && $conversation->ticket) {
            $status = strtolower((string) $conversation->ticket->status);
            if (in_array($status, ['solve', 'selesai', 'reject', 'dibatalkan'], true)) {
                return response()->json(['error' => 'Tiket ini sudah ditutup. Tidak dapat mengirim pesan baru.'], 422);
            }
        }

        $request->validate([
            'body' => 'nullable|string',
            'ticket_id' => 'nullable|exists:tickets,id',
            'reply_to_message_id' => 'nullable|exists:messages,id',
            'attachment' => 'nullable|file|max:3072|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,zip',
        ], [
            'attachment.max' => 'Ukuran file lampiran maksimal 3 MB.',
            'attachment.mimes' => 'Format file lampiran tidak diizinkan.',
        ]);

        if (empty($request->body) && ! $request->hasFile('attachment')) {
            return response()->json(['error' => 'Pesan tidak boleh kosong.'], 422);
        }

        if ($request->filled('reply_to_message_id')) {
            $parent = Message::whereKey($request->reply_to_message_id)
                ->where('conversation_id', $conversation->id)
                ->first();

            if (! $parent) {
                return response()->json(['error' => 'Pesan yang dibalas tidak ditemukan di percakapan ini.'], 422);
            }
        }

        $ticketId = $conversation->type === 'ticket'
            ? $conversation->ticket_id
            : $request->ticket_id;

        if ($ticketId && $conversation->type !== 'ticket') {
            $ticket = Ticket::find($ticketId);
            if (!$ticket || !$ticket->isOwnedByUser($user)) {
                return response()->json(['error' => 'Tiket tidak ditemukan.'], 403);
            }
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
                ?: Ticket::ownedByUser($user)
                    ->whereIn('status', ['open', 'on_proses', 'pending', 'need_revision'])
                    ->latest('updated_at')
                    ->value('id')
                ?: Ticket::ownedByUser($user)
                    ->latest('id')
                    ->value('id');
        }

        $message = DB::transaction(function () use ($conversation, $user, $ticketId, $request) {
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

            MessageRead::firstOrCreate([
                'message_id' => $message->id,
                'user_type' => User::class,
                'user_id' => $user->id,
            ], [
                'read_at' => now(),
            ]);

            $conversation->update(['last_message_at' => now()]);

            if ($conversation->type === 'ticket') {
                TicketChatBridgeService::syncChatMessageToTicket($message->load('attachments'));
            }

            return $message;
        });

        TicketChatBridgeService::afterCommit(function () use ($message): void {
            TicketChatBridgeService::emitChatMessage($message);
            TicketChatBridgeService::notifyChatMessageRecipients($message);
        });

        $message->load(['sender', 'attachments', 'ticket.subUnit', 'replyTo.sender', 'reads']);

        return response()->json([
            'success' => true,
            'message' => $this->serializeMessage($message),
        ]);
    }

    public function updateMessage(Request $request, Message $message)
    {
        $user = Auth::user();
        if ($message->sender_type !== User::class || (int) $message->sender_id !== (int) $user->id) {
            abort(403);
        }

        $hasBeenRead = MessageRead::where('message_id', $message->id)
            ->where(fn ($q) => $q->where('user_type', '!=', User::class)->orWhere('user_id', '!=', $user->id))
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

        try {
            broadcast(new ChatMessageEdited($message))->toOthers();
        } catch (\Throwable $e) {
            \Log::warning('ChatMessageEdited broadcast failed: '.$e->getMessage());
        }

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

        try {
            broadcast(new ChatMessageDeleted($messageId, $conversationId))->toOthers();
        } catch (\Throwable $e) {
            \Log::warning('ChatMessageDeleted broadcast failed: '.$e->getMessage());
        }

        return response()->json(['success' => true]);
    }

    public function markAsRead(Conversation $conversation)
    {
        Gate::authorize('view', $conversation);
        /** @var User $user */
        $user = Auth::user();

        $this->markConversationRead($conversation, $user);

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
        /** @var User $user */
        $user = Auth::user();

        $this->markConversationRead($conversation, $user);

        $conversationData = ChatInboxService::present($conversation, $user, false, 0);

        $rawMessages = Message::where('conversation_id', $conversation->id)
            ->with([
                'sender',
                'ticket:id,sub_unit_id,user_id,created_by_admin_id,form_data,status,priority',
                'ticket.subUnit:id,nama_layanan',
                'attachments',
                'replyTo.sender',
                'replyTo.attachments',
                'reads',
            ])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'conversation' => $conversationData,
            'messages' => $this->serializeMessages($rawMessages),
        ]);
    }

    public function downloadAttachment(ChatAttachment $attachment)
    {
        $message = $attachment->message;
        if (!$message || !$message->conversation) {
            abort(404, 'File tidak ditemukan');
        }

        Gate::authorize('view', $message->conversation);

        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->file_name);
    }

    public function viewAttachment(ChatAttachment $attachment)
    {
        $message = $attachment->message;
        if (!$message || !$message->conversation) {
            abort(404, 'File tidak ditemukan');
        }

        Gate::authorize('view', $message->conversation);

        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan');
        }

        return response()->file(Storage::disk('public')->path($attachment->file_path), [
            'Content-Type' => $attachment->file_type ?: Storage::disk('public')->mimeType($attachment->file_path),
            'Content-Disposition' => 'inline; filename="'.$attachment->file_name.'"',
        ]);
    }
}
