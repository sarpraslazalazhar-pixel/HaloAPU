<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTypingStatus implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $conversationId;
    public string $userType;
    public int $userId;
    public string $userName;
    public bool $isTyping;

    public function __construct(int $conversationId, string $userType, int $userId, string $userName, bool $isTyping)
    {
        $this->conversationId = $conversationId;
        $this->userType = $userType;
        $this->userId = $userId;
        $this->userName = $userName;
        $this->isTyping = $isTyping;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.conversation.' . $this->conversationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'UserTypingStatus';
    }
}
