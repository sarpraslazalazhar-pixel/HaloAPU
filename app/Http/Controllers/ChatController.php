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
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login');
        }

        $user->update(['last_seen_at' => now()]);

        // 1. Global Public Conversation
        $supportConv = Conversation::firstOrCreate(
            ['type' => 'public_global'],
            ['last_message_at' => now()]
        );

        // 2. Direct Conversations with ALL Admins
        $assignedAdminIds = Ticket::where('user_id', $user->id)
            ->whereNotNull('assigned_admin_id')
            ->pluck('assigned_admin_id')
            ->unique()
            ->toArray();

        $allAdmins = Admin::all();
        foreach ($allAdmins as $adm) {
            Conversation::firstOrCreate([
                'type' => 'user_admin_direct',
                'user_id' => $user->id,
                'admin_one_id' => $adm->id,
            ], [
                'last_message_at' => now(),
            ]);
        }

        // Fetch all conversations for this user
        $query = Conversation::with([
            'adminOne:id,name,username,email,avatar_path,last_seen_at',
            'latestMessage.sender',
            'latestMessage.attachments',
        ])->where(function ($q) use ($user) {
            $q->where('type', 'public_global')
              ->orWhere(function ($q2) use ($user) {
                  $q2->where('user_id', $user->id)
                     ->whereIn('type', ['user_admin_direct']);
              });
        });

        $conversations = $query->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($conv) use ($user, $assignedAdminIds) {
                $unreadCount = Message::where('conversation_id', $conv->id)
                    ->where('sender_type', '!=', User::class)
                    ->whereDoesntHave('reads', function ($q) use ($user) {
                        $q->where('user_type', User::class)->where('user_id', $user->id);
                    })
                    ->count();

                $latestMsg = $conv->latestMessage;

                if ($conv->type === 'public_global') {
                    $title = 'Forum Bantuan Halo APU';
                    $subtitle = 'Grup Publik';
                    $avatar = null;
                    $isAssigned = false;
                } else {
                    $target = $conv->adminOne;
                    $title = $target ? ($target->name ?? $target->username) : 'Admin';
                    $isAssigned = in_array($conv->admin_one_id, $assignedAdminIds);
                    $subtitle = $isAssigned ? 'Admin (Ditugaskan)' : 'Admin';
                    $avatar = $target && $target->avatar_path ? '/storage/' . $target->avatar_path : null;
                }

                return [
                    'id' => $conv->id,
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'is_assigned' => $isAssigned,
                    'user' => [
                        'avatar' => $avatar,
                    ],
                    'last_message' => $latestMsg ? ($latestMsg->body ?? ($latestMsg->attachments->first() ? '[Lampiran]' : '[Tiket]')) : 'Belum ada pesan',
                    'last_message_at' => $conv->last_message_at ? $conv->last_message_at->toIso8601String() : $conv->updated_at->toIso8601String(),
                    'unread_count' => $unreadCount,
                ];
            });

        $activeConversationId = $request->query('active');

        if (!$activeConversationId && $conversations->isNotEmpty()) {
            // Priority to support chat if no active selected
            $supportItem = $conversations->where('title', 'Forum Bantuan Halo APU')->first();
            $activeConversationId = $supportItem ? $supportItem['id'] : $conversations->first()['id'];
        }

        $activeMessages = [];
        $activeConversationData = null;

        if ($activeConversationId) {
            $activeConv = Conversation::where('id', $activeConversationId)
                ->where(function ($q) use ($user) {
                    $q->where('type', 'public_global')
                      ->orWhere('user_id', $user->id);
                })
                ->with(['adminOne'])
                ->first();

            if ($activeConv) {
                if ($activeConv->type === 'public_global') {
                    $title = 'Forum Bantuan Halo APU';
                    $subtitle = 'Grup Publik';
                    $avatar = null;
                } else {
                    $target = $activeConv->adminOne;
                    $title = $target ? ($target->name ?? $target->username) : 'Admin';
                    $subtitle = 'Admin (Privat)';
                    $avatar = $target && $target->avatar_path ? '/storage/' . $target->avatar_path : null;
                }

                $activeConversationData = [
                    'id' => $activeConv->id,
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'user' => [
                        'avatar' => $avatar,
                    ],
                ];

                // Mark unread messages as read
                $unreadMessages = Message::where('conversation_id', $activeConv->id)
                    ->where('sender_type', '!=', User::class)
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
                    broadcast(new ChatMessageRead($activeConv->id, User::class, $user->id))->toOthers();
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
            }
        }

        return Inertia::render('Chat/Index', [
            'conversations' => $conversations,
            'activeConversationId' => $activeConversationId ? (int) $activeConversationId : null,
            'activeConversation' => $activeConversationData,
            'activeMessages' => $activeMessages,
        ]);
    }

    public function userTickets()
    {
        $user = Auth::user();
        $tickets = Ticket::with('subUnit:id,nama_layanan')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
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
        Gate::authorize('view', $conversation);
        $user = Auth::user();

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
            'sender_type' => User::class,
            'sender_id' => $user->id,
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
            'user_type' => User::class,
            'user_id' => $user->id,
            'read_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        broadcast(new ChatMessageSent($message))->toOthers();

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
            ->where('sender_type', '!=', User::class)
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
            broadcast(new ChatMessageRead($conversation->id, User::class, $user->id))->toOthers();
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
            ->where('sender_type', '!=', User::class)
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
            broadcast(new ChatMessageRead($conversation->id, User::class, $user->id))->toOthers();
        }

        if ($conversation->type === 'public_global') {
            $title = 'Forum Bantuan Halo APU';
            $subtitle = 'Grup Publik';
            $avatar = null;
        } else {
            $target = $conversation->adminOne;
            $title = $target ? ($target->name ?? $target->username) : 'Admin';
            $subtitle = 'Admin (Privat)';
            $avatar = $target && $target->avatar_path ? '/storage/' . $target->avatar_path : null;
        }

        $conversationData = [
            'id' => $conversation->id,
            'title' => $title,
            'subtitle' => $subtitle,
            'user' => [
                'avatar' => $avatar,
            ],
        ];

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
        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->file_name);
    }
}
