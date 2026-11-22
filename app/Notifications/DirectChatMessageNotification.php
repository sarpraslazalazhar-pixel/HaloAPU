<?php

namespace App\Notifications;

use App\Channels\FcmChannel;
use App\Models\Conversation;
use App\Traits\FilterNotificationChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class DirectChatMessageNotification extends Notification
{
    use Queueable, FilterNotificationChannels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly string $senderName,
        public readonly string $messageText,
        public readonly string $url,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (! empty($notifiable->fcm_token)) {
            $channels[] = FcmChannel::class;
        }

        return $this->filterChannels($channels, $notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $preview = Str::limit($this->messageText, 120);

        return [
            'type' => 'chat_message',
            'conversation_id' => $this->conversation->id,
            'title' => "Pesan Baru dari {$this->senderName}",
            'message' => $preview,
            'body' => $preview,
            'url' => $this->url,
            'aksi_url' => $this->url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toFcm(object $notifiable): array
    {
        $preview = Str::limit($this->messageText, 120);

        return [
            'title' => "Pesan Baru dari {$this->senderName}",
            'body' => $preview,
            'type' => 'chat_message',
            'url' => $this->url,
            'data' => [
                'type' => 'chat_message',
                'conversation_id' => (string) $this->conversation->id,
                'url' => $this->url,
            ],
        ];
    }
}
