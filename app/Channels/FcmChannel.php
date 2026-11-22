<?php

namespace App\Channels;

use App\Services\FcmService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class FcmChannel
{
    /**
     * Kirim notifikasi via Firebase Cloud Messaging (FCM).
     *
     * @param mixed $notifiable Model penerima notifikasi (User / Admin)
     * @param Notification $notification
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (!is_object($notifiable) || empty($notifiable->fcm_token)) {
            return;
        }

        $payload = null;

        if (method_exists($notification, 'toFcm')) {
            $payload = $notification->toFcm($notifiable);
        } elseif (method_exists($notification, 'toArray')) {
            $arrayData = $notification->toArray($notifiable);
            $payload = [
                'title' => $arrayData['title'] ?? $arrayData['judul'] ?? 'Halo APU',
                'body' => $arrayData['message'] ?? $arrayData['body'] ?? $arrayData['pesan'] ?? 'Ada pembaruan tiket baru',
                'data' => $arrayData,
            ];
        }

        if (empty($payload)) {
            return;
        }

        $title = $payload['title'] ?? 'Halo APU';
        $body = $payload['body'] ?? $payload['message'] ?? '';
        $data = $payload['data'] ?? [];

        // Pastikan ticket_id, id, dan notification_id tersertakan di payload data
        if (isset($payload['ticket_id']) && !isset($data['ticket_id'])) {
            $data['ticket_id'] = $payload['ticket_id'];
        }
        if (isset($payload['url']) && !isset($data['url'])) {
            $data['url'] = $payload['url'];
        }
        if (isset($payload['type']) && !isset($data['type'])) {
            $data['type'] = $payload['type'];
        }
        if (!empty($notification->id)) {
            if (!isset($data['notification_id'])) {
                $data['notification_id'] = (string) $notification->id;
            }
            if (!isset($data['id'])) {
                $data['id'] = (string) $notification->id;
            }
        }

        try {
            FcmService::sendToUser($notifiable, $title, $body, $data);
        } catch (\Throwable $e) {
            Log::error('FcmChannel send error: ' . $e->getMessage());
        }
    }
}
