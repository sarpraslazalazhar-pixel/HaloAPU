<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $messageData;
    public ?int $conversationId = null;
    public ?string $conversationType = null;
    public ?int $participantUserId = null;
    public ?int $participantAdminOneId = null;
    public ?int $participantAdminTwoId = null;

    public function __construct(Message $message)
    {
        $message->load([
            'sender',
            'ticket:id,sub_unit_id,user_id,status,priority',
            'ticket.subUnit:id,nama_layanan',
            'attachments',
            'replyTo.sender',
            'replyTo.attachments',
            'reads',
            'conversation',
        ]);

        $this->conversationId = $message->conversation_id;
        if ($message->conversation) {
            $this->conversationType = $message->conversation->type;
            $this->participantUserId = $message->conversation->user_id;
            $this->participantAdminOneId = $message->conversation->admin_one_id;
            $this->participantAdminTwoId = $message->conversation->admin_two_id;
        }

        $this->messageData = [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_type' => $message->sender_type,
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender ? ($message->sender->name ?? $message->sender->username) : 'Sistem',
            'sender_avatar' => $message->sender && !empty($message->sender->avatar_path) ? '/storage/' . $message->sender->avatar_path : null,
            'ticket' => $message->ticket ? [
                'id' => $message->ticket->id,
                'formatted_id' => $message->ticket->formatted_id,
                'judul' => $message->ticket->judul,
                'status' => $message->ticket->status,
                'priority' => $message->ticket->priority,
            ] : null,
            'reply_to_message_id' => $message->reply_to_message_id,
            'reply_to' => $message->replyTo ? [
                'id' => $message->replyTo->id,
                'body' => $message->replyTo->body,
                'sender_name' => $message->replyTo->sender ? ($message->replyTo->sender->name ?? $message->replyTo->sender->username) : 'User',
            ] : null,
            'body' => $message->body,
            'is_edited' => $message->is_edited,
            'attachments' => $message->attachments->map(fn($att) => [
                'id' => $att->id,
                'file_name' => $att->file_name,
                'file_path' => '/storage/' . $att->file_path,
                'file_type' => $att->file_type,
                'file_size' => $att->file_size,
            ]),
            'reads' => $message->reads->map(fn($r) => [
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

        if ($this->participantUserId) {
            $channels[] = new PrivateChannel('App.Models.User.' . $this->participantUserId);
        }

        if ($this->participantAdminOneId) {
            $channels[] = new PrivateChannel('App.Models.Admin.' . $this->participantAdminOneId);
        }

        if ($this->participantAdminTwoId) {
            $channels[] = new PrivateChannel('App.Models.Admin.' . $this->participantAdminTwoId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageSent';
    }
}
