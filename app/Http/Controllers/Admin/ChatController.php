<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Events\ChatMessageDeleted;
use App\Events\ChatMessageEdited;
use App\Events\ChatMessageRead;
use App\Events\ChatMessageSent;
use App\Events\UserTypingStatus;
use App\Models\Admin;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $admin = Auth::guard('admin')->user();
        if (!$admin) {
            return redirect()->route('admin.login');
        }

        $admin->update(['last_seen_at' => now()]);

        // Auto-create direct conversations with all other admins
        $otherAdmins = Admin::where('id', '!=', $admin->id)->get();
        foreach ($otherAdmins as $otherAdmin) {
            $minId = min($admin->id, $otherAdmin->id);
            $maxId = max($admin->id, $otherAdmin->id);

            Conversation::firstOrCreate([
                'type' => 'admin_direct',
                'admin_one_id' => $minId,
                'admin_two_id' => $maxId,
            ], [
                'last_message_at' => now(),
            ]);
        }

        // ponytail: wrap base filter in a closure to prevent orWhere leaks
        $query = Conversation::with([
            'user:id,name,username,email,avatar_path,last_seen_at',
            'adminOne:id,name,username,email,avatar_path,last_seen_at',
            'adminTwo:id,name,username,email,avatar_path,last_seen_at',
            'latestMessage.sender',
            'latestMessage.attachments',
        ])->where(function ($base) use ($admin) {
            $base->where('type', 'public_global')
                 ->orWhere(function ($q) use ($admin) {
                     $q->where('type', 'admin_direct')
                       ->where(function ($q2) use ($admin) {
                           $q2->where('admin_one_id', $admin->id)
                              ->orWhere('admin_two_id', $admin->id);
                       });
                 })
                 ->orWhere(function ($q) use ($admin) {
                     $q->where('type', 'user_admin_direct')
                       ->where('admin_one_id', $admin->id);
                 });
        });

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                       ->orWhere('username', 'like', "%{$search}%");
                })->orWhereHas('adminOne', function ($a) use ($search) {
                    $a->where('name', 'like', "%{$search}%")
                       ->orWhere('username', 'like', "%{$search}%");
                })->orWhereHas('adminTwo', function ($a) use ($search) {
                    $a->where('name', 'like', "%{$search}%")
                       ->orWhere('username', 'like', "%{$search}%");
                })->orWhereHas('messages', function ($mq) use ($search) {
                    $mq->where('body', 'like', "%{$search}%");
                });
            });
        }

        if ($request->boolean('unread_only')) {
            $query->whereHas('messages', function ($mq) use ($admin) {
                $mq->where('sender_type', '!=', Admin::class)
                   ->whereDoesntHave('reads', function ($rq) use ($admin) {
                       $rq->where('user_type', Admin::class)->where('user_id', $admin->id);
                   });
            });
        }

        $conversations = $query->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($conv) use ($admin) {
                $unreadCount = Message::where('conversation_id', $conv->id)
                    ->where('sender_type', '!=', Admin::class)
                    ->whereDoesntHave('reads', function ($q) use ($admin) {
                        $q->where('user_type', Admin::class)->where('user_id', $admin->id);
                    })
                    ->count();

                $latestMsg = $conv->latestMessage;

                if ($conv->type === 'public_global') {
                    $target = null;
                    $title = 'Forum Bantuan Halo APU';
                    $subtitle = 'Grup Publik';
                } elseif ($conv->type === 'user_admin_direct') {
                    $target = $conv->user;
                    $title = $target ? ($target->name ?? $target->username) : 'Pengguna';
                    $subtitle = 'User (Privat)';
                } else {
                    $target = (int) $conv->admin_one_id === (int) $admin->id ? $conv->adminTwo : $conv->adminOne;
                    $title = $target ? ($target->name ?? $target->username) : 'Admin';
                    $subtitle = 'Admin';
                }

                return [
                    'id' => $conv->id,
                    'user' => $target ? [
                        'id' => $target->id,
                        'name' => $target->name ?? $target->username,
                        'email' => $target->email ?? '',
                        'avatar' => $target->avatar_path ? '/storage/' . $target->avatar_path : null,
                        'last_seen_at' => $target->last_seen_at ? $target->last_seen_at->toIso8601String() : null,
                    ] : null,
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'last_message' => $latestMsg ? ($latestMsg->body ?? ($latestMsg->attachments->first() ? '[Lampiran]' : '[Tiket]')) : 'Belum ada pesan',
                    'last_message_at' => $conv->last_message_at ? $conv->last_message_at->toIso8601String() : $conv->updated_at->toIso8601String(),
                    'unread_count' => $unreadCount,
                ];
            });

        $activeConversationId = $request->query('active');

        if (!$activeConversationId && $conversations->isNotEmpty()) {
            $supportItem = $conversations->where('title', 'Forum Bantuan Halo APU')->first();
            $activeConversationId = $supportItem ? $supportItem['id'] : $conversations->first()['id'];
        }

        $activeMessages = [];
        $activeConversationData = null;

        if ($activeConversationId) {
            $activeConv = Conversation::where('id', $activeConversationId)
                ->with(['user', 'adminOne', 'adminTwo'])
                ->first();

            if ($activeConv) {
                if ($activeConv->type === 'public_global') {
                    $target = null;
                    $title = 'Forum Bantuan Halo APU';
                    $subtitle = 'Grup Publik';
                } elseif ($activeConv->type === 'user_admin_direct') {
                    $target = $activeConv->user;
                    $title = $target ? ($target->name ?? $target->username) : 'Pengguna';
                    $subtitle = 'User (Privat)';
                } else {
                    $target = (int) $activeConv->admin_one_id === (int) $admin->id ? $activeConv->adminTwo : $activeConv->adminOne;
                    $title = $target ? ($target->name ?? $target->username) : 'Admin';
                    $subtitle = 'Admin';
                }

                $activeConversationData = [
                    'id' => $activeConv->id,
                    'user' => $target ? [
                        'id' => $target->id,
                        'name' => $target->name ?? $target->username,
                        'email' => $target->email ?? '',
                        'avatar' => $target->avatar_path ? '/storage/' . $target->avatar_path : null,
                        'last_seen_at' => $target->last_seen_at ? $target->last_seen_at->toIso8601String() : null,
                    ] : null,
                    'title' => $title,
                    'subtitle' => $subtitle,
                ];

                // Mark unread messages as read
                $unreadMessages = Message::where('conversation_id', $activeConv->id)
                    ->where('sender_type', '!=', Admin::class)
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
                    broadcast(new ChatMessageRead($activeConv->id, Admin::class, $admin->id))->toOthers();
                }

                $rawMessages = Message::where('conversation_id', $activeConv->id)
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

                $activeMessages = $rawMessages->map(function ($msg) {
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
                            'sender_name' => $msg->replyTo->sender ? ($msg->replyTo->sender->name ?? $msg->replyTo->sender->username) : 'Pengguna',
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
            }
        }

        return Inertia::render('Admin/Chat/Index', [
            'conversations' => $conversations,
            'activeConversationId' => $activeConversationId ? (int) $activeConversationId : null,
            'activeConversation' => $activeConversationData,
            'activeMessages' => $activeMessages,
            'filters' => [
                'search' => $request->input('search', ''),
                'unread_only' => $request->boolean('unread_only'),
            ]
        ]);
    }

    public function ticketsList(Request $request)
    {
        $admin = Auth::guard('admin')->user();
        $query = Ticket::with('subUnit:id,nama_layanan');

        if ($request->filled('conversation_id')) {
            $conversation = Conversation::find($request->conversation_id);
            if ($conversation && $conversation->user_id) {
                $query->where('user_id', $conversation->user_id);
            }
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        } else {
            if (!$admin->hasRole('Super Admin') && !$admin->hasPermissionTo('akses-laporan')) {
                $unitIds = $admin->units->pluck('id')->toArray();
                $query->where(function ($q) use ($admin, $unitIds) {
                    $q->where('assigned_admin_id', $admin->id)
                      ->orWhereIn('unit_id', $unitIds);
                });
            }
        }

        $tickets = $query->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($t) => [
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
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('view', $conversation);

        $request->validate([
            'body' => 'nullable|string',
            'ticket_id' => 'nullable|exists:tickets,id',
            'reply_to_message_id' => 'nullable|exists:messages,id',
            'attachment' => 'nullable|file|max:3072', 
        ], [
            'attachment.max' => 'Ukuran file lampiran maksimal 3 MB.',
        ]);

        if (empty($request->body) && empty($request->ticket_id) && !$request->hasFile('attachment')) {
            return response()->json(['error' => 'Pesan tidak boleh kosong.'], 422);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => Admin::class,
            'sender_id' => $admin->id,
            'ticket_id' => $request->ticket_id,
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
            'user_type' => Admin::class,
            'user_id' => $admin->id,
            'read_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        broadcast(new ChatMessageSent($message))->toOthers();

        return response()->json(['success' => true, 'message' => $message->load(['sender', 'attachments', 'ticket', 'replyTo'])]);
    }

    public function updateMessage(Request $request, Message $message)
    {
        $admin = Auth::guard('admin')->user();
        if ($message->sender_type !== Admin::class || (int) $message->sender_id !== (int) $admin->id) {
            abort(403);
        }

        $hasBeenRead = MessageRead::where('message_id', $message->id)
            ->where('user_type', '!=', Admin::class)
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
        $admin = Auth::guard('admin')->user();

        $conversationId = $message->conversation_id;
        $messageId = $message->id;
        $message->delete();

        broadcast(new ChatMessageDeleted($messageId, $conversationId))->toOthers();

        return response()->json(['success' => true]);
    }

    public function markAsRead(Conversation $conversation)
    {
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('view', $conversation);

        $unreadMessages = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', '!=', Admin::class)
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
            broadcast(new ChatMessageRead($conversation->id, Admin::class, $admin->id))->toOthers();
        }

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

    public function downloadAttachment(ChatAttachment $attachment)
    {
        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->file_name);
    }
}
