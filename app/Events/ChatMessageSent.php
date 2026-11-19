<?php

namespace App\Events;

use App\Models\Admin;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $messageData;
    public ?int $conversationId = null;
    public ?string $conversationType = null;
    /** @var array<int, int> */
    public array $recipientUserIds = [];
    /** @var array<int, int> */
    public array $recipientAdminIds = [];

    public function __construct(Message $message)
    {
        $message->load([
            'sender',
            'ticket:id,unit_id,sub_unit_id,user_id,created_by_admin_id,assigned_admin_id,form_data,status,priority,created_at',
            'ticket.subUnit:id,nama_layanan',
            'ticket.user:id,name,username,avatar_path',
            'ticket.createdByAdmin:id,name,username,avatar_path',
            'attachments',
            'replyTo.sender',
            'replyTo.attachments',
            'reads',
            'conversation.ticket',
        ]);

        $this->conversationId = $message->conversation_id;
        $conv = $message->conversation;
        $ticket = $message->ticket ?? $conv?->ticket;

        $userIds = [];
        $adminIds = [];

        if ($conv) {
            $this->conversationType = $conv->type;
            if ($conv->user_id) {
                $userIds[] = (int) $conv->user_id;
            }
            if ($conv->admin_one_id) {
                $adminIds[] = (int) $conv->admin_one_id;
            }
            if ($conv->admin_two_id) {
                $adminIds[] = (int) $conv->admin_two_id;
            }
        }

        $requesterKey = null;
        $requesterType = null;
        $requesterId = null;
        $requesterName = null;
        $requesterAvatar = null;

        if ($ticket) {
            if ($ticket->user_id) {
                $userIds[] = (int) $ticket->user_id;
            }
            if ($ticket->created_by_admin_id) {
                $adminIds[] = (int) $ticket->created_by_admin_id;
            }
            if ($ticket->assigned_admin_id) {
                $adminIds[] = (int) $ticket->assigned_admin_id;
            }
            if ($ticket->unit_id) {
                $unitAdminIds = DB::table('admin_unit')
                    ->where('unit_id', $ticket->unit_id)
                    ->pluck('admin_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $adminIds = array_merge($adminIds, $unitAdminIds);
            }
            if ($ticket->sub_unit_id) {
                $subUnitAdminIds = DB::table('admin_sub_unit')
                    ->where('sub_unit_id', $ticket->sub_unit_id)
                    ->pluck('admin_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $adminIds = array_merge($adminIds, $subUnitAdminIds);
            }

            try {
                $superAdminIds = Admin::where(function ($q) {
                    $q->where('username', 'like', '%superadmin%')
                        ->orWhereHas('roles', fn ($rq) => $rq->whereIn('name', ['superadmin', 'Super Admin', 'super_admin', 'Superadmin']));
                })->pluck('id')->map(fn ($id) => (int) $id)->all();
                $adminIds = array_merge($adminIds, $superAdminIds);
            } catch (\Throwable) {
                // Ignore role lookup errors if permissions tables aren't seeded.
            }

            if ($ticket->created_by_admin_id && $ticket->createdByAdmin) {
                $creator = $ticket->createdByAdmin;
                $requesterKey = 'admin:' . $creator->id;
                $requesterType = 'admin';
                $requesterId = (int) $creator->id;
                $requesterName = $creator->name ?? $creator->username;
                $requesterAvatar = !empty($creator->avatar_path) ? '/storage/' . $creator->avatar_path : null;
            } elseif ($ticket->user_id && $ticket->user) {
                $owner = $ticket->user;
                $requesterKey = 'user:' . $owner->id;
                $requesterType = 'user';
                $requesterId = (int) $owner->id;
                $requesterName = $owner->name ?? $owner->username;
                $requesterAvatar = !empty($owner->avatar_path) ? '/storage/' . $owner->avatar_path : null;
            }
        }

        $this->recipientUserIds = array_values(array_unique(array_filter($userIds)));
        $this->recipientAdminIds = array_values(array_unique(array_filter($adminIds)));

        $this->messageData = [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_type' => $message->sender_type,
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender ? ($message->sender->name ?? $message->sender->username) : 'Sistem',
            'sender_avatar' => $message->sender && !empty($message->sender->avatar_path) ? '/storage/' . $message->sender->avatar_path : null,
            'requester_key' => $requesterKey,
            'requester_type' => $requesterType,
            'requester_id' => $requesterId,
            'requester_name' => $requesterName,
            'requester_avatar' => $requesterAvatar,
            'ticket' => $ticket ? [
                'id' => $ticket->id,
                'formatted_id' => $ticket->formatted_id,
                'judul' => $ticket->judul,
                'chat_label' => $ticket->chatLabel(),
                'created_at' => $ticket->created_at?->toIso8601String(),
                'nama_layanan' => $ticket->subUnit?->nama_layanan,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'created_by_admin_id' => $ticket->created_by_admin_id,
            ] : null,
            'reply_to_message_id' => $message->reply_to_message_id,
            'reply_to' => $message->replyTo ? [
                'id' => $message->replyTo->id,
                'body' => $message->replyTo->body,
                'sender_name' => $message->replyTo->sender ? ($message->replyTo->sender->name ?? $message->replyTo->sender->username) : 'User',
            ] : null,
            'body' => $message->body,
            'is_edited' => $message->is_edited,
            'attachments' => $message->attachments->map(fn ($att) => [
                'id' => $att->id,
                'file_name' => $att->file_name,
                'file_path' => '/storage/' . $att->file_path,
                'file_type' => $att->file_type,
                'file_size' => $att->file_size,
            ]),
            'reads' => $message->reads->map(fn ($r) => [
                'user_type' => $r->user_type,
                'user_id' => $r->user_id,
                'read_at' => $r->read_at ? $r->read_at->toIso8601String() : null,
            ]),
            'created_at' => $message->created_at->toIso8601String(),
        ];
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('chat.conversation.' . $this->conversationId),
        ];

        if ($this->conversationType === 'public_global') {
            $channels[] = new PrivateChannel('chat.public_global');
        }

        foreach ($this->recipientUserIds as $uid) {
            $channels[] = new PrivateChannel('App.Models.User.' . $uid);
        }

        foreach ($this->recipientAdminIds as $aid) {
            $channels[] = new PrivateChannel('App.Models.Admin.' . $aid);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageSent';
    }
}
