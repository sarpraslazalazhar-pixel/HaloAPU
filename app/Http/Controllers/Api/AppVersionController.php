<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AppRelease;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class AppVersionController extends Controller
{
    /**
     * Public endpoint consumed by the mobile app to check for updates.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['nullable', 'string', Rule::in(AppRelease::PLATFORMS)],
            'channel' => ['nullable', 'string', Rule::in(AppRelease::CHANNELS)],
        ]);

        $platform = $validated['platform'] ?? 'android';
        $channel = $validated['channel'] ?? 'production';
        $audience = $this->resolveAudience($request);

        $release = AppRelease::getActiveRelease($platform, $channel, $audience);

        return response()->json([
            'data' => $release?->toApiPayload(),
        ]);
    }

    /**
     * Resolve release audience from optional Sanctum Bearer token (never returning 401).
     */
    private function resolveAudience(Request $request): string
    {
        $account = null;

        if ($bearer = $request->bearerToken()) {
            $accessToken = PersonalAccessToken::findToken($bearer);
            if ($accessToken !== null) {
                $expiration = config('sanctum.expiration');
                $isExpired = ($accessToken->expires_at !== null && $accessToken->expires_at->isPast())
                    || (is_numeric($expiration)
                        && $accessToken->created_at !== null
                        && $accessToken->created_at->lte(now()->subMinutes((int) $expiration)));

                if (! $isExpired) {
                    $account = $accessToken->tokenable;
                }
            }
        } elseif (! Auth::guard('web')->check()) {
            // Supports Sanctum::actingAs() in automated tests without leaking web session cookies.
            $account = $request->user('sanctum');
        }

        if ($account instanceof Admin) {
            return AppRelease::TARGET_ADMINS;
        }

        if ($account instanceof User && (bool) $account->is_active) {
            return AppRelease::TARGET_USERS;
        }

        return AppRelease::AUDIENCE_GUEST;
    }

    /**
     * Internal webhook endpoint for CI/CD (e.g., GitHub Actions) to register releases.
     */
    public function storeInternal(Request $request): JsonResponse
    {
        $secret = (string) config('services.app_release.webhook_secret', '');

        if ($secret === '') {
            return response()->json([
                'message' => 'Release webhook secret belum dikonfigurasi.',
            ], 503);
        }

        $token = (string) $request->header('X-Release-Token', '');

        if ($token === '' || ! hash_equals($secret, $token)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        $platform = (string) $request->input('platform', 'android');

        $validated = $request->validate([
            'platform' => ['required', 'string', Rule::in(AppRelease::PLATFORMS)],
            'channel' => ['required', 'string', Rule::in(AppRelease::CHANNELS)],
            'target_audience' => ['nullable', 'string', Rule::in(AppRelease::TARGET_AUDIENCES)],
            'version_name' => ['required', 'string', 'max:50'],
            'build_number' => ['required', 'integer', 'min:1'],
            'min_supported_build_number' => ['nullable', 'integer', 'min:0'],
            'force_update' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:150'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
            'download_url' => AppRelease::downloadUrlRules(),
            'sha256' => AppRelease::sha256Rules($platform),
            'file_size_bytes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $shouldActivate = (bool) ($validated['is_active'] ?? false);

        $lookup = [
            'platform' => $validated['platform'],
            'channel' => $validated['channel'],
            'build_number' => (int) $validated['build_number'],
        ];

        $existing = AppRelease::query()->where($lookup)->first();

        $attributes = [
            'version_name' => trim($validated['version_name']),
            'min_supported_build_number' => 0,
            'force_update' => false,
            'title' => $validated['title'] ?? null,
            'release_notes' => $validated['release_notes'] ?? null,
            'download_url' => trim($validated['download_url']),
            'sha256' => ! empty($validated['sha256']) ? strtolower($validated['sha256']) : null,
            'file_size_bytes' => $validated['file_size_bytes'] ?? null,
        ];

        if (array_key_exists('target_audience', $validated) && $validated['target_audience'] !== null) {
            $attributes['target_audience'] = AppRelease::normalizeTargetAudience($validated['target_audience']);
        } elseif ($existing === null) {
            $attributes['target_audience'] = AppRelease::TARGET_ALL;
        }

        $release = AppRelease::updateOrCreate($lookup, $attributes);

        if ($shouldActivate) {
            $release->activate();
        }

        return response()->json([
            'data' => $release->fresh()->toApiPayload(),
            'message' => 'Rilis aplikasi berhasil disimpan.',
        ], $release->wasRecentlyCreated ? 201 : 200);
    }
}
