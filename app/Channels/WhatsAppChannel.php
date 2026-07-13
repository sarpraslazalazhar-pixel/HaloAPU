<?php

namespace App\Channels;

use App\Models\SystemConfig;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel
{
    /**
     * Kirim notifikasi via WhatsApp gateway (Watzap.id).
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
            $notifiableId = $notifiable->id ?? 'Anonymous';
            Log::warning("WhatsApp notification gagal: nomor WA tidak tersedia untuk {$notifiableId}");
            return;
        }

        // Normalisasi nomor: ubah awalan 08 menjadi 628
        $phoneNumber = preg_replace('/^0/', '62', $phoneNumber);

        // Ambil konfigurasi gateway dari system_configs
        $gatewayUrl = SystemConfig::getValue('wa_gateway_url', 'https://api.watzap.id/v1/send_message');
        $apiKey = SystemConfig::getValue('wa_api_key');
        $numberKey = SystemConfig::getValue('wa_number_key');

        if (!$apiKey || !$numberKey) {
            Log::error('WhatsApp gateway belum dikonfigurasi (wa_api_key / wa_number_key)');
            return;
        }

        try {
            $response = Http::timeout(30)->post($gatewayUrl, [
                'api_key' => $apiKey,
                'number_key' => $numberKey,
                'phone_no' => $phoneNumber,
                'message' => $data['message'],
            ]);

            if ($response->failed()) {
                Log::error("WhatsApp API error: {$response->status()} - {$response->body()}");
            } else {
                Log::info("WhatsApp notification terkirim ke {$phoneNumber} - Response: {$response->body()}");
            }
        } catch (\Exception $e) {
            Log::error("WhatsApp notification exception: {$e->getMessage()}");
        }
    }
}
