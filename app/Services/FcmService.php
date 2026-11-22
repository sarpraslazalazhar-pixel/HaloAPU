<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\SystemConfig;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FcmService
{
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    protected static bool $lastSendSucceeded = false;
    protected static ?string $lastError = null;

    public static function resetLastSendStatus(): void
    {
        self::$lastSendSucceeded = false;
        self::$lastError = null;
    }

    public static function lastSendSucceeded(): bool
    {
        return self::$lastSendSucceeded;
    }

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Validate decoded Firebase Service Account JSON structure and RSA private key.
     */
    public static function validateServiceAccountArray(array $decoded): ?string
    {
        if (($decoded['type'] ?? null) !== 'service_account') {
            return 'File JSON bukan tipe service_account Firebase.';
        }

        if (trim((string) ($decoded['project_id'] ?? '')) === '') {
            return 'Field project_id pada Service Account JSON tidak ditemukan.';
        }

        if (trim((string) ($decoded['client_email'] ?? '')) === '') {
            return 'Field client_email pada Service Account JSON tidak ditemukan.';
        }

        $privateKey = (string) ($decoded['private_key'] ?? '');
        if (trim($privateKey) === '') {
            return 'Field private_key pada Service Account JSON tidak ditemukan.';
        }

        if (@openssl_pkey_get_private($privateKey) === false) {
            return 'Format private_key RSA pada Service Account JSON tidak valid.';
        }

        return null;
    }

    public static function clearCachedAccessToken(): void
    {
        $panelEmail = trim((string) SystemConfig::getValue('fcm_client_email', ''));
        $panelProject = trim((string) SystemConfig::getValue('fcm_project_id', ''));

        if ($panelEmail !== '' && $panelProject !== '') {
            Cache::forget('fcm_v1_access_token:' . sha1($panelEmail . '|' . $panelProject));
        }

        $credentials = self::resolveConfiguredCredentials(false);
        if ($credentials !== null) {
            Cache::forget(self::tokenCacheKey($credentials));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function getDiagnosticStatus(): array
    {
        $enabled = (bool) SystemConfig::getValue('fcm_enabled', true);
        $source = null;
        $credentials = self::resolveConfiguredCredentials(false, $source);

        $mode = 'unconfigured';
        $configured = false;
        $projectId = null;
        $clientEmailMasked = null;

        if ($credentials !== null && $source !== null) {
            $mode = $source;
            $configured = true;
            $projectId = $credentials['project_id'];
            $clientEmailMasked = self::maskEmail($credentials['client_email']);
        } elseif (trim((string) config('services.fcm.key', '')) !== '') {
            $mode = 'legacy_key';
            $configured = true;
            $projectId = trim((string) config('services.fcm.project_id', '')) ?: null;
        }

        return [
            'enabled' => $enabled,
            'configured' => $configured,
            'mode' => $mode,
            'project_id' => $projectId,
            'client_email_masked' => $clientEmailMasked,
            'uploaded_at' => SystemConfig::getValue('fcm_uploaded_at'),
            'registered_users_count' => User::query()
                ->whereNotNull('fcm_token')
                ->where('fcm_token', '!=', '')
                ->count(),
            'registered_admins_count' => Admin::query()
                ->whereNotNull('fcm_token')
                ->where('fcm_token', '!=', '')
                ->count(),
        ];
    }

    /**
     * Kirim notifikasi push FCM ke pengguna tertentu (User atau Admin).
     */
    public static function sendToUser($user, string $title, string $body, array $data = []): bool
    {
        self::resetLastSendStatus();

        if (! $user || empty($user->fcm_token)) {
            self::$lastError = 'Pengguna belum memiliki fcm_token terdaftar.';
            Log::info('FCM Skip: User has no FCM token.', ['user_id' => $user->id ?? null]);

            return false;
        }

        return self::sendToToken((string) $user->fcm_token, $title, $body, $data);
    }

    /**
     * Kirim notifikasi push FCM ke token perangkat tertentu.
     * Memprioritaskan FCM HTTP v1 (Panel Admin / .env Service Account JSON), lalu fallback ke Legacy Server Key.
     */
    public static function sendToToken(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        self::resetLastSendStatus();

        if ($fcmToken === '') {
            self::$lastError = 'Token FCM perangkat kosong.';

            return false;
        }

        if ((bool) SystemConfig::getValue('fcm_enabled', true) === false) {
            self::$lastError = 'Push notification FCM sedang dinonaktifkan di konfigurasi sistem.';

            return false;
        }

        $formattedData = self::stringifyDataPayload($data, $title, $body);

        // 1. Prioritaskan FCM HTTP v1 API bila service account JSON tersedia (Panel Admin atau .env)
        $serviceAccount = self::loadServiceAccountCredentials();
        if ($serviceAccount !== null) {
            return self::sendViaHttpV1($serviceAccount, $fcmToken, $title, $body, $formattedData);
        }

        // 2. Fallback ke Legacy FCM Server Key bila dikonfigurasi
        $serverKey = trim((string) config('services.fcm.key', ''));
        if ($serverKey !== '') {
            return self::sendViaLegacy($serverKey, $fcmToken, $title, $body, $formattedData);
        }

        self::$lastError = 'Kredensial Firebase Service Account JSON belum dikonfigurasi.';
        Log::info('FCM notification prepared (no FIREBASE_CREDENTIALS or FCM_SERVER_KEY configured):', [
            'token' => substr($fcmToken, 0, 15) . '...',
            'title' => $title,
            'body' => $body,
            'data' => $formattedData,
        ]);

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function stringifyDataPayload(array $data, string $title, string $body): array
    {
        $formattedData = [];

        foreach ($data as $key => $value) {
            $formattedData[(string) $key] = is_array($value) || is_object($value)
                ? (json_encode($value) ?: '')
                : (string) $value;
        }

        $formattedData['title'] = $title;
        $formattedData['body'] = $body;

        return $formattedData;
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string, token_uri: string}  $credentials
     * @param  array<string, string>  $formattedData
     */
    private static function sendViaHttpV1(
        array $credentials,
        string $fcmToken,
        string $title,
        string $body,
        array $formattedData
    ): bool {
        $accessToken = self::getHttpV1AccessToken($credentials);
        if ($accessToken === null) {
            self::$lastError ??= 'Gagal memperoleh OAuth2 access token dari Google.';

            return false;
        }

        $projectId = $credentials['project_id'];
        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $androidNotification = [
            'channel_id' => 'high_importance_channel',
            'sound' => 'default',
        ];

        if (! empty($formattedData['notification_id'])) {
            $androidNotification['tag'] = $formattedData['notification_id'];
        }

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $formattedData,
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => $androidNotification,
                ],
            ],
        ];

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(10)
                ->post($endpoint, $payload);

            if ($response->status() === 401) {
                Cache::forget(self::tokenCacheKey($credentials));
            }

            if ($response->successful()) {
                Log::info('FCM HTTP v1 notification sent successfully', [
                    'title' => $title,
                    'project_id' => $projectId,
                ]);
                self::$lastSendSucceeded = true;

                return true;
            }

            $errorDetail = trim((string) ($response->json('error.message') ?? ''));
            self::$lastError = $errorDetail !== ''
                ? "FCM HTTP {$response->status()}: {$errorDetail}"
                : "FCM HTTP {$response->status()}.";

            Log::warning('FCM HTTP v1 notification failed', [
                'status' => $response->status(),
                'project_id' => $projectId,
                'error_message' => $errorDetail,
            ]);
        } catch (\Throwable $e) {
            self::$lastError = 'Kesalahan jaringan saat mengirim FCM HTTP v1: ' . $e->getMessage();
            Log::error('FCM HTTP v1 error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * @param  array<string, string>  $formattedData
     */
    private static function sendViaLegacy(
        string $serverKey,
        string $fcmToken,
        string $title,
        string $body,
        array $formattedData
    ): bool {
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
            ])->timeout(10)->post('https://fcm.googleapis.com/fcm/send', $payload);

            if ($response->successful()) {
                Log::info('FCM Push Notification sent successfully', [
                    'title' => $title,
                    'response' => $response->json(),
                ]);
                self::$lastSendSucceeded = true;

                return true;
            }

            self::$lastError = "FCM Legacy HTTP {$response->status()}.";
            Log::warning('FCM Push Notification failed', [
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            self::$lastError = 'Kesalahan jaringan saat mengirim FCM: ' . $e->getMessage();
            Log::error('FCM Push Notification error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string, token_uri: string}|null
     */
    private static function loadServiceAccountCredentials(): ?array
    {
        return self::resolveConfiguredCredentials(true);
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string, token_uri: string}|null
     */
    private static function resolveConfiguredCredentials(bool $respectEnabled, ?string &$source = null): ?array
    {
        if ($respectEnabled && (bool) SystemConfig::getValue('fcm_enabled', true) === false) {
            return null;
        }

        // 1. Cek file yang diunggah melalui Admin Panel (storage/app/private/firebase/service-account.json)
        $panelPath = trim((string) SystemConfig::getValue('fcm_service_account_path', ''));
        if ($panelPath !== '' && Storage::disk('local')->exists($panelPath)) {
            $parsed = self::parseCredentialsFile(Storage::disk('local')->path($panelPath));
            if ($parsed !== null) {
                $source = 'http_v1_panel';

                return $parsed;
            }
        }

        // 2. Fallback ke FIREBASE_CREDENTIALS di .env
        $configuredPath = trim((string) config('services.fcm.service_account_json', ''));
        if ($configuredPath === '') {
            return null;
        }

        $resolvedPath = self::resolveCredentialsFilePath($configuredPath);
        if ($resolvedPath === null) {
            Log::warning('FCM HTTP v1 credentials file not found.', [
                'configured_path' => basename($configuredPath),
            ]);

            return null;
        }

        $parsed = self::parseCredentialsFile($resolvedPath);
        if ($parsed !== null) {
            $source = 'http_v1_env';

            return $parsed;
        }

        return null;
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string, token_uri: string}|null
     */
    private static function parseCredentialsFile(string $resolvedPath): ?array
    {
        $rawJson = @file_get_contents($resolvedPath);
        if (! is_string($rawJson) || $rawJson === '') {
            return null;
        }

        $decoded = json_decode($rawJson, true);
        if (! is_array($decoded)) {
            Log::warning('FCM HTTP v1 credentials file contains invalid JSON.');

            return null;
        }

        $validationError = self::validateServiceAccountArray($decoded);
        if ($validationError !== null) {
            Log::warning('FCM HTTP v1 credentials file failed validation.', [
                'reason' => $validationError,
            ]);

            return null;
        }

        $tokenUri = trim((string) ($decoded['token_uri'] ?? self::DEFAULT_TOKEN_URI));

        return [
            'project_id' => trim((string) $decoded['project_id']),
            'client_email' => trim((string) $decoded['client_email']),
            'private_key' => (string) $decoded['private_key'],
            'token_uri' => $tokenUri !== '' ? $tokenUri : self::DEFAULT_TOKEN_URI,
        ];
    }

    private static function resolveCredentialsFilePath(string $configuredPath): ?string
    {
        $candidates = [
            $configuredPath,
            base_path($configuredPath),
            storage_path($configuredPath),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string, token_uri: string}  $credentials
     */
    private static function tokenCacheKey(array $credentials): string
    {
        return 'fcm_v1_access_token:' . sha1($credentials['client_email'] . '|' . $credentials['project_id']);
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string, token_uri: string}  $credentials
     */
    private static function getHttpV1AccessToken(array $credentials): ?string
    {
        $cacheKey = self::tokenCacheKey($credentials);
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $header = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']) ?: '{}');
        $claims = self::base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::FCM_SCOPE,
            'aud' => $credentials['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]) ?: '{}');

        $signingInput = "{$header}.{$claims}";
        $signature = '';

        if (! openssl_sign($signingInput, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            self::$lastError = 'Gagal menandatangani JWT RSA256 dengan private key Firebase.';
            Log::error('FCM HTTP v1 failed to sign JWT assertion with service account private key.');

            return null;
        }

        $jwt = $signingInput . '.' . self::base64UrlEncode($signature);

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(10)
                ->post($credentials['token_uri'], [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

            if (! $response->successful()) {
                self::$lastError = "Google OAuth2 gagal (HTTP {$response->status()}).";
                Log::warning('FCM HTTP v1 OAuth2 token exchange failed', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $accessToken = trim((string) $response->json('access_token', ''));
            if ($accessToken === '') {
                self::$lastError = 'Respons OAuth2 Google tidak menyertakan access_token.';

                return null;
            }

            Cache::put($cacheKey, $accessToken, 3000);

            return $accessToken;
        } catch (\Throwable $e) {
            self::$lastError = 'Kesalahan jaringan saat pertukaran token OAuth2 Google: ' . $e->getMessage();
            Log::error('FCM HTTP v1 OAuth2 token exchange error: ' . $e->getMessage());

            return null;
        }
    }

    private static function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }

        $local = $parts[0];
        $prefix = substr($local, 0, min(6, strlen($local)));

        return $prefix . '***@' . $parts[1];
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
