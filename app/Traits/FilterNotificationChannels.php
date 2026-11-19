<?php

namespace App\Traits;

use NotificationChannels\WebPush\WebPushChannel;

trait FilterNotificationChannels
{
    /**
     * Filter channels based on recipient's notification preferences (notify_inapp & notify_browser).
     */
    protected function filterChannels(array $channels, mixed $notifiable): array
    {
        // Shared hosting (cPanel) runs without Reverb/Pusher. Broadcasting to a
        // dead driver would make every notification fail, so drop the channel.
        $broadcastDriver = config('broadcasting.default');
        if (in_array($broadcastDriver, [null, 'null', 'log'], true)) {
            $channels = array_values(array_filter($channels, fn ($c) => $c !== 'broadcast'));
        }

        if (is_object($notifiable)) {
            if (isset($notifiable->notify_inapp) && ($notifiable->notify_inapp === false || $notifiable->notify_inapp === 0)) {
                $channels = array_values(array_filter($channels, fn($c) => $c !== 'database'));
            }
            if (isset($notifiable->notify_browser) && ($notifiable->notify_browser === false || $notifiable->notify_browser === 0)) {
                $channels = array_values(array_filter($channels, fn($c) => $c !== WebPushChannel::class));
            }
        }
        return $channels;
    }
}
