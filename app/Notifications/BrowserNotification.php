<?php

namespace App\Notifications;

use App\Traits\FilterNotificationChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class BrowserNotification extends Notification implements ShouldBroadcast
{
    use FilterNotificationChannels, Queueable;

    public $title;
    public $message;
    public $url;

    /**
     * Create a new notification instance.
     */
    public function __construct($title, $message, $url = null)
    {
        $this->title = $title;
        $this->message = $message;
        $this->url = $url;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Without a realtime driver (cPanel has no Reverb) persist to database
        // so NotificationBell polling still delivers the alert.
        $channels = in_array(config('broadcasting.default'), [null, 'null', 'log'], true)
            ? ['database']
            : ['broadcast'];

        return $this->filterChannels($channels, $notifiable);
    }

    /**
     * Get the array/database representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'aksi_url' => $this->url,
            'time' => now()->toDateTimeString(),
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'time' => now()->toDateTimeString(),
        ]);
    }
}
