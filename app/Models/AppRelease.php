<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AppRelease extends Model
{
    public const PLATFORMS = ['android', 'ios'];
    public const CHANNELS = ['production', 'staging'];

    protected $fillable = [
        'platform',
        'channel',
        'version_name',
        'build_number',
        'min_supported_build_number',
        'force_update',
        'title',
        'release_notes',
        'download_url',
        'sha256',
        'file_size_bytes',
        'is_active',
        'published_at',
        'last_notified_at',
        'last_notified_count',
        'last_notified_target',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'build_number' => 'integer',
            'min_supported_build_number' => 'integer',
            'force_update' => 'boolean',
            'is_active' => 'boolean',
            'file_size_bytes' => 'integer',
            'published_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'last_notified_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $release): void {
            self::clearActiveCache($release->platform, $release->channel);
        });

        static::deleted(function (self $release): void {
            self::clearActiveCache($release->platform, $release->channel);
        });
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public static function activeCacheKey(string $platform, string $channel): string
    {
        return "app_release_active:{$platform}:{$channel}";
    }

    public static function clearActiveCache(?string $platform = null, ?string $channel = null): void
    {
        if ($platform !== null && $channel !== null) {
            Cache::forget(self::activeCacheKey($platform, $channel));

            return;
        }

        foreach (self::PLATFORMS as $p) {
            foreach (self::CHANNELS as $c) {
                Cache::forget(self::activeCacheKey($p, $c));
            }
        }
    }

    public static function getActiveRelease(string $platform, string $channel): ?self
    {
        return Cache::remember(
            self::activeCacheKey($platform, $channel),
            300,
            fn () => self::query()
                ->where('platform', $platform)
                ->where('channel', $channel)
                ->where('is_active', true)
                ->orderByDesc('build_number')
                ->first()
        );
    }

    /**
     * Activate this release atomically while deactivating sibling releases
     * in the same (platform, channel) scope.
     */
    public function activate(): self
    {
        DB::transaction(function (): void {
            self::query()
                ->where('platform', $this->platform)
                ->where('channel', $this->channel)
                ->whereKeyNot($this->getKey())
                ->update(['is_active' => false]);

            $this->is_active = true;
            $this->published_at ??= now();
            $this->save();
        });

        self::clearActiveCache($this->platform, $this->channel);

        return $this;
    }

    /**
     * @return list<string>
     */
    public static function allowedDownloadHosts(): array
    {
        $hosts = [
            'github.com',
            'objects.githubusercontent.com',
            'release-assets.githubusercontent.com',
            'releases.githubusercontent.com',
        ];

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = strtolower($appHost);
        }

        $configured = config('services.app_release.allowed_hosts', []);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (is_array($configured)) {
            foreach ($configured as $host) {
                $normalized = strtolower(trim((string) $host));
                if ($normalized !== '') {
                    $hosts[] = $normalized;
                }
            }
        }

        return array_values(array_unique($hosts));
    }

    public static function isAllowedDownloadUrl(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '' || ! str_starts_with(strtolower($trimmed), 'https://')) {
            return false;
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts)) {
            return false;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return false;
        }

        if (in_array($host, self::allowedDownloadHosts(), true)) {
            return true;
        }

        return preg_match('/^(?:[a-z0-9-]+\.)+githubusercontent\.com$/', $host) === 1;
    }

    /**
     * @return array<int, mixed>
     */
    public static function downloadUrlRules(): array
    {
        return [
            'required',
            'string',
            'max:1000',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! self::isAllowedDownloadUrl((string) $value)) {
                    $fail('URL unduhan harus menggunakan HTTPS dari host yang diizinkan (mis. GitHub Releases) dan tanpa kredensial.');
                }
            },
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function sha256Rules(string $platform): array
    {
        return [
            $platform === 'android' ? 'required' : 'nullable',
            'string',
            'regex:/^[a-f0-9]{64}$/i',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiPayload(): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'channel' => $this->channel,
            'version_name' => $this->version_name,
            'build_number' => (int) $this->build_number,
            'min_supported_build_number' => 0,
            'force_update' => false,
            'title' => $this->title,
            'release_notes' => $this->release_notes,
            'download_url' => $this->download_url,
            'sha256' => $this->sha256,
            'file_size_bytes' => $this->file_size_bytes !== null ? (int) $this->file_size_bytes : null,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
