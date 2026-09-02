<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageEdited implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $messageId;
    public int $conversationId;
    public string $body;
    public string $editedAt;

    public function __construct(Message $message)
    {
        $this->messageId = $message->id;
        $this->conversationId = $message->conversation_id;
        $this->body = $message->body ?? '';
        $this->editedAt = $message->edited_at ? $message->edited_at->toIso8601String() : now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.conversation.' . $this->conversationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageEdited';
    }
}
