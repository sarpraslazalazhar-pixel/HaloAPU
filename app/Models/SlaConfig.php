<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaConfig extends Model
{
    protected $fillable = [
        'sub_unit_id',
        'tier',
        'jenis',
        'threshold_minutes',
    ];

    protected $casts = [
        'tier' => 'integer',
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

    public static function getThreshold(?int $subUnitId, int $tier, string $jenis): int
    {
        if ($subUnitId) {
            $config = self::where('sub_unit_id', $subUnitId)
                ->where('tier', $tier)
                ->where('jenis', $jenis)
                ->first();

            if ($config) {
                return $config->threshold_minutes;
            }
        }

        $global = self::whereNull('sub_unit_id')
            ->where('tier', $tier)
            ->where('jenis', $jenis)
            ->first();

        return $global?->threshold_minutes ?? 60;
    }
}
