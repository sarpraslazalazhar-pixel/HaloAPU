<?php

namespace App\Console\Commands;

use App\Channels\WhatsAppChannel;
use App\Models\SystemConfig;
use Illuminate\Console\Command;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class TestWhatsAppCommand extends Command
{
    protected $signature = 'wa:test {phone=6283146564122}';
    protected $description = 'Tes pengiriman notifikasi WhatsApp dan cek status gateway';

    public function handle(): int
    {
        $phone = $this->argument('phone');
        $phone = preg_replace('/\D/', '', $phone);
        $phone = preg_replace('/^0/', '62', $phone);

        $gatewayUrl = trim((string) SystemConfig::getValue('wa_gateway_url')) ?: 'https://api.watzap.id/v1/send_message';
        $apiKey = trim((string) SystemConfig::getValue('wa_api_key'));
        $numberKey = trim((string) SystemConfig::getValue('wa_number_key'));
        $enabled = SystemConfig::getValue('wa_notification_enabled', true);

        $this->info("--- Konfigurasi WhatsApp Saat Ini ---");
        $this->line("Gateway URL : {$gatewayUrl}");
        $this->line("API Key     : " . ($apiKey ? substr($apiKey, 0, 8) . '...' : '(KOSONG)'));
        $this->line("Number Key  : " . ($numberKey ? substr($numberKey, 0, 8) . '...' : '(KOSONG - Mode Custom Gateway)'));
        $this->line("Status Aktif: " . ($enabled ? 'YA' : 'TIDAK'));
        $this->line("Target No WA: {$phone}");
        $this->newLine();

        if (!$apiKey) {
            $this->error('ERROR: wa_api_key belum diisi di database!');
            return 1;
        }

        $this->info("1. Menguji koneksi langsung ke Gateway HTTP...");
        try {
            $req = Http::timeout(15)->withoutVerifying();

            if (!empty($numberKey)) {
                $response = $req->post($gatewayUrl, [
                    'api_key' => $apiKey,
                    'number_key' => $numberKey,
                    'phone_no' => $phone,
                    'message' => 'Tes WA Halo APU (Watzap mode)',
                ]);
            } else {
                $response = $req->withHeaders([
                    'x-api-key' => $apiKey,
                    'Authorization' => 'Bearer ' . $apiKey,
                ])->post($gatewayUrl, [
                    'to' => $phone,
                    'message' => 'Tes notifikasi WhatsApp langsung dari Halo APU.',
                ]);
            }

            $this->line("HTTP Status : " . $response->status());
            $this->line("Response    : " . $response->body());

            if ($response->successful()) {
                $this->info("-> Sukses terhubung ke gateway!");
            } else {
                $this->error("-> Gateway merespons error!");
            }
        } catch (\Throwable $e) {
            $this->error("-> Gagal koneksi HTTP: " . $e->getMessage());
            return 1;
        }

        $this->newLine();
        $this->info("2. Menguji via WhatsAppChannel::send()...");
        try {
            $channel = new WhatsAppChannel();
            $notification = new class($phone) extends Notification {
                public function __construct(private string $target) {}
                public function toWhatsApp(object $notifiable): array
                {
                    return [
                        'receiver' => $this->target,
                        'message' => 'Tes notifikasi via WhatsAppChannel Halo APU.',
                    ];
                }
            };

            $channel->send(new AnonymousNotifiable, $notification);
            $this->info("-> Eksekusi WhatsAppChannel selesai. Cek log atau WhatsApp Anda.");
        } catch (\Throwable $e) {
            $this->error("-> Error di WhatsAppChannel: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
