<?php

namespace App\Http\Controllers\Admin;

use App\Events\ChatMessageDeleted;
use App\Events\ChatMessageRead;
use App\Events\UserTypingStatus;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Ticket;
use App\Services\ChatInboxService;
use App\Services\DocumentContentService;
use App\Services\TicketChatBridgeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        /** @var Admin|null $admin */
        $admin = Auth::guard('admin')->user();
        if (!$admin) {
            return redirect()->route('admin.login');
        }

        $admin->update(['last_seen_at' => now()]);

        // Ensure private admin-pair rows exist in storage for policy/direct compatibility,
        // while the Admin Chat inbox UI strictly lists ticket requesters (Users & Admins).
        $otherAdminIds = Admin::where('id', '!=', $admin->id)->pluck('id')->all();
        if (!empty($otherAdminIds)) {
            $existingPairs = Conversation::where('type', 'admin_direct')
                ->where(fn ($q) => $q->where('admin_one_id', $admin->id)->orWhere('admin_two_id', $admin->id))
                ->get(['admin_one_id', 'admin_two_id']);

            $existingOtherIds = $existingPairs
                ->map(fn ($c) => (int) $c->admin_one_id === (int) $admin->id ? $c->admin_two_id : $c->admin_one_id)
                ->all();
            $missingAdminIds = array_diff($otherAdminIds, $existingOtherIds);

            foreach ($missingAdminIds as $missingId) {
                Conversation::firstOrCreate([
                    'type' => 'admin_direct',
                    'admin_one_id' => min($admin->id, $missingId),
                    'admin_two_id' => max($admin->id, $missingId),
                ], [
                    'last_message_at' => now(),
                ]);
            }
        }

        $activeConversationId = null;
        $requestedSpecificTicket = $request->filled('ticket_id');
        $linkedTicket = null;

        if ($request->filled('active')) {
            $candidate = Conversation::find((int) $request->query('active'));
            if ($candidate && Gate::forUser($admin)->allows('view', $candidate)) {
                $activeConversationId = $candidate->id;
            }
        }

        if (!$activeConversationId && $requestedSpecificTicket) {
            $ticketId = (int) $request->input('ticket_id');
            $ticket = Ticket::accessibleBy($admin)->with('subUnit:id,nama_layanan')->find($ticketId);

            if ($ticket) {
                $linkedTicket = $ticket;
                $activeConversationId = ChatInboxService::ensureTicketConversation($ticket)->id;
            }
        }

        if (!$activeConversationId && !$requestedSpecificTicket && $request->filled('user_id')) {
            $targetUserId = (int) $request->input('user_id');
            if ($targetUserId > 0) {
                $userTicket = Ticket::accessibleBy($admin)
                    ->where('user_id', $targetUserId)
                    ->orderByDesc('updated_at')
                    ->first();

                if ($userTicket) {
                    $activeConversationId = ChatInboxService::ensureTicketConversation($userTicket)->id;
                }
            }
        }

        $search = $request->filled('search') ? (string) $request->input('search') : null;
        $unreadOnly = $request->boolean('unread_only');

        $conversations = ChatInboxService::adminGroupedItems($admin, $search, $unreadOnly);

        if (!$activeConversationId && !$requestedSpecificTicket && !empty($conversations)) {
            $activeConversationId = (int) $conversations[0]['id'];
        }

        $activeMessages = [];
        $activeConversationData = null;

        if ($activeConversationId) {
            $activeConv = Conversation::with([
                'user',
                'adminOne',
                'adminTwo',
                'ticket.subUnit.unit',
                'ticket.user',
                'ticket.createdByAdmin',
                'ticket.assignedAdmin',
            ])->find($activeConversationId);

            if ($activeConv && Gate::forUser($admin)->allows('view', $activeConv)) {
                $cleared = $this->markConversationRead($activeConv, $admin);

                $activeConversationData = ChatInboxService::present($activeConv, $admin, true, 0);

                $rawMessages = Message::where('conversation_id', $activeConv->id)
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

                $activeMessages = $rawMessages->map(fn (Message $msg) => $this->serializeMessage($msg))->all();

                if ($cleared > 0) {
                    foreach ($conversations as &$group) {
                        if (!empty($group['ticket_rooms']) && is_array($group['ticket_rooms'])) {
                            foreach ($group['ticket_rooms'] as &$room) {
                                if ((int) $room['conversation_id'] === (int) $activeConv->id) {
                                    $room['unread_count'] = 0;
                                }
                            }
                            unset($room);
                            $group['unread_count'] = array_sum(array_column($group['ticket_rooms'], 'unread_count'));
                        }
                    }
                    unset($group);
                }
            }
        }

        return Inertia::render('Admin/Chat/Index', [
            'conversations' => $conversations,
            'activeConversationId' => $activeConversationData['id'] ?? null,
            'activeConversation' => $activeConversationData ?? ['id' => null, 'ticket_id' => null],
            'activeMessages' => $activeMessages,
            'linkedTicket' => $linkedTicket ? [
                'id' => $linkedTicket->id,
                'formatted_id' => $linkedTicket->formatted_id,
                'judul' => $linkedTicket->judul,
                'chat_label' => $linkedTicket->chatLabel(),
                'created_at' => $linkedTicket->created_at?->toIso8601String(),
                'nama_layanan' => $linkedTicket->subUnit?->nama_layanan,
                'status' => $linkedTicket->status,
                'priority' => $linkedTicket->priority,
            ] : null,
            'filters' => [
                'search' => $request->input('search', ''),
                'unread_only' => $unreadOnly,
            ],
        ]);
    }

    public function ticketsList(Request $request)
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        $query = Ticket::accessibleBy($admin)->with('subUnit:id,nama_layanan');

        if ($request->filled('conversation_id')) {
            $conversation = Conversation::with('ticket')->find($request->conversation_id);
            if ($conversation) {
                if ($conversation->ticket?->created_by_admin_id) {
                    $query->where('created_by_admin_id', $conversation->ticket->created_by_admin_id);
                } elseif ($conversation->user_id) {
                    $query->where('user_id', $conversation->user_id);
                }
            }
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $tickets = $query->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'formatted_id' => $t->formatted_id,
                'judul' => $t->judul,
                'status' => $t->status,
                'priority' => $t->priority,
                'created_at' => $t->created_at->format('d/m/Y H:i'),
            ]);

        return response()->json(['tickets' => $tickets]);
    }

    public function storeMessage(Request $request, Conversation $conversation)
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('view', $conversation);

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
            'attachment' => 'nullable|file|max:3072|extensions:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv,zip|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv,txt,zip',
        ], [
            'attachment.max' => 'Ukuran file lampiran maksimal 3 MB.',
            'attachment.mimes' => 'Format file lampiran tidak diizinkan.',
        ]);

        if (empty($request->body) && empty($request->ticket_id) && !$request->hasFile('attachment')) {
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

        if (!$ticketId && $request->filled('reply_to_message_id')) {
            $parent = Message::find($request->reply_to_message_id);
            if ($parent && $parent->ticket_id) {
                $ticketId = $parent->ticket_id;
            }
        }

        if (!$ticketId && $conversation->user_id) {
            $ticketId = Message::where('conversation_id', $conversation->id)
                ->whereNotNull('ticket_id')
                ->latest('id')
                ->value('ticket_id')
                ?: Ticket::where('user_id', $conversation->user_id)
                    ->whereIn('status', ['open', 'on_proses', 'pending', 'need_revision'])
                    ->latest('updated_at')
                    ->value('id')
                ?: Ticket::where('user_id', $conversation->user_id)
                    ->latest('id')
                    ->value('id');
        }

        $message = DB::transaction(function () use ($conversation, $admin, $ticketId, $request) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => Admin::class,
                'sender_id' => $admin->id,
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
                    'file_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'file_size' => $file->getSize(),
                ]);
            }

            MessageRead::firstOrCreate([
                'message_id' => $message->id,
                'user_type' => Admin::class,
                'user_id' => $admin->id,
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
        $admin = Auth::guard('admin')->user();
        $validator = Validator::make($request->all(), [
            'body' => ['required', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            $firstError = $validator->errors()->first('body');

            return response()->json([
                'error' => $firstError,
                'message' => $firstError,
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();

        try {
            $updated = TicketChatBridgeService::editChatMessage($message, $admin, $validated['body']);
        } catch (AuthorizationException) {
            abort(403);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $this->serializeMessage($updated->load(['sender', 'attachments', 'ticket.subUnit', 'replyTo.sender', 'reads'])),
        ]);
    }

    public function destroyMessage(Message $message)
    {
        $admin = Auth::guard('admin')->user();
        if ($message->conversation) {
            Gate::forUser($admin)->authorize('view', $message->conversation);
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
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('view', $conversation);

        $this->markConversationRead($conversation, $admin);

        return response()->json(['success' => true]);
    }

    public function typing(Request $request, Conversation $conversation)
    {
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('view', $conversation);
        $isTyping = (bool) $request->input('is_typing', false);

        broadcast(new UserTypingStatus(
            $conversation->id,
            Admin::class,
            $admin->id,
            $admin->name ?? $admin->username,
            $isTyping
        ))->toOthers();

        return response()->json(['success' => true]);
    }

    public function show(Conversation $conversation)
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('view', $conversation);

        $this->markConversationRead($conversation, $admin);

        $conversationData = ChatInboxService::present($conversation, $admin, true, 0);

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
            'messages' => $rawMessages->map(fn (Message $msg) => $this->serializeMessage($msg))->all(),
        ]);
    }

    public function downloadAttachment(ChatAttachment $attachment)
    {
        $admin = Auth::guard('admin')->user();
        $message = $attachment->message;
        if (!$message || !$message->conversation) {
            abort(404, 'File tidak ditemukan');
        }

        Gate::forUser($admin)->authorize('view', $message->conversation);

        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->file_name);
    }

    public function viewAttachment(ChatAttachment $attachment)
    {
        $admin = Auth::guard('admin')->user();
        $message = $attachment->message;
        if (!$message || !$message->conversation) {
            abort(404, 'File tidak ditemukan');
        }

        Gate::forUser($admin)->authorize('view', $message->conversation);

        return DocumentContentService::streamInlineOrDownload(
            (string) $attachment->file_path,
            (string) ($attachment->file_name ?: basename((string) $attachment->file_path)),
            $attachment->file_type,
        );
    }

    public function previewAttachment(ChatAttachment $attachment)
    {
        $admin = Auth::guard('admin')->user();
        $message = $attachment->message;
        if (! $message || ! $message->conversation) {
            abort(404, 'File tidak ditemukan');
        }

        Gate::forUser($admin)->authorize('view', $message->conversation);

        return DocumentContentService::previewSpreadsheetResponse(
            (string) $attachment->file_path,
            (string) ($attachment->file_name ?: basename((string) $attachment->file_path)),
            $attachment->file_type,
        );
    }

    protected function markConversationRead(Conversation $conversation, Admin $admin): int
    {
        $unreadMessages = Message::where('conversation_id', $conversation->id)
            ->where(function ($sq) use ($admin) {
                $sq->where('sender_type', '!=', Admin::class)
                    ->orWhere('sender_id', '!=', $admin->id);
            })
            ->whereDoesntHave('reads', function ($q) use ($admin) {
                $q->where('user_type', Admin::class)->where('user_id', $admin->id);
            })
            ->get();

        foreach ($unreadMessages as $uMsg) {
            MessageRead::firstOrCreate([
                'message_id' => $uMsg->id,
                'user_type' => Admin::class,
                'user_id' => $admin->id,
            ], [
                'read_at' => now(),
            ]);
        }

        if ($unreadMessages->isNotEmpty()) {
            Cache::forget("unread_chat_admin_{$admin->id}");

            try {
                broadcast(new ChatMessageRead($conversation->id, Admin::class, $admin->id, $unreadMessages->count()));
            } catch (\Throwable $e) {
                \Log::warning('ChatMessageRead broadcast failed: '.$e->getMessage());
            }
        }

        return $unreadMessages->count();
    }

    protected function serializeMessage(Message $msg): array
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
                'sender_name' => $msg->replyTo->sender ? ($msg->replyTo->sender->name ?? $msg->replyTo->sender->username) : 'Pengguna',
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
                'read_at' => $r->read_at ? $r->read_at->toIso8601String() : null,
            ])->all(),
            'created_at' => $msg->created_at->toIso8601String(),
        ];
    }
}
