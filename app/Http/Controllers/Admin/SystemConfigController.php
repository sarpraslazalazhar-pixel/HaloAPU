<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\SystemConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class SystemConfigController extends Controller
{
    public function index()
    {
        $configs = [
            'nama_sistem' => SystemConfig::getValue('nama_sistem', 'Halo APU'),
            'logo_path' => SystemConfig::getValue('logo_path'),
            'favicon_path' => SystemConfig::getValue('favicon_path'),
            'banner_path' => SystemConfig::getValue('banner_path'),
            'notification_sound_path' => SystemConfig::getValue('notification_sound_path'),
            'email_admin' => SystemConfig::getValue('email_admin', 'sarpras@alazharpeduli.or.id'),
            'wa_api_key' => SystemConfig::getValue('wa_api_key'),
            'wa_number_key' => SystemConfig::getValue('wa_number_key'),
            'wa_gateway_url' => SystemConfig::getValue('wa_gateway_url'),
            'wa_notification_enabled' => (bool) SystemConfig::getValue('wa_notification_enabled', true),
            'nomor_wa_utama' => SystemConfig::getValue('nomor_wa_utama'),
            'nomor_wa_fallback' => SystemConfig::getValue('nomor_wa_fallback'),
            'device_lock_enabled' => (bool) SystemConfig::getValue('device_lock_enabled', true),
            'app_timezone' => SystemConfig::getValue('app_timezone', 'Asia/Jakarta'),
            'jam_kerja' => SystemConfig::getValue('jam_kerja', [
                'senin' => ['08:00', '16:00'],
                'selasa' => ['08:00', '16:00'],
                'rabu' => ['08:00', '16:00'],
                'kamis' => ['08:00', '16:00'],
                'jumat' => ['08:00', '16:00'],
                'sabtu' => null,
                'minggu' => null,
            ]),
            'sparc_integration_enabled' => (bool) SystemConfig::getValue('sparc_integration_enabled', false),
            'sparc_base_url' => SystemConfig::getValue('sparc_base_url', ''),
            'sparc_api_token' => SystemConfig::getValue('sparc_api_token', ''),
            'sparc_webhook_secret' => SystemConfig::getValue('sparc_webhook_secret', ''),
            'sparc_default_admin_id' => SystemConfig::getValue('sparc_default_admin_id'),
            'sparc_filter_rules' => SystemConfig::getValue('sparc_filter_rules', []),
        ];

        $humasAdmins = Admin::whereHas('units', fn ($q) => $q->where('nama_unit', 'Humas'))
            ->select('id', 'name', 'username')
            ->get();

        return Inertia::render('Admin/Konfigurasi/Index', [
            'configs' => $configs,
            'humasAdmins' => $humasAdmins,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'nama_sistem' => 'required|string|max:100',
            'email_admin' => 'nullable|email|max:100',
            'wa_api_key' => 'nullable|string|max:255',
            'wa_number_key' => 'nullable|string|max:255',
            'wa_gateway_url' => 'nullable|string|max:255',
            'wa_notification_enabled' => 'nullable|boolean',
            'device_lock_enabled' => 'nullable|boolean',
            'nomor_wa_utama' => 'nullable|string|max:20',
            'nomor_wa_fallback' => 'nullable|string|max:20',
            'app_timezone' => 'required|string|max:50|timezone',
            'jam_kerja' => 'required|array',
            'jam_kerja.senin' => 'nullable|array|size:2',
            'jam_kerja.selasa' => 'nullable|array|size:2',
            'jam_kerja.rabu' => 'nullable|array|size:2',
            'jam_kerja.kamis' => 'nullable|array|size:2',
            'jam_kerja.jumat' => 'nullable|array|size:2',
            'jam_kerja.sabtu' => 'nullable|array|size:2',
            'jam_kerja.minggu' => 'nullable|array|size:2',
            'sparc_integration_enabled' => 'nullable|boolean',
            'sparc_base_url' => 'nullable|url|max:255',
            'sparc_api_token' => 'nullable|string|max:255',
            'sparc_webhook_secret' => 'nullable|string|max:255',
            'sparc_default_admin_id' => 'nullable|integer|exists:admins,id',
            'sparc_filter_rules' => 'nullable|array',
        ]);

        SystemConfig::setValue('nama_sistem', $validated['nama_sistem']);
        SystemConfig::setValue('email_admin', $validated['email_admin']);
        SystemConfig::setValue('wa_api_key', $validated['wa_api_key']);
        SystemConfig::setValue('wa_number_key', $validated['wa_number_key']);
        SystemConfig::setValue('wa_gateway_url', $validated['wa_gateway_url']);
        SystemConfig::setValue('wa_notification_enabled', $request->boolean('wa_notification_enabled'));
        SystemConfig::setValue('device_lock_enabled', $request->boolean('device_lock_enabled', true));
        SystemConfig::setValue('nomor_wa_utama', $validated['nomor_wa_utama']);
        SystemConfig::setValue('nomor_wa_fallback', $validated['nomor_wa_fallback']);
        SystemConfig::setValue('app_timezone', $validated['app_timezone']);
        SystemConfig::setValue('jam_kerja', $validated['jam_kerja']);
        if ($request->has('sparc_integration_enabled')) {
            SystemConfig::setValue('sparc_integration_enabled', $request->boolean('sparc_integration_enabled'));
        }
        if ($request->has('sparc_base_url')) {
            SystemConfig::setValue('sparc_base_url', $validated['sparc_base_url']);
        }
        if ($request->has('sparc_api_token')) {
            SystemConfig::setValue('sparc_api_token', $validated['sparc_api_token']);
        }
        if ($request->has('sparc_webhook_secret')) {
            SystemConfig::setValue('sparc_webhook_secret', $validated['sparc_webhook_secret']);
        }
        if ($request->has('sparc_default_admin_id')) {
            SystemConfig::setValue('sparc_default_admin_id', $validated['sparc_default_admin_id']);
        }
        if ($request->has('sparc_filter_rules')) {
            SystemConfig::setValue('sparc_filter_rules', $validated['sparc_filter_rules']);
        }

        return back()->with('success', 'Konfigurasi berhasil disimpan.');
    }

    public function testSparcConnection(Request $request): JsonResponse
    {
        $baseUrl = rtrim((string) ($request->input('sparc_base_url') ?: SystemConfig::getValue('sparc_base_url', '')), '/');
        $secret = (string) ($request->input('sparc_webhook_secret') ?: SystemConfig::getValue('sparc_webhook_secret', ''));

        if (empty($baseUrl)) {
            return response()->json([
                'success' => false,
                'message' => 'Base URL SPARC belum diisi.',
            ], 422);
        }

        $payload = [
            'event' => 'ping',
            'ticket' => [],
            'sent_at' => now()->toIso8601String(),
        ];
        $json = json_encode($payload) ?: '{}';
        $sig = 'sha256='.hash_hmac('sha256', $json, $secret);

        $start = microtime(true);
        try {
            $response = Http::withHeaders([
                'X-HaloAPU-Event' => 'ping',
                'X-HaloAPU-Timestamp' => (string) time(),
                'X-HaloAPU-Signature' => $sig,
                'Accept' => 'application/json',
            ])->timeout(8)->post("{$baseUrl}/api/webhooks/haloapu", $payload);

            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => "Koneksi ke SPARC webhook berhasil (HTTP {$response->status()}, latency {$latency}ms).",
                    'latency_ms' => $latency,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => "SPARC merespons dengan HTTP {$response->status()}: ".Str::limit($response->body(), 120),
                'latency_ms' => $latency,
            ], 400);
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghubungi server SPARC: '.$e->getMessage(),
                'latency_ms' => $latency,
            ], 500);
        }
    }

    public function generateSparcCredentials(Request $request): JsonResponse
    {
        $token = 'sparc_'.bin2hex(random_bytes(24));
        $secret = 'whsec_'.bin2hex(random_bytes(24));

        SystemConfig::setValue('sparc_api_token', $token);
        SystemConfig::setValue('sparc_webhook_secret', $secret);

        return response()->json([
            'success' => true,
            'message' => 'Kredensial integrasi SPARC berhasil di-generate.',
            'data' => [
                'api_token' => $token,
                'webhook_secret' => $secret,
            ],
        ]);
    }

    public function testWa(Request $request)
    {
        $request->validate([
            'target_phone' => 'required|string',
        ]);

        $phoneNumber = preg_replace('/\D/', '', $request->target_phone);
        $phoneNumber = preg_replace('/^0/', '62', $phoneNumber);

        $gatewayUrl = trim((string) SystemConfig::getValue('wa_gateway_url')) ?: 'https://api.watzap.id/v1/send_message';
        $apiKey = trim((string) SystemConfig::getValue('wa_api_key'));
        $numberKey = trim((string) SystemConfig::getValue('wa_number_key'));

        if (! $apiKey) {
            return back()->with('error', 'API Key WhatsApp belum diisi di konfigurasi.');
        }

        try {
            $req = Http::timeout(15)->withoutVerifying();

            if (! empty($numberKey)) {
                $response = $req->post($gatewayUrl, [
                    'api_key' => $apiKey,
                    'number_key' => $numberKey,
                    'phone_no' => $phoneNumber,
                    'message' => 'Tes notifikasi WhatsApp dari Halo APU.',
                ]);
            } else {
                $response = $req->withHeaders([
                    'x-api-key' => $apiKey,
                    'Authorization' => 'Bearer '.$apiKey,
                ])->post($gatewayUrl, [
                    'to' => $phoneNumber,
                    'message' => 'Tes notifikasi WhatsApp dari Halo APU.',
                ]);
            }

            if ($response->successful()) {
                return back()->with('success', 'Pesan tes WA berhasil dikirim ke '.$phoneNumber.': '.$response->body());
            }

            return back()->with('error', 'Gateway WA merespons error ('.$response->status().'): '.$response->body());
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal koneksi ke gateway WA: '.$e->getMessage());
        }
    }

    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg,svg|max:2048',
        ]);

        $oldPath = SystemConfig::getValue('logo_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $file = $request->file('logo');
        $filename = $file->hashName();
        Storage::disk('public')->put('branding/'.$filename, file_get_contents($file->getPathname()));
        $path = 'branding/'.$filename;

        SystemConfig::setValue('logo_path', $path);

        return back()->with('success', 'Logo berhasil diunggah.');
    }

    public function uploadBanner(Request $request)
    {
        $request->validate([
            'banner' => 'required|image|mimes:png,jpg,jpeg|max:5120',
        ]);

        $oldPath = SystemConfig::getValue('banner_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $file = $request->file('banner');
        $filename = $file->hashName();
        Storage::disk('public')->put('branding/'.$filename, file_get_contents($file->getPathname()));
        $path = 'branding/'.$filename;

        SystemConfig::setValue('banner_path', $path);

        return back()->with('success', 'Background Login berhasil diunggah.');
    }

    public function uploadFavicon(Request $request)
    {
        $request->validate([
            'favicon' => 'required|image|mimes:png,jpg,jpeg,ico,svg|max:2048',
        ]);

        $oldPath = SystemConfig::getValue('favicon_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $file = $request->file('favicon');
        $filename = $file->hashName();
        Storage::disk('public')->put('branding/'.$filename, file_get_contents($file->getPathname()));
        $path = 'branding/'.$filename;

        SystemConfig::setValue('favicon_path', $path);

        return back()->with('success', 'Favicon berhasil diunggah.');
    }

    public function uploadSound(Request $request)
    {
        $request->validate([
            'sound' => 'required|file|mimes:mp3,wav|max:5120',
        ]);

        $oldPath = SystemConfig::getValue('notification_sound_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $file = $request->file('sound');
        $filename = $file->hashName();
        Storage::disk('public')->put('branding/'.$filename, file_get_contents($file->getPathname()));
        $path = 'branding/'.$filename;

        SystemConfig::setValue('notification_sound_path', $path);

        return back()->with('success', 'Suara Notifikasi berhasil diunggah.');
    }

    public function serveNotificationSound()
    {
        $path = SystemConfig::getValue('notification_sound_path');
        if ($path && Storage::disk('public')->exists($path)) {
            $filePath = Storage::disk('public')->path($path);
            $mimeType = $this->getAudioMimeType($filePath);

            return response()->file($filePath, [
                'Content-Type' => $mimeType,
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        $defaultPath = public_path('sounds/ting-ting-ting.wav');
        if (file_exists($defaultPath)) {
            return response()->file($defaultPath, [
                'Content-Type' => 'audio/wav',
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        abort(404);
    }

    private function getAudioMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'aac', 'm4a' => 'audio/aac',
            'webm' => 'audio/webm',
            default => 'audio/mpeg',
        };
    }
}
