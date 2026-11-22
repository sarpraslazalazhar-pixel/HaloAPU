<?php

namespace App\Notifications;

use App\Channels\FcmChannel;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TestPushNotification extends Notification
{
    public function via($notifiable): array
    {
        $channels = ['database', WebPushChannel::class];

        if (is_object($notifiable) && ! empty($notifiable->fcm_token)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'test_push',
            'title' => 'Tes Notifikasi Halo APU',
            'message' => 'Push Notification Mobile (FCM) dan notifikasi aplikasi berfungsi dengan baik.',
            'body' => 'Push Notification Mobile (FCM) dan notifikasi aplikasi berfungsi dengan baik.',
            'url' => url('/'),
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'Tes Notifikasi Halo APU',
            'body' => 'Push Notification Mobile (FCM) dan notifikasi aplikasi berfungsi dengan baik.',
            'type' => 'test_push',
            'url' => url('/'),
        ];
    }

    public function toWebPush($notifiable, $notification)
    {
        return (new WebPushMessage)
            ->title('Test Notifikasi Berhasil! 🎉')
            ->icon('/favicon.ico')
            ->body('Halo! Jika kamu melihat popup ini, berarti integrasi sistem Push Notification berjalan dengan sempurna.')
            ->action('Tutup', url('/'));
    }
}
