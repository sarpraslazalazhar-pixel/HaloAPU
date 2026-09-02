<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaConfig extends Model
{
    protected $fillable = [
        'sub_unit_id',
        'priority',
        'jenis',
        'threshold_minutes',
    ];

    protected $casts = [
        'threshold_minutes' => 'integer',
    ];

    public function subUnit(): BelongsTo
    {
        return $this->belongsTo(SubUnit::class);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('sub_unit_id');
    }

    public function scopeForSubUnit($query, int $subUnitId)
    {
        return $query->where('sub_unit_id', $subUnitId);
    }

    protected static array $thresholdCache = [];

    public static function getThreshold(?int $subUnitId, string $priority, string $jenis): int
    {
        $cacheKey = ($subUnitId ?? 'global') . "_{$priority}_{$jenis}";
        
        if (array_key_exists($cacheKey, self::$thresholdCache)) {
            return self::$thresholdCache[$cacheKey];
        }

        if ($subUnitId) {
            $config = self::where('sub_unit_id', $subUnitId)
                ->where('priority', $priority)
                ->where('jenis', $jenis)
                ->first();

            if ($config) {
                return self::$thresholdCache[$cacheKey] = $config->threshold_minutes;
            }
        }

        $global = self::whereNull('sub_unit_id')
            ->where('priority', $priority)
            ->where('jenis', $jenis)
            ->first();

        return self::$thresholdCache[$cacheKey] = ($global?->threshold_minutes ?? 60);
    }
}
