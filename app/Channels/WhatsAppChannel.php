<?php

namespace App\Channels;

use App\Models\SystemConfig;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel
{
    /**
     * Kirim notifikasi via WhatsApp gateway.
     *
     * @param mixed $notifiable Model yang menerima notifikasi (Admin/User)
     * @param Notification $notification
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        // Panggil method toWhatsApp() dari notification class
        if (!method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $data = $notification->toWhatsApp($notifiable);

        // Ambil nomor WA penerima
        $phoneNumber = $data['receiver'] ?? $notifiable->no_wa ?? null;

        if (!$phoneNumber) {
            Log::warning("WhatsApp notification gagal: nomor WA tidak tersedia untuk {$notifiable->id}");
            return;
        }

        // Ambil konfigurasi gateway dari system_configs
        // Fallback or comment out for now if SystemConfig doesn't exist
        $gatewayUrl = class_exists(SystemConfig::class) ? SystemConfig::getValue('wa_gateway_url') : env('WA_GATEWAY_URL');
        $apiKey = class_exists(SystemConfig::class) ? SystemConfig::getValue('wa_api_key') : env('WA_API_KEY');

        if (!$gatewayUrl || !$apiKey) {
            Log::error('WhatsApp gateway belum dikonfigurasi (wa_gateway_url / wa_api_key)');
            return;
        }

        try {
            $response = Http::timeout(30)->post($gatewayUrl, [
                'api_key' => $apiKey,
                'receiver' => $phoneNumber,
                'data' => [
                    'message' => $data['message'],
                ],
            ]);

            if ($response->failed()) {
                Log::error("WhatsApp API error: {$response->status()} - {$response->body()}");
            } else {
                Log::info("WhatsApp notification terkirim ke {$phoneNumber}");
            }
        } catch (\Exception $e) {
            Log::error("WhatsApp notification exception: {$e->getMessage()}");
        }
    }
}
