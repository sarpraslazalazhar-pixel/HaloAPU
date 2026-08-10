<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $conversation;

    public function __construct(Message $message)
    {
        // Load relationships needed for the frontend
        $this->message = $message->load(['sender', 'attachments', 'reads', 'ticket']);
        $this->conversation = $message->conversation;
    }

    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->conversation->type === 'public_global') {
            $channels[] = new PrivateChannel('chat.public_global');
        } else {
            $channels[] = new PrivateChannel('chat.conversation.' . $this->conversation->id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'MessageSent';
    }

    public function broadcastWith(): array
    {
        $sender = $this->message->sender;
        $senderName = $sender ? ($sender->name ?? $sender->username) : 'Sistem';
        $senderType = $this->message->sender_type;
        if (str_contains($senderType, 'Admin')) {
            $senderName .= ' (Admin)';
        } elseif (str_contains($senderType, 'User')) {
            $senderName .= ' (User)';
        }

        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id' => $this->message->sender_id,
            'sender_type' => $this->message->sender_type,
            'body' => $this->message->body,
            'created_at' => $this->message->created_at->toIso8601String(),
            'sender_name' => $senderName,
            'sender_avatar' => $sender && $sender->avatar_path ? '/storage/' . $sender->avatar_path : null,
            'attachments' => $this->message->attachments->map(function ($att) {
                return [
                    'id' => $att->id,
                    'file_name' => $att->file_name,
                    'file_path' => '/storage/' . $att->file_path,
                    'file_type' => $att->file_type,
                    'file_size' => $att->file_size,
                ];
            })->toArray(),
            'ticket' => $this->message->ticket_id ? [
                'id' => $this->message->ticket->id,
                'formatted_id' => $this->message->ticket->formatted_id,
                'judul' => $this->message->ticket->judul,
                'status' => $this->message->ticket->status,
                'is_internal' => $this->message->ticket->is_internal,
            ] : null,
            'reads' => [], // Just sent, no reads yet except sender maybe
        ];
    }
}
