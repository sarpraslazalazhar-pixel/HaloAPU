<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    protected static bool $lastSendSucceeded = false;

    public static function resetLastSendStatus(): void
    {
        self::$lastSendSucceeded = false;
    }

    public static function lastSendSucceeded(): bool
    {
        return self::$lastSendSucceeded;
    }

    /**
     * Kirim notifikasi push FCM ke pengguna tertentu (User atau Admin).
     */
    public static function sendToUser($user, string $title, string $body, array $data = []): bool
    {
        self::$lastSendSucceeded = false;

        if (! $user || empty($user->fcm_token)) {
            Log::info('FCM Skip: User has no FCM token.', ['user_id' => $user->id ?? null]);

            return false;
        }

        return self::sendToToken((string) $user->fcm_token, $title, $body, $data);
    }

    /**
     * Kirim notifikasi push FCM ke token perangkat tertentu.
     * Memprioritaskan FCM HTTP v1 (Service Account JSON), lalu fallback ke Legacy Server Key.
     */
    public static function sendToToken(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        self::$lastSendSucceeded = false;

        if ($fcmToken === '') {
            return false;
        }

        $formattedData = self::stringifyDataPayload($data, $title, $body);

        // 1. Prioritaskan FCM HTTP v1 API bila service account JSON tersedia
        $serviceAccount = self::loadServiceAccountCredentials();
        if ($serviceAccount !== null) {
            return self::sendViaHttpV1($serviceAccount, $fcmToken, $title, $body, $formattedData);
        }

        // 2. Fallback ke Legacy FCM Server Key bila dikonfigurasi
        $serverKey = trim((string) config('services.fcm.key', ''));
        if ($serverKey !== '') {
            return self::sendViaLegacy($serverKey, $fcmToken, $title, $body, $formattedData);
        }

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
            return false;
        }

        $projectId = $credentials['project_id'];
        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

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
                    'notification' => [
                        'channel_id' => 'high_importance_channel',
                        'sound' => 'default',
                    ],
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

            Log::warning('FCM HTTP v1 notification failed', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
        } catch (\Throwable $e) {
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

            Log::warning('FCM Push Notification failed', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('FCM Push Notification error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string, token_uri: string}|null
     */
    private static function loadServiceAccountCredentials(): ?array
    {
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

        $rawJson = @file_get_contents($resolvedPath);
        if (! is_string($rawJson) || $rawJson === '') {
            return null;
        }

        $decoded = json_decode($rawJson, true);
        if (! is_array($decoded)) {
            Log::warning('FCM HTTP v1 credentials file contains invalid JSON.');

            return null;
        }

        $clientEmail = trim((string) ($decoded['client_email'] ?? ''));
        $privateKey = (string) ($decoded['private_key'] ?? '');
        $projectId = trim((string) ($decoded['project_id'] ?? config('services.fcm.project_id', '')));
        $tokenUri = trim((string) ($decoded['token_uri'] ?? self::DEFAULT_TOKEN_URI));

        if ($clientEmail === '' || $privateKey === '' || $projectId === '') {
            Log::warning('FCM HTTP v1 credentials missing required fields.');

            return null;
        }

        return [
            'project_id' => $projectId,
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
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
                Log::warning('FCM HTTP v1 OAuth2 token exchange failed', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $accessToken = trim((string) $response->json('access_token', ''));
            if ($accessToken === '') {
                return null;
            }

            Cache::put($cacheKey, $accessToken, 3000);

            return $accessToken;
        } catch (\Throwable $e) {
            Log::error('FCM HTTP v1 OAuth2 token exchange error: ' . $e->getMessage());

            return null;
        }
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
