<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $conversationId;
    public string $userType;
    public int $userId;
    public string $readAt;

    public function __construct(int $conversationId, string $userType, int $userId)
    {
        $this->conversationId = $conversationId;
        $this->userType = $userType;
        $this->userId = $userId;
        $this->readAt = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('chat.conversation.' . $this->conversationId),
        ];

        $conversation = \App\Models\Conversation::find($this->conversationId);
        if ($conversation) {
            if ($conversation->type === 'public_global') {
                $channels[] = new PrivateChannel('chat.public_global');
            }
            if ($conversation->user_id) {
                $channels[] = new PrivateChannel('App.Models.User.' . $conversation->user_id);
            }
            if ($conversation->admin_one_id) {
                $channels[] = new PrivateChannel('App.Models.Admin.' . $conversation->admin_one_id);
            }
            if ($conversation->admin_two_id) {
                $channels[] = new PrivateChannel('App.Models.Admin.' . $conversation->admin_two_id);
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageRead';
    }
}
