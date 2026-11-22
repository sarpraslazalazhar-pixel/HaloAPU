<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        $release = AppRelease::getActiveRelease($platform, $channel);

        return response()->json([
            'data' => $release?->toApiPayload(),
        ]);
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

        $release = AppRelease::updateOrCreate(
            [
                'platform' => $validated['platform'],
                'channel' => $validated['channel'],
                'build_number' => (int) $validated['build_number'],
            ],
            [
                'version_name' => trim($validated['version_name']),
                'min_supported_build_number' => 0,
                'force_update' => false,
                'title' => $validated['title'] ?? null,
                'release_notes' => $validated['release_notes'] ?? null,
                'download_url' => trim($validated['download_url']),
                'sha256' => ! empty($validated['sha256']) ? strtolower($validated['sha256']) : null,
                'file_size_bytes' => $validated['file_size_bytes'] ?? null,
            ]
        );

        if ($shouldActivate) {
            $release->activate();
        }

        return response()->json([
            'data' => $release->fresh()->toApiPayload(),
            'message' => 'Rilis aplikasi berhasil disimpan.',
        ], $release->wasRecentlyCreated ? 201 : 200);
    }
}
