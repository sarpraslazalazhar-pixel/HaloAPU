<?php

namespace App\Notifications;

use App\Channels\FcmChannel;
use App\Models\AppRelease;
use App\Traits\FilterNotificationChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class AppUpdateAvailableNotification extends Notification implements ShouldBroadcast
{
    use Queueable, FilterNotificationChannels;

    public function __construct(
        public readonly AppRelease $release,
        public readonly string $notificationTitle,
        public readonly string $notificationBody,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if (! empty($notifiable->fcm_token)) {
            $channels[] = FcmChannel::class;
        }

        return $this->filterChannels($channels, $notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'type' => 'app_update',
            'title' => $this->notificationTitle,
            'message' => $this->notificationBody,
            'body' => $this->notificationBody,
            'channel' => $this->release->channel,
            'version_name' => $this->release->version_name,
            'build_number' => (string) $this->release->build_number,
            'force_update' => '0',
            'release_id' => $this->release->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => $this->notificationTitle,
            'body' => $this->notificationBody,
            'type' => 'app_update',
            'data' => $this->payload(),
        ];
    }
}
