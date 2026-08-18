<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    /**
     * Kirim notifikasi push FCM ke pengguna tertentu (User atau Admin).
     */
    public static function sendToUser($user, string $title, string $body, array $data = []): bool
    {
        if (!$user || empty($user->fcm_token)) {
            Log::info("FCM Skip: User has no FCM token.", ['user_id' => $user->id ?? null]);
            return false;
        }

        return self::sendToToken($user->fcm_token, $title, $body, $data);
    }

    /**
     * Kirim notifikasi push FCM ke token perangkat tertentu.
     */
    public static function sendToToken(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        if (empty($fcmToken)) {
            return false;
        }

        $serverKey = config('services.fcm.key');

        // Pastikan semua nilai dalam array $data bertipe string untuk FCM payload
        $formattedData = [];
        foreach ($data as $key => $value) {
            $formattedData[$key] = is_array($value) || is_object($value) ? json_encode($value) : (string) $value;
        }
        $formattedData['title'] = $title;
        $formattedData['body'] = $body;

        // 1. Jika FCM Server Key (Legacy / Server Key) tersedia di konfigurasi
        if (!empty($serverKey)) {
            try {
                $payload = [
                    'to' => $fcmToken,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'sound' => 'default',
                        'badge' => 1,
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        'channel_id' => 'high_importance_channel',
                    ],
                    'data' => $formattedData,
                    'priority' => 'high',
                    'content_available' => true,
                ];

                $response = Http::withHeaders([
                    'Authorization' => 'key=' . $serverKey,
                    'Content-Type' => 'application/json',
                ])->post('https://fcm.googleapis.com/fcm/send', $payload);

                if ($response->successful()) {
                    Log::info('FCM Push Notification sent successfully', [
                        'title' => $title,
                        'response' => $response->json(),
                    ]);
                    return true;
                } else {
                    Log::warning('FCM Push Notification failed', [
                        'status' => $response->status(),
                        'response' => $response->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('FCM Push Notification error: ' . $e->getMessage());
            }
        } else {
            // Jika FCM Server Key belum diatur di .env, tetap catat log agar developer mudah melacak
            Log::info('FCM notification prepared (FCM_SERVER_KEY is not configured in .env):', [
                'token' => substr($fcmToken, 0, 15) . '...',
                'title' => $title,
                'body' => $body,
                'data' => $formattedData,
            ]);
        }

        return false;
    }
}
