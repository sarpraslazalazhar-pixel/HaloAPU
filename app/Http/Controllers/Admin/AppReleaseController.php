<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AppRelease;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AppReleaseController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $platform = (string) $request->input('platform', 'android');
        $channel = (string) $request->input('channel', 'production');

        $validated = $request->validate([
            'platform' => ['required', 'string', Rule::in(AppRelease::PLATFORMS)],
            'channel' => ['required', 'string', Rule::in(AppRelease::CHANNELS)],
            'version_name' => ['required', 'string', 'max:50'],
            'build_number' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('app_releases', 'build_number')->where(
                    fn ($query) => $query->where('platform', $platform)->where('channel', $channel)
                ),
            ],
            'min_supported_build_number' => ['nullable', 'integer', 'min:0'],
            'force_update' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:150'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
            'download_url' => AppRelease::downloadUrlRules(),
            'sha256' => AppRelease::sha256Rules($platform),
            'file_size_bytes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'build_number.unique' => 'Build Number ini sudah terdaftar untuk platform dan channel tersebut.',
        ]);

        $release = AppRelease::create([
            'platform' => $validated['platform'],
            'channel' => $validated['channel'],
            'version_name' => trim($validated['version_name']),
            'build_number' => (int) $validated['build_number'],
            'min_supported_build_number' => 0,
            'force_update' => false,
            'title' => $validated['title'] ?? null,
            'release_notes' => $validated['release_notes'] ?? null,
            'download_url' => trim($validated['download_url']),
            'sha256' => ! empty($validated['sha256']) ? strtolower($validated['sha256']) : null,
            'file_size_bytes' => $validated['file_size_bytes'] ?? null,
            'is_active' => false,
            'created_by_admin_id' => $request->user('admin')?->id,
        ]);

        if ($request->boolean('is_active')) {
            $release->activate();
        }

        return back()->with(
            'success',
            "Rilis v{$release->version_name} (Build {$release->build_number}) berhasil ditambahkan."
        );
    }

    public function update(Request $request, AppRelease $appRelease): RedirectResponse
    {
        $validated = $request->validate([
            'version_name' => ['required', 'string', 'max:50'],
            'build_number' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                Rule::unique('app_releases', 'build_number')
                    ->where(fn ($query) => $query
                        ->where('platform', $appRelease->platform)
                        ->where('channel', $appRelease->channel))
                    ->ignore($appRelease->id),
            ],
            'min_supported_build_number' => ['nullable', 'integer', 'min:0'],
            'force_update' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:150'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
            'download_url' => AppRelease::downloadUrlRules(),
            'sha256' => AppRelease::sha256Rules($appRelease->platform),
            'file_size_bytes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'build_number.unique' => 'Build Number ini sudah terdaftar untuk platform dan channel tersebut.',
        ]);

        $appRelease->fill([
            'version_name' => trim($validated['version_name']),
            'build_number' => $validated['build_number'] ?? $appRelease->build_number,
            'min_supported_build_number' => 0,
            'force_update' => false,
            'title' => $validated['title'] ?? null,
            'release_notes' => $validated['release_notes'] ?? null,
            'download_url' => trim($validated['download_url']),
            'sha256' => ! empty($validated['sha256']) ? strtolower($validated['sha256']) : null,
            'file_size_bytes' => $validated['file_size_bytes'] ?? null,
        ]);

        if ($request->has('is_active')) {
            if ($request->boolean('is_active')) {
                $appRelease->save();
                $appRelease->activate();
            } else {
                $appRelease->is_active = false;
                $appRelease->save();
            }
        } else {
            $appRelease->save();
        }

        return back()->with(
            'success',
            "Rilis v{$appRelease->version_name} (Build {$appRelease->build_number}) berhasil diperbarui."
        );
    }

    public function activate(AppRelease $appRelease): RedirectResponse
    {
        $appRelease->activate();

        return back()->with(
            'success',
            "Rilis v{$appRelease->version_name} (Build {$appRelease->build_number}) untuk {$appRelease->platform} ({$appRelease->channel}) sekarang aktif."
        );
    }

    public function deactivate(AppRelease $appRelease): RedirectResponse
    {
        $appRelease->is_active = false;
        $appRelease->save();

        return back()->with(
            'success',
            "Rilis v{$appRelease->version_name} (Build {$appRelease->build_number}) telah dinonaktifkan."
        );
    }

    public function destroy(AppRelease $appRelease): RedirectResponse
    {
        if ($appRelease->is_active) {
            return back()->with(
                'error',
                'Rilis yang sedang aktif tidak dapat dihapus. Nonaktifkan terlebih dahulu.'
            );
        }

        $label = "v{$appRelease->version_name} (Build {$appRelease->build_number})";
        $appRelease->delete();

        return back()->with('success', "Riwayat rilis {$label} berhasil dihapus.");
    }

    public function notify(Request $request, AppRelease $appRelease): RedirectResponse
    {
        if (! $appRelease->is_active) {
            return back()->with(
                'error',
                'Hanya rilis yang sedang aktif yang dapat dibroadcast notifikasinya.'
            );
        }

        $validated = $request->validate([
            'target' => ['required', 'string', Rule::in(['all', 'users', 'admins'])],
            'notification_title' => ['required', 'string', 'max:120'],
            'notification_body' => ['required', 'string', 'max:300'],
        ]);

        $payload = [
            'type' => 'app_update',
            'channel' => $appRelease->channel,
            'version_name' => $appRelease->version_name,
            'build_number' => (string) $appRelease->build_number,
            'force_update' => '0',
        ];

        $target = $validated['target'];
        $title = trim($validated['notification_title']);
        $body = trim($validated['notification_body']);
        $totalTargets = 0;
        $sentCount = 0;

        if ($target === 'all' || $target === 'users') {
            User::query()
                ->where('is_active', true)
                ->whereNotNull('fcm_token')
                ->where('fcm_token', '!=', '')
                ->chunkById(100, function ($users) use (&$totalTargets, &$sentCount, $title, $body, $payload): void {
                    foreach ($users as $user) {
                        $totalTargets++;

                        try {
                            if (FcmService::sendToUser($user, $title, $body, $payload)) {
                                $sentCount++;
                            }
                        } catch (\Throwable $e) {
                            Log::warning('AppRelease FCM notify failed for user', [
                                'user_id' => $user->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });
        }

        if ($target === 'all' || $target === 'admins') {
            Admin::query()
                ->whereNotNull('fcm_token')
                ->where('fcm_token', '!=', '')
                ->chunkById(100, function ($admins) use (&$totalTargets, &$sentCount, $title, $body, $payload): void {
                    foreach ($admins as $admin) {
                        $totalTargets++;

                        try {
                            if (FcmService::sendToUser($admin, $title, $body, $payload)) {
                                $sentCount++;
                            }
                        } catch (\Throwable $e) {
                            Log::warning('AppRelease FCM notify failed for admin', [
                                'admin_id' => $admin->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });
        }

        $appRelease->update([
            'last_notified_at' => now(),
            'last_notified_count' => $totalTargets,
            'last_notified_target' => $target,
        ]);

        return back()->with(
            'success',
            "Notifikasi update v{$appRelease->version_name} (Build {$appRelease->build_number}) dikirim ke {$totalTargets} perangkat target ({$sentCount} terkirim via FCM)."
        );
    }
}
