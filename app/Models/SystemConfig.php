<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemConfig extends Model
{
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    /**
     * Cache key for all system configurations.
     */
    public const CACHE_KEY = 'system_configs_all';

    /**
     * In-memory cache for the current request lifecycle.
     *
     * @var array<string, mixed>|null
     */
    protected static ?array $runtimeCache = null;

    /**
     * Boot the model to attach cache clearing events.
     */
    protected static function booted(): void
    {
        static::saved(function ($model) {
            static::clearCache($model->key);
        });

        static::deleted(function ($model) {
            static::clearCache($model->key);
        });
    }

    /**
     * Clear both runtime and persistent cache.
     */
    public static function clearCache(?string $key = null): void
    {
        self::$runtimeCache = null;
        Cache::forget(self::CACHE_KEY);

        if ($key) {
            Cache::forget("sys_cfg_{$key}");
        }
    }

    /**
     * Retrieve all configurations as key-value pairs (cached).
     *
     * @return array<string, mixed>
     */
    public static function getAll(): array
    {
        if (self::$runtimeCache !== null) {
            return self::$runtimeCache;
        }

        try {
            self::$runtimeCache = Cache::rememberForever(self::CACHE_KEY, function () {
                return self::query()->pluck('value', 'key')->toArray();
            });
        } catch (\Throwable $e) {
            return [];
        }

        return self::$runtimeCache ?? [];
    }

    /**
     * Get a configuration value by key with optional default fallback.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $all = self::getAll();

        if (!array_key_exists($key, $all) || $all[$key] === null) {
            return $default;
        }

        $rawValue = $all[$key];

        $decoded = json_decode($rawValue, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return $rawValue;
    }

    /**
     * Set a configuration value and automatically invalidate cache.
     */
    public static function setValue(string $key, mixed $value): void
    {
        $storeValue = is_array($value) ? json_encode($value) : (string) $value;

        self::updateOrCreate(
            ['key' => $key],
            ['value' => $storeValue]
        );

        self::clearCache($key);
    }

    /**
     * Helper to get common app branding/configurations for Inertia sharing.
     */
    public static function getAppConfig(): array
    {
        return [
            'nama_sistem' => self::getValue('nama_sistem', 'Halo APU'),
            'logo_path' => self::getValue('logo_path'),
            'banner_path' => self::getValue('banner_path'),
            'favicon_path' => self::getValue('favicon_path'),
            'notification_sound_path' => self::getValue('notification_sound_path'),
        ];
    }
}
